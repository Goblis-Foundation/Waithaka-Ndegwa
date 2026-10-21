<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/environment.php';
$CONFIG=['app_env'=>'testing','mail'=>['sns_topic_arn'=>'arn:aws:sns:us-east-1:111122223333:website-fixture','from'=>'writer@example.invalid','from_name'=>'Waithaka Ndegwa','ses_configuration_set'=>'fixture']];
require dirname(__DIR__).'/app/functions.php';
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/app/mail_feedback.php';
require dirname(__DIR__).'/app/operations.php';
require __DIR__.'/mail_feedback_fixture.php';
$checks=0;
function feedback_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
function feedback_reject(callable $operation,string $message):void{try{$operation();}catch(InvalidArgumentException|RuntimeException $e){$GLOBALS['checks']++;return;}throw new RuntimeException($message);}
[$key,$pem]=feedback_fixture_signer();$loads=0;
$loader=static function(string $url)use($pem,&$loads):string{$loads++;return $pem;};
$message=feedback_fixture_message(['eventType'=>'Delivery']);
$body=feedback_fixture_signed($message,$key);
$verified=mail_feedback_verify($body,$loader);
feedback_check($verified['MessageId']===$message['MessageId']&&$loads===1,'A signed SHA-256 envelope must validate with the fixed-topic test certificate.');
$old=feedback_fixture_signed(array_replace($message,['SignatureVersion'=>'1']),$key);
feedback_check(mail_feedback_verify($old,$loader)['SignatureVersion']==='1','Existing SNS SHA-1 topics must remain compatible.');
$tampered=json_decode($body,true);$tampered['Message']='{"eventType":"Complaint"}';
feedback_reject(fn()=>mail_feedback_verify(json_encode($tampered),$loader),'Changing the signed event must invalidate its signature.');
$loads=0;$wrongTopic=array_replace($message,['TopicArn'=>'arn:aws:sns:us-east-1:999900001111:other-topic']);
feedback_reject(fn()=>mail_feedback_verify(feedback_fixture_signed($wrongTopic,$key),$loader),'An unexpected topic must be refused.');
feedback_check($loads===0,'Topic rejection must happen before certificate retrieval.');
foreach([
    'http://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem',
    'https://sns.us-east-1.amazonaws.com.attacker.invalid/SimpleNotificationService-test.pem',
    'https://sns.eu-west-1.amazonaws.com/SimpleNotificationService-test.pem',
    'https://sns.us-east-1.amazonaws.com@127.0.0.1/SimpleNotificationService-test.pem',
    'https://sns.us-east-1.amazonaws.com:444/SimpleNotificationService-test.pem',
    'https://sns.us-east-1.amazonaws.com/../secret',
    'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-test.pem?secret=1',
    'https://s3.us-east-1.amazonaws.com/SimpleNotificationService-test.pem',
]as$url)feedback_reject(fn()=>mail_feedback_verify(feedback_fixture_signed(array_replace($message,['SigningCertURL'=>$url]),$key),$loader),'An untrusted certificate URL must never be fetched.');
feedback_check($loads===0,'All rejected certificate locations must stay offline.');
foreach(['Timestamp'=>gmdate('Y-m-d\TH:i:s\Z',time()-36*86400),'MessageId'=>'bad','Type'=>'InjectedType','SignatureVersion'=>'9']as$field=>$value){
    $invalid=json_decode($body,true);$invalid[$field]=$value;
    feedback_reject(fn()=>mail_feedback_verify(json_encode($invalid),$loader),'Invalid envelope fields must be refused.');
}
$future=array_replace($message,['Timestamp'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)]);
feedback_reject(fn()=>mail_feedback_verify(feedback_fixture_signed($future,$key),$loader),'Future-dated envelopes must be refused.');
feedback_reject(fn()=>mail_feedback_verify(str_repeat('x',262145),$loader),'Oversized webhook payloads must be bounded.');
feedback_reject(fn()=>mail_feedback_verify('{invalid',$loader),'Invalid JSON must be refused.');
$CONFIG['app_env']='production';
feedback_reject(fn()=>mail_feedback_verify($body,$loader),'Injected certificate loaders must not exist in production.');
$CONFIG['app_env']='testing';
$headers=['List-Unsubscribe'=>'<https://www.waithakandegwa.com/newsletter/one-click?token='.str_repeat('a',64).'>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click'];
feedback_check(email_safe_headers($headers)===$headers,'The two RFC 8058 headers must be preserved verbatim.');
foreach([['Bcc'=>'hidden@example.invalid'],['List-Unsubscribe'=>"<https://example.com/>\r\nBcc: bad@example.invalid"],['List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click'],['List-Unsubscribe'=>'<https://user:password@example.com/>']]as$bad)feedback_reject(fn()=>email_safe_headers($bad),'Unsafe or incomplete custom mail headers must be rejected.');
$args=email_ses_arguments(['to'=>'subscriber@example.invalid','subject'=>'Synthetic edition','html'=>'<p>Fixture only.</p>','headers'=>$headers],str_repeat('f',64));
feedback_check(count($args['Content']['Simple']['Headers'])===2&&$args['Content']['Simple']['Headers'][0]['Name']==='List-Unsubscribe','SES v2 must receive the approved list headers.');
feedback_check($args['EmailTags'][0]['Value']===str_repeat('f',64),'SES delivery feedback must bind to the durable outbox key.');
feedback_check(str_contains($args['FromEmailAddress'],'Waithaka Ndegwa')&&str_contains($args['FromEmailAddress'],'writer@example.invalid'),'SES must use the approved sender name and address.');
$client=new \Aws\SesV2\SesV2Client(['region'=>'us-east-1','version'=>'2019-09-27','credentials'=>['key'=>'synthetic','secret'=>'synthetic']]);
$validator=new \Aws\Api\Validator();
$validator->validate('SendEmail',$client->getApi()->getOperation('SendEmail')->getInput(),$args);
feedback_check(true,'The payload must satisfy the installed official SES v2 operation model without making a request.');
echo "Mail feedback unit checks passed: $checks\n";
