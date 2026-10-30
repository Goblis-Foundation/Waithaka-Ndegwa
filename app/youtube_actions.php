<?php
declare(strict_types=1);

/** Owner-reviewed channel maintenance. No worker, automatic replay or token-bearing media URL. */
final class YouTubeActionException extends RuntimeException {}
function yta_types(): array {return ['playlist'=>'Create a playlist','playlist_item'=>'Add an owned video to a playlist','caption'=>'Upload timed captions','thumbnail'=>'Set a video thumbnail','moderate'=>'Moderate one comment'];}
function yta_owner(int $uid): void {if($uid<1||$uid!==(int)(comm_config()['owner']??0))throw new YouTubeActionException('Owner access required.');}
function yta_text(mixed $v,int $max,bool $required=true): string {
    if(!is_string($v)||!mb_check_encoding($v,'UTF-8')||strlen($v)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v))throw new YouTubeActionException('Use valid text within the displayed limit.');
    $v=str_replace(["\r\n","\r"],"\n",$v);if($required&&trim($v)==='')throw new YouTubeActionException('Complete the required content.');return $v;
}
function yta_id(mixed $v,string $kind='resource'): string {
    $pattern=match($kind){'video'=>'/^[A-Za-z0-9_-]{11}$/D','channel'=>'/^UC[A-Za-z0-9_-]{22}$/D','action'=>'/^[a-f0-9]{32}$/D',default=>'/^[A-Za-z0-9][A-Za-z0-9_=.-]{0,511}$/D'};
    if(!is_string($v)||!preg_match($pattern,$v))throw new YouTubeActionException('Use a valid '. $kind.' identifier.');return $v;
}
function yta_ready(int $uid): array {
    yta_owner($uid);$c=publishing_connection('google_youtube_manage');
    if(!$c||($c['status']??'')!=='connected'||(int)($c['connected_by']??0)!==$uid||empty($c['external_id'])||empty($c['verified_at'])||!in_array('https://www.googleapis.com/auth/youtube.force-ssl',publishing_decode($c['scopes_json']??''),true))throw new YouTubeActionException('Connect YouTube channel management and confirm your returned channel first.');
    yta_id($c['selected_resource']??null,'channel');return $c;
}
function yta_fingerprint(array $c): string {return hash('sha256',publishing_json([$c['id'],$c['external_id'],$c['selected_resource'],$c['verified_at'],$c['scopes_json'],(int)$c['connected_by']]));}
function yta_same_grant(array $r,int $uid): array {$c=yta_ready($uid);if(!hash_equals($r['grant'],yta_fingerprint($c)))throw new YouTubeActionException('The account, selected channel or grant changed. Prepare a new action.');return $c;}
function yta_items(array $d): array {if(!is_array($d['items']??null)||!array_is_list($d['items'])||(array_key_exists('nextPageToken',$d)&&(!is_string($d['nextPageToken'])||$d['nextPageToken']!=='')))throw new YouTubeActionException('YouTube returned an incomplete result. No change was made.');return $d['items'];}
function yta_one(array $d,string $id): array {$items=yta_items($d);if(count($items)!==1||!is_array($items[0])||($items[0]['id']??'')!==$id)throw new YouTubeActionException('The selected YouTube record is unavailable or changed.');return $items[0];}
function yta_channel(array $c,string $token,callable $http): void {
    $d=$http('GET','channels',['part'=>'id','mine'=>'true','maxResults'=>50],$token);
    $items=yta_items($d);$matches=array_filter($items,static fn($x)=>is_array($x)&&($x['id']??'')===$c['selected_resource']);
    if(count($matches)!==1)throw new YouTubeActionException('YouTube did not confirm the selected channel for this account.');
}
function yta_resource(string $kind,string $id,string $channel,string $token,callable $http): array {
    $parts=match($kind){'videos','playlists'=>'snippet,status',default=>'snippet'};
    $x=yta_one($http('GET',$kind,['part'=>$parts,'id'=>$id,'maxResults'=>1],$token),$id);
    if(($x['snippet']['channelId']??'')!==$channel)throw new YouTubeActionException('The selected record does not belong to the confirmed channel.');
    if(!is_string($x['etag']??null)||$x['etag']==='')throw new YouTubeActionException('YouTube did not return a source revision.');
    $snippet=$x['snippet'];$out=['id'=>$id,'etag'=>$x['etag'],'channel'=>$channel];
    if($kind==='comments'){
        $out+=['text'=>yta_text($snippet['textOriginal']??'',10000),'moderation'=>$snippet['moderationStatus']??''];
        if(!in_array($out['moderation'],['heldForReview','likelySpam','published'],true))throw new YouTubeActionException('This comment has no reviewable moderation state.');
    }else{
        $out+=['title'=>yta_text($snippet['title']??'',1000),'privacy'=>$x['status']['privacyStatus']??''];
        if(!in_array($out['privacy'],['private','unlisted','public'],true))throw new YouTubeActionException('The source visibility could not be confirmed.');
    }
    return $out;
}
function yta_srt(string $text): array {
    $text=yta_text($text,100000);$text=preg_replace('/^\xEF\xBB\xBF/','',$text);$blocks=preg_split('/\n[ \t]*\n/',trim($text));
    if(!$blocks||count($blocks)>1000)throw new YouTubeActionException('Use at most 1,000 timed SRT cues.');$cues=[];$previous=-1;
    foreach($blocks as$block){$lines=explode("\n",$block);if(count($lines)<3||!ctype_digit(trim(array_shift($lines))))throw new YouTubeActionException('Use numbered SRT cues with timing and text.');$timing=array_shift($lines);
        if(!preg_match('/^(\d{2,3}):([0-5]\d):([0-5]\d),(\d{3}) --> (\d{2,3}):([0-5]\d):([0-5]\d),(\d{3})$/D',trim($timing),$m))throw new YouTubeActionException('Use SRT timing HH:MM:SS,mmm --> HH:MM:SS,mmm.');
        $start=((int)$m[1]*3600+(int)$m[2]*60+(int)$m[3])*1000+(int)$m[4];$end=((int)$m[5]*3600+(int)$m[6]*60+(int)$m[7])*1000+(int)$m[8];$body=implode("\n",$lines);
        if($start<$previous||$end<=$start||trim($body)==='')throw new YouTubeActionException('Caption cues need ordered start times, a later end and nonempty text.');
        $cues[]=[$start,$end,$body];$previous=$start;
    }return $cues;
}
function yta_thumbnail(array $asset): array {
    if(!is_int($asset['bytes']??null)||$asset['bytes']<1||$asset['bytes']>2097152||!in_array($asset['mime']??'', ['image/jpeg','image/png'],true)||!preg_match('/^[a-f0-9]{64}$/D',$asset['sha256']??''))throw new YouTubeActionException('Choose a ready JPEG or PNG from the media library, at most 2 MiB.');return $asset;
}
function yta_thumbnail_bytes(array $asset): string {
    yta_thumbnail($asset);$current=media_library_approved_asset((int)$asset['id']);if($current!==$asset)throw new YouTubeActionException('The selected thumbnail changed. Prepare again.');
    $local=media_library_materialize($asset,2097152);
    try{$bytes=file_get_contents($local['path']);if(!is_string($bytes)||strlen($bytes)!==$asset['bytes']||!hash_equals($asset['sha256'],hash('sha256',$bytes)))throw new YouTubeActionException('The approved thumbnail bytes changed.');$image=@getimagesizefromstring($bytes);
        if(!$image||($image['mime']??'')!==$asset['mime']||$image[0]<1||$image[1]<1||$image[0]>4096||$image[1]>4096)throw new YouTubeActionException('Use a valid image no larger than 4096 pixels in either direction.');return $bytes;
    }finally{media_library_release($local);}
}
/** Fixed Google endpoints only, no redirects, bounded response, no provider error bodies. */
function yta_http(string $method,string $resource,array $query,string $token,?array $body=null,?array $media=null): array {
    $read=in_array($resource,['channels','videos','playlists','playlistItems','captions','comments'],true)||(str_starts_with($resource,'captions/')&&rawurlencode(yta_id(rawurldecode(substr($resource,9))))===substr($resource,9));
    $write=in_array($resource,['playlists','playlistItems','captions','thumbnails/set','comments/setModerationStatus'],true);
    if(($method==='GET'&&!$read)||($method==='POST'&&!$write)||!in_array($method,['GET','POST'],true))throw new YouTubeActionException('Unsupported YouTube operation.');
    $upload=$media!==null;if($upload&&($method!=='POST'||!in_array($resource,['captions','thumbnails/set'],true)))throw new YouTubeActionException('Unsupported media operation.');
    $url='https://www.googleapis.com/'.($upload?'upload/':'').'youtube/v3/'.$resource.'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    $headers=publishing_bearer($token);$headers[]='Accept: application/json';$raw='';$ch=curl_init($url);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$raw):int{if(strlen($raw)+strlen($part)>2097152)return 0;$raw.=$part;return strlen($part);}];
    if($upload){
        if($resource==='captions'){$boundary='wn_'.bin2hex(random_bytes(24));$headers[]='Content-Type: multipart/related; boundary='.$boundary;$options[CURLOPT_POSTFIELDS]='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($body,JSON_THROW_ON_ERROR)."\r\n--".$boundary."\r\nContent-Type: application/x-subrip\r\n\r\n".$media['bytes']."\r\n--".$boundary."--\r\n";}
        else{$headers[]='Content-Type: '.$media['mime'];$options[CURLOPT_POSTFIELDS]=$media['bytes'];}
    }elseif($body!==null){$headers[]='Content-Type: application/json; charset=UTF-8';$options[CURLOPT_POSTFIELDS]=json_encode($body,JSON_THROW_ON_ERROR);}
    elseif($method==='POST')$options[CURLOPT_POSTFIELDS]='';
    $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$options);$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($ok===false||$status<200||$status>=300)throw new PublishingRemoteException('YouTube request did not complete. Review this saved action before preparing a replacement.',$status,$method==='POST'&&($ok===false||$status>=500||$status===408));
    if($status===204)return ['acknowledged'=>true];
    if($method==='GET'&&str_starts_with($resource,'captions/'))return ['text'=>yta_text($raw,100000)];
    $data=json_decode($raw,true);if(!is_array($data))throw new PublishingRemoteException('YouTube returned an unreadable result.',$status,$method==='POST');return $data;
}
function yta_owned(string $id,int $uid): array {yta_owner($uid);yta_id($id,'action');$r=comm_get('youtube.action.'.$id);if(!$r||(int)$r['owner']!==$uid)throw new YouTubeActionException('This action was not found.');return $r;}
function yta_records(int $uid): array {yta_owner($uid);return array_values(comm_get('youtube.action_index.'.$uid));}
function yta_save(array $r,string $state): array {
    $r['state']=$state;$r['version']++;$r['updated_at']=time();$r['audit'][]=['state'=>$state,'at'=>time()];comm_put('youtube.action.'.$r['id'],$r);
    comm_lock('yta-index-'.$r['owner'],static function()use($r){$index=comm_get('youtube.action_index.'.$r['owner']);$index[$r['id']]=array_intersect_key($r,array_flip(['id','owner','kind','title','state','created_at','updated_at']));comm_put('youtube.action_index.'.$r['owner'],$index);});return $r;
}
function yta_digest(array $r): string {return hash('sha256',publishing_json([$r['id'],$r['owner'],$r['kind'],$r['title'],$r['payload'],$r['source'],$r['grant'],$r['channel']]));}
function yta_version(array $r,int $v): void {if($r['version']!==$v)throw new YouTubeActionException('This action changed. Reload and review it.');}
function yta_source(array $r,string $token,callable $http): array {
    $p=$r['payload'];$channel=$r['channel'];$out=[];
    if($r['kind']!=='playlist'&&$r['kind']!=='moderate')$out['video']=yta_resource('videos',$p['video'],$channel,$token,$http);
    if($r['kind']==='playlist_item'){
        $out['playlist']=yta_resource('playlists',$p['playlist'],$channel,$token,$http);
        $items=yta_items($http('GET','playlistItems',['part'=>'id','playlistId'=>$p['playlist'],'videoId'=>$p['video'],'maxResults'=>50],$token));
        if($items)throw new YouTubeActionException('This video is already in that playlist. No duplicate will be added.');
    }
    if($r['kind']==='caption'){
        $items=yta_items($http('GET','captions',['part'=>'snippet','videoId'=>$p['video']],$token));$tracks=[];
        foreach($items as$item){if(!is_array($item)||($item['snippet']['videoId']??'')!==$p['video'])throw new YouTubeActionException('Unexpected caption inventory.');$tracks[]=['id'=>yta_id($item['id']??null),'etag'=>yta_text($item['etag']??'',512)];if(($item['snippet']['language']??'')===$p['language']&&($item['snippet']['name']??'')===$p['name'])throw new YouTubeActionException('A caption track with this language and name already exists. Review it in Studio.');}
        usort($tracks,static fn($a,$b)=>strcmp($a['id'],$b['id']));$out['tracks']=$tracks;
    }
    if($r['kind']==='moderate')$out['comment']=yta_resource('comments',$p['comment'],$channel,$token,$http);
    return $out;
}
function yta_prepare(array $input,int $uid,?callable $http=null,?callable $tokens=null): string {
    $c=yta_ready($uid);$kind=yta_text($input['kind']??'',20);if(!isset(yta_types()[$kind]))throw new YouTubeActionException('Choose an available action.');$p=[];$title=yta_types()[$kind];
    if($kind==='playlist'){$p=['title'=>trim(yta_text($input['title']??'',150)),'description'=>yta_text($input['description']??'',5000,false),'privacy'=>$input['privacy']??''];if(str_contains($p['title'],"\n")||!in_array($p['privacy'],['private','unlisted','public'],true))throw new YouTubeActionException('Choose a single-line title and exact playlist visibility.');$title=$p['title'];}
    if(!in_array($kind,['playlist','moderate'],true))$p['video']=yta_id($input['video']??null,'video');
    if($kind==='playlist_item')$p['playlist']=yta_id($input['playlist']??null);
    if($kind==='caption'){$p+=['language'=>yta_text($input['language']??'',35),'name'=>trim(yta_text($input['name']??'',100)),'text'=>yta_text($input['text']??'',100000),'draft'=>($input['draft']??null)==='1'];if(!preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D',$p['language'])||!in_array($input['draft']??null,['0','1'],true))throw new YouTubeActionException('Choose a caption language and explicit draft/public setting.');yta_srt($p['text']);}
    if($kind==='thumbnail'){$asset=filter_var($input['asset']??null,FILTER_VALIDATE_INT);if(!$asset||$asset<1)throw new YouTubeActionException('Choose a media-library image.');$p['asset']=yta_thumbnail(media_library_approved_asset($asset));}
    if($kind==='moderate'){$p=['comment'=>yta_id($input['comment']??null),'moderation'=>$input['moderation']??''];if(!in_array($p['moderation'],['published','heldForReview','rejected'],true))throw new YouTubeActionException('Choose an exact moderation outcome.');}
    $http??='yta_http';$token=$tokens?$tokens($c):publishing_access_token($c);yta_channel($c,$token,$http);
    $r=['id'=>bin2hex(random_bytes(16)),'owner'=>$uid,'kind'=>$kind,'title'=>$title,'payload'=>$p,'source'=>[],'channel'=>$c['selected_resource'],'account'=>$c['display_name'],'grant'=>yta_fingerprint($c),'version'=>0,'created_at'=>time(),'audit'=>[]];
    $r['source']=yta_source($r,$token,$http);yta_same_grant($r,$uid);
    if($kind==='moderate'&&$r['source']['comment']['moderation']===$p['moderation'])throw new YouTubeActionException('The comment already has this moderation state.');
    comm_lock('yta-capacity-'.$uid,static function()use($uid,$r){if(count(yta_records($uid))>=200)throw new YouTubeActionException('The retained action limit is 200. Keep attempted actions as evidence.');yta_save($r,'draft');});return $r['id'];
}
function yta_review(string $id,int $uid,int $version): void {
    comm_lock('yta-'.$id,static function()use($id,$uid,$version){$r=yta_owned($id,$uid);yta_version($r,$version);if(!in_array($r['state'],['draft','reviewed'],true))throw new YouTubeActionException('Only an unused action can be reviewed.');yta_same_grant($r,$uid);$r['approval']=yta_digest($r);$r['reviewed_at']=time();yta_save($r,'reviewed');});
}
function yta_verify(array $r,string $token,callable $http): array {
    $p=$r['payload'];$id=yta_id($r['remote_id']??null);$result=['provider_checked_at'=>time()];
    if($r['kind']==='playlist'){$x=yta_resource('playlists',$id,$r['channel'],$token,$http);$d=yta_one($http('GET','playlists',['part'=>'snippet,status','id'=>$id],$token),$id);if($x['title']!==$p['title']||$x['privacy']!==$p['privacy']||($d['snippet']['description']??'')!==$p['description'])throw new YouTubeActionException('The created playlist content or visibility could not be verified.');return $result+['url'=>'https://www.youtube.com/playlist?list='.$id,'verification'=>'Playlist channel, title, description and visibility read back.'];}
    if($r['kind']==='playlist_item'){$x=yta_one($http('GET','playlistItems',['part'=>'snippet','id'=>$id],$token),$id);$s=$x['snippet']??[];if(($s['channelId']??'')!==$r['channel']||($s['playlistId']??'')!==$p['playlist']||($s['resourceId']['kind']??'')!=='youtube#video'||($s['resourceId']['videoId']??'')!==$p['video'])throw new YouTubeActionException('The playlist entry could not be independently verified.');return $result+['url'=>'https://www.youtube.com/playlist?list='.$p['playlist'],'verification'=>'Exact playlist entry and video read back.'];}
    if($r['kind']==='caption'){$x=yta_one($http('GET','captions',['part'=>'snippet','id'=>$id,'videoId'=>$p['video']],$token),$id);$s=$x['snippet']??[];if(($s['videoId']??'')!==$p['video']||($s['language']??'')!==$p['language']||($s['name']??'')!==$p['name']||($s['isDraft']??null)!==$p['draft']||($s['status']??'')!=='serving')throw new YouTubeActionException('Caption processing, identity or visibility is not yet verified. Recheck this track; do not upload another.');$text=$http('GET','captions/'.rawurlencode($id),['tfmt'=>'srt'],$token)['text']??'';if(yta_srt($text)!==yta_srt($p['text']))throw new YouTubeActionException('Caption cue content/timing differs from the reviewed text.');return $result+['url'=>'https://studio.youtube.com/video/'.$p['video'].'/translations','verification'=>'Caption language, draft state, processing and timed text read back.'];}
    if($r['kind']==='thumbnail'){
        yta_resource('videos',$p['video'],$r['channel'],$token,$http);$x=yta_one($http('GET','videos',['part'=>'snippet','id'=>$p['video']],$token),$p['video']);$actual=$x['snippet']['thumbnails']??[];$expected=$r['thumbnail_result']??[];
        if(!$expected)throw new YouTubeActionException('No known thumbnail response is available. Review Studio.');foreach($expected as$key=>$thumbnail){if(!is_array($thumbnail)||!is_string($thumbnail['url']??null)||($actual[$key]['url']??'')!==$thumbnail['url'])throw new YouTubeActionException('Thumbnail metadata has not converged. Recheck without uploading again.');}
        return $result+['url'=>'https://studio.youtube.com/video/'.$p['video'].'/edit','verification'=>'YouTube acknowledged the approved image and its thumbnail URLs were read back. YouTube transforms images; visual pixel identity is not certified.'];
    }
    $x=yta_resource('comments',$p['comment'],$r['channel'],$token,$http);if($x['moderation']!==$p['moderation']||$x['text']!==$r['source']['comment']['text'])throw new YouTubeActionException('The comment outcome could not be independently verified.');return $result+['url'=>'https://studio.youtube.com/','verification'=>'Exact comment text, channel and moderation state read back.'];
}
function yta_execute(string $id,int $uid,int $version,?callable $http=null,?callable $tokens=null,?callable $images=null): array {
    return comm_lock('yta-'.$id,static function()use($id,$uid,$version,$http,$tokens,$images){$r=yta_owned($id,$uid);yta_version($r,$version);
        if($r['state']!=='reviewed'||time()-($r['reviewed_at']??0)>1800||!hash_equals($r['approval']??'',yta_digest($r)))throw new YouTubeActionException('Review the exact action again before changing YouTube.');
        $target=$r['payload']['video']??$r['payload']['comment']??$r['id'];if($r['kind']==='playlist_item')$target=$r['payload']['playlist'].'|'.$target;
        return comm_lock('yta-target-'.hash('sha256',$r['channel'].'|'.$r['kind'].'|'.$target),static function()use($r,$uid,$http,$tokens,$images){
            $c=yta_same_grant($r,$uid);$http??='yta_http';$token=$tokens?$tokens($c):publishing_access_token($c);yta_channel($c,$token,$http);$source=yta_source($r,$token,$http);if($source!==$r['source'])throw new YouTubeActionException('The YouTube source changed after preparation. Prepare and review a new action.');
            $p=$r['payload'];$media=null;if($r['kind']==='thumbnail'){$bytes=$images?$images($p['asset']):yta_thumbnail_bytes($p['asset']);if(!is_string($bytes)||strlen($bytes)!==$p['asset']['bytes']||!hash_equals($p['asset']['sha256'],hash('sha256',$bytes)))throw new YouTubeActionException('The approved thumbnail changed.');$media=['bytes'=>$bytes,'mime'=>$p['asset']['mime']];}
            yta_same_grant($r,$uid);$r=yta_save($r,'executing');$returned=false;
            try{
                if($r['kind']==='playlist')$d=$http('POST','playlists',['part'=>'snippet,status'],$token,['snippet'=>['title'=>$p['title'],'description'=>$p['description']],'status'=>['privacyStatus'=>$p['privacy']]]);
                elseif($r['kind']==='playlist_item')$d=$http('POST','playlistItems',['part'=>'snippet'],$token,['snippet'=>['playlistId'=>$p['playlist'],'resourceId'=>['kind'=>'youtube#video','videoId'=>$p['video']]]]);
                elseif($r['kind']==='caption')$d=$http('POST','captions',['part'=>'snippet','uploadType'=>'multipart'],$token,['snippet'=>['videoId'=>$p['video'],'language'=>$p['language'],'name'=>$p['name'],'isDraft'=>$p['draft']]],['bytes'=>$p['text'],'mime'=>'application/x-subrip']);
                elseif($r['kind']==='thumbnail'){$d=$http('POST','thumbnails/set',['videoId'=>$p['video'],'uploadType'=>'media'],$token,null,$media);$r['thumbnail_result']=$d['items'][0]??[];$d['id']=$p['video'];}
                else{$d=$http('POST','comments/setModerationStatus',(['id'=>$p['comment'],'moderationStatus'=>$p['moderation']]+($p['moderation']==='rejected'?['banAuthor'=>'false']:[])),$token);if(empty($d['acknowledged']))throw new YouTubeActionException('YouTube did not acknowledge moderation.');$d['id']=$p['comment'];}
                $returned=true;$r['remote_id']=yta_id($d['id']??null);$r=yta_save($r,'verification_pending');
                if($r['kind']==='moderate'&&$p['moderation']==='rejected'){$r['outcome']='YouTube acknowledged rejection. Rejection hides replies and cannot be reversed here. Verify in Studio; a missing API comment is not independent proof of the outcome.';return yta_save($r,'acknowledged');}
                $verified=yta_verify($r,$token,$http);yta_same_grant($r,$uid);$r=array_merge($r,$verified);$r['outcome']='YouTube change acknowledged and independently checked.';return yta_save($r,'done');
            }catch(Throwable $e){$uncertain=$returned||!($e instanceof PublishingRemoteException)||$e->ambiguous||$e->httpStatus===0||$e->httpStatus>=500||$e->httpStatus===408;$r['outcome']=$uncertain?'Outcome needs review. Check the saved result or Studio. This action will not be sent again.':'YouTube rejected the request. Review the account, eligibility and quota before preparing another action.';return yta_save($r,$uncertain?'unknown':'failed');}
        });
    });
}
function yta_recheck(string $id,int $uid,int $version,?callable $http=null,?callable $tokens=null): array {
    return comm_lock('yta-'.$id,static function()use($id,$uid,$version,$http,$tokens){$r=yta_owned($id,$uid);yta_version($r,$version);if(!in_array($r['state'],['unknown','verification_pending','done'],true)||empty($r['remote_id']))throw new YouTubeActionException('There is no known result to check. Inspect Studio.');$c=yta_same_grant($r,$uid);$http??='yta_http';$token=$tokens?$tokens($c):publishing_access_token($c);yta_channel($c,$token,$http);$verified=yta_verify($r,$token,$http);yta_same_grant($r,$uid);$r=array_merge($r,$verified);$r['outcome']='Existing YouTube result independently checked. No change was resent.';return yta_save($r,'done');});
}
function yta_dispatch(string $path): bool {
    if(!preg_match('~^/write/google/youtube(?:/(new|[a-f0-9]{32})(/image)?)?$~D',$path,$m))return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}
    $part=$m[1]??'';$record=null;$error='';$values=['kind'=>is_string($_GET['kind']??null)&&isset(yta_types()[$_GET['kind']])?$_GET['kind']:'playlist','privacy'=>'private','draft'=>'1'];
    try{
        if($part!==''&&$part!=='new')$record=yta_owned($part,$uid);
        if(!empty($m[2])){journeys_method(['GET','HEAD']);if(!$record||$record['kind']!=='thumbnail'){http_response_code(404);return true;}$bytes=yta_thumbnail_bytes($record['payload']['asset']);header('Content-Type: '.$record['payload']['asset']['mime']);header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: default-src 'none'; sandbox");if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $bytes;return true;}
        if(is_post()){
            verify_csrf();if(!journeys_rate_limit('youtube-actions',(string)$uid,20,300))throw new YouTubeActionException('Please wait before another YouTube action.');$action=$_POST['action']??'';
            if($action==='prepare'&&$part==='new'){if(($_POST['content_consent']??'')!=='1')throw new YouTubeActionException('Confirm reading the selected channel records and preparing this content.');redirect(url('/write/google/youtube/'.yta_prepare($_POST,$uid)));}
            if(!$record)throw new YouTubeActionException('Choose a saved action.');$version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT);if($version===false)throw new YouTubeActionException('Reload this action.');
            if($action==='review'||$action==='apply'){if(($_POST['confirm']??'')!=='1')throw new YouTubeActionException('Confirm this exact channel, content and outcome.');if($action==='review')yta_review($part,$uid,$version);else yta_execute($part,$uid,$version);}
            elseif($action==='recheck')yta_recheck($part,$uid,$version);
            elseif($action==='discard')comm_lock('yta-'.$part,static function()use($part,$uid,$version){$r=yta_owned($part,$uid);yta_version($r,$version);if(!in_array($r['state'],['draft','reviewed'],true))throw new YouTubeActionException('Keep attempted actions as evidence.');yta_save($r,'discarded');});
            else throw new YouTubeActionException('Choose an available action.');redirect(url('/write/google/youtube/'.$part));
        }
    }catch(YouTubeActionException $e){$error=$e->getMessage();$values=array_filter($_POST,'is_string')?:$values;}catch(Throwable $e){$error='The operation could not complete. Check this saved action before continuing.';error_log('YouTube action failure: '.get_class($e));}
    if($record)$record=yta_owned($part,$uid);$records=yta_records($uid);usort($records,static fn($a,$b)=>$b['created_at']<=>$a['created_at']);$assets=$part==='new'?array_values(array_filter(media_library_choices(),static fn($a)=>$a['status']==='ready'&&in_array($a['mime'],['image/jpeg','image/png'],true)&&(int)$a['bytes']<=2097152)):[];
    render_write('youtube_actions',compact('uid','part','record','records','values','assets','error')+['writeTitle'=>'YouTube channel studio']);return true;
}
