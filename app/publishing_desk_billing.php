<?php
declare(strict_types=1);
/** Commercial orders are deliberately independent of contributions and monthly giving. */
function desk_billing_fingerprint(string $provider): string {
    if($provider==='paypal')return paypal_connection_fingerprint();
    if($provider==='pesapal')return hash('sha256',setting('pesapal.environment','sandbox').'|'.setting('pesapal.consumer_key','').'|'.setting('pesapal.consumer_secret',''));
    if($provider==='bank')return hash('sha256',desk_config()['seller'].'|'.desk_config()['bank_reference']);
    throw new InvalidArgumentException('Unknown payment provider.');
}
function desk_billing_ready(string $provider,array $quote): bool {
    $c=desk_config();if(empty($c['billing_enabled'])||!in_array($provider,$c['providers'],true)||$c['seller']===''||$c['billing_email']==='')return false;
    if($provider==='bank')return $c['bank_instructions']!==''&&$c['bank_reference']!=='';
    if($provider==='paypal')return setting('paypal.environment','sandbox')==='live'&&$c['paypal_merchant']!==''&&trim((string)setting('paypal.client_id',''))!==''&&trim((string)setting('paypal.secret',''))!==''&&in_array($quote['currency'],['USD','EUR','GBP'],true);
    return $provider==='pesapal'&&setting('pesapal.environment','sandbox')==='live'&&!empty($c['pesapal_ipn'])&&hash_equals($c['pesapal_fingerprint']??'',desk_billing_fingerprint('pesapal'));
}
function desk_billing_save(array $in,int $uid): void {
    comm_lock('desk.catalog',function()use($in,$uid){
        $c=desk_config();if((int)($in['config_version']??0)!==(int)($c['version']??0))throw new InvalidArgumentException('Settings changed. Reload before saving.');
        foreach(['seller'=>180,'billing_email'=>254,'invoice_address'=>1000,'tax_note'=>1000,'bank_instructions'=>3000,'bank_reference'=>180,'paypal_merchant'=>80]as$key=>$limit)$c[$key]=desk_text($in[$key]??'',$key,$limit);
        if($c['billing_email']!==''&&!filter_var($c['billing_email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid commercial billing email.');
        if($c['paypal_merchant']!==''&&!preg_match('/^[A-Z0-9]{5,80}$/D',$c['paypal_merchant']))throw new InvalidArgumentException('Enter the actual PayPal merchant ID, not an email or app ID.');
        $c['providers']=array_values(array_intersect(['pesapal','paypal','bank'],is_array($in['providers']??null)?$in['providers']:[]));
        $cap=filter_var($in['daily_cap']??1,FILTER_VALIDATE_INT);if($cap===false||$cap<1||$cap>5)throw new InvalidArgumentException('Choose a sponsored daily capacity of 1–5 per channel.');$c['daily_cap']=$cap;
        $c['billing_enabled']=($in['billing_enabled']??'')==='1';
        if($c['billing_enabled']&&(($in['commercial_ack']??'')!=='1'||$c['seller']===''||$c['billing_email']===''||$c['invoice_address']===''||!$c['providers']))throw new InvalidArgumentException('Confirm the commercial supplier, invoice details and authorised receiving accounts before enabling checkout.');
        $c['version']=($c['version']??0)+1;$c['updated_by']=$uid;$c['updated_at']=time();comm_put('desk.config',$c);
    });
}
function desk_register_pesapal(): void {
    if(setting('pesapal.environment','sandbox')!=='live')throw new InvalidArgumentException('Connect the intended live Pesapal merchant first.');
    $fp=desk_billing_fingerprint('pesapal');$r=http_json('POST',pesapal_base().'/api/URLSetup/RegisterIPN',['Authorization: Bearer '.pesapal_token()],['url'=>url('/payments/commercial/pesapal/ipn'),'ipn_notification_type'=>'GET']);
    if(!preg_match('/^[a-f0-9-]{36}$/i',(string)($r['ipn_id']??''))||($r['url']??'')!==url('/payments/commercial/pesapal/ipn'))throw new RuntimeException('Pesapal did not confirm the commercial callback.');
    comm_lock('desk.catalog',function()use($fp,$r){if(!hash_equals($fp,desk_billing_fingerprint('pesapal')))throw new InvalidArgumentException('The merchant connection changed.');$c=desk_config();$c['pesapal_ipn']=$r['ipn_id'];$c['pesapal_fingerprint']=$fp;$c['version']=($c['version']??0)+1;comm_put('desk.config',$c);});
}
function desk_payment_context(array $o): void {
    $p=$o['payment']??[];
    if(empty($p['provider'])||!hash_equals((string)($p['fingerprint']??''),desk_billing_fingerprint($p['provider'])))throw new InvalidArgumentException('This order belongs to a different payment connection. Reconcile it in the original merchant account.');
}
function desk_checkout(string $id,string $provider,string $email): string {
    return comm_lock('desk.catalog',function()use($id,$provider,$email){
        $o=desk_order($id);$r=desk_get($o['record']);
        if(!hash_equals($o['client_email'],$email)||!hash_equals($r['client']['email']??'',$email))throw new InvalidArgumentException('Client access denied.');
        if(!in_array($o['state'],['accepted','checkout_pending','payment_unconfirmed'],true)||$o['accepted_hash']!==$o['quote']['hash']||!empty($o['paused']))throw new InvalidArgumentException('Accept a current quote before payment.');
        if(!desk_billing_ready($provider,$o['quote']))throw new InvalidArgumentException('That commercial payment method is not enabled for this currency.');
        if(!empty($o['payment'])){
            desk_payment_context($o);if($o['payment']['provider']!==$provider)throw new InvalidArgumentException('An earlier checkout must be reconciled before changing providers.');
            if(!empty($o['payment']['url']))return $o['payment']['url'];
            throw new InvalidArgumentException('The earlier checkout is unconfirmed. Ask for reconciliation rather than creating another payment.');
        }
        if($o['hold_until']<=time()||$o['times'][0]<=time()+3600||desk_closed($r,end($o['times'])))throw new InvalidArgumentException('The reserved quote expired. Request a new schedule before paying.');
        desk_capacity($o['times'],$o['quote']['channels'],$o['id']);$cfg=desk_config();$fp=desk_billing_fingerprint($provider);
        $o['merchant']=$provider==='paypal'?$cfg['paypal_merchant']:$fp;if($provider==='bank')$o['bank_instructions']=$cfg['bank_instructions'];
        $o['payment']=['provider'=>$provider,'fingerprint'=>$fp,'started'=>time(),'remote'=>'','url'=>''];$o['state']='checkout_pending';comm_put('desk.order.'.$id,$o);
        if($provider==='bank')return url('/client/workspace/'.$o['record']);
        try{
            if($provider==='paypal'){
                $return=url('/payments/commercial/return?order='.$id);
                $remote=http_json('POST',paypal_base().'/v2/checkout/orders',['Authorization: Bearer '.paypal_token(),'PayPal-Request-Id: wncommercial-'.$id,'Prefer: return=representation'],['intent'=>'CAPTURE','purchase_units'=>[['reference_id'=>$id,'custom_id'=>$id,'invoice_id'=>'WN-'.$id,'description'=>'Sponsored publishing: '.$o['quote']['name'],'payee'=>['merchant_id'=>$o['merchant']],'amount'=>['currency_code'=>$o['quote']['currency'],'value'=>desk_decimal($o['quote']['minor'])]]],'application_context'=>['brand_name'=>'Waithaka Ndegwa','shipping_preference'=>'NO_SHIPPING','user_action'=>'PAY_NOW','return_url'=>$return,'cancel_url'=>$return]],25);
                if(!preg_match('/^[A-Z0-9]{1,36}$/D',(string)($remote['id']??''))||($remote['purchase_units'][0]['payee']['merchant_id']??'')!==$o['merchant'])throw new RuntimeException('Merchant identity was not confirmed.');
                $o['payment']['remote']=$remote['id'];$o['payment']['url']=paypal_approval_url($remote)??'';
            }else{
                $remote=http_json('POST',pesapal_base().'/api/Transactions/SubmitOrderRequest',['Authorization: Bearer '.pesapal_token()],['id'=>$id,'currency'=>$o['quote']['currency'],'amount'=>(float)desk_decimal($o['quote']['minor']),'description'=>'Sponsored publishing: '.$o['quote']['name'],'callback_url'=>url('/payments/commercial/return?order='.$id),'notification_id'=>$cfg['pesapal_ipn'],'billing_address'=>['email_address'=>$email,'first_name'=>$r['client']['name']]],25);
                if(($remote['merchant_reference']??'')!==$id||!preg_match('/^[a-f0-9-]{36}$/i',(string)($remote['order_tracking_id']??'')))throw new RuntimeException('The commercial order was not confirmed.');
                $o['payment']['remote']=$remote['order_tracking_id'];$o['payment']['url']=(string)($remote['redirect_url']??'');
            }
            if(!payment_checkout_url($provider,$o['payment']['url']))throw new RuntimeException('The provider returned no trusted checkout address.');
            comm_put('desk.order.'.$id,$o);return $o['payment']['url'];
        }catch(Throwable $e){$o['state']='payment_unconfirmed';comm_put('desk.order.'.$id,$o);error_log('Commercial checkout unconfirmed: '.get_class($e));throw new RuntimeException('Checkout creation is unconfirmed. Your order is saved; reconcile it before trying another payment.');}
    });
}
function desk_provider_minor(mixed $value): int {
    if(is_float($value)){$rounded=round($value*100);if(!is_finite($value)||$value<0||abs($value*100-$rounded)>0.00001||$rounded>99999999999)throw new InvalidArgumentException('Invalid provider amount.');return (int)$rounded;}
    return desk_money($value)??0;
}
function desk_pesapal_proof(array $o,array $d): array {
    return ['status'=>(string)($d['status_code']??'')==='1'?'completed':((string)($d['status_code']??'')==='3'?'reversed':'pending'),'reference'=>(string)($d['merchant_reference']??''),'currency'=>(string)($d['currency']??''),'minor'=>desk_provider_minor($d['amount']??'0'),'transaction'=>(string)($d['confirmation_code']??''),'merchant'=>$o['payment']['fingerprint']];
}
function desk_paypal_proof(array $o,array $d): array {
    if(($d['id']??'')!==($o['payment']['remote']??'')||count($d['purchase_units']??[])!==1)throw new InvalidArgumentException('PayPal order identity differs.');
    $u=$d['purchase_units'][0];$captures=$u['payments']['captures']??[];
    if(($u['reference_id']??'')!==$o['id']||($u['custom_id']??'')!==$o['id']||($u['payee']['merchant_id']??'')!==$o['merchant']||($u['amount']['currency_code']??'')!==$o['quote']['currency']||desk_provider_minor($u['amount']['value']??'0')!==$o['quote']['minor'])throw new InvalidArgumentException('PayPal order does not match the accepted invoice.');
    if(count($captures)>1)throw new InvalidArgumentException('Multiple captures need manual reconciliation.');$c=$captures[0]??[];
    $state=$c['status']??'';$proof=['status'=>$state==='COMPLETED'&&($d['status']??'')==='COMPLETED'?'completed':(in_array($state,['REFUNDED','PARTIALLY_REFUNDED','REVERSED'],true)?'reversed':'pending'),'reference'=>$o['id'],'merchant'=>$u['payee']['merchant_id'],'currency'=>$c['amount']['currency_code']??$o['quote']['currency'],'minor'=>desk_provider_minor($c['amount']['value']??'0'),'transaction'=>(string)($c['id']??'')];return $proof;
}
function desk_settle(array $o,array $proof): array {
    $o['checked']=time();$o['next_check']=time()+1800;
    if($proof['status']==='reversed'){$o['state']='payment_review';$o['paused']=true;$o['payment_notice']='Provider reports a refund or reversal. Reconcile the earned and unearned portions.';}
    elseif(desk_payment_matches($o,$proof)){
        $key='desk.transaction.'.hash('sha256',$o['payment']['provider'].'|'.$o['payment']['fingerprint'].'|'.$proof['transaction']);$prior=comm_get($key);
        if($prior&&$prior['order']!==$o['id'])throw new InvalidArgumentException('This transaction is already allocated to another invoice.');
        comm_put($key,['order'=>$o['id']]);$o['state']='paid';$o['paid_at']=$o['paid_at']??time();$o['proof']=$proof;
        if($o['times'][0]<time()||!empty($o['cancellation_requested'])){$o['paused']=true;$o['payment_notice']='Payment confirmed, but the campaign needs a reviewed schedule or cancellation settlement.';}
        if(empty($o['receipt_queued'])){$o['receipt_queued']=true;$receipt=enqueue_email($o['client_email'],'Payment receipt · WN-'.strtoupper(substr($o['id'],0,12)),email_design('Commercial payment received','<p>Received '.e($o['quote']['currency'].' '.desk_decimal($o['quote']['minor'])).' for '.e($o['quote']['name']).'. This is a publishing service, not a contribution.</p><p>Publication remains subject to client and editorial approval. Your invoice and delivery records are in your private workspace.</p>'), 'desk.receipt.'.$o['id']);desk_register_mail((int)$receipt,$o['record'],$o['client_email'],time()+14*86400);}
    }elseif($proof['status']==='completed'){$o['state']='payment_review';$o['paused']=true;$o['payment_notice']='Provider amount, currency or reference did not match the invoice.';}
    comm_put('desk.order.'.$o['id'],$o);return $o;
}
function desk_reconcile(string $id,bool $capture=false): array {
    return comm_lock('desk.catalog',function()use($id,$capture){$o=desk_order($id);desk_payment_context($o);$p=$o['payment'];
        if(empty($p['remote'])||$p['provider']==='bank')throw new InvalidArgumentException('This payment needs merchant-side reconciliation.');
        if($p['provider']==='pesapal')$proof=desk_pesapal_proof($o,pesapal_status($p['remote']));
        else{$d=paypal_get_order($p['remote']);$proof=desk_paypal_proof($o,$d);
            if($capture&&($d['status']??'')==='APPROVED'){
                if($o['state']==='paid'||!empty($o['paused'])||($o['accepted_hash']??'')!==$o['quote']['hash']||$o['hold_until']<=time())throw new InvalidArgumentException('This quote is no longer open for capture.');
                try{$d=paypal_capture_order($p['remote']);}catch(Throwable){$d=paypal_get_order($p['remote']);}
                $proof=desk_paypal_proof($o,$d);
            }
            if(($proof['status']??'')==='completed'&&preg_match('/^[A-Z0-9]{1,36}$/D',$proof['transaction'])){
                $c=http_json('GET',paypal_base().'/v2/payments/captures/'.$proof['transaction'],['Authorization: Bearer '.paypal_token()]);
                if(in_array($c['status']??'',['REFUNDED','PARTIALLY_REFUNDED','REVERSED'],true))$proof['status']='reversed';
                elseif(($c['status']??'')!=='COMPLETED')$proof['status']='pending';
            }
        }
        return desk_settle($o,$proof);
    });
}
function desk_bank_verify(string $id,array $in,int $uid): void {
    comm_lock('desk.catalog',function()use($id,$in,$uid){$o=desk_order($id);
        if(($o['payment']['provider']??'')!=='bank'||($in['bank_ack']??'')!=='1'||$o['accepted_hash']!==$o['quote']['hash'])throw new InvalidArgumentException('Accept the bank-transfer order and confirm the actual account statement first.');
        desk_payment_context($o);$proof=['status'=>'completed','reference'=>$o['id'],'currency'=>strtoupper(desk_text($in['currency']??'','Currency',3,true)),'minor'=>desk_money($in['amount']??''),'transaction'=>desk_text($in['transaction']??'','Bank transaction reference',180,true),'merchant'=>$o['merchant'],'verified_by'=>$uid,'verified_at'=>time()];
        if(!desk_payment_matches($o,$proof))throw new InvalidArgumentException('Bank amount, currency and invoice must match exactly. Screenshots alone are not verification.');desk_settle($o,$proof);
    });
}
function desk_order_adjust(string $id,array $in,int $uid): void {
    comm_lock('desk.catalog',function()use($id,$in,$uid){$o=desk_order($id);$action=$in['action']??'';
        if($action==='pause'){$o['paused']=true;$o['pause_reason']=desk_text($in['note']??'','Reason',1000,true);}
        elseif($action==='resume'){
            if($o['state']!=='paid'||!empty($o['cancellation_requested']))throw new InvalidArgumentException('Resolve the payment or cancellation before resuming.');$o['paused']=false;
        }elseif($action==='settlement'){
            $amount=desk_money($in['amount']??'');if(!$amount||$amount>$o['quote']['minor'])throw new InvalidArgumentException('Enter a settlement no larger than the invoice.');
            $o['paused']=true;$o['settlement']=['type'=>desk_choice($in['settlement_type']??'',['credit'=>1,'refund_record'=>1],'settlement type'),'minor'=>$amount,'reference'=>desk_text($in['transaction']??'','Completed refund or credit reference',180,true),'note'=>desk_text($in['note']??'','Settlement note',2000,true),'by'=>$uid,'at'=>time()];
            // This records a verified external settlement. It does not call a refund API.
        }else throw new InvalidArgumentException('Unsupported order action.');
        $o['audit'][]=['at'=>time(),'by'=>$uid,'action'=>$action];comm_put('desk.order.'.$id,$o);desk_delivery_guard();
    });
}
function desk_reconcile_due(): void {
    $n=0;foreach(comm_records('desk.order',3000)as$o){if($n>=5)break;
        if(empty($o['payment']['remote'])||($o['payment']['provider']??'')==='bank'||($o['next_check']??0)>time()||in_array($o['state'],['cancelled','payment_review'],true))continue;
        if($o['created']<time()-180*86400)continue;$n++;
        try{desk_reconcile($o['id']);}catch(Throwable){comm_lock('desk.catalog',function()use($o){$current=desk_order($o['id']);$current['next_check']=time()+3600;comm_put('desk.order.'.$o['id'],$current);});}
    }
}

function desk_recover_payment(string $id,array $in,int $uid): void {
    comm_lock('desk.catalog',function()use($id,$in,$uid){$o=desk_order($id);desk_payment_context($o);if(!empty($o['payment']['remote'])||$o['state']!=='payment_unconfirmed')throw new InvalidArgumentException('Only an unconfirmed checkout without a remote ID needs recovery.');
        $remote=desk_text($in['provider_order']??'','Actual merchant order ID',80,true);$provider=$o['payment']['provider'];$o['payment']['remote']=$remote;
        if($provider==='paypal'){$d=paypal_get_order($remote);$proof=desk_paypal_proof($o,$d);$o['payment']['url']=paypal_approval_url($d)??'';}
        elseif($provider==='pesapal'){$d=pesapal_status($remote);$proof=desk_pesapal_proof($o,$d);if($proof['reference']!==$id)throw new InvalidArgumentException('The provider order belongs to another invoice.');}
        else throw new InvalidArgumentException('Bank orders use statement verification.');
        $o['state']='checkout_pending';$o['recovered_by']=$uid;$o['recovered_at']=time();desk_settle($o,$proof);
    });
}
