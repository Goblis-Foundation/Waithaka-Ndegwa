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
$sensitive='https://private.example/secret';$summary=pesapal_ipn_summary([['url'=>$sensitive,'ipn_id'=>'PRIVATE-ID']],$row['url']);
pp_check(!str_contains($summary,$sensitive)&&!str_contains($summary,'PRIVATE-ID'),'Other callback data stays private.');
$bad=array_replace($row,['ipn_status'=>0]);$calls=[];
try{pesapal_connection_ipn(function($m,$p,$b=null)use(&$calls,$bad){$calls[]=$m;return [$bad];});throw new LogicException('Inactive callback accepted.');}catch(PesapalConnectionException $e){pp_check(str_contains($e->getMessage(),'inactive')&&$calls===['GET'],'Existing unsuitable callback is diagnosed without duplicate registration.');}
$calls=[];$inspection=pesapal_connection_inspect(function($m,$p,$b=null)use(&$calls,$row){$calls[]=$m;return [$row];});
pp_check($calls===['GET']&&str_contains($inspection,'exact website callbacks: 1'),'Inspection is read only.');
try{pesapal_connection_ipn(function(){throw new RuntimeException('Remote service returned HTTP 401. SECRET');});}catch(PesapalConnectionException $e){pp_check(str_contains($e->getMessage(),'IPN list')&&str_contains($e->getMessage(),'HTTP 401')&&!str_contains($e->getMessage(),'SECRET'),'Stage and HTTP diagnostics omit raw error text.');}
echo "PASS: exact callback matching, idempotent reuse, registration read-back and failure rejection.\n";
