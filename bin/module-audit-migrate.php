<?php
declare(strict_types=1);
/** Narrow additive production migration. Never resets or rewrites existing application tables. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/migrations.php';
require dirname(__DIR__).'/app/module_audit_migration.php';
if(array_diff(array_slice($argv,1),['--apply','--plan']))throw new RuntimeException('Use --plan or --apply.');
$apply=in_array('--apply',$argv,true);
if(module_audit_verified(db())){fwrite(STDOUT,"Module audit migrations already verified with runtime read access.\n");exit;}
if(!$apply){fwrite(STDOUT,"PLAN: Add missing subscriber name columns without changing existing names, preserve the Culture category and record verified migrations 150/180.\n");exit;}
$region=(string)app_config('aws_region','');$arn=(string)getenv('DB_ADMIN_SECRET_ARN');
if($region!=='af-south-1'||!preg_match('~^arn:aws:secretsmanager:af-south-1:466401734428:secret:[A-Za-z0-9/!_+=.@-]+$~D',$arn))throw new RuntimeException('The exact production database migration identity is required.');
$client=new \Aws\SecretsManager\SecretsManagerClient(['version'=>'2017-10-17','region'=>$region,'retries'=>2,'http'=>['connect_timeout'=>5,'timeout'=>20]]);
try {
 $response=$client->getSecretValue(['SecretId'=>$arn]);
} catch (\Aws\Exception\AwsException $error) {
 $code=(string)($error->getAwsErrorCode()??'Unknown');
 if(!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$code))$code='Unknown';
 fwrite(STDERR,'Module audit migration AWS read failed: '.$code.'; HTTP '.(int)$error->getStatusCode().".\n");
 exit(1);
}
$secret=json_decode((string)$response['SecretString'],true,16,JSON_THROW_ON_ERROR);unset($response);
if(!is_string($secret['username']??null)||!is_string($secret['password']??null))throw new RuntimeException('Migration identity is incomplete.');
$db=(array)app_config('db');$host=(string)($db['host']??'');$name=(string)($db['name']??'');$ca=(string)($db['ssl_ca']??'');$port=(int)($db['port']??3306);
if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/D',$name)||$host===''||!is_readable($ca)||$port<1||$port>65535)throw new RuntimeException('Production database TLS configuration is incomplete.');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$secret['username'],$secret['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::MYSQL_ATTR_SSL_CA=>$ca,PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=>true]);unset($secret);
module_audit_apply_migrations($pdo);
fwrite(STDOUT,"Module audit additive migrations applied and verified.\n");
