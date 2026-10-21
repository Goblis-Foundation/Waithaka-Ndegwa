<?php
declare(strict_types=1);

// Synthetic API only. This server never contacts TikTok or publishes anything.
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
$file=(string)getenv('TIKTOK_FIXTURE_STATE_FILE');
if($file===''||!is_file($file)){http_response_code(503);exit;}
$state=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
header('Content-Type: application/json');header('Cache-Control: no-store');
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$method=$_SERVER['REQUEST_METHOD'];
$save=static function()use(&$state,$file):void{file_put_contents($file,json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX);};
$reply=static function(int $status,array $body)use($save):never{$save();http_response_code($status);echo json_encode($body,JSON_THROW_ON_ERROR);exit;};
if($path==='/_health') {
    if(!hash_equals($state['client_secret'],(string)($_SERVER['HTTP_X_FIXTURE_SECRET']??'')))$reply(403,[]);
    echo '{"fixture":true}';exit;
}
$state['requests'][]=['path'=>$path,'method'=>$method,'fields'=>$_GET['fields']??null];
if($path==='/v2/oauth/token/'&&$method==='POST') {
    if(!str_starts_with((string)($_SERVER['CONTENT_TYPE']??''),'application/x-www-form-urlencoded'))$reply(400,['error'=>'invalid_request']);
    if(($_POST['client_key']??'')!==$state['client_key']||!hash_equals($state['client_secret'],(string)($_POST['client_secret']??''))||isset($_POST['client_id']))$reply(401,['error'=>'invalid_client']);
    $grant=$_POST['grant_type']??'';
    if($grant==='authorization_code') {
        if(($_POST['code']??'')!=='synthetic-authorization-code'||($_POST['redirect_uri']??'')!=='https://www.waithakandegwa.com/write/connections/callback/tiktok_read')$reply(400,['error'=>'invalid_grant']);
        $state['authorizations']++;
    } elseif($grant==='refresh_token') {
        if(($_POST['refresh_token']??'')!==$state['refresh_token']){$state['invalid_refresh_replays']++;$reply(400,['error'=>'invalid_grant']);}
        $state['refreshes']++;
        $state['refresh_token']='synthetic-rotated-refresh-'.$state['refreshes'];
        $state['access_token']='synthetic-rotated-access-'.$state['refreshes'];
        if($state['mode']==='delayed_refresh'){ $save();usleep(500000); }
        if($state['mode']==='reconnect_during_refresh') {
            if(getenv('APP_ENV')!=='testing'||!preg_match('/\Awaithaka_test_[a-z0-9_]+_test\z/',(string)getenv('DB_DATABASE'))||getenv('DB_HOST')!=='127.0.0.1')$reply(500,[]);
            $pdo=new PDO('mysql:host=127.0.0.1;port='.(int)getenv('DB_PORT').';dbname='.getenv('DB_DATABASE').';charset=utf8mb4',(string)getenv('DB_USERNAME'),(string)getenv('DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
            $pdo->prepare("UPDATE publishing_connections SET credentials_encrypted=?,status='connected' WHERE id=? AND service='tiktok_read'")->execute([$state['replacement_encrypted'],$state['connection_id']]);
        }
    } else $reply(400,['error'=>'unsupported_grant_type']);
    if($state['mode']==='lost_refresh'&&$grant==='refresh_token'){$save();header('Content-Length: 2048');echo '{';exit;}
    $token=['open_id'=>$state['mode']==='refresh_identity_changed'&&$grant==='refresh_token'?'different-owner':$state['open_id'],
        'access_token'=>$state['access_token'],'refresh_token'=>$state['refresh_token'],'scope'=>implode(',',$state['scopes']),
        'token_type'=>'Bearer','expires_in'=>86400,'refresh_expires_in'=>31536000];
    if($state['mode']==='declined_scope')$token['scope']='user.info.basic,video.list';
    $reply(200,$token);
}
if(!hash_equals('Bearer '.$state['access_token'],(string)($_SERVER['HTTP_AUTHORIZATION']??'')))$reply(401,['error'=>['code'=>'access_token_invalid']]);
if($path==='/v2/user/info/'&&$method==='GET') {
    $state['user_reads']++;
    $user=['open_id'=>$state['mode']==='wrong_user'?'different-owner':$state['open_id'],'display_name'=>'Waithaka Ndegwa','username'=>'waithakandegwa',
        'is_verified'=>true,'follower_count'=>120,'following_count'=>7,'likes_count'=>300];
    $reply(200,['data'=>['user'=>$user],'error'=>['code'=>'ok']]);
}
if($path==='/v2/video/list/'&&$method==='POST') {
    $state['video_reads']++;
    $body=json_decode((string)file_get_contents('php://input'),true);
    if(($body['max_count']??null)!==20||count($body)!==1)$reply(400,['error'=>['code'=>'invalid_params']]);
    if($state['mode']==='report_scope_error')$reply(200,['data'=>[],'error'=>['code'=>'scope_not_authorized','message'=>'Never display private provider error text.']]);
    $reply(200,['data'=>['videos'=>[['id'=>'7000000000000000001','title'=>'Week one','video_description'=>'Testing the problem',
        'create_time'=>1792512000,'share_url'=>'https://www.tiktok.com/@waithakandegwa/video/7000000000000000001?utm_source=fixture',
        'view_count'=>200,'like_count'=>10,'share_count'=>0]],'has_more'=>true,'cursor'=>1792512000000],'error'=>['code'=>'ok']]);
}
$reply(404,['error'=>['code'=>'unsupported_fixture_endpoint']]);
