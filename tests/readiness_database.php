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
$checks=0;$ids=[];$outboxId=0;$suffix=bin2hex(random_bytes(8));$connection='readiness_'.$suffix;
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
    ready_db_check($snapshot['migrations']['available']&&$snapshot['migrations']['pending']===[]&&$snapshot['migrations']['changed']===[],'Prepared migration checksums should be reported without applying changes.');
} finally {
    foreach($ids as$id)if($id)db()->prepare('DELETE FROM worker_runs WHERE id=?')->execute([$id]);
    if($outboxId)db()->prepare('DELETE FROM email_outbox WHERE id=?')->execute([$outboxId]);
    db()->prepare('DELETE FROM publishing_connections WHERE service=?')->execute([$connection]);
}
echo "Readiness database checks passed: $checks\n";
