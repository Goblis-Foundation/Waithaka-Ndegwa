<?php
declare(strict_types=1);
function wk_env_bool(string $key,bool $default): bool { $v=getenv($key);return $v===false?$default:in_array(strtolower($v),['1','true','yes','on'],true); }
function wk_validate_app_key(mixed $key): void {
    // Validate without trimming or transforming the recovered encryption key.
    if (!is_string($key) || strlen($key)<32 || trim($key)==='' || $key==='GENERATED_BY_INSTALLER') {
        throw new RuntimeException('A valid application key is required. Preserve the original key when restoring data.');
    }
}
function wk_environment_config(array $config): array {
    $defaults=['app_env'=>'production','base_url'=>'https://www.waithakandegwa.com','timezone'=>'Africa/Nairobi','force_canonical'=>true,'worker_enabled'=>false,
        'allowed_hosts'=>['waithakandegwa.com','www.waithakandegwa.com','waithakandegwa.co.ke','www.waithakandegwa.co.ke'],
        'trusted_proxies'=>[],'session_driver'=>'files','db'=>['port'=>3306,'charset'=>'utf8mb4'],'mail'=>['transport'=>'disabled'],'media'=>['driver'=>'local']];
    $config=array_replace_recursive($defaults,$config);
    $map=['APP_ENV'=>'app_env','APP_URL'=>'base_url','APP_KEY'=>'app_key','APP_TIMEZONE'=>'timezone','SESSION_DRIVER'=>'session_driver',
        'DB_HOST'=>'db.host','DB_PORT'=>'db.port','DB_DATABASE'=>'db.name','DB_USERNAME'=>'db.user','DB_PASSWORD'=>'db.pass','DB_SSL_CA'=>'db.ssl_ca',
        'MAIL_TRANSPORT'=>'mail.transport','MAIL_FROM'=>'mail.from','MAIL_FROM_NAME'=>'mail.from_name','MAIL_REPLY_TO'=>'mail.reply_to',
        'SMTP_HOST'=>'mail.smtp_host','SMTP_PORT'=>'mail.smtp_port','SMTP_USERNAME'=>'mail.smtp_username','SMTP_PASSWORD'=>'mail.smtp_password','SMTP_ENCRYPTION'=>'mail.smtp_encryption',
        'SES_REGION'=>'mail.ses_region','SES_CONFIGURATION_SET'=>'mail.ses_configuration_set','SES_SNS_TOPIC_ARN'=>'mail.sns_topic_arn','MAIL_POSTAL_ADDRESS'=>'mail.postal_address',
        'MEDIA_DRIVER'=>'media.driver','MEDIA_BUCKET'=>'media.bucket','MEDIA_PRIVATE_BUCKET'=>'media.private_bucket','MEDIA_PUBLIC_URL'=>'media.public_url','AWS_REGION'=>'aws_region'];
    foreach($map as $env=>$key){$v=getenv($env);if($v===false)continue;$target=&$config;foreach(explode('.',$key) as $part)$target=&$target[$part];$target=$v;unset($target);}
    if(getenv('APP_FORCE_CANONICAL')!==false)$config['force_canonical']=wk_env_bool('APP_FORCE_CANONICAL',true);
    if(getenv('WORKER_ENABLED')!==false)$config['worker_enabled']=wk_env_bool('WORKER_ENABLED',false);
    foreach(['APP_ALLOWED_HOSTS'=>'allowed_hosts','TRUSTED_PROXY_CIDRS'=>'trusted_proxies'] as $env=>$key)if(getenv($env)!==false)$config[$key]=array_values(array_filter(array_map('trim',explode(',',getenv($env)))));
    return $config;
}
function wk_ip_in_cidr(string $ip,string $cidr): bool {
    [$network,$bits]=array_pad(explode('/',$cidr,2),2,null);$packed=@inet_pton($ip);$net=@inet_pton($network);
    if($packed===false||$net===false||strlen($packed)!==strlen($net))return false;
    // An empty or nonnumeric prefix must never become /0 through an integer cast.
    if($bits!==null&&!preg_match('/\A[0-9]{1,3}\z/',$bits))return false;
    $bits=$bits===null?strlen($packed)*8:(int)$bits;if($bits<0||$bits>strlen($packed)*8)return false;
    $bytes=intdiv($bits,8);$rest=$bits%8;
    return substr($packed,0,$bytes)===substr($net,0,$bytes)&&(!$rest||((ord($packed[$bytes])&(255<<(8-$rest)))===(ord($net[$bytes])&(255<<(8-$rest)))));
}
function wk_trusted_proxy(string $ip): bool { global $CONFIG;foreach(($CONFIG['trusted_proxies']??[])as $range)if(wk_ip_in_cidr($ip,$range))return true;return false; }
function wk_request_is_https(): bool {
    if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')return true;
    return wk_trusted_proxy($_SERVER['REMOTE_ADDR']??'')&&strtolower(trim(explode(',',$_SERVER['HTTP_X_FORWARDED_PROTO']??'')[0]))==='https';
}
function wk_client_ip(): string {
    $ip=$_SERVER['REMOTE_ADDR']??'127.0.0.1';if(!wk_trusted_proxy($ip))return $ip;
    foreach(array_reverse(array_map('trim',explode(',',$_SERVER['HTTP_X_FORWARDED_FOR']??'')))as $candidate){if(!filter_var($candidate,FILTER_VALIDATE_IP)||!wk_trusted_proxy($ip))break;$ip=$candidate;}return $ip;
}
