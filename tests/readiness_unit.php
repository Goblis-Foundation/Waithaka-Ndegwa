<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/environment.php';
require dirname(__DIR__).'/app/readiness.php';
$checks=0;
function readiness_test(bool $ok,string $message): void { $GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message); }
$secret='PRIVATE_READINESS_SECRET_VALUE_2026';
$counts=readiness_counts(['publishing'=>['processed'=>3,'published'=>1,'busy'=>false,'token'=>$secret,'failed'=>-1,'checked'=>'2'],
    'email'=>['sent'=>2,'enabled'=>true,'message'=>$secret,'pending'=>PHP_INT_MAX],'password'=>$secret,'provider_response'=>['sent'=>3]]);
readiness_test($counts['publishing']===['processed'=>3,'published'=>1,'busy'=>false],'Worker metrics must retain only allowlisted typed counts.');
readiness_test($counts['email']['pending']===1000000000,'Worker counts must be bounded.');
readiness_test(!str_contains(json_encode($counts),$secret),'Worker metadata must discard secrets and provider responses.');
readiness_test(readiness_stage($secret)==='working'&&readiness_error_code($secret)==='worker_failed','Unknown stage/error text must not enter monitoring records.');
readiness_test(readiness_error_code(null)===null,'Successful records may have no error code.');
readiness_test(readiness_stage('discovery')==='discovery','The discovery worker stage must remain identifiable in its heartbeat.');
$discoveryCounts=readiness_counts(['discovery'=>['enabled'=>true,'added'=>2,'attempted'=>2,'accepted'=>1,'verification_pending'=>1,'key'=>$secret,'url'=>$secret]]);
readiness_test($discoveryCounts===['discovery'=>['enabled'=>true,'added'=>2,'attempted'=>2,'accepted'=>1,'verification_pending'=>1]],'Discovery receipts retain safe counts without keys or notification URLs.');
foreach(['10.0.0.0/16','192.0.2.1','2001:db8::/64']as$range)readiness_test(readiness_proxy_valid($range),'Valid explicit proxy boundary should pass.');
foreach(['0.0.0.0/0','::/0','10.0.0.0/000','10.0.0.0/','10.0.0.0/not-a-prefix','10.0.0.0/33',[],null]as$range)readiness_test(!readiness_proxy_valid($range),'Missing, malformed or trust-all proxy ranges must need review.');
readiness_test(readiness_https_url('https://www.waithakandegwa.com'),'Public HTTPS canonical configuration should pass.');
foreach(['http://www.waithakandegwa.com','https://secret@example.com','https://user:password@example.com','https://example.com/?token=private','https://example.com/#private']as$value)readiness_test(!readiness_https_url($value),'Private or non-HTTPS URL must not be marked configured.');
$config=['app_key'=>$secret,'base_url'=>'https://www.waithakandegwa.com','force_canonical'=>true,'trusted_proxies'=>['10.2.0.0/16'],
    'session_driver'=>'database','db'=>['host'=>$secret,'user'=>$secret,'pass'=>$secret,'ssl_ca'=>$secret],
    'aws_region'=>'eu-west-1','media'=>['driver'=>'s3','bucket'=>$secret.'-public','private_bucket'=>$secret.'-private','public_url'=>'https://www.waithakandegwa.com'],
    'mail'=>['transport'=>'ses','from'=>$secret,'smtp_password'=>$secret,'sns_topic_arn'=>'arn:aws:sns:eu-west-1:111122223333:fixture-feedback']];
$caps=['php_version_id'=>80300,'missing_extensions'=>[],'session_table'=>true,'db_ca_readable'=>true,'s3_sdk'=>true,'mail_ready'=>true];
$output=readiness_config_checks($config,$caps);
readiness_test(count($output)===9,'Expected local readiness checks should be present.');
readiness_test(!str_contains(json_encode($output),$secret),'Configuration diagnostics must not expose key, host, credentials, buckets or mail identity.');
readiness_test(count(array_filter($output,fn($check)=>$check['state']==='ok'))===9,'Locally complete configuration should pass without claiming provider verification.');
$config['media']['private_bucket']=$config['media']['bucket'];$config['mail']['transport']='disabled';
$byId=array_column(readiness_config_checks($config,$caps),null,'id');
readiness_test($byId['media']['state']==='attention','Public and private media must not share a bucket.');
readiness_test($byId['mail']['state']==='disabled','Paused mail must not be described as broken or live.');
$config['app_key']='';$byId=array_column(readiness_config_checks($config,$caps),null,'id');
readiness_test($byId['key']['state']==='blocked','Missing application key should block readiness.');
$now=(new DateTimeImmutable('2026-10-21 12:00:00',new DateTimeZone('UTC')))->getTimestamp();
readiness_test(readiness_age('2026-10-21 14:59:00',$now,'Africa/Nairobi')===60,'Legacy Nairobi times need an explicit source timezone.');
readiness_test(readiness_age('2026-10-21 11:59:00',$now)===60,'Worker timestamps are UTC.');
readiness_test(readiness_age('2026-13-21 11:59:00',$now)===null,'Malformed timestamps must not normalize silently.');
readiness_test(readiness_worker_health(null,true,$now)['state']==='attention','Enabled worker without any report must need review.');
readiness_test(readiness_worker_health(['state'=>'completed','heartbeat_at'=>'2026-10-21 11:59:00'],true,$now)['state']==='ok','A recently completed invocation should be visible.');
readiness_test(readiness_worker_health(['state'=>'completed','heartbeat_at'=>'2026-10-21 11:54:00'],true,$now)['state']==='attention','A missing timer report should need attention.');
readiness_test(readiness_worker_health(['state'=>'running','heartbeat_at'=>'2026-10-21 11:40:00'],true,$now)['state']==='blocked','A stale running invocation must not look healthy.');
readiness_test(readiness_worker_health(['state'=>'failed','heartbeat_at'=>'2026-10-21 11:59:00'],true,$now)['state']==='blocked','Recent failure is not readiness.');
readiness_test(readiness_worker_health(['state'=>'disabled','heartbeat_at'=>'2026-10-21 11:59:00'],false,$now)['state']==='disabled','Intentionally paused work remains distinct from timer failure.');
readiness_test(readiness_worker_health(['state'=>'disabled','heartbeat_at'=>'2026-10-21 11:59:00'],true,$now)['state']==='attention','Enabled web config with a paused worker must show disagreement.');
echo "Readiness unit checks passed: $checks\n";
