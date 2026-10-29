<?php
declare(strict_types=1);

/** Selected, read-only Meet artifacts. Local transcript copies use encrypted owner storage. */
final class GoogleMeetException extends RuntimeException {}
function gmeet_ready(int $uid): array {
    $c=publishing_connection('google_meet');
    if($uid<1||!$c||($c['status']??'')!=='connected'||(int)($c['connected_by']??0)!==$uid||empty($c['external_id'])||($c['selected_resource']??'')!==$c['external_id'])throw new GoogleMeetException('Connect and confirm your own Google Meet account first.');
    if(!in_array('https://www.googleapis.com/auth/meetings.space.readonly',publishing_decode($c['scopes_json']??'[]'),true))throw new GoogleMeetException('Reconnect Google Meet to grant its separate read permission.');
    return $c;
}
function gmeet_fingerprint(array $c): string {
    return hash('sha256',json_encode([$c['id'],$c['external_id'],$c['selected_resource'],$c['connected_by'],$c['verified_at']??'',$c['scopes_json']],JSON_THROW_ON_ERROR));
}
function gmeet_bound(int $uid,string $fingerprint): array {
    $c=gmeet_ready($uid);
    if(!hash_equals(gmeet_fingerprint($c),$fingerprint))throw new GoogleMeetException('The Google account or grant changed. Read the meeting list again.');
    return $c;
}
function gmeet_name(mixed $name,string $kind='conference'): string {
    $tail=match($kind){'conference'=>'','transcript'=>'/transcripts/[A-Za-z0-9_-]{1,200}','recording'=>'/recordings/[A-Za-z0-9_-]{1,200}',default=>throw new GoogleMeetException('Unsupported artifact type.')};
    if(!is_string($name)||!preg_match('~^conferenceRecords/[A-Za-z0-9_-]{1,200}'.$tail.'$~D',$name))throw new GoogleMeetException('Select a valid meeting artifact.');
    return $name;
}
function gmeet_get(string $path,array $headers,callable $http,array $query=[]): array {
    $r=$http('GET','https://meet.googleapis.com/v2/'.$path.($query?'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986):''),$headers);
    if(isset($r['status'])&&($r['status']<200||$r['status']>=300))throw new GoogleMeetException('Google did not return this artifact. Check your account and try an explicit read again.');
    $data=$r['data']??null;
    if(!is_array($data)||isset($data['error'])||strlen(json_encode($data,JSON_THROW_ON_ERROR))>1000000)throw new GoogleMeetException('Google returned an invalid or oversized response. Nothing was imported.');
    return $data;
}
function gmeet_list(string $path,string $field,array $headers,callable $http,array $query=[]): array {
    $rows=[];$tokens=[];$bytes=0;
    for($page=0;$page<5;$page++){
        $data=gmeet_get($path,$headers,$http,$query+['pageSize'=>100]);$items=$data[$field]??[];
        if(!is_array($items)||!array_is_list($items))throw new GoogleMeetException('Google returned an invalid artifact list.');
        foreach($items as$item){if(!is_array($item))throw new GoogleMeetException('Google returned an invalid artifact.');$bytes+=strlen(json_encode($item,JSON_THROW_ON_ERROR));$rows[]=$item;}
        if(count($rows)>500||$bytes>750000)throw new GoogleMeetException('This result exceeds the private import limit. Open the original in Google. Nothing was imported.');
        $next=$data['nextPageToken']??'';
        if(!is_string($next)||strlen($next)>4096)throw new GoogleMeetException('Google returned an invalid page token.');
        if($next==='')return $rows;
        if(isset($tokens[$next]))throw new GoogleMeetException('Google repeated a page. Nothing was imported.');
        $tokens[$next]=true;$query['pageToken']=$next;
    }
    throw new GoogleMeetException('This result needs more than five pages. Open the original in Google. Nothing was imported.');
}
function gmeet_headers(array $c,?callable $tokenProvider): array {
    return publishing_bearer($tokenProvider?$tokenProvider($c):publishing_access_token($c));
}
function gmeet_discover(int $uid,?callable $http=null,?callable $tokenProvider=null): array {
    $c=gmeet_ready($uid);$fingerprint=gmeet_fingerprint($c);$until=gmdate('Y-m-d\TH:i:s\Z');$since=gmdate('Y-m-d\TH:i:s\Z',time()-7*86400);
    $rows=gmeet_list('conferenceRecords','conferenceRecords',gmeet_headers($c,$tokenProvider),$http??'publishing_http',['filter'=>'end_time >= "'.$since.'" AND end_time <= "'.$until.'"']);
    $records=[];$seen=[];foreach($rows as$r){$name=gmeet_name($r['name']??null);if(isset($seen[$name]))throw new GoogleMeetException('Google repeated a meeting. Read the list again.');$seen[$name]=true;$records[]=['name'=>$name,'start'=>gmeet_timestamp($r['startTime']??''),'end'=>gmeet_timestamp($r['endTime']??'')];}
    gmeet_bound($uid,$fingerprint);
    return ['records'=>$records,'fingerprint'=>$fingerprint,'account'=>$c['display_name'],'since'=>$since,'until'=>$until];
}
function gmeet_timestamp(mixed $value): string {
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D',$value))throw new GoogleMeetException('Google returned an invalid artifact timestamp.');
    return $value;
}
function gmeet_artifact(array $r,string $kind,string $conference): array {
    $name=gmeet_name($r['name']??null,$kind);
    if(!str_starts_with($name,$conference.'/'))throw new GoogleMeetException('Google returned an artifact from another meeting.');
    $state=$r['state']??'';
    if(!in_array($state,['STATE_UNSPECIFIED','STARTED','ENDED','FILE_GENERATED'],true))throw new GoogleMeetException('Google returned an unknown artifact state.');
    $file=$kind==='transcript'?($r['docsDestination']['document']??''):($r['driveDestination']['file']??'');$link='';
    // Build links from strict file IDs; never trust an arbitrary provider-supplied export URL.
    if($state==='FILE_GENERATED'&&is_string($file)&&preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$file))$link=$kind==='transcript'?'https://docs.google.com/document/d/'.$file.'/edit':'https://drive.google.com/file/d/'.$file.'/view';
    return ['name'=>$name,'kind'=>$kind,'state'=>$state,'start'=>gmeet_timestamp($r['startTime']??''),'end'=>empty($r['endTime'])?'':gmeet_timestamp($r['endTime']),'url'=>$link];
}
function gmeet_artifacts(string $conference,int $uid,string $fingerprint,?callable $http=null,?callable $tokenProvider=null): array {
    $conference=gmeet_name($conference);$c=gmeet_bound($uid,$fingerprint);$headers=gmeet_headers($c,$tokenProvider);$send=$http??'publishing_http';$artifacts=[];
    foreach(['recordings'=>'recording','transcripts'=>'transcript']as$collection=>$kind)foreach(gmeet_list($conference.'/'.$collection,$collection,$headers,$send)as$r)$artifacts[]=gmeet_artifact($r,$kind,$conference);
    gmeet_bound($uid,$fingerprint);
    return ['conference'=>$conference,'fingerprint'=>$fingerprint,'account'=>$c['display_name'],'artifacts'=>$artifacts];
}
function gmeet_records(int $uid): array {
    $out=[];foreach(comm_get('meet.index.'.$uid)['ids']??[]as$id){$r=comm_get('meet.copy.'.$id);if(($r['owner']??0)===$uid)$out[]=$r;}return $out;
}
function gmeet_owned(string $id,int $uid): array {
    if($uid<1||!preg_match('/^[a-f0-9]{64}$/D',$id))throw new GoogleMeetException('Choose a saved private transcript.');
    $r=comm_get('meet.copy.'.$id);if(($r['owner']??0)!==$uid)throw new GoogleMeetException('This private transcript is unavailable.');return $r;
}
function gmeet_import(string $name,int $uid,string $fingerprint,?callable $http=null,?callable $tokenProvider=null): string {
    $name=gmeet_name($name,'transcript');$c=gmeet_bound($uid,$fingerprint);$id=hash('sha256',$uid.'|'.$c['external_id'].'|'.$name);
    if(comm_get('meet.copy.'.$id)){gmeet_owned($id,$uid);return $id;}
    $headers=gmeet_headers($c,$tokenProvider);$send=$http??'publishing_http';$conference=explode('/transcripts/',$name)[0];
    $artifact=gmeet_artifact(gmeet_get($name,$headers,$send),'transcript',$conference);
    if($artifact['name']!==$name||$artifact['state']!=='FILE_GENERATED'||$artifact['end']==='')throw new GoogleMeetException('The selected transcript is not ready. Read its status again after Google finishes it.');
    $rows=gmeet_list($name.'/entries','transcriptEntries',$headers,$send);$entries=[];$seen=[];$bytes=0;$speakers=[];
    foreach($rows as$r){
        $entry=$r['name']??'';$text=$r['text']??null;$participant=$r['participant']??'';
        if(!is_string($entry)||!preg_match('~^'.preg_quote($name,'~').'/entries/[A-Za-z0-9_-]{1,200}$~D',$entry)||isset($seen[$entry]))throw new GoogleMeetException('Google returned inconsistent transcript entries. Nothing was imported.');
        if(!is_string($text)||!mb_check_encoding($text,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text))throw new GoogleMeetException('Google returned invalid transcript text.');
        if(!is_string($participant)||!preg_match('~^'.preg_quote($conference,'~').'/participants/[A-Za-z0-9_-]{1,200}$~D',$participant))throw new GoogleMeetException('Google returned an invalid speaker reference.');
        $bytes+=strlen($text);if($bytes>250000)throw new GoogleMeetException('The transcript exceeds 250,000 bytes. Open the original in Google. Nothing was imported.');
        $seen[$entry]=true;if(!isset($speakers[$participant]))$speakers[$participant]=count($speakers)+1;
        $entries[]=['speaker'=>$speakers[$participant],'text'=>$text,'start'=>gmeet_timestamp($r['startTime']??''),'end'=>gmeet_timestamp($r['endTime']??'')];
    }
    if(!$entries)throw new GoogleMeetException('No transcript entries were returned. They may be unavailable or outside Google’s retention period. Nothing was imported.');
    return comm_lock('meet-owner-'.$uid,static function()use($uid,$fingerprint,$id,$c,$artifact,$entries){
        gmeet_bound($uid,$fingerprint);$index=comm_get('meet.index.'.$uid,['ids'=>[]]);
        if(comm_get('meet.copy.'.$id)){gmeet_owned($id,$uid);return $id;}
        if(count($index['ids'])>=100)throw new GoogleMeetException('Remove an older local copy before importing more than 100 transcripts.');
        $record=['id'=>$id,'owner'=>$uid,'account'=>$c['display_name'],'imported_at'=>time(),'artifact'=>$artifact,'entries'=>$entries];
        // Index first: a failed copy write leaves only an invisible index entry, never orphaned content.
        $index['ids']=array_values(array_unique([...$index['ids'],$id]));comm_put('meet.index.'.$uid,$index);comm_put('meet.copy.'.$id,$record);return $id;
    });
}
function gmeet_remove(string $id,int $uid): void {
    comm_lock('meet-owner-'.$uid,static function()use($id,$uid){gmeet_owned($id,$uid);comm_put('meet.copy.'.$id,[]);$index=comm_get('meet.index.'.$uid,['ids'=>[]]);$index['ids']=array_values(array_diff($index['ids'],[$id]));comm_put('meet.index.'.$uid,$index);});
}
function gmeet_dispatch(string $path): bool {
    if(!preg_match('~^/write/google/meet(?:/([a-f0-9]{64}))?$~D',$path,$m))return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];
    if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}
    $record=null;$discovery=null;$selection=null;$error='';$connected=null;
    try{
        if(isset($m[1]))$record=gmeet_owned($m[1],$uid);
        if(is_post()){
            verify_csrf();if(!journeys_rate_limit('google-meet',(string)$uid,12,300))throw new GoogleMeetException('Please wait before reading more artifacts.');
            $a=$_POST['action']??'';$fingerprint=is_string($_POST['fingerprint']??null)?$_POST['fingerprint']:'';
            if($a==='discover'&&!$record)$discovery=gmeet_discover($uid);
            elseif($a==='artifacts'&&!$record)$selection=gmeet_artifacts(gmeet_name($_POST['conference']??null),$uid,$fingerprint);
            elseif($a==='import'&&!$record){if(($_POST['confirm']??'')!=='1')throw new GoogleMeetException('Confirm keeping this selected transcript privately.');$id=gmeet_import(gmeet_name($_POST['transcript']??null,'transcript'),$uid,$fingerprint);redirect(url('/write/google/meet/'.$id));}
            elseif($a==='remove'&&$record){if(($_POST['confirm']??'')!=='1')throw new GoogleMeetException('Confirm removing this local copy.');gmeet_remove($record['id'],$uid);redirect(url('/write/google/meet'));}
            else throw new GoogleMeetException('Choose an available Meet action.');
        }
    }catch(GoogleMeetException $e){$error=$e->getMessage();}
    catch(Throwable $e){$error='Google Meet could not complete this request. Check your connection and the saved copies before trying again.';error_log('Google Meet failure: '.get_class($e));}
    try{$connected=gmeet_ready($uid);}catch(GoogleMeetException $e){}
    $records=gmeet_records($uid);render_write('google_meet',compact('record','discovery','selection','error','connected','records')+['writeTitle'=>'Google Meet artifacts']);return true;
}
