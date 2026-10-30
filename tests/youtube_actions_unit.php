<?php
declare(strict_types=1);
require_once __DIR__.'/../app/youtube_actions.php';
final class PublishingRemoteException extends RuntimeException {public function __construct(string $m,public readonly int $httpStatus=0,public readonly bool $ambiguous=false){parent::__construct($m);}}
$store=[];$connection=[];$held=[];$requests=[];$remote=[];$checks=0;$writes=0;$mode='ok';$actor=7;$post=false;$csrf=true;$rendered=[];
function check(bool $ok,string $m):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($m);}
function denied(callable $fn,string $m):void{try{$fn();}catch(YouTubeActionException){check(true,$m);return;}throw new RuntimeException($m);}
function publishing_json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR);}
function publishing_decode(string $v):array{return json_decode($v,true,32,JSON_THROW_ON_ERROR);}
function publishing_connection(string $service):?array{check($service==='google_youtube_manage','Dedicated management grant only');return $GLOBALS['connection']?:null;}
function publishing_access_token(array $c):string{return 'fixture-token';}
function comm_config():array{return ['owner'=>7];}
function comm_get(string $key,array $default=[]):array{return $GLOBALS['store'][$key]??$default;}
function comm_put(string $key,array $value):void{$GLOBALS['store'][$key]=$value;}
function comm_lock(string $key,callable $fn):mixed{check(empty($GLOBALS['held'][$key]),'Locks cannot recursively enter');$GLOBALS['held'][$key]=true;try{return $fn();}finally{unset($GLOBALS['held'][$key]);}}
function media_library_approved_asset(int $id):array{return $GLOBALS['assetOverride']??['id'=>$id,'bytes'=>3,'mime'=>'image/png','sha256'=>hash('sha256','png'),'title'=>'Synthetic image'];}
function setup():void{
    $GLOBALS['connection']=['id'=>44,'status'=>'connected','connected_by'=>7,'external_id'=>'subject-7','display_name'=>'fixture@example.invalid','selected_resource'=>'UC'.str_repeat('a',22),'verified_at'=>'2026-10-30 08:00:00','scopes_json'=>'["https://www.googleapis.com/auth/youtube.force-ssl"]'];
    $GLOBALS['store']=[];$GLOBALS['requests']=[];$GLOBALS['remote']=[];$GLOBALS['writes']=0;$GLOBALS['mode']='ok';
}
function source(string $resource,string $id):array{
    $channel='UC'.str_repeat('a',22);$x=['id'=>$id,'etag'=>'revision-1','snippet'=>['channelId'=>$channel,'title'=>'Owned record','description'=>'Description'],'status'=>['privacyStatus'=>'private']];
    if($resource==='comments')$x['snippet']=['channelId'=>$channel,'textOriginal'=>'<script>untrusted comment</script>','moderationStatus'=>'heldForReview'];return $x;
}
function transport(string $method,string $resource,array $query,string $token,?array $body=null,?array $media=null):array{
    global $mode,$requests,$writes,$remote,$connection;
    check($token==='fixture-token','Only a fixture token is used');$requests[]=[$method,$resource,$query,$body];
    if($method==='POST'){
        $writes++;if($mode==='timeout')throw new PublishingRemoteException('timeout',0,true);if($mode==='denied')throw new PublishingRemoteException('denied',403,false);
        if($mode==='grant-during-write')$connection['external_id']='changed';
        if($resource==='comments/setModerationStatus'){check(($query['banAuthor']??'false')==='false'&&!str_contains($query['id'],','),'Single comment, never ban author');check($query['moderationStatus']==='rejected'||!array_key_exists('banAuthor',$query),'Ban parameter omitted where provider forbids it');$x=source('comments',$query['id']);$x['snippet']['moderationStatus']=$query['moderationStatus'];$remote['comments'][$query['id']]=$x;return ['acknowledged'=>true];}
        if($resource==='thumbnails/set'){check($media===['bytes'=>'png','mime'=>'image/png'],'Exact approved image bytes');$remote['thumbnail']=['default'=>['url'=>'https://i.ytimg.com/vi/video_12345/default.jpg']];return ['items'=>[$remote['thumbnail']]];}
        $id='created-'.$writes;$x=['id'=>$id,'etag'=>'new-revision']+$body;
        if($resource==='playlists')$x['snippet']['channelId']='UC'.str_repeat('a',22);
        if($resource==='playlistItems')$x['snippet']['channelId']='UC'.str_repeat('a',22);
        if($resource==='captions'){check(($query['uploadType']??'')==='multipart'&&!isset($query['sync']),'No deprecated caption sync');check($media['bytes']===$GLOBALS['srt'],'Exact reviewed caption bytes');$x['snippet']['status']=$mode==='processing'?'syncing':'serving';$remote['caption-text'][$id]=$media['bytes'];}
        $remote[$resource][$id]=$x;return $x;
    }
    check($method==='GET','Preparation and result checks use GET');
    if($mode==='read-fails'&&$writes>0)throw new PublishingRemoteException('read unavailable',503,false);
    if($resource==='channels')return ['items'=>[['id'=>$mode==='wrong-channel'?'UC'.str_repeat('z',22):'UC'.str_repeat('a',22)]]];
    if($mode==='grant-during-read')$connection['external_id']='changed';
    if(str_starts_with($resource,'captions/'))return ['text'=>$mode==='caption-mismatch'?str_replace('Hello','Changed',$GLOBALS['srt']):$GLOBALS['srt']];
    if($resource==='captions'){
        if(isset($query['id'])){$x=$remote['captions'][$query['id']];if($mode==='processing')$x['snippet']['status']='syncing';else $x['snippet']['status']='serving';return ['items'=>[$x]];}
        return ['items'=>$mode==='existing-caption'?[['id'=>'existing-caption','etag'=>'track-rev','snippet'=>['videoId'=>'video_12345','language'=>'en-KE','name'=>'English']]]:[]];
    }
    if($resource==='playlistItems'){
        if(isset($query['id'])){$x=$remote['playlistItems'][$query['id']];if($mode==='item-mismatch')$x['snippet']['resourceId']['videoId']='wrong_12345';return ['items'=>[$x]];}
        return ['items'=>$mode==='existing-item'?[['id'=>'existing-item']]:[]];
    }
    $id=$query['id'];$x=$remote[$resource][$id]??source($resource,$id);
    if($resource==='videos'&&isset($remote['thumbnail']))$x['snippet']['thumbnails']=$mode==='thumbnail-mismatch'?[]:$remote['thumbnail'];
    if($mode==='wrong-owner')$x['snippet']['channelId']='UC'.str_repeat('z',22);
    if($mode==='changed-source')$x['etag']='changed';
    if($mode==='content-mismatch'&&$writes>0)$x['snippet']['description']='changed';
    $d=['items'=>[$x]];if($mode==='incomplete')$d['nextPageToken']='another';return $d;
}
$tokens=static fn()=> 'fixture-token';$images=static fn()=> 'png';$srt="1\n00:00:01,000 --> 00:00:03,000\nHello world.\n\n2\n00:00:03,500 --> 00:00:05,000\nSecond cue.";
$cases=[
    'playlist'=>['kind'=>'playlist','title'=>'Private test playlist','description'=>'Reviewed description','privacy'=>'private'],
    'playlist_item'=>['kind'=>'playlist_item','video'=>'video_12345','playlist'=>'PL_fixture'],
    'caption'=>['kind'=>'caption','video'=>'video_12345','language'=>'en-KE','name'=>'English','text'=>$srt,'draft'=>'1'],
    'thumbnail'=>['kind'=>'thumbnail','video'=>'video_12345','asset'=>'9'],
    'moderate'=>['kind'=>'moderate','comment'=>'Ug.fixture.reply','moderation'=>'published'],
];
foreach($cases as$kind=>$input){
    setup();$id=yta_prepare($input,7,'transport',$tokens);$r=yta_owned($id,7);check($r['state']==='draft'&&$writes===0,$kind.' preview cannot write');
    denied(fn()=>yta_owned($id,8),'Other user cannot read private preview');denied(fn()=>yta_execute($id,7,1,'transport',$tokens,$images),'Review required');
    yta_review($id,7,1);$r=yta_execute($id,7,2,'transport',$tokens,$images);check($r['state']==='done'&&$writes===1,$kind.' exact mutation and independent read-back');
    $before=$writes;denied(fn()=>yta_execute($id,7,$r['version'],'transport',$tokens,$images),'Completed action cannot replay');check($writes===$before,'No duplicate mutation');
    $r=yta_recheck($id,7,$r['version'],'transport',$tokens);check($r['state']==='done'&&$writes===$before,'Result recheck is read only');
}
foreach(['timeout'=>'unknown','denied'=>'failed','read-fails'=>'unknown','content-mismatch'=>'unknown','grant-during-write'=>'unknown']as$testMode=>$expected){
    setup();$id=yta_prepare($cases['playlist'],7,'transport',$tokens);yta_review($id,7,1);$mode=$testMode;$r=yta_execute($id,7,2,'transport',$tokens);check($r['state']===$expected,'Truthful '.$testMode.' state');$before=$writes;
    denied(fn()=>yta_execute($id,7,$r['version'],'transport',$tokens),'No automatic retry after '.$testMode);check($writes===$before,'No mutation replay after uncertain result');
    if($testMode==='read-fails'){$mode='ok';$r=yta_recheck($id,7,$r['version'],'transport',$tokens);check($r['state']==='done'&&$writes===$before,'Known result recovers by reads alone');}
}
setup();$id=yta_prepare($cases['playlist'],7,'transport',$tokens);yta_review($id,7,1);$r=yta_owned($id,7);$r['payload']['title']='tampered';comm_put('youtube.action.'.$id,$r);denied(fn()=>yta_execute($id,7,2,'transport',$tokens),'Approval digest blocks changed content');check($writes===0,'No tampered write');
setup();$id=yta_prepare($cases['playlist'],7,'transport',$tokens);yta_review($id,7,1);$r=yta_owned($id,7);$r['reviewed_at']=time()-1801;comm_put('youtube.action.'.$id,$r);denied(fn()=>yta_execute($id,7,2,'transport',$tokens),'Expired review blocked');yta_review($id,7,2);check(yta_owned($id,7)['reviewed_at']>=time()-1,'Explicit review renewal');
foreach(['external_id','selected_resource','verified_at','connected_by','scopes_json']as$field){setup();$id=yta_prepare($cases['playlist'],7,'transport',$tokens);yta_review($id,7,1);$connection[$field]=$field==='connected_by'?8:($field==='scopes_json'?'[]':'changed');denied(fn()=>yta_execute($id,7,2,'transport',$tokens),'Changed grant field '.$field.' blocks execution');check($writes===0,'No changed-grant mutation');}
foreach(['wrong-channel','wrong-owner','incomplete','grant-during-read']as$testMode){setup();$mode=$testMode;denied(fn()=>yta_prepare($cases['playlist_item'],7,'transport',$tokens),'Unsafe source '.$testMode.' rejected');check($writes===0&&$store===[],'No partial action after unsafe source');}
setup();$id=yta_prepare($cases['playlist_item'],7,'transport',$tokens);yta_review($id,7,1);$mode='changed-source';denied(fn()=>yta_execute($id,7,2,'transport',$tokens),'Concurrent source revision change');check($writes===0,'No write after source change');
setup();$mode='existing-item';denied(fn()=>yta_prepare($cases['playlist_item'],7,'transport',$tokens),'Already-present video not duplicated');
setup();$mode='existing-caption';denied(fn()=>yta_prepare($cases['caption'],7,'transport',$tokens),'Existing matching caption not overwritten');
foreach(['caption'=>['processing','caption-mismatch'],'thumbnail'=>['thumbnail-mismatch'],'playlist_item'=>['item-mismatch']]as$kind=>$modes)foreach($modes as$testMode){setup();$id=yta_prepare($cases[$kind],7,'transport',$tokens);yta_review($id,7,1);$mode=$testMode;$r=yta_execute($id,7,2,'transport',$tokens,$images);check($r['state']==='unknown'&&isset($r['remote_id']),'Unverified '.$testMode.' keeps known result without replay');$mode='ok';$before=$writes;$r=yta_recheck($id,7,$r['version'],'transport',$tokens);check($r['state']==='done'&&$writes===$before,'Read-only recovery after '.$testMode);}
setup();$input=array_replace($cases['moderate'],['moderation'=>'rejected']);$id=yta_prepare($input,7,'transport',$tokens);yta_review($id,7,1);$r=yta_execute($id,7,2,'transport',$tokens);check($r['state']==='acknowledged'&&!isset($r['verification']),'Rejection acknowledgement never mislabeled independently verified');denied(fn()=>yta_recheck($id,7,$r['version'],'transport',$tokens),'No false rejection verification by disappearance');
setup();$id=yta_prepare($cases['thumbnail'],7,'transport',$tokens);yta_review($id,7,1);denied(fn()=>yta_execute($id,7,2,'transport',$tokens,static fn()=> 'changed'),'Changed image bytes block write');check($writes===0,'No changed-image upload');
setup();$connection['scopes_json']='["https://www.googleapis.com/auth/youtube.upload"]';denied(fn()=>yta_ready(7),'Upload grant never substitutes management scope');setup();$connection['status']='pending_confirmation';denied(fn()=>yta_ready(7),'Unconfirmed channel cannot execute');
foreach(['../escape','https://untrusted.invalid','x&part=secret',',batch','..','']as$id)denied(fn()=>yta_id($id),'Unsafe resource ID rejected');check(yta_id('Ug.reply.with.dots')==='Ug.reply.with.dots','Opaque reply ID accepted');check(yta_id('opaque_caption==')==='opaque_caption==','Opaque padded caption ID accepted');
foreach(["no timing",str_replace('00:00:03,000','00:00:00,500',$srt),str_replace('00:00:03,500','00:00:00,500',$srt),str_replace('Hello world.','',$srt),str_repeat('x',100001)]as$bad)denied(fn()=>yta_srt($bad),'Malformed SRT rejected');check(yta_srt(str_replace("\n","\r\n",$srt))===yta_srt($srt),'SRT line endings normalised');
denied(fn()=>yta_thumbnail(['bytes'=>2097153,'mime'=>'image/png','sha256'=>str_repeat('a',64)]),'Thumbnail byte limit');denied(fn()=>yta_thumbnail(['bytes'=>3,'mime'=>'image/svg+xml','sha256'=>str_repeat('a',64)]),'SVG thumbnail rejected');
denied(fn()=>yta_http('GET','https://untrusted.invalid',[],'unused'),'No arbitrary provider URL');denied(fn()=>yta_http('DELETE','videos',[],'unused'),'Unsupported remote mutation rejected before transport');
// Native media staging is byte-bounded and always released, including changed/invalid files.
function media_library_materialize(array $asset,int $limit):array{check($limit===2097152,'Native staging byte limit');return ['path'=>$GLOBALS['imageFile']];}
function media_library_release(array $local):void{$GLOBALS['released']=($GLOBALS['released']??0)+1;}
$imageFile=tempnam(sys_get_temp_dir(),'yta-native-');
try{
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aS2kAAAAASUVORK5CYII=',true);file_put_contents($imageFile,$png);
    $assetOverride=['id'=>9,'bytes'=>strlen($png),'mime'=>'image/png','sha256'=>hash('sha256',$png),'title'=>'Native fixture'];
    $released=0;check(yta_thumbnail_bytes($assetOverride)===$png,'Native image returns only exact approved bytes');check($released===1,'Native staging released after success');
    file_put_contents($imageFile,str_repeat('x',2097153));denied(fn()=>yta_thumbnail_bytes($assetOverride),'Changed oversized staging file rejected');check($released===2,'Changed staging released');
    file_put_contents($imageFile,'not an image');$assetOverride['bytes']=strlen('not an image');$assetOverride['sha256']=hash('sha256','not an image');denied(fn()=>yta_thumbnail_bytes($assetOverride),'Non-image contents rejected after hash validation');check($released===3,'Invalid image staging released');
    $snapshot=$assetOverride;$assetOverride['title']='Changed metadata';denied(fn()=>yta_thumbnail_bytes($snapshot),'Changed media-library metadata rejected');check($released===3,'No staging created after metadata mismatch');
}finally{unlink($imageFile);unset($assetOverride);}
// Exercise the real dispatcher without provider calls, including owner, CSRF and exact confirmation.
function require_auth():void{}
function private_response_headers():void{}
function journeys_method(array $v):void{if(!in_array($GLOBALS['post']?'POST':'GET',$v,true))throw new RuntimeException('Method blocked');}
function current_user():array{return ['id'=>$GLOBALS['actor']];}
function is_post():bool{return $GLOBALS['post'];}
function verify_csrf():void{if(!$GLOBALS['csrf'])throw new RuntimeException('CSRF fixture rejection');}
function journeys_rate_limit(...$args):bool{return true;}
function render_write(string $view,array $data):void{$GLOBALS['rendered']=[$view,$data];}
function media_library_choices():array{return [];}
setup();$before=$requests;check(yta_dispatch('/write/google/youtube'),'Studio route handled');check($requests===$before,'GET studio performs no provider request');
$actor=8;ob_start();yta_dispatch('/write/google/youtube');$deniedBody=ob_get_clean();check(http_response_code()===403&&str_contains($deniedBody,'Owner'),'Nonowner route forbidden');$actor=7;http_response_code(200);
$post=true;$csrf=false;$_POST=['action'=>'prepare','kind'=>'playlist','content_consent'=>'1'];$before=$requests;yta_dispatch('/write/google/youtube/new');check($requests===$before,'CSRF blocks provider work');$csrf=true;$_POST=['action'=>'prepare','kind'=>'playlist'];yta_dispatch('/write/google/youtube/new');check($requests===$before,'Missing content consent blocks provider work');$post=false;$_POST=[];
// Render the actual production preview: provider text is inert, IDs/account are visible.
function e(mixed $v):string{return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $v):string{return $v;}
function csrf_field():string{return '<input type="hidden" name="csrf" value="synthetic">';}
setup();$id=yta_prepare($cases['moderate'],7,'transport',$tokens);$record=yta_owned($id,7);$uid=7;$part=$id;$error='';$records=[];$assets=[];$values=[];ob_start();include __DIR__.'/../views/write/youtube_actions.php';$html=ob_get_clean();check(!str_contains($html,'<script>untrusted')&&str_contains($html,'&lt;script&gt;untrusted'),'Provider comment is escaped');check(str_contains($html,$record['channel'])&&str_contains($html,'Confirm review'),'Exact channel and explicit review visible');
echo 'PASS: '.$checks." YouTube action, scope, ownership, source revision, exact approval, no-replay, read-back and rendering checks. No provider called.\n";
