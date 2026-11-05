<?php
declare(strict_types=1);

/** Export only counters, flags and timestamps; never source text, IDs or secrets. */
function orh_summary(array $config,bool $configured,array $monitors,int $lastRun,?int $now=null): array {
    $now??=time();
    $timestamp=static fn(mixed $value):int=>is_int($value)&&$value>0&&$value<=$now?$value:0;
    $lastRun=$timestamp($lastRun);
    $out=['enabled'=>!empty($config['enabled']),'ai_enabled'=>!empty($config['ai_enabled']),
        'ai_configured'=>$configured,'published_count'=>count($monitors),
        'source_checked_count'=>0,'ai_checked_count'=>0,'source_attention_count'=>0,'ai_attention_count'=>0,
        'source_current_count'=>0,'ai_current_count'=>0,'recovery_checked_count'=>0,
        'pending_review_count'=>0,'last_worker_at'=>$lastRun,'worker_recent'=>$lastRun>=$now-900&&$lastRun>0,
        'latest_source_at'=>0,'latest_ai_at'=>0];
    foreach($monitors as$m){
        $sourceAt=$timestamp($m['last_success']??null);$aiAt=$timestamp($m['ai_checked_at']??null);
        if($sourceAt>0){$out['source_checked_count']++;$out['latest_source_at']=max($out['latest_source_at'],$sourceAt);}
        if($aiAt>0&&!empty($m['analysis_hash'])){$out['ai_checked_count']++;$out['latest_ai_at']=max($out['latest_ai_at'],$aiAt);}
        if(in_array($m['fetch_state']??'',['needs_review','partial'],true))$out['source_attention_count']++;
        if(in_array($m['ai_state']??'',['needs_review','not_configured','disabled','daily_limit','awaiting_source'],true))$out['ai_attention_count']++;
        if(!empty($m['proposal']))$out['pending_review_count']++;
        $currentEngine=function_exists('om_version')&&($m['engine_version']??'')===om_version();
        if($currentEngine)$out['recovery_checked_count']++;
        $interval=$m['_review_interval']??null;
        $sourceCurrent=$currentEngine&&is_int($interval)&&$interval>0&&empty($m['paused'])&&($m['fetch_state']??'')==='checked'&&$sourceAt>0&&$sourceAt+$interval>=$now;
        if($sourceCurrent)$out['source_current_count']++;
        if($sourceCurrent&&$configured&&!empty($config['ai_enabled'])&&$aiAt>0&&($m['analysis_version']??'')===om_version()
            &&!empty($m['analysis_hash'])&&hash_equals((string)$m['analysis_hash'],(string)($m['source_hash']??''))
            &&!empty($m['_public_digest'])&&hash_equals((string)$m['_public_digest'],(string)($m['listing_digest']??''))
            &&in_array($m['ai_state']??'',['no_change','review_required'],true))$out['ai_current_count']++;
    }
    return $out;
}
function orh_snapshot(): array {
    $monitors=[];
    foreach(desk_all()as$r){
        if(($r['kind']??'')!=='opportunity'||empty($r['public'])||empty($r['public_visible'])||($r['state']??'')==='withdrawn'||!empty($r['public']['fields']['sponsored']))continue;
        $m=comm_get('ol.monitor.'.$r['id']);$m['_review_interval']=ol_interval(ol_public_record($r));$m['_public_digest']=$r['public']['digest']??'';$monitors[]=$m;
    }
    return orh_summary(ol_config(),trim((string)setting('openai.api_key',''))!=='',$monitors,(int)(comm_get('ol.last_run')['at']??0));
}
