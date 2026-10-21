<?php
declare(strict_types=1);

// Pure permission, identity, report and endpoint-boundary checks. No credentials/network.
$tiktokTestEnvironment='testing';
function app_config(?string $key=null,mixed $default=null):mixed {return $key==='app_env'?$GLOBALS['tiktokTestEnvironment']:$default;}
require dirname(__DIR__).'/app/publishing.php';
putenv('TIKTOK_TEST_BASE_URL');
$checks=0;
function tt_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function tt_reject(callable $fn,string $label,?int $status=null):void {
    try{$fn();}catch(RuntimeException $e){tt_check($status===null||($e instanceof PublishingRemoteException&&$e->httpStatus===$status),$label);return;}
    tt_check(false,$label);
}
$token=['access_token'=>'synthetic-access','refresh_token'=>'synthetic-refresh','open_id'=>'synthetic-owner',
    'scope'=>implode(',',publishing_tiktok_scopes()),'token_type'=>'Bearer','expires_in'=>86400,'refresh_expires_in'=>31536000];
$valid=publishing_tiktok_validate_token($token);
tt_check($valid['expires_at']>time()&&$valid['refresh_expires_at']>$valid['expires_at'],'Both token lifetimes are recorded from the actual response');
foreach(publishing_tiktok_scopes() as $scope){
    $bad=$token;$bad['scope']=implode(',',array_diff(publishing_tiktok_scopes(),[$scope]));
    tt_reject(fn()=>publishing_tiktok_validate_token($bad),'Declined '.$scope.' cannot become a connected read service',401);
}
foreach(['scope','open_id','refresh_token','expires_in','refresh_expires_in','token_type'] as $field){
    $bad=$token;unset($bad[$field]);tt_reject(fn()=>publishing_tiktok_validate_token($bad),'Missing '.$field.' cannot be silently assumed');
}
$bad=$token;$bad['refresh_token']="invalid\r\nheader";
tt_reject(fn()=>publishing_tiktok_validate_token($bad),'Control characters cannot enter stored provider credentials');
tt_reject(fn()=>publishing_tiktok_validate_token($token,'different-owner'),'A refreshed token cannot switch the account identity',401);
$bad=$token;$bad['error']='invalid_grant';
tt_reject(fn()=>publishing_tiktok_validate_token($bad),'HTTP-success OAuth error is not an authorization',401);
$profile=['open_id'=>'synthetic-owner','username'=>'waithakandegwa','display_name'=>'Waithaka Ndegwa','is_verified'=>true];
$account=publishing_tiktok_profile($profile,'synthetic-owner');
tt_check($account['profile_url']==='https://www.tiktok.com/@waithakandegwa'&&$account['is_verified']===true,'Observed username gives a safe canonical account link');
tt_check(!isset($account['account_type']),'TikTok identity verification badge does not assert a Business account type');
tt_reject(fn()=>publishing_tiktok_profile($profile,'another-owner'),'User-info identity must match token identity',401);
$bad=$profile;$bad['username']='../../admin';
tt_reject(fn()=>publishing_tiktok_profile($bad,'synthetic-owner'),'Invalid profile identifier cannot create an arbitrary link');
tt_check(publishing_tiktok_data(['error'=>['code'=>'ok'],'data'=>['value'=>1]])===['value'=>1],'Successful API envelope exposes only returned data');
tt_reject(fn()=>publishing_tiktok_data(['data'=>[]]),'Missing success envelope does not become an empty report');
tt_reject(fn()=>publishing_tiktok_data(['error'=>['code'=>'access_token_invalid','message'=>'secret'],'data'=>[]]),'HTTP-success expired token requires reconnection',401);
tt_reject(fn()=>publishing_tiktok_data(['error'=>['code'=>'scope_not_authorized'],'data'=>[]]),'HTTP-success missing scope is a permission failure',403);
tt_reject(fn()=>publishing_tiktok_data(['error'=>['code'=>'rate_limit_exceeded'],'data'=>[]]),'Provider rate limits retain their meaning',429);
foreach([null,-1,'20',2.5,false] as $counter)tt_check(publishing_tiktok_count($counter)===null,'Unavailable or malformed counters are not fabricated zeros');
tt_check(publishing_tiktok_count(0)===0&&publishing_tiktok_count(200)===200,'Real integer counters, including zero, are preserved');
$video=['id'=>'7000000000000000001','title'=>'Week one','create_time'=>1792512000,
    'share_url'=>'https://www.tiktok.com/@waithakandegwa/video/7000000000000000001?utm_source=app','view_count'=>120,'like_count'=>0];
$row=publishing_tiktok_video_row($video);
tt_check($row['view_count']===120&&$row['like_count']===0&&$row['comment_count']===null,'Report distinguishes zero from unavailable engagement counters');
tt_check($row['share_url']==='https://www.tiktok.com/@waithakandegwa/video/7000000000000000001','Safe platform video URL drops tracking query parameters');
foreach(['https://evil.example/@a/video/7000000000000000001','javascript:alert(1)','https://www.tiktok.com.evil.example/@a/video/7000000000000000001',
    'https://user@www.tiktok.com/@a/video/7000000000000000001','https://www.tiktok.com/@a/video/7000000000000000002'] as $url){
    $bad=$video;$bad['share_url']=$url;tt_check(publishing_tiktok_video_row($bad)['share_url']==='','Untrusted or mismatched share URL is not rendered as a link');
}
$service=publishing_services()['tiktok_read'];
tt_check(!in_array('video.publish',$service['scopes'],true)&&!in_array('video.upload',$service['scopes'],true),'Read connection requests no publication permission');
tt_check(!publishing_capability(['slug'=>'tiktok','account_type'=>'professional','delivery_method'=>'direct'],['service'=>'tiktok_read','status'=>'connected','selected_resource'=>'synthetic-owner'])['usable'],'A confirmed read token never enables a native TikTok publishing route');
putenv('TIKTOK_TEST_BASE_URL=http://127.0.0.1:19092');
tt_check(publishing_http_tiktok_fixture_url('https://open.tiktokapis.com/v2/user/info/?fields=open_id')==='http://127.0.0.1:19092/v2/user/info/?fields=open_id','Synthetic API requests can use a numeric loopback fixture');
$tiktokTestEnvironment='production';
tt_reject(fn()=>publishing_http_tiktok_fixture_url('https://open.tiktokapis.com/v2/user/info/'),'Test endpoint injection is forbidden in production');
$tiktokTestEnvironment='testing';
foreach(['https://provider.example.com','http://localhost:19092','http://127.0.0.1:19092/other','http://127.0.0.1:65536'] as $base){
    putenv('TIKTOK_TEST_BASE_URL='.$base);tt_reject(fn()=>publishing_http_tiktok_fixture_url('https://open.tiktokapis.com/v2/user/info/'),'Test override cannot target arbitrary destinations or paths');
}
putenv('TIKTOK_TEST_BASE_URL=http://127.0.0.1:19092');
tt_reject(fn()=>publishing_http_tiktok_fixture_url('https://open.tiktokapis.com/v2/post/publish/video/init/'),'Read fixture has no publishing endpoint');
putenv('TIKTOK_TEST_BASE_URL');
echo "TikTok policy checks passed: {$checks}\n";
