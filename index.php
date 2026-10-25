<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if (!installed()) {
    redirect('/install/');
}

$path = path_only();
if($path==='/webhooks/paypal')paypal_notifications_webhook();
if (workspace_reports_dispatch($path) || planner_dispatch($path) || communication_mail_dispatch($path) || app_security_dispatch($path) || communication_dispatch($path) || meetings_dispatch($path) || google_workspace_dispatch($path) || impact_writer_route($path) || writer_account_route($path) || studio_dispatch($path) || media_centre_dispatch($path)) exit;
if (str_starts_with($path,'/write') || str_starts_with($path,'/consent/') || str_starts_with($path,'/payments/')) private_response_headers();
// Optional modules may handle additional routes without changing legacy URLs.
if (function_exists('app_extension_route') && app_extension_route($path)) exit;

function not_found(): never {
    http_response_code(404);
    render('404', [
        'page'=>'404',
        'seo'=>['title'=>'Page not found · Waithaka Ndegwa','description'=>'The requested page could not be found.','canonical'=>url(path_only()),'robots'=>'noindex,follow']
    ]);
    exit;
}

function person_schema(): array {
    return [
        '@context'=>'https://schema.org','@type'=>'Person','@id'=>url('/about#person'),'name'=>'Waithaka Ndegwa','url'=>url('/about'),
        'image'=>asset('images/journey/conversation.webp'),'birthPlace'=>['@type'=>'Place','name'=>'Nyeri, Kenya'],
        'description'=>'On the frontlines of social change in Africa, building practical solutions to some of the world’s most pressing social, economic, and environmental challenges.',
        'jobTitle'=>['Social entrepreneur','Innovation strategist','Ecosystem builder'],
        'sameAs'=>array_values(social_links()),
        'knowsAbout'=>['Social entrepreneurship','Agribusiness','National infrastructure','Innovation','Travel','Renewable energy','Regenerative agriculture','Circular economy','Impact investment','Education','Inclusive economic growth'],
    ];
}

if ($path === '/') {
    $latest = latest_posts(7);
    $selected = selected_posts(3);
    $sql = 'SELECT c.*, SUM(CASE WHEN '.published_where().' THEN 1 ELSE 0 END) post_count FROM categories c LEFT JOIN posts p ON p.category_id=c.id GROUP BY c.id ORDER BY c.sort_order,c.name';
    $topicStats = db()->query($sql)->fetchAll();
    $schema = [person_schema(),[
        '@context'=>'https://schema.org','@type'=>'WebSite','@id'=>url('/#website'),'name'=>'Waithaka Ndegwa','url'=>url('/'),
        'description'=>seo_defaults()['description'],
        'publisher'=>['@type'=>'Person','name'=>'Waithaka Ndegwa','url'=>url('/about')],
        'potentialAction'=>['@type'=>'SearchAction','target'=>url('/search?q={search_term_string}'),'query-input'=>'required name=search_term_string'],
        'inLanguage'=>'en',
        'audience'=>['@type'=>'Audience','audienceType'=>'Readers worldwide interested in African development, social entrepreneurship, agribusiness, infrastructure, innovation and travel']
    ]];
    render('home',['page'=>'home','latest'=>$latest,'selected'=>$selected,'topicStats'=>$topicStats,'schema'=>$schema,'seo'=>['title'=>'Waithaka Ndegwa · Social Entrepreneur, Innovation Strategist & Ecosystem Builder','description'=>'Social entrepreneur, innovation strategist and ecosystem builder advancing sustainable enterprises, inclusive growth and practical solutions across Africa.','canonical'=>url('/')]]);
    exit;
}

if ($path === '/writing') { $pageNumber=archive_page_number($_GET['page']??null);if($pageNumber===null)not_found();redirect(url(archive_page_path('/my-thoughts',$pageNumber)), 301); }
if (preg_match('~^/writing/([a-z0-9-]+)$~',$path,$m)) { redirect(url('/my-thoughts/'.$m[1]), 301); }

if ($path === '/my-thoughts') {
    $pagination=archive_pagination(archive_page_number($_GET['page']??null),public_post_count(),24,'/my-thoughts');if($pagination===null)not_found();
    render('writing',[
        'page'=>'thoughts','posts'=>latest_posts($pagination['per_page'],null,$pagination['offset']),'categories'=>categories(),'activeCategory'=>null,'pagination'=>$pagination,
        'seo'=>['title'=>'My Thoughts'.($pagination['page']>1?' · Page '.$pagination['page']:'').' · Africa, Development & a Global View · Waithaka Ndegwa','description'=>'Long-form personal views from Kenya and East Africa on social entrepreneurship, agribusiness, infrastructure, innovation and travel, written for readers across Africa and the wider world.','canonical'=>url($pagination['path'])]
    ]);
    exit;
}

if (preg_match('~^/topic/([a-z0-9-]+)$~',$path,$m)) {
    $cat=category_by_slug($m[1]); if(!$cat) not_found();
    $pagination=archive_pagination(archive_page_number($_GET['page']??null),public_post_count((int)$cat['id']),24,'/topic/'.$cat['slug']);if($pagination===null)not_found();
    $regionalTopicIntro=(string)$cat['description'];
    render('writing',[
        'page'=>'thoughts','posts'=>latest_posts($pagination['per_page'],(int)$cat['id'],$pagination['offset']),'categories'=>categories(),'activeCategory'=>$cat,'pagination'=>$pagination,
        'headingEyebrow'=>'Topic','heading'=>$cat['name'],'intro'=>$regionalTopicIntro,
        'seo'=>['title'=>$cat['name'].($pagination['page']>1?' · Page '.$pagination['page']:'').' · Africa & Global Perspectives · Waithaka Ndegwa','description'=>$regionalTopicIntro,'canonical'=>url($pagination['path'])]
    ]);
    exit;
}

if (preg_match('~^/my-thoughts/([a-z0-9-]+)$~',$path,$m)) {
    $post=post_by_slug($m[1]); if(!$post) not_found();
    $canonical=url('/my-thoughts/'.$post['slug']);
    $image=featured_image_url($post['featured_image']);
    $meaningfulModified=(function_exists('editorial_article_modified')?editorial_article_modified($post):null)?:$post['published_at'];
    $article=[
        '@context'=>'https://schema.org','@type'=>'BlogPosting','headline'=>$post['title'],
        'description'=>$post['seo_description'] ?: ($post['standfirst'] ?: excerpt($post['content_html'],160)),
        'datePublished'=>date(DATE_ATOM,strtotime($post['published_at'])),'dateModified'=>date(DATE_ATOM,strtotime($meaningfulModified)),
        'mainEntityOfPage'=>$canonical,'url'=>$canonical,'image'=>[$image],
        'author'=>['@type'=>'Person','name'=>'Waithaka Ndegwa','url'=>url('/about'),'sameAs'=>array_values(social_links())],
        'publisher'=>['@type'=>'Person','name'=>'Waithaka Ndegwa','url'=>url('/about')],
        'articleSection'=>$post['category_name'] ?: null,
        'keywords'=>implode(', ',array_column($post['tags'],'name')),
        'inLanguage'=>'en'
    ];
    $breadcrumbs=['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[
        ['@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>url('/')],
        ['@type'=>'ListItem','position'=>2,'name'=>'My Thoughts','item'=>url('/my-thoughts')],
        ['@type'=>'ListItem','position'=>3,'name'=>$post['title'],'item'=>$canonical],
    ]];
    render('post',[
        'page'=>'post','post'=>$post,'related'=>related_posts($post,3),'schema'=>[$article,$breadcrumbs],
        'seo'=>['title'=>($post['seo_title'] ?: $post['title']).' · Waithaka Ndegwa','description'=>$post['seo_description'] ?: ($post['standfirst'] ?: excerpt($post['content_html'],160)),'canonical'=>$canonical,'image'=>$image,'type'=>'article']
    ]);
    exit;
}

if ($path === '/about') {
    $profile=['@context'=>'https://schema.org','@type'=>'ProfilePage','mainEntity'=>person_schema(),'url'=>url('/about'),'name'=>'About Waithaka Ndegwa'];
    render('about',['page'=>'about','schema'=>[$profile],'seo'=>['title'=>'About Waithaka Ndegwa · Purpose, Practice & 2030 Goals','description'=>'Meet Waithaka Ndegwa: social entrepreneur, innovation strategist and ecosystem builder. Explore his work, approach and enterprise, employment and market-access goals for 2030.','canonical'=>url('/about')]]);
    exit;
}

if ($path === '/contact') {
    header('Cache-Control: no-store, private, max-age=0');header('Referrer-Policy: no-referrer');
    if (is_post()) {
        verify_csrf();
        unset($_SESSION['contact_receipt']);
        $_SESSION['contact_form']=contact_draft_values($_POST);
        $contactNonce=contact_form_nonce($_POST['contact_request']??'');
        $contactDigest=hash('sha256',json_encode($_SESSION['contact_form'],JSON_THROW_ON_ERROR));
        if($contactNonce!==''&&isset($_SESSION['contact_requests'][$contactNonce])){$priorReceipt=$_SESSION['contact_requests'][$contactNonce];if(($priorReceipt['digest']??'')!==$contactDigest){flash('error','That form was already sent. Your changes are shown below; review and send this new form to create a follow-up.');redirect(url('/contact#contact-message'));}$_SESSION['contact_receipt']=$priorReceipt;unset($_SESSION['contact_form']);redirect(url('/contact#contact-receipt'));}
        $m=[
            'reference'=>make_reference('WK'),'first_name'=>trim((string)($_POST['first_name']??'')),'last_name'=>trim((string)($_POST['last_name']??'')),
            'email'=>trim((string)($_POST['email']??'')),'phone_code'=>trim((string)($_POST['phone_code']??'+254')),'phone'=>trim((string)($_POST['phone']??'')),
            'country'=>trim((string)($_POST['country']??'Kenya')),'city'=>trim((string)($_POST['city']??'')),'message'=>trim((string)($_POST['message']??'')),
        ];
        if($m['first_name']===''||!filter_var($m['email'],FILTER_VALIDATE_EMAIL)||$m['message']===''){
            flash('error','Please complete all required fields with a valid email address.');redirect(url('/contact#contact-message'));
        }
        $contactTokenValid=contact_form_consume($contactNonce);$contactCaptchaValid=recaptcha_ok();
        if(function_exists('reader_contact_submit')){
            try{$contactId=contact_guard_receive($m,$_POST,$contactTokenValid,$contactCaptchaValid);}catch(InvalidArgumentException $e){flash('error',$e->getMessage());redirect(url('/contact#contact-message'));}
        }else{
            $st=db()->prepare('INSERT INTO contact_messages(reference,first_name,last_name,email,phone_code,phone,country,city,message,ip_hash,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $st->execute([$m['reference'],$m['first_name'],$m['last_name'],$m['email'],$m['phone_code'],$m['phone'],$m['country'],$m['city'],$m['message'],ip_hash(),mb_substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
            $sent=send_contact_email($m); if($sent&&!function_exists('enqueue_email'))db()->prepare('UPDATE contact_messages SET email_sent=1 WHERE reference=?')->execute([$m['reference']]);
        }
        $receipt=['reference'=>$m['reference'],'first'=>$m['first_name'],'type'=>$_SESSION['contact_form']['enquiry_type']??'general','digest'=>$contactDigest];$_SESSION['contact_receipt']=$receipt;unset($_SESSION['contact_form']);if($contactNonce!==''){$_SESSION['contact_requests'][$contactNonce]=$receipt;$_SESSION['contact_requests']=array_slice($_SESSION['contact_requests'],-20,null,true);}
        flash('success','Thank you. Your message has been received. Reference: '.$m['reference']);redirect(url('/contact#contact-receipt')); 
    }
    $contactDraft=$_SESSION['contact_form']??[];$contactReceipt=$_SESSION['contact_receipt']??null;unset($_SESSION['contact_form'],$_SESSION['contact_receipt']);if($contactDraft||$contactReceipt)header('X-Robots-Tag: noindex, nofollow, noarchive');$queryType=is_string($_GET['type']??null)&&isset(contact_guides()[$_GET['type']])?$_GET['type']:'general';$contactValues=$contactDraft+['enquiry_type'=>$queryType,'phone_code'=>'+254','country'=>''];
    render('contact',['contactValues'=>$contactValues,'contactReceipt'=>$contactReceipt,'page'=>'contact','seo'=>['title'=>'Contact · Waithaka Ndegwa','description'=>'Contact Waithaka Ndegwa for thoughtful questions, speaking invitations, collaborations, media enquiries or responses to My Thoughts.','canonical'=>url('/contact')]]);exit;
}

if ($path === '/contribute') {
    if (is_post()) {
        verify_csrf();
        $method=(string)($_POST['method']??'pesapal');
        if($method==='wise')redirect(url('/contribute?method=wise#wise-bank-transfer'));
        if(!in_array($method,['mpesa','pesapal','paypal'],true)){
            flash('error','Please choose a valid payment method.');redirect(url('/contribute'));
        }

        try {
            $currency='KES';
            if($method==='mpesa'){
                $choice=(string)($_POST['amount_choice_kes']??'1000');
                $amount=payment_decimal($choice==='other'?($_POST['amount_kes']??'0'):$choice);
                $minor=payment_minor_units($amount);
                if($minor<5000||$minor>100000000||$minor%100!==0)throw new InvalidArgumentException('Enter a whole Kenyan shilling amount between 50 and 1,000,000.');
            }elseif($method==='pesapal'){
                $currency=strtoupper(trim((string)($_POST['card_currency']??'USD')));
                if(!in_array($currency,['KES','USD','EUR','GBP'],true))throw new InvalidArgumentException('Choose a supported card currency.');
                $choice=(string)($_POST['amount_choice_card']??($currency==='KES'?'1000':'10'));
                $amount=payment_decimal($choice==='other'?($_POST['amount_card']??'0'):$choice);
                $minor=payment_minor_units($amount);
                if($minor<($currency==='KES'?5000:100)||$minor>($currency==='KES'?100000000:10000000))throw new InvalidArgumentException('Enter an amount within the supported contribution range.');
            }else{
                $currency='USD';$choice=(string)($_POST['amount_choice_usd']??'10');
                $amount=payment_decimal($choice==='other'?($_POST['amount_usd']??'0'):$choice);
                $minor=payment_minor_units($amount);
                if($minor<100||$minor>1000000)throw new InvalidArgumentException('Enter a PayPal contribution between USD 1 and USD 10,000.');
            }
        }catch(InvalidArgumentException $e){flash('error',$e->getMessage());redirect(url('/contribute'));}

        if(empty($_POST['contribution_acknowledgement'])){flash('error','Please confirm that you understand the contribution terms.');redirect(url('/contribute'));}

        $reference=make_reference('CON');$contributionId=0;
        try{
            if($method==='mpesa'){
                $phone=trim((string)($_POST['mpesa_phone']??'')); $normalized=normalize_ke_phone($phone);
                db()->prepare('INSERT INTO contributions(reference,method,amount,currency,status) VALUES(?,?,?,?,?)')
                    ->execute([$reference,'mpesa',$amount,'KES','initiated']);
                $contributionId=(int)db()->lastInsertId();payment_start_tracking($contributionId,'mpesa');
                $r=mpesa_stk_push($reference,$amount,$normalized);
                payment_assert((string)($r['ResponseCode']??'')==='0'&&!empty($r['CheckoutRequestID'])&&!empty($r['MerchantRequestID']),'M-Pesa did not confirm checkout initiation.');
                payment_bind_provider($contributionId,'mpesa',(string)$r['CheckoutRequestID'],['merchant'=>(string)$r['MerchantRequestID']],$r);
                redirect(url('/contribute?ref='.rawurlencode($reference)));
            }

            if($method==='pesapal'){
                $anonymous=!empty($_POST['anonymous']);
                $contactType=(string)($_POST['card_contact_type']??'email');
                $paymentContact=trim((string)($_POST['card_payment_contact']??''));
                if(!in_array($contactType,['email','phone'],true))$contactType='email';
                if($paymentContact==='') throw new RuntimeException('Pesapal requires either an email address or phone number to start card checkout.');
                if($contactType==='email'&&!filter_var($paymentContact,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid email address for Pesapal card checkout.');
                $person=[
                    'first_name'=>$anonymous?'':trim((string)($_POST['card_first_name']??'')),
                    'last_name'=>$anonymous?'':trim((string)($_POST['card_last_name']??'')),
                    'email'=>$contactType==='email'?$paymentContact:'',
                    'phone'=>$contactType==='phone'?$paymentContact:'',
                    'country_code'=>''
                ];
                db()->prepare('INSERT INTO contributions(reference,method,amount,currency,first_name,last_name,email,phone,status) VALUES(?,?,?,?,?,?,?,?,?)')
                    ->execute([$reference,'pesapal',$amount,$currency,$person['first_name']?:null,$person['last_name']?:null,$anonymous?null:($person['email']?:null),$anonymous?null:($person['phone']?:null),'initiated']);
                $contributionId=(int)db()->lastInsertId();payment_start_tracking($contributionId,'pesapal');
                $r=pesapal_submit($reference,$amount,$currency,$person);
                $provider=$r['order_tracking_id']??null;
                $redir=$r['redirect_url']??null;
                payment_assert(!empty($provider)&&($r['merchant_reference']??'')===$reference&&(string)($r['status']??'')==='200','Pesapal did not confirm the expected order.');
                payment_bind_provider($contributionId,'pesapal',(string)$provider,[],$r);
                if(!$redir||!payment_checkout_url('pesapal',$redir)) throw new RuntimeException('Pesapal did not return a valid checkout URL.');
                redirect($redir);
            }

            db()->prepare('INSERT INTO contributions(reference,method,amount,currency,status) VALUES(?,?,?,?,?)')
                ->execute([$reference,'paypal',$amount,'USD','initiated']);
            $contributionId=(int)db()->lastInsertId();payment_start_tracking($contributionId,'paypal');
            $r=paypal_create_order($reference,$amount,'USD');
            $provider=(string)($r['id']??'');
            $redir=paypal_approval_url($r);
            if($provider===''||!$redir) throw new RuntimeException('PayPal did not return a valid approval link.');
            payment_bind_provider($contributionId,'paypal',$provider,[],$r);
            redirect($redir);
        }catch(Throwable $e){
            if($contributionId){
                $row=['id'=>$contributionId,'method'=>$method];
                payment_needs_review($row,'Checkout initiation result is uncertain; check provider records before retrying.');
                payment_event($row,'initiation','unconfirmed',['reason'=>$e->getMessage()]);
                error_log('Contribution initiation unconfirmed '.$reference.': '.$e->getMessage());
                flash('error','Checkout could not be confirmed. Keep reference '.$reference.' and check for an existing payment before trying again.');
                redirect(url('/contribute?ref='.rawurlencode($reference)));
            }
            flash('error',$e->getMessage());redirect(url('/contribute'));
        }
    }
    $ref=trim((string)($_GET['ref']??''));$contribution=null;
    if($ref!==''&&preg_match('/^CON-[A-Z0-9-]+$/',$ref)){
        $st=db()->prepare('SELECT reference,method,amount,currency,status,created_at FROM contributions WHERE reference=? LIMIT 1');$st->execute([$ref]);$contribution=$st->fetch()?:null;
    }
    render('contribute',[
        'page'=>'contribute','contribution'=>$contribution,
        'seo'=>[
            'title'=>'Support My Thoughts · Waithaka Ndegwa',
            'description'=>'Support Waithaka Ndegwa’s independent writing anonymously or by name through M-Pesa, card via Pesapal, PayPal, or Wise bank transfer when available.',
            'canonical'=>url('/contribute')
        ]
    ]);exit;
}

if ($path === '/payments/status') {
    $ref=trim((string)($_GET['ref']??''));
    if($ref===''||!preg_match('/^CON-[A-Z0-9-]+$/',$ref))json_response(['ok'=>false],400);
    $st=db()->prepare('SELECT reference,method,amount,currency,status FROM contributions WHERE reference=? LIMIT 1');$st->execute([$ref]);$row=$st->fetch();
    if(!$row)json_response(['ok'=>false],404);
    json_response(['ok'=>true,'reference'=>$row['reference'],'status'=>$row['status'],'method'=>$row['method'],'amount'=>(float)$row['amount'],'currency'=>$row['currency']]);
}

if ($path === '/payments/mpesa/callback') {
    if(!is_post())json_response(['ResultCode'=>1,'ResultDesc'=>'POST required'],405);
    $raw=file_get_contents('php://input',false,null,0,65537) ?: '';
    if(strlen($raw)>65536)json_response(['ResultCode'=>1,'ResultDesc'=>'Payload too large'],413);
    $payload=json_decode($raw,true);
    if(!is_array($payload)||!is_array($payload['Body']['stkCallback']??null))json_response(['ResultCode'=>1,'ResultDesc'=>'Invalid callback'],400);
    try{
        $row=payment_record_mpesa_callback($payload,(string)($_GET['ref']??''),(string)($_GET['state']??''));
        if($row)payment_reconcile((int)$row['id']);
    }catch(Throwable $e){error_log('M-Pesa callback rejected: '.$e->getMessage());json_response(['ResultCode'=>1,'ResultDesc'=>'Callback could not be correlated'],400);}
    json_response(['ResultCode'=>0,'ResultDesc'=>'Received']);
}

if ($path === '/payments/pesapal/callback' || $path === '/payments/pesapal/ipn') {
    $parameters=$_GET;
    if(is_post()){
        $raw=file_get_contents('php://input',false,null,0,65537) ?: '';
        if(strlen($raw)>65536)json_response(['status'=>500],413);
        $json=json_decode($raw,true);$parameters=is_array($json)?$json:$_POST;
    }
    $tracking=trim((string)($parameters['OrderTrackingId']??$parameters['orderTrackingId']??$parameters['order_tracking_id']??''));
    $merchant=trim((string)($parameters['OrderMerchantReference']??$parameters['orderMerchantReference']??$parameters['merchant_reference']??''));
    $notification=(string)($parameters['OrderNotificationType']??'IPNCHANGE');
    $ipn=$path==='/payments/pesapal/ipn';
    $ack=['orderNotificationType'=>$notification,'orderTrackingId'=>$tracking,'orderMerchantReference'=>$merchant,'status'=>500];
    try{
        payment_assert($tracking!==''&&$merchant!==''&&(!$ipn||$notification==='IPNCHANGE'),'Pesapal notification is missing expected identifiers.');
        $row=payment_find('pesapal',$tracking,$merchant);
        payment_assert($row!==null,'Pesapal tracking and merchant references do not match an existing order.');
        payment_event($row,'notification','unverified',['tracking'=>$tracking,'merchant'=>$merchant]);
        $confirmed=payment_reconcile((int)$row['id']);
        if($ipn){$ack['status']=$confirmed?200:500;json_response($ack,$confirmed?200:503);}
        if(!$confirmed)flash('info','The card payment is still being confirmed. Please keep your reference.');
        redirect(url('/contribute?ref='.rawurlencode($row['reference'])));
    }catch(Throwable $e){
        error_log('Pesapal notification not confirmed: '.$e->getMessage());
        if($ipn)json_response($ack,400);
        flash('error','We could not confirm this card transaction. Please keep your payment reference.');redirect(url('/contribute'));
    }
}

if ($path === '/payments/paypal/return') {
    $reference=trim((string)($_GET['ref']??''));$orderId=trim((string)($_GET['token']??''));$state=(string)($_GET['state']??'');
    $row=null;
    try{
        payment_assert($reference!==''&&$orderId!=='','PayPal returned without expected references.');
        $row=payment_find('paypal',$orderId,$reference);
        payment_assert($row!==null,'PayPal order and contribution do not match.');
        $st=db()->prepare('SELECT provider_environment FROM payment_reconciliation WHERE contribution_id=?');$st->execute([$row['id']]);
        payment_assert($st->fetchColumn()===payment_provider_environment('paypal'),'The original PayPal environment must be confirmed before processing.');
        $row['_primary']=payment_provider_identifier($row,'order');
        $response=paypal_get_order($orderId);
        $decision=payment_evaluate_paypal($row,$orderId,$response,payment_paypal_merchant($row));
        if(($response['status']??'')==='APPROVED'){
            payment_assert(hash_equals(payment_return_state($reference),$state),'This return link cannot authorize capture. The approved order remains available for reconciliation.');
            try{$captureResponse=paypal_capture_order($orderId);payment_event($row,'capture_response','unverified',$captureResponse);}catch(Throwable $e){
                // A timeout may occur after capture. A later retry uses the same
                // PayPal-Request-Id; the readback below never initiates a charge.
                payment_event($row,'capture_response','unconfirmed',['reason'=>$e->getMessage()]);
            }
            // A capture response may omit original order fields. Read the full
            // order so expected payee, references and totals are all checked.
            $response=paypal_get_order($orderId);
            $decision=payment_evaluate_paypal($row,$orderId,$response,payment_paypal_merchant($row));
        }
        payment_apply_verified($row,$decision,$response,'paypal_return');
    }catch(Throwable $e){
        if($row){payment_needs_review($row,$e->getMessage(),'retry');payment_event($row,'paypal_return','unconfirmed',['reason'=>$e->getMessage()]);}
        error_log('PayPal return not confirmed: '.$e->getMessage());
        flash('error','We could not confirm the PayPal transaction yet. Please keep your PayPal reference.');
    }
    redirect(url('/contribute'.($row?'?ref='.rawurlencode($row['reference']):'')));
}

if ($path === '/payments/paypal/cancel') {
    // A browser redirect is not proof that a payment was cancelled or unpaid.
    $reference=trim((string)($_GET['ref']??''));$state=(string)($_GET['state']??'');
    if($reference!==''&&hash_equals(payment_return_state($reference),$state)){
        $st=db()->prepare("SELECT id FROM contributions WHERE reference=? AND method='paypal'");$st->execute([$reference]);$id=$st->fetchColumn();
        if($id)payment_reconcile((int)$id);
    }
    flash('info','You returned from PayPal checkout. Any payment already made will be confirmed against PayPal records.');
    redirect(url('/contribute'));
}

// Private consent links. These are deliberately unlisted and excluded from search.
if (preg_match('~^/consent/([a-f0-9]{64})$~',$path,$m)) {
    $release=media_release_by_token($m[1]);
    if(!$release){http_response_code(404);exit('This consent link is not available.');}
    if(is_post()){
        verify_csrf();
        if(($release['status']??'')==='closed'){http_response_code(410);exit('This consent record is closed and cannot be signed.');}
        if(!empty($release['signed_at'])) redirect(url('/consent/'.$release['public_token']));
        if(!media_release_can_sign($release)){http_response_code(410);exit('This consent link is no longer open for signing.');}
        if(empty($_POST['affirm'])){flash('error','Please confirm that you have read the consent form.');redirect(url('/consent/'.$release['public_token']));}
        $name=trim((string)($_POST['signer_name']??''));$signature=trim((string)($_POST['signature_text']??''));
        $capacity=in_array(($_POST['signer_capacity']??'self'),['self','parent_guardian'],true)?(string)$_POST['signer_capacity']:'self';
        $participant=trim((string)($_POST['participant_name']??''));
        $attribution=in_array(($_POST['signer_attribution']??'anonymous'),['full_name','first_name','anonymous'],true)?(string)$_POST['signer_attribution']:'anonymous';
        $choices=['photo'=>!empty($_POST['consent_photo'])&&$release['allow_photo'],'video'=>!empty($_POST['consent_video'])&&$release['allow_video'],'audio'=>!empty($_POST['consent_audio'])&&$release['allow_audio'],'quotes'=>!empty($_POST['consent_quotes'])&&$release['allow_quotes']];
        if($name===''||$signature===''){flash('error','Please enter your name and electronic signature.');redirect(url('/consent/'.$release['public_token']));}
        if(mb_strlen($name)>190||mb_strlen($signature)>190||mb_strlen($participant)>190){flash('error','Names and signatures must be 190 characters or fewer.');redirect(url('/consent/'.$release['public_token']));}
        if($capacity==='parent_guardian'&&$participant===''){flash('error','Please enter the name of the person you are signing for.');redirect(url('/consent/'.$release['public_token']));}
        if(!array_filter($choices)){flash('error','Please select at least one use you agree to.');redirect(url('/consent/'.$release['public_token']));}
        $snapshot=json_encode(['version'=>release_consent_version(),'reference'=>$release['reference'],'title'=>$release['title'],'context'=>$release['context_note'],'purpose'=>$release['purpose_note'],'consent_text'=>release_consent_text($release),'choices'=>$choices,'attribution'=>$attribution,'capacity'=>$capacity,'participant'=>$participant],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
        $st=db()->prepare("UPDATE media_releases SET status='signed',signer_name=?,signer_contact=?,signer_country=?,signer_capacity=?,participant_name=?,signer_attribution=?,consent_photo=?,consent_video=?,consent_audio=?,consent_quotes=?,consent_version=?,consent_snapshot=?,signature_text=?,signed_ip_hash=?,signed_user_agent=?,signed_at=NOW() WHERE id=? AND signed_at IS NULL AND status='sent' AND (expires_at IS NULL OR expires_at>=NOW())");
        $st->execute([$name,mb_substr(trim((string)($_POST['signer_contact']??'')),0,190),mb_substr(trim((string)($_POST['signer_country']??'')),0,120),$capacity,$participant?:null,$attribution,$choices['photo']?1:0,$choices['video']?1:0,$choices['audio']?1:0,$choices['quotes']?1:0,release_consent_version(),$snapshot,$signature,ip_hash(),mb_substr($_SERVER['HTTP_USER_AGENT']??'',0,500),(int)$release['id']]);
        if($st->rowCount()!==1){http_response_code(409);exit('This consent record changed. Refresh the link to see its current status.');}
        log_release_event((int)$release['id'],'consent_signed','Consent recorded by signer.','signer',null);
        notify_writer('Consent signed · '.$release['reference'],"A private media consent has been signed.\n\nReference: {$release['reference']}\nTitle: {$release['title']}\nOpen: ".url('/write/releases/'.$release['id'])."\n");
        redirect(url('/consent/'.$release['public_token']));
    }
    require __DIR__.'/views/consent.php';exit;
}
if (preg_match('~^/consent/([a-f0-9]{64})/withdraw$~',$path,$m) && is_post()) {
    verify_csrf();$release=media_release_by_token($m[1]);if(!$release||empty($release['signed_at'])){http_response_code(404);exit('Consent record not found.');}
    if(empty($release['withdrawal_requested_at'])){
        $st=db()->prepare("UPDATE media_releases SET status=IF(status='closed','closed','withdrawal_requested'),withdrawal_requested_at=NOW() WHERE id=? AND withdrawal_requested_at IS NULL AND signed_at IS NOT NULL");$st->execute([(int)$release['id']]);
        if($st->rowCount()!==1)redirect(url('/consent/'.$release['public_token']));
        log_release_event((int)$release['id'],'withdrawal_requested','Signer requested withdrawal for future use.','signer',null);
        notify_writer('Consent withdrawal requested · '.$release['reference'],"A signer requested withdrawal of consent for future use.\n\nReference: {$release['reference']}\nTitle: {$release['title']}\nOpen: ".url('/write/releases/'.$release['id'])."\n");
    }
    redirect(url('/consent/'.$release['public_token']));
}

if ($path === '/search') {
    $q=trim((string)($_GET['q']??''));$posts=[];
    if($q!==''){
        $like='%'.$q.'%';
        $sql='SELECT DISTINCT p.*,c.name category_name,c.slug category_slug FROM posts p LEFT JOIN categories c ON c.id=p.category_id LEFT JOIN post_tags pt ON pt.post_id=p.id LEFT JOIN tags t ON t.id=pt.tag_id WHERE '.published_where().' AND (p.title LIKE ? OR p.standfirst LIKE ? OR p.content_html LIKE ? OR t.name LIKE ? OR c.name LIKE ?) ORDER BY p.published_at DESC LIMIT 60';
        $st=db()->prepare($sql);$st->execute([$like,$like,$like,$like,$like]);$posts=$st->fetchAll();
    }
    render('search',['page'=>'search','q'=>$q,'posts'=>$posts,'categories'=>categories(),'seo'=>['title'=>($q?'Search: '.$q:'Search').' · Waithaka Ndegwa','description'=>'Search Waithaka Ndegwa’s published thoughts and long-form posts.','canonical'=>url('/search'.($q?'?q='.rawurlencode($q):'')),'robots'=>$q?'noindex,follow':'index,follow']]);exit;
}

if ($path === '/feed.xml') {
    header('Content-Type: application/rss+xml; charset=utf-8');$posts=latest_posts(50);
    echo '<?xml version="1.0" encoding="UTF-8"?>';?>
<rss version="2.0"><channel><title>Waithaka Ndegwa</title><link><?=e(url('/'))?></link><description><?=e(seo_defaults()['description'])?></description><language>en-ke</language><?php foreach($posts as $p):?><item><title><?=e($p['title'])?></title><link><?=e(url('/my-thoughts/'.$p['slug']))?></link><guid isPermaLink="true"><?=e(url('/my-thoughts/'.$p['slug']))?></guid><pubDate><?=e(date(DATE_RSS,strtotime($p['published_at'])))?></pubDate><description><?=e($p['standfirst']?:excerpt($p['content_html'],240))?></description></item><?php endforeach;?></channel></rss><?php exit;
}

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');$posts=latest_posts(5000);$cats=categories();$static=['/','/my-thoughts','/about','/contact','/contribute','/privacy','/cookies','/legal','/terms','/contribution-terms','/editorial-independence'];
    $entries=[];
    foreach($static as $s)$entries[]=['loc'=>url($s)];
    foreach($cats as $c)$entries[]=['loc'=>url('/topic/'.$c['slug'])];
    foreach($posts as $p)$entries[]=['loc'=>url('/my-thoughts/'.$p['slug']),'lastmod'=>date('c',strtotime($p['updated_at']))];
    if(function_exists('app_sitemap_entries'))$entries=array_merge($entries,app_sitemap_entries());
    echo sitemap_xml($entries);exit;
}

if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');echo "User-agent: *\nAllow: /\nDisallow: /write\nDisallow: /install\nDisallow: /app\nDisallow: /storage\n\nSitemap: ".url('/sitemap.xml')."\n";exit;
}

$policies=[
'/editorial-independence'=>['title'=>'Editorial independence & personal views','intro'=>'A clear boundary between my personal writing and any work or institution with which I may be associated.','html'=>'<h2>Personal responsibility</h2><p>Unless a page explicitly states otherwise, the views, interpretations and opinions published on this website are mine. They do not necessarily represent the views, policies or positions of any business, institution or organisation with which I may be associated.</p><h2>Experience and disclosure</h2><p>My work gives me direct experience in fields I may write about. Where a relationship could materially help a reader understand the context of a piece, I aim to disclose it clearly. Experience may inform an argument; it does not transfer authorship or responsibility for that argument to an organisation.</p><h2>Corrections</h2><p>I may update published writing to correct errors, improve clarity or reflect material new information. Substantive updates should be reflected by the page’s updated date.</p><h2>AI assistance</h2><p>Technology, including artificial intelligence, may assist with tasks such as tagging, search metadata, formatting, research organisation or editorial checks. The published views remain mine, and automated tools are not presented as speaking on my behalf.</p>'],
'/privacy'=>['title'=>'Privacy policy','intro'=>'How information submitted through waithakandegwa.com is handled.','html'=>'<h2>Information you provide</h2><p>The contact form collects the details you choose to submit there. Contributions may be recorded anonymously. Names are optional; M-Pesa requires a phone number to initiate STK Push and Pesapal requires either an email address or phone number to create card checkout. When anonymous is selected for card support, that transaction contact is sent to Pesapal but is not saved as the contributor’s contact record on this website. Payment transaction references, amount, currency, method and status may be retained for reconciliation.</p><h2>Why it is used</h2><p>Information is used to respond to messages, process and reconcile contributions, protect the website from abuse, maintain records where reasonably necessary, and understand site performance when analytics consent has been given.</p><h2>Payments</h2><p>M-Pesa transactions are processed through Safaricom services, card transactions through Pesapal, PayPal contributions through PayPal, and bank transfers use the published Wise receiving details. This website does not store card numbers, M-Pesa PINs or PayPal passwords. Payment providers may request information they require to process a transaction under their own terms.</p><h2>Private media consent</h2><p>When a photograph, video, audio recording or conversation needs explicit permission, I may send the person an unlisted consent link. The form asks only for information reasonably needed to document permission, such as a name/electronic signature, selected uses, attribution choice, timestamp and optional contact or country. The link is excluded from search indexing and is not part of the public website navigation.</p><h2>Private appreciation and expense records</h2><p>If I reimburse expenses or give a modest appreciation connected with a conversation, photograph or other contribution, the Writer area can retain a private payment trace such as amount, currency, method, transaction reference and optional proof. These records are not displayed publicly and are kept separately from the person’s consent choices.</p><h2>Startup and founder submissions</h2><p>Founder submissions collect the contact details, venture information, proposed story and evidence you choose to provide. These records are reviewed privately. Sending a submission does not publish it. Public founder stories require editorial review and the founder’s approval of the exact final story version. Private review links are sent to verified email and WhatsApp contacts with permission. Founders may approve, decline or propose edits; edited versions need a new review, with a maximum of three rounds. The default response window is 14 days after both providers accept the notifications, configurable when requesting a review. Declined, expired and three-times-unapproved stories are discarded from publication, while a private audit record is retained. Notification failures are distinguished from a founder’s refusal. Private evidence and contact information are not included in the public collection.</p><h2>Email updates</h2><p>Subscription requests require confirmation through a time-limited email link before an address is marked subscribed. Subscription and consent records are retained to deliver requested updates and honour unsubscribe requests. Confirmation and unsubscribe links are excluded from analytics. You may unsubscribe using the link provided in an update or contact the website for help.</p><h2>Booking documents and private reports</h2><p>Visitors choose calendar-verified time slots. All new bookings require host approval after a private briefing report is prepared. Optional PDF, JPEG or PNG attachments remain private and are not automatically sent to an AI provider. The host can review them manually or, where authorised, explicitly request a private briefing draft using OpenAI or Google Gemini. AI output is unverified and reviewed by the host. If analysis is unavailable, the request remains for manual document review. The private workspace also stores notes and reviewed reports; selected notes are sent to the chosen AI provider only with explicit author consent. Reports are shared by email or prepared in WhatsApp only through an explicit author action.</p><h2>Service providers</h2><p>Configured hosting, email, analytics and social publishing providers process the information needed to supply their services. Social account grants are used only for the selected connections and approved publishing tasks. Access tokens are stored encrypted and are not displayed publicly. Public social posts are subject to the relevant platform’s privacy settings and terms.</p><h2>Analytics and cookies</h2><p>The Google tag library loads on public editorial pages when analytics is enabled. Analytics data collection and analytics cookies remain disabled until you allow analytics. Advertising storage and personalisation remain disabled. Private links, Writer pages, searches, contact and payment forms are excluded. Only recognised campaign identifiers are retained for attribution; submitted form values and private link tokens are not sent. Necessary session and security cookies may operate without analytics consent because they are required for site functionality.</p><h2>Your requests</h2><p>You may use the contact form to request access, correction or deletion of personal information held by this website, subject to records that must be retained for legal, security or accounting reasons.</p>'],
'/cookies'=>['title'=>'Cookie policy','intro'=>'A minimal approach to cookies and analytics.','html'=>'<h2>Necessary cookies</h2><p>Necessary cookies support security, session management, the private writing interface and form protection. They are not used for advertising.</p><h2>Analytics</h2><p>When analytics is enabled, the Google tag library loads on public editorial pages. Its measurement and analytics cookies remain disabled until you choose Allow analytics. You can decline and continue using the website, or change your choice through Cookie preferences. Advertising storage and personalisation remain off. Necessary and analytics preferences are stored locally in your browser.</p><h2>Third-party media</h2><p>Embedded video or payment services may set their own cookies or receive technical information when you choose to use those features. YouTube embeds use the privacy-enhanced domain where possible.</p>'],
'/legal'=>['title'=>'Legal Policy','intro'=>'The legal terms governing access to, reliance on, linking to and use of material published on waithakandegwa.com.','html'=>'<h2>Personal commentary</h2><p>The writing on this website reflects my personal experience, commentary and views unless a page explicitly states otherwise. It does not necessarily represent the views, policies or positions of any business, institution or organisation with which I may be associated.</p><h2>General information</h2><p>Content is provided for general information, commentary and discussion. It is not professional legal, financial, medical, tax or investment advice and should not be treated as a substitute for advice appropriate to your circumstances.</p><h2>Copyright and permitted use</h2><p>Unless otherwise indicated, original text, graphics and site materials are protected by applicable copyright law. You may link to public pages and quote brief passages with clear attribution to Waithaka Ndegwa and a link to the original page. Republishing, adapting or commercially exploiting substantial portions requires prior permission.</p><h2>Accuracy and updates</h2><p>I aim to publish responsibly, but information can change and errors may occur. Content may be corrected, revised, expanded, archived or withdrawn without notice. Where practical, substantive revisions to published writing may be reflected by an updated date.</p><h2>External services and links</h2><p>Links, embedded media and third-party services are provided for context, functionality or convenience. Their content, availability, security, terms and privacy practices are controlled by their respective operators.</p><h2>Availability and liability</h2><p>The website is provided on an as-available basis. To the extent permitted by applicable law, no guarantee is made that every page, link, integration or third-party service will always be uninterrupted, error-free or current.</p>'],
'/terms'=>['title'=>'Legal Policy','intro'=>'The legal terms governing access to, reliance on, linking to and use of material published on waithakandegwa.com.','html'=>'<h2>Personal commentary</h2><p>The writing on this website reflects my personal experience, commentary and views unless a page explicitly states otherwise. It does not necessarily represent the views, policies or positions of any business, institution or organisation with which I may be associated.</p><h2>General information</h2><p>Content is provided for general information, commentary and discussion. It is not professional legal, financial, medical, tax or investment advice and should not be treated as a substitute for advice appropriate to your circumstances.</p><h2>Copyright and permitted use</h2><p>Unless otherwise indicated, original text, graphics and site materials are protected by applicable copyright law. You may link to public pages and quote brief passages with clear attribution to Waithaka Ndegwa and a link to the original page. Republishing, adapting or commercially exploiting substantial portions requires prior permission.</p><h2>Accuracy and updates</h2><p>I aim to publish responsibly, but information can change and errors may occur. Content may be corrected, revised, expanded, archived or withdrawn without notice. Where practical, substantive revisions to published writing may be reflected by an updated date.</p><h2>External services and links</h2><p>Links, embedded media and third-party services are provided for context, functionality or convenience. Their content, availability, security, terms and privacy practices are controlled by their respective operators.</p><h2>Availability and liability</h2><p>The website is provided on an as-available basis. To the extent permitted by applicable law, no guarantee is made that every page, link, integration or third-party service will always be uninterrupted, error-free or current.</p>'],
'/contribution-terms'=>['title'=>'Contribution terms','intro'=>'Contributions are a simple way to support the independent work behind this website.','html'=>'<h2>Voluntary contribution</h2><p>A contribution is voluntary. It is not a charitable donation, membership fee, investment, purchase of goods, or payment for a promised service or editorial outcome.</p><h2>No influence over views</h2><p>A contribution does not buy access, favourable coverage, endorsement, editorial control or influence over what I publish.</p><h2>Payment processing</h2><p>M-Pesa payments are processed through Safaricom, card payments through Pesapal, PayPal contributions through PayPal. Wise bank transfers use the receiving details published for each enabled currency and require receipt verification. Card contributions may be requested in KES, USD, EUR or GBP. Provider terms, currency conversion and card-issuer charges may also apply. Transaction references, amount, currency, method and status may be retained for reconciliation, fraud prevention and accounting.</p><h2>Anonymous contributions</h2><p>You may keep your contribution anonymous and names are optional. M-Pesa requires a phone number for STK Push, while Pesapal requires one transaction contact — either email or phone — to initiate card checkout. When anonymous is selected for card support, this website does not retain that contact as the contributor’s identity. Payment providers may still process information they require under their own terms. There is no public donor list.</p><h2>Refunds and errors</h2><p>If you believe a contribution was duplicated or made in error, contact me promptly with the transaction reference so the circumstances can be reviewed. Any refund remains subject to payment-provider capability, applicable charges and law.</p><h2>Tax treatment</h2><p>Contributions should not be assumed to qualify for charitable or tax-deductible treatment. Contributors are responsible for obtaining their own tax advice where relevant.</p>']
];
if(isset($policies[$path])){render('policy',['page'=>'policy','policy'=>$policies[$path],'seo'=>['title'=>$policies[$path]['title'].' · Waithaka Ndegwa','description'=>$policies[$path]['intro'],'canonical'=>url($path)]]);exit;}

// Private writer routes
if ($path === '/write/login') {
    if(current_user())redirect(url('/write'));
    if(is_post()){verify_csrf();$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');if(attempt_login($email,$password))redirect(url('/write'));flash('error',login_rate_limited($email)?'Too many failed attempts. Please wait before trying again.':'Email or password is incorrect.');redirect(url('/write/login'));}
    render_write('login',['writeTitle'=>'Sign in']);exit;
}
if ($path === '/write/logout') {
    require_auth();
    if (!is_post()) {
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Sign out</title></head><body><form method="post">'.csrf_field().'<button type="submit">Sign out of Writer</button></form></body></html>';exit;
    }
    verify_csrf();app_trust_forget();session_unset();session_destroy();redirect(url('/write/login'));
}
if ($path === '/write') {
    require_auth();$posts=writer_posts();$rows=db()->query("SELECT status,COUNT(*) n FROM posts GROUP BY status")->fetchAll();$counts=['draft'=>0,'scheduled'=>0,'published'=>0,'selected'=>(int)db()->query('SELECT COUNT(*) FROM posts WHERE is_selected=1')->fetchColumn()];foreach($rows as $r)$counts[$r['status']]=(int)$r['n'];render_write('dashboard',['writeTitle'=>'My Thoughts','posts'=>$posts,'counts'=>$counts,'workspace'=>writer_workspace_summary()]);exit;
}
if ($path === '/write/new') { require_auth();$newPost=[];if(isset($_GET['founder'])){$founderId=reader_id($_GET['founder']);$q=db()->prepare('SELECT id,associated_post_id FROM founder_submissions WHERE id=?');$q->execute([$founderId]);$founder=$q->fetch();if(!$founder)not_found();if($founder['associated_post_id'])redirect(url('/write/edit/'.$founder['associated_post_id']));$newPost['_founder_submission_id']=$founderId;}render_write('editor',['writeTitle'=>'New post','post'=>$newPost,'categories'=>categories()]);exit; }
if (preg_match('~^/write/edit/(\d+)$~',$path,$m)) {
    require_auth();$post=writer_post_by_id((int)$m[1]);if(!$post)not_found();
    render_write('editor',['writeTitle'=>'Edit · '.$post['title'],'post'=>$post,'categories'=>categories()]);exit;
}
if (preg_match('~^/write/preview/(\d+)$~',$path,$m)) {
    require_auth();$post=writer_post_by_id((int)$m[1]);if(!$post)not_found();render_writer_preview($post);exit;
}
if ($path === '/write/save' && is_post()) {
    require_auth();verify_csrf();$user=current_user();$id=(int)($_POST['id']??0);$autosave=!empty($_POST['autosave']);
    // An autosave must never publish, even if a stale client includes action=publish.
    $weeklyPublish=!$autosave&&($_POST['action']??'draft')==='publish_weekly';$publish=!$autosave && in_array($_POST['action']??'draft',['publish','publish_weekly'],true);
    try {
        $image=null;
        if(!$autosave && !empty($_FILES['featured_image_file']['name']))$image=upload_featured_image($_FILES['featured_image_file']);
        $preparedMetadata=null;
        if($publish && trim((string)setting('openai.api_key',''))!=='') {
            $existing=$id?writer_post_by_id($id):['status'=>'draft'];
            if(!$existing)throw new InvalidArgumentException('The article could not be found.');
            if($id && (int)($_POST['draft_version']??-1)!==(int)($existing['_draft_version']??0))throw new WriterConflict('A newer draft exists. Reload before publishing.');
            $checked=writer_post_fields($_POST,$existing,$image);
            $category=db()->prepare('SELECT name FROM categories WHERE id=?');$category->execute([$checked['category_id']]);
            $preparedMetadata=auto_editorial_metadata($checked['title'],$checked['standfirst'],$checked['content_html'],(string)$category->fetchColumn());
        }
        if($weeklyPublish){
            $saved=comm_lock('publishing.weekly.articles',function()use($id,$user,$publish,$image,$preparedMetadata){$current=$id?writer_post_by_id($id):null;if($current&&!empty($current['_is_public']))throw new InvalidArgumentException('An already-public article keeps its original publication date.');$input=$_POST;$input['published_at']=publishing_weekly_article_time($id);return writer_save_post($input,(int)$user['id'],$publish,$image,$preparedMetadata);});
        }else $saved=writer_save_post($_POST,(int)$user['id'],$publish,$image,$preparedMetadata);
    } catch (WriterConflict $e) {
        if($autosave)json_response(['ok'=>false,'error'=>$e->getMessage(),'conflict'=>true],409);
        http_response_code(409);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Newer draft exists</title></head><body><h1>A newer version is already saved.</h1><p>'.e($e->getMessage()).'</p><p>Use your browser Back button to copy your unsaved work before opening the current editor.</p><a href="'.e(url('/write/edit/'.$id)).'">Open the current saved version</a></body></html>';exit;
    } catch (InvalidArgumentException $e) {
        if($autosave)json_response(['ok'=>false,'error'=>$e->getMessage()],422);
        flash('error',$e->getMessage());redirect($id?url('/write/edit/'.$id):url('/write/new'));
    } catch (Throwable $e) {
        error_log('Writer save failed: '.$e->getMessage());
        if($autosave)json_response(['ok'=>false,'error'=>'The draft could not be saved. Keep this page open and try again.'],500);
        flash('error','The draft could not be saved. Please try again.');redirect($id?url('/write/edit/'.$id):url('/write/new'));
    }
    if($autosave)json_response(['ok'=>true,'id'=>$saved['id'],'version'=>$saved['version']]);
    flash('success',$publish?($saved['status']==='scheduled'?'Publication scheduled.':'Published.'):'Private draft saved. The public version is unchanged.');
    redirect(url('/write/edit/'.$saved['id']));
}

if ($path === '/write/releases') {
    require_auth();
    $releases=db()->query("SELECT r.*,(SELECT COUNT(*) FROM media_release_payments p WHERE p.release_id=r.id) payment_count FROM media_releases r ORDER BY r.created_at DESC LIMIT 300")->fetchAll();
    $counts=['all'=>count($releases),'sent'=>0,'signed'=>0,'withdrawal_requested'=>0,'closed'=>0];foreach($releases as $r){if(isset($counts[$r['status']]))$counts[$r['status']]++;}
    render_write('releases',['writeTitle'=>'Consent & releases','releases'=>$releases,'counts'=>$counts]);exit;
}
if ($path === '/write/releases/new') {
    require_auth();
    if(is_post()){
        verify_csrf();$u=current_user();$title=trim((string)($_POST['title']??''));$context=trim((string)($_POST['context_note']??''));$purpose=trim((string)($_POST['purpose_note']??''));
        $allowPhoto=!empty($_POST['allow_photo']);$allowVideo=!empty($_POST['allow_video']);$allowAudio=!empty($_POST['allow_audio']);$allowQuotes=!empty($_POST['allow_quotes']);
        if($title===''||!($allowPhoto||$allowVideo||$allowAudio||$allowQuotes)){flash('error','Add a title and request at least one type of permission.');redirect(url('/write/releases/new'));}
        $attr=in_array(($_POST['requested_attribution']??'signer_choice'),['full_name','first_name','anonymous','signer_choice'],true)?(string)$_POST['requested_attribution']:'signer_choice';
        $exp=trim((string)($_POST['expires_on']??''));$expires=$exp!==''?date('Y-m-d 23:59:59',strtotime($exp)):null;$ref=make_reference('CONSENT');$token=bin2hex(random_bytes(32));
        $st=db()->prepare("INSERT INTO media_releases(reference,public_token,title,context_note,purpose_note,allow_photo,allow_video,allow_audio,allow_quotes,requested_attribution,status,expires_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'sent',?,?)");
        $st->execute([$ref,$token,$title,$context?:null,$purpose?:null,$allowPhoto?1:0,$allowVideo?1:0,$allowAudio?1:0,$allowQuotes?1:0,$attr,$expires,(int)$u['id']]);$id=(int)db()->lastInsertId();
        log_release_event($id,'consent_link_created','Private consent link created.','writer',(int)$u['id']);flash('success','Private consent link created.');redirect(url('/write/releases/'.$id));
    }
    render_write('release_form',['writeTitle'=>'New consent link']);exit;
}
if (preg_match('~^/write/releases/(\d+)$~',$path,$m)) {
    require_auth();$release=media_release_by_id((int)$m[1]);if(!$release)not_found();render_write('release_detail',['writeTitle'=>'Consent '.$release['reference'],'release'=>$release,'payments'=>media_release_payments((int)$release['id']),'events'=>media_release_events((int)$release['id'])]);exit;
}
if (preg_match('~^/write/releases/(\d+)/payment$~',$path,$m) && is_post()) {
    require_auth();verify_csrf();$u=current_user();$release=media_release_by_id((int)$m[1]);if(!$release)not_found();
    $method=in_array(($_POST['method']??''),['mpesa','bank'],true)?(string)$_POST['method']:'';$amount=(float)($_POST['amount']??0);$currency=strtoupper(trim((string)($_POST['currency']??'KES')));$ref=trim((string)($_POST['transaction_reference']??''));$institution=trim((string)($_POST['institution']??''));
    if($method===''||$amount<=0||!preg_match('/^[A-Z]{3}$/',$currency)||$ref===''){flash('error','Complete the payment method, amount, three-letter currency code and transaction reference.');redirect(url('/write/releases/'.$release['id']));}
    if($method==='mpesa'&&$currency!=='KES'){flash('error','M-Pesa trace records use KES. Use Bank for other currencies.');redirect(url('/write/releases/'.$release['id']));}
    if($method==='bank'&&$institution===''){flash('error','Enter the bank or institution for a bank transfer.');redirect(url('/write/releases/'.$release['id']));}
    try{$proof=!empty($_FILES['proof_file']['name'])?upload_release_proof($_FILES['proof_file']):null;}catch(Throwable $e){flash('error',$e->getMessage());redirect(url('/write/releases/'.$release['id']));}
    $paidRaw=trim((string)($_POST['paid_at']??''));$paidAt=$paidRaw!==''?date('Y-m-d H:i:s',strtotime($paidRaw)):date('Y-m-d H:i:s');
    $st=db()->prepare('INSERT INTO media_release_payments(release_id,method,amount,currency,recipient_name,recipient_destination,institution,transaction_reference,paid_at,proof_path,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([(int)$release['id'],$method,$amount,$currency,mb_substr(trim((string)($_POST['recipient_name']??'')),0,190)?:null,mb_substr(trim((string)($_POST['recipient_destination']??'')),0,190)?:null,$institution?:null,mb_substr($ref,0,190),$paidAt,$proof,trim((string)($_POST['notes']??''))?:null,(int)$u['id']]);
    log_release_event((int)$release['id'],'payment_recorded',strtoupper($method).' '.$currency.' '.number_format($amount,2).' · '.$ref,'writer',(int)$u['id']);flash('success','Payment trace saved.');redirect(url('/write/releases/'.$release['id']));
}
if (preg_match('~^/write/releases/(\d+)/close$~',$path,$m) && is_post()) {
    require_auth();verify_csrf();$u=current_user();$release=media_release_by_id((int)$m[1]);if(!$release)not_found();db()->prepare("UPDATE media_releases SET status='closed' WHERE id=?")->execute([(int)$release['id']]);log_release_event((int)$release['id'],'record_closed','Record closed by writer.','writer',(int)$u['id']);flash('success','Consent record closed.');redirect(url('/write/releases/'.$release['id']));
}
if (preg_match('~^/write/releases/proof/(\d+)$~',$path,$m)) {
    require_auth();
    $st=db()->prepare('SELECT * FROM media_release_payments WHERE id=? LIMIT 1');$st->execute([(int)$m[1]]);$pay=$st->fetch();
    if(!$pay||empty($pay['proof_path'])||!function_exists('media_private_path'))not_found();
    $proofPath=media_private_path((string)$pay['proof_path'],(string)app_config('media.driver','local'));
    if($proofPath===null)not_found();
    $downloadName='payment-proof-'.intval($pay['id']).'.'.pathinfo($proofPath,PATHINFO_EXTENSION);
    if(str_starts_with($proofPath,'s3-private://')){
        if(!function_exists('media_serve_private'))not_found();
        media_serve_private($proofPath,$downloadName);
    }
    $base=realpath(__DIR__.'/storage/release-proofs');$file=realpath(__DIR__.'/'.$proofPath);
    if(!$base||!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file))not_found();
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file)?:'application/octet-stream';
    header('Content-Type: '.$mime);header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="'.$downloadName.'"');header('Content-Length: '.filesize($file));readfile($file);exit;
}

if ($path === '/write/settings') {
    require_auth();
    if(is_post()){
        verify_csrf();
        $measurementId=trim((string)($_POST['google_ga4']??''));
        if($measurementId!==''&&!preg_match('/^G-[A-Z0-9]{4,20}$/D',$measurementId)){flash('error','Enter a valid GA4 measurement ID beginning G-, or leave it blank to disable analytics.');redirect(url('/write/settings'));}
        set_setting('google.analytics_enabled',!empty($_POST['google_analytics_enabled'])?'1':'0');
        set_setting('contact.recipient',trim((string)($_POST['contact_recipient']??'')));set_setting('mail.from',trim((string)($_POST['mail_from']??'')));set_setting('google.ga4',trim((string)($_POST['google_ga4']??'')));set_setting('google.search_verification',trim((string)($_POST['google_search_verification']??'')));set_setting('recaptcha.site_key',trim((string)($_POST['recaptcha_site_key']??'')));if(!empty($_POST['recaptcha_secret']))set_setting('recaptcha.secret',(string)$_POST['recaptcha_secret'],true);
        set_setting('openai.model',trim((string)($_POST['openai_model']??'gpt-5.4-mini')));if(!empty($_POST['openai_api_key']))set_setting('openai.api_key',(string)$_POST['openai_api_key'],true);
        set_setting('mpesa.environment',(string)($_POST['mpesa_environment']??'sandbox'));set_setting('mpesa.shortcode',trim((string)($_POST['mpesa_shortcode']??'')));set_setting('mpesa.transaction_type',(string)($_POST['mpesa_transaction_type']??'CustomerPayBillOnline'));foreach(['consumer_key','consumer_secret','passkey'] as $k)if(!empty($_POST['mpesa_'.$k]))set_setting('mpesa.'.$k,(string)$_POST['mpesa_'.$k],true);
        set_setting('pesapal.environment',(string)($_POST['pesapal_environment']??'sandbox'));set_setting('pesapal.ipn_id',trim((string)($_POST['pesapal_ipn_id']??'')));foreach(['consumer_key','consumer_secret'] as $k)if(!empty($_POST['pesapal_'.$k]))set_setting('pesapal.'.$k,(string)$_POST['pesapal_'.$k],true);
        set_setting('paypal.environment',(string)($_POST['paypal_environment']??'sandbox'));if(!empty($_POST['paypal_client_id']))set_setting('paypal.client_id',(string)$_POST['paypal_client_id'],true);if(!empty($_POST['paypal_secret']))set_setting('paypal.secret',(string)$_POST['paypal_secret'],true);
        try{if(($_POST['action']??'save')==='register_ipn'){$r=pesapal_register_ipn();if(!empty($r['ipn_id']))set_setting('pesapal.ipn_id',(string)$r['ipn_id']);flash('success','Settings saved and Pesapal IPN registered.');}else flash('success','Settings saved.');}catch(Throwable $e){flash('error','Settings saved, but IPN registration failed: '.$e->getMessage());}
        redirect(url('/write/settings'));
    }
    render_write('settings',['writeTitle'=>'Settings']);exit;
}

not_found();


