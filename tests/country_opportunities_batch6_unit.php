<?php
declare(strict_types=1);

$path=dirname(__DIR__).'/app/content/country-opportunities-20261107-batch6.json';
$data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};

$ok(($data['release']??'')==='country-opportunities-20261107-batch6','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===2,'Two Kenya startup-capital routes');
$expected=['kenya-youth-enterprise-development-fund-capital-2026','kenya-uwezo-fund-enterprise-loans-2026'];
$ok(array_column($entries,'slug')===$expected,'Exact reviewed capital set');
$allowed=['www.portal.youthfund.go.ke','www.youthfund.go.ke','www.uwezo.go.ke','loans.uwezo.go.ke'];

foreach($entries as$entry){
  $ok(($entry['type']??'')==='startup-capital',$entry['slug'].' is capital, not grant');
  $ok(($entry['deadline_mode']??'')==='rolling',$entry['slug'].' is rolling');
  $ok(str_contains((string)($entry['countries']??''),'Kenya'),$entry['slug'].' is Kenya-eligible');
  $ok(preg_match('~loan|repay~i',(string)($entry['support']??''))===1,$entry['slug'].' discloses repayable finance');
  foreach(['benefits','eligibility','conditions','steps']as$key)$ok(count($entry[$key]??[])>=3,$entry['slug'].' substantive '.$key);
  foreach($entry['sources']??[] as$source){$host=parse_url($source[1]??'',PHP_URL_HOST);$ok(is_string($host)&&in_array($host,$allowed,true),'Official source host '.(string)$host);}
}

require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/country_opportunities_batch6_release.php';
try{
  foreach($entries as$entry){
    $input=country_opportunities_batch6_input($entry,$data['checked_on']);
    $fields=desk_fields($input,'opportunity',true);
    $ok(($fields['type']??'')==='startup-capital','Desk accepts startup capital type');
    $body=opportunity_guide_body($entry,$entry['guide'],$data['guidance_note']);
    $ok(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))>=300,$entry['slug'].' guide has useful depth');
  }
}catch(Throwable $e){$fail[]='Publishing Desk contract: '.$e->getMessage();}

$hook=(string)file_get_contents(dirname(__DIR__).'/.platform/hooks/postdeploy/50_country_opportunities_batch5_20261106.sh');
$ok(str_contains($hook,'import-country-opportunities-batch6-20261107.php'),'Production hook executes import');

if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: Kenya startup-capital opportunities are verified, rolling and not mislabeled as grants.\n";
