<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('WK_TEST_ALLOW_WRITES')!=='1'||!in_array(getenv('WK_TEST_DB_HOST'),['127.0.0.1','::1'],true)
    ||!preg_match('/\Awaithaka_test_[a-z0-9_]+_test\z/',(string)getenv('WK_TEST_DB_NAME'))){fwrite(STDERR,"Use a newly prepared, explicitly authorized loopback test database.\n");exit(2);}
$CONFIG=['app_env'=>'testing','app_key'=>'local-test-key-not-for-production-use','base_url'=>'https://www.waithakandegwa.com','worker_enabled'=>true,
    'timezone'=>'Africa/Nairobi','session_driver'=>'database','mail'=>['transport'=>'disabled'],'media'=>['driver'=>'local'],
    'db'=>['host'=>getenv('WK_TEST_DB_HOST'),'port'=>(int)getenv('WK_TEST_DB_PORT'),'name'=>getenv('WK_TEST_DB_NAME'),
        'user'=>getenv('WK_TEST_DB_USER'),'pass'=>getenv('WK_TEST_DB_PASS'),'charset'=>'utf8mb4']];
$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
require dirname(__DIR__).'/app/environment.php';require dirname(__DIR__).'/app/functions.php';require dirname(__DIR__).'/app/readiness.php';
$checks=0;$ids=[];$outboxId=0;$contactId=0;$subscriberId=0;$suffix=bin2hex(random_bytes(8));$connection='readiness_'.$suffix;
function ready_db_check(bool $ok,string $message): void { $GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message); }
try {
    $id=readiness_worker_begin(true);ready_db_check($id!==null,'Enabled worker start should persist.');$ids[]=$id;
    readiness_worker_touch($id,'email',['email'=>['sent'=>3,'secret'=>'SHOULD_NOT_BE_RECORDED']]);
    $row=db()->query('SELECT * FROM worker_runs WHERE id='.(int)$id)->fetch();
    ready_db_check($row['stage']==='email'&&!str_contains($row['counts_json'],'SHOULD_NOT_BE_RECORDED'),'Heartbeat must persist only safe metrics.');
    readiness_worker_finish($id,true,['email'=>['sent'=>3]]);readiness_worker_finish($id,false,[],'PRIVATE_ERROR_TEXT');
    $row=db()->query('SELECT * FROM worker_runs WHERE id='.(int)$id)->fetch();
    ready_db_check($row['state']==='completed'&&$row['error_code']===null,'Repeated finish must not downgrade a completed worker run.');
    ready_db_check(abs(readiness_age($row['finished_at'],time())??9999)<5,'Worker records must use UTC despite the legacy database session.');
    $disabled=readiness_worker_begin(false);ready_db_check($disabled!==null,'Paused timer invocation should be recorded.');$ids[]=$disabled;
    readiness_worker_touch($disabled,'publishing');readiness_worker_finish($disabled,true);
    ready_db_check(db()->query('SELECT state FROM worker_runs WHERE id='.(int)$disabled)->fetchColumn()==='disabled','Paused invocation must remain terminal.');
    $failed=readiness_worker_begin(true);$ids[]=$failed;readiness_worker_finish($failed,false,[],'PRIVATE_ERROR_TEXT');
    ready_db_check(db()->query('SELECT error_code FROM worker_runs WHERE id='.(int)$failed)->fetchColumn()==='worker_failed','Worker errors must not persist raw provider messages.');
    db()->prepare("INSERT INTO email_outbox(dedupe_key,payload,status,available_at,error_code)VALUES(?,?,'ambiguous',DATE_SUB(NOW(),INTERVAL 1 HOUR),?)")
        ->execute([hash('sha256',$suffix),encrypt_secret('PRIVATE_RECIPIENT_MESSAGE_AND_TOKEN'),'PRIVATE_ERROR_CODE']);$outboxId=(int)db()->lastInsertId();
    db()->prepare("INSERT INTO publishing_connections(service,provider,status,credentials_encrypted)VALUES(?,'fixture','reconnect',?)")->execute([$connection,encrypt_secret('PRIVATE_CONNECTION_TOKEN')]);
    $before=(int)db()->query('SELECT COUNT(*) FROM worker_runs')->fetchColumn();
    $snapshot=readiness_snapshot();$encoded=json_encode($snapshot,JSON_THROW_ON_ERROR);
    ready_db_check($snapshot['worker']['latest']['id']==$failed&&$snapshot['worker']['health']['state']==='blocked','Read-only status should report the most recent failed invocation.');
    ready_db_check($snapshot['email']['available']&&($snapshot['email']['counts']['ambiguous']??0)>=1,'Outbox outcome counts should be visible.');
    ready_db_check(in_array($connection,array_column($snapshot['connections'],'service'),true),'Reconnect requirement should link back to Connections.');
    foreach(['PRIVATE_RECIPIENT_MESSAGE_AND_TOKEN','PRIVATE_ERROR_CODE','PRIVATE_CONNECTION_TOKEN','PRIVATE_ERROR_TEXT','local-test-key-not-for-production-use']as$secret)
        ready_db_check(!str_contains($encoded,$secret),'Operational diagnostics must never include secrets, payloads or raw errors.');
    ready_db_check((int)db()->query('SELECT COUNT(*) FROM worker_runs')->fetchColumn()===$before,'Reading operations must not create a worker run.');
    ready_db_check(db()->query('SELECT status FROM email_outbox WHERE id='.(int)$outboxId)->fetchColumn()==='ambiguous','Reading operations must not retry or settle email.');
    db()->prepare("UPDATE email_outbox SET status='pending',error_code='contact_owner_review' WHERE id=?")->execute([$outboxId]);
    $pendingSnapshot=readiness_snapshot();
    $pendingRows=array_values(array_filter($pendingSnapshot['email']['pending_review'],static fn($row)=>(int)$row['id']===$outboxId));
    ready_db_check(count($pendingRows)===1 && $pendingRows[0]['reason']==='Contact message awaits owner review.','Pending messages need an individual safe review reason.');
    ready_db_check(!array_key_exists('error_code',$pendingRows[0]) && !str_contains(json_encode($pendingSnapshot),'PRIVATE_RECIPIENT_MESSAGE_AND_TOKEN'),'Pending review must not expose payloads or raw errors.');
    ready_db_check(db()->query('SELECT status FROM email_outbox WHERE id='.(int)$outboxId)->fetchColumn()==='pending','Pending inspection must not release or suppress a message.');
    ready_db_check($pendingRows[0]['source']===null,'An unmatched queue item must not invent a source.');
    $reference='WK-'.strtoupper($suffix);
    db()->prepare("INSERT INTO contact_messages(reference,first_name,last_name,email,phone_code,phone,country,city,message) VALUES(?,'PRIVATE_NAME','PRIVATE_NAME','private@example.invalid','+254','000','Kenya','PRIVATE_CITY','PRIVATE_BODY')")->execute([$reference]);$contactId=(int)db()->lastInsertId();
    db()->prepare("INSERT INTO reader_enquiries(contact_id,created_at,updated_at) VALUES(?,UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$contactId]);$enquiryId=(int)db()->lastInsertId();
    foreach(['contact:'=>'Contact notification','contact-receipt:'=>'Contact acknowledgement']as$prefix=>$label) {
        db()->prepare('UPDATE email_outbox SET dedupe_key=? WHERE id=?')->execute([hash('sha256',$prefix.$reference),$outboxId]);
        $matched=readiness_snapshot();$rows=array_values(array_filter($matched['email']['pending_review'],static fn($row)=>(int)$row['id']===$outboxId));
        ready_db_check($rows[0]['source']===['label'=>$label,'path'=>'/write/readers/enquiries/'.$enquiryId],'Each contact message must resolve to its exact protected enquiry.');
        foreach(['PRIVATE_NAME','private@example.invalid','PRIVATE_CITY','PRIVATE_BODY',$reference]as$private)ready_db_check(!str_contains(json_encode($matched),$private),'Source resolution must not disclose contact fields.');
    }
    ready_db_check(readiness_email_sources([$outboxId],[])===[],'Missing source tables must not fabricate a source.');
    ready_db_check(db()->query('SELECT status FROM email_outbox WHERE id='.(int)$outboxId)->fetchColumn()==='pending','Source resolution is read-only.');
    $diagnostic=readiness_email_diagnostic($outboxId);
    ready_db_check($diagnostic['category']==='Message origin could not be read','Malformed encrypted payloads return a safe diagnostic.');
    $originKey='notify:'.$suffix;
    db()->prepare('UPDATE email_outbox SET dedupe_key=?,payload=? WHERE id=?')->execute([hash('sha256',$originKey),encrypt_secret(json_encode(['dedupe_key'=>$originKey,'to'=>'private@example.invalid','subject'=>'PRIVATE_SUBJECT','html'=>'PRIVATE_BODY'])),$outboxId]);
    $diagnostic=readiness_email_diagnostic($outboxId);
    ready_db_check($diagnostic['category']==='Writer notification','The diagnostic identifies an authenticated stored origin.');
    foreach(['private@example.invalid','PRIVATE_SUBJECT','PRIVATE_BODY',$originKey]as$private)ready_db_check(!str_contains(json_encode($diagnostic),$private),'Owner diagnostic must not expose payload values.');
    ready_db_check(db()->query('SELECT status FROM email_outbox WHERE id='.(int)$outboxId)->fetchColumn()==='pending','Diagnostics never release or suppress queued mail.');
    db()->prepare("INSERT INTO newsletter_subscribers(email,consent_version,requested_at,created_at,updated_at) VALUES(?,'fixture',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute([$suffix.'@example.invalid']);$subscriberId=(int)db()->lastInsertId();
    $tokenHash=hash('sha256','private-token-'.$suffix);
    db()->prepare("INSERT INTO newsletter_tokens(subscriber_id,purpose,token_hash,expires_at,created_at) VALUES(?,'confirm',?,DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY),UTC_TIMESTAMP())")->execute([$subscriberId,$tokenHash]);
    db()->prepare('UPDATE email_outbox SET dedupe_key=? WHERE id=?')->execute([hash('sha256','newsletter-confirm:'.$tokenHash),$outboxId]);
    $sourceTables=['newsletter_tokens','newsletter_subscribers'];
    $source=readiness_email_sources([$outboxId],$sourceTables);
    ready_db_check($source[$outboxId]===['label'=>'Expired subscription confirmation','path'=>'/write/newsletter/subscribers/'.$subscriberId],'Expired confirmation links must point to the exact subscriber without exposing tokens.');
    db()->prepare('UPDATE newsletter_tokens SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE token_hash=?')->execute([$tokenHash]);
    ready_db_check(readiness_email_sources([$outboxId],$sourceTables)[$outboxId]['label']==='Subscription confirmation','A future unused token must not be labelled expired.');
    db()->prepare('UPDATE newsletter_tokens SET used_at=UTC_TIMESTAMP() WHERE token_hash=?')->execute([$tokenHash]);
    ready_db_check(readiness_email_sources([$outboxId],$sourceTables)[$outboxId]['label']==='Used subscription confirmation','A used confirmation must never look reusable.');
    ready_db_check($snapshot['migrations']['available']&&$snapshot['migrations']['pending']===[]&&$snapshot['migrations']['changed']===[],'Prepared migration checksums should be reported without applying changes.');
} finally {
    foreach($ids as$id)if($id)db()->prepare('DELETE FROM worker_runs WHERE id=?')->execute([$id]);
    if($subscriberId)db()->prepare('DELETE FROM newsletter_subscribers WHERE id=?')->execute([$subscriberId]);
    if($contactId)db()->prepare('DELETE FROM contact_messages WHERE id=?')->execute([$contactId]);
    if($outboxId)db()->prepare('DELETE FROM email_outbox WHERE id=?')->execute([$outboxId]);
    db()->prepare('DELETE FROM publishing_connections WHERE service=?')->execute([$connection]);
}
echo "Readiness database checks passed: $checks\n";
