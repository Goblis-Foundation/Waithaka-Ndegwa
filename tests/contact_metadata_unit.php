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

$valid=['first_name'=>'Amina','last_name'=>'Fixture','email'=>'amina@example.invalid','phone_code'=>'+254','phone'=>'0712345678','country'=>'Kenya','county'=>'Nyeri','sub_county'=>'Kieni West','ward'=>'Mweiga','city'=>'Endarasha','enquiry_type'=>'general','message'=>'A genuine synthetic enquiry with enough context.'];
$normalized=contact_required_values($valid);cm_check($normalized['phone']==='+254712345678'&&$normalized['country']==='Kenya','Kenya default calling code and national number normalize correctly.');
foreach(array_keys($valid)as$key){$copy=$valid;unset($copy[$key]);$blocked=false;try{contact_required_values($copy);}catch(InvalidArgumentException){$blocked=true;}cm_check($blocked,'Missing public contact field is rejected on the server: '.$key);}
$foreign=array_replace($valid,['country'=>'United Kingdom','phone_code'=>'+44','phone'=>'+447123456789','region'=>'England','district'=>'Westminster','city'=>'London']);$parsed=contact_required_values($foreign);cm_check($parsed['county']===''&&$parsed['ward']===''&&$parsed['region']==='England','Only applicable international administrative fields enter the record.');
foreach(['region','district','city']as$key){$copy=$foreign;$copy[$key]='';$blocked=false;try{contact_required_values($copy);}catch(InvalidArgumentException){$blocked=true;}cm_check($blocked,'International field is mandatory: '.$key);}
$copy=$valid;$copy['phone']='+447123456789';$blocked=false;try{contact_required_values($copy);}catch(InvalidArgumentException){$blocked=true;}cm_check($blocked,'Calling code cannot contradict the full phone number.');
$review=['state'=>'released','reviewed_by'=>42,'reviewed_at'=>time(),'review_note'=>'Reviewed the exact enquiry','email_consent'=>true];cm_check(contact_guard_email_approved($review,42),'Current owner can approve exact contact emails.');foreach([[],array_replace($review,['email_consent'=>false]),array_replace($review,['reviewed_by'=>43]),array_replace($review,['state'=>'held'])]as$unapproved)cm_check(!contact_guard_email_approved($unapproved,42),'Old automatic/other-owner/unapproved states cannot authorize email.');
$contactValues=$valid;$contactReceipt=null;ob_start();include dirname(__DIR__).'/views/contact.php';$html=ob_get_clean();$dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);foreach(['first_name','last_name','email','phone_code','phone','country','county','sub_county','ward','city','message','enquiry_type']as$name)cm_check($xp->query('//*[@name="'.$name.'" and @required]')->length===1,'Required attribute is present for '.$name);
cm_check($xp->query('//details[contains(@class,"contact-extra")]')->length===0,'Phone and country are never hidden in optional details.');cm_check($xp->query('//*[@name="region" and @disabled]')->length===1,'Inactive foreign fields do not block a Kenyan submission.');
echo 'PASS: '.$n." contact validation, visible mandatory fields, international switching and owner-email approval checks.\n";
