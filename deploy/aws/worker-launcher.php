<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
ini_set('display_errors','0');
ini_set('zend.exception_ignore_args','1');
try {
    require_once dirname(__DIR__,2).'/app/environment.php';
    $json=shell_exec('/opt/elasticbeanstalk/bin/get-config environment');
    $env=json_decode((string)$json,true,512,JSON_THROW_ON_ERROR);
    if(!is_array($env))throw new RuntimeException('Environment is unavailable.');
    foreach($env as$key=>$value)if(preg_match('/\A[A-Z][A-Z0-9_]*\z/',(string)$key)&&is_scalar($value))putenv($key.'='.$value);
    unset($env,$json);
    if(!wk_env_bool('APP_READY',false)||!wk_env_bool('WORKER_ENABLED',false))exit(0);
    foreach(['DB_HOST','DB_DATABASE']as$key) {
        $value=getenv($key);
        if(!is_string($value)||$value===''||preg_match('/[;\x00-\x20\x7f]/',$value))throw new RuntimeException('Worker database configuration is incomplete.');
    }
    // get-config excludes environmentsecrets. Fetch the same exact JSON secret using the instance role.
    require dirname(__DIR__,2).'/vendor/autoload.php';
    $secretArn=(string)getenv('WORKER_APP_SECRET_ARN');
    if(trim($secretArn)==='')throw new RuntimeException('Worker secret ARN is not configured.');
    $client=new \Aws\SecretsManager\SecretsManagerClient(['version'=>'2017-10-17','region'=>getenv('AWS_REGION'),'http'=>['connect_timeout'=>5,'timeout'=>15]]);
    $secret=$client->getSecretValue(['SecretId'=>$secretArn]);
    $values=json_decode((string)($secret['SecretString']??''),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($values))throw new RuntimeException('Worker secret is invalid.');
    $credentials=[];
    foreach(['APP_KEY','DB_USERNAME','DB_PASSWORD']as$key) {
        if(!isset($values[$key])||!is_string($values[$key])||$values[$key]===''||str_contains($values[$key],"\0"))throw new RuntimeException('Worker secret is incomplete.');
        $credentials[$key]=$values[$key];
    }
    wk_validate_app_key($credentials['APP_KEY']);
    if(trim($credentials['DB_USERNAME'])==='')throw new RuntimeException('Worker database username is missing.');
    if(array_key_exists('SMTP_PASSWORD',$values)) {
        if(!is_string($values['SMTP_PASSWORD'])||str_contains($values['SMTP_PASSWORD'],"\0"))throw new RuntimeException('Worker email secret is invalid.');
        $credentials['SMTP_PASSWORD']=$values['SMTP_PASSWORD'];
    }
    // Export only after every required field has passed validation.
    foreach($credentials as$key=>$value)putenv($key.'='.$value);
    unset($values,$secret,$credentials,$value,$key,$client);
    require dirname(__DIR__,2).'/bin/worker.php';
} catch(Throwable $e) {
    // AWS/configuration exceptions can contain credentials or provider responses.
    error_log('Worker launcher failed: '.get_class($e).' at '.basename($e->getFile()).':'.$e->getLine());
    fwrite(STDERR,"Worker stopped. Verify runtime configuration, required secret fields, and secret access permissions.\n");
    exit(1);
}
