<?php
declare(strict_types=1);

$path=dirname(__DIR__).'/app/content/country-opportunities-20261106-batch4.json';
$data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};

$ok(($data['release']??'')==='country-opportunities-20261106-batch4','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===2,'Two additive opportunities');
$expected=['circular-economy-mauritius-built-environment-2026','global-eba-fund-medium-grants-2026'];
$ok(array_column($entries,'slug')===$expected,'Stable batch slugs');
$allowed=['environment.govmu.org','mibl.govmu.org','globalebafund.org'];
foreach($entries as$entry){
  $slug=(string)($entry['slug']??'');
  foreach(['title','organisation','type','topic','sectors','summary','support','deadline_label','countries','apply_url','deadline_mode']as$key)$ok(is_string($entry[$key]??null)&&trim($entry[$key])!=='',$slug.' has '.$key);
  foreach(['benefits','eligibility','conditions','steps']as$key)$ok(count($entry[$key]??[])>=3,$slug.' substantive '.$key);
  foreach(['context','fit','strategy','commitment']as$key)$ok(mb_strlen((string)($entry['guide'][$key]??''))>=180,$slug.' substantive '.$key);
  $ok(count($entry['guide']['prepare']??[])>=3,$slug.' preparation checklist');
  foreach($entry['sources']??[] as$source){$host=parse_url($source[1]??'',PHP_URL_HOST);$ok(is_string($host)&&in_array($host,$allowed,true),$slug.' official source host '.(string)$host);}
}
$mauritius=$entries[0]??[];$ok(($mauritius['deadline_iso']??'')==='2026-11-13T23:59:00+01:00','Mauritius exact Paris deadline preserved');
$ok(str_contains((string)($mauritius['countries']??''),'Mauritius'),'Mauritius direct country coverage');
$eba=$entries[1]??[];$ok(($eba['deadline_date']??'')==='2026-11-16'&&($eba['deadline_mode']??'')==='unknown','Global EbA date-only deadline preserved without invented cutoff');

require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/country_opportunities_batch4_release.php';
foreach($entries as$entry){
  try{
    $input=country_opportunities_batch4_input($entry,$data['checked_on']);$fields=desk_fields($input,'opportunity',true);
    $ok(($fields['instrument']??null)==='',$entry['slug'].' keeps long support outside capped instrument field');
    $body=opportunity_guide_body($entry,$entry['guide'],$data['guidance_note']);
    $ok(substr_count($body,'<h2>')>=9&&str_contains($body,'Sources and official information'),$entry['slug'].' complete guide renders');
    $ok(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))>=300,$entry['slug'].' guide has useful depth');
  }catch(Throwable $e){$fail[]=$entry['slug'].' Publishing Desk contract: '.$e->getMessage();}
}
$hook=(string)file_get_contents(dirname(__DIR__).'/.platform/hooks/postdeploy/49_country_opportunities_batch4_20261106.sh');
$ok(str_contains($hook,'Opportunities was retired')&&str_contains($hook,'exit 0'),'Retired Waithaka opportunity hook stays inert');

if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: Mauritius and Global EbA batch is source-bounded and deadline-precise.\n";
