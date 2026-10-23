<?php
declare(strict_types=1);

/** Local stdio analytics bridge, not Meta's Ads MCP server. No remote refresh or mutations. */
function meta_mcp_snapshot(string $platform, ?array $history=null): array {
    if(!in_array($platform,['facebook','instagram'],true))throw new InvalidArgumentException('Choose facebook or instagram.');
    $history??=media_centre_history($platform);$report=$history[0]??null;
    if(!$report)return ['platform'=>$platform,'available'=>false,'note'=>'No saved report. Unavailable is not zero.'];
    $allowed=['Followers at period end','Views','Accounts reached','Impressions','Engagements','Link clicks','Followers','Published media','Latest daily accounts reached'];
    $metrics=[];foreach($report['metrics']??[]as$label=>$value){$count=media_centre_count($value);if($count!==null&&in_array($label,$allowed,true))$metrics[$label]=$count;}
    // Exclude account names, source references, free text, credentials, conversations and media.
    return ['platform'=>$platform,'available'=>true,'source'=>($report['source']??'')==='Connected provider API'?'Connected provider API':'Owner-recorded report','period'=>preg_match('/\A[0-9A-Za-z ():+,\-.\/]{1,140}\z/',(string)($report['period']??''))?$report['period']:'See dashboard for period','observed_at'=>preg_match('/\A[0-9T:+\-Z]{10,40}\z/',(string)($report['observed_at']??''))?$report['observed_at']:null,'metrics'=>(object)$metrics,'note'=>'Saved snapshot only; not a live refresh. Do not add reach or followers across platforms as unique people.'];
}
function meta_mcp_request(array $request, bool &$initialized, ?callable $read=null): ?array {
    $id=$request['id']??null;$hasId=array_key_exists('id',$request);
    $error=static fn(int $code,string $message):array=>['jsonrpc'=>'2.0','id'=>$id,'error'=>['code'=>$code,'message'=>$message]];
    if(($request['jsonrpc']??'')!=='2.0'||!is_string($request['method']??null)||($hasId&&!is_string($id)&&!is_int($id)&&$id!==null))return $error(-32600,'Invalid request.');
    if(!$hasId)return null;
    $method=$request['method'];$params=$request['params']??[];
    if(!is_array($params))return $error(-32602,'Invalid parameters.');
    if($method==='initialize'){
        if($initialized)return $error(-32600,'Already initialized.');
        $supported=['2026-07-28','2025-11-25','2025-06-18'];$version=in_array($params['protocolVersion']??null,$supported,true)?$params['protocolVersion']:'2025-11-25';
        $initialized=true;$result=['protocolVersion'=>$version,'capabilities'=>['tools'=>new stdClass()],'serverInfo'=>['name'=>'waithaka-meta-saved-analytics','version'=>'1.0.0'],'instructions'=>'Read-only saved aggregate analytics. No publishing, messages, credentials, account identities, provider refresh or ad actions.'];
    }elseif($method==='ping')$result=new stdClass();
    elseif(!$initialized)return $error(-32002,'Initialize first.');
    elseif($method==='tools/list')$result=['tools'=>[['name'=>'meta_saved_analytics','description'=>'Read the latest saved aggregate Facebook or Instagram analytics report. No live provider calls.','inputSchema'=>['type'=>'object','properties'=>['platform'=>['type'=>'string','enum'=>['facebook','instagram']]],'required'=>['platform'],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'openWorldHint'=>false]]]];
    elseif($method==='tools/call'){
        if(($params['name']??'')!=='meta_saved_analytics')return $error(-32602,'Unknown tool.');$args=$params['arguments']??[];
        if(!is_array($args)||array_diff(array_keys($args),['platform'])||!in_array($args['platform']??null,['facebook','instagram'],true))return $error(-32602,'Choose a supported platform only.');
        try{$data=$read? $read($args['platform']):meta_mcp_snapshot($args['platform']);$result=['content'=>[['type'=>'text','text'=>json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)]],'structuredContent'=>$data,'isError'=>false];}
        catch(Throwable){$result=['content'=>[['type'=>'text','text'=>'Saved analytics unavailable. Check the private dashboard.']],'isError'=>true];}
    }else return $error(-32601,'Method not found.');
    return ['jsonrpc'=>'2.0','id'=>$id,'result'=>$result];
}
