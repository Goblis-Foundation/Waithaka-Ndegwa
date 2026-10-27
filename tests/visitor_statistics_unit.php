<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/visitor_statistics.php';
$n=0;function vc(bool $ok,string $why):void{global $n;$n++;if(!$ok)throw new RuntimeException($why);}
$now=new DateTimeImmutable('2026-12-31T20:59:30Z');$t=$now->getTimestamp();
$s=['timezone'=>'Africa/Nairobi','active'=>2,'active_at'=>$t,'active_window'=>2,'active_mode'=>'presence','month'=>'2026-12','monthly_visitors'=>14,'monthly_metric'=>'totalUsers','month_at'=>$t,'year'=>'2026','yearly_visitors'=>28,'yearly_metric'=>'totalUsers','year_at'=>$t];
$v=visitor_statistics_display($s,$now);vc($v['now']===2&&$v['month']===14&&$v['year']===28,'Each counter uses its separate verified reading.');vc($v['month_label']==='December'&&$v['year_label']==='2026','Labels follow property-local calendar.');
$r=visitor_statistics_display($s,new DateTimeImmutable('2026-12-31T21:00:01Z'));vc($r['month_label']==='January'&&$r['year_label']==='2027','Nairobi midnight rolls the year before UTC midnight.');vc(!isset($r['month'],$r['year']),'Prior-period totals are not relabelled.');
$r=visitor_statistics_display($s,$now->modify('+151 seconds'));vc(!isset($r['now']),'Stale live reading is not presented as current.');
$s['month']='2026-11';$r=visitor_statistics_display($s,$now);vc(!isset($r['month'])&&$r['year']===28,'Month rollover does not reset a valid yearly reading.');$s['month']='2026-12';
$s['monthly_metric']='activeUsers';vc(!isset(visitor_statistics_display($s,$now)['month']),'Old metric snapshot is not silently relabelled as total visitors.');$s['monthly_metric']='totalUsers';
$s['month_at']=$t-901;vc(!isset(visitor_statistics_display($s,$now)['month']),'Stale cumulative reading expires.');$s['month_at']=$t+100;vc(!isset(visitor_statistics_display($s,$now)['month']),'Future timestamps rejected.');
$s['active_window']=30;vc(!isset(visitor_statistics_display($s,$now)['now']),'Old 30-minute reading is not represented as live presence.');
vc(visitor_statistics_number(['rows'=>[['metricValues'=>[['value'=>'0']]]]])===0,'Measured zero preserved.');vc(visitor_statistics_number([])===null,'Missing data is not fabricated zero.');vc(visitor_statistics_number(['rows'=>[['metricValues'=>[['value'=>'9']]]],'metadata'=>['subjectToThresholding'=>true]])===null,'Thresholded data withheld.');
vc(visitor_statistics_period_number(['metricHeaders'=>[['name'=>'totalUsers']],'rows'=>[]])===0,'Explicitly empty valid report means zero.');vc(visitor_statistics_period_number(['metricHeaders'=>[['name'=>'sessions']],'rows'=>[]])===null,'Sessions cannot substitute for distinct visitors.');
$q=visitor_statistics_realtime_request('123',false,true);vc($q['minuteRanges'][0]['startMinutesAgo']===1&&$q['minuteRanges'][0]['endMinutesAgo']===0,'Live request is narrowed to recent presence.');vc(str_contains(json_encode($q),'wn_live_presence')&&str_contains(json_encode($q),'streamId'),'Multi-stream property is scoped to this stream and event.');
vc(!str_contains(json_encode(visitor_statistics_realtime_request('123',true,true)),'streamId'),'Exact single-stream property needs no unsupported fallback filter.');
$source=file_get_contents(dirname(__DIR__).'/app/visitor_statistics.php');vc(str_contains($source,"time()-300"),'Cumulative provider refresh interval is five minutes.');vc(str_contains($source,"Cache-Control: no-store"),'Public endpoint cannot cache stale snapshots.');
echo 'Visitor statistics: '.$n." unit assertions passed.\n";
