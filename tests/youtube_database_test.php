<?php
declare(strict_types=1);

/** Real approval/media/worker integration with a synthetic loopback provider.
 * The aggregate runner supplies a fresh migrated database and empty app config.
 * Nothing here connects a real account, sends mail or contacts Google.
 */
if (PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' || getenv('WK_TEST_ALLOW_WRITES')!=='1'
    || !in_array(getenv('DB_HOST'),['127.0.0.1','::1'],true)
    || !preg_match('/\Awaithaka_test_[a-z0-9_]+_test\z/',(string)getenv('DB_DATABASE'))) {
    fwrite(STDERR,"Use the aggregate runner's fresh, explicitly disposable loopback test database.\n");exit(2);
}
require dirname(__DIR__).'/app/bootstrap.php';
if (app_config('app_env')!=='testing' || (string)db()->query('SELECT DATABASE()')->fetchColumn()!==getenv('DB_DATABASE')) {
    throw new RuntimeException('The loaded application does not match the isolated test database.');
}
foreach(['publishing_connections','publishing_packages','publishing_jobs','media_assets'] as $table) {
    if((int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('YouTube integration requires a fresh database without existing publishing or media records.');
}
$CONFIG['media']['driver']='local';$CONFIG['base_url']='https://www.waithakandegwa.com';
$ytDbDirectory=sys_get_temp_dir().'/waithaka-youtube-db-'.bin2hex(random_bytes(8));
if(!mkdir($ytDbDirectory,0700))throw new RuntimeException('Cannot create the isolated provider fixture directory.');
$ytDbState=$ytDbDirectory.'/state.json';$ytDbToken='youtube-db-fixture-'.bin2hex(random_bytes(16));
$ytDbChecks=0;$ytDbProcess=null;$ytDbPipes=[];$ytDbActor=0;$ytDbConnectionId=0;
$ytDbPackages=[];$ytDbAssets=[];$ytDbPaths=[];$ytDbOriginalChannel=publishing_channel('youtube');
function yd_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['ytDbChecks']++;}
function yd_reject(callable $action,string $label):void {try{$action();}catch(RuntimeException|MediaLibraryException $e){yd_check(true,$label);return;}yd_check(false,$label);}
function yd_provider_state():array {return json_decode((string)file_get_contents($GLOBALS['ytDbState']),true,512,JSON_THROW_ON_ERROR);}
function yd_job(int $id):array {
    $query=db()->prepare('SELECT j.*,r.snapshot_json,r.content_hash FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE j.id=?');
    $query->execute([$id]);$job=$query->fetch();if(!$job)throw new RuntimeException('Missing fixture delivery job.');return $job;
}
function yd_provider_reset(string $mode):void {
    $path=$GLOBALS['ytDbDirectory'].'/received.bin';file_put_contents($path,'');
    file_put_contents($GLOBALS['ytDbState'],json_encode(['mode'=>$mode,'channel_id'=>'UC'.str_repeat('A',22),'initializations'=>0,
        'accepted'=>0,'complete'=>false,'status_queries'=>0,'chunk_requests'=>0,'video_reads'=>0,'ranges'=>[],'requests'=>[],'body_path'=>$path],JSON_THROW_ON_ERROR));
}
try {
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
    if(!$socket)throw new RuntimeException('Cannot reserve the local provider fixture port.');
    $address=(string)stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
    $log=$ytDbDirectory.'/provider.log';
    $ytDbProcess=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/youtube_fixture_router.php'],
        [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$ytDbPipes,__DIR__,
        ['YOUTUBE_FIXTURE_STATE_FILE'=>$ytDbState,'YOUTUBE_FIXTURE_TOKEN'=>$ytDbToken]);
    if(!is_resource($ytDbProcess))throw new RuntimeException('Cannot start the local provider fixture.');
    fclose($ytDbPipes[0]);putenv('YOUTUBE_TEST_BASE_URL=http://127.0.0.1:'.$port);
    $ready=false;
    for($attempt=0;$attempt<50;$attempt++) {
        $curl=curl_init('http://127.0.0.1:'.$port.'/_health');
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT_MS=>100,CURLOPT_TIMEOUT_MS=>300,
            CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$ytDbToken]]);
        $body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($code===200&&$body==='{"fixture":true}'){$ready=true;break;}usleep(20000);
    }
    yd_check($ready,'The authenticated loopback provider fixture starts');
    yd_provider_reset('lost_final');
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['YouTube synthetic writer','youtube-'.bin2hex(random_bytes(6)).'@example.invalid',password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);
    $ytDbActor=(int)db()->lastInsertId();
    $source=$ytDbDirectory.'/source.mp4';
    file_put_contents($source,hex2bin('000000186674797069736f6d0000020069736f6d69736f32').str_repeat('synthetic-media-',4096));
    $assetId=media_library_import($source,'week-one.mp4',['title'=>'Synthetic approved video','visibility'=>'private'],$ytDbActor);
    $ytDbAssets[]=$assetId;$asset=media_library_asset($assetId);$ytDbPaths[]=media_library_local_path($asset);
    $frozen=media_library_approved_asset($assetId);
    $channelId='UC'.str_repeat('A',22);
    publishing_save_observed_connection('google_youtube_upload',['access_token'=>$ytDbToken,'expires_at'=>time()+3600],
        ['https://www.googleapis.com/auth/youtube.upload','https://www.googleapis.com/auth/youtube.readonly'],
        ['id'=>'synthetic-youtube-google-user','name'=>'Synthetic fixture account',
            'resources'=>[['id'=>$channelId,'name'=>'Synthetic fixture channel','type'=>'youtube_channel','url'=>'https://www.youtube.com/channel/'.$channelId]]],$ytDbActor);
    $ytDbConnectionId=(int)publishing_connection('google_youtube_upload')['id'];
    db()->exec("UPDATE publishing_channels SET delivery_method='direct' WHERE slug='youtube'");
    $draft=publishing_default_draft();$draft['title']='Synthetic native video package';
    $draft['variants']['youtube']=array_replace($draft['variants']['youtube'],[
        'enabled'=>true,'title'=>'Week one: the approved version','text'=>'Synthetic description from the approved version.',
        'media_asset_id'=>$assetId,'media_asset'=>['id'=>9999,'key'=>'../../private-file'],
        'privacy'=>'private','made_for_kids'=>true,'ai_generated'=>false,'notify_subscribers'=>false,
    ]);
    $packageId=publishing_save_package($draft,$ytDbActor);$ytDbPackages[]=$packageId;
    $saved=publishing_decode(publishing_package($packageId)['draft_json']);
    yd_check($saved['variants']['youtube']['media_asset_id']===$assetId&&!isset($saved['variants']['youtube']['media_asset']),'Drafts retain a library selection and discard submitted forged media metadata');
    yd_check($saved['variants']['youtube']['media_url']==='','Private library media is never exposed through a public URL');
    yd_reject(fn()=>publishing_approve($packageId,1,$ytDbActor),'An observed Google identity cannot upload before destination confirmation');
    publishing_confirm_connection('google_youtube_upload',$channelId,$ytDbActor);
    $destination=publishing_destination(publishing_channel('youtube'),publishing_variant_with_asset($saved['variants']['youtube']));
    $revisionId=publishing_approve($packageId,1,$ytDbActor,hash('sha256',publishing_json(['youtube'=>$destination])));
    $query=db()->prepare('SELECT * FROM publishing_revisions WHERE id=?');$query->execute([$revisionId]);$revision=$query->fetch();
    $snapshot=publishing_decode($revision['snapshot_json']);$approvedVariant=$snapshot['variants']['youtube'];
    yd_check($approvedVariant['media_asset']===$frozen,'Approval pins the real owned asset ID, key, version, size, MIME and fingerprint');
    yd_check($approvedVariant['privacy']==='private'&&$approvedVariant['made_for_kids']===true&&$approvedVariant['ai_generated']===false&&$approvedVariant['notify_subscribers']===false,'The immutable revision retains explicit true and false publication choices');
    yd_check(hash_equals($revision['content_hash'],hash('sha256',$revision['snapshot_json'])),'The approved asset metadata is covered by the revision integrity hash');
    $release=(new DateTimeImmutable('now',new DateTimeZone('Africa/Nairobi')))->modify('+1 hour')->format('Y-m-d\TH:i');
    yd_check(publishing_schedule($packageId,$revisionId,$release,$ytDbActor)===1,'One native upload job is scheduled for the reviewed revision');
    yd_check(publishing_schedule($packageId,$revisionId,$release,$ytDbActor)===0,'Repeated scheduling cannot create another video job');
    $query=db()->prepare('SELECT id FROM publishing_jobs WHERE revision_id=?');$query->execute([$revisionId]);$jobId=(int)$query->fetchColumn();
    $draft['variants']['youtube']['title']='A later unapproved title';$draft['variants']['youtube']['privacy']='public';
    publishing_save_package($draft,$ytDbActor,$packageId,1);
    yd_check(publishing_package($packageId)['approved_revision_id']===null,'A later draft edit clears current approval');
    yd_reject(fn()=>publishing_schedule($packageId,$revisionId,$release,$ytDbActor),'A stale approved revision cannot be scheduled after a later edit');
    yd_check(yd_job($jobId)['snapshot_json']===$revision['snapshot_json'],'An already scheduled job retains its exact previously approved title and visibility');
    media_library_archive($assetId,$ytDbActor);
    yd_reject(fn()=>publishing_approve($packageId,2,$ytDbActor),'Archived media is unavailable for a new approval');
    db()->prepare('UPDATE publishing_jobs SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$jobId]);
    $result=publishing_work(1);$job=yd_job($jobId);$remote=yd_provider_state();
    yd_check($result['processed']===1&&$job['status']==='delivered'&&empty($job['remote_id']),'The real worker retains a resumable job after a lost final upload acknowledgement');
    yd_check($remote['received_sha256']===$frozen['sha256']&&$remote['initializations']===1,'The real library materializer sends the exact approved archived asset once');
    yd_check($remote['metadata']['snippet']['title']===$approvedVariant['title']&&$remote['metadata']['status']['privacyStatus']==='private','Later unapproved edits do not change the video sent by the worker');
    yd_check(!str_contains((string)$job['provider_state_json'],'upload_id=fixture')&&publishing_youtube_can_resume($job),'The real database keeps the upload session encrypted and safely resumable');
    db()->prepare('UPDATE publishing_jobs SET next_check_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$jobId]);
    $result=publishing_work(1);$job=yd_job($jobId);
    yd_check($result['checked']===1&&$job['status']==='delivered'&&$job['remote_id']==='aBcDeFgHi12','The real worker recovers the existing video ID without claiming completed processing');
    db()->prepare('UPDATE publishing_jobs SET next_check_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$jobId]);
    publishing_work(1);$job=yd_job($jobId);$remote=yd_provider_state();
    yd_check($job['status']==='published'&&$job['completed_at']!==null&&publishing_job_label($job)==='Uploaded · Private','Owner processing verification completes the private upload without a public-publication claim');
    yd_check($remote['initializations']===1&&$remote['chunk_requests']===1&&$remote['status_queries']===1,'Database recovery never initializes or uploads a duplicate video');
    $before=count($remote['requests']);publishing_work(1);
    yd_check(count(yd_provider_state()['requests'])===$before,'A completed delivery is not repeated by another worker run');
    yd_check((int)db()->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===0,'Native video delivery does not enqueue assisted-post email');
    echo "YouTube database integration tests passed: {$ytDbChecks}\n";
} finally {
    putenv('YOUTUBE_TEST_BASE_URL');
    if(is_resource($ytDbProcess)){proc_terminate($ytDbProcess);proc_close($ytDbProcess);}
    foreach($ytDbPackages as $packageId) {
        db()->prepare('DELETE j FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE r.package_id=?')->execute([$packageId]);
        db()->prepare('DELETE FROM publishing_events WHERE package_id=?')->execute([$packageId]);
        db()->prepare('DELETE FROM publishing_revisions WHERE package_id=?')->execute([$packageId]);
        db()->prepare('DELETE FROM publishing_packages WHERE id=?')->execute([$packageId]);
    }
    if($ytDbConnectionId)db()->prepare('DELETE FROM publishing_connections WHERE id=?')->execute([$ytDbConnectionId]);
    foreach($ytDbAssets as $id)db()->prepare('DELETE FROM media_assets WHERE id=?')->execute([$id]);
    foreach($ytDbPaths as $path)if(is_file($path)&&!is_link($path))unlink($path);
    if($ytDbActor) {
        db()->prepare('DELETE FROM publishing_events WHERE actor_id=?')->execute([$ytDbActor]);
        db()->prepare('DELETE FROM journey_audit_events WHERE actor_id=?')->execute([$ytDbActor]);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$ytDbActor]);
    }
    if($ytDbOriginalChannel)db()->prepare('UPDATE publishing_channels SET delivery_method=? WHERE id=?')->execute([$ytDbOriginalChannel['delivery_method'],$ytDbOriginalChannel['id']]);
    foreach(glob($ytDbDirectory.'/*')?:[] as $path)if(is_file($path))unlink($path);
    rmdir($ytDbDirectory);
}
