<?php
declare(strict_types=1);

// Synthetic loopback-only Google-protocol fixture. Never load the application.
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }
$fixtureFile=(string)getenv('YOUTUBE_FIXTURE_STATE_FILE');
$fixtureToken=(string)getenv('YOUTUBE_FIXTURE_TOKEN');
if ($fixtureToken==='' || !hash_equals('Bearer '.$fixtureToken,(string)($_SERVER['HTTP_AUTHORIZATION']??''))) { http_response_code(403); exit; }
header('Cache-Control: no-store');
header('Content-Type: application/json');
$path=(string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/_health'){echo '{"fixture":true}';exit;}
$state=json_decode((string)file_get_contents($fixtureFile),true,512,JSON_THROW_ON_ERROR);
$method=$_SERVER['REQUEST_METHOD'];
$mode=$state['mode'];
$state['requests'][]=['method'=>$method,'path'=>$path,'range'=>$_SERVER['HTTP_CONTENT_RANGE']??null];
$save=static function()use(&$state,$fixtureFile):void{file_put_contents($fixtureFile,json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),LOCK_EX);};
$reply=static function(int $code,array $body=[])use($save):never{$save();http_response_code($code);echo json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);exit;};
$video=static function(bool $processed=false)use(&$state,$mode):array{
    $status=$state['metadata']['status'];
    $status['uploadStatus']=$processed?'processed':'uploaded';
    if($processed&&$mode==='privacy_mismatch')$status['privacyStatus']='public';
    if($processed&&$mode==='audience_mismatch')$status['selfDeclaredMadeForKids']=!$status['selfDeclaredMadeForKids'];
    if($processed&&$mode==='disclosure_mismatch')$status['containsSyntheticMedia']=!$status['containsSyntheticMedia'];
    if($processed&&$mode==='audience_missing')unset($status['selfDeclaredMadeForKids']);
    if($processed&&$mode==='disclosure_missing')unset($status['containsSyntheticMedia']);
    if($processed&&$mode==='processing_failed')$status['uploadStatus']='rejected';
    return ['id'=>'aBcDeFgHi12','snippet'=>$state['metadata']['snippet']+['channelId'=>$mode==='wrong_video_channel'?'UC'.str_repeat('B',22):$state['channel_id']],
        'status'=>$status,'processingDetails'=>['processingStatus'=>$mode==='processing_failed'?'failed':($processed?'succeeded':'processing')]];
};
if($path==='/youtube/v3/channels'&&$method==='GET') {
    $reply(200,['items'=>[['id'=>$mode==='wrong_channel'?'UC'.str_repeat('B',22):$state['channel_id']]]]);
}
if($path==='/youtube/v3/videos'&&$method==='GET') {
    $state['video_reads']++;
    $reply(200,['items'=>[$video($mode!=='processing_pending')]]);
}
if($path!=='/upload/youtube/v3/videos')$reply(404,['error'=>'Unexpected fixture route']);
if($method==='POST') {
    $state['initializations']++;
    if($mode==='init_401')$reply(401,['error'=>['code'=>401]]);
    $body=(string)file_get_contents('php://input');
    $state['metadata']=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    $state['notify_subscribers']=$_GET['notifySubscribers']??null;
    $state['total']=(int)($_SERVER['HTTP_X_UPLOAD_CONTENT_LENGTH']??0);
    $state['mime']=$_SERVER['HTTP_X_UPLOAD_CONTENT_TYPE']??null;
    if($state['total']<1||$state['total']>536870912)$reply(400,['error'=>'Unexpected size']);
    $location=$mode==='unsafe_location'
        ? 'http://169.254.169.254/upload/youtube/v3/videos?uploadType=resumable&upload_id=fixture'
        : 'http://127.0.0.1:'.$_SERVER['SERVER_PORT'].'/upload/youtube/v3/videos?uploadType=resumable&upload_id=fixture';
    header('Location: '.$location);
    if($mode==='init_lost'){$save();http_response_code(200);header('Content-Length: 1024');echo '{';exit;}
    $reply(200);
}
if($method!=='PUT'||($_GET['upload_id']??'')!=='fixture')$reply(400,['error'=>'Unexpected fixture request']);
$range=(string)($_SERVER['HTTP_CONTENT_RANGE']??'');
if($range==='bytes */'.$state['total']) {
    $state['status_queries']++;
    if(in_array($mode,['expired_final','expired_partial'],true))$reply(404,['error'=>['code'=>404]]);
    if(!empty($state['complete'])&&$mode!=='range_no_id')$reply(201,$video());
    if($mode==='malformed_range')header('Range: bytes=200-999');
    elseif($state['accepted']>0)header('Range: bytes=0-'.($state['accepted']-1));
    $reply(308);
}
if(!preg_match('/\Abytes ([0-9]+)-([0-9]+)\/([0-9]+)\z/',$range,$match))$reply(400,['error'=>'Invalid content range']);
$offset=(int)$match[1];$end=(int)$match[2];$total=(int)$match[3];
$state['chunk_requests']++;
if($mode==='chunk_401'&&$state['chunk_requests']===1)$reply(401,['error'=>['code'=>401]]);
if($offset!==$state['accepted']||$total!==$state['total']||$end<$offset||$end>=$total){$state['invalid_replay']=true;$reply(400,['error'=>'Overlap or skipped bytes']);}
$body=(string)file_get_contents('php://input');
if(strlen($body)!==$end-$offset+1||strlen($body)>8388608)$reply(400,['error'=>'Unexpected chunk length']);
$state['ranges'][]=$range;
if(in_array($mode,['partial','expired_partial','malformed_range'],true)&&$state['chunk_requests']===1) {
    $body=substr($body,0,262144);
    file_put_contents($state['body_path'],$body,FILE_APPEND|LOCK_EX);$state['accepted']+=strlen($body);
    $reply(503,['error'=>['code'=>503]]);
}
file_put_contents($state['body_path'],$body,FILE_APPEND|LOCK_EX);$state['accepted']+=strlen($body);
if($mode==='premature_video')$reply(201,$video());
if($state['accepted']<$total) {
    header('Range: bytes=0-'.($state['accepted']-1));
    $reply(308);
}
$state['complete']=true;$state['received_sha256']=hash_file('sha256',$state['body_path']);
if($mode==='range_no_id'){header('Range: bytes=0-'.($state['accepted']-1));$reply(308);}
if(in_array($mode,['lost_final','expired_final'],true)) {
    // Commit the upload, then lose the final JSON acknowledgement on the wire.
    $save();http_response_code(201);header('Content-Length: 1024');echo '{';exit;
}
$reply(201,$video());
