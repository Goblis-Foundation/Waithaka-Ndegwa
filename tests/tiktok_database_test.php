<?php
declare(strict_types=1);

// Real OAuth/read/rotating-token integration against a guarded synthetic HTTP provider.
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'
    ||getenv('DB_HOST')!=='127.0.0.1'||!preg_match('/\Awaithaka_test_[a-z0-9_]+_test\z/',(string)getenv('DB_DATABASE'))) {
    fwrite(STDERR,"Use the aggregate runner's fresh, explicitly disposable loopback database.\n");exit(2);
}
require dirname(__DIR__).'/app/bootstrap.php';
if(app_config('app_env')!=='testing'||(string)db()->query('SELECT DATABASE()')->fetchColumn()!==getenv('DB_DATABASE'))throw new RuntimeException('Unexpected test configuration.');
if(($argv[1]??'')==='--parallel-read') {
    try{publishing_access_token(publishing_connection('tiktok_read'));echo "parallel-ok\n";exit(0);}catch(Throwable $e){fwrite(STDERR,"Parallel refresh failed.\n");exit(1);}
}
if((int)db()->query("SELECT COUNT(*) FROM publishing_connections WHERE service='tiktok_read'")->fetchColumn()!==0)throw new RuntimeException('TikTok integration requires a fresh fixture connection.');
$CONFIG['base_url']='https://www.waithakandegwa.com';
$ttDirectory=sys_get_temp_dir().'/waithaka-tiktok-db-'.bin2hex(random_bytes(8));
if(!mkdir($ttDirectory,0700))throw new RuntimeException('Cannot create fixture directory.');
$ttStatePath=$ttDirectory.'/state.json';$ttSecret='synthetic-'.bin2hex(random_bytes(16));$ttChecks=0;$ttProcess=null;$ttActor=0;
function td_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['ttChecks']++;}
function td_reject(callable $fn,string $label):void {try{$fn();}catch(RuntimeException $e){td_check(true,$label);return;}td_check(false,$label);}
function td_state():array {return json_decode((string)file_get_contents($GLOBALS['ttStatePath']),true,512,JSON_THROW_ON_ERROR);}
function td_write_state(array $state):void {file_put_contents($GLOBALS['ttStatePath'],json_encode($state,JSON_THROW_ON_ERROR),LOCK_EX);}
function td_reset(string $mode='ok'):void {
    td_write_state(['mode'=>$mode,'client_key'=>'synthetic-client','client_secret'=>$GLOBALS['ttSecret'],'open_id'=>'synthetic-owner',
        'access_token'=>'synthetic-access','refresh_token'=>'synthetic-refresh','scopes'=>publishing_tiktok_scopes(),
        'authorizations'=>0,'refreshes'=>0,'invalid_refresh_replays'=>0,'user_reads'=>0,'video_reads'=>0,'requests'=>[]]);
}
function td_connect():array {
    publishing_oauth_complete('tiktok_read','synthetic-authorization-code',[],$GLOBALS['ttActor']);
    publishing_confirm_connection('tiktok_read','synthetic-owner',$GLOBALS['ttActor']);
    return publishing_connection('tiktok_read');
}
function td_expire():array {
    $row=publishing_connection('tiktok_read');$token=publishing_credentials($row);$token['expires_at']=time()-1;
    db()->prepare('UPDATE publishing_connections SET credentials_encrypted=? WHERE id=?')->execute([encrypt_secret(publishing_json($token)),$row['id']]);
    return publishing_connection('tiktok_read');
}
try {
    td_reset();
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Cannot reserve fixture port.');
    $address=(string)stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);
    $serverEnvironment=['PATH'=>(string)getenv('PATH'),'TIKTOK_FIXTURE_STATE_FILE'=>$ttStatePath,'APP_ENV'=>'testing'];
    foreach(['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD'] as $name)$serverEnvironment[$name]=(string)getenv($name);
    // Preserve the explicitly empty synthetic DB password on PHP builds that omit empty env values.
    $serverCommand=[PHP_BINARY,'-S','127.0.0.1:'.$port,__DIR__.'/tiktok_fixture_router.php'];
    if($serverEnvironment['DB_PASSWORD']==='')$serverCommand=array_merge(['/usr/bin/env','DB_PASSWORD='],$serverCommand);
    $ttProcess=proc_open($serverCommand,[0=>['file','/dev/null','r'],1=>['file',$ttDirectory.'/server.log','a'],2=>['file',$ttDirectory.'/server.log','a']],$pipes,__DIR__,$serverEnvironment);
    if(!is_resource($ttProcess))throw new RuntimeException('Cannot start synthetic provider.');
    putenv('TIKTOK_TEST_BASE_URL=http://127.0.0.1:'.$port);
    $ready=false;
    for($n=0;$n<50;$n++){
        $curl=curl_init('http://127.0.0.1:'.$port.'/_health');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>300,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['X-Fixture-Secret: '.$ttSecret]]);
        $body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        if($status===200&&$body==='{"fixture":true}'){$ready=true;break;}usleep(20000);
    }
    td_check($ready,'The authenticated numeric-loopback provider starts');
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['TikTok fixture','tiktok-'.bin2hex(random_bytes(5)).'@example.test',password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT)]);
    $ttActor=(int)db()->lastInsertId();
    set_setting('publishing.tiktok.client_id','synthetic-client');set_setting('publishing.tiktok.client_secret',$ttSecret,true);
    $authorize=publishing_oauth_start('tiktok_read',$ttActor);parse_str((string)parse_url($authorize,PHP_URL_QUERY),$params);
    td_check(str_starts_with($authorize,'https://www.tiktok.com/v2/auth/authorize/?')&&($params['client_key']??'')==='synthetic-client'&&!isset($params['client_id']),'OAuth uses TikTok client_key and the official web authorization endpoint');
    td_check(($params['scope']??'')===implode(',',publishing_tiktok_scopes())&&($params['disable_auto_auth']??'')==='1','OAuth asks for explicit read scopes and account consent');
    $state=publishing_consume_state('tiktok_read',$params['state'],$ttActor);
    td_reject(fn()=>publishing_consume_state('tiktok_read',$params['state'],$ttActor),'OAuth state cannot be consumed twice');
    publishing_oauth_complete('tiktok_read','synthetic-authorization-code',$state,$ttActor);
    $row=publishing_connection('tiktok_read');
    td_check($row['status']==='pending_confirmation'&&$row['external_id']==='synthetic-owner'&&$row['selected_resource']===null,'Observed identity remains pending until destination confirmation');
    td_check(!str_contains($row['credentials_encrypted'],'synthetic-access'),'Credentials are encrypted at rest');
    td_check(publishing_decode($row['metadata_json'])['account_type_verified']===false,'Identity verification does not claim a provider-verified account subtype');
    td_reject(fn()=>publishing_tiktok_report($row),'Unconfirmed account cannot load a report');
    td_reject(fn()=>publishing_confirm_connection('tiktok_read','not-returned',$ttActor),'A manually entered identity cannot replace the returned account');
    publishing_confirm_connection('tiktok_read','synthetic-owner',$ttActor);$row=publishing_connection('tiktok_read');
    $report=publishing_tiktok_report($row);
    td_check($report['account']['username']==='waithakandegwa'&&$report['summary']['follower_count']===120,'Confirmed reports are tied to actual returned identity and profile counters');
    td_check($report['summary']['video_count']===null&&$report['rows'][0]['comment_count']===null&&$report['rows'][0]['share_count']===0,'Unavailable metrics remain distinct from zero');
    td_check($report['has_more']===true&&count($report['rows'])===1,'A report is explicit about its bounded recent-public-video page');
    td_check(td_state()['refreshes']===0,'An unexpired read token does not cause an OAuth renewal');
    $expired=td_expire();$before=td_state()['refreshes'];
    $access=publishing_access_token($expired);$fresh=publishing_credentials(publishing_connection('tiktok_read'));
    td_check($access==='synthetic-rotated-access-1'&&$fresh['refresh_token']==='synthetic-rotated-refresh-1','Rotating refresh response replaces both encrypted tokens');
    td_check(empty($fresh['refresh_state'])&&td_state()['refreshes']===$before+1,'Successful renewal clears its durable uncertainty checkpoint');
    td_check(publishing_access_token($expired)===$access&&td_state()['refreshes']===$before+1,'A stale caller reloads current tokens instead of replaying the old refresh token');
    td_reset('declined_scope');$prior=publishing_connection('tiktok_read');
    td_reject(fn()=>publishing_oauth_complete('tiktok_read','synthetic-authorization-code',[],$ttActor),'Declined required permission cannot replace an existing valid connection');
    td_check(publishing_connection('tiktok_read')['credentials_encrypted']===$prior['credentials_encrypted'],'Failed new consent preserves the previous connection');
    td_reset('wrong_user');
    td_reject(fn()=>publishing_oauth_complete('tiktok_read','synthetic-authorization-code',[],$ttActor),'User-info identity mismatch cannot establish a connection');
    td_reset();td_connect();$bad=td_state();$bad['mode']='report_scope_error';td_write_state($bad);
    td_reject(fn()=>publishing_tiktok_report(publishing_connection('tiktok_read')),'HTTP-success API scope error is not rendered as a successful empty report');
    td_reset();td_connect();$expired=td_expire();$state=td_state();$state['mode']='lost_refresh';td_write_state($state);
    td_reject(fn()=>publishing_access_token($expired),'Lost refresh reply stops automatic credential use');
    $after=publishing_connection('tiktok_read');
    td_check($after['status']==='reconnect'&&publishing_credentials($after)['refresh_state']==='in_progress','Unknown token rotation persists a visible reconnection state');
    td_reject(fn()=>publishing_access_token($expired),'A stale request cannot retry the potentially rotated refresh token');
    td_check(td_state()['refreshes']===1&&td_state()['invalid_refresh_replays']===0,'Lost acknowledgement never replays a refresh mutation');
    td_reset();td_connect();$expired=td_expire();$state=td_state();$state['mode']='refresh_identity_changed';td_write_state($state);
    td_reject(fn()=>publishing_access_token($expired),'Token renewal cannot silently change account identity');
    td_check(publishing_connection('tiktok_read')['status']==='reconnect','Identity-changing refresh requires new consent');
    td_reset();td_connect();$expired=td_expire();$state=td_state();$state['mode']='reconnect_during_refresh';
    $replacement=publishing_credentials($expired);$replacement['access_token']='new-consent-access';$replacement['refresh_token']='new-consent-refresh';$replacement['expires_at']=time()+86400;
    $state['replacement_encrypted']=encrypt_secret(publishing_json($replacement));$state['connection_id']=$expired['id'];td_write_state($state);
    td_reject(fn()=>publishing_access_token($expired),'Concurrent renewed consent prevents a stale refresh from overwriting it');
    $new=publishing_connection('tiktok_read');
    td_check($new['status']==='connected'&&$new['credentials_encrypted']===$state['replacement_encrypted'],'A concurrent new connection survives both stale save and stale failure handler');
    td_reset();td_connect();td_expire();$state=td_state();$state['mode']='delayed_refresh';td_write_state($state);
    $children=[];
    for($n=0;$n<2;$n++){
        $log=$ttDirectory.'/parallel-'.$n.'.log';
        $process=proc_open([PHP_BINARY,__FILE__,'--parallel-read'],[0=>['file','/dev/null','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,__DIR__);
        if(!is_resource($process))throw new RuntimeException('Cannot start concurrent reader fixture.');$children[]=[$process,$log];
    }
    foreach($children as [$process,$log]){td_check(proc_close($process)===0&&trim((string)file_get_contents($log))==='parallel-ok','Concurrent read completes using serialized current credentials');}
    td_check(td_state()['refreshes']===1&&td_state()['invalid_refresh_replays']===0,'Concurrent readers perform exactly one rotating refresh');
    $row=publishing_connection('tiktok_read');$token=publishing_credentials($row);$token['refresh_state']='in_progress';
    db()->prepare('UPDATE publishing_connections SET credentials_encrypted=? WHERE id=?')->execute([encrypt_secret(publishing_json($token)),$row['id']]);
    td_reject(fn()=>publishing_access_token(publishing_connection('tiktok_read')),'A prior process crash checkpoint cannot be silently resumed');
    td_check(publishing_connection('tiktok_read')['status']==='reconnect','Crash recovery makes the required reconnection visible in Connections');
    td_check(td_state()['refreshes']===1,'Crash recovery issues no second renewal request');
    echo "TikTok OAuth/report/database checks passed: {$ttChecks}\n";
} finally {
    putenv('TIKTOK_TEST_BASE_URL');
    if(is_resource($ttProcess)){proc_terminate($ttProcess);proc_close($ttProcess);}
    if($ttActor){
        db()->prepare('DELETE FROM publishing_oauth_states WHERE user_id=?')->execute([$ttActor]);
        db()->prepare('DELETE FROM publishing_events WHERE actor_id=?')->execute([$ttActor]);
        db()->prepare("DELETE FROM publishing_connections WHERE service='tiktok_read' AND connected_by=?")->execute([$ttActor]);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$ttActor]);
    }
    db()->exec("DELETE FROM settings WHERE setting_key IN ('publishing.tiktok.client_id','publishing.tiktok.client_secret')");
    foreach(glob($ttDirectory.'/*')?:[] as $path)if(is_file($path))unlink($path);
    if(is_dir($ttDirectory))rmdir($ttDirectory);
}
