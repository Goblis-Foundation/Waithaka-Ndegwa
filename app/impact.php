<?php
declare(strict_types=1);

/** Aggregate reporting only: no enterprise/person records or provider credentials leave this module. */
function impact_sources(): array {
    return ['goblis_group'=>'Goblis Group','ubunifu'=>'Ubunifu Institute','afritrace'=>'AfriTrace Link','goblis_foundation'=>'Goblis Foundation'];
}
function impact_organisations(): array {
    return [
        ['name'=>'Goblis Group','url'=>'https://www.goblisgroup.com/','domain'=>'goblisgroup.com','logo'=>'goblis-group.svg'],
        ['name'=>'Ubunifu Institute','url'=>'https://www.ubunifuinstitute.com/','domain'=>'ubunifuinstitute.com','logo'=>'ubunifu.svg'],
        ['name'=>'AfriTrace Link','url'=>'https://www.afritracelink.com/','domain'=>'afritracelink.com','logo'=>'afritrace.png'],
        ['name'=>'Goblis Foundation','url'=>'https://www.goblisfoundation.org/','domain'=>'goblisfoundation.org','logo'=>'goblis-foundation.png'],
    ];
}
function impact_metrics(): array {
    return [
        'startups'=>['target'=>30,'label'=>'Successful startups','source'=>'goblis_group','copy'=>'Establish or be directly involved in 30 successful startups through Goblis Group.'],
        'permanent_staff'=>['target'=>2030,'label'=>'Permanent staff','source'=>'goblis_group','copy'=>'Build those enterprises to employ 2,030 permanent staff collectively by June 2030.'],
        'social_enterprises'=>['target'=>2030,'label'=>'Social enterprises established','source'=>'ubunifu','copy'=>'Help establish 2,030 successful social enterprises through Ubunifu Institute.'],
        'market_access'=>['target'=>30000,'label'=>'African enterprises connected to markets','source'=>'afritrace','copy'=>'Connect 30,000 African social enterprises to markets through Goblis Foundation’s AfriTrace Link.'],
    ];
}
function impact_validate(array $data,string $source,?int $now=null): array {
    $now??=time();
    if(!isset(impact_sources()[$source]) || ($data['schema_version']??null)!==1 || ($data['source']??null)!==$source
        || ($data['period_start']??null)!=='2026-07-01' || ($data['period_end']??null)!=='2030-06-30'
        || ($data['definition_version']??null)!=='2030-v1' || ($data['approved_for_publication']??null)!==true) throw new InvalidArgumentException('Reporting contract does not match.');
    $asOf=$data['as_of']??null;
    if(!is_string($asOf) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})\z/',$asOf))throw new InvalidArgumentException('An explicit reporting timestamp is required.');
    $stamp=strtotime($asOf);
    if($stamp===false || $stamp<strtotime('2026-07-01T00:00:00+03:00') || $stamp>$now+300)throw new InvalidArgumentException('Invalid reporting timestamp.');
    $values=$data['metrics']??null;if(!is_array($values))throw new InvalidArgumentException('Missing aggregate metrics.');
    $keys=$source==='goblis_foundation'?['market_access']:array_keys(array_filter(impact_metrics(),fn($m)=>$m['source']===$source));
    $metrics=[];foreach($keys as$key){$value=$values[$key]??null;if(!is_int($value)||$value<0||$value>1000000000)throw new InvalidArgumentException('Invalid aggregate count.');$metrics[$key]=$value;}
    // Deliberately discard every unrecognised field, including names, contacts and raw records.
    return ['source'=>$source,'as_of'=>gmdate('c',$stamp),'metrics'=>$metrics,'received_at'=>gmdate('c',$now)];
}
function impact_endpoint(string $url): array {
    $p=parse_url($url);
    if(!$p || ($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['query']) || (isset($p['port'])&&$p['port']!==443) || !filter_var($url,FILTER_VALIDATE_URL))throw new InvalidArgumentException('Use an HTTPS reporting URL without credentials, query strings or fragments.');
    $host=strtolower($p['host']);if(!preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}\z/',$host))throw new InvalidArgumentException('A public DNS hostname is required.');
    $records=dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];
    foreach($records?:[] as$r){$ip=$r['ip']??$r['ipv6']??null;if($ip!==null){if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_GLOBAL_RANGE)||str_contains($ip,':'))continue;$ips[]=$ip;}}
    if(!$ips)throw new InvalidArgumentException('The reporting host must resolve to a public IPv4 address.');
    return [$host,array_values(array_unique($ips))];
}
function impact_fetch(string $url,string $token): array {
    [$host,$ips]=impact_endpoint($url);
    if(preg_match('/[\x00-\x20\x7f]/',$token)||strlen($token)>4000)throw new InvalidArgumentException('Invalid reporting token.');
    $ch=curl_init($url);$body='';
    curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,
        CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],
        CURLOPT_HTTPHEADER=>array_values(array_filter(['Accept: application/json',$token!==''?'Authorization: Bearer '.$token:null])),
        CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);}]);
    try{$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);if($ok===false||$status!==200)throw new RuntimeException('Reporting source is unavailable.');}
    finally{curl_close($ch);}
    $data=json_decode($body,true,16,JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Invalid reporting response.');return $data;
}
function impact_snapshot(string $source): array {
    $raw=setting('impact.snapshot.'.$source,'');$s=is_string($raw)?json_decode($raw,true):null;return is_array($s)?$s:[];
}
function impact_sync(bool $force=false): array {
    $pdo=db();if((int)$pdo->query("SELECT GET_LOCK('waithaka-impact-sync',0)")->fetchColumn()!==1)return [];
    $results=[];
    try{foreach(impact_sources()as$source=>$label){
        $url=(string)setting('impact.url.'.$source,'');if($url==='')continue;
        $old=impact_snapshot($source);$attempt=strtotime((string)($old['attempted_at']??''))?:0;
        if(!$force&&time()-$attempt<900)continue;
        try{
            $fresh=impact_validate(impact_fetch($url,(string)setting('impact.token.'.$source,'')),$source);
            if(isset($old['data']['as_of'])&&strtotime($fresh['as_of'])<strtotime($old['data']['as_of']))throw new RuntimeException('An older snapshot cannot replace a newer report.');
            $saved=['status'=>'available','attempted_at'=>gmdate('c'),'data'=>$fresh];$results[$source]='updated';
        }catch(Throwable $e){$saved=['status'=>'unavailable','attempted_at'=>gmdate('c'),'data'=>$old['data']??null];$results[$source]='unavailable';}
        set_setting('impact.snapshot.'.$source,json_encode($saved,JSON_THROW_ON_ERROR));
    }}finally{$pdo->query("SELECT RELEASE_LOCK('waithaka-impact-sync')");}
    return $results;
}
function impact_display(array $snapshot,string $key,?int $now=null): array {
    $data=$snapshot['data']??null;$value=is_array($data)?($data['metrics'][$key]??null):null;
    if(!is_int($value))return ['value'=>null,'status'=>'Awaiting source data','as_of'=>null];
    $stamp=strtotime((string)($data['as_of']??''))?:0;$stale=($snapshot['status']??'')!=='available'||($now??time())-$stamp>172800;
    return ['value'=>$value,'status'=>$stale?'Last reported · update pending':'Source-reported progress','as_of'=>$data['as_of']];
}
function impact_writer_route(string $path): bool {
    if($path!=='/write/impact')return false;
    require_auth();private_response_headers();
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','POST'],true)){http_response_code(405);return true;}
    if(is_post()){
        verify_csrf();
        try{
            if(($_POST['action']??'')==='sync'){$result=impact_sync(true);flash('success',$result?'Source checks complete. Review the connection status below.':'No reporting feeds are configured.');}
            else{
                $pending=[];
                foreach(impact_sources()as$source=>$label){$url=trim((string)($_POST[$source.'_url']??''));if($url!=='')impact_endpoint($url);
                    $token=trim((string)($_POST[$source.'_token']??''));if(preg_match('/[\x00-\x20\x7f]/',$token)||strlen($token)>4000)throw new InvalidArgumentException('Invalid reporting token.');
                    $pending[$source]=[$url,$token];}
                foreach($pending as$source=>[$url,$token]){
                    if($url!==(string)setting('impact.url.'.$source,''))set_setting('impact.snapshot.'.$source,'');
                    set_setting('impact.url.'.$source,$url);if($token!=='')set_setting('impact.token.'.$source,$token,true);
                    if(isset($_POST[$source.'_clear_token']))set_setting('impact.token.'.$source,'',true);
                }flash('success','Reporting connections saved. The scheduler checks configured sources every 15 minutes.');
            }
        }catch(InvalidArgumentException $e){flash('error',$e->getMessage());}catch(Throwable $e){flash('error','The reporting connection could not be updated.');}
        redirect(url('/write/impact'));
    }
    render_write('impact',['writeTitle'=>'2030 goals & reporting']);return true;
}
