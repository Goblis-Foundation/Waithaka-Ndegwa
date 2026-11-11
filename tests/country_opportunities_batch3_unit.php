<?php
declare(strict_types=1);

$path=dirname(__DIR__).'/app/content/country-opportunities-20261106-batch3.json';
$data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};

$ok(($data['release']??'')==='country-opportunities-20261106-batch3','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===5,'Five urgent country/pan-African entries');
$expected=[
 'young-africa-innovates-nigeria-cohort-2-2026',
 'makerere-rif8-college-level-grants-2026',
 'makerere-infectious-disease-modelling-fellowship-2026',
 'makerere-midwize-embolden-phd-2026',
 'oceanhub-africa-accelerator-cohort-8-2027'
];
$ok(array_column($entries,'slug')===$expected,'Stable urgent opportunity slugs');
$allowed=['www.undp.org','youngafricainnovates.ng','rif.mak.ac.ug','sph.mak.ac.ug','oceanhub.africa'];
foreach($entries as$entry){
    $slug=(string)($entry['slug']??'');
    foreach(['title','organisation','type','topic','sectors','summary','support','deadline_label','countries','apply_url','deadline_mode']as$key)$ok(is_string($entry[$key]??null)&&trim($entry[$key])!=='',$slug.' has '.$key);
    if(($entry['deadline_iso']??null)!==null)$ok(preg_match('/^2026-11-(?:09|10|11)T17:00:00\+03:00$/D',$entry['deadline_iso'])===1,$slug.' exact EAT deadline');
    if(($entry['deadline_date']??'')!=='')$ok(preg_match('/^2026-(?:11-(?:09|10|11|18)|12-06)$/D',$entry['deadline_date'])===1,$slug.' expected current deadline date');
    foreach(['benefits','eligibility','conditions','steps']as$key)$ok(count($entry[$key]??[])>=3,$slug.' substantive '.$key);
    foreach(['context','fit','strategy','commitment']as$key)$ok(mb_strlen((string)($entry['guide'][$key]??''))>=180,$slug.' substantive '.$key);
    $ok(count($entry['guide']['prepare']??[])>=3,$slug.' preparation checklist');
    foreach(array_merge([['apply',$entry['apply_url']]],$entry['sources']??[])as$source){
        $host=parse_url($source[1]??'',PHP_URL_HOST);
        $ok(is_string($host)&&in_array($host,$allowed,true),$slug.' approved official host '.(string)$host);
    }
}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/country_opportunities_batch3_release.php';
foreach($entries as$entry){
    try{
        $input=country_opportunities_batch3_input($entry,$data['checked_on']);$fields=desk_fields($input,'opportunity',true);
        $ok(($fields['instrument']??null)==='',$entry['slug'].' keeps long support outside capped instrument field');
        $body=opportunity_guide_body($entry,$entry['guide'],$data['guidance_note']);
        $ok(substr_count($body,'<h2>')>=9&&str_contains($body,'Sources and official information'),$entry['slug'].' complete guide renders');
        $ok(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))>=300,$entry['slug'].' guide has useful depth');
    }catch(Throwable $e){$fail[]=$entry['slug'].' Publishing Desk contract: '.$e->getMessage();}
}
$yai=$entries[0];$ok(($yai['deadline_date']??'')==='2026-11-18'&&str_contains((string)$yai['countries'],'Nigeria'),'YAI Nigeria eligibility and date');
$rif=$entries[1];$ok(($rif['deadline_iso']??'')==='2026-11-09T17:00:00+03:00'&&str_contains((string)$rif['support'],'UGX 200–500 million'),'Makerere RIF exact deadline and award range');
$model=$entries[2];$ok(($model['deadline_iso']??'')==='2026-11-10T17:00:00+03:00','Modelling fellowship exact deadline');
$phd=$entries[3];$ok(($phd['deadline_iso']??'')==='2026-11-11T17:00:00+03:00','Makerere PhD exact deadline');
$ocean=$entries[4];$ok(($ocean['deadline_date']??'')==='2026-12-06'&&str_contains((string)$ocean['countries'],'African countries'),'OceanHub pan-African deadline');

$importer=(string)file_get_contents(dirname(__DIR__).'/bin/import-country-opportunities-batch3-20261106.php');
$ok(str_contains($importer,'country_opportunities_batch3_import'),'CLI importer uses native batch importer');
$hook=(string)file_get_contents(dirname(__DIR__).'/.platform/hooks/postdeploy/48_country_opportunities_batch3_20261106.sh');
$ok(str_contains($hook,'Opportunities was retired')&&str_contains($hook,'exit 0'),'Retired Waithaka opportunity hook stays inert');

if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: urgent Nigeria, Uganda and pan-African opportunity batch is source-bounded and deadline-precise.\n";
