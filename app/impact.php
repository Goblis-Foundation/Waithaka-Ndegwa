<?php
declare(strict_types=1);

/** Aggregate reporting only: no enterprise/person records or provider credentials leave this module. */
function impact_sources(): array {
    return ['goblis_group'=>'Goblis Group','ubunifu'=>'Ubunifu Institute','afritrace'=>'AfriTrace Link','goblis_foundation'=>'Goblis Foundation'];
}

/** Source registry is an integration contract, not live account activation. */
function impact_source_exchange(): array {
 return [
  'goblis_group'=>['metric_keys'=>['startups','permanent_staff'],'ownership'=>'Goblis Group certifies cumulative qualifying startups and month-end permanent staff across its direct enterprise scope.','route'=>'/api/public/impact/waithaka/'],
  'ubunifu'=>['metric_keys'=>['social_enterprises','ubunifu_permanent_staff (optional)'],'ownership'=>'Ubunifu Institute approves incubated social-enterprise outcomes and any verified employment aggregates. Its export code is distinct from partner learner referrals.','route'=>'/api/public/impact/waithaka/'],
  'afritrace'=>['metric_keys'=>['market_access'],'ownership'=>'AfriTrace Link provides the primary verified distinct-enterprise market-access total under Goblis Foundation programme governance.','route'=>'/api/public/impact/waithaka/'],
  'goblis_foundation'=>['metric_keys'=>['market_access (corroboration only)'],'ownership'=>'Goblis Foundation corroborates AfriTrace outcomes; its figures are not added to the AfriTrace headline goal.','route'=>'/api/public/impact/waithaka/']
 ];
}
function impact_source_readiness(string $source,string $url,array $snapshot): array {
 if(!isset(impact_source_exchange()[$source]))throw new InvalidArgumentException('Unknown reporting source.');
 if($url==='')return ['label'=>'Endpoint not configured','detail'=>'Source approval, route and receiving URL remain unverified. No impact value is inferred.'];
 if(($snapshot['status']??'')==='available'&&is_array($snapshot['data']??null)
    &&($snapshot['data']['approved_for_publication']??null)===true)
  return ['label'=>'Approved report accepted','detail'=>'Read the exact measurement and publication dates below. This is not a guarantee of continued source availability.'];
 if(($snapshot['status']??'')==='withheld'||is_array($snapshot['withdrawn_report']??null))
  return ['label'=>'Source withdrew publication','detail'=>'The source revision withholds metrics. This is not a measured zero.'];
 if(($snapshot['status']??'')==='unavailable')
  return ['label'=>'Connection check unavailable','detail'=>'The last accepted report, if any, is preserved separately. Recheck source route and approval; never replace with invented counts.'];
 return ['label'=>'Configured · verification pending','detail'=>'A saved endpoint is not proof of authorized or approved data.'];
}

function impact_organisations(): array {
    return [
        ['name'=>'Goblis Group','url'=>'https://www.goblisgroup.com/','domain'=>'goblisgroup.com','logo'=>'goblis-group-approved.png'],
        ['name'=>'Ubunifu Institute','url'=>'https://www.ubunifuinstitute.com/','domain'=>'ubunifuinstitute.com','logo'=>'ubunifu.svg'],
        ['name'=>'AfriTrace Link','url'=>'https://www.afritracelink.com/','domain'=>'afritracelink.com','logo'=>'afritrace-approved.jpg'],
        ['name'=>'Goblis Foundation','url'=>'https://www.goblisfoundation.org/','domain'=>'goblisfoundation.org','logo'=>'goblis-foundation.png'],
    ];
}
function impact_metrics(): array {
    return [
        'startups'=>['target'=>30,'label'=>'Successful startups','source'=>'goblis_group','copy'=>'Establish or be directly involved in 30 successful startups through Goblis Group.'],
        'permanent_staff'=>['target'=>2030,'label'=>'Permanent staff','source'=>'goblis_group','copy'=>'Build those enterprises to employ 2,030 permanent staff collectively by June 2030.'],
        'social_enterprises'=>['target'=>2030,'label'=>'Social enterprises established','source'=>'ubunifu','copy'=>'Help establish 2,030 successful social enterprises through incubation at Ubunifu Institute.'],
        'ubunifu_permanent_staff'=>['target'=>10150,'label'=>'Permanent jobs through incubated enterprises','source'=>'ubunifu','optional'=>true,'copy'=>'Support 2,030 social enterprises incubated at Ubunifu Institute to employ an average of 5 permanent staff each—10,150 permanent jobs across those enterprises. Ubunifu contributes through incubation; the enterprises are the employers. This is a target, not a reported result.'],
        'market_access'=>['target'=>30000,'label'=>'African enterprises connected to markets','source'=>'afritrace','copy'=>'Connect 30,000 African social enterprises to markets through Goblis Foundation’s AfriTrace Link, with planned coverage across all 55 African countries.'],
    ];
}
function impact_indirect_groups(): array {
    return [
        'goblis_group'=>['label'=>'Goblis Group','copy'=>'Household members supported by employees’ earnings, and people in local supplier and service networks who experience a documented improvement in livelihoods. Employees already counted directly are excluded.'],
        'ubunifu'=>['label'=>'Ubunifu Institute','copy'=>'Household members of enterprise founders and employees, and community members who benefit indirectly from the enterprises’ social or environmental work. Founders, employees and people directly receiving enterprise services are counted separately.'],
        'goblis_foundation'=>['label'=>'Goblis Foundation · AfriTrace Link','copy'=>'Household members of participating producers and enterprise workers, and people benefiting from improved local supplier, logistics and market networks. A listed enterprise, buyer introduction or sale alone does not establish a beneficiary count.']
    ];
}
/** Explicit timestamps are distinct from measurement periods and local fetch checks. */
function impact_stamp(mixed $value,int $now): int {
    if(!is_string($value)||!preg_match('/\\A\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}(?:Z|[+-]\\d{2}:\\d{2})\\z/',$value))throw new InvalidArgumentException('An explicit reporting timestamp is required.');
    if(preg_match('/([+-])(\\d{2}):(\\d{2})\\z/',$value,$offset)&&((int)$offset[2]>14||(int)$offset[3]>59||((int)$offset[2]===14&&(int)$offset[3]!==0)))throw new InvalidArgumentException('Invalid reporting timezone offset.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:sP',str_ends_with($value,'Z')?substr($value,0,-1).'+00:00':$value);$errors=DateTimeImmutable::getLastErrors();
    if(!$d||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$d->getTimestamp()<strtotime('2026-07-01T00:00:00+03:00')||$d->getTimestamp()>$now+300)throw new InvalidArgumentException('Invalid reporting timestamp.');return $d->getTimestamp();
}
function impact_report_dates(array $data,int $now): array {
    if($data['schema_version']===1){$stamp=impact_stamp($data['as_of']??null,$now);return ['schema_version'=>1,'approved_for_publication'=>true,'reporting_frequency'=>'legacy','as_of'=>gmdate('c',$stamp),'published_at'=>null,'approved_at'=>null];}
    $allowed=['schema_version','source','period_start','period_end','definition_version','approved_for_publication','publication_status','report_id','revision','reporting_frequency','measurement_period_start','measurement_period_end','measurement_timezone','approved_at','published_at','metrics','beneficiary_basis','beneficiary_deduplicated'];
    if(array_diff(array_keys($data),$allowed))throw new InvalidArgumentException('Only the approved aggregate report contract is accepted.');
    $approved=($data['approved_for_publication']??null)===true;
    if(!in_array($data['approved_for_publication']??null,[true,false],true)||($data['publication_status']??null)!==($approved?'approved':'withdrawn')||($data['reporting_frequency']??null)!=='monthly')throw new InvalidArgumentException('Explicit monthly publication approval is required.');
    $id=$data['report_id']??null;$rev=$data['revision']??null;
    if(!is_string($id)||!preg_match('/\\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,79}\\z/D',$id)||!is_int($rev)||$rev<1||$rev>1000000)throw new InvalidArgumentException('A report ID and positive revision are required.');
    $zone=$data['measurement_timezone']??null;if(!is_string($zone)||!in_array($zone,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('A valid measurement timezone is required.');$z=new DateTimeZone($zone);
    $start=$data['measurement_period_start']??null;$end=$data['measurement_period_end']??null;
    if(!is_string($start)||!is_string($end)||!preg_match('/\\A\\d{4}-\\d{2}-01\\z/D',$start))throw new InvalidArgumentException('A closed monthly measurement period is required.');
    $month=DateTimeImmutable::createFromFormat('!Y-m-d',$start,$z);$errors=DateTimeImmutable::getLastErrors();
    if(!$month||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$month->format('Y-m-d')!==$start||$end!==$month->format('Y-m-t')||$start<'2026-07-01'||$end>'2030-06-30')throw new InvalidArgumentException('Invalid monthly measurement period.');
    $closed=$month->modify('first day of next month')->getTimestamp();$published=impact_stamp($data['published_at']??null,$now);
    $approval=$approved?impact_stamp($data['approved_at']??null,$now):null;
    if($closed>$now||$published<$closed||($approved&&($approval<$closed||$approval>$published)))throw new InvalidArgumentException('Measurement must close before approval and publication.');
    if(!$approved&&array_intersect(['metrics','beneficiary_basis','beneficiary_deduplicated'],array_keys($data)))throw new InvalidArgumentException('Withdrawals must not transmit unapproved metrics.');
    $due=$end==='2030-06-30'?null:$month->modify('first day of +2 months')->modify('+14 days')->setTime(23,59,59)->format('c');
    return ['schema_version'=>2,'approved_for_publication'=>$approved,'publication_status'=>$approved?'approved':'withdrawn','report_id'=>$id,'revision'=>$rev,'reporting_frequency'=>'monthly','measurement_period_start'=>$start,'measurement_period_end'=>$end,'measurement_timezone'=>$zone,'as_of'=>gmdate('c',$closed-1),'approved_at'=>$approval===null?null:gmdate('c',$approval),'published_at'=>gmdate('c',$published),'next_report_due_at'=>$due];
}
/** Repeated checks cannot change measurement age, publication time or an accepted revision. */
function impact_accept_report(array $old,array $fresh,?int $now=null): array {
    $now??=time();$prior=$old['data']??$old['withdrawn_report']??null;
    if(is_array($prior)){
        if(($prior['schema_version']??1)===2&&($fresh['schema_version']??1)!==2)throw new RuntimeException('A monthly source cannot downgrade to the legacy reporting contract.');
        $before=($prior['schema_version']??1)===2?$prior['measurement_period_end']:strtotime((string)($prior['as_of']??''));$after=($prior['schema_version']??1)===2?$fresh['measurement_period_end']:strtotime($fresh['as_of']);if($after<$before)throw new RuntimeException('An older measurement cannot replace a newer report.');
        if(($prior['schema_version']??1)===2&&$after===$before){
            if($fresh['report_id']!==$prior['report_id']||$fresh['revision']<$prior['revision']||strtotime($fresh['published_at'])<strtotime($prior['published_at']))throw new RuntimeException('The report identity or revision regressed.');
            if($fresh['revision']===$prior['revision']){foreach(['metrics','approved_for_publication','approved_at','published_at','beneficiary_basis','measurement_period_start','measurement_period_end','measurement_timezone']as$key)if(($fresh[$key]??null)!==($prior[$key]??null))throw new RuntimeException('A changed report requires a new revision.');}
        }
    }
    return ['status'=>$fresh['approved_for_publication']?'available':'withheld','attempted_at'=>gmdate('c',$now),'last_success_at'=>gmdate('c',$now),'data'=>$fresh['approved_for_publication']?$fresh:null,'withdrawn_report'=>$fresh['approved_for_publication']?null:$fresh];
}
function impact_validate(array $data,string $source,?int $now=null): array {
    $now??=time();
    if(!isset(impact_sources()[$source]) || !in_array($data['schema_version']??null,[1,2],true) || ($data['source']??null)!==$source
        || ($data['period_start']??null)!=='2026-07-01' || ($data['period_end']??null)!=='2030-06-30'
        || ($data['definition_version']??null)!=='2030-v1' || (($data['schema_version']??null)===1&&($data['approved_for_publication']??null)!==true)) throw new InvalidArgumentException('Reporting contract does not match.');
    $meta=impact_report_dates($data,$now);
    if(!$meta['approved_for_publication'])return ['source'=>$source]+$meta+['metrics'=>[],'received_at'=>gmdate('c',$now),'beneficiary_basis'=>null];
    $values=$data['metrics']??null;if(!is_array($values))throw new InvalidArgumentException('Missing aggregate metrics.');
    $keys=$source==='goblis_foundation'?['market_access']:array_keys(array_filter(impact_metrics(),fn($m)=>$m['source']===$source));
    if(($data['schema_version']??1)===2&&array_diff(array_keys($values),array_merge($keys,['indirect_beneficiaries'])))throw new InvalidArgumentException('Only approved aggregate metric keys are accepted.');
    $metrics=[];foreach($keys as$key){if(!array_key_exists($key,$values)&&!empty(impact_metrics()[$key]['optional']))continue;$value=$values[$key]??null;if(!is_int($value)||$value<0||$value>1000000000)throw new InvalidArgumentException('Invalid aggregate count.');$metrics[$key]=$value;}
    if(($data['schema_version']??1)===2&&!array_key_exists('indirect_beneficiaries',$values)&&array_intersect(['beneficiary_basis','beneficiary_deduplicated'],array_keys($data)))throw new InvalidArgumentException('Beneficiary methodology requires a beneficiary aggregate.');
    $basis=null;
    if(array_key_exists('indirect_beneficiaries',$values)){
        $value=$values['indirect_beneficiaries'];$basis=$data['beneficiary_basis']??null;
        if(!is_int($value)||$value<0||$value>1000000000||!is_string($basis)||trim($basis)===''||mb_strlen($basis)>1500||($data['beneficiary_deduplicated']??false)!==true)throw new InvalidArgumentException('Indirect beneficiary counts require a public methodology and deduplication confirmation.');
        $metrics['indirect_beneficiaries']=$value;$basis=trim(strip_tags($basis));
    }
    // Deliberately discard every unrecognised field, including names, contacts and raw records.
    return ['source'=>$source]+$meta+['metrics'=>$metrics,'received_at'=>gmdate('c',$now),'beneficiary_basis'=>$basis];
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
            $saved=impact_accept_report($old,$fresh);$results[$source]=$fresh['approved_for_publication']?'updated':'withheld';
        }catch(Throwable $e){$saved=['status'=>'unavailable','attempted_at'=>gmdate('c'),'last_success_at'=>$old['last_success_at']??null,'withdrawn_report'=>$old['withdrawn_report']??null,'data'=>$old['data']??null];$results[$source]='unavailable';}
        set_setting('impact.snapshot.'.$source,json_encode($saved,JSON_THROW_ON_ERROR));
    }}finally{$pdo->query("SELECT RELEASE_LOCK('waithaka-impact-sync')");}
    return $results;
}
function impact_display(array $snapshot,string $key,?int $now=null): array {
    $now??=time();$data=$snapshot['data']??null;$base=['value'=>null,'status'=>'Awaiting source data','as_of'=>null,'published_at'=>null,'measurement_period_start'=>null,'measurement_period_end'=>null,'measurement_timezone'=>null,'next_report_due_at'=>null,'connection_status'=>($snapshot['status']??'')==='available'?'Source reachable':'Source check pending','checked_at'=>$snapshot['attempted_at']??null];
    if(!is_array($data)||($data['approved_for_publication']??null)!==true||!is_int($data['metrics'][$key]??null))return $base;
    $monthly=($data['schema_version']??1)===2&&($data['reporting_frequency']??'')==='monthly';
    $stale=$monthly?(!empty($data['next_report_due_at'])&&$now>(strtotime($data['next_report_due_at'])?:0)):($now-(strtotime((string)($data['as_of']??''))?:0)>172800);
    return array_replace($base,['value'=>$data['metrics'][$key],'status'=>$stale?($monthly?'Last reported · monthly update overdue':'Last reported · update pending'):'Source-reported progress','as_of'=>$data['as_of'],'published_at'=>$data['published_at']??null,'measurement_period_start'=>$data['measurement_period_start']??null,'measurement_period_end'=>$data['measurement_period_end']??null,'measurement_timezone'=>$data['measurement_timezone']??null,'next_report_due_at'=>$data['next_report_due_at']??null]);
}
/** Feed configuration and approved-source checks are super-administrator actions. */
function impact_writer_access(int $userId,int $ownerId): bool {return $userId>0&&$ownerId>0&&$userId===$ownerId;}
function impact_writer_route(string $path): bool {
    if($path!=='/write/impact')return false;
    require_auth();private_response_headers();
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','POST'],true)){http_response_code(405);return true;}
    // Reject invalid CSRF even for a Writer without publication authority.
    if(is_post())verify_csrf();
    if(!impact_writer_access((int)(current_user()['id']??0),(int)(comm_config()['owner']??0))){
        if(is_post()){http_response_code(403);echo 'Owner access required to edit reporting sources.';return true;}
        render_write('impact_observer',['writeTitle'=>'Goal 2030 · source status']);return true;
    }
    if(is_post()){
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
