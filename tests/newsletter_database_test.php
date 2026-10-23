<?php
declare(strict_types=1);

/** Integration with the real outbox and consent helpers; synthetic local DB only.
 * APP_ENV=testing WK_TEST_ALLOW_WRITES=1 DB_HOST=127.0.0.1 DB_PORT=3307
 * DB_DATABASE=waithaka_test_<unique>_test DB_USERNAME=root DB_PASSWORD=''
 * php tests/newsletter_database_test.php
 * Prepare all migrations with tests/prepare.php first. No mail provider is used.
 */
function nl_db_env(string $name,string $fallback):string|false{$value=getenv($name);return $value!==false?$value:getenv($fallback);}
$host=nl_db_env('DB_HOST','WK_TEST_DB_HOST');$port=nl_db_env('DB_PORT','WK_TEST_DB_PORT');
$name=nl_db_env('DB_DATABASE','WK_TEST_DB_NAME');$user=nl_db_env('DB_USERNAME','WK_TEST_DB_USER');$pass=nl_db_env('DB_PASSWORD','WK_TEST_DB_PASS');
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'
    ||!is_string($name)||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',$name)
    ||!in_array($host,['127.0.0.1','::1'],true)||!is_string($port)||!ctype_digit($port)||(int)$port<1||(int)$port>65535
    ||!is_string($user)||$user===''||!is_string($pass)){
    fwrite(STDERR,"Use explicit APP_ENV=testing, WK_TEST_ALLOW_WRITES=1 and numeric-loopback DB_* credentials for a fresh waithaka_test_*_test database.\n");exit(2);
}
$CONFIG=['app_env'=>'testing','app_key'=>'newsletter-local-fixture-key-not-for-production','base_url'=>'https://www.waithakandegwa.com','timezone'=>'Africa/Nairobi',
    'worker_enabled'=>false,'db'=>['host'=>$host,'port'=>(int)$port,'name'=>$name,'user'=>$user,'pass'=>$pass,'charset'=>'utf8mb4'],
    'mail'=>['transport'=>'disabled','from'=>'newsletter@example.com','from_name'=>'Newsletter fixture','reply_to'=>'reply@example.com','postal_address'=>'Synthetic fixture address']];
$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
foreach(['functions','journeys','operations','mail_feedback','newsletter']as$module)require_once __DIR__.'/../app/'.$module.'.php';
$checks=0;
function nl_db_check(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);$checks++;}
function nl_db_reject(callable $action,string $label):void{try{$action();}catch(NewsletterValidationException|NewsletterConflictException $error){nl_db_check(true,$label);return;}nl_db_check(false,$label);}
function nl_db_row(string $sql,array $values=[]):array{$q=db()->prepare($sql);$q->execute($values);return $q->fetch()?:[];}
function nl_db_scalar(string $sql,array $values=[]):mixed{$q=db()->prepare($sql);$q->execute($values);return $q->fetchColumn();}
function nl_db_subscriber(string $label,string $status='confirmed',bool $confirmed=true):int{
    db()->prepare('INSERT INTO newsletter_subscribers(email,name,status,consent_version,requested_at,confirmed_at,created_at,updated_at) VALUES(?,?,?,?,NOW(),?,NOW(),NOW())')
        ->execute([$label.'@example.invalid',$label,$status,'synthetic-consent-v1',$confirmed?date('Y-m-d H:i:s'):null]);return (int)db()->lastInsertId();
}
function nl_db_isolate():void{
    // Every subscriber in this schema was created by this test; none are imported.
    db()->exec("UPDATE newsletter_subscribers SET status='unsubscribed',consent_revision=consent_revision+1 WHERE status='confirmed'");
}
function nl_db_approved(string $label,?int $source=null):array{
    global $actor;
    $values=['title'=>$label,'subject'=>$label,'preheader'=>'Synthetic preview','body_html'=>'<p>Exact approved words for '.$label.'.</p>','source_post_id'=>$source];
    $id=newsletter_save_campaign($values,$actor);$campaign=newsletter_campaign($id);
    $revision=newsletter_approve($id,1,hash('sha256',newsletter_json(newsletter_snapshot($campaign))),$actor);
    return [$id,$revision];
}
function nl_db_schedule(int $id,int $revision):int{
    global $actor;
    return newsletter_schedule($id,$revision,(new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i'),(int)newsletter_campaign($id)['state_version'],$actor);
}
function nl_db_due(int $id):void{db()->prepare('UPDATE newsletter_campaigns SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$id]);}
function nl_db_delivery(int $campaign,int $subscriber):array{return nl_db_row('SELECT * FROM newsletter_deliveries WHERE campaign_id=? AND subscriber_id=?',[$campaign,$subscriber]);}
function nl_db_outbox(array $delivery):array{return nl_db_row('SELECT * FROM email_outbox WHERE id=?',[$delivery['outbox_id']]);}
function nl_db_payload(array $delivery):array{return json_decode(decrypt_secret(nl_db_outbox($delivery)['payload']),true,512,JSON_THROW_ON_ERROR);}
function nl_db_run_gate(array $delivery,?array $payload=null):array{
    $gate=newsletter_email_gate('newsletter-campaign:'.$delivery['delivery_key'],$payload??nl_db_payload($delivery),(int)$delivery['outbox_id']);
    try{return ['action'=>$gate['action'],'reason'=>$gate['reason']];}finally{newsletter_email_unlock($gate);}
}
function nl_db_release_deferred():void{db()->exec("UPDATE email_outbox SET available_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE status='pending'");}
function nl_db_token(string $url):string{parse_str((string)parse_url($url,PHP_URL_QUERY),$query);return (string)($query['token']??'');}

// Avoid consuming or changing any queue that was not created by this test.
foreach(['newsletter_campaigns','newsletter_subscribers','email_outbox','mail_suppressions']as$table){
    if((int)nl_db_scalar('SELECT COUNT(*) FROM '.$table)!==0)throw new RuntimeException('Use a fresh prepared newsletter fixture database; '.$table.' is not empty.');
}
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Newsletter fixture','newsletter-writer@example.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);
$actor=(int)db()->lastInsertId();
$postIds=[];
foreach(['published','draft','scheduled']as$status){
    db()->prepare('INSERT INTO posts(user_id,title,slug,standfirst,content_html,status,published_at) VALUES(?,?,?,?,?,?,?)')
        ->execute([$actor,'Synthetic '.$status.' article','newsletter-fixture-'.$status,'Public summary','<p>Public approved article text.</p>',$status,$status==='scheduled'?date('Y-m-d H:i:s',time()+86400):date('Y-m-d H:i:s',time()-60)]);
    $postIds[$status]=(int)db()->lastInsertId();
}
$import=newsletter_article_values($postIds['published']);
nl_db_check($import['source_post_id']===$postIds['published']&&str_contains($import['body_html'],'Read this article on the website'),'Only the published snapshot is imported with a public link.');
nl_db_reject(fn()=>newsletter_article_values($postIds['draft']),'A private draft cannot be imported.');
nl_db_reject(fn()=>newsletter_article_values($postIds['scheduled']),'A future article cannot be imported.');
$incomplete=newsletter_save_campaign(['title'=>'Incomplete private draft'],$actor);
nl_db_reject(fn()=>newsletter_snapshot(newsletter_campaign($incomplete)),'A partial private draft cannot enter approval.');
$campaign=newsletter_save_campaign($import,$actor);$saved=newsletter_campaign($campaign);
$review=hash('sha256',newsletter_json(newsletter_snapshot($saved)));
nl_db_reject(fn()=>newsletter_approve($campaign,1,str_repeat('0',64),$actor),'A guessed or stale review fingerprint is rejected.');
$revision=newsletter_approve($campaign,1,$review,$actor);
$frozen=newsletter_revision($revision);
nl_db_check(abs((new DateTimeImmutable($frozen['approved_at'],new DateTimeZone('UTC')))->getTimestamp()-time())<10,'Newsletter approval timestamps are UTC under the legacy Nairobi DB session.');
nl_db_check(newsletter_approve($campaign,1,$review,$actor)===$revision,'Repeated approval returns the same immutable revision.');
$changed=$import;$changed['subject']='Reviewed revision two';
newsletter_save_campaign($changed,$actor,$campaign,1);
nl_db_check(newsletter_campaign($campaign)['approved_revision_id']===null,'Editing an approved draft clears the active approval.');
nl_db_check(newsletter_revision($revision)['snapshot_json']===$frozen['snapshot_json'],'Earlier approved content remains immutable after a draft edit.');
nl_db_reject(fn()=>newsletter_save_campaign($changed,$actor,$campaign,1),'A stale concurrent draft save is rejected.');
nl_db_reject(fn()=>newsletter_approve($campaign,1,$review,$actor),'A stale draft version cannot be approved.');
$saved=newsletter_campaign($campaign);$review=hash('sha256',newsletter_json(newsletter_snapshot($saved)));
$CONFIG['mail']['from_name']='Changed sender';
nl_db_reject(fn()=>newsletter_approve($campaign,2,$review,$actor),'Sender changes invalidate the saved preview.');
$CONFIG['mail']['from_name']='Newsletter fixture';$revision=newsletter_approve($campaign,2,$review,$actor);

$a=nl_db_subscriber('recipient-a');$b=nl_db_subscriber('recipient-b');$pending=nl_db_subscriber('recipient-pending','pending',false);
nl_db_subscriber('recipient-unsubscribed','unsubscribed');nl_db_subscriber('recipient-unproved','confirmed',false);
$localSuppressed=nl_db_subscriber('recipient-local-suppressed');
db()->prepare("UPDATE newsletter_subscribers SET suppressed_at=NOW(),suppression_reason='manual' WHERE id=?")->execute([$localSuppressed]);
$centralSuppressed=nl_db_subscriber('recipient-central-suppressed');$centralEmail=newsletter_subscriber($centralSuppressed)['email'];
db()->prepare("INSERT INTO mail_suppressions(email_hash,email_encrypted,reason,created_at,updated_at) VALUES(?,?,'complaint',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([email_address_hash($centralEmail),encrypt_secret($centralEmail)]);
$when=(new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i');$state=(int)newsletter_campaign($campaign)['state_version'];
nl_db_check(newsletter_schedule($campaign,$revision,$when,$state,$actor)===2,'The audience contains only confirmed, unsuppressed subscribers with recorded confirmation.');
nl_db_check(newsletter_schedule($campaign,$revision,$when,$state,$actor)===2&&(int)nl_db_scalar('SELECT COUNT(*) FROM newsletter_deliveries WHERE campaign_id=?',[$campaign])===2,'Repeated scheduling reuses the original audience without duplicate recipients.');
$confirm=newsletter_create_token($pending,'confirm',date('Y-m-d H:i:s',time()+3600));
nl_db_check(newsletter_apply_token($confirm,'confirm'),'The ordinary double opt-in confirmation still works.');
nl_db_check((int)nl_db_scalar('SELECT COUNT(*) FROM newsletter_deliveries WHERE campaign_id=? AND subscriber_id=?',[$campaign,$pending])===0,'A new confirmation after scheduling never joins the frozen edition.');
nl_db_reject(fn()=>newsletter_save_campaign($changed,$actor,$campaign,2),'Scheduled content cannot be overwritten.');
nl_db_check(newsletter_work()['queued']===0,'A future edition is not queued early.');
nl_db_due($campaign);nl_db_check(newsletter_work(1)['queued']===1,'The campaign worker respects its bounded recipient batch.');
nl_db_check(newsletter_work(1)['queued']===1&&newsletter_work(1)['queued']===0,'Later batches queue each audience member once.');
$da=nl_db_delivery($campaign,$a);$db=nl_db_delivery($campaign,$b);$payload=nl_db_payload($da);$outbox=nl_db_outbox($da);
nl_db_check(!str_contains($outbox['payload'],'recipient-a')&&!str_contains($outbox['payload'],'Reviewed revision two'),'The queued recipient and approved content are encrypted.');
nl_db_check($payload['subject']==='Reviewed revision two'&&$payload['headers']['List-Unsubscribe-Post']==='List-Unsubscribe=One-Click','Queued messages contain the exact subject and one-click list headers.');
nl_db_check(preg_match('~^<https://www\.waithakandegwa\.com/newsletter/one-click\?token=([a-f0-9]{64})>$~D',$payload['headers']['List-Unsubscribe'],$tokenMatch)===1,'A recipient-specific HTTPS one-click capability is frozen in the outbox.');
nl_db_check((int)nl_db_scalar("SELECT COUNT(*) FROM newsletter_tokens WHERE token_hash=? AND purpose='one_click'",[hash('sha256',$tokenMatch[1])])===1,'The one-click token is stored only as a private hash.');
nl_db_check((int)nl_db_scalar('SELECT COUNT(*) FROM newsletter_tokens WHERE token_hash=?',[$tokenMatch[1]])===0,'Raw unsubscribe tokens are absent from token storage.');
nl_db_check(email_work()['enabled']===false&&nl_db_outbox($da)['status']==='pending','An offline mail transport leaves the real outbox pending.');

$state=(int)newsletter_campaign($campaign)['state_version'];newsletter_transition($campaign,'pause',$state,$actor);
nl_db_reject(fn()=>newsletter_transition($campaign,'resume',$state,$actor,$when),'Stale release controls cannot change campaign state.');
nl_db_check(nl_db_run_gate($da)['action']==='defer','Pausing a queued edition defers its final transport gate.');
$calls=0;$stats=email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});
nl_db_check($calls===0&&$stats['deferred']===2,'The real mail worker cannot deliver a paused edition.');
$gate=newsletter_email_gate($payload['dedupe_key'],$payload,(int)$da['outbox_id']);
$second=new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try{
    foreach([['campaign',$campaign],['subscriber',$a]]as[$kind,$id]){$q=$second->prepare('SELECT GET_LOCK(?,0)');$q->execute([newsletter_lock_name($kind,$id)]);nl_db_check((int)$q->fetchColumn()===0,'The '.$kind.' transport lock excludes an independent database connection.');}
}finally{newsletter_email_unlock($gate);}
$q=$second->prepare('SELECT GET_LOCK(?,0)');$q->execute([newsletter_lock_name('subscriber',$a)]);
nl_db_check((int)$q->fetchColumn()===1,'The transport gate releases its subscriber lock in finally.');$second->prepare('SELECT RELEASE_LOCK(?)')->execute([newsletter_lock_name('subscriber',$a)]);
$humanToken=nl_db_token(newsletter_unsubscribe_url($b));$oldConsent=(int)newsletter_subscriber($b)['consent_revision'];
nl_db_check(newsletter_apply_token($humanToken,'unsubscribe')&&(int)newsletter_subscriber($b)['consent_revision']===$oldConsent+1,'Human unsubscribe invalidates the queued consent revision.');
nl_db_check(newsletter_apply_token($humanToken,'unsubscribe')&&(int)newsletter_subscriber($b)['consent_revision']===$oldConsent+1,'Repeated human unsubscribe is idempotent.');
newsletter_transition($campaign,'resume',(int)newsletter_campaign($campaign)['state_version'],$actor,$when);nl_db_due($campaign);nl_db_release_deferred();
$accepted=[];$stats=email_work(100,function(array $message,string $key)use(&$accepted){$accepted[]=$message;return 'synthetic-accepted-'.$key;});
nl_db_check($stats['sent']===1&&count($accepted)===1&&$accepted[0]['to']==='recipient-a@example.invalid','Only the still-eligible frozen recipient reaches the offline provider stub.');
nl_db_check(nl_db_delivery($campaign,$b)['status']==='suppressed','Unsubscribe after queueing suppresses the old delivery.');
nl_db_check(nl_db_delivery($campaign,$a)['status']==='accepted','Provider acceptance is not falsely reported as delivery.');
nl_db_check(newsletter_campaign($campaign)['status']==='completed','Processing completes only after all recipients have a recorded outcome.');
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'duplicate';});nl_db_check($calls===0,'Acknowledged messages are never sent twice.');
foreach(['delivered','bounced','complained','delivered']as$feedback){db()->beginTransaction();newsletter_delivery_feedback((int)$da['outbox_id'],$feedback,'synthetic-provider-id');db()->commit();}
newsletter_email_acknowledged($payload['dedupe_key'],(int)$da['outbox_id'],'synthetic-provider-id');newsletter_email_reconcile();
nl_db_check(nl_db_delivery($campaign,$a)['status']==='complained','Late delivery or acknowledgement cannot erase stronger complaint feedback.');
nl_db_check(nl_db_delivery($campaign,$a)['delivered_at']!==null,'Historical verified delivery remains recorded after a later complaint.');

// Subscriber controls never manufacture confirmation and cannot erase provider causes.
$consent=(int)newsletter_subscriber($pending)['consent_revision'];
newsletter_manage_subscriber($pending,'suppress','Synthetic manual preference',$actor,$consent);
nl_db_check(newsletter_address_suppressed('recipient-pending@example.invalid'),'Manual suppression blocks the newsletter address.');
nl_db_reject(fn()=>newsletter_manage_subscriber($pending,'clear_suppression','Fixture',$actor,$consent),'Stale consent controls cannot override a newer preference.');
newsletter_manage_subscriber($pending,'clear_suppression','Synthetic resolved request',$actor,(int)newsletter_subscriber($pending)['consent_revision']);
nl_db_check(newsletter_subscriber($pending)['status']==='unsubscribed'&&newsletter_subscriber($pending)['confirmed_at']===null,'Clearing a manual suppression still requires fresh double opt-in.');
db()->prepare("UPDATE newsletter_subscribers SET suppressed_at=NOW(),suppression_reason='complaint' WHERE id=?")->execute([$centralSuppressed]);
nl_db_reject(fn()=>newsletter_manage_subscriber($centralSuppressed,'suppress','Replace reason',$actor,(int)newsletter_subscriber($centralSuppressed)['consent_revision']),'Manual preferences cannot disguise provider suppression reasons.');
nl_db_reject(fn()=>newsletter_manage_subscriber($centralSuppressed,'clear_suppression','Clear reason',$actor,(int)newsletter_subscriber($centralSuppressed)['consent_revision']),'Provider complaint suppression cannot be cleared through the newsletter editor.');
$countBefore=(int)nl_db_scalar('SELECT COUNT(*) FROM email_outbox');newsletter_request_signup($centralEmail,'Synthetic repeated signup');
nl_db_check((int)nl_db_scalar('SELECT COUNT(*) FROM email_outbox')===$countBefore,'A suppressed address cannot generate another confirmation email.');

// A renewed subscription must never revive an already-scheduled consent snapshot.
nl_db_isolate();$renewed=nl_db_subscriber('recipient-renewed');[$renewalCampaign,$renewalRevision]=nl_db_approved('Consent renewal fixture');nl_db_schedule($renewalCampaign,$renewalRevision);nl_db_due($renewalCampaign);newsletter_work();
$renewalDelivery=nl_db_delivery($renewalCampaign,$renewed);$oneClickToken=nl_db_token(newsletter_one_click_url($renewed));
nl_db_check(!newsletter_apply_one_click(str_repeat('0',64)),'Unknown one-click capabilities are rejected.');
nl_db_check(!newsletter_apply_one_click(newsletter_create_token($renewed,'unsubscribe',null)),'Human unsubscribe tokens cannot be used as one-click capabilities.');
nl_db_check(newsletter_apply_one_click($oneClickToken),'The opaque one-click capability unsubscribes its owner.');
$afterOneClick=(int)newsletter_subscriber($renewed)['consent_revision'];
nl_db_check(newsletter_apply_one_click($oneClickToken)&&(int)newsletter_subscriber($renewed)['consent_revision']===$afterOneClick,'Repeated one-click POSTs do not keep changing consent.');
newsletter_request_signup('recipient-renewed@example.invalid','Renewed fixture');
nl_db_check(newsletter_subscriber($renewed)['status']==='pending','Re-subscription starts with a new pending confirmation.');
$confirmation=nl_db_row('SELECT * FROM email_outbox ORDER BY id DESC LIMIT 1');$confirmationPayload=json_decode(decrypt_secret($confirmation['payload']),true,512,JSON_THROW_ON_ERROR);
preg_match('~/newsletter/confirm\?token=([a-f0-9]{64})~',$confirmationPayload['html'],$confirmMatch);
nl_db_check(isset($confirmMatch[1])&&newsletter_apply_token($confirmMatch[1],'confirm'),'The re-subscription requires and consumes its new confirmation token.');
nl_db_check(nl_db_run_gate($renewalDelivery)['action']==='suppress','New confirmation does not revive an old queued audience membership.');
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});nl_db_check($calls===0,'Neither obsolete campaign nor consumed confirmation mail reaches transport.');

// Cancellation is durable and preserves already-known outcomes.
[$cancelCampaign,$cancelRevision]=nl_db_approved('Cancel fixture');nl_db_schedule($cancelCampaign,$cancelRevision);nl_db_due($cancelCampaign);newsletter_work();$cancelDelivery=nl_db_delivery($cancelCampaign,$renewed);
newsletter_transition($cancelCampaign,'cancel',(int)newsletter_campaign($cancelCampaign)['state_version'],$actor);
nl_db_check(nl_db_outbox($cancelDelivery)['status']==='suppressed'&&nl_db_delivery($cancelCampaign,$renewed)['status']==='cancelled','Cancelling an edition suppresses its pending outbox and recipient entry atomically.');
nl_db_reject(fn()=>newsletter_schedule($cancelCampaign,$cancelRevision,$when,(int)newsletter_campaign($cancelCampaign)['state_version'],$actor),'Cancelled editions cannot be scheduled again.');
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});nl_db_check($calls===0,'Cancelled messages never reach transport.');

// Unknown provider outcomes are held, not blindly retried.
[$uncertainCampaign,$uncertainRevision]=nl_db_approved('Lost response fixture');nl_db_schedule($uncertainCampaign,$uncertainRevision);nl_db_due($uncertainCampaign);newsletter_work();
$uncertainDelivery=nl_db_delivery($uncertainCampaign,$renewed);$calls=0;
$stats=email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;throw new RuntimeException('Synthetic response loss after possible provider acceptance.');});
nl_db_check($calls===1&&$stats['ambiguous']===1&&nl_db_delivery($uncertainCampaign,$renewed)['status']==='ambiguous','An uncertain send is retained as an explicit ambiguous outcome.');
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'duplicate';});nl_db_check($calls===0,'An ambiguous delivery is never automatically replayed.');

// A queue payload must exactly match its approved recipient envelope.
[$tamperCampaign,$tamperRevision]=nl_db_approved('Envelope fixture');nl_db_schedule($tamperCampaign,$tamperRevision);nl_db_due($tamperCampaign);newsletter_work();
$tamperDelivery=nl_db_delivery($tamperCampaign,$renewed);$tampered=nl_db_payload($tamperDelivery);$tampered['subject']='Unapproved replacement';
db()->prepare('UPDATE email_outbox SET payload=? WHERE id=?')->execute([encrypt_secret(json_encode($tampered,JSON_THROW_ON_ERROR)),$tamperDelivery['outbox_id']]);
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});
nl_db_check($calls===0&&nl_db_delivery($tamperCampaign,$renewed)['status']==='failed','Changed queued content fails its final envelope check before transport.');

// Withdrawn articles and changed addresses stop the queue and the final send.
[$sourceCampaign,$sourceRevision]=nl_db_approved('Public source fixture',$postIds['published']);nl_db_schedule($sourceCampaign,$sourceRevision);nl_db_due($sourceCampaign);
db()->prepare("UPDATE posts SET status='draft' WHERE id=?")->execute([$postIds['published']]);$stats=newsletter_work();
nl_db_check($stats['paused']===1&&newsletter_campaign($sourceCampaign)['status']==='paused','Withdrawing the linked article automatically pauses the unqueued edition.');
nl_db_check(nl_db_delivery($sourceCampaign,$renewed)['outbox_id']===null,'A private source cannot create a campaign outbox message.');
db()->prepare("UPDATE posts SET status='published' WHERE id=?")->execute([$postIds['published']]);newsletter_transition($sourceCampaign,'resume',(int)newsletter_campaign($sourceCampaign)['state_version'],$actor,$when);nl_db_due($sourceCampaign);newsletter_work();
$sourceDelivery=nl_db_delivery($sourceCampaign,$renewed);db()->prepare('UPDATE posts SET slug=? WHERE id=?')->execute(['newsletter-fixture-moved',$postIds['published']]);
nl_db_check(nl_db_run_gate($sourceDelivery)['action']==='defer'&&newsletter_campaign($sourceCampaign)['status']==='paused','A moved public article address invalidates the frozen link at the last delivery gate.');
newsletter_transition($sourceCampaign,'cancel',(int)newsletter_campaign($sourceCampaign)['state_version'],$actor);db()->prepare('UPDATE posts SET slug=? WHERE id=?')->execute(['newsletter-fixture-published',$postIds['published']]);

// Settings changes cannot silently send an approved edition with another sender.
[$senderCampaign,$senderRevision]=nl_db_approved('Sender gate fixture');nl_db_schedule($senderCampaign,$senderRevision);nl_db_due($senderCampaign);newsletter_work();$senderDelivery=nl_db_delivery($senderCampaign,$renewed);
$CONFIG['mail']['reply_to']='changed@example.com';
nl_db_check(nl_db_run_gate($senderDelivery)['action']==='defer'&&newsletter_campaign($senderCampaign)['status']==='paused','Changed sender settings pause queued approved mail.');
$CONFIG['mail']['reply_to']='reply@example.com';newsletter_transition($senderCampaign,'resume',(int)newsletter_campaign($senderCampaign)['state_version'],$actor,$when);nl_db_due($senderCampaign);
db()->prepare("UPDATE email_outbox SET status='sent',provider_id='synthetic-durable-ack',sent_at=NOW() WHERE id=?")->execute([$senderDelivery['outbox_id']]);
nl_db_check(email_work()['enabled']===false,'Reconciliation remains available while live mail is disabled.');
$repaired=nl_db_delivery($senderCampaign,$renewed);
nl_db_check($repaired['status']==='accepted'&&$repaired['provider_id']==='synthetic-durable-ack','Durable outbox acknowledgement repairs interrupted campaign bookkeeping.');
nl_db_check(abs((new DateTimeImmutable($repaired['accepted_at'],new DateTimeZone('UTC')))->getTimestamp()-time())<10,'Recovered acknowledgement timestamps convert the legacy DB timezone to UTC.');

[$addressCampaign,$addressRevision]=nl_db_approved('Recipient address fixture');nl_db_schedule($addressCampaign,$addressRevision);nl_db_due($addressCampaign);newsletter_work();$addressDelivery=nl_db_delivery($addressCampaign,$renewed);
db()->prepare('UPDATE newsletter_subscribers SET email=? WHERE id=?')->execute(['changed-address@example.invalid',$renewed]);
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});
nl_db_check($calls===0&&nl_db_delivery($addressCampaign,$renewed)['status']==='suppressed','Changing a subscriber address cannot redirect a frozen audience delivery.');

[$feedbackCampaign,$feedbackRevision]=nl_db_approved('Late suppression fixture');nl_db_schedule($feedbackCampaign,$feedbackRevision);nl_db_due($feedbackCampaign);newsletter_work();$feedbackDelivery=nl_db_delivery($feedbackCampaign,$renewed);
$changedEmail=newsletter_subscriber($renewed)['email'];
db()->prepare("INSERT INTO mail_suppressions(email_hash,email_encrypted,reason,created_at,updated_at) VALUES(?,?,'hard_bounce',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([email_address_hash($changedEmail),encrypt_secret($changedEmail)]);
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});
nl_db_check($calls===0&&nl_db_delivery($feedbackCampaign,$renewed)['status']==='suppressed','New central suppression after queueing wins before campaign transport.');
nl_db_check((int)nl_db_scalar('SELECT COUNT(*) FROM newsletter_events WHERE campaign_id=?',[$feedbackCampaign])>=3,'Draft, approval and schedule transitions retain an audit trail.');
nl_db_check((int)nl_db_scalar('SELECT COUNT(*) FROM newsletter_deliveries WHERE status IN (\'pending\',\'queued\')')===0,'The fixture leaves no eligible delivery waiting for accidental transport.');

// Confirmation capabilities prove the issued address, not whichever address a
// subscriber row happens to contain later. Exercise the real queued-send hook.
$confirmationFixture=static function(string $label):array{
    $email='confirmation-'.$label.'@example.invalid';newsletter_request_signup($email,'Synthetic binding fixture');
    $subscriber=nl_db_row('SELECT * FROM newsletter_subscribers WHERE email=?',[$email]);
    $outbox=nl_db_row('SELECT * FROM email_outbox ORDER BY id DESC LIMIT 1');$payload=json_decode(decrypt_secret($outbox['payload']),true,512,JSON_THROW_ON_ERROR);
    if(!preg_match('~/newsletter/confirm\?token=([a-f0-9]{64})~',$payload['html'],$match))throw new RuntimeException('Missing fixture confirmation token.');
    return [$subscriber,$outbox,$payload,$match[1]];
};
[$bound,$boundOutbox,$boundPayload,$boundToken]=$confirmationFixture('changed-before-send');
$boundHash=hash('sha256',$boundToken);
nl_db_check(nl_db_scalar('SELECT issued_email_hash FROM newsletter_tokens WHERE token_hash=?',[$boundHash])===newsletter_email_hash($bound['email']),'New confirmation tokens freeze only the normalized issuance address hash.');
$publicRecord=newsletter_token_record($boundToken,'confirm');
nl_db_check(!array_key_exists('email',$publicRecord)&&!array_key_exists('issued_email_hash',$publicRecord),'Public confirmation token metadata exposes neither address nor issuance hash.');
db()->prepare('UPDATE newsletter_subscribers SET email=? WHERE id=?')->execute(['changed-before-send@example.invalid',$bound['id']]);
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});
nl_db_check($calls===0&&nl_db_scalar('SELECT status FROM email_outbox WHERE id=?',[$boundOutbox['id']])==='suppressed','An address change before queue transport suppresses the old confirmation email.');
nl_db_check(!newsletter_apply_token($boundToken,'confirm')&&newsletter_subscriber((int)$bound['id'])['status']==='pending','An old capability cannot confirm the changed subscriber address.');

[$sentBinding,$sentOutbox,$sentPayload,$sentToken]=$confirmationFixture('changed-after-send');
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'synthetic-confirmation-ack';});
nl_db_check($calls===1&&nl_db_scalar('SELECT status FROM email_outbox WHERE id=?',[$sentOutbox['id']])==='sent','An unchanged confirmation recipient reaches only the offline fixture transport.');
db()->prepare('UPDATE newsletter_subscribers SET email=? WHERE id=?')->execute(['changed-after-send@example.invalid',$sentBinding['id']]);
nl_db_check(!newsletter_apply_token($sentToken,'confirm')&&newsletter_subscriber((int)$sentBinding['id'])['status']==='pending','A link already delivered to the previous mailbox cannot confirm a later replacement address.');

[$queueBinding,$queueOutbox,$queuePayload,$queueToken]=$confirmationFixture('wrong-envelope');
nl_db_check(newsletter_confirmation_deliverable($queuePayload['dedupe_key'],strtoupper($queueBinding['email'])),'Equivalent address casing preserves valid confirmation eligibility.');
nl_db_check(!newsletter_confirmation_deliverable($queuePayload['dedupe_key'],'different-envelope@example.invalid'),'Final confirmation gating compares the immutable envelope recipient too.');
$queuePayload['to']='different-envelope@example.invalid';db()->prepare('UPDATE email_outbox SET payload=? WHERE id=?')->execute([encrypt_secret(json_encode($queuePayload,JSON_THROW_ON_ERROR)),$queueOutbox['id']]);
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});
nl_db_check($calls===0&&nl_db_scalar('SELECT status FROM email_outbox WHERE id=?',[$queueOutbox['id']])==='suppressed','A misbound confirmation envelope cannot send a capability to another mailbox.');

// Additive migration leaves old rows untouched; use encrypted issuance evidence
// when available and ask for a new opt-in when it cannot be established safely.
[$legacy,$legacyOutbox,$legacyPayload,$legacyToken]=$confirmationFixture('legacy-proven');$legacyHash=hash('sha256',$legacyToken);
db()->prepare('UPDATE newsletter_tokens SET issued_email_hash=NULL WHERE token_hash=?')->execute([$legacyHash]);
nl_db_check(newsletter_confirmation_deliverable($legacyPayload['dedupe_key'],$legacy['email']),'A restored legacy token remains deliverable when its encrypted outbox proves the same issued address.');
nl_db_check(newsletter_apply_token($legacyToken,'confirm'),'A legacy confirmation with matching immutable issuance evidence remains usable.');
nl_db_check(nl_db_scalar('SELECT issued_email_hash FROM newsletter_tokens WHERE token_hash=?',[$legacyHash])===null,'Legacy acceptance does not rewrite missing history with the current address.');
email_work(100,fn()=> 'must-not-send-consumed-confirmation');
[$legacyChanged,$legacyChangedOutbox,$legacyChangedPayload,$legacyChangedToken]=$confirmationFixture('legacy-changed');$legacyChangedHash=hash('sha256',$legacyChangedToken);
db()->prepare('UPDATE newsletter_tokens SET issued_email_hash=NULL WHERE token_hash=?')->execute([$legacyChangedHash]);
db()->prepare('UPDATE newsletter_subscribers SET email=? WHERE id=?')->execute(['legacy-changed-address@example.invalid',$legacyChanged['id']]);
nl_db_check(!newsletter_confirmation_deliverable($legacyChangedPayload['dedupe_key'],$legacyChangedPayload['to']),'Legacy evidence cannot be rebound from its original mailbox to a changed current address.');
nl_db_check(!newsletter_apply_token($legacyChangedToken,'confirm'),'Legacy token consumption rejects a different current address.');
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send';});nl_db_check($calls===0,'Misbound legacy confirmations never reach transport.');

$unproved=nl_db_subscriber('confirmation-legacy-unproved','pending',false);$unprovedToken=newsletter_create_token($unproved,'confirm',date('Y-m-d H:i:s',time()+3600));$unprovedHash=hash('sha256',$unprovedToken);
db()->prepare('UPDATE newsletter_tokens SET issued_email_hash=NULL WHERE token_hash=?')->execute([$unprovedHash]);
nl_db_check(!newsletter_apply_token($unprovedToken,'confirm')&&newsletter_subscriber($unproved)['status']==='pending','A recovered token without issuance evidence cannot manufacture confirmed consent.');
nl_db_check((int)nl_db_scalar('SELECT COUNT(*) FROM newsletter_tokens WHERE token_hash=?',[$unprovedHash])===1,'Unprovable legacy token history is retained rather than deleted.');
newsletter_request_signup('confirmation-legacy-unproved@example.invalid','Fresh opt-in fixture');$fresh=nl_db_row('SELECT payload FROM email_outbox ORDER BY id DESC LIMIT 1');$freshPayload=json_decode(decrypt_secret($fresh['payload']),true,512,JSON_THROW_ON_ERROR);
preg_match('~/newsletter/confirm\?token=([a-f0-9]{64})~',$freshPayload['html'],$freshMatch);
nl_db_check(isset($freshMatch[1])&&newsletter_apply_token($freshMatch[1],'confirm'),'A reader with unprovable legacy history can complete a fresh bound double opt-in.');
$calls=0;email_work(100,function($payload)use(&$calls){if(str_starts_with($payload['dedupe_key'],'newsletter-lifecycle:'))return 'fixture-lifecycle-notice';$calls++;return 'must-not-send-consumed';});nl_db_check($calls===0,'Consumed replacement confirmation mail is suppressed normally.');

[$unreadable,$unreadableOutbox,$unreadablePayload,$unreadableToken]=$confirmationFixture('legacy-unreadable');
db()->prepare('UPDATE newsletter_tokens SET issued_email_hash=NULL WHERE token_hash=?')->execute([hash('sha256',$unreadableToken)]);
db()->prepare("UPDATE email_outbox SET payload='invalid encrypted fixture',status='suppressed' WHERE id=?")->execute([$unreadableOutbox['id']]);
nl_db_check(!newsletter_confirmation_deliverable($unreadablePayload['dedupe_key'],$unreadable['email'])&&!newsletter_apply_token($unreadableToken,'confirm'),'Unreadable legacy issuance evidence fails closed without disclosing its contents.');

// Lifecycle notices are bound to the exact recipient and consent revision.
$lifecycle=nl_db_subscriber('lifecycle-reader');
$life=newsletter_subscriber($lifecycle);$key='newsletter-lifecycle:welcome:'.$lifecycle.':'.$life['consent_revision'];
nl_db_check(newsletter_lifecycle_deliverable($key,$life['email'])&&!newsletter_lifecycle_deliverable($key,'other@example.invalid'),'Welcome cannot be redirected to another mailbox');
newsletter_queue_lifecycle($lifecycle,'welcome');newsletter_queue_lifecycle($lifecycle,'welcome');
nl_db_check((int)nl_db_scalar('SELECT COUNT(*) FROM email_outbox WHERE dedupe_key=?',[hash('sha256',$key)])===1,'The welcome queue is idempotent per consent revision');
$byeToken=newsletter_create_token($lifecycle,'unsubscribe',null);
nl_db_check(newsletter_apply_token($byeToken,'unsubscribe',true),'Requested human unsubscribe receipt records the change');
$life=newsletter_subscriber($lifecycle);$byeKey='newsletter-lifecycle:goodbye:'.$lifecycle.':'.$life['consent_revision'];
nl_db_check(!newsletter_lifecycle_deliverable($key,$life['email'])&&newsletter_lifecycle_deliverable($byeKey,$life['email']),'Unsubscribe suppresses an unsent welcome but allows the explicitly requested receipt');
$before=(int)nl_db_scalar('SELECT COUNT(*) FROM email_outbox');newsletter_apply_token($byeToken,'unsubscribe',true);
nl_db_check((int)nl_db_scalar('SELECT COUNT(*) FROM email_outbox')===$before,'Repeated unsubscribe cannot queue another receipt');
$lifeCalls=0;email_work(100,function($payload)use(&$lifeCalls,$byeKey){if($payload['dedupe_key']===$byeKey)$lifeCalls++;return 'offline-lifecycle-receipt';});
nl_db_check($lifeCalls===1,'The requested receipt reaches only the offline test transport once');
echo "PASS: {$checks} newsletter database assertions (offline transport only)\n";
