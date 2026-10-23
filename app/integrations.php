<?php
declare(strict_types=1);
require_once __DIR__.'/payment_integrity.php';

function http_json(string $method,string $urlValue,array $headers=[],?array $body=null,int $timeout=20): array {
    if (parse_url($urlValue, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('Remote integrations require HTTPS.');
    $ch=curl_init($urlValue); $h=['Accept: application/json','Content-Type: application/json']; foreach($headers as $x)$h[]=$x;
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$h,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);
    if($body!==null){$h[]='Content-Type: application/json';curl_setopt($ch,CURLOPT_HTTPHEADER,$h);curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_SLASHES));}
    $raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
    if($raw===false||$err)throw new RuntimeException('The provider could not be reached. The result remains unconfirmed.');
    $data=json_decode((string)$raw,true);
    if($status<200||$status>=300)throw new RuntimeException('Remote service returned HTTP '.$status.'. The result remains unconfirmed.');
    if(!is_array($data))throw new RuntimeException('Remote service returned an invalid JSON response.');
    return $data;
}

function openai_text(string $instructions,string $input,int $maxTokens=700): ?string {
    $key=trim((string)setting('openai.api_key','')); if($key==='')return null;
    $model=trim((string)setting('openai.model','gpt-5.4-mini')) ?: 'gpt-5.4-mini';
    try{
        $r=http_json('POST','https://api.openai.com/v1/responses',['Authorization: Bearer '.$key],[
            'model'=>$model,'instructions'=>$instructions,'input'=>$input,'store'=>false,'max_output_tokens'=>$maxTokens
        ],30);
        if(!empty($r['output_text']))return trim((string)$r['output_text']);
        $out='';foreach(($r['output']??[]) as $item){foreach(($item['content']??[]) as $c){if(isset($c['text']))$out.=$c['text'];}}
        return trim($out)?:null;
    }catch(Throwable $e){error_log('OpenAI request failed: '.get_class($e));return null;}
}

function auto_editorial_metadata(string $title,string $standfirst,string $html,string $category): array {
    $text=mb_substr(plain_text($html),0,16000);
    $prompt="Title: $title\nCategory: $category\nStandfirst: $standfirst\nArticle:\n$text";
    $instructions='You are an editorial SEO assistant for Waithaka Ndegwa\'s personal long-form publication. Return ONLY valid JSON with keys: tags (array of 5-10 concise meaningful tags, reusing common canonical topic names), seo_title (natural, <= 65 characters where practical), seo_description (natural, compelling, about 140-160 characters). The publication is rooted in Kenya and East Africa but written for an international English-speaking audience, including readers in Europe, the United Kingdom, United States, Canada and the African diaspora. Use geographic terms only when genuinely relevant to the article. Do not keyword-stuff, manufacture regional relevance or invent claims not in the article.';
    $raw=openai_text($instructions,$prompt,700);
    if($raw){$raw=trim(preg_replace('/^```(?:json)?|```$/m','',$raw));$d=json_decode($raw,true);if(is_array($d)&&isset($d['tags']))return $d;}
    return ['tags'=>fallback_tags($title.' '.$standfirst.' '.$text,$category),'seo_title'=>$title,'seo_description'=>$standfirst!==''?mb_substr($standfirst,0,300):excerpt($html,155)];
}

function fallback_tags(string $text,string $category=''): array {
    $stop=array_flip(['about','after','again','against','also','among','and','are','because','been','before','being','between','both','but','can','could','does','doing','during','each','for','from','had','has','have','into','its','more','most','not','our','out','over','such','than','that','the','their','them','then','there','these','they','this','those','through','under','very','was','were','what','when','where','which','while','who','will','with','would','your','you','his','her','how','why']);
    preg_match_all('/\b[\p{L}][\p{L}\-]{3,}\b/u',mb_strtolower($text),$m);$freq=[];foreach($m[0]??[] as $w){if(isset($stop[$w]))continue;$freq[$w]=($freq[$w]??0)+1;}arsort($freq);$tags=[];if($category)$tags[]=$category;foreach(array_keys($freq) as $w){$tags[]=mb_convert_case($w,MB_CASE_TITLE,'UTF-8');if(count($tags)>=8)break;}return array_values(array_unique($tags));
}

function mpesa_base(): string { return setting('mpesa.environment','sandbox')==='live'?'https://api.safaricom.co.ke':'https://sandbox.safaricom.co.ke'; }
function mpesa_token(): string {
    $key=(string)setting('mpesa.consumer_key','');$secret=(string)setting('mpesa.consumer_secret','');if($key===''||$secret==='')throw new RuntimeException('M-Pesa is not configured yet.');
    $ch=curl_init(mpesa_base().'/oauth/v1/generate?grant_type=client_credentials');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode($key.':'.$secret)],CURLOPT_TIMEOUT=>15]);$raw=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$d=json_decode((string)$raw,true);if($status>=400||empty($d['access_token']))throw new RuntimeException('Unable to authenticate with M-Pesa.');return $d['access_token'];
}
function normalize_ke_phone(string $phone): string { $n=preg_replace('/\D+/','',$phone);if(str_starts_with($n,'0'))$n='254'.substr($n,1);elseif(str_starts_with($n,'7')||str_starts_with($n,'1'))$n='254'.$n;if(!preg_match('/^254[17]\d{8}$/',$n))throw new RuntimeException('Enter a valid Kenyan M-Pesa number.');return $n; }
function mpesa_stk_push(string $reference,string|float $amount,string $phone): array {
    $short=(string)setting('mpesa.shortcode','');$passkey=(string)setting('mpesa.passkey','');if($short===''||$passkey==='')throw new RuntimeException('M-Pesa shortcode/passkey is not configured.');
    $timestamp=date('YmdHis');$password=base64_encode($short.$passkey.$timestamp);$phone=normalize_ke_phone($phone);
    $minor=payment_minor_units($amount);if($minor<=0||$minor%100!==0)throw new RuntimeException('M-Pesa amounts must be whole Kenyan shillings.');
    return http_json('POST',mpesa_base().'/mpesa/stkpush/v1/processrequest',['Authorization: Bearer '.mpesa_token()],[
      'BusinessShortCode'=>$short,'Password'=>$password,'Timestamp'=>$timestamp,'TransactionType'=>(string)setting('mpesa.transaction_type','CustomerPayBillOnline'),'Amount'=>intdiv($minor,100),'PartyA'=>$phone,'PartyB'=>$short,'PhoneNumber'=>$phone,'CallBackURL'=>url('/payments/mpesa/callback?ref='.rawurlencode($reference).'&state='.payment_return_state($reference,'mpesa')),'AccountReference'=>substr(str_replace('-','',$reference),-12),'TransactionDesc'=>'Support My Thoughts'
    ]);
}

function mpesa_stk_query(string $checkoutId): array {
    if ($checkoutId === '' || strlen($checkoutId)>190 || !preg_match('/^[A-Za-z0-9_-]+$/D',$checkoutId)) throw new RuntimeException('Invalid M-Pesa checkout reference.');
    $short=(string)setting('mpesa.shortcode','');$passkey=(string)setting('mpesa.passkey','');
    if($short===''||$passkey==='')throw new RuntimeException('M-Pesa query is not configured.');
    $timestamp=date('YmdHis');
    return http_json('POST',mpesa_base().'/mpesa/stkpushquery/v1/query',['Authorization: Bearer '.mpesa_token()],[
        'BusinessShortCode'=>$short,'Password'=>base64_encode($short.$passkey.$timestamp),'Timestamp'=>$timestamp,'CheckoutRequestID'=>$checkoutId
    ]);
}

function pesapal_base(): string { return setting('pesapal.environment','sandbox')==='live'?'https://pay.pesapal.com/v3':'https://cybqa.pesapal.com/pesapalv3'; }
function pesapal_token(): string {
    $key=(string)setting('pesapal.consumer_key','');$secret=(string)setting('pesapal.consumer_secret','');if($key===''||$secret==='')throw new RuntimeException('Pesapal is not configured yet.');
    $r=http_json('POST',pesapal_base().'/api/Auth/RequestToken',[],['consumer_key'=>$key,'consumer_secret'=>$secret]);if(empty($r['token']))throw new RuntimeException('Unable to authenticate with Pesapal.');return $r['token'];
}
function pesapal_submit(string $reference,string|float $amount,string $currency,array $person): array {
    $ipn=(string)setting('pesapal.ipn_id','');if($ipn==='')throw new RuntimeException('Pesapal IPN ID is not configured. Register the IPN URL first in Writer → Settings.');
    return http_json('POST',pesapal_base().'/api/Transactions/SubmitOrderRequest',['Authorization: Bearer '.pesapal_token()],[
      'id'=>$reference,'currency'=>$currency,'amount'=>(float)payment_decimal($amount),'description'=>'Contribution to Waithaka Ndegwa writing','callback_url'=>url('/payments/pesapal/callback'),'notification_id'=>$ipn,'billing_address'=>[
        'email_address'=>$person['email'],'phone_number'=>$person['phone']??'','country_code'=>$person['country_code']??'KE','first_name'=>$person['first_name'],'middle_name'=>'','last_name'=>$person['last_name'],'line_1'=>'','line_2'=>'','city'=>'','state'=>'','postal_code'=>'','zip_code'=>''
      ]
    ]);
}
function pesapal_register_ipn(): array {
    return http_json('POST',pesapal_base().'/api/URLSetup/RegisterIPN',['Authorization: Bearer '.pesapal_token()],[
      'url'=>url('/payments/pesapal/ipn'),'ipn_notification_type'=>'GET'
    ]);
}
function pesapal_status(string $trackingId): array {
    if (!preg_match('/^[A-Za-z0-9-]{1,190}$/D',$trackingId)) throw new RuntimeException('Invalid Pesapal tracking reference.');
    return http_json('GET',pesapal_base().'/api/Transactions/GetTransactionStatus?orderTrackingId='.rawurlencode($trackingId),['Authorization: Bearer '.pesapal_token()]);
}


function paypal_base(): string {
    return setting('paypal.environment','sandbox') === 'live'
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

function paypal_token(): string {
    $clientId=(string)setting('paypal.client_id','');
    $secret=(string)setting('paypal.secret','');
    if($clientId===''||$secret==='') throw new RuntimeException('PayPal is not configured yet.');
    $ch=curl_init(paypal_base().'/v1/oauth2/token');
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>'grant_type=client_credentials',
        CURLOPT_HTTPHEADER=>['Accept: application/json','Accept-Language: en_US','Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_USERPWD=>$clientId.':'.$secret,
        CURLOPT_TIMEOUT=>20,
        CURLOPT_CONNECTTIMEOUT=>8,
    ]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    $err=curl_error($ch);
    curl_close($ch);
    if($raw===false||$err) throw new RuntimeException('Unable to connect to PayPal.');
    $d=json_decode((string)$raw,true);
    if($status>=400||!is_array($d)||empty($d['access_token'])) throw new RuntimeException('Unable to authenticate with PayPal.');
    return (string)$d['access_token'];
}

function paypal_create_order(string $reference,string|float $amount,string $currency='USD'): array {
    $amount=payment_decimal($amount);
    if(payment_minor_units($amount)<=0) throw new RuntimeException('Enter a valid PayPal contribution amount.');
    $payload=[
        'intent'=>'CAPTURE',
        'purchase_units'=>[[
            'reference_id'=>$reference,
            'custom_id'=>$reference,
            'description'=>'Voluntary contribution to Waithaka Ndegwa',
            'amount'=>['currency_code'=>$currency,'value'=>$amount]
        ]],
        'application_context'=>[
            'brand_name'=>'Waithaka Ndegwa',
            'landing_page'=>'LOGIN',
            'user_action'=>'PAY_NOW',
            'return_url'=>url('/payments/paypal/return?ref='.rawurlencode($reference).'&state='.payment_return_state($reference)),
            'cancel_url'=>url('/payments/paypal/cancel?ref='.rawurlencode($reference).'&state='.payment_return_state($reference))
        ]
    ];
    return http_json('POST',paypal_base().'/v2/checkout/orders',[
        'Authorization: Bearer '.paypal_token(),
        'PayPal-Request-Id: '.$reference,
        'Prefer: return=representation'
    ],$payload,25);
}

function paypal_approval_url(array $order): ?string {
    foreach(($order['links']??[]) as $link){
        if(in_array(($link['rel']??''),['payer-action','approve'],true) && !empty($link['href']) && payment_checkout_url('paypal',(string)$link['href'])) return (string)$link['href'];
    }
    return null;
}

function paypal_capture_order(string $orderId): array {
    if(!preg_match('/^[A-Z0-9]{1,36}$/D',$orderId)) throw new RuntimeException('Invalid PayPal order reference.');
    return http_json('POST',paypal_base().'/v2/checkout/orders/'.rawurlencode($orderId).'/capture',[
        'Authorization: Bearer '.paypal_token(),
        'PayPal-Request-Id: cap-'.substr(hash('sha256',$orderId),0,32),
        'Prefer: return=representation'
    ],null,25);
}

function paypal_get_order(string $orderId): array {
    if(!preg_match('/^[A-Z0-9]{1,36}$/D',$orderId)) throw new RuntimeException('Invalid PayPal order reference.');
    return http_json('GET',paypal_base().'/v2/checkout/orders/'.rawurlencode($orderId),['Authorization: Bearer '.paypal_token(),'Prefer: return=representation']);
}

function payment_checkout_url(string $provider, string $value): bool {
    if (!filter_var($value,FILTER_VALIDATE_URL) || parse_url($value,PHP_URL_SCHEME)!=='https' || parse_url($value,PHP_URL_USER)!==null || parse_url($value,PHP_URL_PASS)!==null) return false;
    $host = strtolower((string)parse_url($value,PHP_URL_HOST));
    $hosts = $provider === 'paypal' ? ['www.paypal.com','www.sandbox.paypal.com'] : ['pay.pesapal.com','cybqa.pesapal.com'];
    return in_array($host,$hosts,true) && (parse_url($value,PHP_URL_PORT) === null || parse_url($value,PHP_URL_PORT) === 443);
}
