<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$data=json_decode((string)file_get_contents($root.'/app/content/country-opportunities-20261107-batch7.json'),true,128,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $m)use(&$fail){if(!$v)$fail[]=$m;};
$ok(($data['release']??'')==='country-opportunities-20261107-batch7','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===4,'Four verified country opportunities');
$expected=['nigeria-uspf-changemaker-challenge-2026','malawi-ncst-ppp-research-innovation-grant-2026','tanzania-ict-startup-wezesha-fund-2026-2027','tanzania-costech-innovation-credit-guarantee-2026'];
$ok(array_column($entries,'slug')===$expected,'Exact reviewed set');
$allowed=['uspf.gov.ng','uspfchangemaker.ng','grants.ncst.mw','jamiihunt.mawasiliano.go.tz','www.mawasiliano.go.tz','cgs.costech.or.tz'];
foreach($entries as$e){
 $ok(in_array($e['type']??'', ['competition','research','startup-capital'],true),$e['slug'].' type');
 $ok(is_array($e['sources']??null)&&count($e['sources'])>=2,$e['slug'].' sources');
 foreach($e['sources'] as$s){$h=parse_url($s[1]??'',PHP_URL_HOST);$ok(is_string($h)&&in_array($h,$allowed,true),$e['slug'].' official host '.(string)$h);}
 foreach(['benefits','eligibility','conditions','steps'] as$k)$ok(count($e[$k]??[])>=3,$e['slug'].' '.$k);
}
require_once $root.'/app/bootstrap.php';
require_once $root.'/app/country_opportunities_batch7_release.php';
try{
 foreach($entries as$e){
  $input=country_opportunities_batch7_input($e,$data['checked_on']);
  $fields=desk_fields($input,'opportunity',true);
  $ok(($fields['type']??'')===$e['type'],$e['slug'].' desk type');
  $body=opportunity_guide_body($e,$e['guide'],$data['guidance_note']);
  $ok(count(preg_split('/\s+/u',plain_text($body),-1,PREG_SPLIT_NO_EMPTY))>=300,$e['slug'].' useful depth');
 }
}catch(Throwable $e){$fail[]='Publishing contract: '.$e->getMessage();}
$hook=(string)file_get_contents($root.'/.platform/hooks/postdeploy/50_country_opportunities_batch5_20261106.sh');
$ok(str_contains($hook,'import-country-opportunities-batch7-20261107.php'),'Postdeploy importer');
if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: Nigeria, Malawi and Tanzania opportunities are source-bounded and financing types are explicit.\n";
