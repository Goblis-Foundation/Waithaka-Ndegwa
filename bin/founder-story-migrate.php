<?php
declare(strict_types=1);
/** Narrow additive production migration. Never resets or rewrites existing application tables. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/migrations.php';
require dirname(__DIR__).'/app/founder_story_migration.php';
$apply=in_array('--apply',$argv,true);
// Routine releases use the runtime reader to verify the ledger and never reopen admin access.
$hash=hash_file('sha256',dirname(__DIR__).'/app/migrations/170_founder_story_approval.sql');
$q=db()->prepare('SELECT sha256 FROM schema_migrations WHERE name=?');$q->execute(['170_founder_story_approval.sql']);$already=$q->fetchColumn();
if($already!==false){if(!hash_equals((string)$already,$hash))throw new RuntimeException('The applied founder migration differs from this release.');fwrite(STDOUT,"Founder story migration already verified with runtime read access.\n");exit;}

if(array_diff(array_slice($argv,1),['--apply','--plan']))throw new RuntimeException('Use --plan or --apply.');
$region=(string)app_config('aws_region','');$arn=(string)getenv('DB_ADMIN_SECRET_ARN');
if($region!=='af-south-1'||!preg_match('~^arn:aws:secretsmanager:af-south-1:466401734428:secret:[A-Za-z0-9/!_+=.@-]+$~D',$arn))throw new RuntimeException('The exact production database migration identity is required.');
$file=dirname(__DIR__).'/app/migrations/170_founder_story_approval.sql';$hash=hash_file('sha256',$file);$statements=wk_sql_statements((string)file_get_contents($file));
founder_story_migration_statements($file);
if(!$apply){fwrite(STDOUT,"PLAN: Add the founder approval column, lookup index and three review tables; record migration 170. Existing application rows remain unchanged.\n");exit;}
$client=new \Aws\SecretsManager\SecretsManagerClient(['version'=>'2017-10-17','region'=>$region,'retries'=>2,'http'=>['connect_timeout'=>5,'timeout'=>20]]);
$secret=json_decode((string)$client->getSecretValue(['SecretId'=>$arn])['SecretString'],true,16,JSON_THROW_ON_ERROR);
if(!is_string($secret['username']??null)||!is_string($secret['password']??null))throw new RuntimeException('Migration identity is incomplete.');
$db=(array)app_config('db');$host=(string)($db['host']??'');$name=(string)($db['name']??'');$ca=(string)($db['ssl_ca']??'');$port=(int)($db['port']??3306);
if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/D',$name)||$host===''||!is_readable($ca)||$port<1||$port>65535)throw new RuntimeException('Production database TLS configuration is incomplete.');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$secret['username'],$secret['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_SSL_CA=>$ca,PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>true]);unset($secret);
founder_story_apply_migration($pdo,$file);
fwrite(STDOUT,"Founder story additive migration applied and verified.\n");
