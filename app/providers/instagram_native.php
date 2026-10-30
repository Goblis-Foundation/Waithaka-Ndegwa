<?php
declare(strict_types=1);

/** Instagram Login image/Reel delivery. Persist each boundary; never replay media_publish. */
function publishing_instagram_native_ready(array $c): bool {
    $scopes=publishing_decode($c['scopes_json']??'');
    return ($c['service']??'')==='instagram'&&($c['status']??'')==='connected'
        &&preg_match('/^[0-9]{5,40}$/D',(string)($c['selected_resource']??''))
        &&(string)($c['external_id']??'')===(string)$c['selected_resource']
        &&!array_diff(['instagram_business_basic','instagram_business_content_publish'],$scopes);
}
function publishing_instagram_native_validate_variant(array $v): void {
    if(!in_array($v['format']??'',['post','reel'],true))throw new RuntimeException('Direct Instagram Login supports a JPEG post or an owned-video Reel. Keep Stories, carousels and native stickers assisted until their account/API capability is verified.');
    if(!empty($v['ai_generated']))throw new RuntimeException('Use assisted Instagram publishing when an AI disclosure is required.');
    social_options_validate('instagram',$v,['method'=>'direct']);
    if(!is_string($v['text']??null)||!mb_check_encoding($v['text'],'UTF-8')||mb_strlen($v['text'])>2200)throw new RuntimeException('Use a valid Instagram caption of at most 2,200 characters.');
    if(!publishing_public_url((string)($v['media_url']??'')))throw new RuntimeException('Instagram needs an approved public media URL without private or signed tokens.');
    if($v['format']==='post'){
        if(($v['media_type']??'')!=='image'||!preg_match('/\.jpe?g$/i',(string)parse_url($v['media_url'],PHP_URL_PATH))||trim((string)($v['alt_text']??''))==='')throw new RuntimeException('A direct Instagram image post needs a public JPEG and its image description.');return;
    }
    $asset=$v['media_asset']??[];
    if(($v['media_type']??'')!=='video'||!is_array($asset)||(int)($asset['id']??0)<1||(int)($v['media_asset_id']??0)!==(int)$asset['id']||($asset['visibility']??'')!=='public'||($asset['public_url']??'')!==$v['media_url']
        ||!in_array($asset['mime']??'',['video/mp4','video/quicktime'],true)||!is_int($asset['bytes']??null)||$asset['bytes']<1||$asset['bytes']>536870912||!preg_match('/^[a-f0-9]{64}$/D',(string)($asset['sha256']??'')))throw new RuntimeException('Select an owned public MP4 or MOV from the media library, at most 512 MiB, for a direct Reel.');
    if(($v['instagram_settings_confirmed']??false)!==true||!is_bool($v['instagram_share_to_feed']??null))throw new RuntimeException('Review the Reel audience, feed visibility and media requirements before approval.');
}
function publishing_instagram_native_id(mixed $id): string {if(!is_string($id)||!preg_match('/^[0-9]{5,40}$/D',$id))throw new PublishingRemoteException('Instagram did not return a valid media identifier. Review the existing job.',0,true);return $id;}
function publishing_instagram_native_fingerprint(array $c): string {return hash('sha256',publishing_json([$c['id'],$c['external_id'],$c['selected_resource'],$c['scopes_json'],(int)($c['connected_by']??0)]));}
function publishing_instagram_native_response(int $status,string $raw,bool $ok,bool $mutation): array {
    if(!$ok)throw new PublishingRemoteException('Instagram did not return a complete response. Check the saved job before continuing.',0,$mutation);
    $d=json_decode($raw,true);$error=is_array($d)?($d['error']??null):null;
    if($status<200||$status>=300||$error){$errorCode=is_array($error)?(int)($error['code']??0):0;$mapped=$errorCode===190?401:($errorCode===10?403:(in_array($errorCode,[4,17,32,613],true)?429:$status));
        throw new PublishingRemoteException(match($mapped){401=>'Reconnect the same Instagram account.',403=>'Instagram denied the requested format or permission.',429=>'Instagram rate limit reached. Keep this job for review.',default=>'Instagram rejected the request. Check account eligibility, media and quota.'},$mapped,$mutation&&($mapped>=500||$mapped===408));}
    if(!is_array($d))throw new PublishingRemoteException('Instagram returned an unreadable result. Check the saved job.',0,$mutation);return $d;
}
function publishing_instagram_native_http(string $method,string $path,array $query,string $token,?array $body=null): array {
    if(!preg_match('~^/(?:me|[0-9]{5,40}(?:/(?:media|media_publish))?)$~D',$path)||!in_array($method,['GET','POST'],true)||($method==='POST'&&!preg_match('~^/[0-9]{5,40}/(?:media|media_publish)$~D',$path)))throw new PublishingRemoteException('Unsupported Instagram endpoint.',422);
    $url='https://graph.instagram.com/'.publishing_meta_version().$path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):'');$raw='';$headers=publishing_bearer($token);$headers[]='Accept: application/json';$ch=curl_init($url);
    $options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$raw):int{if(strlen($raw)+strlen($part)>1048576)return 0;$raw.=$part;return strlen($part);}];
    if($method==='POST'){$headers[]='Content-Type: application/x-www-form-urlencoded';$options[CURLOPT_POSTFIELDS]=http_build_query($body??[],'','&',PHP_QUERY_RFC3986);}$options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$options);$ok=curl_exec($ch)!==false;$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return publishing_instagram_native_response($status,$raw,$ok,$method==='POST');
}
function publishing_instagram_native_profile(array $c,string $token,callable $http): void {
    $who=$http('GET','/me',['fields'=>'id,user_id,account_type'],$token);$id=(string)($who['user_id']??$who['id']??'');
    if($id!==$c['selected_resource']||!in_array(strtoupper((string)($who['account_type']??'')),['BUSINESS','CREATOR','MEDIA_CREATOR'],true))throw new PublishingRemoteException('Instagram did not confirm the approved professional account. Keep the job for review.',422);
}
function publishing_instagram_native_expected(array $job,array $c): array {
    $json=$job['snapshot_json']??'';if(!is_string($json)||$json===''||!hash_equals((string)($job['content_hash']??''),hash('sha256',$json)))throw new PublishingRemoteException('The approved Instagram revision could not be verified.',422);
    $snapshot=publishing_decode($json);$d=publishing_decode($job['destination_json']??'');$expected=$snapshot['destinations']['instagram']??[];
    if(!publishing_instagram_native_ready($c)||($d['slug']??'')!=='instagram'||($d['method']??'')!=='direct'||$d!=$expected||(int)($d['connection_id']??0)!==(int)$c['id']||($d['connection_external_id']??'')!==$c['external_id']||($d['resource']??'')!==$c['selected_resource'])throw new PublishingRemoteException('The approved Instagram account or publishing grant changed.',422);
    $v=$snapshot['variants']['instagram']??[];try{publishing_instagram_native_validate_variant($v);}catch(RuntimeException $e){throw new PublishingRemoteException($e->getMessage(),422);}return [$d,$v];
}
function publishing_instagram_native_state(array $job): array {$s=publishing_decode($job['provider_state_json']??'');return ($s['adapter']??'')==='instagram_native_v2'?$s:[];}
function publishing_instagram_can_resume(array $job): bool {
    $s=publishing_instagram_native_state($job);if(($job['method']??'')!=='direct'||(publishing_decode($job['destination_json']??'')['slug']??'')!=='instagram'||!$s)return false;
    return (($s['phase']??'')==='container_ready'&&preg_match('/^[0-9]{5,40}$/D',(string)($s['container_id']??'')))||(($s['phase']??'')==='verification_pending'&&preg_match('/^[0-9]{5,40}$/D',(string)($s['remote_id']??'')));
}
function publishing_instagram_native_checkpoint(array &$job,array $s,array $fields=[]): void {$fields=['provider_state_json'=>publishing_json($s)]+$fields;publishing_job_update((int)$job['id'],$fields);$job=array_replace($job,$fields);}
function publishing_instagram_native_pending(array $s,string $message='Instagram is processing the saved container.'): array {return ['status'=>'delivered','provider_state_json'=>publishing_json($s),'last_error'=>$message,'next_check_at'=>gmdate('Y-m-d H:i:s',time()+60)];}
function publishing_instagram_native_deliver(array $job,array $snapshot,array $c,?callable $http=null,?callable $tokens=null): array {
    [$d,$v]=publishing_instagram_native_expected($job,$c);if(!empty($job['provider_state_json'])||!empty($job['remote_id']))throw new PublishingRemoteException('This Instagram job already has processing evidence. No replacement container will be created.',0,true);
    if($v['format']==='reel'){$current=media_library_approved_asset((int)$v['media_asset_id']);if($current!==$v['media_asset'])throw new PublishingRemoteException('The approved Reel asset changed. Approve a new revision.',422);}
    $http??='publishing_instagram_native_http';$token=$tokens?$tokens($c):publishing_access_token($c);publishing_instagram_native_profile($c,$token,$http);
    $s=['adapter'=>'instagram_native_v2','phase'=>'initializing','approval_hash'=>$job['content_hash'],'grant'=>publishing_instagram_native_fingerprint($c),'target'=>$d['resource'],'format'=>$v['format'],'started_at'=>time(),'checks'=>0,'verification_checks'=>0];
    publishing_instagram_native_checkpoint($job,$s);
    $body=$v['format']==='reel'?['media_type'=>'REELS','video_url'=>$v['media_url'],'caption'=>$v['text'],'share_to_feed'=>$v['instagram_share_to_feed']?'true':'false']:social_instagram_parameters($v);
    $r=$http('POST','/'.$d['resource'].'/media',[],$token,$body);$s['container_id']=publishing_instagram_native_id($r['id']??null);$s['phase']='container_ready';publishing_instagram_native_checkpoint($job,$s,publishing_instagram_native_pending($s));
    return publishing_instagram_native_check($job,$c,$http,static fn(array $unused):string=>$token);
}
function publishing_instagram_native_owned_media(string $id,string $target,string $token,callable $http): void {
    $cursor='';$seen=[];$ids=[];
    for($page=0;$page<4;$page++){$q=['fields'=>'id','limit'=>25];if($cursor!=='')$q['after']=$cursor;$r=$http('GET','/'.$target.'/media',$q,$token);
        if(!is_array($r['data']??null)||!array_is_list($r['data'])||count($r['data'])>25)throw new PublishingRemoteException('Instagram returned an invalid owned-media inventory.',422);
        foreach($r['data']as$item){$returned=publishing_instagram_native_id($item['id']??null);if(isset($ids[$returned]))throw new PublishingRemoteException('Instagram returned duplicate owned-media rows.',422);$ids[$returned]=true;if($returned===$id)return;}
        if(empty($r['paging']['next']))break;$cursor=$r['paging']['cursors']['after']??null;if(!is_string($cursor)||strlen($cursor)>2048||$cursor===''||preg_match('/[\x00-\x20\x7f]/',$cursor)||isset($seen[$cursor]))throw new PublishingRemoteException('Instagram returned invalid media pagination.',422);$seen[$cursor]=true;
    }throw new PublishingRemoteException('The known publication was not found in the bounded approved-account inventory. Review it in Instagram; do not publish again.',422);
}
function publishing_instagram_native_verify(array &$job,array $s,array $c,array $v,string $token,callable $http): array {
    $id=publishing_instagram_native_id($s['remote_id']??null);$s['verification_checks']=(int)($s['verification_checks']??0)+1;publishing_instagram_native_checkpoint($job,$s,['status'=>'delivered','remote_id'=>$id]);
    try{
        $r=$http('GET','/'.$id,['fields'=>'id,caption,media_type,media_product_type,permalink'],$token);
        if(($r['id']??'')!==$id||($r['caption']??'')!==$v['text']||($r['media_type']??'')!==($v['format']==='reel'?'VIDEO':'IMAGE')||($v['format']==='reel'&&($r['media_product_type']??'')!=='REELS')||!publishing_job_url_valid((string)($r['permalink']??''),'instagram'))throw new PublishingRemoteException('The existing Instagram result does not yet match the approved format, caption and permalink.',422);
        publishing_instagram_native_owned_media($id,$c['selected_resource'],$token,$http);$s['phase']='published';return ['status'=>'published','remote_id'=>$id,'external_url'=>$r['permalink'],'provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>null];
    }catch(Throwable $e){
        if($e instanceof PublishingRemoteException&&$e->httpStatus===401)throw $e;
        if($s['verification_checks']>=5)return ['status'=>'failed','remote_id'=>$id,'provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'Instagram accepted this existing publication, but read-back is not verified. Review its saved ID or resume reads; no publication is resent.'];
        return publishing_instagram_native_pending($s,'Instagram returned a publication ID. Independent format, caption, account and permalink verification is still pending.');
    }
}
function publishing_instagram_native_check(array $job,array $c,?callable $http=null,?callable $tokens=null): array {
    [$d,$v]=publishing_instagram_native_expected($job,$c);$s=publishing_instagram_native_state($job);
    if(!$s||($s['approval_hash']??'')!==$job['content_hash']||($s['grant']??'')!==publishing_instagram_native_fingerprint($c)||($s['target']??'')!==$d['resource']||($s['format']??'')!==$v['format'])throw new PublishingRemoteException('The saved Instagram task does not match the approved content or connection.',422);
    if(!in_array($s['phase']??'',['container_ready','verification_pending'],true))throw new PublishingRemoteException('The Instagram publish boundary was already entered. Review the existing post; no automatic repeat is permitted.',0,true);
    $http??='publishing_instagram_native_http';$token=$tokens?$tokens($c):publishing_access_token($c);publishing_instagram_native_profile($c,$token,$http);
    if($s['phase']==='verification_pending')return publishing_instagram_native_verify($job,$s,$c,$v,$token,$http);
    $container=publishing_instagram_native_id($s['container_id']??null);
    if(time()-(int)$s['started_at']>=86400||(int)$s['checks']>=5)return ['status'=>'failed','provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'The saved Instagram container needs review after five processing checks or its 24-hour expiry. No new container or post was created.'];
    $s['checks']++;publishing_instagram_native_checkpoint($job,$s,publishing_instagram_native_pending($s));$r=$http('GET','/'.$container,['fields'=>'id,status_code'],$token);
    if(($r['id']??'')!==$container)throw new PublishingRemoteException('Instagram returned an unexpected container. No publication was attempted.',422);$status=$r['status_code']??'';
    if(in_array($status,['ERROR','EXPIRED'],true))return ['status'=>'failed','provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'Instagram rejected or expired the saved container. Check the media in Instagram before approving a replacement.'];
    if($status==='PUBLISHED'){$s['phase']='publish_attempted';return ['status'=>'ambiguous','provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'Instagram reports this container already published without a saved publication ID. Review the profile; no second publish request will be sent.'];}
    if($status==='IN_PROGRESS')return publishing_instagram_native_pending($s);
    if($status!=='FINISHED')throw new PublishingRemoteException('Instagram returned an unknown processing state. No publication was attempted.',422);
    // A subsequent timeout or crash leaves this non-replayable marker, even before receipt persistence.
    $s['phase']='publish_attempted';publishing_instagram_native_checkpoint($job,$s,['status'=>'processing','locked_at'=>gmdate('Y-m-d H:i:s')]);
    $published=$http('POST','/'.$d['resource'].'/media_publish',[],$token,['creation_id'=>$container]);$s['remote_id']=publishing_instagram_native_id($published['id']??null);$s['phase']='verification_pending';
    publishing_instagram_native_checkpoint($job,$s,['status'=>'delivered','remote_id'=>$s['remote_id'],'next_check_at'=>gmdate('Y-m-d H:i:s',time()+60)]);
    return publishing_instagram_native_verify($job,$s,$c,$v,$token,$http);
}
