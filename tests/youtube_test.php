<?php
declare(strict_types=1);

// Offline policy and state tests. No database, credentials or network required.
$youtubeTestEnv = 'testing';
function app_config(?string $key=null, mixed $default=null): mixed {
    global $youtubeTestEnv;
    return ['app_env'=>$youtubeTestEnv,'timezone'=>'Africa/Nairobi','base_url'=>'https://www.waithakandegwa.com'][$key] ?? $default;
}
function url(string $path=''): string { return 'https://www.waithakandegwa.com/'.ltrim($path,'/'); }
require dirname(__DIR__).'/app/publishing.php';
putenv('YOUTUBE_TEST_BASE_URL');
$checks=0;
function yt_check(bool $condition,string $label):void { global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);$checks++; }
function yt_reject(callable $action,string $label,?bool $ambiguous=null):void {
    try { $action(); } catch(RuntimeException $e) {
        yt_check($ambiguous===null || ($e instanceof PublishingRemoteException && $e->ambiguous===$ambiguous),$label);
        return;
    }
    yt_check(false,$label);
}

$connection=['service'=>'google_youtube_upload','status'=>'connected','external_id'=>'google-user',
    'selected_resource'=>'UC'.str_repeat('A',22),'scopes_json'=>publishing_json([
        'https://www.googleapis.com/auth/youtube.upload','https://www.googleapis.com/auth/youtube.readonly',
    ])];
$channel=['slug'=>'youtube','account_type'=>'channel','delivery_method'=>'direct'];
yt_check(publishing_capability($channel,$connection)['usable'],'Separate confirmed upload grant enables the implemented native route');
$read=$connection;$read['service']='google_youtube_read';
yt_check(!publishing_capability($channel,$read)['usable'],'Read-only Google service cannot be used to upload');
$read=$connection;$read['scopes_json']=publishing_json(['https://www.googleapis.com/auth/youtube.upload']);
yt_check(!publishing_capability($channel,$read)['usable'],'Upload without owner read access cannot falsely claim processing verification');
$read=$connection;$read['status']='pending_confirmation';
yt_check(!publishing_capability($channel,$read)['usable'],'Observed identity requires destination confirmation');
$read=$connection;$read['selected_resource']='https://youtube.com/@example';
yt_check(!publishing_capability($channel,$read)['usable'],'Branding URL is not an observed channel ID');
yt_check(!publishing_capability(['slug'=>'tiktok','delivery_method'=>'direct'])['usable'],'Native TikTok posting requires a separate confirmed Accounts API grant');

$variant=publishing_default_draft()['variants']['youtube'];
$variant['title']='Week one: testing the problem';$variant['text']='A video from the first week.';
$variant['media_asset_id']=1;$variant['media_asset']=['id'=>1,'driver'=>'local','key'=>'owned/immutable.mp4','version_id'=>null,
    'sha256'=>str_repeat('a',64),'bytes'=>1024,'mime'=>'video/mp4','extension'=>'mp4','visibility'=>'private','public_url'=>'','title'=>'Week one'];
publishing_validate_variant('youtube',$variant,['method'=>'direct']);
yt_check(true,'A private owned video can be approved without a public media URL');
publishing_validate_variant('youtube',$variant,['method'=>'manual']);
yt_check(true,'Private owned media stays available for assisted publishing');
foreach(['x','linkedin','facebook','instagram','tiktok','youtube'] as $slug) {
    $v=$variant;$v['format']=$slug==='youtube'?'short':'post';
    yt_reject(fn()=>publishing_validate_variant($slug,$v,['method'=>'buffer']),'Private owned media cannot silently disappear from a Buffer '.$slug.' post');
}
foreach(['x','linkedin','facebook'] as $slug) {
    $v=$variant;$v['format']='post';
    yt_reject(fn()=>publishing_validate_variant($slug,$v,['method'=>'direct']),'Private owned media cannot silently become a text-only '.$slug.' post');
    $v['media_url']='https://media.example.com/owned.mp4';$v['media_asset']['visibility']='public';
    yt_reject(fn()=>publishing_validate_variant($slug,$v,['method'=>'direct']),'Public owned media cannot silently become a text-only '.$slug.' post');
}
yt_check(publishing_youtube_metadata($variant)['status']===['privacyStatus'=>'private','selfDeclaredMadeForKids'=>false,'containsSyntheticMedia'=>false],'False declarations and private visibility are explicitly sent');
foreach(['private','unlisted','public'] as $privacy) {
    $v=$variant;$v['privacy']=$privacy;
    yt_check(publishing_youtube_metadata($v)['status']['privacyStatus']===$privacy,'Approved '.$privacy.' visibility is preserved exactly');
}
$v=$variant;$v['made_for_kids']=true;$v['ai_generated']=true;
yt_check(publishing_youtube_metadata($v)['status']['selfDeclaredMadeForKids']===true,'Made-for-kids declaration is sent');
yt_check(publishing_youtube_metadata($v)['status']['containsSyntheticMedia']===true,'Altered/synthetic-content disclosure is sent');
$v=$variant;unset($v['media_asset']);$v['media_url']='https://media.example.com/video.mp4';
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Arbitrary public URL cannot become a native YouTube download');
$v=$variant;$v['media_asset']['bytes']=PUBLISHING_YOUTUBE_MAX_BYTES+1;
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Application video size limit is enforced before provider requests');
$v=$variant;$v['media_asset']['mime']='application/octet-stream';
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Unknown binary file is not accepted as a video');
$v=$variant;$v['title']='A <misleading> title';
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Unsupported title characters are rejected');
$v=$variant;$v['text']=str_repeat('é',2501);
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Description byte limit is enforced for multibyte text');
$v=$variant;$v['text']='An invalid > description';
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Unsupported description characters are rejected');
$v=$variant;unset($v['made_for_kids']);
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Missing audience declaration cannot silently become false');
$v=$variant;$v['privacy']='';
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Missing visibility cannot fall through to YouTube public default');
$v=$variant;$v['media_asset_id']=2;
yt_reject(fn()=>publishing_youtube_validate_variant($v),'Approved asset identity must match the selected owned file');

$safe='https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=example';
yt_check(publishing_youtube_url_allowed($safe,true),'Official resumable session URL is accepted');
foreach([
    'https://www.googleapis.com.evil.example/upload/youtube/v3/videos?uploadType=resumable&upload_id=example',
    'http://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=example',
    'https://user:password@www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=example',
    'https://169.254.169.254/upload/youtube/v3/videos?uploadType=resumable&upload_id=example',
    'https://www.googleapis.com/other/path?uploadType=resumable&upload_id=example',
    'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable',
    'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id[]=example',
    $safe.'#fragment',
] as $url) yt_check(!publishing_youtube_url_allowed($url,true),'Session endpoint rejects untrusted host/path/shape: '.$url);
putenv('YOUTUBE_TEST_BASE_URL=http://127.0.0.1:19091');
yt_check(publishing_youtube_base()==='http://127.0.0.1:19091','Numeric loopback fixture is enabled only for testing');
$youtubeTestEnv='production';
yt_reject(fn()=>publishing_youtube_base(),'Test endpoint override is rejected in production',false);
$youtubeTestEnv='testing';putenv('YOUTUBE_TEST_BASE_URL=https://provider.example.com');
yt_reject(fn()=>publishing_youtube_base(),'Even testing cannot inject an arbitrary remote endpoint',false);
putenv('YOUTUBE_TEST_BASE_URL');

$state=['adapter'=>'youtube_resumable_v1','phase'=>'uploading','bytes'=>1024,'offset'=>256,'max_sent'=>768,'session_uri_encrypted'=>'encrypted-fixture'];
yt_check(publishing_youtube_range(['headers'=>['range'=>'bytes=0-511']],$state)===512,'Resume offset begins after the last acknowledged byte');
yt_reject(fn()=>publishing_youtube_range(['headers'=>['range'=>'bytes=0-1023']],$state),'Provider cannot acknowledge bytes this job never attempted',true);
yt_reject(fn()=>publishing_youtube_range(['headers'=>['range'=>'bytes=0-10']],$state),'A backward server offset cannot cause overlapping replay',true);
yt_reject(fn()=>publishing_youtube_range(['headers'=>[]],$state),'Missing range after previous acknowledgement cannot reset an upload',true);
yt_reject(fn()=>publishing_youtube_range(['headers'=>['range'=>'bytes=10-99']],$state),'Noncontiguous acknowledgement is rejected',true);
$zero=$state;$zero['offset']=0;$zero['max_sent']=0;
yt_check(publishing_youtube_range(['headers'=>[]],$zero)===0,'Missing range is valid only before any acknowledged bytes');
$job=['method'=>'direct','destination_json'=>publishing_json(['slug'=>'youtube']),'provider_state_json'=>publishing_json($state)];
yt_check(publishing_youtube_can_resume($job),'Existing upload session is eligible for a same-session status query');
$state['phase']='initializing';$job['provider_state_json']=publishing_json($state);
yt_check(!publishing_youtube_can_resume($job),'Lost initial response without a saved session cannot be replayed');
$state['phase']='expired';$job['provider_state_json']=publishing_json($state);
yt_check(!publishing_youtube_can_resume($job),'Expired session cannot automatically create a replacement upload');
$state['phase']='processing';$state['remote_id']='aBcDeFgHi12';$job['provider_state_json']=publishing_json($state);
yt_check(publishing_youtube_can_resume($job),'Known uploaded video resumes read-only processing checks');
$state['phase']='initialization_rejected';$state['max_sent']=0;unset($state['session_uri_encrypted'],$state['remote_id']);$job['provider_state_json']=publishing_json($state);
yt_check(publishing_youtube_can_restart_rejected_init($job),'Definitively rejected metadata-only initialization can be reviewed and retried');
$state['max_sent']=1;$job['provider_state_json']=publishing_json($state);
yt_check(!publishing_youtube_can_restart_rejected_init($job),'Any attempted video bytes prevent a metadata-only restart');
yt_check(!publishing_public_url('https://www.waithakandegwa.com/newsletter/one-click'),'One-click unsubscribe endpoint cannot be distributed as a source or asset');

echo "YouTube offline tests passed: {$checks}\n";
