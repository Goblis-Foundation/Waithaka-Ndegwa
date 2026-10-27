<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
function url(string $p=''):string{return 'https://www.example.org/'.ltrim($p,'/');}
function app_config(string $key,mixed $default=null):mixed{return $default;}
function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function social_links():array{return ['LinkedIn'=>'https://linkedin.com/in/iwaithakaw','Facebook'=>'https://facebook.com/iwaithakaw','X'=>'https://x.com/iwaithakaw','Instagram'=>'https://instagram.com/iwaithakaw','YouTube'=>'https://youtube.com/@waithakandegwa','TikTok'=>'https://tiktok.com/@waithakandegwa'];}
require dirname(__DIR__).'/app/email_design.php';require dirname(__DIR__).'/app/location.php';require dirname(__DIR__).'/app/contact_experience.php';require dirname(__DIR__).'/app/client_contact.php';require dirname(__DIR__).'/app/reconciliation_register.php';
$n=0;function rc(bool $ok,string $why):void{global $n;$n++;if(!$ok)throw new RuntimeException($why);}
$html=email_design('Thank you for writing.','<p>Your message is recorded.</p>',['name'=>'Amina','reference'=>'WN-EXAMPLE']);
rc(str_contains($html,'brand-lockup.png?v=20261027')&&str_contains($html,'alt="Waithaka Ndegwa"'),'Approved actual logo, not a generated likeness.');
rc(str_contains($html,'#073b6a')&&str_contains($html,'#2f6f3e')&&str_contains($html,'#ffffff'),'Blue, green and white email design.');
rc(str_contains($html,'People · Ideas · Impact')&&!str_contains($html,'MY THOUGHTS · IDEAS INTO IMPACT'),'Slogan is not all capitals.');
rc(str_contains($html,'Hello Amina,')&&str_contains($html,'@iwaithakaw'),'Personal salutation and handle.');
foreach(social_links()as$name=>$link)rc(str_contains($html,$link),'Existing social destination retained.');
$text=email_design_text($html);rc(str_contains($text,'Waithaka Ndegwa')&&str_contains($text,'WN-EXAMPLE'),'Text alternative and reference remain useful without images.');
rc(!isset(contact_guides()['speaking'])&&count(contact_guides())===5,'Retired speaking invitation is not offered.');
rc(contact_draft_values(['enquiry_type'=>'speaking'])['enquiry_type']==='general','Old bookmarked purpose falls back safely.');
try{contact_guard_receive([],['enquiry_type'=>'speaking'],true,true);throw new RuntimeException('Retired purpose accepted');}catch(InvalidArgumentException){rc(true,'Server rejects a retired purpose before storing an enquiry.');}
$cfg=['enabled'=>true,'types'=>['media'=>['name'=>'Media & speaking','minutes'=>45,'approval'=>true]]];$changed=reconciliation_meeting_labels($cfg);rc($changed['types']['media']['name']==='Media & interviews'&&$changed['types']['media']['minutes']===45&&$changed['enabled'],'Only the obsolete label changes, not availability or meeting rules.');
$profile=client_contact_values(['country'=>'Kenya','county'=>'Nyeri','sub_county'=>'Kieni','city'=>'Endarasha','phone_code'=>'+254','phone'=>'0718751696']);rc($profile['phone']==='+254718751696'&&$profile['county']==='Nyeri','Client form reuses Kenyan location/phone rules.');
$foreign=client_contact_values(['country'=>'Uganda','county'=>'Nyeri','sub_county'=>'Kieni','region'=>'Central','district'=>'Kampala','city'=>'Kampala','phone_code'=>'+44','phone'=>'+447700900123']);rc($foreign['county']===''&&$foreign['sub_county']===''&&$foreign['region']==='Central'&&$foreign['phone']==='+447700900123','Residence and phone numbering remain independent; inactive Kenyan fields excluded.');
rc(count(location_kenya_counties())===47,'All 47 existing county options preserved.');
$css=file_get_contents(dirname(__DIR__).'/assets/css/brand-refresh.css');rc(str_contains($css,'grid-template-columns:repeat(3,minmax(0,1fr))')&&str_contains($css,'font-variant-numeric:tabular-nums'),'Equal visitor columns and stable number alignment.');
rc(str_contains($css,'body .site-footer .footer-note')&&str_contains($css,'@media(max-width:760px)'),'Shared views notice and mobile footer rules.');
rc(count(reconciliation_items())>=20,'No-drop register retains implemented and blocked requirements.');
echo 'PASS: '.$n." email branding, invitation removal, shared forms, footer and reconciliation assertions.\n";
