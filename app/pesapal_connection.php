<?php
declare(strict_types=1);
/** Exact-URL IPN registration/read-back. No order, debit or payment is created. */
function pesapal_connection_fingerprint(): string {return hash('sha256',setting('pesapal.environment','sandbox').'|'.setting('pesapal.consumer_key','').'|'.setting('pesapal.consumer_secret',''));}
function pesapal_ipn_match(mixed $rows,string $url,string $expected=''): string {
 if(!is_array($rows)||!array_is_list($rows))throw new RuntimeException('Pesapal IPN list was not available.');
 foreach($rows as$row){if(!is_array($row))throw new RuntimeException('Invalid IPN list.');$id=$row['ipn_id']??'';
  if(($row['url']??'')===$url&&(string)($row['status']??'')==='200'&&empty($row['error'])&&(!isset($row['ipn_status'])||(int)$row['ipn_status']===1)&&(!isset($row['ipn_notification_type_description'])||$row['ipn_notification_type_description']==='GET')&&is_string($id)&&preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/iD',$id)&&($expected===''||$expected===$id))return $id;
 }return '';
}
function pesapal_connection_ipn(?callable $request=null): string {
 $target=url('/payments/pesapal/ipn');
 if($request===null){$token=pesapal_token();$request=static fn(string $method,string $path,?array $body=null):array=>http_json($method,pesapal_base().$path,['Authorization: Bearer '.$token],$body);}
 $rows=$request('GET','/api/URLSetup/GetIpnList');$id=pesapal_ipn_match($rows,$target);
 if($id===''){
  $created=$request('POST','/api/URLSetup/RegisterIPN',['url'=>$target,'ipn_notification_type'=>'GET']);
  $id=pesapal_ipn_match([$created],$target);if($id==='')throw new RuntimeException('Pesapal did not confirm this website’s IPN.');
 }
 if(pesapal_ipn_match($request('GET','/api/URLSetup/GetIpnList'),$target,$id)==='')throw new RuntimeException('Pesapal IPN read-back did not match. Verify before trying again.');
 return $id;
}
