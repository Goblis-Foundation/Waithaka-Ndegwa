<?php
declare(strict_types=1);
require_once __DIR__.'/../app/social_options.php';
require_once __DIR__.'/../app/providers/instagram_native.php';
require_once __DIR__.'/../app/providers/direct.php';
final class PublishingRemoteException extends RuntimeException {public function __construct(string $m,public readonly int $httpStatus=0,public readonly bool $ambiguous=false){parent::__construct($m);}}
$checks=0;$calls=[];$saved=[];$mode='ok';$creates=0;$publishes=0;$statusReads=0;$frozen=[];
function check(bool $v,string $m):void{$GLOBALS['checks']++;if(!$v)throw new RuntimeException($m);}
function denied(callable $fn,string $m,bool $ambiguous=false):void{try{$fn();}catch(RuntimeException $e){if($ambiguous)check($e instanceof PublishingRemoteException&&$e->ambiguous,$m.' ambiguity retained');check(true,$m);return;}throw new RuntimeException($m);}
function publishing_json(array $x):string{return json_encode($x,JSON_THROW_ON_ERROR);}
function publishing_decode(?string $x):array{return json_decode($x??'',true)?:[];}
function publishing_public_url(string $x):bool{return str_starts_with($x,'https://media.example.com/');}
function publishing_job_url_valid(string $url,string $slug):bool{return $slug==='instagram'&&preg_match('~^https://www\.instagram\.com/(p|reel)/[A-Za-z0-9_-]+/$~D',$url);}
function publishing_job_update(int $id,array $fields):void{check($id===31,'Only selected job updated');$GLOBALS['saved']=array_replace($GLOBALS['saved'],$fields);}
function media_library_approved_asset(int $id):array{check($id===9,'Exact owned media selection');return $GLOBALS['frozen'];}
function setup(string $format='reel'):array{
    global $calls,$saved,$mode,$creates,$publishes,$statusReads,$frozen;$GLOBALS['tokenCalls']=0;$calls=[];$mode='ok';$creates=0;$publishes=0;$statusReads=0;
    $c=['id'=>12,'service'=>'instagram','status'=>'connected','external_id'=>'17840000000000001','selected_resource'=>'17840000000000001','connected_by'=>7,'scopes_json'=>'["instagram_business_basic","instagram_business_content_publish"]'];
    $frozen=['id'=>9,'visibility'=>'public','public_url'=>'https://media.example.com/immutable.mp4','bytes'=>1000,'sha256'=>str_repeat('a',64),'mime'=>'video/mp4'];
    $v=['format'=>$format,'text'=>'Reviewed caption','media_url'=>$format==='reel'?$frozen['public_url']:'https://media.example.com/image.jpg','media_asset_id'=>$format==='reel'?9:0,'media_asset'=>$format==='reel'?$frozen:[],'media_type'=>$format==='reel'?'video':'image','alt_text'=>$format==='reel'?'':'Reviewed image description','platform_options'=>[],'ai_generated'=>false,'instagram_share_to_feed'=>false,'instagram_settings_confirmed'=>true];
    $d=['slug'=>'instagram','method'=>'direct','service'=>'instagram','connection_id'=>12,'connection_external_id'=>$c['external_id'],'resource'=>$c['selected_resource']];$snapshot=['variants'=>['instagram'=>$v],'destinations'=>['instagram'=>$d]];$json=publishing_json($snapshot);
    $job=['id'=>31,'method'=>'direct','status'=>'scheduled','snapshot_json'=>$json,'content_hash'=>hash('sha256',$json),'destination_json'=>publishing_json($d),'provider_state_json'=>null,'remote_id'=>null];$saved=$job;return [$job,$snapshot,$c,$v];
}
function transport(string $method,string $path,array $query,string $token,?array $body=null):array{
    global $calls,$mode,$creates,$publishes,$statusReads,$saved;
    check($token==='fixture-token','Token only reaches fixture transport');$calls[]=[$method,$path,$query,$body];
    if($path==='/me')return ['user_id'=>$mode==='wrong-profile'?'17840000000009999':'17840000000000001','account_type'=>$mode==='consumer'?'PERSONAL':'MEDIA_CREATOR'];
    if($method==='POST'&&str_ends_with($path,'/media')){
        $creates++;$s=publishing_decode($saved['provider_state_json']);check($s['phase']==='initializing','Container intent persisted before request');
        if($mode==='init-timeout')throw new PublishingRemoteException('Synthetic timeout',0,true);if($mode==='init-missing')return [];
        if(($body['media_type']??'')==='REELS'){check($body['share_to_feed']==='false','Exact reviewed feed visibility');check($body['video_url']==='https://media.example.com/immutable.mp4','Owned public video only');check(!isset($body['alt_text'])&&!isset($body['location_id'])&&!isset($body['user_tags']),'No unsupported Reel metadata sent');}
        else check(($body['image_url']??'')==='https://media.example.com/image.jpg'&&($body['alt_text']??'')==='Reviewed image description','Existing JPEG payload preserved');
        return ['id'=>'18000000000000001'];
    }
    if($method==='POST'&&str_ends_with($path,'/media_publish')){
        $publishes++;check(publishing_decode($saved['provider_state_json'])['phase']==='publish_attempted','Non-replayable publish marker precedes request');check($body===['creation_id'=>'18000000000000001'],'Publish uses existing saved container');
        if($mode==='publish-timeout')throw new PublishingRemoteException('Synthetic timeout',0,true);if($mode==='publish-missing')return [];return ['id'=>'18000000000000002'];
    }
    if($path==='/18000000000000001'){$statusReads++;return ['id'=>$mode==='wrong-container'?'18000000000009999':'18000000000000001','status_code'=>match($mode){'processing'=>'IN_PROGRESS','rejected'=>'ERROR','expired'=>'EXPIRED','already-published'=>'PUBLISHED','unknown-status'=>'UNKNOWN',default=>'FINISHED'}];}
    if($path==='/18000000000000002'){
        if($mode==='verify-timeout')throw new PublishingRemoteException('Synthetic read failure',503);
        $s=publishing_decode($saved['provider_state_json']);return ['id'=>'18000000000000002','caption'=>$mode==='wrong-caption'?'Changed':'Reviewed caption','media_type'=>$s['format']==='reel'?'VIDEO':'IMAGE','media_product_type'=>$mode==='wrong-format'?'FEED':($s['format']==='reel'?'REELS':'FEED'),'permalink'=>$mode==='bad-link'?'https://untrusted.invalid/post':'https://www.instagram.com/'.($s['format']==='reel'?'reel':'p').'/fixture/'];
    }
    if($path==='/17840000000000001/media'){
        check($method==='GET'&&$query['limit']===25,'Owned-media verification is a bounded read');
        if($mode==='wrong-owner')return ['data'=>[['id'=>'18000000000009999']]];
        if($mode==='paging-loop')return ['data'=>[],'paging'=>['next'=>'https://untrusted.invalid/never-follow','cursors'=>['after'=>'repeated']]];
        if($mode==='paging-valid'&&!isset($query['after']))return ['data'=>[['id'=>'18000000000009999']],'paging'=>['next'=>'https://untrusted.invalid/never-follow','cursors'=>['after'=>'next_cursor']]];
        return ['data'=>[['id'=>'18000000000000002']]];
    }
    throw new RuntimeException('Unexpected fixture request '.$method.' '.$path);
}
$tokens=static function(array $c):string{$GLOBALS['tokenCalls']++;return 'fixture-token';};
foreach(['post','reel']as$format){[$job,$snapshot,$c,$v]=setup($format);$r=publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens);check($r['status']==='published'&&$creates===1&&$publishes===1,$format.' published only after independent receipt checks');check($GLOBALS['tokenCalls']===1,'Access token resolved once during initial delivery');$saved=array_replace($saved,$r);$before=count($calls);denied(fn()=>publishing_instagram_native_deliver($saved,$snapshot,$c,'transport',$tokens),'Existing job cannot create another container',true);denied(fn()=>publishing_instagram_native_check($saved,$c,'transport',$tokens),'Published state cannot replay',true);check(count($calls)===$before,'No API request after no-replay boundary');}
foreach(['init-timeout','init-missing','publish-timeout','publish-missing']as$testMode){[$job,$snapshot,$c]=setup();$mode=$testMode;denied(fn()=>publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens),'Uncertain '.$testMode.' retained',true);$state=publishing_instagram_native_state($saved);check($state['phase']===(str_starts_with($mode,'init')?'initializing':'publish_attempted'),'Crash-safe intent preserved');check(!publishing_instagram_can_resume($saved),'Uncertain mutation cannot resume automatically');$before=$publishes;denied(fn()=>publishing_instagram_native_check($saved,$c,'transport',$tokens),'Saved uncertain state cannot send again',true);check($publishes===$before,'No duplicate publish after uncertainty');}
[$job,$snapshot,$c]=setup();$mode='processing';$r=publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens);check($r['status']==='delivered'&&$publishes===0,'Processing is not publication');$saved=array_replace($saved,$r);check(publishing_instagram_can_resume($saved),'Known waiting container can resume');$mode='ok';$r=publishing_instagram_native_check($saved,$c,'transport',$tokens);check($r['status']==='published'&&$creates===1&&$publishes===1,'Only existing finished container is published');
[$job,$snapshot,$c]=setup();$mode='processing';$r=publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens);for($i=0;$i<5;$i++){$saved=array_replace($saved,$r);$r=publishing_instagram_native_check($saved,$c,'transport',$tokens);}check($r['status']==='failed'&&$statusReads===5&&$publishes===0,'Processing checks are bounded to five');
foreach(['rejected','expired','already-published']as$testMode){[$job,$snapshot,$c]=setup();$mode=$testMode;$r=publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens);check($r['status']===($mode==='already-published'?'ambiguous':'failed')&&$publishes===0,'No publish after '.$mode);}
foreach(['wrong-profile','consumer','wrong-container','unknown-status']as$testMode){[$job,$snapshot,$c]=setup();$mode=$testMode;denied(fn()=>publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens),'Unsafe '.$mode.' blocked');check($publishes===0,'No publication after unsafe identity/status');}
foreach(['verify-timeout','wrong-caption','wrong-format','bad-link','wrong-owner','paging-loop']as$testMode){[$job,$snapshot,$c]=setup();$mode=$testMode;$r=publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens);check($r['status']==='delivered'&&$r['provider_state_json']!==''&&$saved['remote_id']==='18000000000000002','Known ID retained after '.$mode);$saved=array_replace($saved,$r);$mode='ok';$r=publishing_instagram_native_check($saved,$c,'transport',$tokens);check($r['status']==='published'&&$publishes===1&&$creates===1,'Read-only verification recovery after '.$testMode);}
[$job,$snapshot,$c]=setup();$mode='paging-valid';$r=publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens);check($r['status']==='published','Bounded cursor verification can find a known owned post');check(count(array_filter($calls,static fn($x)=>str_contains($x[1],'untrusted')))===0,'Provider paging URLs never followed');
[$job,$snapshot,$c]=setup();$job['content_hash']=str_repeat('0',64);denied(fn()=>publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens),'Changed approval hash blocked');check($creates===0,'No request with invalid approval');
foreach(['external_id','selected_resource','scopes_json']as$field){[$job,$snapshot,$c]=setup();$c[$field]=$field==='scopes_json'?'[]':'17840000000009999';denied(fn()=>publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens),'Changed connection '.$field.' blocked');check($creates===0&&$publishes===0,'No mutation with changed grant');}
[$job,$snapshot,$c,$v]=setup();$mode='processing';$r=publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens);$saved=array_replace($saved,$r);$c['connected_by']=8;$before=count($calls);denied(fn()=>publishing_instagram_native_check($saved,$c,'transport',$tokens),'Changed grant owner blocks polling/publication');check(count($calls)===$before,'No provider call after grant owner changes');
[$job,$snapshot,$c,$v]=setup();$frozen['sha256']=str_repeat('b',64);denied(fn()=>publishing_instagram_native_deliver($job,$snapshot,$c,'transport',$tokens),'Changed owned media blocked');check($creates===0,'No changed-media container');
foreach(['instagram_settings_confirmed'=>false,'media_type'=>'image','media_url'=>'https://untrusted.invalid/asset.mp4','ai_generated'=>true,'format'=>'story']as$field=>$value){[$job,$snapshot,$c,$v]=setup();$v[$field]=$value;denied(fn()=>publishing_instagram_native_validate_variant($v),'Unsupported/missing setting '.$field.' blocked');}
[$job,$snapshot,$c,$v]=setup();$v['platform_options']=['user_tags'=>'fixture,0.5,0.5'];denied(fn()=>publishing_instagram_native_validate_variant($v),'Instagram Login tags must remain assisted');social_options_validate('instagram',$v,['method'=>'manual']);check(true,'Assisted posting retains unsupported requirements');
foreach([['{"error":{"code":190,"message":"sensitive body"}}',400,401],['{"error":{"code":10}}',400,403],['{"error":{"code":4}}',400,429]]as[$raw,$status,$expected]){try{publishing_instagram_native_response($status,$raw,true,true);check(false,'Provider denial must throw');}catch(PublishingRemoteException $e){check($e->httpStatus===$expected&&!str_contains($e->getMessage(),'sensitive'),'Safe provider error classification');}}
denied(fn()=>publishing_instagram_native_response(200,'not json',true,true),'Unreadable mutation result remains ambiguous',true);denied(fn()=>publishing_instagram_native_response(200,'',false,true),'Incomplete mutation response remains ambiguous',true);denied(fn()=>publishing_instagram_native_http('GET','https://untrusted.invalid',[],'unused'),'No arbitrary provider endpoint');
check(publishing_instagram_native_response(200,'{"id":"18000000000000002"}',true,false)['id']==='18000000000000002','JSON provider result accepted');
// Older JPEG containers remain readable and cannot be republished after PUBLISHED.
function publishing_access_token(array $c):string{return 'fixture-token';}
function publishing_bearer(string $s):array{return ['Authorization: Bearer '.$s];}
function publishing_meta_version():string{return 'v24.0';}
function publishing_http(string $method,string $url,array $headers):array{check($method==='GET','Legacy recovery never repeats publication');return ['data'=>['status_code'=>'PUBLISHED']];}
[$job,$snapshot,$c]=setup('post');$legacy=$job;$legacy['provider_state_json']=publishing_json(['container_id'=>'18000000000000001','phase'=>'container_ready']);
$r=publishing_instagram_check($legacy,$c);check($r['status']==='ambiguous','Already-published legacy container pauses for review');
$legacy['provider_state_json']=publishing_json(['container_id'=>'18000000000000001','phase'=>'container_ready','checks'=>5]);$r=publishing_instagram_check($legacy,$c);check($r['status']==='failed','Legacy pending polling remains bounded');
echo 'PASS: '.$checks." Instagram image/Reel approval, owned asset, saved-container, no-replay, bounded processing, receipt, safe-error and scope checks. No provider called.\n";
