<?php
declare(strict_types=1);

/** Run only against a dedicated disposable schema. No provider calls are made.
 * PUBLISHING_TEST_DSN='mysql:host=127.0.0.1;port=...;dbname=waithaka_test_publishing'
 * PUBLISHING_TEST_USER=... PUBLISHING_TEST_PASSWORD=... php tests/publishing_database_test.php
 */
$dsn=getenv('PUBLISHING_TEST_DSN')?:'';
if(!preg_match('/(?:^|;)dbname=(?:waithaka_test_[a-z0-9_]+|waithaka_publishing_test)(?:;|$)/',$dsn)){fwrite(STDERR,"Set PUBLISHING_TEST_DSN to an explicitly disposable waithaka_test_* or waithaka_publishing_test schema.\n");exit(2);}
$pdo=new PDO($dsn,getenv('PUBLISHING_TEST_USER')?:'root',getenv('PUBLISHING_TEST_PASSWORD')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
function db(): PDO {global $pdo;return $pdo;}
function app_config(?string $key=null,mixed $default=null):mixed{return ['timezone'=>'Africa/Nairobi','app_env'=>'test','base_url'=>'https://www.waithakandegwa.com'][$key]??$default;}
function url(string $path=''):string{return 'https://www.waithakandegwa.com/'.ltrim($path,'/');}
require_once __DIR__.'/../app/publishing.php';
$pdo->exec("SET time_zone='+03:00'");
foreach(['publishing_events','publishing_jobs','publishing_revisions','publishing_packages','publishing_oauth_states','publishing_channels','publishing_connections'] as $table)$pdo->exec('DROP TABLE IF EXISTS '.$table);
$sql=file_get_contents(__DIR__.'/../app/migrations/030_publishing.sql');
foreach(preg_split('/;\s*(?:\r?\n|$)/',trim($sql)) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
$n=0;
function check_db(bool $condition,string $label):void{global $n;if(!$condition)throw new RuntimeException('FAIL: '.$label);$n++;}
function rejects_db(callable $action,string $label):void{try{$action();}catch(RuntimeException $e){check_db(true,$label);return;}check_db(false,$label);}
$reminders=[];
function publishing_send_reminder(array $job,array $snapshot):bool{global $reminders;$reminders[$job['job_key']]=true;return true;}

$draft=publishing_default_draft();$draft['title']='Test release';$draft['variants']['facebook']['enabled']=true;$draft['variants']['facebook']['text']='A deliberately manual test.';
$packageId=publishing_save_package($draft,1);
$rev=publishing_approve($packageId,1,1);
$approvedAt=(string)$pdo->query('SELECT approved_at FROM publishing_revisions WHERE id='.(int)$rev)->fetchColumn();
check_db(abs((new DateTimeImmutable($approvedAt,new DateTimeZone('UTC')))->getTimestamp()-time())<10,'Approval time is stored in UTC even with the legacy Nairobi database session');
$snapshot=(string)$pdo->query('SELECT snapshot_json FROM publishing_revisions WHERE id='.(int)$rev)->fetchColumn();
$local=(new DateTimeImmutable('now',new DateTimeZone('Africa/Nairobi')))->modify('+1 hour')->format('Y-m-d\TH:i');
check_db(publishing_schedule($packageId,$rev,$local,1)===1,'Exactly one selected channel creates a job');
check_db(publishing_schedule($packageId,$rev,$local,1)===0,'Repeated scheduling is idempotent');
$draft['variants']['facebook']['text']='A later unapproved draft.';publishing_save_package($draft,1,$packageId,1);
check_db((string)$pdo->query('SELECT snapshot_json FROM publishing_revisions WHERE id='.(int)$rev)->fetchColumn()===$snapshot,'Editing a draft does not change approved content');
check_db(publishing_package($packageId)['approved_revision_id']===null,'Changing the draft clears its current approval');
rejects_db(fn()=>publishing_approve($packageId,1,1),'Stale version is rejected');
rejects_db(fn()=>publishing_approve($packageId,2,1,str_repeat('0',64)),'Changed destination review is rejected');
$pdo->exec("UPDATE publishing_jobs SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)");
$result=publishing_work();
check_db($result['assisted']===1,'Due assisted job creates a completion task');
check_db($pdo->query('SELECT status FROM publishing_jobs')->fetchColumn()==='reminder_queued','Enqueued reminder remains distinct from a published post');
check_db(count($reminders)===1,'One reminder has a stable deduplication key');
check_db(publishing_work()['processed']===0,'Repeated worker invocation does not repeat an assisted task');
$pdo->exec("UPDATE publishing_jobs SET status='processing',locked_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 20 MINUTE)");
publishing_work();
check_db($pdo->query('SELECT status FROM publishing_jobs')->fetchColumn()==='ambiguous','Abandoned in-flight work becomes ambiguous and is never replayed');
check_db(count($reminders)===1,'Ambiguous work creates no duplicate reminder or delivery');

session_id('publishing-offline-test');
$state=str_repeat('a',64);$hash=hash('sha256',$state);
$pdo->prepare('INSERT INTO publishing_oauth_states(state_hash,service,user_id,session_hash,expires_at) VALUES(?,?,?,?,?)')->execute([$hash,'x',1,hash('sha256',session_id()),gmdate('Y-m-d H:i:s',time()+600)]);
publishing_consume_state('x',$state,1);check_db(true,'OAuth state binds to the initiating Writer session');
rejects_db(fn()=>publishing_consume_state('x',$state,1),'OAuth state replay is rejected');
$other=str_repeat('b',64);$pdo->prepare('INSERT INTO publishing_oauth_states(state_hash,service,user_id,session_hash,expires_at) VALUES(?,?,?,?,?)')->execute([hash('sha256',$other),'x',1,hash('sha256','another-session'),gmdate('Y-m-d H:i:s',time()+600)]);
rejects_db(fn()=>publishing_consume_state('x',$other,1),'Authorization cannot cross Writer sessions');
echo "Publishing database tests passed: {$n}\n";
