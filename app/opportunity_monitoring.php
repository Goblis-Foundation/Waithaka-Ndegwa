<?php
declare(strict_types=1);

/** Private source-review helpers. No publishing, messages or application submissions. */
function om_version(): string { return 'source-review-20261105-v2'; }
function om_messages(): array {
    return [
        'source_dns'=>'The official host could not be resolved. A retry is scheduled.',
        'source_address_review'=>'The address is not a permitted public HTTPS destination. Review the source URL.',
        'source_redirect_review'=>'The source redirects outside its approved host or too many times. Review the destination.',
        'source_timeout'=>'The official source timed out. A retry is scheduled.',
        'source_access'=>'The source requires login, permission or a human browser. Review it manually; access controls are not bypassed.',
        'source_rate_limit'=>'The provider limited requests. A delayed retry is scheduled.',
        'source_not_found'=>'The source was not found. Locate the current official announcement; this is not evidence of closure.',
        'source_server'=>'The provider returned a server error. A retry is scheduled.',
        'source_format'=>'The source is not readable HTML or plain text. Review the document or portal manually.',
        'source_size'=>'The source exceeded the bounded download limit. Review the announcement manually.',
        'source_content'=>'No useful announcement text was returned. Review the official notice manually.',
        'source_missing'=>'No official monitoring source is configured.',
        'source_unavailable'=>'The official source could not be read. Previous evidence is retained.',
        'analysis_invalid'=>'The AI response was not valid review data. A bounded retry is scheduled.',
        'analysis_evidence_missing'=>'The AI citation did not match the source text. No revision was accepted.',
        'analysis_source_mismatch'=>'The text did not establish the same programme and application round. Review the source.',
        'analysis_date_invalid'=>'The suggested date was invalid. No revision was accepted.',
        'analysis_timeout'=>'The AI service timed out. A bounded retry is scheduled.',
        'analysis_rate_limit'=>'The AI provider limited requests. A delayed retry is scheduled.',
        'analysis_token_limit'=>'The AI response was incomplete. A bounded retry is scheduled.',
        'analysis_credentials'=>'The configured AI service needs an administrator check.',
        'analysis_unavailable'=>'AI analysis did not complete. Source evidence remains available for review.',
        'daily_limit'=>'The daily AI allowance is exhausted. Analysis can resume after the UTC reset.',
        'not_configured'=>'AI is not configured. Source checking continues without claiming AI verification.',
        'disabled'=>'AI review is disabled. Source checking continues.',
    ];
}
function om_message(string $code): string { return om_messages()[$code]??'Awaiting a recorded check.'; }
function om_error(Throwable $error,string $stage): string {
    $code=$error->getMessage();
    if(isset(om_messages()[$code])&&str_starts_with($code,$stage==='source'?'source_':'analysis_'))return $code;
    if($stage==='analysis'&&$error instanceof OpenAiRequestException)return match($error->reason){
        'timeout'=>'analysis_timeout','rate_limit'=>'analysis_rate_limit','token_limit'=>'analysis_token_limit',
        'authentication','permissions','not_configured','quota','model','credits','project_limit','organisation_limit','request'=>'analysis_credentials',
        default=>'analysis_unavailable',
    };
    return $stage==='source'?'source_unavailable':'analysis_invalid';
}
function om_normalize(string $text): string {
    if(!mb_check_encoding($text,'UTF-8'))throw new RuntimeException('source_content');
    return trim(preg_replace('/[\s\p{Z}]+/u',' ',$text)??'');
}
function om_text(string $raw,string $type): string {
    if(strlen($raw)>1048576)throw new RuntimeException('source_size');
    $type=strtolower(trim(explode(';',$type)[0]));
    if(!in_array($type,['text/html','text/plain'],true))throw new RuntimeException('source_format');
    if(!mb_check_encoding($raw,'UTF-8'))throw new RuntimeException('source_content');
    if($type==='text/html'){
        $dom=new DOMDocument();$old=libxml_use_internal_errors(true);
        try{
            $dom->loadHTML('<?xml encoding="UTF-8">'.$raw,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
            $title=$dom->getElementsByTagName('title')->item(0)?->textContent??'';
            if(preg_match('/just a moment|access denied|attention required|verify (?:you are|your identity)|sign in to continue/i',$title))throw new RuntimeException('source_access');
            $xpath=new DOMXPath($dom);
            foreach(iterator_to_array($xpath->query('//script|//style|//nav|//footer|//noscript|//template'))as$node)$node->parentNode?->removeChild($node);
            $main=$xpath->query('//main')->item(0)??$xpath->query('//article')->item(0)??$dom;
            // Preserve word boundaries between block elements in the evidence text.
            foreach(iterator_to_array($xpath->query('//p|//div|//li|//h1|//h2|//h3|//td|//br'))as$node)$node->appendChild($dom->createTextNode(' '));
            $raw=$main->textContent;
        }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }
    $text=om_normalize(html_entity_decode($raw,ENT_QUOTES|ENT_HTML5,'UTF-8'));
    if(mb_strlen($text)<100)throw new RuntimeException('source_content');
    if(mb_strlen($text)<1800&&preg_match('/verify you are human|enable javascript and cookies to continue|automated (?:access|requests) (?:are )?(?:blocked|prohibited)/i',$text))throw new RuntimeException('source_access');
    return mb_substr($text,0,24000);
}
function om_public_url(string $url): string {
    $url=desk_url($url,'official monitoring source',true);$p=parse_url($url);
    if(!$p||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||(isset($p['port'])&&$p['port']!==443))throw new RuntimeException('source_address_review');
    return $url;
}
function om_redirect(string $url,string $location): string {
    if($location===''||preg_match('/[\x00-\x20\x7f\\\\]/',$location))throw new RuntimeException('source_redirect_review');
    if(str_starts_with($location,'//'))return om_public_url('https:'.$location);
    if(preg_match('/^[a-z][a-z0-9+.-]*:/i',$location))return om_public_url($location);
    $p=parse_url($url);$root='https://'.$p['host'];$path=$p['path']??'/';
    if(str_starts_with($location,'?'))return om_public_url($root.$path.$location);
    if(str_starts_with($location,'#'))throw new RuntimeException('source_redirect_review');
    $relative=str_starts_with($location,'/')?$location:substr($path,0,(int)strrpos($path,'/')+1).$location;
    $parts=explode('?',explode('#',$relative,2)[0],2);$segments=[];
    foreach(explode('/',$parts[0])as$segment){if($segment==='..')array_pop($segments);elseif($segment!=='.'&&$segment!=='')$segments[]=$segment;}
    return om_public_url($root.'/'.implode('/',$segments).($segments&&str_ends_with($parts[0],'/')?'/':'').(isset($parts[1])?'?'.$parts[1]:''));
}
function om_request(string $url,string $host,string $ip,int $timeout): array {
    $raw='';$location='';$type='';$oversized=false;$ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>min(5,$timeout),CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_PROXY=>'',CURLOPT_ENCODING=>'',CURLOPT_USERAGENT=>'Waithaka-Opportunity-Review/2.0',
        CURLOPT_HTTPHEADER=>['Accept: text/html,text/plain'],
        CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$raw,&$oversized){if(strlen($raw)+strlen($chunk)>1048576){$oversized=true;return 0;}$raw.=$chunk;return strlen($chunk);},
        CURLOPT_HEADERFUNCTION=>static function($ch,$header)use(&$location,&$type){if(strncasecmp($header,'Location:',9)===0)$location=trim(substr($header,9));if(strncasecmp($header,'Content-Type:',13)===0)$type=strtolower(trim(substr($header,13)));return strlen($header);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$errno=curl_errno($ch);curl_close($ch);
    if($oversized)throw new RuntimeException('source_size');
    if($ok===false)throw new RuntimeException($errno===CURLE_OPERATION_TIMEDOUT?'source_timeout':'source_unavailable');
    return ['status'=>$status,'location'=>$location,'type'=>$type,'body'=>$raw];
}
/** Injected transport is only used by isolated tests. Production uses public DNS and HTTPS. */
function om_fetch(string $source,?callable $resolve=null,?callable $request=null): string {
    $url=om_public_url($source);$origin=preg_replace('/^www\./','',strtolower((string)parse_url($url,PHP_URL_HOST)));$start=microtime(true);
    for($hop=0;$hop<3;$hop++){
        $host=(string)parse_url($url,PHP_URL_HOST);
        if(preg_replace('/^www\./','',strtolower($host))!==$origin)throw new RuntimeException('source_redirect_review');
        $ips=$resolve?$resolve($host):gethostbynamel($host);if(!is_array($ips)||!$ips)throw new RuntimeException('source_dns');
        foreach($ips as$ip)if(!is_string($ip)||!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new RuntimeException('source_address_review');
        $remaining=(int)ceil(15-(microtime(true)-$start));if($remaining<1)throw new RuntimeException('source_timeout');
        $response=($request??'om_request')($url,$host,$ips[0],$remaining);$status=(int)($response['status']??0);
        if(in_array($status,[301,302,303,307,308],true)){$url=om_redirect($url,(string)($response['location']??''));continue;}
        if(in_array($status,[401,403,999],true))throw new RuntimeException('source_access');
        if($status===429)throw new RuntimeException('source_rate_limit');
        if(in_array($status,[404,410],true))throw new RuntimeException('source_not_found');
        if($status>=500)throw new RuntimeException('source_server');
        if($status!==200)throw new RuntimeException('source_unavailable');
        return om_text((string)($response['body']??''),(string)($response['type']??''));
    }
    throw new RuntimeException('source_redirect_review');
}
function om_parse(string $raw,array $texts,bool $requireEvidence=false): array {
    if(strlen($raw)>16000)throw new RuntimeException('analysis_invalid');
    $raw=trim(preg_replace('/^```(?:json)?\s*|\s*```$/','',trim($raw))??'');
    try{$d=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){throw new RuntimeException('analysis_invalid');}
    if(!is_array($d)||!is_bool($d['changed']??null))throw new RuntimeException('analysis_invalid');
    $changed=$d['changed'];$kind=$d['kind']??'';
    if($changed&&!in_array($kind,['deadline','closed','eligibility','funding','fees','application','other'],true))throw new RuntimeException('analysis_invalid');
    if($requireEvidence&&($d['same_round']??null)!==true&&(!$changed||$kind!=='other'))throw new RuntimeException('analysis_source_mismatch');
    if(!$changed&&!$requireEvidence)return ['changed'=>false];
    $index=$d['source_index']??null;$quote=is_string($d['quote']??null)?om_normalize($d['quote']):'';
    if(!is_int($index)||!isset($texts[$index])||mb_strlen($quote)<15||mb_strlen($quote)>1200||!str_contains(om_normalize($texts[$index]),$quote))throw new RuntimeException('analysis_evidence_missing');
    if(!$changed)return ['changed'=>false,'source_index'=>$index,'quote'=>$quote,'same_round'=>true];
    $date=$d['deadline_date']??'';if(!is_string($date)||($date!==''&&!ol_date_valid($date)))throw new RuntimeException('analysis_date_invalid');
    if(!is_string($d['summary']??''))throw new RuntimeException('analysis_invalid');
    return ['changed'=>true,'kind'=>$kind,'summary'=>mb_substr(strip_tags($d['summary']??''),0,1500),'source_index'=>$index,'quote'=>$quote,'deadline_date'=>$date];
}
function om_retry(int $now,int $failures,int $interval): int { return $now+min($interval,3600*(2**min(4,max(0,$failures-1)))); }
function om_due(array $m,int $interval): int {
    if(($m['engine_version']??'')!==om_version())return 0;
    return min((int)($m['next_check']??0),(int)($m['last_attempt']??0)+$interval);
}
function om_finish(array $m): array {
    $item=array_intersect_key($m,array_flip(['last_attempt','fetch_state','fetch_error','source_hash','ai_state','ai_error','listing_digest','next_check']));
    $m['attempt_history']=array_slice([...($m['attempt_history']??[]),$item],-20);return $m;
}
function om_scan(array $r,array $m,array $cfg,?callable $fetch=null,?callable $ai=null,?int $now=null): array {
    $now??=time();$interval=ol_interval(ol_public_record($r),$now)??7*86400;$sources=ol_sources($r);$texts=[];$readSources=[];$reports=[];
    $m['engine_version']=om_version();$m['last_attempt']=$now;$m['attempted_sources']=$sources;$m['next_check']=$now+$interval;
    foreach($sources as$url){
        try{$text=($fetch??'ol_fetch_source')($url);if(!is_string($text)||mb_strlen($text)<100)throw new RuntimeException('source_content');$texts[]=$text;$readSources[]=$url;$reports[]=['url'=>$url,'state'=>'checked','at'=>$now];}
        catch(Throwable $error){$reports[]=['url'=>$url,'state'=>'needs_review','reason'=>om_error($error,'source'),'at'=>$now];}
    }
    $m['source_reports']=$reports;
    if(!$texts){$m['fetch_state']='needs_review';$m['fetch_error']=$sources?($reports[0]['reason']??'source_unavailable'):'source_missing';$m['failures']=(int)($m['failures']??0)+1;$m['next_check']=om_retry($now,$m['failures'],$interval);$m['ai_state']='awaiting_source';return om_finish($m);}
    $complete=count($texts)===count($sources);$hash=hash('sha256',json_encode(['sources'=>$readSources,'texts'=>$texts],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $m['fetch_state']=$complete?'checked':'partial';$m['last_success']=$now;$m['failures']=$complete?0:(int)($m['failures']??0)+1;unset($m['fetch_error']);
    if(!$complete)$m['next_check']=om_retry($now,$m['failures'],$interval);
    if(($m['source_hash']??'')!==$hash){$m['previous_hash']=$m['source_hash']??null;$m['source_changed_at']=$now;$m['previous_texts']=$m['texts']??[];}
    $m['source_hash']=$hash;$m['texts']=$texts;$m['sources']=$readSources;
    if(($m['analysis_hash']??'')===$hash&&($m['listing_digest']??'')===($r['public']['digest']??'')&&($m['analysis_version']??'')===om_version()){$m['ai_state']=!empty($m['proposal'])?'review_required':'no_change';return om_finish($m);}
    if(empty($cfg['ai_enabled'])){$m['ai_state']='disabled';return om_finish($m);}
    if(!$ai&&trim((string)setting('openai.api_key',''))===''){$m['ai_state']='not_configured';return om_finish($m);}
    $day=gmdate('Y-m-d',$now);$budget=comm_get('ol.budget.'.$day);$used=(int)($budget['calls']??0);
    if(!$ai&&$used>=(int)$cfg['ai_daily_limit']){$m['ai_state']='daily_limit';$m['next_check']=min($m['next_check'],strtotime('tomorrow UTC',$now)+60);return om_finish($m);}
    if(!$ai)comm_put('ol.budget.'.$day,['calls'=>$used+1]);
    try{
        $result=$ai?$ai($r,$texts):ol_analyse($r,$texts);
        if($ai)$result=om_parse(json_encode($result,JSON_THROW_ON_ERROR),$texts,false);
        if(!is_array($result)||!is_bool($result['changed']??null))throw new RuntimeException('analysis_invalid');
    }catch(Throwable $error){$m['ai_state']='needs_review';$m['ai_error']=om_error($error,'analysis');$m['ai_failures']=(int)($m['ai_failures']??0)+1;$m['next_check']=min($m['next_check'],$m['ai_error']==='analysis_credentials'?$now+$interval:om_retry($now,$m['ai_failures'],$interval));return om_finish($m);}
    $m['analysis_version']=om_version();$m['analysis_hash']=$hash;$m['listing_digest']=$r['public']['digest'];$m['ai_checked_at']=$now;$m['ai_failures']=0;unset($m['ai_error']);
    if(isset($result['source_index'],$result['quote']))$m['analysis_evidence']=['source_url'=>$readSources[$result['source_index']],'quote'=>$result['quote'],'at'=>$now];
    $m['ai_state']=$result['changed']?'review_required':'no_change';
    if($result['changed']){$proposal=$result+['source_hash'=>$hash,'public_digest'=>$r['public']['digest'],'at'=>$now,'source_url'=>$readSources[$result['source_index']]];$proposal['id']=hash('sha256',json_encode($proposal,JSON_THROW_ON_ERROR));$m['proposal']=$proposal;}
    elseif(!empty($m['proposal']))$m['ai_state']='review_required';
    return om_finish($m);
}
