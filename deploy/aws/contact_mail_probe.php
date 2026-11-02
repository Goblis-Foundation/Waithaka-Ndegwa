<?php
declare(strict_types=1);
/** Bounded production relay check: no DATA command, message, mailbox read, or queue drain. */
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__,2).'/vendor/autoload.php';
$result='rejected';$smtp=new \PHPMailer\PHPMailer\SMTP();
try{
 $env=json_decode((string)shell_exec('/opt/elasticbeanstalk/bin/get-config environment'),true,32,JSON_THROW_ON_ERROR);
 foreach(['MAIL_TRANSPORT'=>'smtp','MAIL_FROM'=>'isaac@waithakandegwa.com','SMTP_HOST'=>'smtp-relay.gmail.com','SMTP_PORT'=>'587','SMTP_ENCRYPTION'=>'tls']as$key=>$value)if(($env[$key]??null)!==$value)throw new RuntimeException('Configuration mismatch.');
 if(($env['SMTP_USERNAME']??'')!=='')throw new RuntimeException('IP relay must not use stale SMTP credentials.');
 $smtp->Timeout=15;$smtp->Timelimit=15;$smtp->do_debug=0;
 if(!$smtp->connect('smtp-relay.gmail.com',587,15)||!$smtp->hello('waithakandegwa.com')||!$smtp->startTLS()||!$smtp->hello('waithakandegwa.com')||!$smtp->mail('isaac@waithakandegwa.com')||!$smtp->recipient('isaac@waithakandegwa.com'))throw new RuntimeException('Relay not accepted.');
 $smtp->reset();$result='accepted';
}catch(Throwable){/* Never print SMTP/provider response text or environment values. */}
finally{if($smtp->connected())$smtp->quit();$smtp->close();}
echo 'Workspace contact relay probe: '.$result.'.'.PHP_EOL;
// A refused relay must remain visible without taking the public website offline.
exit($result==='accepted'?0:1);
