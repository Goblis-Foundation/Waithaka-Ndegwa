<?php
declare(strict_types=1);
function monthly_paypal_cfg(): array {return comm_get('giving.monthly.config');}
function monthly_paypal_choices(): array {
 $cfg=monthly_paypal_cfg();if(empty($cfg['enabled'])||!paypal_notifications_current($cfg)||payment_provider_environment('paypal')!=='live')return [];
 return array_filter($cfg['plans']??[],static fn($p)=>!empty($p['verified'])&&($p['currency']??'')==='USD');
}
function monthly_paypal_plan(string $product,string $amount): array {
 if(!preg_match('/^PROD-[A-Z0-9]{17}$/D',$product)||!in_array($amount,['5.00','10.00','25.00','50.00','100.00'],true))throw new InvalidArgumentException('Choose a supported monthly amount.');
 return ['product_id'=>$product,'name'=>'Writing support · USD '.$amount.' monthly','description'=>'Voluntary monthly support for Waithaka Ndegwa. Cancel at any time.','status'=>'ACTIVE','billing_cycles'=>[['frequency'=>['interval_unit'=>'MONTH','interval_count'=>1],'tenure_type'=>'REGULAR','sequence'=>1,'total_cycles'=>0,'pricing_scheme'=>['fixed_price'=>['value'=>$amount,'currency_code'=>'USD']]]],'payment_preferences'=>['auto_bill_outstanding'=>false,'payment_failure_threshold'=>1]];
}
function monthly_paypal_plan_matches(array $plan,string $product,string $amount): bool {
 $c=$plan['billing_cycles']??[];return ($plan['product_id']??'')===$product&&($plan['status']??'')==='ACTIVE'&&count($c)===1&&($c[0]['tenure_type']??'')==='REGULAR'&&($c[0]['frequency']['interval_unit']??'')==='MONTH'&&(int)($c[0]['frequency']['interval_count']??0)===1&&(int)($c[0]['total_cycles']??-1)===0&&($c[0]['pricing_scheme']['fixed_price']['currency_code']??'')==='USD'&&payment_equal($amount,$c[0]['pricing_scheme']['fixed_price']['value']??null)&&payment_equal('0.00',$plan['taxes']['percentage']??'0.00')&&empty($plan['payment_preferences']['auto_bill_outstanding'])&&payment_equal('0.00',$plan['payment_preferences']['setup_fee']['value']??'0.00');
}
function monthly_paypal_setup(array $in): void {
 $amount=payment_decimal($in['monthly_amount']??'10');if(!in_array($amount,['5.00','10.00','25.00','50.00','100.00'],true))throw new InvalidArgumentException('Choose a preset monthly amount.');
 $auth=comm_get('paypal.auth');if(!isset($auth['fingerprint'])||!hash_equals($auth['fingerprint'],paypal_connection_fingerprint()))throw new InvalidArgumentException('Verify the selected PayPal app first.');
 comm_lock('monthly.paypal.setup',function()use($amount){
  $cfg=monthly_paypal_cfg();if(!paypal_notifications_current($cfg))$cfg=['fingerprint'=>paypal_connection_fingerprint(),'plans'=>[],'enabled'=>false];
  $headers=['Authorization: Bearer '.paypal_token(),'Prefer: return=representation'];
  if(empty($cfg['product'])){
   $product=http_json('POST',paypal_base().'/v1/catalogs/products',array_merge($headers,['PayPal-Request-Id: wnprod-'.substr($cfg['fingerprint'],0,30)]),['name'=>'Waithaka Ndegwa monthly writing support','description'=>'Voluntary recurring support for independent writing. No promised goods, access or charitable deduction.','type'=>'SERVICE','home_url'=>url('/contribute/monthly')]);
   if(!preg_match('/^PROD-[A-Z0-9]{17}$/D',(string)($product['id']??'')))throw new RuntimeException('PayPal did not confirm the product.');$cfg['product']=$product['id'];comm_put('giving.monthly.config',$cfg);
  }
  if(empty($cfg['plans'][$amount]['id'])){
   $plan=http_json('POST',paypal_base().'/v1/billing/plans',array_merge($headers,['PayPal-Request-Id: wnplan-'.substr(hash('sha256',$cfg['fingerprint'].'|'.$amount),0,30)]),monthly_paypal_plan($cfg['product'],$amount));
   if(!preg_match('/^P-[A-Z0-9]{1,30}$/D',(string)($plan['id']??'')))throw new RuntimeException('PayPal did not confirm the monthly plan.');
   $cfg['plans'][$amount]=['id'=>$plan['id'],'amount'=>$amount,'currency'=>'USD','verified'=>false];comm_put('giving.monthly.config',$cfg);
  }
  $plan=http_json('GET',paypal_base().'/v1/billing/plans/'.$cfg['plans'][$amount]['id'],$headers);
  if(!monthly_paypal_plan_matches($plan,$cfg['product'],$amount))throw new RuntimeException('The plan does not match the approved monthly price and schedule.');
  $cfg['plans'][$amount]['verified']=true;$cfg['updated']=time();comm_put('giving.monthly.config',$cfg);
 });
}
function monthly_record(string $token): array {
 if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('This private support link is unavailable.');
 $record=comm_get('giving.monthly.record.'.hash('sha256',$token));if(!$record)throw new InvalidArgumentException('This private support link is unavailable.');return $record;
}
function monthly_save(array $record): void {comm_put('giving.monthly.record.'.hash('sha256',$record['token']),$record);}
function monthly_subscription_matches(array $record,array $data): bool {
 return isset($record['subscription'],$record['plan'])&&($data['id']??'')===$record['subscription']&&($data['plan_id']??'')===$record['plan']&&($data['custom_id']??'')===$record['id']&&payment_equal('0.00',$data['shipping_amount']['value']??'0.00')&&empty($data['plan_overridden'])&&(string)($data['quantity']??'1')==='1'&&in_array($data['status']??'',['APPROVAL_PENDING','APPROVED','ACTIVE','SUSPENDED','CANCELLED','EXPIRED'],true);
}
function monthly_refresh(string $token): array {
 return comm_lock('monthly.'.hash('sha256',$token),function()use($token){
  $r=monthly_record($token);if(!paypal_notifications_current($r)||empty($r['subscription']))throw new RuntimeException('This subscription needs a connection check. Manage payments directly in PayPal meanwhile.');
  $s=http_json('GET',paypal_base().'/v1/billing/subscriptions/'.$r['subscription'],['Authorization: Bearer '.paypal_token()]);
  if(!monthly_subscription_matches($r,$s))throw new RuntimeException('PayPal subscription details did not match this support record.');
  $r['status']=$s['status'];$r['checked']=time();$r['next_billing']=$s['billing_info']['next_billing_time']??'';$r['failed_payments']=(int)($s['billing_info']['failed_payments_count']??0);
  $last=$s['billing_info']['last_payment']??[];if(($last['amount']['currency_code']??'')===$r['currency']&&payment_equal($r['amount'],$last['amount']['value']??null))$r['last_payment']=['amount'=>$r['amount'],'currency'=>$r['currency'],'time'=>substr((string)($last['time']??''),0,40)];
  // An active mandate is not evidence that any instalment was paid.
  monthly_save($r);return $r;
 });
}
function monthly_start(array $in): string {
 $choices=monthly_paypal_choices();$amount=payment_decimal($in['amount']??'10');
 if(!isset($choices[$amount])||empty($in['monthly_ack']))throw new InvalidArgumentException('Choose an available monthly amount and confirm recurring support.');
 if(!journeys_rate_limit('monthly-start',ip_hash(),5,3600))throw new InvalidArgumentException('Please wait before requesting another subscription.');
 $currentPlan=http_json('GET',paypal_base().'/v1/billing/plans/'.$choices[$amount]['id'],['Authorization: Bearer '.paypal_token()]);if(!monthly_paypal_plan_matches($currentPlan,(string)(monthly_paypal_cfg()['product']??''),$amount))throw new RuntimeException('This monthly amount needs re-verification. Please choose another amount or give once.');
 $token=bin2hex(random_bytes(32));$r=['token'=>$token,'id'=>'MONTHLY-'.strtoupper(bin2hex(random_bytes(12))),'amount'=>$amount,'currency'=>'USD','plan'=>$choices[$amount]['id'],'fingerprint'=>paypal_connection_fingerprint(),'status'=>'CREATING','created'=>time(),'checked'=>0];monthly_save($r);
 try{
  $back=url('/contribute/monthly/manage/'.$token);
  $s=http_json('POST',paypal_base().'/v1/billing/subscriptions',['Authorization: Bearer '.paypal_token(),'PayPal-Request-Id: wnsub-'.substr(hash('sha256',$r['id']),0,30),'Prefer: return=representation'],['plan_id'=>$r['plan'],'custom_id'=>$r['id'],'application_context'=>['brand_name'=>'Waithaka Ndegwa','shipping_preference'=>'NO_SHIPPING','user_action'=>'SUBSCRIBE_NOW','return_url'=>$back,'cancel_url'=>$back]]);
  if(!preg_match('/^I-[A-Z0-9]{1,30}$/D',(string)($s['id']??'')))throw new RuntimeException('Subscription creation was not confirmed.');
  $r['subscription']=$s['id'];$r['status']='APPROVAL_PENDING';monthly_save($r);
  comm_put('giving.monthly.provider.'.hash('sha256',$r['fingerprint'].'|'.$r['subscription']),['token'=>$token]);
  foreach($s['links']??[]as$link)if(($link['rel']??'')==='approve'&&payment_checkout_url('paypal',(string)($link['href']??'')))return $link['href'];
  throw new RuntimeException('No trusted approval link returned.');
 }catch(Throwable $e){$r['status']='CREATION_UNCONFIRMED';monthly_save($r);throw new RuntimeException('PayPal could not confirm the subscription setup. No monthly support is active on this page. Check PayPal before trying again.');}
}
function monthly_cancel(string $token): array {
 $r=monthly_refresh($token);if(in_array($r['status'],['CANCELLED','EXPIRED'],true))return $r;
 if(!in_array($r['status'],['ACTIVE','APPROVED','SUSPENDED'],true))throw new InvalidArgumentException('No active subscription is available to cancel here. You can also manage it in PayPal.');
 // 204 is expected; http_json requires JSON, so use a tightly scoped no-body transport.
 $ch=curl_init(paypal_base().'/v1/billing/subscriptions/'.$r['subscription'].'/cancel');
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['reason'=>'Supporter cancelled future monthly support.']),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.paypal_token(),'Content-Type: application/json'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15]);curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
 if($code!==204)throw new RuntimeException('Cancellation was not confirmed. Check PayPal before retrying.');return monthly_refresh($token);
}
function monthly_paypal_events(): array {return ['BILLING.SUBSCRIPTION.ACTIVATED','BILLING.SUBSCRIPTION.CANCELLED','BILLING.SUBSCRIPTION.EXPIRED','BILLING.SUBSCRIPTION.PAYMENT.FAILED','BILLING.SUBSCRIPTION.SUSPENDED','BILLING.SUBSCRIPTION.UPDATED','PAYMENT.SALE.COMPLETED','PAYMENT.SALE.DENIED','PAYMENT.SALE.REFUNDED','PAYMENT.SALE.REVERSED'];}
function monthly_notice(array $event): bool {
 if(!in_array($event['event_type']??'',monthly_paypal_events(),true))return false;
 $resource=$event['resource']??[];$sub=str_starts_with($event['event_type'],'BILLING.SUBSCRIPTION.')?($resource['id']??''):($resource['billing_agreement_id']??'');
 if(!is_string($sub)||!preg_match('/^I-[A-Z0-9]{1,30}$/D',$sub))return false;
 $index=comm_get('giving.monthly.provider.'.hash('sha256',paypal_connection_fingerprint().'|'.$sub));if(empty($index['token']))return false;
 comm_lock('monthly.'.hash('sha256',$index['token']),function()use($index,$event){$r=monthly_record($index['token']);$r['refresh_due']=true;$r['last_notice']=$event['event_type'];if(in_array($event['event_type'],['PAYMENT.SALE.REFUNDED','PAYMENT.SALE.REVERSED'],true))$r['review']='Refund/reversal notice: review provider transaction records.';monthly_save($r);});return true;
}
function monthly_work(): void {
 $cfg=monthly_paypal_cfg();if(!paypal_notifications_current($cfg))return;$rows=comm_records('giving.monthly.record',2000);$count=0;
 foreach($rows as$r){if(empty($r['subscription'])||!paypal_notifications_current($r)||(!empty($r['checked'])&&$r['checked']>time()-1800&&empty($r['refresh_due'])))continue;if($count++>=5)break;
  try{monthly_refresh($r['token']);comm_lock('monthly.'.hash('sha256',$r['token']),function()use($r){$now=monthly_record($r['token']);unset($now['refresh_due']);monthly_save($now);});}catch(Throwable){/* Keep unresolved subscription visible for a later read-only retry. */}
 }
}
function monthly_dispatch(string $path): bool {
 if($path==='/write/monthly-support'){
  require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);if((int)current_user()['id']!==(int)comm_config()['owner']){http_response_code(403);return true;}$error='';
  if(is_post()){verify_csrf();try{if(($_POST['action']??'')==='prepare')monthly_paypal_setup($_POST);elseif(($_POST['action']??'')==='publish'){comm_lock('monthly.paypal.setup',function(){$c=monthly_paypal_cfg();if(!paypal_notifications_current($c)||!array_filter($c['plans']??[],fn($p)=>!empty($p['verified'])))throw new InvalidArgumentException('Prepare and verify at least one monthly plan first.');$c['enabled']=!empty($_POST['enabled']);comm_put('giving.monthly.config',$c);});}else throw new InvalidArgumentException('Choose a monthly support action.');flash('success','Monthly support settings saved. No supporter subscription was started.');redirect(url($path));}catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'The provider could not confirm this plan. Retry the same amount to preserve the request reference.';}}
  render_write('monthly_support',['writeTitle'=>'Monthly support','cfg'=>monthly_paypal_cfg(),'records'=>comm_records('giving.monthly.record',2000),'error'=>$error]);return true;
 }
 if($path!=='/contribute/monthly'&&!preg_match('~^/contribute/monthly/manage/([a-f0-9]{64})$~D',$path,$m))return false;
 private_response_headers();journeys_method(['GET','HEAD','POST']);header('Referrer-Policy: no-referrer');$error='';$record=null;
 if(isset($m[1])){try{$record=monthly_record($m[1]);if(is_post()){verify_csrf();if(($_POST['action']??'')==='cancel'){if(empty($_POST['cancel_ack']))throw new InvalidArgumentException('Confirm cancellation of future monthly support.');$record=monthly_cancel($m[1]);}elseif(($_POST['action']??'')==='refresh')$record=monthly_refresh($m[1]);else throw new InvalidArgumentException('Choose an action.');}elseif(($record['checked']??0)<time()-30&&isset($record['subscription']))$record=monthly_refresh($m[1]);}catch(Throwable $e){$error=$e->getMessage();}}
 elseif(is_post()){verify_csrf();try{redirect(monthly_start($_POST));}catch(Throwable $e){$error=$e->getMessage();}}
 render('monthly_giving',['page'=>'giving','privatePage'=>true,'suppressAnalytics'=>true,'error'=>$error,'record'=>$record,'management'=>isset($m[1]),'choices'=>monthly_paypal_choices(),'seo'=>['title'=>'Monthly writing support · Waithaka Ndegwa','description'=>'Choose voluntary monthly support and manage it securely.','canonical'=>url('/contribute/monthly'),'robots'=>'noindex,nofollow']]);return true;
}
