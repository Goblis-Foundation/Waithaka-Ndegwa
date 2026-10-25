<?php
declare(strict_types=1);

/** PayPal notifications are hints: only authenticated order reads settle contributions. */
function paypal_connection_fingerprint(): string {
 return hash('sha256',payment_provider_environment('paypal').'|'.setting('paypal.client_id','').'|'.setting('paypal.secret',''));
}
function paypal_notifications_config(): array {return comm_get('paypal.notifications');}
function paypal_notifications_current(array $cfg): bool {
 return isset($cfg['fingerprint'])&&hash_equals((string)$cfg['fingerprint'],paypal_connection_fingerprint());
}
function paypal_notification_types(): array {
 return ['CHECKOUT.ORDER.APPROVED','PAYMENT.CAPTURE.COMPLETED','PAYMENT.CAPTURE.PENDING','PAYMENT.CAPTURE.DENIED','PAYMENT.CAPTURE.REFUNDED','PAYMENT.CAPTURE.REVERSED'];
}
function paypal_notification_locator(array $event): array {
 $type=$event['event_type']??'';if(!in_array($type,paypal_notification_types(),true))return [];
 $r=$event['resource']??[];if(!is_array($r))throw new InvalidArgumentException('Invalid PayPal resource.');
 $id=$type==='CHECKOUT.ORDER.APPROVED'?($r['id']??''):($r['supplementary_data']['related_ids']['order_id']??'');$kind='order';
 if($id===''){$kind='capture';$id=$r['supplementary_data']['related_ids']['capture_id']??'';if($id===''&&$type==='PAYMENT.CAPTURE.REVERSED')$id=$r['id']??'';}
 if(!is_string($id)||!preg_match('/^[A-Z0-9]{1,36}$/D',$id))return [];
 return ['kind'=>$kind,'id'=>$id,'review'=>in_array($type,['PAYMENT.CAPTURE.REFUNDED','PAYMENT.CAPTURE.REVERSED'],true)];
}
function paypal_notification_verification(array $headers,array $event,string $webhookId,string $env): array {
 if(!preg_match('/^[A-Z0-9]{1,80}$/D',$webhookId))throw new InvalidArgumentException('Invalid webhook ID.');
 $out=['webhook_id'=>$webhookId,'webhook_event'=>$event];
 foreach(['auth_algo'=>'HTTP_PAYPAL_AUTH_ALGO','cert_url'=>'HTTP_PAYPAL_CERT_URL','transmission_id'=>'HTTP_PAYPAL_TRANSMISSION_ID','transmission_sig'=>'HTTP_PAYPAL_TRANSMISSION_SIG','transmission_time'=>'HTTP_PAYPAL_TRANSMISSION_TIME']as$key=>$header){
  $v=$headers[$header]??'';if(!is_string($v)||$v===''||strlen($v)>4096||preg_match('/[\x00-\x20\x7f]/',$v))throw new InvalidArgumentException('Missing PayPal signature header.');$out[$key]=$v;
 }
 $hosts=$env==='live'?['api.paypal.com','api-m.paypal.com']:['api.sandbox.paypal.com','api-m.sandbox.paypal.com'];$cert=parse_url($out['cert_url']);
 if(!is_array($cert)||($cert['scheme']??'')!=='https'||!in_array($cert['host']??'',$hosts,true)||isset($cert['user'])||isset($cert['pass'])||isset($cert['port'])||isset($cert['query'])||isset($cert['fragment'])||!preg_match('~^/v1/notifications/certs/[A-Za-z0-9-]+$~D',$cert['path']??''))throw new InvalidArgumentException('Unexpected PayPal certificate URL.');
 if($out['auth_algo']!=='SHA256withRSA')throw new InvalidArgumentException('Unexpected PayPal signature algorithm.');
 return $out;
}
function paypal_notifications_save(array $in): void {
 $cfg=paypal_notifications_config();if((int)($in['notification_version']??0)!==(int)($cfg['version']??0))throw new InvalidArgumentException('Notification settings changed. Reload before saving.');
 $id=reader_text($in['webhook_id']??'','PayPal webhook ID',80);$enabled=!empty($in['notifications_enabled']);$reconcile=!empty($in['reconcile_enabled']);$fingerprint=paypal_connection_fingerprint();
 if($enabled||$reconcile){
  $auth=comm_get('paypal.auth');if(empty($auth['fingerprint'])||!hash_equals($fingerprint,$auth['fingerprint']))throw new InvalidArgumentException('Verify these PayPal credentials before enabling payment checks.');
 }
 if($enabled){
  if(!preg_match('/^[A-Z0-9]{1,80}$/D',$id))throw new InvalidArgumentException('Enter the PayPal webhook ID.');
  $hook=http_json('GET',paypal_base().'/v1/notifications/webhooks/'.$id,['Authorization: Bearer '.paypal_token()]);
  if(($hook['id']??'')!==$id||($hook['url']??'')!==url('/webhooks/paypal'))throw new InvalidArgumentException('This webhook does not belong to this app and exact callback URL.');
  $types=array_column($hook['event_types']??[],'name');foreach(paypal_notification_types()as$type)if(!in_array($type,$types,true))throw new InvalidArgumentException('Select all six listed checkout and capture events in PayPal.');
 }
 comm_lock('paypal.notifications',function()use($in,$id,$enabled,$reconcile,$fingerprint){$old=paypal_notifications_config();if((int)($in['notification_version']??0)!==(int)($old['version']??0))throw new InvalidArgumentException('Notification settings changed. Reload before saving.');
  $same=paypal_notifications_current($old);comm_put('paypal.notifications',['version'=>($old['version']??0)+1,'webhook_id'=>$id,'enabled'=>$enabled,'reconcile'=>$reconcile,'fingerprint'=>$fingerprint,'since'=>$same&&isset($old['since'])?$old['since']:(string)db()->query('SELECT NOW()')->fetchColumn(),'updated'=>time()]);
 });
}
/** Bounded, read-only checks for new PayPal contributions. No captures or mail sends. */
function paypal_notifications_work(int $limit=5): array {
 $cfg=paypal_notifications_config();$result=['checked'=>0,'confirmed'=>0];if(empty($cfg['reconcile'])||!paypal_notifications_current($cfg))return $result;
 $q=db()->prepare("SELECT c.id FROM contributions c JOIN payment_reconciliation r ON r.contribution_id=c.id WHERE c.method='paypal' AND c.created_at>=? AND r.provider_environment=? AND r.state IN ('pending','retry') AND (r.next_attempt_at IS NULL OR r.next_attempt_at<=NOW()) AND (r.lease_until IS NULL OR r.lease_until<NOW()) ORDER BY COALESCE(r.next_attempt_at,'1970-01-01') LIMIT ".max(1,min(10,$limit)));$q->execute([$cfg['since'],payment_provider_environment('paypal')]);
 foreach($q->fetchAll(PDO::FETCH_COLUMN)as$id){$result['checked']++;if(payment_reconcile((int)$id))$result['confirmed']++;}return $result;
}
/** Call only after signature verification. Does not alter financial contribution status. */
function paypal_notifications_record(array $event): bool {
  $loc=paypal_notification_locator($event);if(!$loc)return false;
  if($loc['kind']==='order')$row=payment_find('paypal',$loc['id']);
  else{$q=db()->prepare("SELECT c.* FROM contributions c JOIN payment_provider_ids i ON i.contribution_id=c.id WHERE i.provider='paypal' AND i.identifier_type='capture' AND i.external_id=? AND c.method='paypal'");$q->execute([$loc['id']]);$row=$q->fetch()?:null;}
  if(!$row)return false;
  $q=db()->prepare('SELECT provider_environment FROM payment_reconciliation WHERE contribution_id=?');$q->execute([$row['id']]);if($q->fetchColumn()!==payment_provider_environment('paypal'))throw new InvalidArgumentException('PayPal environment differs from the original order.');
  $safe=['event_id'=>$event['id'],'event_type'=>$event['event_type'],'locator'=>$loc];
  // Existing unique event keys suppress duplicate delivery. Refund/reversal stays private for authoritative review.
  payment_event($row,'paypal_webhook','verified_notification',$safe);
  if($loc['review'])payment_needs_review($row,'Verified PayPal refund/reversal notification. Check current provider refund records before deciding the financial status.','review');
  else db()->prepare("UPDATE payment_reconciliation SET next_attempt_at=NOW(),state=IF(state IN ('verified','reversed','review'),state,'pending') WHERE contribution_id=?")->execute([$row['id']]);
  comm_put('paypal.notification.last',['at'=>time(),'event_type'=>$event['event_type']]);
  return true;
}
function paypal_notifications_webhook(): never {
 $method=$_SERVER['REQUEST_METHOD']??'';if(in_array($method,['GET','HEAD'],true))json_response(['ok'=>true,'service'=>'PayPal webhook','payments_confirmed'=>false],200);
 if($method!=='POST')json_response(['ok'=>false],405);
 $raw=file_get_contents('php://input',false,null,0,262145);if(!is_string($raw)||strlen($raw)>262144)json_response(['ok'=>false],413);
 try{$event=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($event)||!is_string($event['id']??null)||!preg_match('/^[A-Za-z0-9-]{1,120}$/D',$event['id']))throw new InvalidArgumentException();}catch(JsonException|InvalidArgumentException){json_response(['ok'=>false],400);}
 $cfg=paypal_notifications_config();if(empty($cfg['enabled'])||!paypal_notifications_current($cfg))json_response(['ok'=>false],503);
 try{$body=paypal_notification_verification($_SERVER,$event,(string)$cfg['webhook_id'],payment_provider_environment('paypal'));}catch(InvalidArgumentException){json_response(['ok'=>false],401);}
 try{$verified=http_json('POST',paypal_base().'/v1/notifications/verify-webhook-signature',['Authorization: Bearer '.paypal_token()],$body,15);}catch(Throwable){json_response(['ok'=>false],503);}
 if(($verified['verification_status']??'')!=='SUCCESS')json_response(['ok'=>false],401);
 try{
  paypal_notifications_record($event);
  json_response(['ok'=>true,'payments_confirmed'=>false],200);
 }catch(Throwable){json_response(['ok'=>false],503);}
}
