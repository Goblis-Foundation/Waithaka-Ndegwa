<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/contribution_receipts.php';
function srcheck(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
function srreject(callable $f): void {try{$f();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected invalid receipt details to be rejected.');}
$org=['supporter_type'=>'organisation','support_purpose'=>'sponsorship','organisation_name'=>'Synthetic Institute & Company','receipt_address'=>"Private synthetic address\nNairobi",'receipt_email'=>'accounts@example.invalid','receipt_details_ack'=>'1'];
$r=support_identity($org);srcheck($r['name']==='Synthetic Institute & Company'&&$r['type']==='organisation'&&$r['purpose']==='sponsorship','Institution payer and sponsorship preserved');
$person=support_identity(['supporter_type'=>'individual','supporter_first'=>'Isaac','supporter_middle'=>'Waithaka','supporter_last'=>'Ndegwa','receipt_details_ack'=>'1']);srcheck($person['name']==='Isaac Waithaka Ndegwa','Three names preserve canonical identity');
$anon=support_identity(array_replace($org,['supporter_type'=>'anonymous']));srcheck($anon['name']==='Anonymous supporter'&&$anon['email']===''&&$anon['address']==='','Anonymous discards stale private details');
srcheck(support_identity([])['type']==='anonymous','Old forms preserve anonymous default');
foreach([['organisation_name'=>''],['receipt_details_ack'=>''],['receipt_email'=>'not-an-email'],['supporter_type'=>[]],['support_purpose'=>'advertising'],['organisation_name'=>str_repeat('x',181)],['organisation_name'=>"bad\0value"]]as$bad)srreject(fn()=>support_identity(array_replace($org,$bad)));
srreject(fn()=>support_identity(['supporter_type'=>'individual','supporter_first'=>'Isaac','receipt_details_ack'=>'1']));
$values=support_form_values(['supporter_type'=>'anonymous','receipt_email'=>'PRIVATE@example.invalid','organisation_name'=>'PRIVATE']);srcheck(!isset($values['receipt_email'],$values['organisation_name']),'Anonymous form retention excludes hidden private fields');
srcheck(strlen(support_form_values(['supporter_type'=>'organisation','organisation_name'=>str_repeat('x',1000)])['organisation_name'])===180,'Form retention bounded before validation');
srcheck(support_purpose($r)==='Sponsorship of independent writing','Correct receipt purpose');
srreject(fn()=>support_receipt_key('../secret'));srreject(fn()=>support_receipt_read(str_repeat('a',32),'short'));
echo "PASS: individual/institution receipt identity, anonymity, validation, bounded retention and capability shape.\n";
