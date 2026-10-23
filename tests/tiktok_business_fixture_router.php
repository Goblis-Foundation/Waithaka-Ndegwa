<?php
declare(strict_types=1);

// Local synthetic Accounts API. No TikTok account, remote download or real publication.
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$file=(string)getenv('TIKTOK_BUSINESS_FIXTURE_STATE_FILE');
if($file===''||!is_file($file)){http_response_code(503);exit;}
$s=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
header('Content-Type: application/json');header('Cache-Control: no-store');
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
$save=static function()use(&$s,$file):void{file_put_contents($file,json_encode($s,JSON_THROW_ON_ERROR),LOCK_EX);};
$reply=static function(array $data=[],int $code=0,int $http=200)use($save):never{$save();http_response_code($http);echo json_encode(['code'=>$code,'message'=>$code===0?'OK':'Synthetic provider failure','request_id'=>'synthetic-request','data'=>$data],JSON_THROW_ON_ERROR);exit;};
if($path==='/_health') {
    if(!hash_equals($s['client_secret'],(string)($_SERVER['HTTP_X_FIXTURE_SECRET']??''))){http_response_code(403);exit;}
    echo '{"fixture":true}';exit;
}
// Never record a full query: TikTok's property-list contract carries the app secret there.
$s['requests'][]=['path'=>$path,'method'=>$method,'query_keys'=>array_keys($_GET)];
$prefix='/open_api/v1.3/';
$tokenPath=$prefix.'tt_user/oauth2/token/';$refreshPath=$prefix.'tt_user/oauth2/refresh_token/';
if(in_array($path,[$tokenPath,$refreshPath],true)&&$method==='POST') {
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(!str_starts_with((string)($_SERVER['CONTENT_TYPE']??''),'application/json')||!is_array($body)
        ||($body['client_id']??'')!==$s['client_id']||!hash_equals($s['client_secret'],(string)($body['client_secret']??'')))$reply([],40000,400);
    if($path===$tokenPath) {
        if(($body['grant_type']??'')!=='authorization_code'||($body['auth_code']??'')!=='synthetic-business-code'
            ||($body['redirect_uri']??'')!=='https://www.waithakandegwa.com/write/connections/callback/tiktok_business/')$reply([],40000,400);
        $s['authorizations']++;
    } else {
        if(($body['grant_type']??'')!=='refresh_token'||($body['refresh_token']??'')!==$s['refresh_token']){$s['invalid_refresh_replays']++;$reply([],40000,400);}
        $s['refreshes']++;
        $s['access_token']='synthetic-business-access-'.$s['refreshes'];$s['refresh_token']='synthetic-business-refresh-'.$s['refreshes'];
        if($s['mode']==='lost_refresh'){$save();header('Content-Length: 2048');echo '{';exit;}
    }
    $scopes=$s['scopes'];if($s['mode']==='token_missing_scope')$scopes=array_values(array_diff($scopes,['video.publish']));
    $reply(['access_token'=>$s['access_token'],'refresh_token'=>$s['refresh_token'],'open_id'=>$s['open_id'],
        'scope'=>implode(',',$scopes),'token_type'=>'Bearer','expires_in'=>86400,'refresh_token_expires_in'=>31536000]);
}
if($path===$prefix.'tt_user/token_info/get/'&&$method==='POST') {
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(($body['app_id']??'')!==$s['client_id']||($body['access_token']??'')!==$s['access_token'])$reply([],40000,400);
    $s['inspections']++;
    if($s['mode']==='inspected_expired')$reply([],40102);
    $scopes=$s['mode']==='inspected_missing_scope'?array_values(array_diff($s['scopes'],['video.publish'])):$s['scopes'];
    $reply(['app_id'=>$s['mode']==='wrong_app'?'different-business-app':$s['client_id'],
        'creator_id'=>$s['mode']==='wrong_creator'?'another-creator':$s['open_id'],'scope'=>implode(',',$scopes)]);
}
if($path===$prefix.'business/property/list/'&&$method==='GET') {
    if(($_GET['app_id']??'')!==$s['client_id']||!hash_equals($s['client_secret'],(string)($_GET['secret']??'')))$reply([],40000,400);
    $s['property_reads']++;
    $reply(['url_property_info_list'=>[['property_type'=>2,'url'=>'https://www.waithakandegwa.com/assets/uploads/',
        'property_status'=>$s['mode']==='property_unverified'?0:1,'signature'=>'synthetic-domain-proof','file_name'=>'synthetic-verification.txt']]]);
}
if(!hash_equals($s['access_token'],(string)($_SERVER['HTTP_ACCESS_TOKEN']??''))||isset($_SERVER['HTTP_AUTHORIZATION']))$reply([],40105,401);
if(($_GET['business_id']??null)!==$s['open_id']&&$method==='GET')$reply([],40000,400);
if($path===$prefix.'business/get/'&&$method==='GET') {
    $s['profile_reads']++;
    $reply(['display_name'=>'Waithaka Ndegwa','username'=>'waithakandegwa','is_business_account'=>false,'has_advanced_access'=>true,
        'profile_deep_link'=>'https://www.tiktok.com/@waithakandegwa','is_verified'=>false]);
}
if($path===$prefix.'business/video/settings/'&&$method==='GET') {
    $s['settings_reads']++;
    $reply(['privacy_level_options'=>$s['mode']==='account_private'?['SELF_ONLY']:['PUBLIC_TO_EVERYONE','SELF_ONLY'],
        'comment_disabled'=>$s['mode']==='comments_restricted','duet_disabled'=>false,'stitch_disabled'=>false,'max_video_post_duration_sec'=>600]);
}
if($path===$prefix.'business/video/publish/'&&$method==='POST') {
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(!is_array($body)||($body['business_id']??'')!==$s['open_id']||($body['video_url']??'')!==$s['expected_media_url'])$reply([],40000,400);
    $info=$body['post_info']??[];
    foreach(['disable_comment','disable_duet','disable_stitch','is_brand_organic','is_branded_content','is_ai_generated','upload_to_draft','is_ads_only'] as $field)if(!is_bool($info[$field]??null))$reply([],40000,400);
    if($info['upload_to_draft']!==false||$info['is_ads_only']!==false)$reply([],40000,400);
    $s['publishes']++;$s['published_body']=$body;
    if($s['mode']==='publish_http500')$reply([],50000,500);
    if($s['mode']==='publish_code_error')$reply([],50000,200);
    if($s['mode']==='publish_lost'){$save();header('Content-Length: 2048');echo '{';exit;}
    if($s['mode']==='publish_missing_id')$reply([]);
    $reply(['share_id'=>$s['publish_id']]);
}
if($path===$prefix.'business/publish/status/'&&$method==='GET') {
    if(($_GET['publish_id']??'')!==$s['publish_id'])$reply([],40000,400);
    $s['status_reads']++;
    if($s['mode']==='status_401')$reply([],40102,401);
    if($s['mode']==='status_failed')$reply(['status'=>'FAILED','reason'=>'frame_rate_check_failed']);
    if($s['mode']==='status_inbox')$reply(['status'=>'SEND_TO_USER_INBOX']);
    if($s['mode']==='status_unknown')$reply(['status'=>'UNRECOGNIZED_NEW_STATE']);
    if($s['mode']==='status_invalid_id')$reply(['status'=>'PUBLISH_COMPLETE','post_ids'=>['not-a-video-id']]);
    if($s['mode']==='status_processing')$reply(['status'=>'PROCESSING_DOWNLOAD']);
    if($s['mode']==='status_no_id')$reply(['status'=>'PUBLISH_COMPLETE']);
    if($s['mode']==='status_complete')$reply(['status'=>'PUBLISH_COMPLETE','post_ids'=>['7000000000000000001']]);
    $reply($s['status_reads']===1?['status'=>'PROCESSING_DOWNLOAD']:($s['status_reads']===2?['status'=>'PUBLISH_COMPLETE']:['status'=>'PUBLISH_COMPLETE','post_ids'=>['7000000000000000001']]));
}
$reply([],40400,404);
