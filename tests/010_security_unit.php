<?php
declare(strict_types=1);
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/integrations.php';
$CONFIG=['app_key'=>'unit-tests-only','base_url'=>'https://www.example.test'];
$APP_INSTALLED=false;
date_default_timezone_set('Africa/Nairobi');
$checks=0;
function check(bool $condition,string $message): void { global $checks; $checks++; if(!$condition)throw new RuntimeException($message); }
function rejects(callable $call,string $message): void { try{$call();}catch(PaymentValidationException|InvalidArgumentException $e){check(true,$message);return;}check(false,$message); }

check(!csrf_matches(null,''),'Missing session and request token must fail.');
check(!csrf_matches('',''),'Two empty tokens must fail.');
check(!csrf_matches(str_repeat('a',64),[]),'Array CSRF token must fail.');
check(!csrf_matches(str_repeat('a',64),str_repeat('b',64)),'Wrong CSRF token must fail.');
check(csrf_matches(str_repeat('a',64),str_repeat('a',64)),'Valid CSRF token must pass.');
$_SESSION=[];check(strlen(csrf_token())===64,'Generated token must be full length.');

$payloads=[
    '<div><img src="https://example.test/a.jpg" onerror="alert(1)"><script>alert(2)</script><a href="javascript:alert(3)">link</a></div>',
    '<section><span><a href="java&#x09;script:alert(1)">nested</a></span></section>',
    '<div><svg><foreignObject><img src=x onerror=alert(1)></foreignObject></svg><math><mtext><script>bad</script></mtext></math></div>',
    '<div id="wk-root"><p onclick="alert(1)" style="background:url(javascript:bad)">Words</p></div>',
    '</div></body><img src="data:image/svg+xml,bad" onload="alert(1)"><iframe srcdoc="bad"></iframe>',
    '<a href="//evil.test">protocol-relative</a><a href="/\\evil.test">backslash</a><img src="/assets/uploads/../../app/config.php">',
    '<noscript><p title="</noscript><img src=x onerror=alert(1)>">x</p>',
];
foreach($payloads as $payload){
    $clean=sanitize_html($payload);
    check(!preg_match('/<(?:script|svg|math|iframe|object|embed)\b|\son[a-z]+\s*=|\sstyle\s*=|javascript:|srcdoc\s*=/i',$clean),'Nested unsafe HTML survived: '.$clean);
    check(sanitize_html($clean)===$clean,'Sanitizer output must be stable after reparsing.');
}
$clean=sanitize_html('<section><p>Keep <strong>bold</strong> and <a href="https://example.test/?x=1&amp;y=2" target="_blank">a link</a>.</p></section>');
check(str_contains($clean,'<strong>bold</strong>')&&str_contains($clean,'noopener noreferrer'),'Useful article formatting should survive.');
check(!safe_content_url('https://example.test@evil.test/image.jpg',true),'Credentials in URLs must be rejected.');
check(!safe_content_url("java\nscript:alert(1)"),'Control characters in URLs must be rejected.');

$xml=sitemap_xml([['loc'=>'https://example.test/a?x=1&y=2','lastmod'=>'2026-10-21T12:00:00+03:00']]);
$dom=new DOMDocument();check($dom->loadXML($xml,LIBXML_NONET),'Sitemap must parse as XML.');
check($dom->getElementsByTagName('loc')->item(0)->textContent==='https://example.test/a?x=1&y=2','Sitemap URL escaping should round trip.');
check(!str_contains($xml,'\\n'),'Sitemap declaration must not contain literal backslash-n.');

check(media_release_can_sign(['status'=>'sent','signed_at'=>null,'expires_at'=>null]),'Open sent consent can be signed.');
foreach(['closed','draft','signed','withdrawal_requested'] as $status)check(!media_release_can_sign(['status'=>$status]),'Non-open consent cannot be signed.');
check(!media_release_can_sign(['status'=>'sent','expires_at'=>'2020-01-01 00:00:00']),'Expired consent cannot be signed.');

check(payment_minor_units('10.10')===1010,'Money must use decimal minor units.');
check(payment_decimal('10')==='10.00','Money formatting should preserve cents.');
check(!payment_equal('10.01','10.001'),'A rounded or truncated amount must not match.');
foreach(['1e3','-5','NaN','1.001',' 10','0001',[]] as $bad)rejects(fn()=>payment_minor_units($bad),'Malformed money accepted.');

$pesapal=['method'=>'pesapal','_primary'=>'TRACK-1','reference'=>'CON-TEST','amount'=>'100.00','currency'=>'KES'];
$status=['status'=>'200','status_code'=>1,'payment_status_description'=>'Completed','merchant_reference'=>'CON-TEST','amount'=>100,'currency'=>'KES','confirmation_code'=>'RECEIPT-1'];
check(payment_evaluate_pesapal($pesapal,'TRACK-1',$status)['status']==='completed','Matching Pesapal evidence must settle.');
foreach(['amount'=>1,'currency'=>'USD','merchant_reference'=>'CON-OTHER','payment_status_description'=>'Incomplete','status_code'=>2] as $key=>$value){$bad=$status;$bad[$key]=$value;rejects(fn()=>payment_evaluate_pesapal($pesapal,'TRACK-1',$bad),'Pesapal '.$key.' mismatch accepted.');}
rejects(fn()=>payment_evaluate_pesapal($pesapal,'TRACK-OTHER',$status),'Foreign Pesapal tracking ID accepted.');
rejects(fn()=>payment_evaluate_pesapal($pesapal+['x'=>1],'TRACK-1',array_diff_key($status,['currency'=>true])),'Missing Pesapal currency accepted.');

$paypal=['method'=>'paypal','_primary'=>'ORDER1','reference'=>'CON-TEST','amount'=>'10.25','currency'=>'USD'];
$order=['id'=>'ORDER1','intent'=>'CAPTURE','status'=>'COMPLETED','purchase_units'=>[['reference_id'=>'CON-TEST','custom_id'=>'CON-TEST','amount'=>['value'=>'10.25','currency_code'=>'USD'],'payee'=>['merchant_id'=>'MERCHANT1'],'payments'=>['captures'=>[['id'=>'CAPTURE1','status'=>'COMPLETED','amount'=>['value'=>'10.25','currency_code'=>'USD']]]]]]];
check(payment_evaluate_paypal($paypal,'ORDER1',$order,'MERCHANT1')['status']==='completed','Matching PayPal capture should settle.');
$bad=$order;$bad['purchase_units'][0]['payments']['captures'][0]['amount']['value']='0.25';rejects(fn()=>payment_evaluate_paypal($paypal,'ORDER1',$bad,'MERCHANT1'),'Wrong captured amount accepted.');
$bad=$order;$bad['purchase_units'][0]['payments']['captures'][0]['status']='PENDING';rejects(fn()=>payment_evaluate_paypal($paypal,'ORDER1',$bad,'MERCHANT1'),'Pending capture accepted as completed.');
$bad=$order;$bad['purchase_units'][0]['custom_id']='CON-OTHER';rejects(fn()=>payment_evaluate_paypal($paypal,'ORDER1',$bad,'MERCHANT1'),'Wrong custom reference accepted.');
$bad=$order;$bad['purchase_units'][0]['payments']['captures'][0]['amount']['currency_code']='KES';rejects(fn()=>payment_evaluate_paypal($paypal,'ORDER1',$bad,'MERCHANT1'),'Wrong capture currency accepted.');
$bad=$order;unset($bad['purchase_units'][0]['payments']);rejects(fn()=>payment_evaluate_paypal($paypal,'ORDER1',$bad,'MERCHANT1'),'Completed order without capture accepted.');
rejects(fn()=>payment_evaluate_paypal($paypal,'ORDER1',$order),'Unknown receiving merchant accepted.');
rejects(fn()=>payment_evaluate_paypal($paypal,'ORDER1',$order,'OTHER'),'Wrong receiving merchant accepted.');
$bad=$order;$bad['status']='APPROVED';unset($bad['purchase_units'][0]['payments']);check(payment_evaluate_paypal($paypal,'ORDER1',$bad,'MERCHANT1')['status']==='pending','Approval must not mean payment completion.');

$mpesa=['method'=>'mpesa','_primary'=>'ws_CO_123','_merchant'=>'MERCHANT-1','amount'=>'100.00','currency'=>'KES'];
$query=['CheckoutRequestID'=>'ws_CO_123','MerchantRequestID'=>'MERCHANT-1','ResponseCode'=>'0','ResultCode'=>0];
$callback=['Body'=>['stkCallback'=>['CheckoutRequestID'=>'ws_CO_123','MerchantRequestID'=>'MERCHANT-1','ResultCode'=>0,'CallbackMetadata'=>['Item'=>[['Name'=>'Amount','Value'=>100],['Name'=>'MpesaReceiptNumber','Value'=>'TESTRECEIPT1'],['Name'=>'PhoneNumber','Value'=>254700000000]]]]]];
$decision=payment_evaluate_mpesa($mpesa,$query,$callback);
check($decision['status']==='completed'&&$decision['level']==='status_only','M-Pesa must distinguish authenticated status from unsigned receipt metadata.');
rejects(fn()=>payment_evaluate_mpesa($mpesa,$query,null),'Missing M-Pesa metadata should stay for review.');
$bad=$callback;$bad['Body']['stkCallback']['CallbackMetadata']['Item'][0]['Value']=1;rejects(fn()=>payment_evaluate_mpesa($mpesa,$query,$bad),'M-Pesa amount mismatch accepted.');
$bad=$query;$bad['CheckoutRequestID']='ws_CO_FOREIGN';rejects(fn()=>payment_evaluate_mpesa($mpesa,$bad,$callback),'Wrong M-Pesa checkout accepted.');
$bad=$query;$bad['MerchantRequestID']='OTHER';rejects(fn()=>payment_evaluate_mpesa($mpesa,$bad,$callback),'Wrong M-Pesa merchant accepted.');
check(!str_contains(payment_payload_for_storage($callback),'254700000000'),'Callback phone must be redacted in stored events.');
check(payment_next_status('completed','failed')==='completed','Late failures must not downgrade completed payments.');
check(payment_next_status('completed','cancelled')==='completed','Unverified cancellation must not downgrade completed payment.');
check(payment_next_status('completed','cancelled',true)==='cancelled','Verified reversal should be recorded.');
check(payment_next_status('failed','pending')==='failed','A pending replay must not reopen a failed payment.');
check(!payment_checkout_url('paypal','https://www.paypal.com.evil.test/checkout'),'Lookalike checkout hosts must be rejected.');
check(!payment_checkout_url('pesapal','javascript:alert(1)'),'Unsafe checkout URLs must be rejected.');
check(payment_checkout_url('paypal','https://www.sandbox.paypal.com/checkoutnow?token=ORDER1'),'Expected PayPal checkout should be accepted.');

$notice=['id'=>'WH-FIXTURE','event_type'=>'PAYMENT.CAPTURE.COMPLETED','resource'=>['id'=>'CAPTURE123','supplementary_data'=>['related_ids'=>['order_id'=>'ORDER123']]]];
check(paypal_notification_locator($notice)===['kind'=>'order','id'=>'ORDER123','review'=>false],'PayPal capture must locate its original order.');
check(paypal_notification_locator(['event_type'=>'BILLING.SUBSCRIPTION.ACTIVATED'])===[],'Unrelated PayPal products are ignored.');
check(paypal_notification_locator(array_replace($notice,['resource'=>['supplementary_data'=>['related_ids'=>['order_id'=>'../FOREIGN']]]]))===[],'Malformed PayPal order identifiers rejected.');
$refund=array_replace($notice,['event_type'=>'PAYMENT.CAPTURE.REFUNDED','resource'=>['supplementary_data'=>['related_ids'=>['capture_id'=>'CAPTURE123']]]]);
check(paypal_notification_locator($refund)===['kind'=>'capture','id'=>'CAPTURE123','review'=>true],'Refunds match known captures and require review.');
$headers=['HTTP_PAYPAL_AUTH_ALGO'=>'SHA256withRSA','HTTP_PAYPAL_CERT_URL'=>'https://api.paypal.com/v1/notifications/certs/CERT-fixture','HTTP_PAYPAL_TRANSMISSION_ID'=>'delivery-123','HTTP_PAYPAL_TRANSMISSION_SIG'=>'fixture','HTTP_PAYPAL_TRANSMISSION_TIME'=>'2026-10-25T12:00:00Z'];
check(paypal_notification_verification($headers,$notice,'123ABC','live')['webhook_event']===$notice,'PayPal verification binds exact event and configured webhook.');
rejects(fn()=>paypal_notification_verification([],$notice,'123ABC','live'),'Missing signature headers rejected.');
foreach(['https://api.paypal.com.evil.invalid/v1/notifications/certs/CERT-fixture','https://user@api.paypal.com/v1/notifications/certs/CERT-fixture','http://api.paypal.com/v1/notifications/certs/CERT-fixture','https://api.paypal.com/v1/notifications/certs/CERT-fixture?secret=1','https://api.sandbox.paypal.com/v1/notifications/certs/CERT-fixture']as$cert)rejects(fn()=>paypal_notification_verification(array_replace($headers,['HTTP_PAYPAL_CERT_URL'=>$cert]),$notice,'123ABC','live'),'Foreign or unsafe certificate URLs rejected.');
echo "PASS: PayPal notification correlation, refund review and signature input boundaries.\n";

echo "PASS: {$checks} security unit assertions\n";

$monthlyPlan=monthly_paypal_plan('PROD-12345678901234567','10.00');
check(monthly_paypal_plan_matches($monthlyPlan,'PROD-12345678901234567','10.00'),'Monthly plan fixes price currency and monthly frequency.');
$changed=$monthlyPlan;$changed['billing_cycles'][0]['frequency']['interval_unit']='DAY';check(!monthly_paypal_plan_matches($changed,'PROD-12345678901234567','10.00'),'Daily schedule cannot masquerade as monthly.');
$changed=$monthlyPlan;$changed['billing_cycles'][0]['pricing_scheme']['fixed_price']['value']='100.00';check(!monthly_paypal_plan_matches($changed,'PROD-12345678901234567','10.00'),'Changed monthly price rejected.');
$changed=$monthlyPlan;$changed['payment_preferences']['setup_fee']=['currency_code'=>'USD','value'=>'1.00'];check(!monthly_paypal_plan_matches($changed,'PROD-12345678901234567','10.00'),'Unexpected setup fee rejected.');
rejects(fn()=>monthly_paypal_plan('PROD-12345678901234567','1000.00'),'Unsupported monthly amount rejected.');
$mr=['subscription'=>'I-FIXTURE','plan'=>'P-FIXTURE','id'=>'MONTHLY-FIXTURE'];$ms=['id'=>'I-FIXTURE','plan_id'=>'P-FIXTURE','custom_id'=>'MONTHLY-FIXTURE','status'=>'ACTIVE'];
check(monthly_subscription_matches($mr,$ms),'Exact provider subscription and plan correlate.');
check(!monthly_subscription_matches($mr,array_replace($ms,['custom_id'=>'FOREIGN'])),'Another support reference cannot take over a subscription.');
check(!monthly_subscription_matches($mr,array_replace($ms,['plan_overridden'=>true])),'Overridden plans require review.');
check(!monthly_subscription_matches($mr,array_replace($ms,['quantity'=>'2'])),'Unexpected multiplier rejected.');
check(!monthly_subscription_matches($mr,array_replace($ms,['status'=>'COMPLETED'])),'An instalment status cannot be substituted for a subscription status.');
echo "PASS: fixed monthly PayPal pricing, no setup fee, subscription identity and no inferred payment settlement.\n";
