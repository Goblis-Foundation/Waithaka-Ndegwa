<?php
declare(strict_types=1);

/** Owned Facebook Page videos/Reels and X MP4s. Persist mutation intent before each remote boundary. */
function publishing_social_video_validate(string $slug,array $v,array $d): void {
    $a=$v['media_asset']??[];
    if(!in_array($slug,['facebook','x'],true)||($v['media_type']??'')!=='video'||!is_array($a)||(int)($a['id']??0)<1||(int)($v['media_asset_id']??0)!==(int)$a['id']||($a['mime']??'')!=='video/mp4'||!is_int($a['bytes']??null)||$a['bytes']<1||$a['bytes']>536870912||!preg_match('/^[a-f0-9]{64}$/D',(string)($a['sha256']??'')))throw new RuntimeException('Direct Facebook/X media needs an owned MP4 from the media library, at most 512 MiB. Images and other files remain assisted or use a verified Buffer route.');
    if(!empty($v['ai_generated']))throw new RuntimeException('Finish this video in the platform to apply its altered-content disclosure.');
    if(($v['video_settings_confirmed']??false)!==true)throw new RuntimeException('Confirm the video rights, public audience and platform eligibility before approval.');
    if($slug==='facebook'){
        if(($d['account_type']??'')!=='page'||!in_array($v['format']??'',['video','reel'],true))throw new RuntimeException('Direct Facebook video delivery requires a confirmed Page and Video or Reel format. Personal-profile and Connect subscriber content is completed in Facebook.');
        if(($a['visibility']??'')!=='public'||($a['public_url']??'')!==($v['media_url']??'')||!publishing_public_url((string)($v['media_url']??'')))throw new RuntimeException('Facebook Page delivery needs an owned public MP4.');
        if(!empty($v['platform_options']))throw new RuntimeException('Finish native Facebook location, tags or audience options in Facebook. No selected option will be dropped.');
    }else{
        if(($d['service']??'')!=='x_media'||($v['format']??'')!=='post')throw new RuntimeException('Connect separate X media publishing and use Post format.');
        social_options_validate('x',$v,['method'=>'direct']);
    }
}
function publishing_social_video_id(mixed $v): string {
    if(!is_string($v)||!preg_match('/^[0-9]{1,40}$/D',$v))throw new PublishingRemoteException('The provider did not confirm a valid identifier. Review the saved job; no request will be repeated.',0,true);return $v;
}
function publishing_social_video_state(array $job): array {$s=publishing_decode($job['provider_state_json']??'');return ($s['adapter']??'')==='social_video_v1'?$s:[];}
function publishing_social_video_can_resume(array $job): bool {
    $s=publishing_social_video_state($job);return ($job['method']??'')==='direct'&&in_array($s['phase']??'',['processing','verification'],true)&&!empty($s['video_id']);
}
function publishing_social_video_checkpoint(array &$job,array $s,array $extra=[]): void {
    $fields=['provider_state_json'=>publishing_json($s)]+$extra;publishing_job_update((int)$job['id'],$fields);$job=array_replace($job,$fields);
}
function publishing_social_video_pending(array $s): array {return ['status'=>'delivered','provider_state_json'=>publishing_json($s),'next_check_at'=>gmdate('Y-m-d H:i:s',time()+60),'last_error'=>'Video accepted; provider processing and independent publication verification are pending.'];}
function publishing_social_video_expected(array $job,array $c): array {
    $json=$job['snapshot_json']??'';if(!is_string($json)||!hash_equals((string)($job['content_hash']??''),hash('sha256',$json)))throw new PublishingRemoteException('The approved video revision could not be verified.',422);
    $snapshot=publishing_decode($json);$d=publishing_decode($job['destination_json']??'');$slug=$d['slug']??'';
    if(!in_array($slug,['facebook','x'],true)||($snapshot['destinations'][$slug]??null)!=$d||($d['method']??'')!=='direct'||(int)($d['connection_id']??0)!==(int)($c['id']??0)||($d['connection_external_id']??'')!==($c['external_id']??null)||($d['resource']??'')!==($c['selected_resource']??null)||($c['status']??'')!=='connected'||($d['service']??'')!==($c['service']??null))throw new PublishingRemoteException('The approved video destination changed. Confirm its exact account before continuing.',422);
    $required=$slug==='x'?['tweet.read','tweet.write','users.read','media.write']:['pages_manage_posts','pages_read_engagement'];
    if(array_diff($required,publishing_decode($c['scopes_json']??'')))throw new PublishingRemoteException('Reconnect this video service and approve its required permissions.',401);
    $v=$snapshot['variants'][$slug]??[];publishing_social_video_validate($slug,$v,$d);
    return [$d,$v];
}
function publishing_social_video_fingerprint(array $c): string {return hash('sha256',publishing_json([$c['id'],$c['external_id'],$c['selected_resource'],$c['scopes_json'],(int)($c['connected_by']??0)]));}

/** Fixed hosts/paths only. X APPEND is multipart; Facebook hosted Reel uses file_url header. */
function publishing_social_video_http(string $method,string $path,string $token,?array $body=null): array {
    if(!in_array($method,['GET','POST'],true))throw new RuntimeException('Unsupported video method.');
    $headers=publishing_bearer($token);$multipart=false;
    if(str_starts_with($path,'x:')){
        $suffix=substr($path,2);
        if(!preg_match('~^/2/(?:users/me|tweets(?:/[0-9]{1,40})?|media/upload(?:/initialize|/[0-9]{1,40}/(?:append|finalize))?)(?:\?[^\x00-\x20#]*)?$~D',$suffix))throw new RuntimeException('Unsupported X video endpoint.');
        $url='https://api.x.com'.$suffix;$multipart=str_contains($suffix,'/append');
    }elseif(preg_match('~^fb:/([0-9]{1,40}|me)(?:/(video_reels|videos))?(?:\?[^\x00-\x20#]*)?$~D',$path)){
        $url='https://graph.facebook.com/'.publishing_meta_version().substr($path,3);
    }elseif(preg_match('~^upload:/[0-9]{1,40}$~D',$path)){
        $url='https://rupload.facebook.com/video-upload/'.publishing_meta_version().substr($path,7);
        if(!is_string($body['file_url']??null)||!publishing_public_url($body['file_url'])||preg_match('/[\r\n]/',$body['file_url']))throw new RuntimeException('Invalid approved Reel source.');
        $headers=['Authorization: OAuth '.$token,'file_url: '.$body['file_url']];$body=[];
    }else throw new RuntimeException('Unsupported video endpoint.');
    $raw='';$ch=curl_init($url);$o=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>90,CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>1048576)return 0;$raw.=$chunk;return strlen($chunk);}];
    if($method==='POST'){
        if($multipart){if(!is_string($body['chunk']??null)||strlen($body['chunk'])>4194304)throw new RuntimeException('Invalid upload segment.');$o[CURLOPT_POSTFIELDS]=['segment_index'=>(string)$body['segment_index'],'media'=>new CURLStringFile($body['chunk'],'segment.mp4','video/mp4')];}
        elseif(str_starts_with($path,'x:')){$headers[]='Content-Type: application/json';$o[CURLOPT_POSTFIELDS]=publishing_json($body??[]);}
        else{$headers[]='Content-Type: application/x-www-form-urlencoded';$o[CURLOPT_POSTFIELDS]=http_build_query($body??[],'','&',PHP_QUERY_RFC3986);}
    }
    $o[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$o);$ok=curl_exec($ch)!==false;$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if(!$ok||$status<200||$status>=300)throw new PublishingRemoteException('The video provider did not accept or confirm this request. Review its saved state and account permissions.',in_array($status,[401,403,429],true)?$status:0,$method==='POST');
    $r=trim($raw)===''?[]:json_decode($raw,true);if(!is_array($r)||!empty($r['error'])||!empty($r['errors']))throw new PublishingRemoteException('The video provider returned an incomplete result. Review the saved task.',0,$method==='POST');return $r;
}
function publishing_social_video_profile(array $d,string $token,callable $http): void {
    $r=$http('GET',$d['slug']==='x'?'x:/2/users/me':'fb:/me?fields=id',$token);$id=$d['slug']==='x'?($r['data']['id']??''):($r['id']??'');
    if($id!==$d['resource'])throw new PublishingRemoteException('The provider returned a different video publishing account.',422);
}
function publishing_social_video_deliver(array $job,array $snapshot,array $c,?callable $http=null,?callable $tokens=null,?callable $opener=null): array {
    [$d,$v]=publishing_social_video_expected($job,$c);
    if(!empty($job['provider_state_json'])||!empty($job['remote_id']))throw new PublishingRemoteException('An existing video attempt cannot be recreated.',0,true);
    if(media_library_approved_asset((int)$v['media_asset_id'])!==$v['media_asset'])throw new PublishingRemoteException('The approved video asset changed.',422);
    $http??='publishing_social_video_http';$token=$tokens?$tokens($c):publishing_access_token($c);publishing_social_video_profile($d,$token,$http);
    $s=['adapter'=>'social_video_v1','slug'=>$d['slug'],'phase'=>'initializing','approval_hash'=>$job['content_hash'],'grant'=>publishing_social_video_fingerprint($c),'target'=>$d['resource'],'started_at'=>time(),'checks'=>0];
    // Even an interrupted initialization cannot allocate a replacement upload automatically.
    publishing_social_video_checkpoint($job,$s);
    if($d['slug']==='x'){
        $r=$http('POST','x:/2/media/upload/initialize',$token,['media_type'=>'video/mp4','total_bytes'=>$v['media_asset']['bytes'],'media_category'=>'tweet_video']);
        $s['video_id']=publishing_social_video_id($r['data']['id']??null);$s['media_key']=(string)($r['data']['media_key']??'');if(!preg_match('/^[0-9]+_[0-9]{1,40}$/D',$s['media_key']))throw new PublishingRemoteException('X did not confirm a valid upload media key.',0,true);$s['phase']='uploading';publishing_social_video_checkpoint($job,$s);
        $opened=null;$handle=null;
        try{
            [$opened,$handle]=$opener?$opener($v['media_asset']):publishing_youtube_open_asset($v['media_asset']);
            for($offset=0,$segment=0;$offset<$v['media_asset']['bytes'];$segment++){
                $size=min(4194304,$v['media_asset']['bytes']-$offset);$chunk=fread($handle,$size);if($chunk===false||strlen($chunk)!==$size)throw new PublishingRemoteException('The approved video changed during reading.',422);
                $http('POST','x:/2/media/upload/'.$s['video_id'].'/append',$token,['segment_index'=>$segment,'chunk'=>$chunk]);$offset+=$size;
            }
            $s['phase']='finalizing';publishing_social_video_checkpoint($job,$s);$r=$http('POST','x:/2/media/upload/'.$s['video_id'].'/finalize',$token,[]);
            if(($r['data']['id']??'')!==$s['video_id'])throw new PublishingRemoteException('X did not confirm the saved upload.',0,true);
        }finally{if(is_resource($handle))fclose($handle);if(is_array($opened)&&!$opener)media_library_release($opened);}
        $s['phase']='processing';publishing_social_video_checkpoint($job,$s,publishing_social_video_pending($s));
    }elseif($v['format']==='reel'){
        $r=$http('POST','fb:/'.$d['resource'].'/video_reels',$token,['upload_phase'=>'start']);$s['video_id']=publishing_social_video_id($r['video_id']??null);$s['phase']='uploading';publishing_social_video_checkpoint($job,$s);
        // Construct the documented upload endpoint; never follow the returned upload_url.
        $r=$http('POST','upload:/'.$s['video_id'],$token,['file_url'=>$v['media_url']]);if(($r['success']??null)!==true)throw new PublishingRemoteException('Facebook did not acknowledge the existing Reel upload.',0,true);
        $s['phase']='publishing';publishing_social_video_checkpoint($job,$s);
        $r=$http('POST','fb:/'.$d['resource'].'/video_reels',$token,['upload_phase'=>'finish','video_id'=>$s['video_id'],'video_state'=>'PUBLISHED','description'=>$v['text'],'title'=>$v['title']]);
        if(($r['success']??null)!==true)throw new PublishingRemoteException('Facebook did not acknowledge the existing Reel publication.',0,true);
        $s['phase']='verification';publishing_social_video_checkpoint($job,$s,['status'=>'delivered','remote_id'=>$s['video_id']]);
    }else{
        $s['phase']='publishing';publishing_social_video_checkpoint($job,$s);
        $r=$http('POST','fb:/'.$d['resource'].'/videos',$token,['file_url'=>$v['media_url'],'description'=>$v['text'],'title'=>$v['title'],'published'=>'true']);
        $s['video_id']=publishing_social_video_id($r['id']??null);$s['phase']='verification';publishing_social_video_checkpoint($job,$s,['status'=>'delivered','remote_id'=>$s['video_id']]);
    }
    return publishing_social_video_check($job,$c,$http,static fn(array $unused):string=>$token);
}
function publishing_social_video_check(array $job,array $c,?callable $http=null,?callable $tokens=null): array {
    [$d,$v]=publishing_social_video_expected($job,$c);$s=publishing_social_video_state($job);
    if(!publishing_social_video_can_resume($job)||($s['approval_hash']??'')!==$job['content_hash']||($s['target']??'')!==$d['resource']||($s['grant']??'')!==publishing_social_video_fingerprint($c))throw new PublishingRemoteException('The video is outside a resumable processing/read-back phase. No publication will be repeated.',0,true);
    if((int)($s['checks']??0)>=10||time()-(int)($s['started_at']??0)>86400)return ['status'=>'failed','provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'Video processing/read-back needs review after ten checks or 24 hours. No replacement post was created.'];
    $http??='publishing_social_video_http';$token=$tokens?$tokens($c):publishing_access_token($c);publishing_social_video_profile($d,$token,$http);$s['checks']++;publishing_social_video_checkpoint($job,$s,publishing_social_video_pending($s));
    if($d['slug']==='x'&&$s['phase']==='processing'){
        $r=$http('GET','x:/2/media/upload?command=STATUS&media_id='.$s['video_id'],$token);$data=$r['data']??[];
        if(($data['id']??'')!==$s['video_id'])throw new PublishingRemoteException('X returned a different upload identifier.',422);
        $state=$data['processing_info']['state']??null;
        if(in_array($state,['pending','in_progress'],true))return publishing_social_video_pending($s);
        if($state==='failed')return ['status'=>'failed','provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'X rejected this saved video. Review duration, account eligibility and API limits.'];
        if($state!=='succeeded')throw new PublishingRemoteException('X did not confirm completed video processing.',422);
        // A crash/timeout after this marker may not replay create-post.
        $s['phase']='publishing';publishing_social_video_checkpoint($job,$s,['status'=>'processing']);
        $body=['text'=>$v['text'],'media'=>['media_ids'=>[$s['video_id']]]];if(!empty($v['platform_options']['location_id']))$body['geo']=['place_id'=>$v['platform_options']['location_id']];
        $r=$http('POST','x:/2/tweets',$token,$body);$s['post_id']=publishing_social_video_id($r['data']['id']??null);$s['phase']='verification';publishing_social_video_checkpoint($job,$s,['status'=>'delivered','remote_id'=>$s['post_id']]);
    }
    if($d['slug']==='x'){
        $r=$http('GET','x:/2/tweets/'.$s['post_id'].'?tweet.fields=author_id,attachments&expansions=attachments.media_keys&media.fields=type',$token);$p=$r['data']??[];
        // X transforms URLs in returned text; exact submitted text stays in the approved revision.
        if(($p['id']??'')!==$s['post_id']||($p['author_id']??'')!==$d['resource']||!in_array($s['media_key'],$p['attachments']['media_keys']??[],true)||$s['media_key']==='')return publishing_social_video_pending($s);
        $id=$s['post_id'];$link='https://x.com/i/web/status/'.$id;
    }else{
        $r=$http('GET','fb:/'.$s['video_id'].'?fields=id,from,description,permalink_url,status',$token);
        if(($r['id']??'')!==$s['video_id']||($r['from']['id']??'')!==$d['resource']||($r['description']??'')!==$v['text']||($r['status']['video_status']??'')!=='ready'||($r['status']['publishing_phase']['status']??'')!=='complete'||!publishing_job_url_valid((string)($r['permalink_url']??''),'facebook'))return publishing_social_video_pending($s);
        $id=$s['video_id'];$link=$r['permalink_url'];
    }
    $s['phase']='published';return ['status'=>'published','remote_id'=>$id,'external_url'=>$link,'provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>null];
}
