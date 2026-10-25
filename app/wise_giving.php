<?php
declare(strict_types=1);
require_once __DIR__.'/wise_api.php';
/** Receiving instructions only. No money-movement permission or unverified settlement. */
function wise_giving_fields(string $currency): array {
 $base=['holder'=>'Account holder','bank'=>'Bank name','bank_address'=>'Bank address'];
 return $base+match($currency){'EUR'=>['iban'=>'IBAN','bic'=>'BIC / SWIFT code'],'USD'=>['routing'=>'ACH routing number','account'=>'Account number','account_type'=>'Account type'] ,'GBP'=>['sort_code'=>'Sort code','account'=>'Account number','iban'=>'IBAN','bic'=>'BIC / SWIFT code'],default=>throw new InvalidArgumentException('Choose EUR, USD or GBP.')};
}
function wise_giving_rail(string $currency): string {return ['EUR'=>'SEPA bank transfer','USD'=>'US ACH bank transfer','GBP'=>'UK bank transfer · Faster Payments'][$currency]??'';}
function wise_giving_iban(string $s): bool {
 if(!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D',$s))return false;
 $n=substr($s,4).substr($s,0,4);$r=0;foreach(str_split($n)as$c){$v=ctype_alpha($c)?(string)(ord($c)-55):$c;foreach(str_split($v)as$d)$r=($r*10+(int)$d)%97;}return $r===1;
}
function wise_giving_validate(string $currency,array $in): array {
 $row=['currency'=>$currency,'enabled'=>!empty($in['enabled'])];foreach(wise_giving_fields($currency)as$key=>$label)$row[$key]=reader_text($in[$key]??'',$label,$key==='bank_address'?500:180,$row['enabled']&&!($currency==='GBP'&&in_array($key,['iban','bic'],true)));
 if(!$row['enabled'])return $row;
 if($currency==='EUR'){$row['iban']=strtoupper(preg_replace('/\s+/','',$row['iban']));$row['bic']=strtoupper($row['bic']);if(!wise_giving_iban($row['iban'])||!preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D',$row['bic']))throw new InvalidArgumentException('Check the EUR IBAN and BIC exactly against Wise.');}
 if($currency==='GBP'&&$row['iban']!==''){$row['iban']=strtoupper(preg_replace('/\s+/','',$row['iban']));$row['bic']=strtoupper($row['bic']);if(!wise_giving_iban($row['iban'])||!preg_match('/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/D',$row['bic']))throw new InvalidArgumentException('Check the GBP IBAN and BIC exactly against Wise.');}
 if($currency==='USD'){if(!preg_match('/^[0-9]{9}$/D',$row['routing']))throw new InvalidArgumentException('Use a nine-digit ACH routing number.');$n=array_map('intval',str_split($row['routing']));if((3*($n[0]+$n[3]+$n[6])+7*($n[1]+$n[4]+$n[7])+$n[2]+$n[5]+$n[8])%10!==0||array_sum($n)===0)throw new InvalidArgumentException('Check the ACH routing number checksum.');if(!preg_match('/^[0-9]{4,20}$/D',$row['account'])||!in_array(strtolower($row['account_type']),['checking','savings'],true))throw new InvalidArgumentException('Check the USD account number and account type.');$row['account_type']=strtolower($row['account_type']);}
 if($currency==='GBP'){$row['sort_code']=preg_replace('/[ -]/','',$row['sort_code']);if(!preg_match('/^[0-9]{6}$/D',$row['sort_code'])||!preg_match('/^[0-9]{8}$/D',$row['account']))throw new InvalidArgumentException('Use the six-digit sort code and eight-digit GBP account number.');}
 return $row;
}
function wise_giving_public(): array {$s=comm_get('giving.wise');$out=[];foreach(['USD','EUR','GBP']as$c)if(!empty($s['accounts'][$c]['enabled'])){$row=$s['accounts'][$c];$out[$c]=array_intersect_key($row,array_flip(array_merge(['currency'],array_keys(wise_giving_fields($c)))));}return $out;}
function wise_giving_account_update(array $old,string $currency,array $row,int $version,int $uid): array {
 if(!in_array($currency,['EUR','USD','GBP'],true)||($row['currency']??'')!==$currency)throw new InvalidArgumentException('Choose a receiving currency.');
 if($version!==(int)($old['version']??0))throw new RuntimeException('Account details changed. Reload before saving.');
 $old['accounts'][$currency]=$row;$old['version']=($old['version']??0)+1;$old['updated']=time();$old['user']=$uid;$old['account_saved'][$currency]=time();return $old;
}
function wise_giving_link(string $value): string {
 $value=trim($value);if($value==='')return '';
 if(!preg_match('~^https://wise\\.com/pay/(?:business|me)/[A-Za-z0-9._-]+/?$~D',$value))throw new InvalidArgumentException('Use your permanent HTTPS wise.com/pay/business/… or wise.com/pay/me/… link without query parameters.');
 return rtrim($value,'/');
}
function wise_giving_hosted(): string {
 $s=comm_get('giving.wise');if(empty($s['hosted_enabled']))return '';
 try{$link=wise_giving_link((string)($s['hosted_link']??''));}catch(InvalidArgumentException){return '';}
 return $link!==''&&str_contains($link,'/pay/business/')?$link.'?currency=USD&description=Writing%20support':$link;
}
function wise_giving_dispatch(string $path): bool {
 if($path==='/webhooks/wise')wise_api_webhook();
 if($path!=='/write/wise')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];if($uid!==(int)comm_config()['owner']){http_response_code(403);echo 'Owner access required.';return true;}$error='';
 if(is_post()){verify_csrf();try{if(($_POST['action']??'')==='save_hosted'){$link=wise_giving_link(reader_text($_POST['hosted_link']??'','Wise payment link',500));$enabled=!empty($_POST['hosted_enabled']);if($enabled&&($link===''||empty($_POST['hosted_confirm'])))throw new InvalidArgumentException('Confirm this permanent link belongs to your receiving account before publishing it.');comm_lock('giving.wise',function()use($link,$enabled,$uid){$s=comm_get('giving.wise');if((int)($_POST['version']??0)!==(int)($s['version']??0))throw new RuntimeException('Wise settings changed. Reload before saving.');$s['hosted_link']=$link;$s['hosted_enabled']=$enabled;$s['version']=($s['version']??0)+1;$s['updated']=time();$s['user']=$uid;comm_put('giving.wise',$s);});flash('success','Wise payment link saved. Bank accounts were retained. Receipt confirmation still requires verification.');redirect(url('/write/wise?tab=accounts'));}if(str_starts_with((string)($_POST['action']??''),'api_')){wise_api_owner_action($uid,$_POST);flash('success','Wise setup action completed. No contribution was automatically confirmed.');redirect(url('/write/wise'));}if(($_POST['action']??'')==='save_currency'){$currency=reader_choice($_POST['currency']??'',['EUR'=>'EUR','USD'=>'USD','GBP'=>'GBP'],'receiving currency');$account=wise_giving_validate($currency,(array)($_POST['accounts'][$currency]??[]));if($account['enabled']&&($_POST['confirm_public']??'')!=='1')throw new InvalidArgumentException('Confirm these receiving details may be public.');comm_lock('giving.wise',function()use($currency,$account,$uid){comm_put('giving.wise',wise_giving_account_update(comm_get('giving.wise'),$currency,$account,(int)($_POST['version']??0),$uid));});flash('success',$currency.' account saved. Your other currency accounts were retained.');redirect(url('/write/wise?tab=accounts&currency='.$currency));}if(!in_array((string)($_POST['action']??''),['','save'],true))throw new InvalidArgumentException('Choose a supported Wise save action.');$accounts=[];foreach(['EUR','USD','GBP']as$c)$accounts[$c]=wise_giving_validate($c,(array)($_POST['accounts'][$c]??[]));if(array_filter($accounts,fn($a)=>$a['enabled'])&&($_POST['confirm_public']??'')!=='1')throw new InvalidArgumentException('Confirm that the enabled receiving details may be public.');comm_lock('giving.wise',function()use($accounts,$uid){$old=comm_get('giving.wise');if((int)($_POST['version']??0)!==(int)($old['version']??0))throw new RuntimeException('Details changed. Reload before saving.');comm_put('giving.wise',['version'=>($old['version']??0)+1,'accounts'=>$accounts,'updated'=>time(),'user'=>$uid]);});flash('success','Wise receiving instructions saved. No payment automation or sending access was activated.');redirect(url('/write/wise?tab=accounts'));}catch(InvalidArgumentException|RuntimeException $e){$error=$e->getMessage();}}
 $record=comm_get('giving.wise');
 if($error!==''&&($_POST['action']??'')==='save_currency'&&in_array($_POST['currency']??'',['EUR','USD','GBP'],true)&&(int)($_POST['version']??0)===(int)($record['version']??0)){$c=$_POST['currency'];$attempt=(array)($_POST['accounts'][$c]??[]);$row=['currency'=>$c,'enabled'=>!empty($attempt['enabled'])];foreach(wise_giving_fields($c)as$key=>$label)$row[$key]=is_string($attempt[$key]??null)?mb_substr($attempt[$key],0,$key==='bank_address'?500:180):'';$accountInput=['currency'=>$c,'row'=>$row];}
 render_write('wise',['writeTitle'=>'Wise','record'=>$record,'accountInput'=>$accountInput??null,'error'=>$error]);return true;
}
