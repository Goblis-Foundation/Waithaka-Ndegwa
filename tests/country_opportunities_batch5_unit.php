<?php
declare(strict_types=1);

$path=dirname(__DIR__).'/app/content/country-opportunities-20261106-batch5.json';
$data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};

$ok(($data['release']??'')==='country-opportunities-20261106-batch5','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===1,'One AU ECOSOCC opportunity');
$entry=$entries[0]??[];
$ok(($entry['slug']??'')==='au-ecosocc-civil-society-democratic-resilience-book-2026','Stable slug');
$ok(($entry['deadline_date']??'')==='2026-11-15','Date-only deadline retained');
$ok(str_contains((string)($entry['countries']??''),'African Union'),'Pan-African scope retained');
$allowed=['ecosocc.au.int'];
foreach(['title','organisation','type','topic','sectors','summary','support','deadline_label','countries','apply_url','deadline_mode']as$key)$ok(is_string($entry[$key]??null)&&trim($entry[$key])!=='','Has '.$key);
foreach(['benefits','eligibility','conditions','steps']as$key)$ok(count($entry[$key]??[])>=3,'Substantive '.$key);
foreach(['context','fit','strategy','commitment']as$key)$ok(mb_strlen((string)($entry['guide'][$key]??''))>=180,'Substantive '.$key);
$ok(count($entry['guide']['prepare']??[])>=3,'Preparation checklist');
foreach($entry['sources']??[] as$source){$host=parse_url($source[1]??'',PHP_URL_HOST);$ok(is_string($host)&&in_array($host,$allowed,true),'Official source host '.(string)$host);}

require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/country_opportunities_batch5_release.php';
try{
  $input=country_opportunities_batch5_input($entry,$data['checked_on']);$fields=desk_fields($input,'opportunity',true);
  $ok(($fields['instrument']??null)==='','Keeps support outside capped instrument field');
  $body=opportunity_guide_body($entry,$entry['guide'],$data['guidance_note']);
  $ok(substr_count($body,'<h2>')>=9&&str_contains($body,'Sources and official information'),'Complete guide renders');
  $ok(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))>=300,'Guide has useful depth');
}catch(Throwable $e){$fail[]='Publishing Desk contract: '.$e->getMessage();}

$hook=(string)file_get_contents(dirname(__DIR__).'/.platform/hooks/postdeploy/50_country_opportunities_batch5_20261106.sh');
$ok(str_contains($hook,'import-country-opportunities-batch5-20261106.php'),'Production hook executes import');

if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: AU ECOSOCC research opportunity is source-bounded and deadline-precise.\n";
