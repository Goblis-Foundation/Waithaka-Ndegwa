<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/impact.php';
$now=strtotime('2026-10-23T10:00:00Z');
$valid=['schema_version'=>1,'source'=>'goblis_group','period_start'=>'2026-07-01','period_end'=>'2030-06-30','definition_version'=>'2030-v1','approved_for_publication'=>true,'as_of'=>'2026-10-23T09:00:00Z','metrics'=>['startups'=>0,'permanent_staff'=>12],'names'=>['PRIVATE']];
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$clean=impact_validate($valid,'goblis_group',$now);check($clean['metrics']['startups']===0,'A reported zero must be preserved.');check(!isset($clean['names']),'Private fields must be stripped.');
foreach([
    ['source'=>'ubunifu'],['approved_for_publication'=>false],['schema_version'=>2],['definition_version'=>'other'],['period_start'=>'2025-07-01'],['period_end'=>'2031-06-30'],['as_of'=>'2030-01-01T00:00:00Z'],['as_of'=>'2026-06-30T00:00:00Z'],['as_of'=>'invalid'],['metrics'=>['startups'=>-1,'permanent_staff'=>0]],['metrics'=>['startups'=>1.5,'permanent_staff'=>0]],['metrics'=>['startups'=>'2','permanent_staff'=>0]],['metrics'=>['startups'=>2]],
]as$patch){$rejected=false;try{impact_validate(array_replace($valid,$patch),'goblis_group',$now);}catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'Invalid contract was accepted.');}
$absent=impact_display([],'startups',$now);check($absent['value']===null,'Missing data is not zero.');
$good=impact_display(['status'=>'available','data'=>$clean],'startups',$now);check($good['value']===0&&$good['status']==='Source-reported progress','Fresh source data should be visible.');
$stale=impact_display(['status'=>'available','data'=>$clean],'startups',$now+172801);check(str_contains($stale['status'],'pending'),'Old data must be labelled.');
$offline=impact_display(['status'=>'unavailable','data'=>$clean],'startups',$now);check($offline['value']===0&&str_contains($offline['status'],'pending'),'Failed refresh must retain and label last accepted data.');
check(impact_metrics()['market_access']['source']==='afritrace','Foundation corroboration must not be added to the primary market count.');
foreach(['http://example.com/report','https://127.0.0.1/report','https://example.com:8443/report','https://user:pass@example.com/report','https://example.com/report?token=secret','https://example.com/report#fragment']as$url){$rejected=false;try{impact_endpoint($url);}catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'Unsafe feed URL was accepted.');}
echo "PASS: reporting contracts, privacy, zero versus missing, stale snapshots and endpoint boundaries.\n";
