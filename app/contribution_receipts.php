<?php
declare(strict_types=1);

function support_text(mixed $value,string $label,int $limit,bool $required=false): string {
    if(!is_string($value)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value))throw new InvalidArgumentException('Review '.$label.'.');
    $value=trim($value);if(mb_strlen($value)>$limit||($required&&$value===''))throw new InvalidArgumentException('Review '.$label.'.');return $value;
}
function support_identity(array $in): array {
    $kind=$in['supporter_type']??'anonymous';$purpose=$in['support_purpose']??'support';
    if(!in_array($kind,['individual','organisation','anonymous'],true)||!in_array($purpose,['support','sponsorship'],true))throw new InvalidArgumentException('Choose who is supporting and the purpose.');
    $r=['type'=>$kind,'purpose'=>$purpose,'name'=>'Anonymous supporter','address'=>'','email'=>''];
    if($kind==='anonymous')return $r; // Discard hidden/stale identity fields, not merely conceal them.
    if(($in['receipt_details_ack']??'')!=='1')throw new InvalidArgumentException('Confirm that the receipt details may be saved privately.');
    if($kind==='organisation')$r['name']=support_text($in['organisation_name']??'','company or institution name',180,true);
    else{$first=support_text($in['supporter_first']??'','first name',120,true);$middle=support_text($in['supporter_middle']??'','middle name',120);$last=support_text($in['supporter_last']??'','last name',120,true);$r['name']=implode(' ',array_filter([$first,$middle,$last],static fn($x)=>$x!==''));}
    $r['address']=support_text($in['receipt_address']??'','billing address',1000);$r['email']=support_text($in['receipt_email']??'','accounts contact email',190);
    if($r['email']!==''&&!filter_var($r['email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid accounts contact email.');return $r;
}
function support_form_values(array $in): array {
    $out=[];foreach(['supporter_type'=>20,'support_purpose'=>20,'supporter_first'=>120,'supporter_middle'=>120,'supporter_last'=>120,'organisation_name'=>180,'receipt_address'=>1000,'receipt_email'=>190,'receipt_details_ack'=>1]as$key=>$limit)if(is_string($in[$key]??null))$out[$key]=mb_substr($in[$key],0,$limit);
    return ($out['supporter_type']??'anonymous')==='anonymous'?array_intersect_key($out,array_flip(['supporter_type','support_purpose'])):$out;
}
function support_purpose(array $identity): string {return ($identity['purpose']??'support')==='sponsorship'?'Sponsorship of independent writing':'Support for independent writing';}
function support_receipt_key(string $id): string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Receipt unavailable.');return 'giving.receipt.'.$id;}
function support_receipt_url(array $r): string {return url('/contribute/receipt/'.$r['id'].'/'.$r['token']);}
function support_receipt_new(array $identity,array $payment): array {
    $r=['id'=>bin2hex(random_bytes(16)),'token'=>bin2hex(random_bytes(32)),'identity'=>$identity,'created'=>time(),'issuer'=>'Isaac Waithaka Ndegwa']+$payment;
    comm_put(support_receipt_key($r['id']),$r);return $r;
}
function support_receipt_attach(int $id,array $identity): array {
    $q=db()->prepare('SELECT id,reference,method,amount,currency FROM contributions WHERE id=?');$q->execute([$id]);$payment=$q->fetch();if(!$payment)throw new RuntimeException('Contribution record unavailable.');
    $r=support_receipt_new($identity,['kind'=>'online','contribution_id'=>$id,'reference'=>$payment['reference'],'method'=>$payment['method'],'amount'=>$payment['amount'],'currency'=>$payment['currency']]);
    if($payment['method']==='mpesa'){$r['receiving_fingerprint']=hash('sha256','mpesa|'.setting('mpesa.shortcode',''));comm_put(support_receipt_key($r['id']),$r);}
    $_SESSION['support_receipts'][$payment['reference']]=['id'=>$r['id'],'token'=>$r['token']];$_SESSION['support_receipts']=array_slice($_SESSION['support_receipts'],-20,null,true);return $r;
}
function support_receipt_read(string $id,string $token): array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))throw new InvalidArgumentException('Receipt unavailable.');
    $r=comm_get(support_receipt_key($id));if(!$r||!hash_equals((string)($r['token']??''),$token))throw new InvalidArgumentException('Receipt unavailable.');return $r;
}
function support_receipt_state(array $r): array {
    if(!empty($r['voided_at']))return ['paid'=>false,'state'=>'void','at'=>null,'can_verify'=>false];
    if($r['kind']==='monthly'){
        try{$subscription=monthly_record($r['monthly_token']);}catch(InvalidArgumentException){return ['paid'=>false,'state'=>'review','at'=>null,'can_verify'=>false];}
        $match=($subscription['subscription']??'')===$r['subscription']&&hash_equals((string)($subscription['fingerprint']??''),$r['account_fingerprint'])&&empty($subscription['review']);
        return ['paid'=>$match&&($r['state']??'')==='verified','state'=>$match?($r['state']??'pending'):'review','at'=>$r['verified_at']??null,'can_verify'=>$match&&($r['state']??'')==='pending'];
    }
    if($r['kind']==='bank')return ['paid'=>($r['state']??'')==='verified','state'=>$r['state']??'pending','at'=>$r['verified_at']??null,'can_verify'=>($r['state']??'')==='pending'];
    $q=db()->prepare('SELECT c.reference,c.method,c.amount,c.currency,c.status,p.state,p.verification_level,p.provider_environment,p.verified_at FROM contributions c LEFT JOIN payment_reconciliation p ON p.contribution_id=c.id WHERE c.id=?');$q->execute([$r['contribution_id']]);$p=$q->fetch();
    $match=$p&&$p['reference']===$r['reference']&&$p['method']===$r['method']&&$p['currency']===$r['currency']&&payment_equal($p['amount'],$r['amount']);
    $paid=$match&&$p['status']==='completed'&&$p['state']==='verified'&&$p['provider_environment']==='live'&&($p['verification_level']==='amount_currency_status'||($p['verification_level']==='status_only'&&$r['method']==='mpesa'&&!empty($r['statement_verified'])));
    $canVerify=$match&&!$paid&&$r['method']==='mpesa'&&$p['status']==='completed'&&$p['state']==='verified'&&$p['provider_environment']==='live'&&$p['verification_level']==='status_only';
    $state=!$match?'review':($paid?'verified':(in_array($p['state'],['review','reversed'],true)?$p['state']:($canVerify?'awaiting_statement':(in_array($p['status'],['failed','cancelled'],true)?$p['status']:'pending'))));
    return ['paid'=>$paid,'state'=>$state,'at'=>$paid?($r['verified_at']??$p['verified_at']):null,'can_verify'=>$canVerify];
}
function support_bank_start(array $in,array $identity): array {
    $currency=$in['bank_currency']??'';$accounts=wise_giving_public();if(!is_string($currency)||!isset($accounts[$currency]))throw new InvalidArgumentException('Choose an available receiving currency.');
    $amount=payment_decimal($in['bank_amount']??'');$minor=payment_minor_units($amount);if($minor<100||$minor>100000000)throw new InvalidArgumentException('Enter a bank transfer amount between 1 and 1,000,000.');
    return support_receipt_new($identity,['kind'=>'bank','reference'=>make_reference('BANK'),'method'=>'wise','amount'=>$amount,'currency'=>$currency,'state'=>'pending','account'=>$accounts[$currency],'account_fingerprint'=>hash('sha256',json_encode($accounts[$currency],JSON_THROW_ON_ERROR))]);
}
function support_bank_verify(string $id,array $in,int $uid): void {
    if($uid<=0||$uid!==(int)(comm_config()['owner']??0))throw new InvalidArgumentException('Owner access required.');
    comm_lock('giving.bank.receipts',static function()use($id,$in,$uid){
        $r=comm_get(support_receipt_key($id));if(!$r||empty(support_receipt_state($r)['can_verify']))throw new InvalidArgumentException('Choose a payment eligible for statement verification.');
        $tx=support_text($in['transaction']??'','statement reference',180,true);$amount=payment_decimal($in['verified_amount']??'');
        if(($in['bank_ack']??'')!=='1'||($in['verified_currency']??'')!==$r['currency']||!payment_equal($amount,$r['amount']))throw new InvalidArgumentException('Check the statement, amount and currency against this record.');
        if($r['kind']==='bank'){$account=wise_giving_public()[$r['currency']]??null;if(!$account||!hash_equals($r['account_fingerprint'],hash('sha256',json_encode($account,JSON_THROW_ON_ERROR))))throw new InvalidArgumentException('Receiving details changed. Reconcile the original account before issuing a receipt.');}
        elseif($r['kind']==='monthly'){if(!hash_equals($r['account_fingerprint'],paypal_connection_fingerprint()))throw new InvalidArgumentException('Review the original PayPal receiving account before issuing this receipt.');}
        else{$short=setting('mpesa.shortcode','');if($short===''||empty($r['receiving_fingerprint'])||!hash_equals($r['receiving_fingerprint'],hash('sha256','mpesa|'.$short)))throw new InvalidArgumentException('Verify the original receiving M-Pesa account first.');$r['account_fingerprint']=hash('sha256','mpesa|'.$short);}
        $key='giving.bank.transaction.'.hash('sha256',$r['account_fingerprint'].'|'.$tx);$pdo=db();$pdo->beginTransaction();
        try{if(comm_get($key))throw new InvalidArgumentException('This statement transaction already has a receipt.');
            $r['state']='verified';$r['statement_verified']=true;$r['verified_at']=time();$r['verified_by']=$uid;$r['statement_reference']=$tx;comm_put($key,['receipt'=>$id]);comm_put(support_receipt_key($id),$r);$pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function support_monthly_receipt(string $token): array {
    return comm_lock('giving.monthly.receipts',static function()use($token){
        $subscription=monthly_record($token);$last=$subscription['last_payment']??[];
        if(empty($subscription['identity'])||empty($subscription['subscription'])||!empty($subscription['review'])||($last['currency']??'')!==$subscription['currency']||!payment_equal($last['amount']??null,$subscription['amount'])||!is_string($last['time']??null)||!($paidAt=strtotime($last['time']))||$paidAt>time()+60)throw new InvalidArgumentException('A matching reported instalment and saved receipt identity are required first.');
        $index='giving.monthly.receipt.'.hash('sha256',$subscription['fingerprint'].'|'.$subscription['subscription'].'|'.$last['time']);$prior=comm_get($index);if($prior)return support_receipt_read($prior['id'],$prior['token']);
        $pdo=db();$pdo->beginTransaction();try{$r=support_receipt_new($subscription['identity'],['kind'=>'monthly','reference'=>make_reference('MONTHLY'),'method'=>'paypal','amount'=>$last['amount'],'currency'=>$last['currency'],'state'=>'pending','monthly_token'=>$token,'subscription'=>$subscription['subscription'],'account_fingerprint'=>$subscription['fingerprint'],'reported_payment_at'=>$paidAt]);comm_put($index,['id'=>$r['id'],'token'=>$r['token']]);$pdo->commit();return $r;}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    });
}
function support_receipt_void(string $id,array $in,int $uid): void {
    if($uid<=0||$uid!==(int)(comm_config()['owner']??0))throw new InvalidArgumentException('Owner access required.');
    $reason=support_text($in['void_reason']??'','void reason',1000,true);if(($in['void_ack']??'')!=='1')throw new InvalidArgumentException('Confirm voiding this receipt record.');
    comm_lock('giving.bank.receipts',static function()use($id,$reason,$uid){$r=comm_get(support_receipt_key($id));if(!$r)throw new InvalidArgumentException('Receipt unavailable.');$r['voided_at']=time();$r['voided_by']=$uid;$r['void_reason']=$reason;comm_put(support_receipt_key($id),$r);});
}
function support_receipts_dispatch(string $path): bool {
    $owner=$path==='/write/contribution-receipts';
    if(!$owner&&!preg_match('~^/contribute/receipt/([a-f0-9]{32})/([a-f0-9]{64})$~D',$path,$m))return false;
    private_response_headers();journeys_method($owner?['GET','HEAD','POST']:['GET','HEAD']);
    if($owner){require_auth();$uid=(int)current_user()['id'];if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}$error='';
        if(is_post()){verify_csrf();try{if(($_POST['action']??'verify')==='void'){support_receipt_void((string)($_POST['receipt_id']??''),$_POST,$uid);flash('success','Receipt voided. The payment and statement claim remain unchanged.');}else{support_bank_verify((string)($_POST['receipt_id']??''),$_POST,$uid);flash('success','Statement checked and receipt available.');}redirect(url($path));}catch(InvalidArgumentException|RuntimeException $e){$error=$e->getMessage();}}
        $rows=comm_records('giving.receipt',2000);usort($rows,static fn($a,$b)=>$b['created']<=>$a['created']);$total=count($rows);$page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]])?:1;$rows=array_slice($rows,($page-1)*20,20);render_write('contribution_receipts',['writeTitle'=>'Support receipts','rows'=>$rows,'error'=>$error,'receiptPage'=>$page,'receiptTotal'=>$total]);return true;
    }
    try{$receipt=support_receipt_read($m[1],$m[2]);$receiptState=support_receipt_state($receipt);}catch(InvalidArgumentException){http_response_code(404);echo 'Receipt unavailable.';return true;}
    render('contribution_receipt',['page'=>'contribute','receipt'=>$receipt,'receiptState'=>$receiptState,'seo'=>['title'=>$receiptState['paid']?'Support receipt':'Support record','description'=>'Private support record.','robots'=>'noindex,nofollow,noarchive','canonical'=>url('/contribute')]]);return true;
}
