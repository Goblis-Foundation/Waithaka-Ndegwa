<?php
declare(strict_types=1);
/** Run only against a disposable MySQL/MariaDB database whose name ends _test.
 * WK_TEST_DB_NAME=waithaka_security_test WK_TEST_DB_USER=... php tests/010_security_database.php
 * No network provider calls, email or production configuration are loaded.
 */
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/integrations.php';
require __DIR__.'/../app/communication.php';
$database=getenv('WK_TEST_DB_NAME')?:'';
if(!preg_match('/^[a-zA-Z0-9_]+_test$/D',$database)){fwrite(STDERR,"Set WK_TEST_DB_NAME to a disposable database ending _test.\n");exit(2);}
$CONFIG=['app_key'=>'test-key-not-for-deployment','base_url'=>'https://www.example.test','db'=>[
    'host'=>getenv('WK_TEST_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('WK_TEST_DB_PORT')?:3306),'name'=>$database,
    'user'=>getenv('WK_TEST_DB_USER')?:'root','pass'=>getenv('WK_TEST_DB_PASS')?:'','charset'=>'utf8mb4'
]];
$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
$checks=0;$userId=0;$postIds=[];$paymentIds=[];
function check(bool $condition,string $message):void{global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
function conflict(callable $call,string $message):void{try{$call();}catch(WriterConflict $e){check(true,$message);return;}check(false,$message);}
function snapshot(int $id):array{$st=db()->prepare('SELECT * FROM posts WHERE id=?');$st->execute([$id]);return $st->fetch();}
function contribution(int $id):array{$st=db()->prepare('SELECT * FROM contributions WHERE id=?');$st->execute([$id]);return $st->fetch();}
function new_payment(string $method,string $amount,string $currency):array{
    global $paymentIds;
    $reference='TEST-'.strtoupper(bin2hex(random_bytes(8)));
    db()->prepare('INSERT INTO contributions(reference,method,amount,currency) VALUES(?,?,?,?)')->execute([$reference,$method,$amount,$currency]);
    $id=(int)db()->lastInsertId();$paymentIds[]=$id;payment_start_tracking($id,$method);return contribution($id);
}
foreach(['app/schema.sql','app/migrations/010_security.sql'] as $file){
    foreach(preg_split('/;\s*(?:\r?\n|$)/',trim(file_get_contents(__DIR__.'/../'.$file))) as $statement)if(trim($statement)!=='')db()->exec($statement);
}
try{
    check(db()->query('SELECT TIMEDIFF(NOW(),UTC_TIMESTAMP())')->fetchColumn()==='03:00:00','DB session must keep Nairobi DATETIME semantics.');
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Security test','security-'.bin2hex(random_bytes(8)).'@example.test','unused']);$userId=(int)db()->lastInsertId();
    $category=(int)db()->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $input=['id'=>0,'title'=>'Security regression '.bin2hex(random_bytes(4)),'content_html'=>'<p>Original article.</p>','category_id'=>$category,'published_at'=>date('Y-m-d\TH:i',time()-120),'seo_title'=>'Original SEO','seo_description'=>'Original description'];
    $draft=writer_save_post($input,$userId,false);$id=$draft['id'];$postIds[]=$id;
    check(writer_post_by_id($id)['content_html']==='<p>Original article.</p>','New private draft should survive reload.');
    $slug=snapshot($id)['slug'];check(post_by_slug($slug)===null,'Unpublished draft must not be public.');
    $input['id']=$id;$input['draft_version']=$draft['version'];$published=writer_save_post($input,$userId,true);
    $public=post_by_slug($slug);check($public!==null&&$public['content_html']==='<p>Original article.</p>','Explicit publish must expose saved version.');
    $before=snapshot($id);$tags=post_tags($id);
    $input['draft_version']=$published['version'];$input['title']='Private renamed thought';$input['content_html']='<section><p onclick="alert(1)">Private new text.</p></section>';$input['seo_title']='Private SEO';$input['is_selected']=1;$input['writer_note']='Private plan';$input['published_at']=date('Y-m-d\TH:i',time()+86400);
    $saved=writer_save_post($input,$userId,false);
    check(snapshot($id)===$before,'Draft save/autosave must leave every published field unchanged.');
    check(post_tags($id)===$tags,'Private edits must not mutate public tags.');
    check(post_by_slug($slug)['title']===$before['title'],'Public title must remain old until publish.');
    $working=writer_post_by_id($id);check($working['_has_draft']&&$working['title']==='Private renamed thought'&&$working['content_html']==='<p>Private new text.</p>','Authenticated editor must read the sanitized working copy.');
    conflict(fn()=>writer_save_post($input,$userId,false),'Stale concurrent autosave must be rejected.');
    $missing=$input;unset($missing['draft_version']);conflict(fn()=>writer_save_post($missing,$userId,false),'Existing article without a version must be rejected.');
    $input['draft_version']=$saved['version'];$replacement=writer_save_post($input,$userId,true);
    $after=snapshot($id);check($after['slug']===$slug,'Publishing a new title must preserve the public URL.');
    check($after['published_at']===$before['published_at']&&$after['status']==='published','Updating a live article must preserve its publication date and public availability.');
    check(post_by_slug($slug)['title']==='Private renamed thought','Explicit publish must replace the public snapshot.');
    check(!writer_post_by_id($id)['_has_draft'],'Published working copy should be cleared.');
    conflict(fn()=>writer_save_post($input,$userId,false),'Delayed pre-publish autosave must not recreate the draft or change public content.');
    check(snapshot($id)===$after,'Rejected stale autosave must be atomic.');

    $scheduledInput=['id'=>0,'title'=>'Scheduled '.bin2hex(random_bytes(4)),'content_html'=>'<p>Scheduled snapshot.</p>','category_id'=>$category,'published_at'=>date('Y-m-d\TH:i',time()+3600)];
    $scheduled=writer_save_post($scheduledInput,$userId,true);$scheduledId=$scheduled['id'];$postIds[]=$scheduledId;
    $scheduledBefore=snapshot($scheduledId);check(post_by_slug($scheduledBefore['slug'])===null,'Future snapshot must remain private until due.');
    $scheduledInput['id']=$scheduledId;$scheduledInput['draft_version']=$scheduled['version'];$scheduledInput['content_html']='<p>Unapproved scheduled edit.</p>';
    writer_save_post($scheduledInput,$userId,false);check(snapshot($scheduledId)===$scheduledBefore,'Private edits must not replace a scheduled snapshot.');
    db()->prepare('UPDATE posts SET published_at=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$scheduledId]);
    check(post_by_slug($scheduledBefore['slug'])['content_html']==='<p>Scheduled snapshot.</p>','A due schedule must publish its approved snapshot, never a later private draft.');

    $pesapal=new_payment('pesapal','100.00','KES');$track='TRACK-'.bin2hex(random_bytes(5));
    payment_bind_provider((int)$pesapal['id'],'pesapal',$track,[],['order_tracking_id'=>$track]);$pesapal=contribution((int)$pesapal['id']);$pesapal['_primary']=$track;
    check(payment_find('pesapal',$track,'CON-WRONG')===null,'Browser merchant reference must not override the stored contribution.');
    check(payment_find('paypal',$track)===null,'Provider identifiers must be method-scoped.');
    $evidence=['status'=>'200','status_code'=>1,'payment_status_description'=>'Completed','merchant_reference'=>$pesapal['reference'],'amount'=>100,'currency'=>'KES','confirmation_code'=>'CONFIRM-'.bin2hex(random_bytes(5))];
    $decision=payment_evaluate_pesapal($pesapal,$track,$evidence);
    payment_apply_verified($pesapal,$decision,$evidence,'test_query');payment_apply_verified($pesapal,$decision,$evidence,'test_query');
    $settled=contribution((int)$pesapal['id']);check($settled['status']==='completed'&&$settled['provider_reference']===$track,'Settlement must retain original tracking ID.');
    $st=db()->prepare("SELECT COUNT(*) FROM payment_events WHERE contribution_id=? AND event_source='test_query'");$st->execute([$pesapal['id']]);check((int)$st->fetchColumn()===1,'Replayed provider event must be idempotent.');
    payment_apply_verified($pesapal,['status'=>'failed','level'=>'amount_currency_status','ids'=>[],'detail'=>'Late failure'],['late'=>true],'test_query');
    check(contribution((int)$pesapal['id'])['status']==='completed','Out-of-order failure must not downgrade completion.');
    $reversal=$evidence;$reversal['status_code']=3;$reversal['payment_status_description']='Reversed';
    payment_apply_verified($pesapal,payment_evaluate_pesapal($pesapal,$track,$reversal),$reversal,'test_query');
    check(contribution((int)$pesapal['id'])['status']==='cancelled','Provider-confirmed reversal must be recorded.');
    payment_apply_verified($pesapal,$decision,$evidence,'test_query');check(contribution((int)$pesapal['id'])['status']==='cancelled','Older completed replay must not undo a verified reversal.');

    $paypal1=new_payment('paypal','10.00','USD');$paypal2=new_payment('paypal','10.00','USD');$captureId='CAP'.strtoupper(bin2hex(random_bytes(5)));
    foreach([$paypal1,$paypal2] as $position=>$paypal){
        $orderId='ORDER'.strtoupper(bin2hex(random_bytes(5)));
        $order=['id'=>$orderId,'intent'=>'CAPTURE','status'=>'COMPLETED','purchase_units'=>[['reference_id'=>$paypal['reference'],'custom_id'=>$paypal['reference'],'payee'=>['merchant_id'=>'FIXTUREMERCHANT'],'amount'=>['value'=>'10.00','currency_code'=>'USD'],'payments'=>['captures'=>[['id'=>$captureId,'status'=>'COMPLETED','amount'=>['value'=>'10.00','currency_code'=>'USD']]]]]]];
        payment_bind_provider((int)$paypal['id'],'paypal',$orderId,[],$order);$paypal=contribution((int)$paypal['id']);$paypal['_primary']=$orderId;
        $decision=payment_evaluate_paypal($paypal,$orderId,$order,payment_paypal_merchant($paypal));
        if($position===0){payment_apply_verified($paypal,$decision,$order,'test_query');check(contribution((int)$paypal['id'])['status']==='completed','Matching PayPal capture should settle against the original merchant.');}
        else{
            $rejected=false;try{payment_apply_verified($paypal,$decision,$order,'test_query');}catch(PaymentValidationException $e){$rejected=true;}
            check($rejected&&contribution((int)$paypal['id'])['status']==='pending','A capture reused across contributions must roll back settlement.');
        }
    }

    $mpesa=new_payment('mpesa','100.00','KES');$checkout='ws_CO_'.bin2hex(random_bytes(6));$merchant='MR-'.bin2hex(random_bytes(5));
    payment_bind_provider((int)$mpesa['id'],'mpesa',$checkout,['merchant'=>$merchant],['CheckoutRequestID'=>$checkout,'MerchantRequestID'=>$merchant]);
    $receipt='TEST'.strtoupper(bin2hex(random_bytes(5)));
    $callback=['Body'=>['stkCallback'=>['CheckoutRequestID'=>$checkout,'MerchantRequestID'=>$merchant,'ResultCode'=>0,'CallbackMetadata'=>['Item'=>[['Name'=>'Amount','Value'=>100],['Name'=>'MpesaReceiptNumber','Value'=>$receipt]]]]]];
    payment_record_mpesa_callback($callback);payment_record_mpesa_callback($callback);
    check(contribution((int)$mpesa['id'])['status']==='pending','Unsigned M-Pesa success callback alone must never settle a payment.');
    $mpesa=contribution((int)$mpesa['id']);$mpesa['_primary']=$checkout;$mpesa['_merchant']=$merchant;
    $query=['ResponseCode'=>'0','ResultCode'=>0,'CheckoutRequestID'=>$checkout,'MerchantRequestID'=>$merchant];
    payment_apply_verified($mpesa,payment_evaluate_mpesa($mpesa,$query,$callback),$query,'test_query');
    check(contribution((int)$mpesa['id'])['provider_reference']===$checkout,'M-Pesa receipt must not replace checkout correlation.');
    $st=db()->prepare('SELECT verification_level FROM payment_reconciliation WHERE contribution_id=?');$st->execute([$mpesa['id']]);check($st->fetchColumn()==='status_only','M-Pesa evidence limitation must be persisted.');

    $legacy=new_payment('mpesa','100.00','KES');$legacyCheckout='ws_CO_'.bin2hex(random_bytes(6));$legacyCallback=$callback;$legacyCallback['Body']['stkCallback']['CheckoutRequestID']=$legacyCheckout;
    db()->prepare('UPDATE contributions SET provider_reference=?,provider_payload=? WHERE id=?')->execute(['LEGACYRECEIPT',payment_payload_for_storage($legacyCallback),$legacy['id']]);
    $found=payment_find('mpesa',$legacyCheckout);check($found&&(int)$found['id']===(int)$legacy['id'],'Legacy checkout must be recoverable from preserved payload.');
    check(contribution((int)$legacy['id'])['provider_reference']==='LEGACYRECEIPT','Legacy receipt reference must not be rewritten.');

    $race=new_payment('mpesa','100.00','KES');$raceCheckout='ws_CO_'.bin2hex(random_bytes(6));$raceMerchant='MR-'.bin2hex(random_bytes(5));
    $raceCallback=$callback;$raceCallback['Body']['stkCallback']['CheckoutRequestID']=$raceCheckout;$raceCallback['Body']['stkCallback']['MerchantRequestID']=$raceMerchant;
    $recorded=payment_record_mpesa_callback($raceCallback,$race['reference'],payment_return_state($race['reference'],'mpesa'));
    check($recorded&&(int)$recorded['id']===(int)$race['id'],'Callback before initiation readback must be correlated without settlement.');
    payment_needs_review($race,'Checkout identifier not bound yet.');
    payment_bind_provider((int)$race['id'],'mpesa',$raceCheckout,['merchant'=>$raceMerchant],['CheckoutRequestID'=>$raceCheckout,'MerchantRequestID'=>$raceMerchant]);
    $st=db()->prepare('SELECT state,callback_payload,next_attempt_at<=NOW() due FROM payment_reconciliation WHERE contribution_id=?');$st->execute([$race['id']]);$reconciliation=$st->fetch();
    check($reconciliation['state']==='pending'&&!empty($reconciliation['callback_payload'])&&(int)$reconciliation['due']===1,'Binding after an early callback must preserve evidence and requeue verification.');

    $noticePay=new_payment('paypal','10.00','USD');$noticeOrder='NOTICE'.strtoupper(bin2hex(random_bytes(5)));
    payment_bind_provider((int)$noticePay['id'],'paypal',$noticeOrder,[],['id'=>$noticeOrder]);
    $notice=['id'=>'WH-FIXTURE-'.bin2hex(random_bytes(5)),'event_type'=>'PAYMENT.CAPTURE.COMPLETED','resource'=>['supplementary_data'=>['related_ids'=>['order_id'=>$noticeOrder]]]];
    check(paypal_notifications_record($notice),'Known verified notification is recorded.');
    check(paypal_notifications_record($notice),'Repeated verified notification is safe.');
    $q=db()->prepare("SELECT COUNT(*) FROM payment_events WHERE contribution_id=? AND event_source='paypal_webhook'");$q->execute([$noticePay['id']]);check((int)$q->fetchColumn()===1,'Duplicate notification creates one audit record.');
    check(contribution((int)$noticePay['id'])['status']==='pending','Notification never settles without authenticated provider readback.');
    $unknown=$notice;$unknown['resource']['supplementary_data']['related_ids']['order_id']='UNKNOWNORDER';check(!paypal_notifications_record($unknown),'Unknown orders never create contributions.');
    $notice['event_type']='PAYMENT.CAPTURE.REFUNDED';paypal_notifications_record($notice);
    $q=db()->prepare('SELECT state FROM payment_reconciliation WHERE contribution_id=?');$q->execute([$noticePay['id']]);check($q->fetchColumn()==='review','Refund event is held for authoritative review.');
    check(contribution((int)$noticePay['id'])['status']==='pending','Refund hint cannot invent a financial reversal.');
    db()->prepare("DELETE FROM settings WHERE setting_key='communication.paypal.notification.last'")->execute();

    echo "PASS: {$checks} database security assertions\n";
}finally{
    foreach($postIds as $id)db()->prepare('DELETE FROM posts WHERE id=?')->execute([$id]);
    foreach($paymentIds as $id)db()->prepare('DELETE FROM contributions WHERE id=?')->execute([$id]);
    if($userId)db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
}
