<?php
declare(strict_types=1);
final class PesapalConnectionException extends RuntimeException {}
/** Exact-URL IPN registration/read-back. No order, debit or payment is created. */
function pesapal_connection_fingerprint(): string {return hash('sha256',setting('pesapal.environment','sandbox').'|'.setting('pesapal.consumer_key','').'|'.setting('pesapal.consumer_secret',''));}
function pesapal_connection_request(): Closure {
 $token=pesapal_token();
 return static fn(string $method,string $path,?array $body=null):array=>http_json($method,pesapal_base().$path,['Authorization: Bearer '.$token],$body);
}
/** Only allow-listed structural metadata, never provider bodies, URLs or identifiers. */
function pesapal_ipn_summary(mixed $rows,string $target): string {
 if(!is_array($rows))return 'Response was not an array.';
 if(!array_is_list($rows)){
  $code=$rows['error']['code']??null;
  return 'Response was an object, not the registered-IPN list.'.(is_string($code)&&preg_match('/^[A-Za-z0-9_.-]{1,60}$/D',$code)?' Provider error code: '.$code.'.':'');
 }
 $same=[];foreach($rows as$row)if(is_array($row)&&($row['url']??'')===$target)$same[]=$row;
 $out='Registered rows: '.count($rows).'; exact website callbacks: '.count($same).'.';$codes=[];foreach($rows as$row){$code=is_array($row)?($row['error']['code']??null):null;if(is_string($code)&&preg_match('/^[A-Za-z0-9_.-]{1,60}$/D',$code))$codes[]=$code;}if($codes)$out.=' Provider error codes: '.implode(', ',array_slice(array_unique($codes),0,3)).'.';
 foreach(array_slice($same,0,3)as$row){
  $status=$row['status']??null;$method=$row['ipn_notification_type_description']??null;
  $out.=' Status: '.(is_scalar($status)&&preg_match('/^[0-9]{3}$/D',(string)$status)?$status:($status===null?'missing/null':'unrecognised')).'; error field: '.(is_array($row['error']??null)?'object with '.count(array_filter($row['error'],static fn($v)=>$v!==null&&$v!=='' )).' populated fields':(empty($row['error'])?'empty':'present')).'; active field: '.(!isset($row['ipn_status'])?'not supplied':((string)$row['ipn_status']==='1'?'active':((string)$row['ipn_status']==='0'?'inactive':'unrecognised'))).'; method: '.(is_string($method)&&in_array(strtoupper($method),['GET','POST'],true)?strtoupper($method):($method===null?'not supplied':'unrecognised')).'; ID format: '.(is_string($row['ipn_id']??null)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$row['ipn_id'])?'valid UUID':'not a UUID').'.';
 }return $out;
}
function pesapal_ipn_call(callable $request,string $stage,string $method,string $path,?array $body=null): mixed {
 try{return $request($method,$path,$body);}catch(Throwable $e){
  $http=preg_match('/\bHTTP ([1-5][0-9]{2})\b/',$e->getMessage(),$m)?' (HTTP '.$m[1].')':'';
  throw new PesapalConnectionException('Pesapal '.$stage.' request failed'.$http.'. No payment was initiated.');
 }
}
function pesapal_ipn_match(mixed $rows,string $url,string $expected=''): string {
 if(!is_array($rows)||!array_is_list($rows))throw new PesapalConnectionException('Pesapal IPN list was not available. '.pesapal_ipn_summary($rows,$url));
 foreach($rows as$row){if(!is_array($row))throw new PesapalConnectionException('Pesapal IPN list contained an invalid row.');$id=$row['ipn_id']??'';
  $status=$row['status']??null;$statusText=is_scalar($status)?trim((string)$status):'';$active=(string)($row['ipn_status']??'')==='1';
  $explicitFailure=preg_match('/^[345][0-9]{2}$/D',$statusText)||in_array(strtolower($statusText),['0','false','inactive','failed','error'],true);
  $positiveStatus=$statusText==='200'||($active&&(is_scalar($status)||$status===null)&&!$explicitFailure);
  if(($row['url']??'')===$url&&$positiveStatus&&empty($row['error'])&&(!isset($row['ipn_status'])||(int)$row['ipn_status']===1)&&(!isset($row['ipn_notification_type_description'])||(is_string($row['ipn_notification_type_description'])&&strtoupper(trim($row['ipn_notification_type_description']))==='GET'))&&is_string($id)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$id)&&($expected===''||strtolower($expected)===strtolower($id)))return $id;
 }return '';
}
function pesapal_connection_inspect(?callable $request=null): string {
 $request??=pesapal_connection_request();$target=url('/payments/pesapal/ipn');
 $rows=pesapal_ipn_call($request,'IPN list','GET','/api/URLSetup/GetIpnList');
 return 'Read-only callback inspection. '.pesapal_ipn_summary($rows,$target).' No callback or payment was created.';
}
function pesapal_connection_ipn(?callable $request=null): string {
 $target=url('/payments/pesapal/ipn');$request??=pesapal_connection_request();
 $rows=pesapal_ipn_call($request,'IPN list','GET','/api/URLSetup/GetIpnList');$id=pesapal_ipn_match($rows,$target);
 if($id===''){
  foreach($rows as$row)if(($row['url']??'')===$target)throw new PesapalConnectionException('An existing website IPN needs review; another was not registered. '.pesapal_ipn_summary($rows,$target));
  $created=pesapal_ipn_call($request,'IPN registration','POST','/api/URLSetup/RegisterIPN',['url'=>$target,'ipn_notification_type'=>'GET']);
  $id=pesapal_ipn_match([$created],$target);if($id==='')throw new PesapalConnectionException('Pesapal did not confirm this website’s IPN registration. '.pesapal_ipn_summary([$created],$target));
 }
 $readback=pesapal_ipn_call($request,'IPN read-back','GET','/api/URLSetup/GetIpnList');
 if(pesapal_ipn_match($readback,$target,$id)==='')throw new PesapalConnectionException('Pesapal IPN read-back did not match. '.pesapal_ipn_summary($readback,$target));
 return $id;
}
