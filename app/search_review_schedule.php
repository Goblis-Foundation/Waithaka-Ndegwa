<?php
declare(strict_types=1);

/** Opt-in Search Console reads. No content writes, indexing calls, email or AI. */
function srs_defaults(): array {
    return ['owner'=>0,'enabled'=>false,'days'=>28,'cadence'=>'weekly','version'=>0,
        'binding'=>'','next_at'=>0,'attempted_at'=>0,'state'=>'disabled','reports'=>[]];
}
function srs_config(): array {return array_replace(srs_defaults(),comm_get('search.review.schedule'));}
function srs_interval(string $cadence): int {
    return match($cadence){'daily'=>86400,'weekly'=>604800,default=>throw new InvalidArgumentException('Choose daily or weekly reviews.')};
}
function srs_binding(array $connection): string {
    $fields=[];foreach(['id','service','provider','status','external_id','selected_resource','connected_by','verified_at','scopes_json']as$key)$fields[$key]=$connection[$key]??null;
    $fields['client']=publishing_client('google');
    return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR));
}
function srs_connection(int $owner): array {
    if($owner<1||$owner!==(int)(comm_config()['owner']??0))throw new RuntimeException('Owner access required.');
    return google_workspace_connection('google_search_console',$owner);
}
function srs_save(int $owner,array $input,?int $now=null): void {
    $now??=time();$enabled=($input['enabled']??'')==='1';
    $days=$input['days']??'';$cadence=$input['cadence']??'';
    if(!is_string($days)||!in_array($days,['28','90'],true)||!is_string($cadence))throw new InvalidArgumentException('Choose a 28-day or 90-day review.');
    $interval=srs_interval($cadence);
    if($owner<1||$owner!==(int)(comm_config()['owner']??0))throw new RuntimeException('Owner access required.');
    if($enabled&&($input['consent']??'')!=='1')throw new InvalidArgumentException('Allow scheduled reads of this selected Search Console property.');
    // Disabling must work even when provider access has been removed.
    $binding=$enabled?srs_binding(srs_connection($owner)):'';
    if($enabled&&(!is_string($input['connection']??null)||!hash_equals($binding,$input['connection'])))throw new InvalidArgumentException('The selected property or account changed. Reload and review it before opting in.');
    comm_lock('search.review.schedule',static function()use($input,$owner,$enabled,$days,$cadence,$interval,$binding,$now){
        $old=srs_config();
        if(!is_string($input['version']??null)||(string)$old['version']!==$input['version'])throw new InvalidArgumentException('Review settings changed in another tab. Reload before saving.');
        if($enabled&&!hash_equals($binding,srs_binding(srs_connection($owner))))throw new RuntimeException('The selected Search Console connection changed. Review it again.');
        // New configuration cancels in-flight work and clears older snapshots.
        comm_put('search.review.schedule',array_replace(srs_defaults(),['owner'=>$owner,'enabled'=>$enabled,'days'=>(int)$days,'cadence'=>$cadence,
            'version'=>(int)$old['version']+1,'binding'=>$binding,'next_at'=>$enabled?$now+$interval:0,'state'=>$enabled?'scheduled':'disabled']));
    });
}
function srs_matches(array $cfg,int $owner): bool {
    if(empty($cfg['enabled'])||($cfg['owner']??0)!==$owner)return false;
    try{return hash_equals((string)$cfg['binding'],srs_binding(srs_connection($owner)));}catch(Throwable){return false;}
}
/** Attempt marker precedes I/O. A crash waits until the next interval rather than flooding the API. */
function srs_work(?int $now=null,?callable $reader=null,bool $requested=false): array {
    $now??=time();$owner=(int)(comm_config()['owner']??0);
    $claim=comm_lock('search.review.schedule',static function()use($now,$owner,$requested){
        $cfg=srs_config();if(empty($cfg['enabled']))return null;
        if(!srs_matches($cfg,$owner)){
            $cfg['state']='connection_changed';$cfg['reports']=[];$cfg['enabled']=false;$cfg['version']++;$cfg['next_at']=0;
            comm_put('search.review.schedule',$cfg);return null;
        }
        // One active request, including an owner-requested read; a stale claim expires after 15 minutes.
        if(($cfg['state']??'')==='reading'&&(int)$cfg['attempted_at']>$now-900)return null;
        if(!$requested&&(int)$cfg['next_at']>$now)return null;
        if($requested&&(int)$cfg['attempted_at']>$now-3600)return null;
        $cfg['attempted_at']=$now;$cfg['next_at']=$now+srs_interval($cfg['cadence']);$cfg['state']='reading';$cfg['claim']=bin2hex(random_bytes(16));
        comm_put('search.review.schedule',$cfg);return $cfg;
    });
    if(!$claim)return ['enabled'=>!empty(srs_config()['enabled']),'checked'=>0,'failed'=>0];
    $report=null;$failed=false;
    try{$report=($reader??static fn(int $days,int $uid):array=>google_search_review($days,null,null,$uid))($claim['days'],$owner);}
    catch(Throwable){$failed=true;}
    $saved=comm_lock('search.review.schedule',static function()use($claim,$owner,$report,$failed,$now){
        $current=srs_config();
        if($current['version']!==$claim['version']||($current['claim']??'')!==$claim['claim']||!srs_matches($current,$owner))return false;
        $current['state']=$failed?'review_failed':'completed';$current['finished_at']=$now;unset($current['claim']);
        // Failed reads keep the last successful dated report; they never fabricate an empty report.
        if(!$failed&&is_array($report))$current['reports']=array_slice(array_merge([$report],$current['reports']),0,6);
        comm_put('search.review.schedule',$current);return true;
    });
    return ['enabled'=>true,'checked'=>$saved&&!$failed?1:0,'failed'=>$saved&&$failed?1:0];
}
function srs_dispatch(string $path): bool {
    if($path!=='/write/google/search-reviews')return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);
    $owner=(int)(comm_config()['owner']??0);$uid=(int)(current_user()['id']??0);
    if($owner<1||$uid!==$owner){http_response_code(403);echo 'Owner access required.';return true;}
    if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')return true;
    $error='';$notice='';
    if(is_post()){
        verify_csrf();
        try{
            $action=$_POST['action']??'';
            if($action==='save'){srs_save($owner,$_POST);$notice='Search review preferences saved. No provider read or publication occurred.';}
            elseif($action==='read'){
                if(!journeys_rate_limit('scheduled-search-review',(string)$uid,4,3600))throw new RuntimeException('Please wait before another search review.');
                $result=srs_work(null,null,true);$notice=$result['checked']?'Search Console review saved privately.':'No new report saved. Check the schedule, connection and last attempt; reads are limited to one per hour.';
            }else throw new InvalidArgumentException('Choose an available search-review action.');
        }catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'Search review could not be completed. Review the connection and try later. No provider error or credential is displayed.';}
    }
    $cfg=srs_config();$same=srs_matches($cfg,$owner);$reports=$same?$cfg['reports']:[];
    $target=null;try{$connection=srs_connection($owner);$target=['account'=>(string)($connection['display_name']??'Confirmed owner account'),'property'=>(string)$connection['selected_resource'],'fingerprint'=>srs_binding($connection)];}catch(Throwable){}
    render_write('search_reviews',['writeTitle'=>'Scheduled search reviews','schedule'=>$cfg,'reports'=>$reports,'connectionMatches'=>$same,'target'=>$target,'error'=>$error,'notice'=>$notice]);return true;
}
