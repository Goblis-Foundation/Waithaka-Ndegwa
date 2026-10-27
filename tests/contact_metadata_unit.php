<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$CONFIG=['app_env'=>'testing','base_url'=>'https://www.example.org','timezone'=>'Africa/Nairobi','app_key'=>'fixture-only-contact-metadata-key'];$APP_INSTALLED=false;$_SESSION=[];
require_once dirname(__DIR__).'/app/functions.php';
require_once dirname(__DIR__).'/app/reader.php';
require_once dirname(__DIR__).'/app/contact_experience.php';
require_once dirname(__DIR__).'/app/location.php';
function comm_config(): array {return ['enabled'=>false];}
$n=0;
function cm_check(bool $ok,string $why):void{global $n;$n++;if(!$ok)throw new RuntimeException($why);}
foreach(['general','media','speaking']as$purpose){
    $contactValues=['enquiry_type'=>$purpose,'country'=>'Kenya','phone_code'=>'+254'];$contactReceipt=null;
    // Simulate a legacy controller description. The real view must replace it
    // before the shared layout emits description, OpenGraph and Twitter tags.
    $seo=['title'=>'Contact','description'=>'Legacy speaking invitations','canonical'=>'https://www.example.org/contact'];
    ob_start();require dirname(__DIR__).'/views/contact.php';$body=(string)ob_get_clean();
    cm_check(!preg_match('/speaking invitations?/i',$seo['description']),'Retired invitation must not survive in rendered metadata.');
    cm_check(str_contains($seo['description'],'media enquiries'),'Media interviews remain available.');
    cm_check(!str_contains($body,'value="speaking"')&&!str_contains($body,'Invite me to speak'),'Retired choices do not return through bookmarks.');
    cm_check(str_contains($body,'name="county"')&&str_contains($body,'name="region"')&&str_contains($body,'name="phone_code"'),'Shared location and telephone controls remain intact.');
    cm_check($seo['canonical']==='https://www.example.org/contact','Canonical URL remains unchanged.');
}
echo 'PASS: '.$n." real contact-view metadata, purpose fallback and shared-form assertions. No database or network requests.\n";
