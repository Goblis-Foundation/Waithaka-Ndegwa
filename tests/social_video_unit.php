<?php
declare(strict_types=1);
require_once __DIR__.'/../app/providers/social_video.php';
require_once __DIR__.'/../app/publishing_studio.php';
final class PublishingRemoteException extends RuntimeException {public function __construct(string $m,public readonly int $httpStatus=0,public readonly bool $ambiguous=false){parent::__construct($m);}}
$checks=0;$saved=[];$asset=[];$calls=[];$mode='ok';$posts=0;
function check(bool $v,string $m):void{$GLOBALS['checks']++;if(!$v)throw new RuntimeException($m);}
function denied(callable $fn,string $m):void{try{$fn();}catch(RuntimeException $e){check(true,$m);return;}throw new RuntimeException($m);}
function publishing_json(array $x):string{return json_encode($x,JSON_THROW_ON_ERROR);}
function publishing_decode(?string $x):array{return json_decode($x??'',true)?:[];}
function publishing_public_url(string $x):bool{return str_starts_with($x,'https://')&&!str_contains($x,'token=');}
function publishing_job_url_valid(string $url,string $slug):bool{return $slug==='facebook'&&preg_match('~^https://www\.facebook\.com/(?:reel|videos)/[0-9]+/?$~D',$url);}
function social_options_validate(string $slug,array $v,array $d):void{if(array_diff(array_keys($v['platform_options']??[]),['location_id']))throw new RuntimeException('Unsupported options.');}
function publishing_job_update(int $id,array $f):void{check($id===31,'Only selected job updated');$GLOBALS['saved']=array_replace($GLOBALS['saved'],$f);}
function media_library_approved_asset(int $id):array{check($id===9,'Only approved asset read');return $GLOBALS['asset'];}
function fixture(string $slug='facebook',string $format='reel'):array{
    $GLOBALS['saved']=[];$GLOBALS['calls']=[];$GLOBALS['mode']='ok';$GLOBALS['posts']=0;
    $a=['id'=>9,'mime'=>'video/mp4','bytes'=>9,'sha256'=>str_repeat('a',64),'visibility'=>'public','public_url'=>'https://media.example.com/video.mp4'];$GLOBALS['asset']=$a;
    $c=['id'=>4,'service'=>$slug==='x'?'x_media':'facebook_pages','status'=>'connected','external_id'=>$slug==='x'?'12345':'99999','selected_resource'=>'12345','scopes_json'=>publishing_json($slug==='x'?['tweet.read','tweet.write','users.read','media.write']:['pages_manage_posts','pages_read_engagement']),'connected_by'=>7];
    $d=['slug'=>$slug,'method'=>'direct','connection_id'=>4,'connection_external_id'=>$c['external_id'],'resource'=>'12345','account_type'=>$slug==='x'?'professional':'page','service'=>$c['service']];
    $v=['media_type'=>'video','media_asset_id'=>9,'media_asset'=>$a,'media_url'=>$a['public_url'],'format'=>$slug==='x'?'post':$format,'text'=>'Approved caption','title'=>'Approved title','platform_options'=>[],'video_settings_confirmed'=>true];
    $snap=['variants'=>[$slug=>$v],'destinations'=>[$slug=>$d]];$json=publishing_json($snap);
    $j=['id'=>31,'method'=>'direct','snapshot_json'=>$json,'content_hash'=>hash('sha256',$json),'destination_json'=>publishing_json($d),'provider_state_json'=>null,'remote_id'=>null];$GLOBALS['saved']=$j;return [$j,$snap,$c,$v,$d];
}
function transport(string $method,string $path,string $token,?array $body=null):array{
    global $calls,$mode,$posts;$calls[]=[$method,$path,$body];check($token==='fixture-token','Only selected grant used');
    $phase=publishing_social_video_state($GLOBALS['saved'])['phase']??'';
    if($path==='fb:/me?fields=id')return ['id'=>$mode==='wrong-profile'?'54321':'12345'];
    if($path==='x:/2/users/me')return ['data'=>['id'=>$mode==='wrong-profile'?'54321':'12345']];
    if($method==='POST'&&($mode==='init-timeout'&&$phase==='initializing'||$mode==='publish-timeout'&&$phase==='publishing'))throw new PublishingRemoteException('Fixture timeout',0,true);
    if($path==='fb:/12345/video_reels'){
        if($body['upload_phase']==='start'){check($phase==='initializing','Reel intent saved before init');return ['video_id'=>'67890','upload_url'=>'https://untrusted.invalid/not-followed'];}
        $posts++;check($phase==='publishing'&&$body['description']==='Approved caption','Reel exact approved caption and publication intent');return ['success'=>true];
    }
    if($path==='upload:/67890'){check($phase==='uploading'&&$body['file_url']===$GLOBALS['asset']['public_url'],'Only immutable owned public URL uploaded');return ['success'=>true];}
    if($path==='fb:/12345/videos'){$posts++;check($phase==='publishing','Video intent saved');return ['id'=>'67890'];}
    if(str_starts_with($path,'fb:/67890?'))return ['id'=>'67890','from'=>['id'=>$mode==='wrong-owner'?'77777':'12345'],'description'=>$mode==='wrong-caption'?'Changed caption':'Approved caption','permalink_url'=>$mode==='bad-link'?'https://untrusted.invalid/post':'https://www.facebook.com/videos/67890','status'=>['video_status'=>$mode==='processing'?'processing':'ready','publishing_phase'=>['status'=>'complete']]];
    if($path==='x:/2/media/upload/initialize'){check($phase==='initializing'&&$body['media_category']==='tweet_video','Dedicated v2 init with approved category');return ['data'=>['id'=>'67890','media_key'=>'13_67890']];}
    if($path==='x:/2/media/upload/67890/append'){check($body['chunk']==='123456789'&&$body['segment_index']===0,'Exact video bytes appended once');return [];}
    if($path==='x:/2/media/upload/67890/finalize'){check($phase==='finalizing','Finalize intent saved');return ['data'=>['id'=>'67890']];}
    if(str_starts_with($path,'x:/2/media/upload?'))return ['data'=>['id'=>'67890','processing_info'=>['state'=>$mode==='processing'?'in_progress':($mode==='rejected'?'failed':'succeeded')]]];
    if($path==='x:/2/tweets'){$posts++;check($phase==='publishing'&&$body['text']==='Approved caption'&&$body['media']['media_ids']===['67890'],'One exact approved X create-post');return ['data'=>['id'=>'78901']];}
    if(str_starts_with($path,'x:/2/tweets/78901?'))return ['data'=>['id'=>'78901','author_id'=>$mode==='wrong-owner'?'77777':'12345','attachments'=>['media_keys'=>[$mode==='wrong-media'?'13_11111':'13_67890']]]];
    throw new RuntimeException('Unexpected fixture path '.$path);
}
$token=static fn(array $c):string=>'fixture-token';$open=static function(array $a):array{$f=fopen('php://temp','w+b');fwrite($f,'123456789');rewind($f);return [[], $f];};
foreach([['facebook','reel'],['facebook','video'],['x','post']]as[$slug,$format]){
    [$j,$snap,$c]=fixture($slug,$format);$r=publishing_social_video_deliver($j,$snap,$c,'transport',$token,$open);check($r['status']==='published'&&$posts===1,$slug.' '.$format.' requires independent publication read-back');$saved=array_replace($saved,$r);
    $before=count($calls);denied(fn()=>publishing_social_video_deliver($saved,$snap,$c,'transport',$token,$open),'Cannot recreate known job');denied(fn()=>publishing_social_video_check($saved,$c,'transport',$token),'Cannot replay known publication');check(count($calls)===$before,'No remote request after published state');
    foreach(($slug==='facebook'&&$format==='video'?['publish-timeout']:['init-timeout','publish-timeout'])as$error){[$j,$snap,$c]=fixture($slug,$format);$mode=$error;denied(fn()=>publishing_social_video_deliver($j,$snap,$c,'transport',$token,$open),'Uncertain mutation retained');check(!publishing_social_video_can_resume($saved),'Uncertain mutation is non-replayable');$before=count($calls);denied(fn()=>publishing_social_video_check($saved,$c,'transport',$token),'Cannot replay uncertainty');check(count($calls)===$before,'No network after unsafe boundary');}
    [$j,$snap,$c]=fixture($slug,$format);$mode='processing';$r=publishing_social_video_deliver($j,$snap,$c,'transport',$token,$open);check($r['status']==='delivered','Processing is not publication');$saved=array_replace($saved,$r);$mode='ok';$r=publishing_social_video_check($saved,$c,'transport',$token);check($r['status']==='published'&&$posts===1,'Existing processing record finishes once');
    [$j,$snap,$c]=fixture($slug,$format);$mode='wrong-owner';$r=publishing_social_video_deliver($j,$snap,$c,'transport',$token,$open);check($r['status']==='delivered'&&!empty($saved['remote_id']),'Wrong owner never certified');$saved=array_replace($saved,$r);$mode='ok';$r=publishing_social_video_check($saved,$c,'transport',$token);check($r['status']==='published'&&$posts===1,'Known result recovery is read-only');
    foreach(['grant','content','destination','asset','profile']as$change){[$j,$snap,$c]=fixture($slug,$format);if($change==='grant')$c['scopes_json']='[]';if($change==='content')$j['content_hash']=str_repeat('b',64);if($change==='destination')$c['selected_resource']='54321';if($change==='asset')$asset['bytes']=10;if($change==='profile')$mode='wrong-profile';denied(fn()=>publishing_social_video_deliver($j,$snap,$c,'transport',$token,$open),'Reject changed '.$change);check($posts===0,'No post for changed '.$change);}
}
[$j,$snap,$c]=fixture('x');$mode='processing';$r=publishing_social_video_deliver($j,$snap,$c,'transport',$token,$open);for($i=0;$i<10;$i++){$saved=array_replace($saved,$r);$r=publishing_social_video_check($saved,$c,'transport',$token);}check($r['status']==='failed'&&$posts===0,'Polling bounded without publication');
foreach(['https://www.facebook.com/groups/123456/','https://www.facebook.com/waithaka.ndegwa/subscribe/']as$url)check(publishing_connect_url_valid($url),'Exact group/subscription supported');
foreach(['https://www.facebook.com/waithaka.ndegwa','https://facebook.com.evil.invalid/groups/123/','https://www.facebook.com/groups/123/#token','https://example.com/groups/123/']as$url)check(!publishing_connect_url_valid($url),'Wrong Connect destination rejected');
foreach(['facebook','instagram','x','linkedin','youtube','tiktok']as$slug)check(count(publishing_studio_formats($slug))>0&&publishing_studio_format_note($slug)!=='','All six platforms have explicit format guidance');
echo 'Passed '.$checks." social video/studio checks.\n";
