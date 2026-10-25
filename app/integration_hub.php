<?php
declare(strict_types=1);
function integration_hub_definitions(): array {
 return [
 'mpesa'=>['name'=>'M-Pesa','prefix'=>'mpesa','environment'=>true,'secrets'=>['consumer_key'=>'Consumer key','consumer_secret'=>'Consumer secret','passkey'=>'Passkey'],'fields'=>['shortcode'=>'Business shortcode','transaction_type'=>'Transaction type'],'defaults'=>['transaction_type'=>'CustomerPayBillOnline']],
 'pesapal'=>['name'=>'Pesapal · cards','prefix'=>'pesapal','environment'=>true,'secrets'=>['consumer_key'=>'Consumer key','consumer_secret'=>'Consumer secret'],'fields'=>['ipn_id'=>'Registered IPN ID'],'defaults'=>[]],
 'paypal'=>['name'=>'PayPal','prefix'=>'paypal','environment'=>true,'secrets'=>['client_id'=>'Client ID','secret'=>'Client secret'],'fields'=>[],'defaults'=>[]],
 'openai'=>['name'=>'OpenAI','prefix'=>'openai','environment'=>false,'secrets'=>['api_key'=>'API key'],'fields'=>['model'=>'Model'],'defaults'=>['model'=>'gpt-5.4-mini']],
 'gemini'=>['name'=>'Google Gemini','prefix'=>'google','environment'=>false,'secrets'=>['gemini_key'=>'API key'],'fields'=>['gemini_model'=>'Model'],'defaults'=>['gemini_model'=>'gemini-3.8-flash']]
 ];
}
function integration_hub_values(string $provider,array $in): array {
 $d=integration_hub_definitions()[$provider]??null;if(!$d)throw new InvalidArgumentException('Choose an integration.');$values=[];
 foreach($d['secrets']as$key=>$label){$v=$in[$key]??'';if(!is_string($v)||strlen($v)>5000||preg_match('/[\\x00-\\x20\\x7f]/',$v))throw new InvalidArgumentException('Enter valid '.$label.'.');if($v!=='')$values[$d['prefix'].'.'.$key]=['value'=>$v,'secret'=>true];}
 foreach($d['fields']as$key=>$label){$v=reader_text($in[$key]??$d['defaults'][$key]??'',$label,240);if($key==='transaction_type'&&!in_array($v,['CustomerPayBillOnline','CustomerBuyGoodsOnline'],true))throw new InvalidArgumentException('Choose a valid M-Pesa transaction type.');if(in_array($key,['model','gemini_model'],true)&&!preg_match('/^[a-zA-Z0-9._-]{1,120}$/D',$v))throw new InvalidArgumentException('Enter a model identifier.');$values[$d['prefix'].'.'.$key]=['value'=>$v,'secret'=>false];}
 if($d['environment'])$values[$d['prefix'].'.environment']=['value'=>reader_choice($in['environment']??'sandbox',['sandbox'=>'Sandbox','live'=>'Live'],'environment'),'secret'=>false];return $values;
}
function integration_hub_configured(string $provider): bool {
 $d=integration_hub_definitions()[$provider]??null;if(!$d)return false;foreach($d['secrets']as$key=>$label)if(setting($d['prefix'].'.'.$key,'')==='')return false;return true;
}
function integration_hub_dispatch(string $path): bool {
 if(!preg_match('~^/write/integration/([a-z]+)$~D',$path,$m))return false;$provider=$m[1];$definition=integration_hub_definitions()[$provider]??null;if(!$definition)not_found();require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);if((int)current_user()['id']!==(int)comm_config()['owner']){http_response_code(403);echo 'Owner access required.';return true;}$error='';$check=null;
 if(is_post()){verify_csrf();try{$action=$_POST['action']??'save';
 if($action==='save'){$values=integration_hub_values($provider,$_POST);db()->beginTransaction();try{foreach($values as$key=>$v)set_setting($key,$v['value'],$v['secret']);db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}flash('success',$definition['name'].' settings saved securely. Verify access before relying on this connection.');redirect(url('/write/integration/'.$provider));}
 elseif($action==='paypal_notifications'&&$provider==='paypal'){paypal_notifications_save($_POST);flash('success','PayPal notification settings verified and saved. No payment was initiated.');redirect(url('/write/integration/paypal'));}
 elseif($action==='verify'){if(!in_array($provider,['mpesa','pesapal','paypal'],true)||!integration_hub_configured($provider))throw new InvalidArgumentException('Save this payment provider’s credentials first.');if(!journeys_rate_limit('finance-verify',(string)current_user()['id'],6,3600))throw new RuntimeException('Please wait before checking another payment connection.');$token=match($provider){'mpesa'=>mpesa_token(),'pesapal'=>pesapal_token(),'paypal'=>paypal_token()};if(!is_string($token)||$token==='')throw new RuntimeException('No provider token returned.');if($provider==='paypal')comm_put('paypal.auth',['fingerprint'=>paypal_connection_fingerprint(),'at'=>time()]);$check='Provider authentication succeeded. Merchant receiving rights, callbacks and a real payment have not been tested.';}
 else throw new InvalidArgumentException('Choose a supported setup action.');
 }catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'This provider’s access could not be verified. Check the selected environment, credentials and provider approval. No payment was initiated.';}}
 render_write('integration_setup',['writeTitle'=>$definition['name'].' connection','provider'=>$provider,'definition'=>$definition,'error'=>$error,'check'=>$check]);return true;
}
