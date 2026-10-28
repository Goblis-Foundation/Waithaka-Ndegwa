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
$offline=impact_display(['status'=>'unavailable','data'=>$clean],'startups',$now);check($offline['value']===0&&$offline['status']==='Source-reported progress'&&$offline['connection_status']==='Source check pending','Failed refresh must retain and label last accepted data.');
check(impact_metrics()['market_access']['source']==='afritrace','Foundation corroboration must not be added to the primary market count.');
foreach(['http://example.com/report','https://127.0.0.1/report','https://example.com:8443/report','https://user:pass@example.com/report','https://example.com/report?token=secret','https://example.com/report#fragment']as$url){$rejected=false;try{impact_endpoint($url);}catch(InvalidArgumentException $e){$rejected=true;}check($rejected,'Unsafe feed URL was accepted.');}
$sourceContracts=impact_source_exchange();
check(count($sourceContracts)===4&&$sourceContracts['ubunifu']['metric_keys'][0]==='social_enterprises','Four source-owned reporting contracts retain separate metric authority');
check($sourceContracts['goblis_foundation']['metric_keys'][0]==='market_access (corroboration only)','Foundation does not double-count AfriTrace enterprise market access');
foreach(impact_sources()as$source=>$label){
 check(impact_source_readiness($source,'',[])['label']==='Endpoint not configured','Absent endpoints show no fabricated published data for '.$source);
}
check(impact_source_readiness('ubunifu','https://www.example.org/approved',[])['label']==='Configured · verification pending','Configured URL is not a provider-verified public feed');
check(impact_source_readiness('afritrace','https://www.example.org/approved',['status'=>'unavailable'])['label']==='Connection check unavailable','Connection failure is not zero');
check(impact_source_readiness('goblis_group','https://www.example.org/approved',['status'=>'available','data'=>['approved_for_publication'=>true]])['label']==='Approved report accepted','Actual stored source confirmation has a distinct state');
echo "PASS: reporting contracts, source authority, privacy, zero versus missing, stale snapshots and endpoint boundaries.\n";

$ubunifu=$valid;$ubunifu['source']='ubunifu';$ubunifu['metrics']=['social_enterprises'=>2030];
check(!isset(impact_validate($ubunifu,'ubunifu',$now)['metrics']['ubunifu_permanent_staff']),'Existing Ubunifu feeds remain compatible.');
$ubunifu['metrics']['ubunifu_permanent_staff']=10150;check(impact_validate($ubunifu,'ubunifu',$now)['metrics']['ubunifu_permanent_staff']===10150,'Employment actuals must come from source, not a computed proxy.');
check(impact_metrics()['ubunifu_permanent_staff']['target']===2030*5,'Ubunifu employment target uses five permanent staff per enterprise.');
$indirect=$valid;$indirect['metrics']['indirect_beneficiaries']=0;$indirect['beneficiary_basis']='Approved sampled household survey, direct participants excluded.';$indirect['beneficiary_deduplicated']=true;
check(impact_validate($indirect,'goblis_group',$now)['metrics']['indirect_beneficiaries']===0,'A verified indirect zero is valid.');
unset($indirect['beneficiary_basis']);$rejected=false;try{impact_validate($indirect,'goblis_group',$now);}catch(InvalidArgumentException){$rejected=true;}check($rejected,'Indirect counts require a public methodology.');

$monthly=['schema_version'=>2,'source'=>'goblis_group','period_start'=>'2026-07-01','period_end'=>'2030-06-30','definition_version'=>'2030-v1','approved_for_publication'=>true,'publication_status'=>'approved','report_id'=>'group-2026-09','revision'=>1,'reporting_frequency'=>'monthly','measurement_period_start'=>'2026-09-01','measurement_period_end'=>'2026-09-30','measurement_timezone'=>'Africa/Nairobi','approved_at'=>'2026-10-14T09:00:00+03:00','published_at'=>'2026-10-15T09:00:00+03:00','metrics'=>['startups'=>3,'permanent_staff'=>20]];
$m=impact_validate($monthly,'goblis_group',$now);$snapshot=impact_accept_report([],$m,$now);$display=impact_display($snapshot,'startups',$now);
check($display['value']===3&&$display['status']==='Source-reported progress','Monthly measurements stay current after 48 hours');
$due=strtotime('2026-11-15T23:59:59+03:00');check(strtotime($m['next_report_due_at'])===$due,'September report due after October closes plus 15-day publication window');
check(impact_display($snapshot,'startups',$due)['status']==='Source-reported progress','Deadline inclusive');
check(str_contains(impact_display($snapshot,'startups',$due+1)['status'],'overdue'),'Monthly report becomes overdue only after its deadline');
$offlineMonthly=$snapshot;$offlineMonthly['status']='unavailable';$offlineMonthly['attempted_at']=gmdate('c',$now+60);
check(impact_display($offlineMonthly,'startups',$now)['status']==='Source-reported progress','Connection failure does not stale monthly measurements');
check($m['as_of']!==$m['published_at']&&$m['received_at']!==$m['published_at'],'Measurement, publication and delivery are separate dates');
$late=$monthly;$late['published_at']='2026-11-17T09:00:00+03:00';$late['approved_at']='2026-11-17T08:00:00+03:00';$lateClean=impact_validate($late,'goblis_group',strtotime('2026-11-18T00:00:00Z'));
check(impact_display(impact_accept_report([],$lateClean),'startups',strtotime('2026-11-18T00:00:00Z'))['status']==='Last reported · monthly update overdue','Late publication cannot reset measurement freshness');
foreach([['names'=>['private']],['metrics'=>['startups'=>1,'permanent_staff'=>2,'payroll'=>[]]],['publication_status'=>'draft'],['approved_for_publication'=>false],['measurement_period_end'=>'2026-09-29'],['measurement_period_start'=>'2026-09-02'],['measurement_period_start'=>'2026-02-01','measurement_period_end'=>'2026-02-30'],['approved_at'=>'2026-09-29T09:00:00Z'],['published_at'=>'2026-10-13T09:00:00Z'],['published_at'=>'2026-10-23T10:00:00+99:00'],['published_at'=>'2026-02-30T09:00:00Z'],['revision'=>0],['revision'=>'2'],['reporting_frequency'=>'daily']]as$patch){$bad=false;try{impact_validate(array_replace($monthly,$patch),'goblis_group',$now);}catch(InvalidArgumentException){$bad=true;}check($bad,'Reject malformed or non-aggregate monthly reports');}
check(impact_accept_report($snapshot,impact_validate($monthly,'goblis_group',$now+60),$now+60)['data']['published_at']===$m['published_at'],'Polling does not republish a monthly report');
$correction=$monthly;$correction['metrics']['startups']=4;$bad=false;try{impact_accept_report($snapshot,impact_validate($correction,'goblis_group',$now));}catch(RuntimeException){$bad=true;}check($bad,'Changed same revision rejected');
$correction['revision']=2;$correction['approved_at']='2026-10-16T08:00:00Z';$correction['published_at']='2026-10-16T09:00:00Z';$corrected=impact_accept_report($snapshot,impact_validate($correction,'goblis_group',$now),$now);check($corrected['data']['metrics']['startups']===4,'Approved higher revision correction accepted');
foreach([$m,$clean]as$older){$bad=false;try{impact_accept_report($corrected,$older);}catch(RuntimeException){$bad=true;}check($bad,'Revision rollback and schema downgrade rejected');}
$withdraw=$correction;$withdraw['revision']=3;$withdraw['approved_for_publication']=false;$withdraw['publication_status']='withdrawn';$withdraw['published_at']='2026-10-17T09:00:00Z';unset($withdraw['metrics'],$withdraw['approved_at']);
$withheld=impact_accept_report($corrected,impact_validate($withdraw,'goblis_group',$now),$now);check($withheld['data']===null&&impact_display($withheld,'startups',$now)['value']===null,'Explicit withdrawal removes public figures');
$bad=false;try{impact_accept_report($withheld,$corrected['data']);}catch(RuntimeException){$bad=true;}check($bad,'Withdrawn figures cannot reappear from replay');
$legacyCache=$clean;unset($legacyCache['approved_for_publication']);check(impact_display(['status'=>'available','data'=>$legacyCache],'startups',$now)['value']===null,'Unmarked cached approval is not inferred');
$leap=$monthly;$leap['measurement_period_start']='2028-02-01';$leap['measurement_period_end']='2028-02-29';$leap['approved_at']='2028-03-10T09:00:00Z';$leap['published_at']='2028-03-11T09:00:00Z';$leapClean=impact_validate($leap,'goblis_group',strtotime('2028-03-12T00:00:00Z'));check(strtotime($leapClean['next_report_due_at'])===strtotime('2028-04-15T23:59:59+03:00'),'Leap month reporting deadline');
$final=$monthly;$final['measurement_period_start']='2030-06-01';$final['measurement_period_end']='2030-06-30';$final['approved_at']='2030-07-10T09:00:00Z';$final['published_at']='2030-07-11T09:00:00Z';check(impact_validate($final,'goblis_group',strtotime('2030-07-12T00:00:00Z'))['next_report_due_at']===null,'Final measurement month does not demand reports beyond target period');
echo "PASS: monthly freshness, temporal boundaries, aggregate approval, revisions, withdrawals and independent connection checks.\\n";

$withdrawWithMetrics=$withdraw;$withdrawWithMetrics['metrics']=[];$bad=false;try{impact_validate($withdrawWithMetrics,'goblis_group',$now);}catch(InvalidArgumentException){$bad=true;}check($bad,'Withdrawal carries no aggregates, even an empty metrics field');
$newMonth=$monthly;$newMonth['report_id']='group-2026-10';$newMonth['measurement_period_start']='2026-10-01';$newMonth['measurement_period_end']='2026-10-31';$newMonth['approved_at']='2026-11-10T09:00:00Z';$newMonth['published_at']='2026-11-11T09:00:00Z';$newMonth['metrics']['permanent_staff']=15;$newer=impact_accept_report($snapshot,impact_validate($newMonth,'goblis_group',strtotime('2026-11-12T00:00:00Z')));check($newer['data']['metrics']['permanent_staff']===15,'Monthly employment is point-in-time and may decrease');
$bad=false;try{impact_accept_report($newer,$m);}catch(RuntimeException){$bad=true;}check($bad,'Older measurement cannot replace newer even if published again');
