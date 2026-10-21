<?php
declare(strict_types=1);

/** TikTok API for Business: Accounts API, separate from Login Kit / Display API.
 * Official contracts checked 2026-10-21:
 * https://business-api.tiktok.com/portal/docs/accounts-api-authorization/v1.3
 * https://business-api.tiktok.com/portal/docs/accounts-api-authentication/v1.3
 * https://business-api.tiktok.com/portal/docs/get-the-authorized-tiktok-account-permission-scopes-via-access-token/v1.3
 * https://business-api.tiktok.com/portal/docs/get-profile-data-of-a-tiktok-account/v1.3
 * https://business-api.tiktok.com/portal/docs/get-the-post-privacy-settings-of-a-tiktok-account/v1.3
 * https://business-api.tiktok.com/portal/docs/publish-a-public-video-post-to-an-owned-account/v1.3
 * https://business-api.tiktok.com/portal/docs/get-the-publishing-status-of-a-tiktok-post/v1.3
 * https://business-api.tiktok.com/portal/docs/manage-url-properties/v1.3
 * https://business-api.tiktok.com/portal/docs/get-the-list-of-added-url-properties-under-an-ad-account/v1.3
 * https://business-api.tiktok.com/portal/docs/return-codes-appendix/v1.3
 * App approval, URL ownership verification and actual account grants are external gates.
 */
function publishing_tiktok_business_scopes(): array {
    return ['video.publish','user.info.basic','user.info.username','user.account.type'];
}

function publishing_tiktok_business_granted_scopes(mixed $value): array {
    if(!is_string($value)||strlen($value)>4000) throw new PublishingRemoteException('TikTok Accounts did not return usable granted permissions. Reconnect this account.',401);
    $scopes=array_values(array_unique(array_filter(preg_split('/[ ,]+/',trim($value))?:[])));
    foreach(publishing_tiktok_business_scopes() as $scope) if(!in_array($scope,$scopes,true)) throw new PublishingRemoteException('TikTok Accounts requires video publishing, basic identity, username and account-type permissions. Reauthorize the separate Accounts API app.',401);
    return $scopes;
}

/** The portal generates the account-holder URL. Never invent or accept another host. */
function publishing_tiktok_business_authorization_url(string $state): string {
    if(!preg_match('/\A[a-f0-9]{64}\z/',$state)) throw new RuntimeException('Invalid authorization state.');
    $saved=trim((string)setting('publishing.tiktok_business.authorization_url',''));
    $parts=parse_url($saved);$callback=publishing_callback_url('tiktok_business');$callbackParts=parse_url($callback);
    if(strlen($saved)>8000||!is_array($parts)||($parts['scheme']??'')!=='https'||strtolower($parts['host']??'')!=='www.tiktok.com'
        ||!in_array($parts['path']??'',['/v2/auth/authorize','/v2/auth/authorize/'],true)
        ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['fragment'])) {
        throw new RuntimeException('Save the TikTok account holder authorization URL copied from this API for Business app.');
    }
    if(($callbackParts['scheme']??'')!=='https'||isset($callbackParts['port'])||isset($callbackParts['query'])||isset($callbackParts['fragment'])
        ||!str_ends_with($callback,'/')||strlen($callback)<10||strlen($callback)>512) {
        throw new RuntimeException('TikTok Accounts requires a registered HTTPS callback ending in /, without a port, query or fragment.');
    }
    $params=[];$allowed=['client_key','client_id','response_type','scope','redirect_uri','state','disable_auto_auth'];
    foreach(explode('&',(string)($parts['query']??'')) as $pair) {
        if($pair==='')continue;
        $pieces=explode('=',$pair,2);$key=urldecode($pieces[0]);$value=urldecode($pieces[1]??'');
        if(!in_array($key,$allowed,true)||array_key_exists($key,$params)||preg_match('/[\x00-\x1f\x7f]/',$value)) throw new RuntimeException('The saved TikTok account holder URL contains unsupported or repeated parameters. Copy it again from the app portal.');
        $params[$key]=$value;
    }
    $identityKeys=array_values(array_intersect(['client_key','client_id'],array_keys($params)));
    // The portal's OAuth client key is opaque; token inspection binds the actual App ID.
    if(count($identityKeys)!==1||!preg_match('/\A[A-Za-z0-9._~-]{1,255}\z/',(string)$params[$identityKeys[0]])
        ||($params['response_type']??'')!=='code'||($params['redirect_uri']??'')!==$callback) {
        throw new RuntimeException('The copied TikTok authorization URL must contain one valid client identifier and the exact displayed callback.');
    }
    publishing_tiktok_business_granted_scopes($params['scope']??null);
    // A portal URL can include other approved app scopes; request only those shown here.
    $params['scope']=implode(',',publishing_tiktok_business_scopes());
    $params['state']=$state;$params['disable_auto_auth']='1';
    return 'https://www.tiktok.com'.$parts['path'].'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}

function publishing_tiktok_business_endpoints(): array {
    return [
        '/tt_user/oauth2/token/'=>'POST','/tt_user/oauth2/refresh_token/'=>'POST','/tt_user/oauth2/revoke/'=>'POST',
        '/tt_user/token_info/get/'=>'POST','/business/get/'=>'GET','/business/video/settings/'=>'GET',
        '/business/property/list/'=>'GET','/business/video/publish/'=>'POST','/business/publish/status/'=>'GET',
    ];
}

/** HTTP 200 can contain an error. Do not expose bodies, tokens or secret-bearing URLs. */
function publishing_tiktok_business_data(array $envelope,bool $mutation=false): array {
    if(!is_int($envelope['code']??null)) throw new PublishingRemoteException('TikTok Accounts returned an incomplete response.',0,$mutation);
    // Exact documented credential/app/code failures; 40100, for example, is rate limiting.
    if(in_array($envelope['code'],[40101,40102,40103,40104,40105,40107,40110,40113,40115,40131],true)) {
        // Authentication-looking mutation responses still cannot prove no side effect.
        throw new PublishingRemoteException('TikTok Accounts requires renewed authorization. Check the separate app credentials and reconnect the intended account.',401,$mutation);
    }
    if($envelope['code']!==0) throw new PublishingRemoteException('TikTok Accounts did not confirm this request. Check the app approval, account permissions and existing task before retrying.',400,$mutation);
    if(!is_array($envelope['data']??null)) throw new PublishingRemoteException('TikTok Accounts returned incomplete operation data.',0,$mutation);
    return $envelope['data'];
}

function publishing_tiktok_business_request(string $method,string $path,array $parameters=[],?string $token=null,bool $mutation=false): array {
    if((publishing_tiktok_business_endpoints()[$path]??null)!==$method) throw new LogicException('Unsupported TikTok Accounts operation.');
    $headers=[];
    if($token!==null) {
        if($token===''||strlen($token)>8192||preg_match('/[\x00-\x20\x7f]/',$token)) throw new PublishingRemoteException('The saved TikTok Accounts access token is invalid. Reconnect.',401);
        $headers[]='Access-Token: '.$token;
    }
    $url='https://business-api.tiktok.com/open_api/v1.3'.$path;
    if($method==='GET'&&$parameters) $url.='?'.http_build_query($parameters,'','&',PHP_QUERY_RFC3986);
    $response=publishing_http($method,$url,$headers,$method==='GET'?null:$parameters,false,$mutation);
    return publishing_tiktok_business_data($response['data'],$mutation);
}

/** Numeric loopback, exact operations, explicit testing environment; never a production override. */
function publishing_http_tiktok_business_fixture_url(string $url): string {
    $base=getenv('TIKTOK_BUSINESS_TEST_BASE_URL');
    if(!$base||parse_url($url,PHP_URL_HOST)!=='business-api.tiktok.com')return $url;
    if(app_config('app_env')!=='testing'||!preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~',$base,$match)||(int)$match[1]>65535) {
        throw new RuntimeException('TikTok Accounts test endpoints require an explicit testing environment and numeric loopback port.');
    }
    $parts=parse_url($url);$path=(string)($parts['path']??'');$prefix='/open_api/v1.3';
    if(($parts['scheme']??'')!=='https'||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['fragment'])
        ||!str_starts_with($path,$prefix)||!array_key_exists(substr($path,strlen($prefix)),publishing_tiktok_business_endpoints())) throw new RuntimeException('Unsupported TikTok Accounts fixture endpoint.');
    return $base.$path.(isset($parts['query'])?'?'.$parts['query']:'');
}

function publishing_tiktok_business_validate_token(array $token,?string $expectedId=null): array {
    foreach(['access_token','refresh_token','open_id'] as $key) {
        if(!is_string($token[$key]??null)||$token[$key]===''||strlen($token[$key])>($key==='open_id'?255:8192)||preg_match('/[\x00-\x20\x7f]/',$token[$key])) throw new PublishingRemoteException('TikTok Accounts returned incomplete authorization credentials.',401);
    }
    if(strtolower((string)($token['token_type']??''))!=='bearer') throw new PublishingRemoteException('TikTok Accounts returned an unsupported token type.',401);
    if($expectedId!==null&&!hash_equals($expectedId,$token['open_id'])) throw new PublishingRemoteException('The TikTok account changed during authorization renewal. Reconnect the intended account.',401);
    publishing_tiktok_business_granted_scopes($token['scope']??null);
    foreach(['expires_in'=>86400,'refresh_token_expires_in'=>366*86400] as $key=>$maximum) {
        if(!is_int($token[$key]??null)||$token[$key]<1||$token[$key]>$maximum) throw new PublishingRemoteException('TikTok Accounts returned an invalid token lifetime.',401);
    }
    $token['expires_at']=time()+$token['expires_in'];
    $token['refresh_expires_at']=time()+$token['refresh_token_expires_in'];
    unset($token['refresh_state'],$token['refresh_started_at']);
    return $token;
}

function publishing_tiktok_business_token_request(array $body,?string $expectedId=null): array {
    $grant=$body['grant_type']??'';
    $path=match($grant){'authorization_code'=>'/tt_user/oauth2/token/','refresh_token'=>'/tt_user/oauth2/refresh_token/',default=>throw new RuntimeException('Unsupported TikTok Accounts grant.')};
    foreach(['client_id','client_secret',$grant==='authorization_code'?'auth_code':'refresh_token'] as $key) {
        if(!is_string($body[$key]??null)||$body[$key]===''||strlen($body[$key])>8192||preg_match('/[\x00-\x20\x7f]/',$body[$key])) throw new RuntimeException('TikTok Accounts authorization data is incomplete.');
    }
    if($grant==='authorization_code'&&($body['redirect_uri']??null)!==publishing_callback_url('tiktok_business')) throw new RuntimeException('TikTok Accounts callback does not match this application.');
    $token=publishing_tiktok_business_validate_token(publishing_tiktok_business_request('POST',$path,$body,null,true),$expectedId);
    $token['client_id']=$body['client_id'];
    return $token;
}

function publishing_tiktok_business_inspect(string $access,string $expectedId): array {
    $client=publishing_client('tiktok_business');
    if($client['client_id']==='') throw new PublishingRemoteException('Configure the separate TikTok Accounts app first.',401);
    $data=publishing_tiktok_business_request('POST','/tt_user/token_info/get/',['app_id'=>$client['client_id'],'access_token'=>$access]);
    if(!is_string($data['app_id']??null)||!hash_equals($client['client_id'],$data['app_id'])
        ||!is_string($data['creator_id']??null)||$expectedId===''||!hash_equals($expectedId,$data['creator_id'])) throw new PublishingRemoteException('TikTok Accounts returned a different app or account identity. Reconnect and confirm the intended account.',401);
    return ['app_id'=>$data['app_id'],'creator_id'=>$data['creator_id'],'scopes'=>publishing_tiktok_business_granted_scopes($data['scope']??null)];
}

function publishing_tiktok_business_profile(array $data,string $expectedId): array {
    if($expectedId===''||strlen($expectedId)>255) throw new PublishingRemoteException('TikTok Accounts identity is missing.',401);
    if(isset($data['open_id'])&&$data['open_id']!==$expectedId) throw new PublishingRemoteException('TikTok Accounts profile does not match the authorized account.',401);
    $username=$data['username']??null;$name=$data['display_name']??null;
    if(!is_string($username)||!preg_match('/\A[A-Za-z0-9._]{1,64}\z/',$username)||!is_string($name)||trim($name)===''
        ||!is_bool($data['is_business_account']??null)||!is_bool($data['has_advanced_access']??null)) throw new PublishingRemoteException('TikTok Accounts did not return the requested profile and account-type information.',401);
    return ['id'=>$expectedId,'username'=>$username,'display_name'=>mb_substr($name,0,255),'profile_url'=>'https://www.tiktok.com/@'.$username,
        'is_business_account'=>$data['is_business_account'],'has_advanced_access'=>$data['has_advanced_access']];
}

function publishing_tiktok_business_validate_settings(array $settings): array {
    $options=$settings['privacy_level_options']??null;$maximum=$settings['max_video_post_duration_sec']??null;
    if(!is_array($options)||!array_is_list($options)||!$options||count($options)>10||array_filter($options,static fn($v)=>!is_string($v))
        ||(!is_int($maximum)&&!is_float($maximum))||!is_finite((float)$maximum)||$maximum<3||$maximum>86400) throw new PublishingRemoteException('TikTok Accounts returned incomplete publishing settings.');
    foreach(['comment_disabled','duet_disabled','stitch_disabled'] as $key) if(!is_bool($settings[$key]??null)) throw new PublishingRemoteException('TikTok Accounts did not confirm the current interaction settings.');
    return ['privacy_level_options'=>array_values(array_unique($options)),'comment_disabled'=>$settings['comment_disabled'],
        'duet_disabled'=>$settings['duet_disabled'],'stitch_disabled'=>$settings['stitch_disabled'],'max_video_post_duration_sec'=>$maximum];
}

function publishing_tiktok_business_observe(string $access,string $id): array {
    $inspection=publishing_tiktok_business_inspect($access,$id);
    $profile=publishing_tiktok_business_profile(publishing_tiktok_business_request('GET','/business/get/',[
        'business_id'=>$id,'fields'=>publishing_json(['display_name','username','is_business_account','has_advanced_access'])],$access),$id);
    $settings=publishing_tiktok_business_validate_settings(publishing_tiktok_business_request('GET','/business/video/settings/',['business_id'=>$id],$access));
    return ['inspection'=>$inspection,'profile'=>$profile,'settings'=>$settings];
}

function publishing_tiktok_business_identity(array &$token): array {
    if(!is_string($token['open_id']??null)||!is_string($token['access_token']??null)) throw new PublishingRemoteException('TikTok Accounts did not return authorization credentials.',401);
    $client=publishing_client('tiktok_business');
    if(($token['client_id']??null)!==$client['client_id']) throw new PublishingRemoteException('TikTok Accounts credentials belong to a different app.',401);
    $observed=publishing_tiktok_business_observe($token['access_token'],$token['open_id']);$profile=$observed['profile'];
    $token['scope']=implode(',',$observed['inspection']['scopes']);
    $name=mb_substr($profile['display_name'].' @'.$profile['username'],0,255);
    return ['id'=>$profile['id'],'name'=>$name,'resources'=>[['id'=>$profile['id'],'name'=>$name,'type'=>'tiktok_account','url'=>$profile['profile_url']]],
        'metadata'=>['accounts_api'=>true,'app_id'=>$observed['inspection']['app_id'],'username'=>$profile['username'],'profile_url'=>$profile['profile_url'],
            'is_business_account'=>$profile['is_business_account'],'has_advanced_access'=>$profile['has_advanced_access'],'settings'=>$observed['settings']]];
}

/** Pure capability display. Every delivery rechecks the grant, identity and settings. */
function publishing_tiktok_business_connection_ready(?array $connection): bool {
    if(!$connection||($connection['service']??'')!=='tiktok_business'||($connection['provider']??'')!=='tiktok_business'
        ||($connection['status']??'')!=='connected'||empty($connection['external_id'])||($connection['selected_resource']??null)!==$connection['external_id'])return false;
    try {
        publishing_tiktok_business_granted_scopes(implode(',',publishing_decode($connection['scopes_json']??null)));
        $metadata=publishing_decode($connection['metadata_json']??null);
        if(($metadata['accounts_api']??false)!==true||!is_string($metadata['app_id']??null)||$metadata['app_id']==='')return false;
        $settings=publishing_tiktok_business_validate_settings(is_array($metadata['settings']??null)?$metadata['settings']:[]);
        if(!in_array('PUBLIC_TO_EVERYONE',$settings['privacy_level_options'],true))return false;
        foreach(publishing_decode($connection['resources_json']??null) as $resource) if(is_array($resource)&&($resource['id']??null)===$connection['selected_resource']&&($resource['type']??'')==='tiktok_account') return true;
    } catch(Throwable $error) { return false; }
    return false;
}

/** Refresh rotation is serialized and durably checkpointed before the exchange. */
function publishing_tiktok_business_access_token(array $connection): string {
    if(($connection['service']??'')!=='tiktok_business'||($connection['provider']??'')!=='tiktok_business'||($connection['status']??'')!=='connected'
        ||empty($connection['id'])||empty($connection['external_id'])||($connection['selected_resource']??null)!==$connection['external_id']) throw new PublishingRemoteException('Connect and confirm the separate TikTok Accounts destination first.',401);
    $pdo=db();$lock='waithaka_tiktok_business_refresh_'.(int)$connection['id'];
    $st=$pdo->prepare('SELECT GET_LOCK(?,5)');$st->execute([$lock]);
    if((int)$st->fetchColumn()!==1)throw new PublishingRemoteException('TikTok Accounts access is being renewed. Try again shortly.');
    try {
        $st=$pdo->prepare('SELECT * FROM publishing_connections WHERE id=?');$st->execute([$connection['id']]);$current=$st->fetch();
        if(!$current||$current['service']!=='tiktok_business'||$current['provider']!=='tiktok_business'||$current['status']!=='connected'
            ||$current['external_id']!==$connection['external_id']||$current['selected_resource']!==$connection['selected_resource']) throw new PublishingRemoteException('The TikTok Accounts connection changed. Reconnect the intended account.',401);
        $credentials=publishing_credentials($current);$client=publishing_client('tiktok_business');
        if(!empty($credentials['refresh_state'])) {
            $pdo->prepare("UPDATE publishing_connections SET status='reconnect' WHERE id=? AND status='connected' AND BINARY credentials_encrypted=BINARY ?")->execute([$current['id'],$current['credentials_encrypted']]);
            throw new PublishingRemoteException('A previous TikTok Accounts renewal has an uncertain result. Reauthorize the app; its old refresh token will not be replayed.',401);
        }
        if($client['client_id']===''||$client['client_secret']===''||($credentials['client_id']??null)!==$client['client_id']||($credentials['open_id']??null)!==$current['external_id']
            ||!is_string($credentials['access_token']??null)||$credentials['access_token']===''||!is_int($credentials['expires_at']??null)) throw new PublishingRemoteException('TikTok Accounts credentials no longer match the configured app and account. Reconnect.',401);
        publishing_tiktok_business_granted_scopes($credentials['scope']??null);
        if($credentials['expires_at']>time()+120)return $credentials['access_token'];
        if(!is_string($credentials['refresh_token']??null)||$credentials['refresh_token']===''||(int)($credentials['refresh_expires_at']??0)<=time())throw new PublishingRemoteException('TikTok Accounts authorization has expired. Reauthorize this app.',401);
        $checkpoint=$credentials;$checkpoint['refresh_state']='in_progress';$checkpoint['refresh_started_at']=gmdate('c');$checkpointEncrypted=encrypt_secret(publishing_json($checkpoint));
        $st=$pdo->prepare("UPDATE publishing_connections SET credentials_encrypted=? WHERE id=? AND status='connected' AND external_id=? AND selected_resource=? AND BINARY credentials_encrypted=BINARY ?");
        $st->execute([$checkpointEncrypted,$current['id'],$current['external_id'],$current['selected_resource'],$current['credentials_encrypted']]);
        if($st->rowCount()!==1)throw new PublishingRemoteException('TikTok Accounts changed before renewal. Reconnect.',401);
        try {
            $replacement=publishing_tiktok_business_token_request($client+['grant_type'=>'refresh_token','refresh_token'=>$credentials['refresh_token']],$current['external_id']);
            $inspection=publishing_tiktok_business_inspect($replacement['access_token'],$current['external_id']);$replacement['scope']=implode(',',$inspection['scopes']);
            $st=$pdo->prepare("UPDATE publishing_connections SET credentials_encrypted=?,scopes_json=? WHERE id=? AND status='connected' AND external_id=? AND selected_resource=? AND BINARY credentials_encrypted=BINARY ?");
            $st->execute([encrypt_secret(publishing_json($replacement)),publishing_json($inspection['scopes']),$current['id'],$current['external_id'],$current['selected_resource'],$checkpointEncrypted]);
            if($st->rowCount()!==1)throw new PublishingRemoteException('TikTok Accounts changed during renewal. Reconnect.',401);
            return $replacement['access_token'];
        } catch(Throwable $error) {
            $pdo->prepare("UPDATE publishing_connections SET status='reconnect' WHERE id=? AND status='connected' AND BINARY credentials_encrypted=BINARY ?")->execute([$current['id'],$checkpointEncrypted]);
            throw new PublishingRemoteException('TikTok Accounts renewal did not complete safely. Reauthorize; the previous refresh token will not be retried.',401);
        }
    } finally {$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);}
}

/** Explicit provider-side revocation helper; local disconnect does not invoke it silently. */
function publishing_tiktok_business_revoke(array $connection): void {
    if(($connection['provider']??'')!=='tiktok_business'||($connection['service']??'')!=='tiktok_business')throw new RuntimeException('Choose a TikTok Accounts connection.');
    $credentials=publishing_credentials($connection);$client=publishing_client('tiktok_business');
    if(($credentials['client_id']??null)!==$client['client_id']||empty($credentials['access_token']))throw new RuntimeException('The saved TikTok Accounts credentials do not match this app.');
    publishing_tiktok_business_request('POST','/tt_user/oauth2/revoke/',$client+['access_token'=>$credentials['access_token']],null,true);
}

/** Only provider-verified domain or HTTPS-prefix boundaries authorize public media. */
function publishing_tiktok_business_property_matches(string $url,array $properties): bool {
    if(!publishing_public_url($url))return false;
    $asset=parse_url($url);
    if(isset($asset['query'])||isset($asset['fragment'])||preg_match('~\\\\|%2e|%2f|%5c|(?:^|/)\.\.?(?:/|$)~i',(string)($asset['path']??'')))return false;
    $host=strtolower($asset['host']);$path=$asset['path']??'/';
    foreach($properties as $property) {
        if(!is_array($property)||($property['property_status']??null)!==1||!is_string($property['url']??null))continue;
        $value=$property['url'];
        if(($property['property_type']??null)===1) {
            if(str_contains($value,'://')) {
                $parts=parse_url($value);
                if(!is_array($parts)||!in_array($parts['scheme']??'',['https','http'],true)||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($parts['path']??'',['','/'],true))continue;
                $value=$parts['host']??'';
            }
            $domain=strtolower($value);
            if(!preg_match('/\A(?=.{1,253}\z)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.[a-z]{2,63}\z/',$domain)||str_contains($domain,'..'))continue;
            if($host===$domain||str_ends_with($host,'.'.$domain))return true;
        } elseif(($property['property_type']??null)===2) {
            if(!publishing_public_url($value)||!str_ends_with($value,'/'))continue;
            $prefix=parse_url($value);
            if(isset($prefix['query'])||isset($prefix['fragment'])||preg_match('~\\\\|%2e|%2f|%5c|(?:^|/)\.\.?(?:/|$)~i',(string)($prefix['path']??'')))continue;
            if($host===strtolower($prefix['host'])&&str_starts_with($path,$prefix['path']??'/'))return true;
        }
    }
    return false;
}

function publishing_tiktok_business_validate_variant(array $variant): void {
    if(($variant['privacy']??null)!=='public'||($variant['tiktok_settings_confirmed']??false)!==true)throw new RuntimeException('Confirm public TikTok publication and review the account settings and content rights before approval.');
    foreach(['ai_generated','tiktok_allow_comments','tiktok_allow_duet','tiktok_allow_stitch','tiktok_brand_organic','tiktok_branded_content'] as $key) {
        if(!is_bool($variant[$key]??null))throw new RuntimeException('Review each TikTok interaction and content-disclosure choice explicitly.');
    }
    if($variant['tiktok_brand_organic']&&$variant['tiktok_branded_content'])throw new RuntimeException('Choose either promotion of your own business or a paid partnership for this post, not both.');
    if(!in_array($variant['format']??'',['post','video'],true)||($variant['media_type']??'')!=='video')throw new RuntimeException('Native TikTok Accounts delivery supports a public video post.');
    $text=$variant['text']??null;
    if(!is_string($text)||!mb_check_encoding($text,'UTF-8')||strlen(mb_convert_encoding($text,'UTF-16LE','UTF-8'))>4400)throw new RuntimeException('TikTok captions must be no longer than 2,200 UTF-16 units.');
    if(preg_match_all('/(?<![A-Za-z0-9_])@[A-Za-z0-9._]+/u',$text)>30)throw new RuntimeException('TikTok captions may contain no more than 30 mentions.');
    $asset=$variant['media_asset']??null;$url=$variant['media_url']??null;
    if(!is_array($asset)||!is_int($asset['id']??null)||$asset['id']<1||(int)($variant['media_asset_id']??0)!==$asset['id']
        ||($asset['visibility']??'')!=='public'||!is_string($url)||$url===''||($asset['public_url']??null)!==$url||!publishing_public_url($url)
        ||!in_array($asset['mime']??'',['video/mp4','video/quicktime','video/webm'],true)||!is_int($asset['bytes']??null)||$asset['bytes']<1||$asset['bytes']>512*1024*1024
        ||!is_string($asset['sha256']??null)||!preg_match('/\A[a-f0-9]{64}\z/',$asset['sha256'])) throw new RuntimeException('Choose an approved public video from the owned media library, up to 512 MiB. An arbitrary URL or private file cannot use this route.');
}

function publishing_tiktok_business_validate_live_settings(array $variant,array $settings): void {
    $settings=publishing_tiktok_business_validate_settings($settings);
    if(!in_array('PUBLIC_TO_EVERYONE',$settings['privacy_level_options'],true))throw new PublishingRemoteException('The confirmed TikTok account does not currently permit public publication.',422);
    foreach(['comments'=>'comment','duet'=>'duet','stitch'=>'stitch'] as $flag=>$key) {
        if(($variant['tiktok_allow_'.$flag]??false)===true&&$settings[$key.'_disabled'])throw new PublishingRemoteException('TikTok no longer permits an interaction enabled in this approved post. Review new settings and approve a new revision.',422);
    }
}

function publishing_tiktok_business_publish_body(string $openId,array $variant): array {
    publishing_tiktok_business_validate_variant($variant);
    if($openId===''||strlen($openId)>255||preg_match('/[\x00-\x20\x7f]/',$openId))throw new RuntimeException('A verified TikTok account identifier is required.');
    return ['business_id'=>$openId,'video_url'=>$variant['media_url'],'post_info'=>[
        'caption'=>$variant['text'],'is_brand_organic'=>$variant['tiktok_brand_organic'],'is_branded_content'=>$variant['tiktok_branded_content'],
        'disable_comment'=>!$variant['tiktok_allow_comments'],'disable_duet'=>!$variant['tiktok_allow_duet'],'disable_stitch'=>!$variant['tiktok_allow_stitch'],
        'is_ai_generated'=>$variant['ai_generated'],'upload_to_draft'=>false,'is_ads_only'=>false,
    ]];
}

function publishing_tiktok_business_preflight(array $connection,array $variant): array {
    try {
    publishing_tiktok_business_validate_variant($variant);
    $access=publishing_tiktok_business_access_token($connection);$observed=publishing_tiktok_business_observe($access,(string)$connection['external_id']);
    publishing_tiktok_business_validate_live_settings($variant,$observed['settings']);
    $client=publishing_client('tiktok_business');
    $properties=publishing_tiktok_business_request('GET','/business/property/list/',['app_id'=>$client['client_id'],'secret'=>$client['client_secret']]);
    if(!is_array($properties['url_property_info_list']??null)||!array_is_list($properties['url_property_info_list'])
        ||!publishing_tiktok_business_property_matches($variant['media_url'],$properties['url_property_info_list']))throw new PublishingRemoteException('Verify ownership of this public media domain or URL prefix in the TikTok API for Business app before publishing.',422);
    if(!function_exists('media_library_validate_public_asset'))throw new RuntimeException('Owned public media verification is unavailable.');
    try {media_library_validate_public_asset($variant['media_asset'],512*1024*1024);}
    catch(Throwable $error) {
        // SDK exceptions may contain signed object URLs or request headers.
        throw new PublishingRemoteException('The owned public video could not be verified against its approved bytes and current media configuration. Review the asset and storage before another attempt.',422);
    }
    return ['access_token'=>$access,'account'=>$observed['profile'],'settings'=>$observed['settings'],'body'=>publishing_tiktok_business_publish_body($connection['external_id'],$variant)];
    } catch(PublishingRemoteException $error) {throw $error;}
    catch(Throwable $error) {
        // This boundary finishes before the durable publish checkpoint or mutation.
        throw new PublishingRemoteException('TikTok preflight could not verify the approved video, settings and current account configuration. Review them before another attempt.',422);
    }
}

function publishing_tiktok_business_publish_id(mixed $id): bool {
    return is_string($id)&&(bool)preg_match('/\A[A-Za-z0-9_~.-]{1,255}\z/',$id);
}

function publishing_tiktok_business_can_resume(array $job): bool {
    $destination=publishing_decode($job['destination_json']??null);$state=publishing_decode($job['provider_state_json']??null);
    return ($destination['slug']??'')==='tiktok'&&($destination['method']??'')==='direct'&&($destination['service']??'')==='tiktok_business'
        &&in_array($state['phase']??'',['accepted','failed','complete'],true)&&publishing_tiktok_business_publish_id($state['publish_id']??null)
        &&is_string($state['open_id']??null)&&$state['open_id']!==''&&($destination['resource']??null)===$state['open_id']
        &&($destination['connection_external_id']??null)===$state['open_id']
        &&is_string($state['destination_hash']??null)&&hash_equals(hash('sha256',(string)$job['destination_json']),$state['destination_hash'])
        &&(empty($job['remote_id'])||$job['remote_id']===$state['publish_id']);
}

function publishing_tiktok_business_job_destination(array $job,array $connection): array {
    $destination=publishing_decode($job['destination_json']??null);
    if(($destination['slug']??'')!=='tiktok'||($destination['method']??'')!=='direct'||($destination['service']??'')!=='tiktok_business'
        ||($connection['service']??'')!=='tiktok_business'||($connection['provider']??'')!=='tiktok_business'
        ||(int)($destination['connection_id']??0)!==(int)($connection['id']??0)
        ||($destination['connection_external_id']??null)!==($connection['external_id']??null)
        ||($destination['resource']??null)!==($connection['selected_resource']??null)||empty($destination['resource']))throw new PublishingRemoteException('This job no longer matches its approved TikTok Accounts destination.',401);
    return $destination;
}

/** Accepted tasks never become published until their public post IDs are returned. */
function publishing_tiktok_business_publication_result(array $data,string $publishId,string $username,array $state=[]): array {
    if(!publishing_tiktok_business_publish_id($publishId)||!preg_match('/\A[A-Za-z0-9._]{1,64}\z/',$username))throw new PublishingRemoteException('The TikTok publishing task identity is incomplete.',0,true);
    $status=$data['status']??null;
    if(!is_string($status)||!in_array($status,['PROCESSING_DOWNLOAD','PUBLISH_COMPLETE','FAILED','SEND_TO_USER_INBOX'],true))throw new PublishingRemoteException('TikTok did not return a recognized publishing state. Review the existing remote task.',0,true);
    $state['publish_id']=$publishId;$state['username']=$username;$state['provider_status']=$status;$state['phase']='accepted';
    $fields=['status'=>'delivered','remote_id'=>$publishId,'external_url'=>null,'last_error'=>null,'next_check_at'=>gmdate('Y-m-d H:i:s',time()+180)];
    if($status==='SEND_TO_USER_INBOX')throw new PublishingRemoteException('TikTok returned a draft-inbox result for an approved public post. Review the existing task; do not submit it again.',0,true);
    if($status==='FAILED') {
        $state['phase']='failed';$fields['status']='failed';$fields['last_error']='TikTok reports that this publishing task failed. Review its media format, duration and account settings before approving a new attempt.';$fields['next_check_at']=null;
    } elseif($status==='PUBLISH_COMPLETE') {
        $ids=$data['post_ids']??[];
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>20)throw new PublishingRemoteException('TikTok returned malformed public post identifiers. Review the existing task.',0,true);
        foreach($ids as $id)if(!is_string($id)||!preg_match('/\A[0-9]{1,30}\z/',$id))throw new PublishingRemoteException('TikTok returned an invalid public post identifier. Review the existing task.',0,true);
        if($ids) {
            $state['phase']='complete';$state['post_ids']=$ids;$fields['status']='published';$fields['external_url']='https://www.tiktok.com/@'.$username.'/video/'.$ids[0];$fields['next_check_at']=null;
        }
    }
    $fields['provider_state_json']=publishing_json($state);
    return $fields;
}

function publishing_tiktok_business_deliver(array $job,array $snapshot,array $connection): array {
    $destination=publishing_tiktok_business_job_destination($job,$connection);
    if(publishing_tiktok_business_can_resume($job))return publishing_tiktok_business_check($job,$connection);
    if(publishing_decode($job['provider_state_json']??null))throw new PublishingRemoteException('A previous TikTok publishing attempt has no safely resumable task ID. Inspect the account before approving another post.',0,true);
    if(isset($job['content_hash'])&&(!is_string($job['content_hash'])||!hash_equals($job['content_hash'],hash('sha256',publishing_json($snapshot)))))throw new PublishingRemoteException('The approved TikTok content integrity check failed.',422);
    $variant=$snapshot['variants']['tiktok']??null;
    if(!is_array($variant))throw new PublishingRemoteException('The approved TikTok variant is missing.',422);
    $prepared=publishing_tiktok_business_preflight($connection,$variant);
    $state=['phase'=>'publish_attempted','open_id'=>$destination['resource'],'username'=>$prepared['account']['username'],
        'destination_hash'=>hash('sha256',(string)$job['destination_json']),'started_at'=>gmdate('c')];
    publishing_job_update((int)$job['id'],['provider_state_json'=>publishing_json($state)]);
    $result=publishing_tiktok_business_request('POST','/business/video/publish/',$prepared['body'],$prepared['access_token'],true);
    if(!publishing_tiktok_business_publish_id($result['share_id']??null))throw new PublishingRemoteException('TikTok did not confirm the publishing task ID. Check the account before another attempt.',0,true);
    $state['phase']='accepted';$state['publish_id']=$result['share_id'];$state['provider_status']='ACCEPTED';
    $fields=['status'=>'delivered','remote_id'=>$result['share_id'],'external_url'=>null,'provider_state_json'=>publishing_json($state),'last_error'=>null,'next_check_at'=>gmdate('Y-m-d H:i:s',time()+180)];
    // Preserve the known acknowledgement immediately; the worker also saves these fields.
    try {publishing_job_update((int)$job['id'],$fields);} catch(Throwable $error) {error_log('TikTok acknowledged a task; its delivery checkpoint needs reconciliation.');}
    return $fields;
}

function publishing_tiktok_business_check(array $job,array $connection): array {
    publishing_tiktok_business_job_destination($job,$connection);
    if(!publishing_tiktok_business_can_resume($job))throw new PublishingRemoteException('The TikTok task has no safely resumable publishing ID. Review its outcome before another publication.',0,true);
    $state=publishing_decode($job['provider_state_json']);$access=publishing_tiktok_business_access_token($connection);
    // Inspect the current grant and queried owner again; account labels never establish ownership.
    publishing_tiktok_business_inspect($access,$state['open_id']);
    $profile=publishing_tiktok_business_profile(publishing_tiktok_business_request('GET','/business/get/',[
        'business_id'=>$state['open_id'],'fields'=>publishing_json(['display_name','username','is_business_account','has_advanced_access'])],$access),$state['open_id']);
    $data=publishing_tiktok_business_request('GET','/business/publish/status/',['business_id'=>$state['open_id'],'publish_id'=>$state['publish_id']],$access);
    return publishing_tiktok_business_publication_result($data,$state['publish_id'],$profile['username'],$state);
}
