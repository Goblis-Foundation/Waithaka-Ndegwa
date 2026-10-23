<?php
declare(strict_types=1);
/** Explicitly launched local, read-only bridge. Never includes calendars, drafts or credentials. */
function google_mcp_snapshot(string $report,?array $saved=null): array {
    $allowed=['ga4-countries','ga4-devices','ga4-channels','ga4-trend','gsc-countries','gsc-devices','gsc-trend','youtube-countries','youtube-trend'];
    if(!in_array($report,$allowed,true))throw new InvalidArgumentException('Choose an aggregate Google report.');
    if($saved===null){
        $uid=filter_var(getenv('WAITHAKA_GOOGLE_MCP_USER_ID'),FILTER_VALIDATE_INT);if(!$uid||$uid<1)throw new RuntimeException('Set the approved Writer user ID.');
        $q=db()->prepare('SELECT payload_json FROM google_report_snapshots WHERE user_id=? AND report_key=? AND observed_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY ORDER BY id DESC LIMIT 1');$q->execute([$uid,$report]);$raw=$q->fetchColumn();$saved=$raw?publishing_decode($raw):[];
        if($saved){$service=google_workspace_reports()[$report][1];$c=publishing_connection($service);if(!$c||$c['status']!=='connected'||(int)$c['connected_by']!==$uid||!hash_equals(hash('sha256',(string)$c['selected_resource']),(string)($saved['destination_hash']??'')))$saved=[];}
    }
    if(!$saved)return ['report'=>$report,'available'=>false,'note'=>'No recent saved report for the confirmed destination.'];
    $rows=[];foreach(array_slice($saved['rows']??[],0,100)as$row){$label=$row[0]??null;if(!is_string($label)||!preg_match('/\A[\p{L}\p{N} ()+,.\/_-]{1,100}\z/u',$label))continue;$clean=[$label];foreach(array_slice($row,1,6)as$v)$clean[]=is_numeric($v)?0+$v:null;$rows[]=$clean;}
    return ['report'=>$report,'available'=>true,'start'=>preg_match('/^\d{4}-\d{2}-\d{2}$/D',$saved['start']??'')?$saved['start']:null,'end'=>preg_match('/^\d{4}-\d{2}-\d{2}$/D',$saved['end']??'')?$saved['end']:null,'columns'=>array_slice(google_mcp_columns($report),0,7),'rows'=>$rows,'note'=>'Saved aggregate snapshot only, at most 30 days old. No live refresh. Do not sum active users across dates or countries as unique people.'];
}
function google_mcp_columns(string $report): array {
    if(str_starts_with($report,'ga4-'))return ['dimension','activeUsers','sessions','screenPageViews','engagementRate'];
    if(str_starts_with($report,'gsc-'))return ['dimension','clicks','impressions','ctr','position'];
    return ['dimension','views','estimatedMinutesWatched','averageViewDuration','subscribersGained','subscribersLost'];
}
function google_mcp_request(array $request,bool &$initialized,?callable $read=null): ?array {
    $id=$request['id']??null;$hasId=array_key_exists('id',$request);$error=static fn($code,$message)=>['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]];
    if(($request['jsonrpc']??'')!=='2.0'||!is_string($request['method']??null)||($hasId&&!is_int($id)&&!is_string($id)&&$id!==null))return $error(-32600,'Invalid request.');if(!$hasId)return null;
    $params=$request['params']??[];if(!is_array($params))return $error(-32602,'Invalid parameters.');$method=$request['method'];
    if($method==='initialize'){$initialized=true;$result=['protocolVersion'=>in_array($params['protocolVersion']??'',['2025-11-25','2025-06-18','2026-07-28'],true)?$params['protocolVersion']:'2025-11-25','capabilities'=>['tools'=>new stdClass()],'serverInfo'=>['name'=>'waithaka-google-saved-analytics','version'=>'1.0.0']];}
    elseif($method==='ping')$result=new stdClass();
    elseif(!$initialized)return $error(-32002,'Initialize first.');
    elseif($method==='tools/list')$result=['tools'=>[['name'=>'google_saved_analytics','description'=>'Read a saved Google audience report. No calendars, content, identities, credentials or live API calls.','inputSchema'=>['type'=>'object','properties'=>['report'=>['type'=>'string','enum'=>['ga4-countries','ga4-devices','ga4-channels','ga4-trend','gsc-countries','gsc-devices','gsc-trend','youtube-countries','youtube-trend']]],'required'=>['report'],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'openWorldHint'=>false]]]];
    elseif($method==='tools/call'){$args=$params['arguments']??[];if(($params['name']??'')!=='google_saved_analytics'||!is_array($args)||array_diff(array_keys($args),['report'])||!is_string($args['report']??null))return $error(-32602,'Choose one aggregate report.');try{$data=$read?$read($args['report']):google_mcp_snapshot($args['report']);$result=['content'=>[['type'=>'text','text'=>json_encode($data,JSON_THROW_ON_ERROR)]],'structuredContent'=>$data,'isError'=>false];}catch(Throwable){$result=['content'=>[['type'=>'text','text'=>'Report unavailable. Review the private Google workspace.']],'isError'=>true];}}
    else return $error(-32601,'Method not found.');return ['jsonrpc'=>'2.0','id'=>$id,'result'=>$result];
}
