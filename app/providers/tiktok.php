<?php
declare(strict_types=1);

/** TikTok Login Kit / Display API only. No publishing grant is requested here.
 * Official contracts checked 2026-10-21:
 * https://developers.tiktok.com/docs/en/login-kit-web
 * https://developers.tiktok.com/docs/en/oauth-user-access-token-management
 * https://developers.tiktok.com/docs/en/tiktok-api-v2-get-user-info
 * https://developers.tiktok.com/docs/en/tiktok-api-v2-video-list
 * https://developers.tiktok.com/docs/en/tiktok-api-v2-video-object
 * Approval of Login Kit, Display API and the requested scopes is external to this application.
 */
function publishing_tiktok_scopes(): array {
    return ['user.info.basic','user.info.profile','user.info.stats','video.list'];
}

function publishing_tiktok_granted_scopes(mixed $value): array {
    if (!is_string($value) || strlen($value)>2000) throw new PublishingRemoteException('TikTok did not return the granted permissions. Reconnect this account.',401);
    $scopes=array_values(array_unique(array_filter(preg_split('/[ ,]+/',trim($value)) ?: [])));
    foreach(publishing_tiktok_scopes() as $scope) {
        if(!in_array($scope,$scopes,true)) throw new PublishingRemoteException('TikTok read access requires profile, statistics and public-video permissions. Reconnect and review the requested permissions.',401);
    }
    return $scopes;
}

/** Do not substitute old rotating refresh credentials when the response is incomplete. */
function publishing_tiktok_validate_token(array $token, ?string $expectedId=null): array {
    if(isset($token['error'])) throw new PublishingRemoteException('TikTok did not grant or renew access. Reconnect this account.',401);
    foreach(['access_token','refresh_token','open_id'] as $key) {
        if(!is_string($token[$key]??null) || $token[$key]==='' || strlen($token[$key])>($key==='open_id'?255:8192) || preg_match('/[\x00-\x20\x7f]/',$token[$key])) {
            throw new PublishingRemoteException('TikTok returned incomplete authorization credentials. Reconnect this account.',401);
        }
    }
    if(strtolower((string)($token['token_type']??''))!=='bearer') throw new PublishingRemoteException('TikTok returned an unsupported authorization type.',401);
    if($expectedId!==null && !hash_equals($expectedId,$token['open_id'])) throw new PublishingRemoteException('TikTok returned a different account during authorization renewal. Reconnect and confirm the intended account.',401);
    publishing_tiktok_granted_scopes($token['scope']??null);
    foreach(['expires_in','refresh_expires_in'] as $key) {
        if(!is_int($token[$key]??null) || $token[$key]<=0 || $token[$key]>366*86400) throw new PublishingRemoteException('TikTok returned an invalid authorization lifetime. Reconnect this account.',401);
    }
    $token['expires_at']=time()+$token['expires_in'];
    $token['refresh_expires_at']=time()+$token['refresh_expires_in'];
    unset($token['refresh_state'],$token['refresh_started_at']);
    return $token;
}

function publishing_tiktok_token_request(array $body, ?string $expectedId=null): array {
    $response=publishing_http('POST','https://open.tiktokapis.com/v2/oauth/token/',[],$body,true,true)['data'];
    return publishing_tiktok_validate_token($response,$expectedId);
}

/** TikTok may return an API error in a successful HTTP response. Never treat it as data. */
function publishing_tiktok_data(array $response): array {
    $error=$response['error']??null;
    if(!is_array($error) || !is_string($error['code']??null)) throw new PublishingRemoteException('TikTok returned an incomplete API response.');
    if($error['code']!=='ok') {
        $code=match($error['code']) {
            'access_token_invalid','access_token_expired','invalid_token','invalid_grant'=>401,
            'scope_not_authorized'=>403,
            'rate_limit_exceeded'=>429,
            default=>400,
        };
        // Provider messages and log IDs can contain private material; show a fixed explanation.
        throw new PublishingRemoteException(match($code) {
            401=>'TikTok requires reconnection before the report can be loaded.',
            403=>'TikTok did not grant the permission needed for this report.',
            429=>'TikTok has limited report requests. Try again later.',
            default=>'TikTok could not return this report. Check the app approval and granted permissions.',
        },$code);
    }
    if(!is_array($response['data']??null)) throw new PublishingRemoteException('TikTok returned an incomplete API response.');
    return $response['data'];
}

function publishing_tiktok_profile(array $user, string $expectedId): array {
    $id=$user['open_id']??null;
    $username=$user['username']??null;
    if(!is_string($id) || $id==='' || !hash_equals($expectedId,$id)) throw new PublishingRemoteException('TikTok returned a different account from the authorized identity. Reconnect and confirm the intended account.',401);
    if(!is_string($username) || !preg_match('/\A[A-Za-z0-9._]{1,64}\z/',$username)) throw new PublishingRemoteException('TikTok did not return a usable account username. Check the profile permission.',401);
    $name=$user['display_name']??null;
    if(!is_string($name) || trim($name)==='') throw new PublishingRemoteException('TikTok did not return a usable account name.');
    return ['id'=>$id,'username'=>$username,'display_name'=>mb_substr($name,0,255),
        'profile_url'=>'https://www.tiktok.com/@'.$username,
        'is_verified'=>is_bool($user['is_verified']??null)?$user['is_verified']:null];
}

function publishing_tiktok_user(string $accessToken, string $expectedId): array {
    $fields='open_id,display_name,username,profile_deep_link,is_verified,follower_count,following_count,likes_count,video_count';
    $data=publishing_tiktok_data(publishing_http('GET','https://open.tiktokapis.com/v2/user/info/?fields='.$fields,publishing_bearer($accessToken))['data']);
    if(!is_array($data['user']??null)) throw new PublishingRemoteException('TikTok did not return the authorized account.');
    publishing_tiktok_profile($data['user'],$expectedId);
    return $data['user'];
}

function publishing_tiktok_identity(array $token): array {
    $id=(string)($token['open_id']??'');
    if($id==='') throw new PublishingRemoteException('TikTok did not return an authorized account identifier.',401);
    $account=publishing_tiktok_profile(publishing_tiktok_user((string)$token['access_token'],$id),$id);
    $name=$account['display_name'].' @'.$account['username'];
    return ['id'=>$id,'name'=>mb_substr($name,0,255),
        'resources'=>[['id'=>$id,'name'=>mb_substr($name,0,255),'type'=>'tiktok_profile','url'=>$account['profile_url']]],
        'metadata'=>['username'=>$account['username'],'profile_url'=>$account['profile_url'],'is_verified_badge'=>$account['is_verified'],
            'account_type_verified'=>false,'read_only'=>true]];
}

/** Refresh is serialized, uses the current row, and cannot overwrite a reconnection.
 * A durable checkpoint precedes the token exchange. A crash/lost reply after rotation
 * requires renewed consent instead of replaying a refresh token with unknown validity.
 */
function publishing_tiktok_access_token(array $connection): string {
    if(($connection['service']??'')!=='tiktok_read' || ($connection['provider']??'')!=='tiktok' || ($connection['status']??'')!=='connected'
        || empty($connection['id']) || empty($connection['external_id']) || ($connection['selected_resource']??null)!==$connection['external_id']) {
        throw new PublishingRemoteException('Connect TikTok read access and confirm the returned account first.',401);
    }
    $pdo=db();$lock='waithaka_tiktok_refresh_'.(int)$connection['id'];
    $st=$pdo->prepare('SELECT GET_LOCK(?,5)');$st->execute([$lock]);
    if((int)$st->fetchColumn()!==1) throw new PublishingRemoteException('TikTok access is being renewed by another request. Try the report again shortly.');
    try {
        $st=$pdo->prepare('SELECT * FROM publishing_connections WHERE id=?');$st->execute([$connection['id']]);$current=$st->fetch();
        if(!$current || $current['service']!=='tiktok_read' || $current['provider']!=='tiktok' || $current['status']!=='connected'
            || !hash_equals((string)$connection['external_id'],(string)$current['external_id']) || $current['selected_resource']!==$connection['selected_resource']) {
            throw new PublishingRemoteException('The TikTok connection changed. Reload it and confirm the intended account.',401);
        }
        $credentials=publishing_credentials($current);
        if(!empty($credentials['refresh_state'])) {
            $pdo->prepare("UPDATE publishing_connections SET status='reconnect' WHERE id=? AND status='connected' AND BINARY credentials_encrypted=BINARY ?")
                ->execute([$current['id'],$current['credentials_encrypted']]);
            throw new PublishingRemoteException('A previous TikTok authorization renewal has an uncertain result. Reconnect this account before reading reports.',401);
        }
        if(!is_string($credentials['open_id']??null) || !hash_equals((string)$current['external_id'],$credentials['open_id'])) throw new PublishingRemoteException('The saved TikTok identity is inconsistent. Reconnect this account.',401);
        publishing_tiktok_granted_scopes($credentials['scope']??null);
        if(empty($credentials['access_token']) || !is_int($credentials['expires_at']??null)) throw new PublishingRemoteException('TikTok access is incomplete. Reconnect this account.',401);
        if($credentials['expires_at']>time()+120) return (string)$credentials['access_token'];
        if(!is_string($credentials['refresh_token']??null) || $credentials['refresh_token']==='' || (int)($credentials['refresh_expires_at']??0)<=time()) {
            throw new PublishingRemoteException('TikTok authorization has expired. Reconnect this account.',401);
        }
        $client=publishing_client('tiktok');
        if($client['client_id']==='' || $client['client_secret']==='') throw new PublishingRemoteException('Save the TikTok client key and secret before renewing access.',401);
        $checkpoint=$credentials;$checkpoint['refresh_state']='in_progress';$checkpoint['refresh_started_at']=gmdate('c');
        $checkpointEncrypted=encrypt_secret(publishing_json($checkpoint));
        $st=$pdo->prepare("UPDATE publishing_connections SET credentials_encrypted=? WHERE id=? AND status='connected' AND external_id=? AND selected_resource=? AND BINARY credentials_encrypted=BINARY ?");
        $st->execute([$checkpointEncrypted,$current['id'],$current['external_id'],$current['selected_resource'],$current['credentials_encrypted']]);
        if($st->rowCount()!==1) throw new PublishingRemoteException('The TikTok connection changed before renewal. Reload the connection.',401);
        try {
            $updated=publishing_tiktok_token_request(['client_key'=>$client['client_id'],'client_secret'=>$client['client_secret'],
                'grant_type'=>'refresh_token','refresh_token'=>$credentials['refresh_token']],(string)$current['external_id']);
            $replacement=array_merge($credentials,$updated);
            unset($replacement['refresh_state'],$replacement['refresh_started_at']);
            $st=$pdo->prepare("UPDATE publishing_connections SET credentials_encrypted=?,scopes_json=? WHERE id=? AND status='connected' AND external_id=? AND selected_resource=? AND BINARY credentials_encrypted=BINARY ?");
            $st->execute([encrypt_secret(publishing_json($replacement)),publishing_json(publishing_tiktok_granted_scopes($updated['scope'])),$current['id'],$current['external_id'],$current['selected_resource'],$checkpointEncrypted]);
            if($st->rowCount()!==1) throw new PublishingRemoteException('The TikTok connection changed during renewal. Reload and reconnect if needed.',401);
            return (string)$replacement['access_token'];
        } catch(Throwable $e) {
            // Only touch the exact checkpoint created by this request. Never undo a later reconnect.
            $st=$pdo->prepare("UPDATE publishing_connections SET status='reconnect' WHERE id=? AND status='connected' AND BINARY credentials_encrypted=BINARY ?");
            $st->execute([$current['id'],$checkpointEncrypted]);
            throw new PublishingRemoteException('TikTok authorization renewal did not complete safely. Reconnect this account; the previous refresh token will not be retried.',401);
        }
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
}

/** Missing or malformed counters are unknown, never a fabricated zero. */
function publishing_tiktok_count(mixed $value): ?int {
    return is_int($value) && $value>=0 ? $value : null;
}

function publishing_tiktok_video_row(array $video): array {
    $id=$video['id']??null;
    if(!is_string($id) || !preg_match('/\A[0-9]{1,30}\z/',$id)) throw new PublishingRemoteException('TikTok returned a video without a usable identifier.');
    $url='';$parts=is_string($video['share_url']??null)?parse_url($video['share_url']):false;
    if(is_array($parts) && ($parts['scheme']??'')==='https' && in_array(strtolower($parts['host']??''),['www.tiktok.com','tiktok.com'],true)
        && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['fragment'])
        && preg_match('~\A/@[A-Za-z0-9._]{1,64}/video/'.preg_quote($id,'~').'/?\z~',$parts['path']??'')) {
        $url='https://www.tiktok.com'.$parts['path'];
    }
    $row=['id'=>$id,'title'=>is_string($video['title']??null)?mb_substr($video['title'],0,500):'',
        'description'=>is_string($video['video_description']??null)?mb_substr($video['video_description'],0,2000):'',
        'create_time'=>is_int($video['create_time']??null)&&$video['create_time']>0?$video['create_time']:null,'share_url'=>$url];
    foreach(['view_count','like_count','comment_count','share_count'] as $key) $row[$key]=publishing_tiktok_count($video[$key]??null);
    return $row;
}

function publishing_tiktok_report(array $connection): array {
    $access=publishing_tiktok_access_token($connection);
    $user=publishing_tiktok_user($access,(string)$connection['external_id']);
    $account=publishing_tiktok_profile($user,(string)$connection['selected_resource']);
    $summary=[];foreach(['follower_count','following_count','likes_count','video_count'] as $key) $summary[$key]=publishing_tiktok_count($user[$key]??null);
    $fields='id,title,video_description,create_time,share_url,view_count,like_count,comment_count,share_count';
    $data=publishing_tiktok_data(publishing_http('POST','https://open.tiktokapis.com/v2/video/list/?fields='.$fields,publishing_bearer($access),['max_count'=>20])['data']);
    if(!is_array($data['videos']??null) || !array_is_list($data['videos']) || count($data['videos'])>20 || !is_bool($data['has_more']??null)) throw new PublishingRemoteException('TikTok returned an incomplete public-video report.');
    $rows=[];foreach($data['videos'] as $video) {
        if(!is_array($video)) throw new PublishingRemoteException('TikTok returned an incomplete public-video report.');
        $rows[]=publishing_tiktok_video_row($video);
    }
    return ['service'=>'tiktok_read','account'=>$account,'summary'=>$summary,'rows'=>$rows,'has_more'=>$data['has_more'],
        'observed_at'=>gmdate('c'),'note'=>'Current profile totals and up to 20 recent public videos returned by TikTok. Counters are cumulative snapshots, not a period-based analytics report. Missing counters are unavailable. The identity badge does not establish a Business account type or publishing eligibility.'];
}
