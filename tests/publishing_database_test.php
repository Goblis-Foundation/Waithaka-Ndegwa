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

// Upgrade a real pre-090 configuration with an approved partner destination.
// Synthetic ciphertext is never decrypted or sent; every provider operation is local policy only.
$legacyTikTok=publishing_channel('tiktok');
check_db($legacyTikTok['account_type']==='personal','The historical 030 seed remains intact for a realistic upgrade');
$remote=['id'=>'fixture-tiktok-channel','name'=>'Fixture TikTok','displayName'=>'Fixture TikTok','service'=>'tiktok','serviceId'=>'fixture-tiktok-identity','type'=>'profile','externalLink'=>'https://www.tiktok.com/@fixture_account','organizationId'=>'fixture-organization','isDisconnected'=>false,'isLocked'=>false,'isQueuePaused'=>false,'hasActiveMemberDevice'=>true,'allowedActions'=>['scheduleUpdates']];
$pdo->prepare('INSERT INTO publishing_connections(service,provider,status,external_id,display_name,credentials_encrypted,scopes_json,resources_json,metadata_json,selected_resource,verified_at,connected_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['buffer','buffer','connected','fixture-buffer-owner','Fixture Buffer owner','synthetic-encrypted-token-preservation-fixture','[]',publishing_json([['id'=>'fixture-organization','name'=>'Fixture organization','type'=>'buffer_organization']]),publishing_json(['channels'=>[$remote]]),'fixture-organization',gmdate('Y-m-d H:i:s'),1]);
$connectionId=(int)$pdo->lastInsertId();
$pdo->prepare("UPDATE publishing_channels SET delivery_method='buffer',buffer_channel_id=?,buffer_mode='automatic' WHERE slug='tiktok'")->execute([$remote['id']]);
$tiktokDraft=publishing_default_draft();$tiktokDraft['title']='TikTok upgrade fixture';
$tiktokDraft['variants']['tiktok']['enabled']=true;$tiktokDraft['variants']['tiktok']['text']='Synthetic migration preservation test.';
$tiktokDraft['variants']['tiktok']['media_url']='https://media.example.com/fixture.mp4';$tiktokDraft['variants']['tiktok']['media_type']='video';
$tiktokPackage=publishing_save_package($tiktokDraft,1);
$legacyRevisionId=publishing_approve($tiktokPackage,1,1);
check_db(publishing_schedule($tiktokPackage,$legacyRevisionId,$local,1)===1,'A legacy TikTok destination has a scheduled approval before migration');
$legacyRevision=$pdo->query('SELECT * FROM publishing_revisions WHERE id='.$legacyRevisionId)->fetch();
$legacyJob=$pdo->query('SELECT * FROM publishing_jobs WHERE revision_id='.$legacyRevisionId)->fetch();
$legacyPackage=publishing_package($tiktokPackage);
$oldReviewHash=hash('sha256',publishing_json(['tiktok'=>publishing_destination(publishing_channel('tiktok'),$tiktokDraft['variants']['tiktok'])]));
$pendingPackage=publishing_save_package($tiktokDraft,1);
$channelBefore=publishing_channel('tiktok');
$otherChannelsBefore=$pdo->query("SELECT * FROM publishing_channels WHERE slug<>'tiktok' ORDER BY id")->fetchAll();
$connectionsBefore=$pdo->query('SELECT * FROM publishing_connections ORDER BY id')->fetchAll();
$statesBefore=$pdo->query('SELECT * FROM publishing_oauth_states ORDER BY state_hash')->fetchAll();
$migration090=file_get_contents(__DIR__.'/../app/migrations/090_tiktok_professional.sql');
$apply090=static function()use($pdo,$migration090):void{foreach(preg_split('/;\s*(?:\r?\n|$)/',trim($migration090)) as $statement)if(trim($statement)!=='')$pdo->exec($statement);};
$apply090();
$channelAfter=publishing_channel('tiktok');
check_db($channelAfter['account_type']==='professional','Migration090 corrects the existing TikTok default to professional');
$comparisonBefore=$channelBefore;$comparisonAfter=$channelAfter;
unset($comparisonBefore['account_type'],$comparisonBefore['updated_at'],$comparisonAfter['account_type'],$comparisonAfter['updated_at']);
check_db($comparisonAfter===$comparisonBefore,'TikTok ID, profile URL, delivery method and selected partner channel are preserved');
check_db($pdo->query("SELECT * FROM publishing_channels WHERE slug<>'tiktok' ORDER BY id")->fetchAll()===$otherChannelsBefore,'Other platform declarations and routes are unchanged');
check_db($pdo->query('SELECT * FROM publishing_connections ORDER BY id')->fetchAll()===$connectionsBefore,'Returned identities, encrypted credentials, granted scopes and resources are unchanged');
check_db($pdo->query('SELECT * FROM publishing_oauth_states ORDER BY state_hash')->fetchAll()===$statesBefore,'Outstanding OAuth states and private verifiers are unchanged');
check_db($pdo->query('SELECT * FROM publishing_revisions WHERE id='.$legacyRevisionId)->fetch()===$legacyRevision,'The old approved snapshot, content hash and approval time remain byte-identical');
check_db($pdo->query('SELECT * FROM publishing_jobs WHERE revision_id='.$legacyRevisionId)->fetch()===$legacyJob,'The old scheduled job, dedupe key, frozen destination and provider state remain unchanged');
check_db(publishing_package($tiktokPackage)===$legacyPackage,'The existing package keeps its approved revision');
check_db((int)publishing_job_connection($legacyJob)['id']===$connectionId,'An existing approved job still resolves the exact confirmed connection');
publishing_validate_buffer_destination(publishing_decode($legacyJob['destination_json']),$remote,'automatic');
check_db(true,'The frozen legacy declaration remains eligible under its originally approved partner route');
check_db(publishing_schedule($tiktokPackage,$legacyRevisionId,$local,1)===0,'Re-scheduling a pre-migration approval creates no duplicate job');
rejects_db(fn()=>publishing_approve($pendingPackage,1,1,$oldReviewHash),'A review opened before the classification correction must be refreshed');
$newDestination=publishing_destination(publishing_channel('tiktok'),$tiktokDraft['variants']['tiktok']);
$newReviewHash=hash('sha256',publishing_json(['tiktok'=>$newDestination]));
$newRevisionId=publishing_approve($pendingPackage,1,1,$newReviewHash);
$newSnapshot=publishing_decode($pdo->query('SELECT snapshot_json FROM publishing_revisions WHERE id='.$newRevisionId)->fetchColumn());
check_db($newSnapshot['destinations']['tiktok']['account_type']==='professional','A refreshed new approval freezes the corrected professional declaration');
check_db($newSnapshot['destinations']['tiktok']['connection_external_id']===$legacyJob['connection_external_id']&&$newSnapshot['destinations']['tiktok']['service_id']===$remote['serviceId'],'The classification correction does not invent or switch the provider identity');
$apply090();
check_db(publishing_channel('tiktok')===$channelAfter,'Reapplying the correction is idempotent');
$pdo->exec("UPDATE publishing_channels SET account_type='business' WHERE slug='tiktok'");
$specificType=publishing_channel('tiktok');$apply090();
check_db(publishing_channel('tiktok')===$specificType,'The migration does not overwrite a more specific existing declaration');
$pdo->exec("UPDATE publishing_channels SET account_type='professional' WHERE slug='tiktok'");
echo "Publishing database tests passed: {$n}\n";
