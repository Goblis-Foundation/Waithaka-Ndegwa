<?php
declare(strict_types=1);
require_once __DIR__.'/../app/pesapal_connection.php';
function url(string $path=''):string{return 'https://www.waithakandegwa.com'.$path;}
function pp_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
$id='12345678-1234-1234-1234-123456789abc';$row=['url'=>url('/payments/pesapal/ipn'),'ipn_id'=>$id,'status'=>'200','error'=>null];
pp_check(pesapal_ipn_match([$row],$row['url'])===$id,'Exact callback match.');
pp_check(pesapal_ipn_match([$row],$row['url'].'?other=1')==='','Foreign URL cannot match.');
$calls=[];$result=pesapal_connection_ipn(function($method,$path,$body=null)use(&$calls,$row){$calls[]=$method;return [$row];});
pp_check($result===$id&&$calls===['GET','GET'],'Existing IPN reused without registration.');
$calls=[];$result=pesapal_connection_ipn(function($method,$path,$body=null)use(&$calls,$row){$calls[]=$method;if(count($calls)===1)return [];if($method==='POST'){pp_check($body['url']===$row['url']&&$body['ipn_notification_type']==='GET','Exact registration payload.');return $row;}return [$row];});
pp_check($result===$id&&$calls===['GET','POST','GET'],'New IPN is independently read back.');
foreach([['error'=>['code'=>'failed']],null]as$bad){try{pesapal_ipn_match($bad,$row['url']);throw new LogicException('Bad list accepted.');}catch(RuntimeException $e){pp_check(!$e instanceof LogicException,'Malformed/error list fails closed.');}}
try{pesapal_connection_ipn(fn($m,$p,$b=null)=>$m==='POST'?$row:[]);throw new LogicException('Read-back failure accepted.');}catch(RuntimeException $e){pp_check(!$e instanceof LogicException,'Unconfirmed registration is not accepted.');}
echo "PASS: exact callback matching, idempotent reuse, registration read-back and failure rejection.\n";
