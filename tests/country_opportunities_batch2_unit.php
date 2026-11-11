<?php
declare(strict_types=1);

$path=dirname(__DIR__).'/app/content/country-opportunities-20261106-batch2.json';
$data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};

$ok(($data['release']??'')==='country-opportunities-20261106-batch2','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===6,'Six reviewed country/international expansion entries');
$expected=[
 'ecowyert-south-africa-regional-trade-training',
 'ecowyert-zimbabwe-regional-trade-training',
 'ifad-crisis-response-farmers-organisations-2026',
 'mastercard-cambridge-scholars-2027-2028',
 'undp-ieee-genai-for-good-cities-2026',
 'isdb-competitive-technology-sourcing-2026'
];
$ok(array_column($entries,'slug')===$expected,'Stable release slugs');
$allowed=['www.undp.org','www.ifad.org','www.unido.org','www.mastercardfoundation.fund.cam.ac.uk','beta.isdb.org'];
$countryCoverage=['South Africa'=>false,'Zimbabwe'=>false,'Malawi'=>false,'Ethiopia'=>false,'South Sudan'=>false,'Mozambique'=>false,'Sudan'=>false,'Somalia'=>false,'Mali'=>false,'Burkina Faso'=>false,'Nigeria'=>false,'Chad'=>false,'All African countries'=>false];
foreach($entries as$entry){
    $slug=(string)($entry['slug']??'');
    foreach(['title','organisation','type','topic','sectors','summary','support','deadline_label','countries','apply_url','deadline_mode']as$key)$ok(is_string($entry[$key]??null)&&trim($entry[$key])!=='',$slug.' has '.$key);
    $ok(in_array($entry['deadline_mode'],['rolling','unknown','fixed'],true),$slug.' valid deadline mode');
    if(($entry['deadline_date']??'')!=='')$ok(preg_match('/^2026-\d{2}-\d{2}$/D',$entry['deadline_date'])===1,$slug.' valid 2026 date-only deadline');
    foreach(['benefits','eligibility','conditions','steps']as$key)$ok(count($entry[$key]??[])>=3,$slug.' substantive '.$key);
    foreach(['context','fit','strategy','commitment']as$key)$ok(mb_strlen((string)($entry['guide'][$key]??''))>=180,$slug.' substantive '.$key);
    $ok(count($entry['guide']['prepare']??[])>=3,$slug.' preparation checklist');
    foreach(array_merge([['apply',$entry['apply_url']]],$entry['sources']??[])as$source){
        $host=parse_url($source[1]??'',PHP_URL_HOST);
        $ok(is_string($host)&&in_array($host,$allowed,true),$slug.' official source host '.(string)$host);
    }
    foreach($countryCoverage as$country=>$seen)if(str_contains((string)$entry['countries'],$country))$countryCoverage[$country]=true;
}
foreach($countryCoverage as$country=>$seen)$ok($seen,'Country coverage includes '.$country);

require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/country_opportunities_batch2_release.php';
foreach($entries as$entry){
    try{
        $input=country_opportunities_batch2_input($entry,$data['checked_on']);
        $fields=desk_fields($input,'opportunity',true);
        $ok(($fields['instrument']??null)==='',$entry['slug'].' keeps support detail out of capped instrument field');
        $body=opportunity_guide_body($entry,$entry['guide'],$data['guidance_note']);
        $ok(substr_count($body,'<h2>')>=9&&str_contains($body,'Sources and official information'),$entry['slug'].' complete guide renders');
        $ok(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))>=300,$entry['slug'].' guide is substantive');
    }catch(Throwable $e){$fail[]=$entry['slug'].' Publishing Desk contract: '.$e->getMessage();}
}
$ifad=array_values(array_filter($entries,fn($e)=>$e['slug']==='ifad-crisis-response-farmers-organisations-2026'))[0]??[];
$ok(($ifad['support']??'')!==''&&str_contains((string)$ifad['support'],'US$250,000'),'IFAD award ceiling retained');
$cambridge=array_values(array_filter($entries,fn($e)=>$e['slug']==='mastercard-cambridge-scholars-2027-2028'))[0]??[];
$ok(str_contains((string)($cambridge['countries']??''),'All African countries'),'Cambridge listing remains pan-African');
$isdb=array_values(array_filter($entries,fn($e)=>$e['slug']==='isdb-competitive-technology-sourcing-2026'))[0]??[];
$ok(($isdb['deadline_date']??'')==='2026-11-15','IsDB date-only deadline preserved');

$importer=(string)file_get_contents(dirname(__DIR__).'/bin/import-country-opportunities-batch2-20261106.php');
$ok(str_contains($importer,'country_opportunities_batch2_import'),'CLI importer uses native batch importer');
$hook=(string)file_get_contents(dirname(__DIR__).'/.platform/hooks/postdeploy/47_country_opportunities_batch2_20261106.sh');
$ok(str_contains($hook,'Opportunities was retired')&&str_contains($hook,'exit 0'),'Retired Waithaka opportunity hook stays inert');

if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: second country opportunity batch is source-bounded, country-aware and deadline-precise.\n";
