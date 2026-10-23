<?php
declare(strict_types=1);

/** Independent Accounts API integration with a disposable DB and synthetic provider.
 * Exercises actual OAuth, immutable approval, owned media, worker and status paths.
 */
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'
    ||getenv('DB_HOST')!=='127.0.0.1'||!preg_match('/\Awaithaka_test_[a-z0-9_]+_test\z/',(string)getenv('DB_DATABASE'))) {
    fwrite(STDERR,"Use a fresh, explicitly disposable loopback database from the aggregate runner.\n");exit(2);
}
require dirname(__DIR__).'/app/bootstrap.php';
set_exception_handler(static function(Throwable $e):never {
    fwrite(STDERR,'Native TikTok fixture failed: '.$e->getMessage()."\n");exit(1);
});
if(app_config('app_env')!=='testing'||(string)db()->query('SELECT DATABASE()')->fetchColumn()!==getenv('DB_DATABASE'))throw new RuntimeException('Unexpected test configuration.');
foreach(['publishing_connections','publishing_packages','publishing_jobs','media_assets'] as $table)if((int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Native TikTok tests require a fresh isolated schema.');
$CONFIG['base_url']='https://www.waithakandegwa.com';$CONFIG['media']['driver']='local';
$bdDirectory=sys_get_temp_dir().'/waithaka-tiktok-business-'.bin2hex(random_bytes(8));
if(!mkdir($bdDirectory,0700))throw new RuntimeException('Cannot create synthetic provider directory.');
$bdStateFile=$bdDirectory.'/state.json';$bdSecret='synthetic-business-'.bin2hex(random_bytes(16));
$bdChecks=0;$bdActor=0;$bdProcess=null;$bdAssets=[];$bdPaths=[];$bdPackages=[];$bdAssetId=0;$bdMediaUrl='';
$bdOriginalChannel=publishing_channel('tiktok');
function bd_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['bdChecks']++;}
function bd_reject(callable $fn,string $label):void {try{$fn();}catch(RuntimeException|MediaLibraryException $e){bd_check(true,$label);return;}bd_check(false,$label);}
function bd_state():array {return json_decode((string)file_get_contents($GLOBALS['bdStateFile']),true,512,JSON_THROW_ON_ERROR);}
function bd_write(array $state):void {file_put_contents($GLOBALS['bdStateFile'],json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX);}
function bd_reset(string $mode='ok'):void {
    bd_write(['mode'=>$mode,'client_id'=>'synthetic-business-client','client_secret'=>$GLOBALS['bdSecret'],'open_id'=>'synthetic-business-owner',
        'access_token'=>'synthetic-business-access','refresh_token'=>'synthetic-business-refresh',
        'scopes'=>['video.publish','user.info.basic','user.info.username','user.account.type'],'expected_media_url'=>$GLOBALS['bdMediaUrl'],
        'publish_id'=>'v_pub_url~v1.7000000000000000001','authorizations'=>0,'refreshes'=>0,'invalid_refresh_replays'=>0,
        'inspections'=>0,'profile_reads'=>0,'settings_reads'=>0,'property_reads'=>0,'publishes'=>0,'status_reads'=>0,'requests'=>[]]);
}
function bd_mode(string $mode):void {$state=bd_state();$state['mode']=$mode;bd_write($state);}
function bd_connect():array {
    publishing_oauth_complete('tiktok_business','synthetic-business-code',[],$GLOBALS['bdActor']);
    publishing_confirm_connection('tiktok_business','synthetic-business-owner',$GLOBALS['bdActor']);
    return publishing_connection('tiktok_business');
}
function bd_job(int $id):array {
    $query=db()->prepare('SELECT j.*,r.snapshot_json,r.content_hash,r.package_id FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE j.id=?');
    $query->execute([$id]);$job=$query->fetch();if(!$job)throw new RuntimeException('Missing fixture job.');return $job;
}
function bd_draft():array {
    $draft=publishing_default_draft();$draft['title']='Synthetic native TikTok package';
    $draft['variants']['tiktok']=array_replace($draft['variants']['tiktok'],[
        'enabled'=>true,'text'=>'Week one: the exact approved TikTok caption','format'=>'video','media_type'=>'video','media_asset_id'=>$GLOBALS['bdAssetId'],
        'privacy'=>'public','ai_generated'=>true,'tiktok_allow_comments'=>true,'tiktok_allow_duet'=>false,'tiktok_allow_stitch'=>true,
        'tiktok_brand_organic'=>false,'tiktok_branded_content'=>true,'tiktok_settings_confirmed'=>true]);
    return $draft;
}
function bd_package():array {
    $draft=bd_draft();$package=publishing_save_package($draft,$GLOBALS['bdActor']);$GLOBALS['bdPackages'][]=$package;
    $saved=publishing_decode(publishing_package($package)['draft_json']);
    $destination=publishing_destination(publishing_channel('tiktok'),publishing_variant_with_asset($saved['variants']['tiktok']));
    $revision=publishing_approve($package,1,$GLOBALS['bdActor'],hash('sha256',publishing_json(['tiktok'=>$destination])));
    $date=(new DateTimeImmutable('now',new DateTimeZone('Africa/Nairobi')))->modify('+1 hour')->format('Y-m-d\TH:i');
    publishing_schedule($package,$revision,$date,$GLOBALS['bdActor']);
    $query=db()->prepare('SELECT id FROM publishing_jobs WHERE revision_id=?');$query->execute([$revision]);$id=(int)$query->fetchColumn();
    db()->prepare('UPDATE publishing_jobs SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$id]);
    return bd_job($id);
}
function bd_poll(int $id):array {
    db()->prepare('UPDATE publishing_jobs SET next_check_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$id]);
    publishing_work(1);return bd_job($id);
}
function bd_retire_jobs():void {db()->exec("UPDATE publishing_jobs SET status='cancelled',next_check_at=NULL WHERE status NOT IN ('published','cancelled')");}
try {
    bd_reset();
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot reserve fixture port.');
    $address=(string)stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
    $bdProcess=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/tiktok_business_fixture_router.php'],
        [0=>['file','/dev/null','r'],1=>['file',$bdDirectory.'/provider.log','a'],2=>['file',$bdDirectory.'/provider.log','a']],$pipes,__DIR__,
        ['PATH'=>(string)getenv('PATH'),'TIKTOK_BUSINESS_FIXTURE_STATE_FILE'=>$bdStateFile]);
    if(!is_resource($bdProcess))throw new RuntimeException('Cannot start synthetic Accounts API.');
    putenv('TIKTOK_BUSINESS_TEST_BASE_URL=http://127.0.0.1:'.$port);
    $ready=false;
    for($n=0;$n<50;$n++){
        $curl=curl_init('http://127.0.0.1:'.$port.'/_health');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>300,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['X-Fixture-Secret: '.$bdSecret]]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($status===200&&$body==='{"fixture":true}'){$ready=true;break;}usleep(20000);
    }
    bd_check($ready,'The synthetic Accounts API starts on its own authenticated loopback port');
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Native TikTok fixture','tiktok-business-'.bin2hex(random_bytes(6)).'@example.test',password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);
    $bdActor=(int)db()->lastInsertId();
    $source=$bdDirectory.'/source.mp4';file_put_contents($source,hex2bin('000000186674797069736f6d0000020069736f6d69736f32').str_repeat('synthetic-video-container-',256));
    $bdAssetId=media_library_import($source,'owned-tiktok.mp4',['title'=>'Synthetic owned public video','visibility'=>'public','public_ack'=>'1'],$bdActor);
    $bdAssets[]=$bdAssetId;$asset=media_library_asset($bdAssetId);$bdPaths[]=media_library_local_path($asset);$bdMediaUrl=$asset['public_url'];
    bd_reset();
    $authUrl='https://www.tiktok.com/v2/auth/authorize/?'.http_build_query(['client_key'=>'synthetic-portal-client-key','response_type'=>'code',
        'scope'=>implode(',',publishing_tiktok_business_scopes()),'redirect_uri'=>'https://www.waithakandegwa.com/write/connections/callback/tiktok_business/'],'','&',PHP_QUERY_RFC3986);
    set_setting('publishing.tiktok_business.client_id','synthetic-business-client');set_setting('publishing.tiktok_business.client_secret',$bdSecret,true);set_setting('publishing.tiktok_business.authorization_url',$authUrl);
    db()->exec("UPDATE publishing_channels SET delivery_method='direct' WHERE slug='tiktok'");
    $authorize=publishing_oauth_start('tiktok_business',$bdActor);parse_str((string)parse_url($authorize,PHP_URL_QUERY),$params);
    bd_check(parse_url($authorize,PHP_URL_HOST)==='www.tiktok.com'&&($params['redirect_uri']??'')==='https://www.waithakandegwa.com/write/connections/callback/tiktok_business/','Business authorization retains the registered static HTTPS callback');
    bd_check(($params['client_key']??'')==='synthetic-portal-client-key'&&$params['client_key']!==setting('publishing.tiktok_business.client_id'),'Real OAuth start preserves the separate opaque portal key; inspected app identity remains authoritative');
    $state=publishing_consume_state('tiktok_business',$params['state'],$bdActor);
    bd_reject(fn()=>publishing_consume_state('tiktok_business',$params['state'],$bdActor),'Native OAuth state is one-use and session-bound');
    foreach(['token_missing_scope','inspected_missing_scope','wrong_app','wrong_creator'] as $mode){
        bd_reset($mode);bd_reject(fn()=>publishing_oauth_complete('tiktok_business','synthetic-business-code',$state,$bdActor),'Native consent cannot establish a connection with '.$mode);
        bd_check(publishing_connection('tiktok_business')===null,'Invalid native authorization leaves no fake connection');
    }
    bd_reset();publishing_oauth_complete('tiktok_business','synthetic-business-code',$state,$bdActor);
    $observed=publishing_connection('tiktok_business');
    bd_check($observed['status']==='pending_confirmation'&&$observed['external_id']==='synthetic-business-owner'&&$observed['selected_resource']===null,'Observed app and creator identity still require destination confirmation');
    bd_check(!publishing_tiktok_business_connection_ready($observed),'Observation alone never activates public delivery');
    $meta=publishing_decode($observed['metadata_json']);
    bd_check($meta['is_business_account']===false&&$meta['has_advanced_access']===true,'Actual returned account entitlements are preserved without inflating the declared professional label');
    bd_check(!str_contains($observed['credentials_encrypted'],'synthetic-business-access'),'Business credentials remain encrypted and separate from Login Kit');
    publishing_confirm_connection('tiktok_business','synthetic-business-owner',$bdActor);$connection=publishing_connection('tiktok_business');
    bd_check(publishing_tiktok_business_connection_ready($connection),'A confirmed account with observed app and required publishing scopes enables the native route');
    $readOnly=$connection;$readOnly['service']='tiktok_read';$readOnly['provider']='tiktok';
    bd_check(!publishing_tiktok_business_connection_ready($readOnly),'A Login Kit read grant cannot unlock Business API publishing');
    $job=bd_package();$snapshot=publishing_decode($job['snapshot_json']);
    bd_check($snapshot['variants']['tiktok']['media_asset']===media_library_approved_asset($bdAssetId),'Native approval freezes the actual owned public asset and fingerprint');
    bd_check($snapshot['variants']['tiktok']['ai_generated']===true&&$snapshot['variants']['tiktok']['tiktok_branded_content']===true&&$snapshot['variants']['tiktok']['tiktok_brand_organic']===false,'Exact disclosure choices are immutable in the approved revision');
    $edited=bd_draft();$edited['variants']['tiktok']['text']='A later unapproved caption';$edited['variants']['tiktok']['ai_generated']=false;
    publishing_save_package($edited,$bdActor,(int)$job['package_id'],1);
    bd_mode('status_processing');
    $result=publishing_work(1);$job=bd_job((int)$job['id']);$sent=bd_state();
    bd_check($result['processed']===1&&$job['status']==='delivered'&&$sent['publishes']===1,'The actual worker records an accepted native task separately from publication');
    $info=$sent['published_body']['post_info'];
    bd_check($info['caption']===$snapshot['variants']['tiktok']['text']&&$info['is_ai_generated']===true,'Delivery uses the approved copy and disclosure despite later edits');
    bd_check($info['is_brand_organic']===false&&$info['is_branded_content']===true&&$info['disable_comment']===false&&$info['disable_duet']===true&&$info['disable_stitch']===false,'Provider flags preserve the exact approved commercial and interaction settings');
    bd_check($info['upload_to_draft']===false&&$info['is_ads_only']===false,'Public native delivery cannot silently become an inbox draft or ads-only post');
    bd_check($sent['property_reads']>=1&&$sent['settings_reads']>=2&&$sent['published_body']['video_url']===$bdMediaUrl,'Worker checks current creator settings and verified ownership of the exact public media URL');
    bd_check(!str_contains($job['provider_state_json'],$bdSecret)&&!str_contains($job['provider_state_json'],'synthetic-domain-proof'),'Durable publishing state does not leak app secrets or ownership signatures');
    $job=bd_poll((int)$job['id']);bd_check($job['status']==='delivered','Downloading media is not publication');
    bd_mode('status_no_id');
    $job=bd_poll((int)$job['id']);bd_check($job['status']==='delivered','PUBLISH_COMPLETE without a public post ID remains pending');
    bd_mode('status_complete');
    $job=bd_poll((int)$job['id']);
    bd_check($job['status']==='published'&&$job['completed_at']!==null&&$job['external_url']==='https://www.tiktok.com/@waithakandegwa/video/7000000000000000001','Returned public post ID completes the job with a canonical platform URL');
    $requests=count(bd_state()['requests']);publishing_work(1);
    bd_check(bd_state()['publishes']===1&&count(bd_state()['requests'])===$requests,'Completed jobs are not published or polled again');
    foreach(['property_unverified','comments_restricted','account_private'] as $mode){
        bd_retire_jobs();bd_reset();bd_connect();$job=bd_package();bd_mode($mode);publishing_work(1);$job=bd_job((int)$job['id']);
        bd_check($job['status']==='failed'&&bd_state()['publishes']===0,'Live '.$mode.' blocks the mutation without overriding approval');
    }
    foreach(['inspected_missing_scope','inspected_expired'] as $mode){
        bd_retire_jobs();bd_reset();bd_connect();$job=bd_package();bd_mode($mode);publishing_work(1);$job=bd_job((int)$job['id']);
        bd_check($job['status']==='reconnect'&&publishing_connection('tiktok_business')['status']==='reconnect','Live '.$mode.' invalidates the earlier confirmed publishing grant');
        bd_check(bd_state()['publishes']===0&&empty(publishing_decode($job['provider_state_json'])),'Live '.$mode.' prevents any new publish attempt');
        bd_retire_jobs();bd_reset();bd_connect();$job=bd_package();publishing_work(1);$accepted=bd_job((int)$job['id']);
        bd_mode($mode);$job=bd_poll((int)$job['id']);
        bd_check($job['status']==='reconnect'&&publishing_connection('tiktok_business')['status']==='reconnect','Read-only task recovery also requires renewed authorization after '.$mode);
        bd_check(publishing_tiktok_business_can_resume($job)&&$job['remote_id']===$accepted['remote_id']
            &&$job['provider_state_json']===$accepted['provider_state_json']&&bd_state()['publishes']===1,'Live '.$mode.' preserves the existing task without replaying publication');
    }
    bd_retire_jobs();bd_reset();bd_connect();$job=bd_package();
    $path=$bdPaths[0];$original=(string)file_get_contents($path);file_put_contents($path,$original.'changed-after-approval');
    try{publishing_work(1);$job=bd_job((int)$job['id']);bd_check($job['status']==='failed'&&bd_state()['publishes']===0,'Changed owned media bytes cannot be published under an old approval (state='.$job['status'].', publishes='.bd_state()['publishes'].', reason='.(string)$job['last_error'].')');}
    finally{file_put_contents($path,$original);}
    foreach(['publish_lost','publish_missing_id','publish_http500','publish_code_error'] as $mode){
        bd_retire_jobs();bd_reset();bd_connect();$job=bd_package();bd_mode($mode);publishing_work(1);$job=bd_job((int)$job['id']);
        bd_check($job['status']==='ambiguous'&&bd_state()['publishes']===1,'Unknown '.$mode.' outcome is retained for human review');
        bd_check(!publishing_tiktok_business_can_resume($job),'A publish attempt without a known task ID cannot be resumed as a new post');
        publishing_work(1);bd_check(bd_state()['publishes']===1,'Another worker does not replay '.$mode);
        bd_reject(fn()=>publishing_tiktok_business_deliver($job,publishing_decode($job['snapshot_json']),publishing_connection('tiktok_business')),'Adapter itself prevents a second mutation for '.$mode);
        bd_check(bd_state()['publishes']===1,'Explicit adapter re-entry also leaves '.$mode.' as one attempt');
    }
    foreach(['status_failed'=>'failed','status_inbox'=>'ambiguous','status_unknown'=>'ambiguous','status_invalid_id'=>'ambiguous'] as $mode=>$expected){
        bd_retire_jobs();bd_reset();bd_connect();$job=bd_package();publishing_work(1);bd_mode($mode);$job=bd_poll((int)$job['id']);
        bd_check($job['status']===$expected&&bd_state()['publishes']===1,'Post-acceptance '.$mode.' is handled without a second publish');
    }
    bd_retire_jobs();bd_reset();bd_connect();$job=bd_package();
    // Simulate loss of the caller's returned-result update after the adapter received share_id.
    publishing_tiktok_business_deliver($job,publishing_decode($job['snapshot_json']),publishing_connection('tiktok_business'));
    $saved=bd_job((int)$job['id']);
    bd_check(publishing_tiktok_business_can_resume($saved)&&!empty(publishing_decode($saved['provider_state_json'])['publish_id']),'The native task ID is durable before the caller records the result');
    bd_mode('status_complete');$fields=publishing_tiktok_business_check($saved,publishing_connection('tiktok_business'));
    bd_check($fields['status']==='published'&&bd_state()['publishes']===1,'Lost local acknowledgment recovers the same known task by read-only status');
    bd_retire_jobs();bd_reset();bd_connect();$job=bd_package();publishing_work(1);bd_mode('status_401');$job=bd_poll((int)$job['id']);
    bd_check($job['status']==='reconnect'&&publishing_tiktok_business_can_resume($job),'Expired access preserves the known task for safe status recovery');
    bd_check(publishing_connection('tiktok_business')['status']==='reconnect'&&bd_state()['publishes']===1,'Expired access cannot trigger a fresh publication');
    bd_mode('status_complete');bd_connect();$fields=publishing_tiktok_business_check($job,publishing_connection('tiktok_business'));
    bd_check($fields['status']==='published'&&bd_state()['publishes']===1,'Reauthorizing the same owner resumes only the existing publishing task');
    bd_retire_jobs();bd_reset();$connection=bd_connect();$credentials=publishing_credentials($connection);$credentials['expires_at']=time()-1;
    db()->prepare('UPDATE publishing_connections SET credentials_encrypted=? WHERE id=?')->execute([encrypt_secret(publishing_json($credentials)),$connection['id']]);
    $stale=publishing_connection('tiktok_business');$access=publishing_access_token($stale);
    bd_check($access==='synthetic-business-access-1'&&publishing_credentials(publishing_connection('tiktok_business'))['refresh_token']==='synthetic-business-refresh-1','Business refresh uses its separate endpoint and persists the rotated token');
    bd_check(publishing_access_token($stale)===$access&&bd_state()['refreshes']===1,'Stale native callers cannot replay the previous refresh token');
    bd_reset();$connection=bd_connect();$credentials=publishing_credentials($connection);$credentials['expires_at']=time()-1;
    db()->prepare('UPDATE publishing_connections SET credentials_encrypted=? WHERE id=?')->execute([encrypt_secret(publishing_json($credentials)),$connection['id']]);
    bd_mode('lost_refresh');$stale=publishing_connection('tiktok_business');
    bd_reject(fn()=>publishing_access_token($stale),'Unknown native token rotation stops access and requires reconnection');
    bd_reject(fn()=>publishing_access_token($stale),'The potentially rotated Business refresh token cannot be reused');
    bd_check(bd_state()['refreshes']===1&&bd_state()['invalid_refresh_replays']===0,'Native refresh acknowledgment loss is never retried blindly');
    bd_check((int)db()->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===0,'Native Accounts API jobs send no assisted-post email');
    echo "TikTok Business OAuth/media/worker checks passed: {$bdChecks}\n";
} finally {
    putenv('TIKTOK_BUSINESS_TEST_BASE_URL');
    if(is_resource($bdProcess)){proc_terminate($bdProcess);proc_close($bdProcess);}
    foreach($bdPackages as $package){
        db()->prepare('DELETE j FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE r.package_id=?')->execute([$package]);
        db()->prepare('DELETE FROM publishing_events WHERE package_id=?')->execute([$package]);
        db()->prepare('DELETE FROM publishing_revisions WHERE package_id=?')->execute([$package]);
        db()->prepare('DELETE FROM publishing_packages WHERE id=?')->execute([$package]);
    }
    if($bdActor){
        db()->prepare('DELETE FROM publishing_oauth_states WHERE user_id=?')->execute([$bdActor]);
        db()->prepare('DELETE FROM publishing_events WHERE actor_id=?')->execute([$bdActor]);
        db()->prepare("DELETE FROM publishing_connections WHERE service='tiktok_business' AND connected_by=?")->execute([$bdActor]);
        foreach($bdAssets as $id)db()->prepare('DELETE FROM media_assets WHERE id=?')->execute([$id]);
        db()->prepare('DELETE FROM journey_audit_events WHERE actor_id=?')->execute([$bdActor]);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$bdActor]);
    }
    foreach($bdPaths as $path)if(is_file($path)&&!is_link($path))unlink($path);
    db()->exec("DELETE FROM settings WHERE setting_key IN ('publishing.tiktok_business.client_id','publishing.tiktok_business.client_secret','publishing.tiktok_business.authorization_url')");
    if($bdOriginalChannel)db()->prepare('UPDATE publishing_channels SET delivery_method=?,account_type=? WHERE id=?')->execute([$bdOriginalChannel['delivery_method'],$bdOriginalChannel['account_type'],$bdOriginalChannel['id']]);
    foreach(glob($bdDirectory.'/*')?:[] as $path)if(is_file($path))unlink($path);
    if(is_dir($bdDirectory))rmdir($bdDirectory);
}
