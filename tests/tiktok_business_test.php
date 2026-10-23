<?php
declare(strict_types=1);

// Independent Accounts API policy tests. No database, provider credentials or network.
$tbTestEnvironment='testing';
$tbSettings=[];
function app_config(?string $key=null,mixed $default=null):mixed {return $key==='app_env'?$GLOBALS['tbTestEnvironment']:$default;}
function setting(string $key,mixed $default=null):mixed {return $GLOBALS['tbSettings'][$key]??$default;}
function url(string $path=''):string {return 'https://www.waithakandegwa.com/'.ltrim($path,'/');}
require dirname(__DIR__).'/app/publishing.php';
require_once dirname(__DIR__).'/app/providers/tiktok_business.php';
putenv('TIKTOK_BUSINESS_TEST_BASE_URL');
$checks=0;
function tb_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function tb_reject(callable $fn,string $label,?bool $ambiguous=null,?int $httpStatus=null):void {
    try{$fn();}catch(RuntimeException $e){tb_check(($ambiguous===null||($e instanceof PublishingRemoteException&&$e->ambiguous===$ambiguous))
        &&($httpStatus===null||($e instanceof PublishingRemoteException&&$e->httpStatus===$httpStatus)),$label);return;}
    tb_check(false,$label);
}
$scopes=['video.publish','user.info.basic','user.info.username','user.account.type'];
tb_check(publishing_tiktok_business_scopes()===$scopes,'Native Accounts API requests its own documented publishing and identity scopes');
$callback='https://www.waithakandegwa.com/write/connections/callback/tiktok_business/';
$authParameters=['client_key'=>'synthetic-portal-key','response_type'=>'code','scope'=>implode(',',$scopes),'redirect_uri'=>$callback,'state'=>'old-portal-state'];
$authUrl='https://www.tiktok.com/v2/auth/authorize/?'.http_build_query($authParameters,'','&',PHP_QUERY_RFC3986);
$tbSettings=['publishing.tiktok_business.client_id'=>'synthetic-app','publishing.tiktok_business.client_secret'=>'synthetic-secret','publishing.tiktok_business.authorization_url'=>$authUrl];
$newState=str_repeat('b',64);parse_str((string)parse_url(publishing_tiktok_business_authorization_url($newState),PHP_URL_QUERY),$authResult);
tb_check($authResult['state']===$newState&&$authResult['disable_auto_auth']==='1','Copied portal URL receives a fresh state and an explicit account-consent prompt');
tb_check($authResult['client_key']==='synthetic-portal-key'&&$authResult['client_key']!==$tbSettings['publishing.tiktok_business.client_id'],'Opaque portal client key may differ from the Business App ID verified by token inspection');
foreach([
    str_replace('www.tiktok.com','www.tiktok.com.evil.example',$authUrl),
    str_replace('www.tiktok.com','user@www.tiktok.com',$authUrl),
    $authUrl.'&client_key=another-app',
    $authUrl.'&client_id=synthetic-app',
    str_replace('client_key=synthetic-portal-key','client_key=',$authUrl),
    str_replace('client_key=synthetic-portal-key','client_key=unsafe%2Fidentity',$authUrl),
    str_replace(rawurlencode($callback),rawurlencode('https://evil.example/callback/'),$authUrl),
    str_replace(rawurlencode($callback),rawurlencode(rtrim($callback,'/')),$authUrl),
] as $bad){$tbSettings['publishing.tiktok_business.authorization_url']=$bad;tb_reject(fn()=>publishing_tiktok_business_authorization_url($newState),'Authorization URL rejects unsafe origin, callback or empty, unsafe or repeated identity parameters');}
$broader=$authParameters;$broader['scope'].=',comment.list.manage';
$tbSettings['publishing.tiktok_business.authorization_url']='https://www.tiktok.com/v2/auth/authorize/?'.http_build_query($broader,'','&',PHP_QUERY_RFC3986);
parse_str((string)parse_url(publishing_tiktok_business_authorization_url($newState),PHP_URL_QUERY),$narrowed);
tb_check($narrowed['scope']===implode(',',$scopes),'A broader copied portal grant requests only the four disclosed native scopes');
$tbSettings['publishing.tiktok_business.authorization_url']=$authUrl;
// Official return codes distinguish expired credentials from the 40100 rate limit.
foreach([40101,40102,40103,40104,40105,40107,40110,40113,40115,40131] as $code){
    foreach([false,true] as $mutation)tb_reject(fn()=>publishing_tiktok_business_data(['code'=>$code,'data'=>[]],$mutation),
        'Documented credential code '.$code.' requires reconnection while preserving mutation uncertainty',$mutation,401);
}
foreach([40100,40118,49999] as $code){
    foreach([false,true] as $mutation)tb_reject(fn()=>publishing_tiktok_business_data(['code'=>$code,'data'=>[]],$mutation),
        'Rate, entitlement or unknown code '.$code.' is not inferred to be an expired credential',$mutation,400);
}
$token=['open_id'=>'owned-account','access_token'=>'synthetic-business-access','refresh_token'=>'synthetic-business-refresh',
    'token_type'=>'Bearer','scope'=>implode(',',$scopes),'expires_in'=>86400,'refresh_token_expires_in'=>31536000];
$validated=publishing_tiktok_business_validate_token($token);
tb_check($validated['expires_at']>time()&&$validated['refresh_expires_at']>$validated['expires_at'],'Accounts API token lifetimes use the Business API response field');
foreach($scopes as $scope){$bad=$token;$bad['scope']=implode(',',array_diff($scopes,[$scope]));tb_reject(fn()=>publishing_tiktok_business_validate_token($bad),'Native authorization rejects missing '.$scope);}
foreach(['open_id','refresh_token','expires_in','refresh_token_expires_in','scope'] as $field){$bad=$token;unset($bad[$field]);tb_reject(fn()=>publishing_tiktok_business_validate_token($bad),'Native token cannot assume missing '.$field);}
$bad=$token;unset($bad['refresh_token_expires_in']);$bad['refresh_expires_in']=31536000;
tb_reject(fn()=>publishing_tiktok_business_validate_token($bad),'Login Kit token shape cannot masquerade as an Accounts API token');
tb_reject(fn()=>publishing_tiktok_business_validate_token($token,'another-account'),'A native refresh cannot silently change the authorized creator');
$settings=['privacy_level_options'=>['PUBLIC_TO_EVERYONE','SELF_ONLY'],'comment_disabled'=>false,'duet_disabled'=>false,'stitch_disabled'=>false,'max_video_post_duration_sec'=>600];
publishing_tiktok_business_validate_settings($settings);tb_check(true,'Complete observed account settings are accepted');
foreach(['privacy_level_options','comment_disabled','duet_disabled','stitch_disabled','max_video_post_duration_sec'] as $field){$bad=$settings;unset($bad[$field]);tb_reject(fn()=>publishing_tiktok_business_validate_settings($bad),'Missing live '.$field.' cannot be guessed');}
$variant=['format'=>'video','media_type'=>'video','text'=>'Week one: testing the problem','media_asset_id'=>1,'media_url'=>'https://media.waithakandegwa.com/assets/uploads/2026/10/'.str_repeat('a',32).'.mp4',
    'privacy'=>'public','ai_generated'=>false,'tiktok_allow_comments'=>true,'tiktok_allow_duet'=>false,'tiktok_allow_stitch'=>false,
    'tiktok_brand_organic'=>true,'tiktok_branded_content'=>false,'tiktok_settings_confirmed'=>true];
$variant['media_asset']=['id'=>1,'driver'=>'local','bucket'=>'','key'=>'assets/uploads/2026/10/'.str_repeat('a',32).'.mp4','version_id'=>'',
    'sha256'=>str_repeat('a',64),'bytes'=>1024,'mime'=>'video/mp4','extension'=>'mp4','visibility'=>'public','public_url'=>$variant['media_url'],'title'=>'Owned public video'];
publishing_tiktok_business_validate_variant($variant);tb_check(true,'Explicit public video settings and a frozen owned media reference are accepted');
foreach(['privacy','ai_generated','tiktok_allow_comments','tiktok_allow_duet','tiktok_allow_stitch','tiktok_brand_organic','tiktok_branded_content','tiktok_settings_confirmed'] as $field){
    $bad=$variant;unset($bad[$field]);tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'An omitted approved '.$field.' cannot acquire a provider default');
}
$bad=$variant;$bad['tiktok_brand_organic']=true;$bad['tiktok_branded_content']=true;
tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'Conflicting commercial labels cannot be silently collapsed by TikTok');
$bad=$variant;$bad['tiktok_settings_confirmed']=false;
tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'Native settings require explicit review consent');
foreach(['private','unlisted',''] as $privacy){$bad=$variant;$bad['privacy']=$privacy;tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'Public Accounts API cannot silently alter '.$privacy.' visibility');}
$bad=$variant;$bad['text']=str_repeat('😀',1101);
tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'Caption limit counts UTF-16 units, including emoji surrogate pairs');
$bad=$variant;$bad['text']=str_repeat('😀',1100);
publishing_tiktok_business_validate_variant($bad);tb_check(true,'Exactly 2200 UTF-16 units fit the documented caption limit');
$bad=$variant;unset($bad['media_asset']);
tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'An arbitrary media URL cannot replace an approved owned asset');
$bad=$variant;$bad['media_asset']['visibility']='private';
tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'Private media cannot be released to the public URL publishing endpoint');
$bad=$variant;$bad['media_asset_id']=2;
tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'Selected asset ID must match the immutable approved reference');
$bad=$variant;$bad['media_asset']['bytes']=512*1024*1024+1;
tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'Application media cap is enforced even though TikTok allows larger videos');
$bad=$variant;$bad['media_asset']['mime']='image/jpeg';
tb_reject(fn()=>publishing_tiktok_business_validate_variant($bad),'Video publishing does not silently treat an image as a video');
publishing_tiktok_business_validate_live_settings($variant,$settings);tb_check(true,'Approved interactions are checked against live account permissions');
$bad=$settings;$bad['comment_disabled']=true;
tb_reject(fn()=>publishing_tiktok_business_validate_live_settings($variant,$bad),'New account comment restriction cannot silently override the approved setting');
$bad=$settings;$bad['privacy_level_options']=['SELF_ONLY'];
tb_reject(fn()=>publishing_tiktok_business_validate_live_settings($variant,$bad),'A private account cannot enter the public publishing path');
foreach(['duet','stitch'] as $field){$v=$variant;$v['tiktok_allow_'.$field]=true;$bad=$settings;$bad[$field.'_disabled']=true;tb_reject(fn()=>publishing_tiktok_business_validate_live_settings($v,$bad),'Live '.$field.' restriction is respected before mutation');}
$url='https://media.waithakandegwa.com/assets/uploads/2026/10/video.mp4';
$domain=[['property_type'=>1,'url'=>'waithakandegwa.com','property_status'=>1]];
tb_check(publishing_tiktok_business_property_matches($url,$domain),'Verified domain ownership includes true subdomains');
foreach(['https://evilwaithakandegwa.com/assets/video.mp4','https://waithakandegwa.com.evil.example/video.mp4','http://media.waithakandegwa.com/video.mp4','https://user@media.waithakandegwa.com/video.mp4'] as $bad){tb_check(!publishing_tiktok_business_property_matches($bad,$domain),'Domain verification cannot cover an untrusted or insecure URL');}
$prefix=[['property_type'=>2,'url'=>'https://media.waithakandegwa.com/assets/uploads/','property_status'=>1]];
tb_check(publishing_tiktok_business_property_matches($url,$prefix),'Exact verified URL prefix covers its immutable media subtree');
foreach(['https://media.waithakandegwa.com/assets/uploads-evil/video.mp4','https://media.waithakandegwa.com/other/video.mp4'] as $bad)tb_check(!publishing_tiktok_business_property_matches($bad,$prefix),'URL prefix matching respects directory boundaries');
$pending=$domain;$pending[0]['property_status']=0;tb_check(!publishing_tiktok_business_property_matches($url,$pending),'Pending domain ownership is not verified ownership');
$failed=$domain;$failed[0]['property_status']=2;tb_check(!publishing_tiktok_business_property_matches($url,$failed),'Failed ownership verification cannot unlock publishing');
$publish='v_pub_url~v1.7000000000000000001';
$pending=publishing_tiktok_business_publication_result(['status'=>'PROCESSING_DOWNLOAD'],$publish,'waithakandegwa');
tb_check($pending['status']==='delivered','Accepted download remains separate from publication');
$pending=publishing_tiktok_business_publication_result(['status'=>'PUBLISH_COMPLETE'],$publish,'waithakandegwa');
tb_check($pending['status']==='delivered'&&!empty($pending['next_check_at']),'Complete status without a public post ID remains pending');
$complete=publishing_tiktok_business_publication_result(['status'=>'PUBLISH_COMPLETE','post_ids'=>['7000000000000000001']],$publish,'waithakandegwa');
tb_check($complete['status']==='published'&&$complete['external_url']==='https://www.tiktok.com/@waithakandegwa/video/7000000000000000001','Public post IDs from the authorized status endpoint establish publication');
$failed=publishing_tiktok_business_publication_result(['status'=>'FAILED','reason'=>'frame_rate_check_failed'],$publish,'waithakandegwa');
tb_check($failed['status']==='failed','TikTok codec/frame-rate rejection is terminal and not reported as a public post');
foreach([['status'=>'SEND_TO_USER_INBOX'],['status'=>'NEW_UNKNOWN_STATUS'],[],['status'=>'PUBLISH_COMPLETE','post_ids'=>['javascript:alert(1)']]] as $bad){
    tb_reject(fn()=>publishing_tiktok_business_publication_result($bad,$publish,'waithakandegwa'),'Unexpected public-route outcome requires an ambiguous-result review',true);
}
$destination=['slug'=>'tiktok','method'=>'direct','service'=>'tiktok_business','resource'=>'owned-account','connection_external_id'=>'owned-account'];
$json=publishing_json($destination);$resumeState=['phase'=>'accepted','publish_id'=>$publish,'open_id'=>'owned-account','destination_hash'=>hash('sha256',$json)];
$job=['destination_json'=>$json,'provider_state_json'=>publishing_json($resumeState),'remote_id'=>$publish];
tb_check(publishing_tiktok_business_can_resume($job),'A known task bound to its exact approved owner can resume read-only checks');
foreach(['phase'=>'publish_attempted','open_id'=>'different-owner','destination_hash'=>str_repeat('0',64)] as $field=>$value){$bad=$job;$state=$resumeState;$state[$field]=$value;$bad['provider_state_json']=publishing_json($state);tb_check(!publishing_tiktok_business_can_resume($bad),'Task recovery rejects altered '.$field);}
$bad=$job;$bad['remote_id']='another-task';tb_check(!publishing_tiktok_business_can_resume($bad),'Job task ID cannot disagree with the durable provider checkpoint');
putenv('TIKTOK_BUSINESS_TEST_BASE_URL=http://127.0.0.1:19093');
tb_check(publishing_http_tiktok_business_fixture_url('https://business-api.tiktok.com/open_api/v1.3/business/get/?business_id=owned-account')==='http://127.0.0.1:19093/open_api/v1.3/business/get/?business_id=owned-account','Native fixtures preserve only allowlisted operation paths');
$tbTestEnvironment='production';tb_reject(fn()=>publishing_http_tiktok_business_fixture_url('https://business-api.tiktok.com/open_api/v1.3/business/get/'),'Production cannot redirect account credentials to a fixture');$tbTestEnvironment='testing';
putenv('TIKTOK_BUSINESS_TEST_BASE_URL=https://provider.example.com');tb_reject(fn()=>publishing_http_tiktok_business_fixture_url('https://business-api.tiktok.com/open_api/v1.3/business/get/'),'Native test override cannot target an arbitrary remote server');
putenv('TIKTOK_BUSINESS_TEST_BASE_URL=http://127.0.0.1:19093');tb_reject(fn()=>publishing_http_tiktok_business_fixture_url('https://business-api.tiktok.com/open_api/v1.3/unimplemented/'),'Native fixture refuses unimplemented endpoint paths');
putenv('TIKTOK_BUSINESS_TEST_BASE_URL');
echo "TikTok Business policy checks passed: {$checks}\n";
