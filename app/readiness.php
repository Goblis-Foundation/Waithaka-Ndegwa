<?php
declare(strict_types=1);

/** Operational diagnostics deliberately never fetch provider credentials or make network calls. */
function readiness_counts(array $counts): array {
    $result=[];
    $metrics=['processed','published','assisted','attention','checked','confirmed','queued','sent','ambiguous','suppressed','failed','bookkeeping_pending','pending','completed','added','updated','deleted','attempted','accepted','verification_pending','retry','rejected','cancelled'];
    foreach(['publishing','payments','email','newsletter','youtube','media','feedback','discovery']as$section) {
        if(!isset($counts[$section])||!is_array($counts[$section]))continue;
        foreach($counts[$section]as$key=>$value) {
            if(in_array($key,['enabled','busy'],true)&&is_bool($value))$result[$section][$key]=$value;
            elseif(in_array($key,$metrics,true)&&is_int($value)&&$value>=0)$result[$section][$key]=min($value,1000000000);
        }
    }
    return $result;
}
function readiness_stage(string $stage): string {
    return in_array($stage,['starting','publishing','payments','email','newsletter','youtube','feedback','media','discovery','complete','paused'],true)?$stage:'working';
}
function readiness_error_code(?string $code): ?string {
    if($code===null)return null;
    return in_array($code,['worker_failed','worker_interrupted','stage_failed','database_unavailable','configuration_invalid'],true)?$code:'worker_failed';
}
/** Monitoring is best-effort and cannot change delivery idempotency or cause a task replay. */
function readiness_worker_begin(bool $enabled): ?int {
    try {
        $q=db()->prepare('INSERT INTO worker_runs(state,stage,started_at,heartbeat_at,finished_at) VALUES(?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),IF(?=1,NULL,UTC_TIMESTAMP()))');
        $q->execute([$enabled?'running':'disabled',$enabled?'starting':'paused',$enabled?1:0]);
        return (int)db()->lastInsertId();
    } catch(Throwable $e) { error_log('Worker monitoring could not record its start.'); return null; }
}
function readiness_worker_touch(?int $id,string $stage,array $counts=[]): void {
    if($id===null||$id<1)return;
    try {
        db()->prepare("UPDATE worker_runs SET stage=?,heartbeat_at=UTC_TIMESTAMP(),counts_json=? WHERE id=? AND state='running'")
            ->execute([readiness_stage($stage),json_encode(readiness_counts($counts),JSON_THROW_ON_ERROR),$id]);
    } catch(Throwable $e) { error_log('Worker monitoring could not record progress.'); }
}
function readiness_worker_finish(?int $id,bool $success,array $counts=[],?string $errorCode=null): void {
    if($id===null||$id<1)return;
    try {
        db()->prepare("UPDATE worker_runs SET state=?,stage=?,heartbeat_at=UTC_TIMESTAMP(),finished_at=UTC_TIMESTAMP(),counts_json=?,error_code=? WHERE id=? AND state='running'")
            ->execute([$success?'completed':'failed',$success?'complete':'working',json_encode(readiness_counts($counts),JSON_THROW_ON_ERROR),$success?null:readiness_error_code($errorCode??'worker_failed'),$id]);
    } catch(Throwable $e) { error_log('Worker monitoring could not record completion.'); }
}
function readiness_age(?string $stamp,int $now,string $timezone='UTC'): ?int {
    if($stamp===null||$stamp==='')return null;
    try {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$stamp,new DateTimeZone($timezone));
        if(!$date||$date->format('Y-m-d H:i:s')!==$stamp)return null;
        return max(0,$now-$date->getTimestamp());
    } catch(Throwable $e) { return null; }
}
function readiness_time(?string $stamp,string $sourceZone='UTC'): string {
    if($stamp===null||$stamp==='')return 'Not recorded';
    try {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$stamp,new DateTimeZone($sourceZone));
        if(!$date||$date->format('Y-m-d H:i:s')!==$stamp)return 'Not recorded';
        return $date->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('j M Y, H:i').' EAT';
    } catch(Throwable $e) { return 'Not recorded'; }
}
function readiness_check(string $id,string $title,string $state,string $summary,string $detail): array {
    return compact('id','title','state','summary','detail');
}
function readiness_proxy_valid(mixed $range): bool {
    if(!is_string($range)||$range==='')return false;
    $parts=explode('/',$range);
    if(count($parts)>2||!filter_var($parts[0],FILTER_VALIDATE_IP))return false;
    if(count($parts)===1)return true;
    $max=str_contains($parts[0],':')?128:32;
    return preg_match('/\A[0-9]{1,3}\z/',$parts[1])===1&&(int)$parts[1]>0&&(int)$parts[1]<=$max;
}
function readiness_https_url(mixed $value): bool {
    if(!is_string($value)||!filter_var($value,FILTER_VALIDATE_URL))return false;
    $url=parse_url($value);
    return is_array($url)&&($url['scheme']??'')==='https'&&!empty($url['host'])&&!isset($url['user'])&&!isset($url['pass'])&&!isset($url['query'])&&!isset($url['fragment']);
}
/** Pure local configuration checks. Returned data never contains configuration values. */
function readiness_config_checks(array $config,array $capabilities): array {
    $checks=[];$keyReady=false;
    try { wk_validate_app_key($config['app_key']??null);$keyReady=true; } catch(Throwable $e) {}
    $checks[]=readiness_check('key','Recovered application key',$keyReady?'ok':'blocked',$keyReady?'Key is configured':'Key is missing or invalid','This checks key format only. Restoration must separately confirm that it is the original key used by the recovered database.');
    $runtime=($capabilities['php_version_id']??0)>=80300&&empty($capabilities['missing_extensions']);
    $checks[]=readiness_check('runtime','PHP runtime',$runtime?'ok':'blocked',$runtime?'Required runtime is available':'A runtime requirement is missing','PHP 8.3 or newer and the required database, HTTP, DOM, image and encryption extensions are checked locally.');
    $canonical=readiness_https_url($config['base_url']??null)&&!empty($config['force_canonical']);
    $checks[]=readiness_check('canonical','Canonical HTTPS address',$canonical?'ok':'attention',$canonical?'HTTPS canonical routing is configured':'Review canonical HTTPS configuration','Verify the live certificates and all domain redirects through the deployed edge before cutover.');
    $proxies=$config['trusted_proxies']??[];$proxiesValid=is_array($proxies)&&$proxies!==[];
    if($proxiesValid)foreach($proxies as$range)if(!readiness_proxy_valid($range))$proxiesValid=false;
    $checks[]=readiness_check('proxy','Trusted proxy boundary',$proxiesValid?'ok':'attention',$proxiesValid?'Explicit proxy ranges are configured':'Proxy ranges need deployment review','Only the actual proxy network belongs here. The page does not test security groups, forwarded headers or origin access.');
    $sessions=($config['session_driver']??'files')==='database';
    $sessionReady=$sessions&&!empty($capabilities['session_table']);
    $checks[]=readiness_check('sessions','Writer sessions',$sessionReady?'ok':'attention',$sessionReady?'Database session storage is available':($sessions?'Session table is unavailable':'Sessions use one application filesystem'),'Database sessions support multiple instances. Verify a Writer login across the deployed instances during acceptance.');
    $tls=($config['db']['ssl_ca']??'')!==''&&!empty($capabilities['db_ca_readable']);
    $checks[]=readiness_check('database_tls','Database TLS configuration',$tls?'ok':'attention',$tls?'A readable CA bundle is configured':'Review database TLS configuration','This checks configuration, not the negotiated production connection or network boundary.');
    $media=$config['media']??[];$s3=($media['driver']??'local')==='s3';
    $mediaReady=$s3&&!empty($capabilities['s3_sdk'])&&!empty($media['bucket'])&&!empty($media['private_bucket'])
        &&$media['bucket']!==$media['private_bucket']&&!empty($config['aws_region'])&&readiness_https_url($media['public_url']??null);
    $checks[]=readiness_check('media','Public and private media',$mediaReady?'ok':'attention',$mediaReady?'Separate S3 stores are configured':($s3?'Complete the S3 configuration':'Media uses the application filesystem'),'No S3 request is made here. Verify restored object hashes, private access and public CDN access before enabling traffic.');
    $transport=$config['mail']['transport']??'disabled';$mailReady=!empty($capabilities['mail_ready']);
    $checks[]=readiness_check('mail','Email delivery',$transport==='disabled'?'disabled':($mailReady?'ok':'blocked'),$transport==='disabled'?'Sending is paused':($mailReady?'A mail transport is configured':'Mail transport configuration is incomplete'),'Configuration does not prove provider acceptance or inbox delivery. Verify sender identity, feedback and suppression handling before sending.');
    $topic=$config['mail']['sns_topic_arn']??'';
    $feedback=is_string($topic)&&preg_match('/\Aarn:(aws|aws-cn|aws-us-gov):sns:[a-z]{2}(?:-[a-z]+)+-[0-9]:[0-9]{12}:[A-Za-z0-9_-]{1,256}\z/',$topic)===1;
    $checks[]=readiness_check('mail_feedback','SES delivery feedback',$feedback?'ok':($transport==='ses'?'attention':'disabled'),$feedback?'An exact SNS topic is configured':($transport==='ses'?'Configure and verify SES feedback':'SES feedback is not configured'),'Open Mail feedback to confirm the signed subscription and inspect delivery, bounce and complaint handling. A configured topic does not prove that SES is publishing events to it.');
    return $checks;
}
function readiness_worker_health(?array $last,bool $enabled,int $now): array {
    if(!$last)return ['state'=>$enabled?'attention':'disabled','summary'=>$enabled?'No worker invocation has been recorded':'Work is paused; no timer invocation recorded','age'=>null];
    $age=readiness_age($last['heartbeat_at']??null,$now);
    if($age===null)return ['state'=>'attention','summary'=>'Worker timestamp needs review','age'=>null];
    if(!$enabled)return ['state'=>'disabled','summary'=>$age<=300?'Timer reported; delivery work is paused':'Delivery work is paused; timer has not reported recently','age'=>$age];
    if(($last['state']??'')==='failed')return ['state'=>'blocked','summary'=>'The latest worker run failed','age'=>$age];
    if(($last['state']??'')==='running')return ['state'=>$age>900?'blocked':'ok','summary'=>$age>900?'A worker run has stopped reporting':'A worker run is in progress','age'=>$age];
    if(($last['state']??'')==='disabled')return ['state'=>'attention','summary'=>'Configuration enables work, but the latest invocation was paused','age'=>$age];
    return ['state'=>$age>300?'attention':'ok','summary'=>$age>300?'The worker has not reported in the last five minutes':'The worker completed recently','age'=>$age];
}
function readiness_snapshot(): array {
    $config=(array)app_config();$now=time();$tables=[];$dbReady=false;
    $result=['generated_at'=>gmdate('Y-m-d H:i:s',$now),'checks'=>[],'migrations'=>['pending'=>[],'changed'=>[],'available'=>false],
        'publishing'=>['available'=>false,'counts'=>[],'overdue'=>0,'stale'=>0,'attention'=>[]],
        'email'=>['available'=>false,'counts'=>[],'overdue'=>0,'stale'=>0,'attention'=>[]],
        'connections'=>[],'worker'=>['available'=>false,'latest'=>null,'recent'=>[],'enabled'=>(bool)($config['worker_enabled']??false)]];
    try {
        db()->query('SELECT 1');
        $tables=db()->query('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        $dbReady=true;
    } catch(Throwable $e) {}
    $missing=[];foreach(['pdo_mysql','curl','dom','mbstring','fileinfo','openssl','gd']as$extension)if(!extension_loaded($extension))$missing[]=$extension;
    $result['checks']=readiness_config_checks($config,['php_version_id'=>PHP_VERSION_ID,'missing_extensions'=>$missing,
        'session_table'=>in_array('app_sessions',$tables,true),'db_ca_readable'=>!empty($config['db']['ssl_ca'])&&is_readable($config['db']['ssl_ca']),
        's3_sdk'=>class_exists(\Aws\S3\S3Client::class),'mail_ready'=>function_exists('email_transport_ready')&&email_transport_ready()]);
    $result['checks'][]=readiness_check('database','Application database',$dbReady?'ok':'blocked',$dbReady?'Database query succeeded':'Database is unavailable','The diagnostic reads local application state and does not change records.');
    if($dbReady) {
        try {
            $applied=[];
            if(in_array('schema_migrations',$tables,true))foreach(db()->query('SELECT name,sha256 FROM schema_migrations')->fetchAll()as$row)$applied[$row['name']]=$row['sha256'];
            foreach(glob(__DIR__.'/migrations/*.sql')?:[]as$file) {
                $name=basename($file);
                if(!isset($applied[$name]))$result['migrations']['pending'][]=$name;
                elseif(!hash_equals((string)$applied[$name],(string)hash_file('sha256',$file)))$result['migrations']['changed'][]=$name;
            }
            $result['migrations']['available']=true;
        } catch(Throwable $e) {}
        try {
            if(in_array('publishing_jobs',$tables,true)) {
                $result['publishing']['available']=true;
                foreach(db()->query('SELECT status,COUNT(*) total FROM publishing_jobs GROUP BY status')->fetchAll()as$row)
                    if(in_array($row['status'],['scheduled','processing','delivered','published','assisted','reminder_queued','reminder_sent','reconnect','failed','ambiguous','cancelled'],true))$result['publishing']['counts'][$row['status']]=(int)$row['total'];
                $result['publishing']['overdue']=(int)db()->query("SELECT COUNT(*) FROM publishing_jobs WHERE status='scheduled' AND scheduled_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE)")->fetchColumn();
                $result['publishing']['stale']=(int)db()->query("SELECT COUNT(*) FROM publishing_jobs WHERE status='processing' AND (locked_at IS NULL OR locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))")->fetchColumn();
                $result['publishing']['attention']=db()->query("SELECT j.id,j.status,j.scheduled_at,r.package_id,c.name channel_name FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_channels c ON c.id=j.channel_id WHERE j.status IN ('reconnect','failed','ambiguous','assisted','reminder_queued','reminder_sent') ORDER BY j.id DESC LIMIT 20")->fetchAll();
            }
        } catch(Throwable $e) { $result['publishing']['available']=false; }
        try {
            if(in_array('email_outbox',$tables,true)) {
                $result['email']['available']=true;
                foreach(db()->query('SELECT status,COUNT(*) total FROM email_outbox GROUP BY status')->fetchAll()as$row)
                    if(in_array($row['status'],['pending','sending','sent','ambiguous','failed','suppressed'],true))$result['email']['counts'][$row['status']]=(int)$row['total'];
                $result['email']['overdue']=(int)db()->query("SELECT COUNT(*) FROM email_outbox WHERE status='pending' AND available_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE)")->fetchColumn();
                $result['email']['stale']=(int)db()->query("SELECT COUNT(*) FROM email_outbox WHERE status='sending' AND (claimed_at IS NULL OR claimed_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE))")->fetchColumn();
                $rows=db()->query("SELECT id,status,created_at,sent_at,error_code FROM email_outbox WHERE status IN ('ambiguous','failed') OR error_code='bookkeeping_pending' ORDER BY id DESC LIMIT 20")->fetchAll();
                foreach($rows as$row) {
                    $row['reason']=match($row['error_code']){'bookkeeping_pending'=>'Delivery acknowledged; local bookkeeping needs reconciliation','worker_interrupted'=>'Worker interrupted; provider outcome must be checked',default=>'Provider outcome requires review'};
                    unset($row['error_code']);$result['email']['attention'][]=$row;
                }
            }
        } catch(Throwable $e) { $result['email']['available']=false; }
        try {
            if(in_array('publishing_connections',$tables,true)) {
                $rows=db()->query("SELECT service,status FROM publishing_connections WHERE status IN ('reconnect','pending_confirmation') ORDER BY service")->fetchAll();
                foreach($rows as$row)if(preg_match('/\A[a-z][a-z0-9_]{0,59}\z/',$row['service']))$result['connections'][]=$row;
            }
        } catch(Throwable $e) {}
        try {
            if(in_array('worker_runs',$tables,true)) {
                $result['worker']['available']=true;
                $rows=db()->query('SELECT id,state,stage,started_at,heartbeat_at,finished_at,counts_json,error_code FROM worker_runs ORDER BY id DESC LIMIT 10')->fetchAll();
                foreach($rows as$row) {
                    $row['state']=in_array($row['state'],['running','completed','failed','disabled'],true)?$row['state']:'unknown';
                    $row['stage']=readiness_stage($row['stage']);
                    $decoded=json_decode((string)$row['counts_json'],true);$row['counts']=readiness_counts(is_array($decoded)?$decoded:[]);
                    $row['error_code']=readiness_error_code($row['error_code']);unset($row['counts_json']);
                    $result['worker']['recent'][]=$row;
                }
                $result['worker']['latest']=$result['worker']['recent'][0]??null;
            }
        } catch(Throwable $e) { $result['worker']['available']=false; }
    }
    $migration=$result['migrations'];
    $state=!$migration['available']||$migration['changed']?'blocked':($migration['pending']?'attention':'ok');
    $summary=!$migration['available']?'Migration ledger is unavailable':($migration['changed']?'An applied migration checksum changed':($migration['pending']?'Database migrations are pending':'Migration checksums match this release'));
    $result['checks'][]=readiness_check('migrations','Database migrations',$state,$summary,'Apply reviewed migrations through the deployment process. This page never applies schema changes.');
    $result['worker']['health']=readiness_worker_health($result['worker']['latest'],$result['worker']['enabled'],$now);
    return $result;
}
