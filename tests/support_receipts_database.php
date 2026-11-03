<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
require __DIR__.'/prepare.php';$settings=wk_test_database_settings();$database=(string)(getenv('DB_DATABASE')?:getenv('WK_TEST_DB_NAME'));wk_test_assert_database_name($database);
$CONFIG=['app_env'=>'testing','base_url'=>'https://example.invalid','app_key'=>'synthetic-receipts-only','db'=>$settings+['name'=>$database]];$APP_INSTALLED=true;$_SESSION=[];
require dirname(__DIR__).'/app/functions.php';require dirname(__DIR__).'/app/integrations.php';require dirname(__DIR__).'/app/communication.php';
$BANK=['USD'=>['enabled'=>true,'account_name'=>'Synthetic receiving account','account_number'=>'TEST-ACCOUNT']];
function wise_giving_public(): array {global $BANK;return $BANK;}
function sdcheck(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function sdreject(callable $f): void {try{$f();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected receipt action rejection.');}
$oldConfig=comm_config();$oldShort=db()->query("SELECT setting_value,is_secret FROM settings WHERE setting_key='mpesa.shortcode'")->fetch();$ids=[];$records=[];$keysBefore=db()->query("SELECT setting_key FROM settings WHERE setting_key LIKE 'communication.giving.%'")->fetchAll(PDO::FETCH_COLUMN);$mailBefore=(int)db()->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();
try{
 comm_put('config',['owner'=>1]);set_setting('mpesa.shortcode','SYNTHETIC-ACCOUNT');
 $identity=support_identity(['supporter_type'=>'organisation','support_purpose'=>'sponsorship','organisation_name'=>'PRIVATE_SYNTHETIC_INSTITUTE','receipt_address'=>'PRIVATE_SYNTHETIC_ADDRESS','receipt_email'=>'fixture@example.invalid','receipt_details_ack'=>'1']);
 db()->prepare('INSERT INTO contributions(reference,method,amount,currency,status) VALUES(?,?,?,?,?)')->execute(['CON-TEST-'.strtoupper(bin2hex(random_bytes(8))),'paypal','25.00','USD','initiated']);$id=(int)db()->lastInsertId();$ids[]=$id;
 db()->prepare("INSERT INTO payment_reconciliation(contribution_id,state,provider_environment,verification_level) VALUES(?,'pending','live','none')")->execute([$id]);
 $r=support_receipt_attach($id,$identity);$records[]=$r;
 sdcheck(support_receipt_read($r['id'],$r['token'])['identity']===$identity,'Private institution snapshot survives encrypted storage');
 $q=db()->prepare('SELECT setting_value,is_secret FROM settings WHERE setting_key=?');$q->execute(['communication.'.support_receipt_key($r['id'])]);$raw=$q->fetch();sdcheck((int)$raw['is_secret']===1&&!str_contains($raw['setting_value'],'PRIVATE_SYNTHETIC'),'No identity plaintext at rest');
 sdreject(fn()=>support_receipt_read($r['id'],str_repeat('0',64)));
 sdcheck(!support_receipt_state($r)['paid'],'Checkout intent cannot issue receipt');
 db()->prepare("UPDATE contributions SET status='completed' WHERE id=?")->execute([$id]);db()->prepare("UPDATE payment_reconciliation SET state='verified',verification_level='amount_currency_status',verified_at=NOW() WHERE contribution_id=?")->execute([$id]);
 sdcheck(support_receipt_state($r)['paid'],'Matching live verified payment permits receipt');
 db()->prepare("UPDATE payment_reconciliation SET provider_environment='sandbox' WHERE contribution_id=?")->execute([$id]);sdcheck(!support_receipt_state($r)['paid'],'Sandbox never issues live paid receipt');
 db()->prepare("UPDATE payment_reconciliation SET provider_environment='live',state='reversed' WHERE contribution_id=?")->execute([$id]);sdcheck(!support_receipt_state($r)['paid'],'Reversal invalidates receipt');
 db()->prepare("UPDATE payment_reconciliation SET state='verified' WHERE contribution_id=?")->execute([$id]);db()->prepare("UPDATE contributions SET amount='26.00' WHERE id=?")->execute([$id]);sdcheck(!support_receipt_state($r)['paid'],'Changed financial identity fails closed');
 db()->prepare("UPDATE contributions SET amount='25.00',method='mpesa' WHERE id=?")->execute([$id]);$r['method']='mpesa';$r['receiving_fingerprint']=hash('sha256','mpesa|SYNTHETIC-ACCOUNT');comm_put(support_receipt_key($r['id']),$r);db()->prepare("UPDATE payment_reconciliation SET verification_level='status_only' WHERE contribution_id=?")->execute([$id]);
 sdcheck(!support_receipt_state($r)['paid']&&support_receipt_state($r)['can_verify'],'M-Pesa status-only evidence awaits statement');
 support_bank_verify($r['id'],['transaction'=>'SYNTHETIC-MPESA-'.bin2hex(random_bytes(6)),'verified_amount'=>'25.00','verified_currency'=>'USD','bank_ack'=>'1'],1);$r=support_receipt_read($r['id'],$r['token']);sdcheck(support_receipt_state($r)['paid'],'Separate statement verification completes M-Pesa receipt');
 db()->prepare("UPDATE payment_reconciliation SET state='reversed' WHERE contribution_id=?")->execute([$id]);sdcheck(!support_receipt_state($r)['paid'],'Statement evidence cannot override later provider reversal');
 $bank=support_bank_start(['bank_currency'=>'USD','bank_amount'=>'50.00'],$identity);$records[]=$bank;sdcheck(!support_receipt_state($bank)['paid'],'Bank reference is not paid');
 $proof=['transaction'=>'SYNTHETIC-BANK-'.bin2hex(random_bytes(6)),'verified_amount'=>'50.00','verified_currency'=>'USD','bank_ack'=>'1'];
 sdreject(fn()=>support_bank_verify($bank['id'],$proof,2));sdreject(fn()=>support_bank_verify($bank['id'],array_replace($proof,['verified_amount'=>'49.00']),1));
 support_bank_verify($bank['id'],$proof,1);$bank=support_receipt_read($bank['id'],$bank['token']);sdcheck(support_receipt_state($bank)['paid'],'Owner matching statement issues bank receipt');
 $duplicate=support_bank_start(['bank_currency'=>'USD','bank_amount'=>'50.00'],$identity);$records[]=$duplicate;sdreject(fn()=>support_bank_verify($duplicate['id'],$proof,1));sdcheck(!support_receipt_state(support_receipt_read($duplicate['id'],$duplicate['token']))['paid'],'Duplicate statement leaves second record unpaid');
 support_receipt_void($bank['id'],['void_ack'=>'1','void_reason'=>'Synthetic correction'],1);sdcheck(!support_receipt_state(support_receipt_read($bank['id'],$bank['token']))['paid'],'Voided receipt cannot claim paid');sdreject(fn()=>support_bank_verify($duplicate['id'],$proof,1));
 $BANK['USD']['account_number']='CHANGED-SYNTHETIC';sdreject(fn()=>support_bank_verify($duplicate['id'],array_replace($proof,['transaction'=>'NEW-SYNTHETIC']),1));
 $monthlyToken=bin2hex(random_bytes(32));$monthly=['token'=>$monthlyToken,'id'=>'MONTHLY-SYNTHETIC','subscription'=>'I-SYNTHETIC','fingerprint'=>paypal_connection_fingerprint(),'identity'=>$identity,'amount'=>'25.00','currency'=>'USD','status'=>'ACTIVE'];monthly_save($monthly);sdreject(fn()=>support_monthly_receipt($monthlyToken));
 $monthly['last_payment']=['amount'=>'25.00','currency'=>'USD','time'=>gmdate('Y-m-d\\TH:i:s\\Z',time()-3600)];monthly_save($monthly);$monthlyReceipt=support_monthly_receipt($monthlyToken);sdcheck(!support_receipt_state($monthlyReceipt)['paid'],'Reported recurring payment is not statement proof');sdcheck(support_monthly_receipt($monthlyToken)['id']===$monthlyReceipt['id'],'Repeated instalment requests reuse one private record');
 support_bank_verify($monthlyReceipt['id'],['transaction'=>'SYNTHETIC-RECURRING-'.bin2hex(random_bytes(6)),'verified_amount'=>'25.00','verified_currency'=>'USD','bank_ack'=>'1'],1);sdcheck(support_receipt_state(support_receipt_read($monthlyReceipt['id'],$monthlyReceipt['token']))['paid'],'Matching recurring statement permits receipt');$monthly['review']='Refund notice';monthly_save($monthly);sdcheck(!support_receipt_state(support_receipt_read($monthlyReceipt['id'],$monthlyReceipt['token']))['paid'],'Recurring refund notice pauses verified receipt');
 sdcheck((int)db()->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$mailBefore,'Receipt operations never subscribe or enqueue mail');
}finally{
 foreach($ids as$id)db()->prepare('DELETE FROM contributions WHERE id=?')->execute([$id]);
 $keysAfter=db()->query("SELECT setting_key FROM settings WHERE setting_key LIKE 'communication.giving.%'")->fetchAll(PDO::FETCH_COLUMN);foreach(array_diff($keysAfter,$keysBefore)as$key)db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);
 comm_put('config',$oldConfig);if($oldShort)db()->prepare('UPDATE settings SET setting_value=?,is_secret=? WHERE setting_key=?')->execute([$oldShort['setting_value'],$oldShort['is_secret'],'mpesa.shortcode']);else db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['mpesa.shortcode']);
}
echo "PASS: private encrypted receipts, capability checks, verified live payment gates, statement matching, duplicate protection, voiding and reversal.\n";
