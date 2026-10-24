<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||!preg_match('/^[a-zA-Z0-9_]+_test$/',(string)getenv('DB_DATABASE'))){fwrite(STDERR,"Use an explicitly configured disposable test database.\n");exit(2);}
require dirname(__DIR__).'/app/bootstrap.php';require dirname(__DIR__).'/app/migrations.php';require dirname(__DIR__).'/app/sessions.php';
$checks=0;
function verify(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
verify(wk_migrate(false,true)===[],'Completed migrations must be an idempotent no-op.');
try{wk_migrate(true);throw new RuntimeException('Fresh migration accepted nonempty database.');}catch(RuntimeException $e){verify(str_contains($e->getMessage(),'empty database'),'Fresh migration rejects restored/existing records.');}
verify(count(wk_sql_statements("-- comment;\nSELECT 'a;b'; /* c; */ SELECT 'x''y';"))===2,'Migration parser preserves semicolons in SQL values.');
verify(wk_ip_in_cidr('10.2.3.4','10.2.0.0/16')&&!wk_ip_in_cidr('203.0.113.1','10.2.0.0/16'),'Trusted proxy CIDR boundary.');
verify(wk_ip_in_cidr('2001:db8::2','2001:db8::/64')&&!wk_ip_in_cidr('2001:db9::1','2001:db8::/64'),'IPv6 proxy CIDR boundary.');
$CONFIG['trusted_proxies']=['10.2.0.0/16'];$_SERVER['REMOTE_ADDR']='203.0.113.1';$_SERVER['HTTP_X_FORWARDED_PROTO']='https';
verify(!wk_request_is_https(),'Untrusted forwarded protocol cannot spoof HTTPS.');
$_SERVER['REMOTE_ADDR']='10.2.3.4';$_SERVER['HTTP_X_FORWARDED_FOR']='198.51.100.2, 10.2.3.5';
verify(wk_request_is_https()&&wk_client_ip()==='198.51.100.2','Trusted proxy chain resolves real client.');
$prefix='operations-'.bin2hex(random_bytes(8));$email='fixture@example.invalid';
$id=enqueue_email($email,'Test delivery','<p>Never sent to a real service.</p>',$prefix);
verify($id===enqueue_email($email,'Test delivery','<p>Different later copy.</p>',$prefix),'Repeated enqueue returns the same immutable outbox entry.');
$row=db()->query('SELECT * FROM email_outbox WHERE id='.(int)$id)->fetch();
verify(!str_contains($row['payload'],$email)&&!str_contains($row['payload'],'Never sent'),'Outbox payload is encrypted at rest by the application.');
verify(email_work()['enabled']===false,'Disabled transport never sends queued messages.');
$sent=[];$stats=email_work(100,function($payload,$key)use(&$sent){$sent[]=$payload;return 'fixture-'.$key;});
verify(count($sent)===1&&$stats['sent']===1,'Provider acknowledgement settles only one outbox delivery.');
verify(str_contains($sent[0]['html'],'Never sent'),'Dedupe preserves first approved payload.');
$stats=email_work(100,function(){throw new RuntimeException('Would be duplicate.');});verify($stats['sent']===0&&$stats['ambiguous']===0,'Sent emails are not repeated.');
$ambiguous=enqueue_email($email,'Test uncertainty','<p>Fixture</p>',$prefix.'-uncertain');
email_work(10,function(){throw new RuntimeException('Simulated response lost after provider acceptance.');});
$q=db()->prepare('SELECT status FROM email_outbox WHERE id=?');$q->execute([$ambiguous]);verify($q->fetchColumn()==='ambiguous','Uncertain send is held for reconciliation.');
$stats=email_work(100,function(){throw new RuntimeException('Should not retry.');});verify($stats['ambiguous']===0,'Ambiguous email is not blindly retried.');
$obsolete=enqueue_email($email,'Expired confirmation','<p>Fixture</p>','newsletter-confirm:'.hash('sha256',$prefix));
email_work(100,function(){throw new RuntimeException('Obsolete newsletter confirmation must never be sent.');});
$q->execute([$obsolete]);verify($q->fetchColumn()==='suppressed','Expired/missing newsletter token suppresses queued email.');
// Use real package revisions and the real worker, with a local transport only.
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Operations fixture',$prefix.'@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
$userId=(int)db()->lastInsertId();$packageIds=[];$extraOutbox=[];
$makeJob=static function(string $title)use($userId,&$packageIds):array{
    $draft=publishing_default_draft();$draft['title']=$title;$draft['variants']['facebook']['enabled']=true;$draft['variants']['facebook']['text']='Synthetic assisted publishing copy.';
    $package=publishing_save_package($draft,$userId);$packageIds[]=$package;$revision=publishing_approve($package,1,$userId);
    publishing_schedule($package,$revision,(new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i'),$userId);
    db()->prepare("UPDATE publishing_jobs SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE revision_id=?")->execute([$revision]);
    publishing_work(10);
    $q=db()->prepare('SELECT j.*,r.snapshot_json,p.created_by FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id WHERE j.revision_id=?');$q->execute([$revision]);return $q->fetch();
};
$outboxFor=static function(array $job):array{$q=db()->prepare('SELECT * FROM email_outbox WHERE dedupe_key=?');$q->execute([hash('sha256','publishing:'.$job['job_key'])]);return $q->fetch();};
$job=$makeJob($prefix.' delivered');$outbox=$outboxFor($job);$extraOutbox[]=(int)$outbox['id'];
verify($job['status']==='reminder_queued'&&$outbox['status']==='pending','Assisted task queues a reminder without claiming it was sent.');
$stats=email_work(100,fn()=> 'fixture-acknowledged-reminder');
verify($stats['sent']===1&&$outboxFor($job)['status']==='sent','Reminder records provider acknowledgement.');
$jobStatus=db()->prepare('SELECT status FROM publishing_jobs WHERE id=?');$jobStatus->execute([$job['id']]);
verify($jobStatus->fetchColumn()==='reminder_sent','Provider acknowledgement updates the dependent publishing job.');
// Simulate a historical interrupted bookkeeping step after durable provider acceptance.
db()->prepare("UPDATE publishing_jobs SET status='reminder_queued' WHERE id=?")->execute([$job['id']]);
verify(email_work()['enabled']===false,'Bookkeeping can reconcile while outbound transport remains disabled.');
$jobStatus->execute([$job['id']]);verify($jobStatus->fetchColumn()==='reminder_sent','Acknowledged outbox state repairs a stale reminder without resending.');
verify(!publishing_send_reminder($job,json_decode($job['snapshot_json'],true)),'An already acknowledged dedupe row is not a newly queued reminder.');
$cancelledJob=$makeJob($prefix.' cancelled');$extraOutbox[]=(int)$outboxFor($cancelledJob)['id'];
db()->prepare("UPDATE publishing_jobs SET status='cancelled' WHERE id=?")->execute([$cancelledJob['id']]);
$calls=0;email_work(100,function()use(&$calls){$calls++;return 'must-not-send';});
verify($calls===0&&$outboxFor($cancelledJob)['status']==='suppressed','A cancelled publishing task never sends its queued reminder.');
verify(!publishing_send_reminder($cancelledJob,json_decode($cancelledJob['snapshot_json'],true)),'A suppressed dedupe row cannot falsely report a new queued reminder.');
$uncertainJob=$makeJob($prefix.' uncertain');$extraOutbox[]=(int)$outboxFor($uncertainJob)['id'];
email_work(100,function(){throw new RuntimeException('Synthetic response lost.');});
$jobStatus->execute([$uncertainJob['id']]);verify($jobStatus->fetchColumn()==='assisted','Unknown email outcome leaves the task available for assisted completion.');
verify($outboxFor($uncertainJob)['status']==='ambiguous','Unknown reminder outcome is retained for review.');
$contactRef='OPS-'.strtoupper(bin2hex(random_bytes(8)));
db()->prepare("INSERT INTO contact_messages(reference,first_name,last_name,email,phone_code,phone,country,city,message) VALUES(?,'Fixture','Writer',?,'','','','','Synthetic contact')")->execute([$contactRef,$email]);
$contactId=(int)db()->lastInsertId();$contactMail=enqueue_email($email,'Contact fixture','<p>Fixture</p>','contact:'.$contactRef);$extraOutbox[]=$contactMail;
db()->prepare("UPDATE email_outbox SET status='sent',provider_id='synthetic-already-accepted',sent_at=NOW() WHERE id=?")->execute([$contactMail]);email_work();
verify((int)db()->query('SELECT email_sent FROM contact_messages WHERE id='.$contactId)->fetchColumn()===1,'Acknowledged contact mail repairs its dependent record without network delivery.');
$scopedMail=enqueue_email($email,'Scoped weekly reminder fixture','<p>Synthetic</p>',$prefix.'-scoped');$extraOutbox[]=$scopedMail;
$legacyMail=enqueue_email($email,'Unrelated paused mail fixture','<p>Synthetic</p>',$prefix.'-unrelated');$extraOutbox[]=$legacyMail;
$scopedCalls=0;$scopedStats=email_work(100,function()use(&$scopedCalls){$scopedCalls++;return 'synthetic-scoped-ack';},[$scopedMail]);
verify($scopedCalls===1&&$scopedStats['sent']===1,'Scoped mail worker sends only the selected reminder.');
verify(db()->query('SELECT status FROM email_outbox WHERE id='.$legacyMail)->fetchColumn()==='pending','Unrelated paused email remains pending.');
verify(email_work(100,fn()=>'must-not-send',[])['sent']===0,'Empty mail scope cannot drain the queue.');
$sessions=new WkDatabaseSessions();$sid='test-'.bin2hex(random_bytes(12));
verify($sessions->read($sid)==='','Missing session is empty.');$sessions->write($sid,'private-session-data');$sessions->close();
verify($sessions->validateId($sid),'Stored session is valid.');verify($sessions->read($sid)==='private-session-data','Encrypted sessions survive a different instance read.');$sessions->close();$sessions->destroy($sid);verify(!$sessions->validateId($sid),'Destroyed session no longer authenticates.');
$tmp=tempnam(sys_get_temp_dir(),'wk-media-');file_put_contents($tmp,'fixture');
try{$CONFIG['media']['driver']='s3';$CONFIG['media']['public_url']='';media_store_upload($tmp,'safe.jpg','image/jpeg');throw new RuntimeException('S3 accepted missing CDN config.');}catch(RuntimeException $e){verify(str_contains($e->getMessage(),'CDN'),'Missing media configuration fails closed before network.');}finally{unlink($tmp);}
$allOutbox=array_merge([$id,$ambiguous,$obsolete],$extraOutbox);db()->prepare('DELETE FROM email_outbox WHERE id IN ('.implode(',',array_fill(0,count($allOutbox),'?')).')')->execute($allOutbox);
foreach($packageIds as$package){db()->prepare('DELETE FROM publishing_events WHERE package_id=?')->execute([$package]);db()->prepare('DELETE j FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE r.package_id=?')->execute([$package]);db()->prepare('DELETE FROM publishing_revisions WHERE package_id=?')->execute([$package]);db()->prepare('DELETE FROM publishing_packages WHERE id=?')->execute([$package]);}
db()->prepare('DELETE FROM contact_messages WHERE id=?')->execute([$contactId]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
echo "Operations checks passed: $checks\n";
