<?php
declare(strict_types=1);
$path=dirname(__DIR__).'/app/content/education-research-opportunities-20261106.json';
$data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};
$ok(($data['release']??'')==='education-research-opportunities-20261106','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===6,'Six education and research entries');
$expectedTypes=['scholarship'=>4,'research'=>2];$seen=['scholarship'=>0,'research'=>0];$slugs=[];
$allowed=['cscuk.fcdo.gov.uk','erasmus-plus.ec.europa.eu','mastercardfdn.org','training.aercafrica.org','aercafrica.org','www.twas.org'];
foreach($entries as$entry){
    $slug=$entry['slug']??'';$ok(is_string($slug)&&preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/D',$slug)===1,'Valid slug '.$slug);$ok(!isset($slugs[$slug]),'Unique slug '.$slug);$slugs[$slug]=true;
    foreach(['title','organisation','summary','support','deadline_label','countries','apply_url','deadline_mode','sectors']as$key)$ok(is_string($entry[$key]??null)&&trim($entry[$key])!=='',$slug.' has '.$key);
    $type=$entry['type']??'';if(isset($seen[$type]))$seen[$type]++;else$fail[]=$slug.' has expected education/research type';
    $mode=$entry['deadline_mode']??'';$ok(in_array($mode,['fixed','unknown'],true),$slug.' valid deadline mode');
    if($mode==='fixed')$ok(is_string($entry['deadline_iso']??null)&&$entry['deadline_iso']!=='',$slug.' fixed timestamp');
    if(($entry['deadline_date']??'')!=='')$ok(preg_match('/^202[67]-\d{2}-\d{2}$/D',$entry['deadline_date'])===1,$slug.' valid date-only deadline');
    foreach(['benefits','eligibility','conditions','steps']as$key)$ok(count($entry[$key]??[])>=2,$slug.' detailed '.$key);
    foreach(['context','fit','strategy','commitment']as$key)$ok(mb_strlen((string)($entry['guide'][$key]??''))>=180,$slug.' substantive '.$key);
    $ok(count($entry['guide']['prepare']??[])>=3,$slug.' preparation checklist');
    foreach(array_merge([['apply',$entry['apply_url']]],$entry['sources']??[])as$source){$host=parse_url($source[1]??'',PHP_URL_HOST);$ok(is_string($host)&&in_array($host,$allowed,true),$slug.' approved source host '.(string)$host);}
}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/education_research_opportunities_release.php';
$ok(isset(desk_types()['research']),'Research is a first-class opportunity type');
foreach($entries as$entry){
    try{
        $input=education_research_opportunity_input($entry,$data['checked_on']);$fields=desk_fields($input,'opportunity',true);
        $ok(($fields['instrument']??null)==='',$entry['slug'].' keeps long support wording outside legacy instrument field');
        $body=opportunity_guide_body($entry,$entry['guide'],$data['guidance_note']);
        $ok(substr_count($body,'<h2>')>=9&&str_contains($body,'Sources and official information'),$entry['slug'].' renders complete detail guide');
        $ok(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))>=300,$entry['slug'].' guide has useful depth');
    }catch(Throwable $e){$fail[]=$entry['slug'].' Publishing Desk contract: '.$e->getMessage();}
}
$view=(string)file_get_contents(dirname(__DIR__).'/views/desk/index.php');$routes=(string)file_get_contents(dirname(__DIR__).'/app/publishing_desk_routes.php');
$ok(str_contains($view,'Start with what you are looking for.')&&str_contains($view,'Scholarships & study')&&str_contains($view,'Research opportunities')&&str_contains($view,'For Kenyan applicants'),'Compact landing exposes education, research and Kenya-first routes');
$ok(str_contains($view,'/opportunities?browse=all')&&str_contains($routes,'$pageSize=$landing?6:18'),'Unfiltered landing is capped at six while full directory remains available');
$ok(str_contains($view,"'research'=>'Research'")&&str_contains($view,"'scholarship'=>'Scholarships'"),'Category navigation includes research and scholarships');
foreach($expectedTypes as$type=>$count)$ok($seen[$type]===$count,'Expected '.$count.' '.$type.' entries');
if($fail){fwrite(STDERR,"FAIL: ".implode('; ',$fail)."\n");exit(1);}echo "PASS: education, scholarship and research opportunities are source-bounded, substantive and deadline-aware.\n";
