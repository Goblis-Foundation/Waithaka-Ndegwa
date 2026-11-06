<?php
declare(strict_types=1);
$path=dirname(__DIR__).'/app/content/research-opportunities-20261106.json';
$data=json_decode((string)file_get_contents($path),true,96,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $value,string $label)use(&$fail){if(!$value)$fail[]=$label;};
$ok(($data['release']??'')==='research-opportunities-20261106','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===2,'Two publishable researched entries');
$ok(count($data['held']??[])===2,'Two held research items retained outside active publication');
$expected=['ashoka-fellowship-east-africa-rolling-nomination','ilo-safe-labour-migration-writing-competitions-2026'];
$ok(array_column($entries,'slug')===$expected,'Stable researched slugs');
$allowed=['www.ashoka.org','www.ilo.org'];
foreach($entries as$entry){
    foreach(['title','organisation','summary','support','deadline_label','countries','apply_url','deadline_mode']as$key)$ok(is_string($entry[$key]??null)&&trim($entry[$key])!=='',$entry['slug'].' has '.$key);
    $ok(in_array($entry['deadline_mode'],['rolling','unknown'],true),$entry['slug'].' uses honest deadline precision');
    if($entry['slug']==='ilo-safe-labour-migration-writing-competitions-2026')$ok(($entry['deadline_date']??'')==='2026-11-25'&&($entry['deadline_iso']??null)===null,'ILO date-only deadline is not given an invented cutoff time');
    if($entry['slug']==='ashoka-fellowship-east-africa-rolling-nomination')$ok(($entry['deadline_date']??'')===''&&($entry['deadline_iso']??null)===null,'Ashoka rolling nomination has no invented deadline');
    foreach(['benefits','eligibility','conditions','steps']as$key)$ok(count($entry[$key]??[])>=3,$entry['slug'].' substantive '.$key);
    foreach(['context','fit','strategy','commitment']as$key)$ok(mb_strlen((string)($entry['guide'][$key]??''))>=180,$entry['slug'].' substantive '.$key);
    $ok(count($entry['guide']['prepare']??[])>=3,$entry['slug'].' preparation checklist');
    foreach(array_merge([['apply',$entry['apply_url']]],$entry['sources']??[])as$source){$host=parse_url($source[1]??'',PHP_URL_HOST);$ok(is_string($host)&&in_array($host,$allowed,true),$entry['slug'].' approved official host '.(string)$host);}
}
$held=array_column($data['held']??[],null,'title');
$ok(($held['Global Innovation Fund']['status']??'')==='closed_watch','Closed GIF is not published as open');
$ok(($held['Pan-African Social Enterprise Summit 2026']['status']??'')==='verification_needed','Conflicting summit date is held for verification');
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/research_opportunities_release.php';
foreach($entries as$entry){
    try{
        $input=research_opportunity_input($entry,$data['checked_on']);$fields=desk_fields($input,'opportunity',true);
        $ok(($fields['instrument']??null)==='',$entry['slug'].' respects legacy Publishing Desk instrument limit');
        $body=opportunity_guide_body($entry,$entry['guide'],$data['guidance_note']);
        $ok(substr_count($body,'<h2>')>=9&&str_contains($body,'Sources and official information'),$entry['slug'].' complete guide renders');
        $ok(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))>=300,$entry['slug'].' guide is substantive');
    }catch(Throwable $e){$fail[]=$entry['slug'].' contract: '.$e->getMessage();}
}
if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: 6 November opportunity research is source-bounded, deduplicated and deadline-precise.\n";
