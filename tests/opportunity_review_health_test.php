<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/opportunity_review_health.php';
require dirname(__DIR__).'/app/opportunity_monitoring.php';
$now=1793870000;$config=['enabled'=>true,'ai_enabled'=>true];
$none=orh_summary($config,true,[[]],0,$now);
if(!$none['ai_configured']||$none['ai_checked_count']!==0||$none['worker_recent']||$none['ai_current_count']!==0)throw new RuntimeException('Configuration is not execution evidence.');
$rows=[['last_success'=>$now-60,'ai_checked_at'=>$now-50,'analysis_hash'=>'fixture','fetch_state'=>'checked','ai_state'=>'no_change','texts'=>['PRIVATE_SOURCE'],'secret'=>'PRIVATE_SECRET'],
 ['fetch_state'=>'needs_review','ai_state'=>'not_configured','proposal'=>['summary'=>'PRIVATE_PROPOSAL']],
 ['last_success'=>$now+10,'ai_checked_at'=>$now+10,'analysis_hash'=>'future']];
$s=orh_summary($config,false,$rows,$now-30,$now);
if($s['source_checked_count']!==1||$s['ai_checked_count']!==1||$s['pending_review_count']!==1||$s['source_attention_count']!==1||$s['ai_attention_count']!==1||!$s['worker_recent']||$s['ai_configured'])throw new RuntimeException('Aggregate evidence is inaccurate.');
if($s['latest_source_at']!==$now-60||$s['latest_ai_at']!==$now-50)throw new RuntimeException('Future evidence accepted.');
if(str_contains(json_encode($s,JSON_THROW_ON_ERROR),'PRIVATE_'))throw new RuntimeException('Private material escaped.');
foreach($s as$value)if(!is_bool($value)&&!is_int($value))throw new RuntimeException('Only aggregate scalar evidence may leave the process.');
if(orh_summary($config,true,[],$now-1800,$now)['worker_recent'])throw new RuntimeException('Old worker evidence called current.');
$current=['last_success'=>$now-60,'ai_checked_at'=>$now-120,'source_hash'=>'same-source','analysis_hash'=>'same-source','fetch_state'=>'checked','ai_state'=>'no_change',
    'listing_digest'=>'same-listing','_public_digest'=>'same-listing','_review_interval'=>21600,'engine_version'=>om_version(),'analysis_version'=>om_version()];
$s=orh_summary($config,true,[$current],$now-30,$now);
if($s['source_current_count']!==1||$s['ai_current_count']!==1||$s['recovery_checked_count']!==1)throw new RuntimeException('Current source and analysis evidence not counted.');
foreach(['source_hash'=>'changed','_public_digest'=>'changed','analysis_version'=>'old','ai_state'=>'needs_review']as$key=>$value){$m=$current;$m[$key]=$value;if(orh_summary($config,true,[$m],$now,$now)['ai_current_count']!==0)throw new RuntimeException('Stale or failed analysis counted as current.');}
foreach(['fetch_state'=>'partial','paused'=>true,'last_success'=>$now-21601,'_review_interval'=>null,'engine_version'=>'old']as$key=>$value){$m=$current;$m[$key]=$value;$s=orh_summary($config,true,[$m],$now,$now);if($s['source_current_count']!==0||$s['ai_current_count']!==0)throw new RuntimeException('Partial, paused or stale source counted as current.');}
if(orh_summary($config,false,[$current],$now,$now)['ai_current_count']!==0)throw new RuntimeException('Missing AI configuration counted as current.');
if(orh_summary(['enabled'=>true,'ai_enabled'=>false],true,[$current],$now,$now)['ai_current_count']!==0)throw new RuntimeException('Disabled AI counted as current.');
$partial=$current;$partial['fetch_state']='partial';$partial['ai_state']='awaiting_source';$s=orh_summary($config,true,[$partial],$now,$now);
if($s['source_attention_count']!==1||$s['ai_attention_count']!==1)throw new RuntimeException('Partial sources and awaiting-source AI must stay visible.');
echo "PASS: historical/current monitoring distinction, evidence freshness and private-data boundaries.\n";
