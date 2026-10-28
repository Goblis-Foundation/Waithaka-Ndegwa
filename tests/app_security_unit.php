<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';require dirname(__DIR__).'/app/functions.php';require dirname(__DIR__).'/app/communication.php';require dirname(__DIR__).'/app/communication_notifications.php';require dirname(__DIR__).'/app/app_security.php';
$CONFIG=['base_url'=>'https://www.waithakandegwa.com','app_key'=>'fixture-only-not-a-production-key'];
function sc(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
function sr(callable $f):void{try{$f();}catch(Throwable){return;}throw new RuntimeException('Expected rejection');}
$client=json_encode(['type'=>'webauthn.get','origin'=>base_url(),'challenge'=>'fixture']);app_credential_origin($client,'webauthn.get');sr(fn()=>app_credential_origin($client,'webauthn.create'));sr(fn()=>app_credential_origin(json_encode(['type'=>'webauthn.get','origin'=>'https://evil.invalid']),'webauthn.get'));sr(fn()=>app_credential_origin(json_encode(['type'=>'webauthn.get','origin'=>base_url(),'crossOrigin'=>true]),'webauthn.get'));
$_SESSION=['passkey_challenge'=>['purpose'=>'login','expires'=>time()+30,'value'=>base64_encode('fixture')]];sc(app_challenge('login')==='fixture','Challenge roundtrip');sr(fn()=>app_challenge('login'));$_SESSION=['passkey_challenge'=>['purpose'=>'login','expires'=>time()-1,'value'=>base64_encode('fixture')]];sr(fn()=>app_challenge('login'));
$w=app_webauthn();$args=$w->getGetArgs([],120,true,true,true,true,true,true);sc($args->publicKey->userVerification==='required','Require actual device verification');$challenge=$w->getChallenge()->getBinaryString();$client=json_encode(['type'=>'webauthn.get','origin'=>base_url(),'challenge'=>comm_b64($challenge),'crossOrigin'=>false]);$auth=hash('sha256','www.waithakandegwa.com',true).chr(5).pack('N',1);$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);$pub=openssl_pkey_get_details($key)['key'];openssl_sign($auth.hash('sha256',$client,true),$sig,$key,OPENSSL_ALGO_SHA256);sc($w->processGet($client,$auth,$sig,$pub,$challenge,0,true,true),'Valid authenticator signature');sr(fn()=>$w->processGet($client,$auth,$sig,$pub,'wrong-challenge',0,true,true));sr(fn()=>$w->processGet($client,$auth,$sig,$pub,$challenge,1,true,true));
echo "PASS: exact origin, action binding, one-use expiring challenges, user verification and assertion signatures.\n";

foreach(['https://evil.invalid','//evil.invalid','/write/logout','/write/integration/google/callback?code=private','/write/../logout','/write%2flogout',[],null]as$value)sc(writer_login_destination($value)==='/write','Unsafe or action return URL rejected.');
sc(writer_login_destination('/write/program?token=private')==='/write/program','Private query parameters are discarded.');
sc(writer_login_destination('/write/communication?tab=content')==='/write/communication?tab=content','Calendar intent is retained.');
$_SESSION=['writer_return_to'=>'/write/program'];sc(writer_login_return()==='/write/program'&&!isset($_SESSION['writer_return_to']),'Return destination is consumed once.');
echo "PASS: allowlisted workspace resumption excludes action endpoints, external URLs and private parameters.\n";
