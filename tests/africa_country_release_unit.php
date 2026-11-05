<?php
declare(strict_types=1);
$path=dirname(__DIR__).'/app/content/africa-country-opportunities-20261105.json';
$data=json_decode((string)file_get_contents($path),true,96,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};
$ok(($data['release']??'')==='africa-country-opportunities-20261105','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===7,'Seven reviewed expansion entries');
$slugs=[];foreach($entries as$entry){
    $slug=$entry['slug']??'';$ok(is_string($slug)&&preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/D',$slug)===1,'Valid slug '.$slug);$ok(!isset($slugs[$slug]),'Unique slug '.$slug);$slugs[$slug]=true;
    foreach(['title','organisation','summary','support','deadline_label','countries','apply_url']as$key)$ok(is_string($entry[$key]??null)&&trim($entry[$key])!=='',$slug.' has '.$key);
    $ok(filter_var($entry['apply_url']??'',FILTER_VALIDATE_URL)!==false,$slug.' application URL');
    $ok(count($entry['benefits']??[])>=2&&count($entry['eligibility']??[])>=2&&count($entry['conditions']??[])>=2&&count($entry['steps']??[])>=2,$slug.' detailed programme sections');
    $ok(count($entry['guide']['prepare']??[])>=3,$slug.' preparation checklist');
    foreach(['context','fit','strategy','commitment']as$key)$ok(mb_strlen((string)($entry['guide'][$key]??''))>=180,$slug.' substantive '.$key);
    foreach($entry['sources']??[]as$source)$ok(isset($source[1])&&str_starts_with($source[1],'https://www.undp.org/'),$slug.' uses official UNDP source');
}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/africa_country_opportunities_release.php';
foreach($entries as$entry){
    try{$input=africa_country_opportunity_input($entry,$data['checked_on']);$fields=desk_fields($input,'opportunity',true);$ok(($fields['instrument']??null)==='',$entry['slug'].' fits the Publishing Desk field contract without duplicating support into instrument');}
    catch(Throwable $e){$fail[]=$entry['slug'].' Publishing Desk contract: '.$e->getMessage();}
}
$fixed=array_values(array_filter($entries,static fn($e)=>$e['deadline_iso']!==null));$ok(count($fixed)===1,'Exactly one fixed-deadline listing in this batch');
if($fixed){$dt=DateTimeImmutable::createFromFormat(DATE_ATOM,$fixed[0]['deadline_iso']);$ok($dt!==false,'Exact ISO deadline parses');}
if($fail){fwrite(STDERR,"FAIL: ".implode('; ',$fail)."\n");exit(1);}echo "PASS: country opportunity expansion content is structured and source-bounded.\n";
