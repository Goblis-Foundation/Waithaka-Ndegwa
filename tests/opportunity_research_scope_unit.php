<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/opportunity_countries.php';
require_once dirname(__DIR__).'/app/opportunity_research_scope.php';

$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};
$order=opportunity_research_country_order();
$ok(count($order)===count(opportunity_africa_countries()),'Every African country is represented');
$ok(count(array_unique($order))===count($order),'Country research order has no duplicates');
$ok(($order[0]??'')==='kenya','Internal research begins with Kenya');
foreach(['research-funding','startup-capital','non-profit-grants','innovation-grants']as$theme)$ok(in_array($theme,opportunity_research_themes(),true),'Research theme '.$theme);
foreach(['for-profit','non-profit','research','individual','mixed']as$form)$ok(isset(opportunity_research_applicant_forms()[$form]),'Applicant form '.$form);
foreach(['grant','equity','debt','guarantee','research-grant','contract','blended']as$instrument)$ok(isset(opportunity_research_instruments()[$instrument]),'Instrument '.$instrument);
$policy=opportunity_research_policy();
$ok(count($policy['source_rules']??[])>=6,'Source verification rules retained');
$public=(string)file_get_contents(dirname(__DIR__).'/views/desk/index.php');
$ok(!str_contains(mb_strtolower($public),'kenya first')&&!str_contains(mb_strtolower($public),'prioritise kenya'),'Internal research order is not public-facing');
$types=(string)file_get_contents(dirname(__DIR__).'/app/publishing_desk_policy.php');
$ok(str_contains($types,"'startup-capital'=>'Startup & SME capital'"),'Startup capital is a first-class opportunity type');

if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: country-by-country research policy covers for-profit, non-profit, research and capital instruments.\n";
