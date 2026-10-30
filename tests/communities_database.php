<?php
declare(strict_types=1);
$dsn=getenv('PUBLISHING_TEST_DSN')?:'';
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'||!preg_match('/^mysql:host=127\.0\.0\.1;port=[0-9]+;dbname=waithaka_test_[a-z0-9_]+_test;charset=utf8mb4$/D',$dsn))throw new RuntimeException('Use an isolated loopback fixture schema.');
$pdo=new PDO($dsn,getenv('PUBLISHING_TEST_USER'),getenv('PUBLISHING_TEST_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$CONFIG=['app_key'=>'community-database-fixture-not-production','app_env'=>'testing','timezone'=>'Africa/Nairobi','base_url'=>'https://www.waithakandegwa.com'];$APP_INSTALLED=true;
// Bind the prepared disposable PDO; production functions are otherwise unchanged.
function db():PDO{return $GLOBALS['pdo'];}
function app_config(?string $key=null,mixed $default=null):mixed{return $GLOBALS['CONFIG'][$key]??$default;}
function url(string $path=''):string{return 'https://www.waithakandegwa.com/'.ltrim($path,'/');}
function encrypt_secret(string $value):string{$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',hash('sha256',$GLOBALS['CONFIG']['app_key'],true),OPENSSL_RAW_DATA,$iv,$tag);return base64_encode($iv.$tag.$cipher);}
function decrypt_secret(string $value):string{$v=base64_decode($value,true);return openssl_decrypt(substr($v,28),'aes-256-gcm',hash('sha256',$GLOBALS['CONFIG']['app_key'],true),OPENSSL_RAW_DATA,substr($v,0,12),substr($v,12,16));}
require_once __DIR__.'/../app/publishing.php';require_once __DIR__.'/../app/migrations.php';
require_once __DIR__.'/../app/community_channel_catalogue.php';
$n=0;function ck(bool $ok,string $why):void{$GLOBALS['n']++;if(!$ok)throw new RuntimeException($why);}
// Recover the exact production gap: deployed code with only the original six rows.
$pdo->exec("DELETE FROM publishing_channels WHERE slug IN ('threads','telegram','discord','signal')");
$pdo->exec("DELETE FROM schema_migrations WHERE name='200_community_channels.sql'");
$sixBefore=publishing_channels();
ck(count($sixBefore)===6&&count(community_catalogue_missing($pdo))===4,'Read-only plan detects the four missing catalogue rows');
ck(publishing_channels()===$sixBefore,'Planning never registers channels');
community_catalogue_apply($pdo);
ck(count(publishing_channels())===10&&community_catalogue_missing($pdo)===[],'Deployment data registration repairs a six-channel installation');
ck(array_values(array_filter(publishing_channels(),static fn(array $row):bool=>in_array($row['slug'],array_column($sixBefore,'slug'),true)))===$sixBefore,'Registration preserves the original six channel records exactly');
ck($pdo->query("SELECT sha256 FROM schema_migrations WHERE name='200_community_channels.sql'")->fetchColumn()===community_catalogue_hash(),'Registration records the unchanged SQL migration checksum');
$pdo->exec("UPDATE publishing_channels SET name='Owner confirmed Threads',profile_url='https://www.threads.net/@owner' WHERE slug='threads'");
$tenBefore=publishing_channels();community_catalogue_apply($pdo);
ck(publishing_channels()===$tenBefore,'Repeated deploy preserves owner destinations and all existing rows');
$pdo->exec("UPDATE schema_migrations SET sha256=REPEAT('0',64) WHERE name='200_community_channels.sql'");
$rejected=false;try{community_catalogue_apply($pdo);}catch(RuntimeException){$rejected=true;}
ck($rejected&&!$pdo->inTransaction()&&publishing_channels()===$tenBefore,'Mismatched applied migration fails safely with a rolled-back transaction');
$pdo->prepare("UPDATE schema_migrations SET sha256=? WHERE name='200_community_channels.sql'")->execute([community_catalogue_hash()]);
ck(count(publishing_channels())===10,'Migration registers all ten channels');$before=$pdo->query("SELECT * FROM publishing_channels WHERE slug IN ('linkedin','facebook','instagram','x','youtube','tiktok') ORDER BY id")->fetchAll();
foreach(wk_sql_statements(file_get_contents(__DIR__.'/../app/migrations/200_community_channels.sql'))as$sql)$pdo->exec($sql);
ck(count(publishing_channels())===10&&$before===$pdo->query("SELECT * FROM publishing_channels WHERE slug IN ('linkedin','facebook','instagram','x','youtube','tiktok') ORDER BY id")->fetchAll(),'Migration is additive and idempotent');
foreach(['threads','telegram','discord','signal']as$slug)ck(publishing_channel($slug)['delivery_method']==='manual','New channel does not activate a queue');
$draft=publishing_default_draft();$draft['title']='Legacy fixture';foreach(['threads','telegram','discord','signal']as$slug)unset($draft['variants'][$slug]);$legacy=publishing_normalize_draft($draft);ck(count($legacy['variants'])===10,'Legacy six-channel draft gains empty new variants');foreach(['threads','telegram','discord','signal']as$slug)ck(!$legacy['variants'][$slug]['enabled'],'New variant remains unselected');
$legacy['variants']['facebook']['enabled']=true;$legacy['variants']['facebook']['text']='Reviewed legacy content';$id=publishing_save_package($legacy,7);$revision=publishing_approve($id,1,7);$q=$pdo->prepare('SELECT snapshot_json FROM publishing_revisions WHERE id=?');$q->execute([$revision]);$frozen=$q->fetchColumn();ck(array_keys(publishing_decode($frozen)['destinations'])===['facebook'],'Existing selected delivery retains its destination');
publishing_community_setup('signal',['destination_label'=>'Synthetic private group'],7);$c=publishing_connection('signal');ck($c['status']==='pending_confirmation'&&empty($c['selected_resource']),'Signal declaration requires confirmation');ck(!empty(publishing_decode($c['metadata_json'])['owner_declared']),'Signal declaration is not API verification');publishing_confirm_connection('signal',publishing_decode($c['resources_json'])[0]['id'],7);$c=publishing_connection('signal');$channel=publishing_channel('signal');$d=publishing_destination($channel);ck($d['community_label']==='Synthetic private group'&&$d['community_target']===$c['selected_resource'],'Assisted destination freezes exact private label');
$direct=$channel;$direct['delivery_method']='direct';ck(!publishing_capability($direct,$c)['usable'],'Signal can never become direct delivery');
publishing_community_setup('discord',['assisted_only'=>'1','destination_label'=>'Synthetic Discord group'],7);$c=publishing_connection('discord');publishing_confirm_connection('discord',publishing_decode($c['resources_json'])[0]['id'],7);$c=publishing_connection('discord');$direct=publishing_channel('discord');$direct['delivery_method']='direct';ck(!publishing_capability($direct,$c)['usable'],'A declared Discord label is not a webhook grant');
$q->execute([$revision]);ck($q->fetchColumn()===$frozen,'New destination setup never rewrites prior approval');ck((int)$pdo->query('SELECT COUNT(*) FROM publishing_jobs')->fetchColumn()===0,'Setup and approval never send or queue messages');
echo 'Passed '.$n." community migration/legacy database checks.\n";
