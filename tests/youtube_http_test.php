<?php
declare(strict_types=1);

/** Actual local HTTP against the production adapter; all accounts/media are synthetic.
 * The fixture binds numeric loopback only, is authenticated with a random test token,
 * and is always terminated. No database or provider credentials are read.
 */
if(PHP_SAPI!=='cli')exit(2);
$ytDirectory=sys_get_temp_dir().'/waithaka-youtube-http-'.bin2hex(random_bytes(8));
if(!mkdir($ytDirectory,0700))throw new RuntimeException('Cannot create isolated fixture directory.');
$ytFixtureFile=$ytDirectory.'/state.json';$ytSource=$ytDirectory.'/source.mp4';
$ytToken='youtube-fixture-'.bin2hex(random_bytes(16));
$ytSaved=[];$ytLeaseCount=0;$ytReleaseCount=0;$ytProcess=null;$ytPipes=[];$ytChecks=0;
function app_config(?string $key=null,mixed $default=null):mixed{return ['app_env'=>'testing'][$key]??$default;}
function publishing_job_update(int $id,array $fields):void{global $ytSaved;$ytSaved[$id]=array_replace($ytSaved[$id]??[],$fields);}
function publishing_access_token(array $connection):string{global $ytToken;return $ytToken;}
function encrypt_secret(string $value):string{
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,hash('sha256','local-youtube-fixture-key',true)));
}
function decrypt_secret(string $value):string{
    $raw=base64_decode($value,true);if($raw===false)throw new RuntimeException('Invalid fixture ciphertext.');
    $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),hash('sha256','local-youtube-fixture-key',true));
    if($plain===false)throw new RuntimeException('Invalid fixture ciphertext.');return $plain;
}
function media_library_materialize(array $asset,int $maxBytes):array{
    global $ytDirectory,$ytSource,$ytLeaseCount;
    if($asset['bytes']>$maxBytes)throw new RuntimeException('Fixture asset exceeds requested bound.');
    $path=tempnam($ytDirectory,'lease-');copy($ytSource,$path);chmod($path,0400);$ytLeaseCount++;
    return ['path'=>$path,'bytes'=>filesize($path),'mime'=>(new finfo(FILEINFO_MIME_TYPE))->file($path),'sha256'=>hash_file('sha256',$path),'temporary'=>true];
}
function media_library_release(array $asset):void{
    global $ytDirectory,$ytReleaseCount;
    if(!empty($asset['temporary'])&&str_starts_with($asset['path'],$ytDirectory.'/lease-')){unlink($asset['path']);$ytReleaseCount++;}
}
require dirname(__DIR__).'/app/providers/http.php';
require dirname(__DIR__).'/app/providers/youtube.php';
function yh_check(bool $condition,string $label):void{global $ytChecks;if(!$condition)throw new RuntimeException('FAIL: '.$label);$ytChecks++;}
function yh_state():array{global $ytFixtureFile;return json_decode((string)file_get_contents($ytFixtureFile),true,512,JSON_THROW_ON_ERROR);}
function yh_store(array $job,array $fields):array{global $ytSaved;publishing_job_update($job['id'],$fields);return $ytSaved[$job['id']];}
function yh_case(string $mode,int $size=1048576):array{
    global $ytFixtureFile,$ytDirectory,$ytSource,$ytSaved;
    static $id=0;$id++;
    // MP4 container signature plus deterministic synthetic bytes; encoding is a media-library concern.
    $file=fopen($ytSource,'wb');fwrite($file,hex2bin('000000186674797069736f6d0000020069736f6d69736f32'));
    $left=$size-24;$block=str_repeat('fixture-video-bytes-',4096);
    while($left>0){$chunk=substr($block,0,min($left,strlen($block)));fwrite($file,$chunk);$left-=strlen($chunk);}fclose($file);
    $bodyPath=$ytDirectory.'/received.bin';file_put_contents($bodyPath,'');
    $channel='UC'.str_repeat('A',22);
    file_put_contents($ytFixtureFile,json_encode(['mode'=>$mode,'channel_id'=>$channel,'initializations'=>0,'accepted'=>0,'complete'=>false,
        'status_queries'=>0,'chunk_requests'=>0,'video_reads'=>0,'ranges'=>[],'requests'=>[],'body_path'=>$bodyPath],JSON_THROW_ON_ERROR));
    $connection=['id'=>1,'service'=>'google_youtube_upload','status'=>'connected','external_id'=>'synthetic-google-user',
        'selected_resource'=>$channel,'scopes_json'=>publishing_json(['https://www.googleapis.com/auth/youtube.upload','https://www.googleapis.com/auth/youtube.readonly'])];
    $destination=['slug'=>'youtube','method'=>'direct','service'=>'google_youtube_upload','resource'=>$channel,'visibility'=>'private'];
    $asset=['id'=>1,'driver'=>'local','bucket'=>'','key'=>'fixture/video.mp4','version_id'=>null,'sha256'=>hash_file('sha256',$ytSource),
        'bytes'=>filesize($ytSource),'mime'=>'video/mp4','extension'=>'mp4','visibility'=>'private','public_url'=>'','title'=>'Synthetic transport fixture'];
    $variant=['enabled'=>true,'title'=>'Synthetic approved video','text'=>'Approved description','media_type'=>'video','media_url'=>'',
        'format'=>'video','privacy'=>'private','made_for_kids'=>false,'ai_generated'=>true,'notify_subscribers'=>false,'media_asset_id'=>1,'media_asset'=>$asset];
    $snapshot=['variants'=>['youtube'=>$variant],'destinations'=>['youtube'=>$destination]];
    $json=publishing_json($snapshot);
    $job=['id'=>$id,'method'=>'direct','status'=>'processing','connection_external_id'=>$connection['external_id'],
        'destination_json'=>publishing_json($destination),'provider_state_json'=>null,'remote_id'=>null,'snapshot_json'=>$json,'content_hash'=>hash('sha256',$json)];
    $ytSaved[$id]=$job;
    return [$job,$snapshot,$connection];
}
function yh_deliver(array $job,array $snapshot,array $connection):array{return yh_store($job,publishing_youtube_deliver($job,$snapshot,$connection));}
function yh_poll(array $job,array $connection):array{return yh_store($job,publishing_youtube_check($job,$connection));}

try {
    $reservation=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if(!$reservation)throw new RuntimeException('Could not reserve a loopback fixture port.');
    $address=stream_socket_get_name($reservation,false);fclose($reservation);
    $port=(int)substr($address,strrpos($address,':')+1);
    $log=$ytDirectory.'/server.log';
    $ytProcess=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/youtube_fixture_router.php'],
        [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$ytPipes,__DIR__,
        ['YOUTUBE_FIXTURE_STATE_FILE'=>$ytFixtureFile,'YOUTUBE_FIXTURE_TOKEN'=>$ytToken]);
    if(!is_resource($ytProcess))throw new RuntimeException('Could not start loopback fixture.');
    fclose($ytPipes[0]);
    putenv('YOUTUBE_TEST_BASE_URL=http://127.0.0.1:'.$port);
    $ready=false;
    for($attempt=0;$attempt<50;$attempt++){
        $curl=curl_init('http://127.0.0.1:'.$port.'/_health');curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$ytToken],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>100,CURLOPT_TIMEOUT_MS=>300]);
        $response=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($code===200&&$response==='{"fixture":true}'){$ready=true;break;}usleep(20000);
    }
    yh_check($ready,'Authenticated numeric-loopback fixture starts');

    [$job,$snapshot,$connection]=yh_case('standard');
    $job=yh_deliver($job,$snapshot,$connection);$state=yh_state();
    yh_check($job['status']==='delivered'&&$job['remote_id']==='aBcDeFgHi12','Upload acknowledgement is saved but processing is not claimed complete');
    yh_check($state['initializations']===1&&$state['received_sha256']===$snapshot['variants']['youtube']['media_asset']['sha256'],'The exact approved bytes reach one initialized upload');
    yh_check($state['metadata']['status']===['privacyStatus'=>'private','selfDeclaredMadeForKids'=>false,'containsSyntheticMedia'=>true],'HTTP initialization contains exact approved visibility, audience and disclosure');
    yh_check($state['notify_subscribers']==='false','Subscriber-notification choice is explicit on the wire');
    yh_check(!str_contains($job['provider_state_json'],'upload_id=fixture'),'Resumable session capability is encrypted in stored job state');
    $job=yh_poll($job,$connection);
    yh_check($job['status']==='published'&&yh_state()['video_reads']===1,'Only confirmed processing and exact settings complete the job');

    [$job,$snapshot,$connection]=yh_case('lost_final');
    $job=yh_deliver($job,$snapshot,$connection);
    yh_check($job['status']==='delivered'&&empty($job['remote_id'])&&yh_state()['complete'],'A lost final response leaves a resumable session instead of a duplicate upload');
    $job=yh_poll($job,$connection);$state=yh_state();
    yh_check($state['status_queries']===1&&$state['initializations']===1&&$state['chunk_requests']===1,'Recovery queries the existing session without initializing or sending the video again');
    yh_check($job['remote_id']==='aBcDeFgHi12'&&$job['status']==='delivered','Lost acknowledgement is recovered as the existing video ID');
    $job=yh_poll($job,$connection);yh_check($job['status']==='published','Recovered video completes through owner status read');

    [$job,$snapshot,$connection]=yh_case('partial',9437184);
    $job=yh_deliver($job,$snapshot,$connection);
    yh_check(yh_state()['accepted']===262144&&$job['status']==='delivered','Partial accepted bytes survive a server error');
    $job=yh_poll($job,$connection);$state=yh_state();
    yh_check($state['status_queries']===1&&str_starts_with($state['ranges'][1],'bytes 262144-'),'Resume starts at the server-confirmed byte after a partial upload');
    yh_check(empty($state['invalid_replay'])&&$state['received_sha256']===$snapshot['variants']['youtube']['media_asset']['sha256'],'Resume neither overlaps nor skips bytes');
    yh_check($state['initializations']===1,'Partial recovery never initializes a replacement video');

    [$job,$snapshot,$connection]=yh_case('lost_final');
    publishing_youtube_deliver($job,$snapshot,$connection); // Deliberately lose the caller's final DB update.
    $job=$ytSaved[$job['id']];
    yh_check($job['status']==='delivered'&&publishing_youtube_state($job)['pending'],'Durable pre-PUT checkpoint survives loss of the worker result');
    $job=yh_poll($job,$connection);
    yh_check($job['remote_id']==='aBcDeFgHi12'&&yh_state()['initializations']===1,'Worker-result loss recovers the same final video');

    foreach(['privacy_mismatch','audience_mismatch','disclosure_mismatch','audience_missing','disclosure_missing'] as $mode){
        [$job,$snapshot,$connection]=yh_case($mode);$job=yh_deliver($job,$snapshot,$connection);$job=yh_poll($job,$connection);
        yh_check($job['status']==='failed'&&!empty($job['remote_id'])&&publishing_youtube_can_resume($job),$mode.' preserves existing video and requires a review');
        yh_check(yh_state()['initializations']===1,$mode.' does not trigger a second upload or silently change visibility');
    }
    [$job,$snapshot,$connection]=yh_case('processing_failed');$job=yh_deliver($job,$snapshot,$connection);$job=yh_poll($job,$connection);
    yh_check($job['status']==='failed'&&yh_state()['initializations']===1,'YouTube processing rejection is not reported as publication');
    [$job,$snapshot,$connection]=yh_case('processing_pending');$job=yh_deliver($job,$snapshot,$connection);$job=yh_poll($job,$connection);
    yh_check($job['status']==='delivered'&&$job['remote_id']==='aBcDeFgHi12','Pending processing stays distinct from a completed private upload');

    [$job,$snapshot,$connection]=yh_case('range_no_id');$job=yh_deliver($job,$snapshot,$connection);
    for($attempt=0;$attempt<9&&$job['status']==='delivered';$attempt++)$job=yh_poll($job,$connection);
    yh_check($job['status']==='ambiguous'&&publishing_youtube_can_resume($job),'Repeated full byte acknowledgement without a video ID eventually pauses for review');
    yh_check(yh_state()['initializations']===1&&yh_state()['chunk_requests']===1,'Missing final ID cannot cause duplicate initialization or binary replay');
    [$job,$snapshot,$connection]=yh_case('wrong_video_channel');$job=yh_deliver($job,$snapshot,$connection);
    yh_check($job['status']==='ambiguous'&&$job['remote_id']==='aBcDeFgHi12','An unexpected returned channel retains the acknowledged video ID for review');
    $job=yh_poll($job,$connection);
    yh_check($job['status']==='ambiguous'&&yh_state()['initializations']===1,'A channel mismatch cannot turn into a second upload during review');
    [$job,$snapshot,$connection]=yh_case('premature_video',9437184);$job=yh_deliver($job,$snapshot,$connection);
    yh_check($job['status']==='ambiguous'&&$job['remote_id']==='aBcDeFgHi12'&&!publishing_youtube_can_resume($job),'A video returned before the complete file was attempted is retained without automatic byte replay');
    [$job,$snapshot,$connection]=yh_case('malformed_range',9437184);$job=yh_deliver($job,$snapshot,$connection);
    try{yh_poll($job,$connection);yh_check(false,'Malformed acknowledgement must reject');}catch(PublishingRemoteException $e){yh_check($e->ambiguous&&yh_state()['chunk_requests']===1,'An invalid server acknowledgement never causes more bytes to be sent');}

    [$job,$snapshot,$connection]=yh_case('expired_final');$job=yh_deliver($job,$snapshot,$connection);$job=yh_poll($job,$connection);
    yh_check($job['status']==='ambiguous'&&!publishing_youtube_can_resume($job)&&yh_state()['initializations']===1,'Expired session after possible final acceptance cannot start another upload');
    [$job,$snapshot,$connection]=yh_case('expired_partial',9437184);$job=yh_deliver($job,$snapshot,$connection);$job=yh_poll($job,$connection);
    yh_check($job['status']==='failed'&&!publishing_youtube_can_resume($job),'Expired incomplete upload needs a new reviewed revision instead of an automatic reset');

    [$job,$snapshot,$connection]=yh_case('wrong_channel');
    try{yh_deliver($job,$snapshot,$connection);yh_check(false,'Wrong channel must reject');}catch(PublishingRemoteException $e){yh_check($e->httpStatus===422&&!$e->ambiguous&&yh_state()['initializations']===0,'Current OAuth channel is verified before upload initialization');}
    [$job,$snapshot,$connection]=yh_case('unsafe_location');
    try{yh_deliver($job,$snapshot,$connection);yh_check(false,'Unsafe location must reject');}catch(PublishingRemoteException $e){yh_check($e->ambiguous&&yh_state()['chunk_requests']===0,'Unsafe Location never receives a bearer token or video bytes');}
    [$job,$snapshot,$connection]=yh_case('init_lost');
    try{yh_deliver($job,$snapshot,$connection);yh_check(false,'Lost initialization must pause');}catch(PublishingRemoteException $e){$job=$ytSaved[$job['id']];yh_check($e->ambiguous&&!publishing_youtube_can_resume($job)&&!publishing_youtube_can_restart_rejected_init($job),'An unknown initialization outcome cannot be restarted as a definite rejection');}
    try{yh_deliver($job,$snapshot,$connection);yh_check(false,'Unknown initialization cannot be repeated');}catch(PublishingRemoteException $e){yh_check(yh_state()['initializations']===1&&yh_state()['chunk_requests']===0,'The same job never initializes again after a lost session response');}
    [$job,$snapshot,$connection]=yh_case('init_401');
    try{yh_deliver($job,$snapshot,$connection);yh_check(false,'Revoked token must reject');}catch(PublishingRemoteException $e){yh_check($e->httpStatus===401&&publishing_youtube_can_restart_rejected_init($ytSaved[$job['id']]),'Definite initialization rejection is reconnectable without an unknown media side effect');}
    [$job,$snapshot,$connection]=yh_case('chunk_401');
    try{yh_deliver($job,$snapshot,$connection);yh_check(false,'Chunk revocation must reject');}catch(PublishingRemoteException $e){$job=$ytSaved[$job['id']];yh_check($e->httpStatus===401&&publishing_youtube_can_resume($job),'Revocation after session creation preserves same-session recovery');}
    $job=yh_poll($job,$connection);yh_check($job['remote_id']==='aBcDeFgHi12'&&yh_state()['initializations']===1,'Reconnected upload resumes the same session');

    [$job,$snapshot,$connection]=yh_case('standard');
    file_put_contents($ytSource,'tampered',FILE_APPEND);
    try{yh_deliver($job,$snapshot,$connection);yh_check(false,'Changed media must reject');}catch(PublishingRemoteException $e){yh_check($e->httpStatus===422&&yh_state()['initializations']===0,'Changed approved asset is rejected before any provider side effect');}
    yh_check($ytLeaseCount===$ytReleaseCount&&glob($ytDirectory.'/lease-*')===[],'Every materialized video lease is released, including failure paths');
    echo "YouTube HTTP tests passed: {$ytChecks}\n";
} finally {
    putenv('YOUTUBE_TEST_BASE_URL');
    if(is_resource($ytProcess)){proc_terminate($ytProcess);proc_close($ytProcess);}
    foreach(glob($ytDirectory.'/*')?:[] as $file)if(is_file($file))unlink($file);
    rmdir($ytDirectory);
}
