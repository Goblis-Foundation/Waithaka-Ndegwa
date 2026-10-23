<?php
declare(strict_types=1);

require_once __DIR__.'/tiktok.php';
require_once __DIR__.'/tiktok_business.php';

/** Official endpoint references, checked 2026-10-21:
 * https://developers.google.com/identity/protocols/oauth2/web-server
 * https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/sign-in-with-linkedin-v2
 * https://docs.x.com/fundamentals/authentication/oauth-2-0/user-access-token
 * https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-instagram-login/business-login
 * https://developers.facebook.com/documentation/pages-api/getting-started
 * https://developers.facebook.com/documentation/facebook-login/guides/access-tokens/get-long-lived
 */
function publishing_services(): array {
    $identity = ['openid', 'profile', 'email'];
    return [
        'google_search_console' => ['name'=>'Google Search Console', 'provider'=>'google', 'scopes'=>array_merge($identity,['https://www.googleapis.com/auth/webmasters.readonly']), 'description'=>'Read search performance for a site you select. This does not verify ownership or submit URLs.'],
        'google_analytics' => ['name'=>'Google Analytics', 'provider'=>'google', 'scopes'=>array_merge($identity,['https://www.googleapis.com/auth/analytics.readonly']), 'description'=>'Read an existing GA4 property. Adding the site measurement tag is a separate setting.'],
        'google_youtube_read' => ['name'=>'YouTube read access', 'provider'=>'google', 'scopes'=>array_merge($identity,['https://www.googleapis.com/auth/youtube.readonly']), 'description'=>'Read your selected channel and video information. No publishing permission.'],
        'google_youtube_upload' => ['name'=>'YouTube upload access', 'provider'=>'google', 'scopes'=>array_merge($identity,['https://www.googleapis.com/auth/youtube.readonly','https://www.googleapis.com/auth/youtube.upload']), 'description'=>'Separate upload grant for resumable video delivery from your media library. Confirm the channel, choose Direct API, and approve visibility, audience and altered-content disclosure for each video. Upload completion and YouTube processing are tracked separately.'],
        'instagram' => ['name'=>'Instagram professional', 'provider'=>'instagram', 'scopes'=>['instagram_business_basic','instagram_business_content_publish'], 'description'=>'Instagram Login for a Business or Creator account. Direct delivery currently supports one JPEG image; use Buffer or assisted publishing for other formats.'],
        'facebook_pages' => ['name'=>'Facebook Page discovery', 'provider'=>'facebook', 'scopes'=>['pages_show_list','pages_read_engagement','pages_manage_posts'], 'description'=>'Discover Pages you manage, then choose a real Page. This cannot publish to your professional-mode profile.'],
        'linkedin_analytics' => ['name'=>'LinkedIn member analytics','provider'=>'linkedin','scopes'=>['openid','profile','r_member_postAnalytics'],'description'=>'Separate approved analytics product and member consent. Read aggregate post impressions, reach and reactions; this does not grant publishing permission.'],
        'linkedin' => ['name'=>'LinkedIn member', 'provider'=>'linkedin', 'scopes'=>['openid','profile','w_member_social'], 'description'=>'Requires Sign In with LinkedIn using OpenID Connect and Share on LinkedIn products. Direct delivery supports text and links; use Buffer for media.'],
        'x' => ['name'=>'X professional account', 'provider'=>'x', 'scopes'=>['tweet.read','tweet.write','users.read','offline.access'], 'description'=>'User OAuth with PKCE. Direct text/link posts use the X API and may incur usage charges. Set your spending limit in X.'],
        'tiktok_read' => ['name'=>'TikTok read access', 'provider'=>'tiktok', 'scopes'=>publishing_tiktok_scopes(), 'description'=>'Login Kit and Display API approval are required. Verify and confirm your TikTok identity, then read profile totals and up to 20 recent public videos. This grant does not permit posting or verify a Business account type.'],
        'tiktok_business' => ['name'=>'TikTok Accounts publishing', 'provider'=>'tiktok_business', 'scopes'=>publishing_tiktok_business_scopes(), 'description'=>'Separate API for Business app approval and account-holder authorization. Confirms the actual account and its current settings before native public video delivery from your verified owned media domain. Professional labeling and the read-only grant do not authorize this route.'],
        'buffer' => ['name'=>'Buffer', 'provider'=>'buffer', 'scopes'=>[], 'description'=>'Connect a personal API key, then select the returned channels. Channel features and publishing rights still depend on the platform and your plan.'],
    ];
}

function publishing_connection(string $service): ?array {
    $st = db()->prepare('SELECT * FROM publishing_connections WHERE service=?');
    $st->execute([$service]);
    return $st->fetch() ?: null;
}

function publishing_credentials(array $connection): array {
    return publishing_decode(decrypt_secret((string)($connection['credentials_encrypted'] ?? '')));
}

function publishing_client(string $provider): array {
    return ['client_id'=>trim((string)setting('publishing.'.$provider.'.client_id','')), 'client_secret'=>trim((string)setting('publishing.'.$provider.'.client_secret',''))];
}

function publishing_callback_url(string $service): string {
    $callback = url('/write/connections/callback/'.$service.($service==='tiktok_business'?'/':''));
    $host = strtolower((string)parse_url($callback, PHP_URL_HOST));
    $local = in_array($host, ['localhost','127.0.0.1','[::1]'], true);
    if (parse_url($callback, PHP_URL_SCHEME) !== 'https' && !($local && app_config('app_env') !== 'production')) {
        throw new RuntimeException('Configure the canonical HTTPS site address before connecting a provider.');
    }
    return $callback;
}

function publishing_meta_version(): string {
    $v = (string)setting('publishing.meta.version','v24.0');
    if (!preg_match('/^v\d{2}\.0$/', $v)) throw new RuntimeException('Enter a valid Meta API version, such as v24.0.');
    return $v;
}

function publishing_oauth_start(string $service, int $userId): string {
    $definition = publishing_services()[$service] ?? null;
    if (!$definition || $service === 'buffer') throw new RuntimeException('This connection does not use this OAuth flow.');
    $provider = $definition['provider'];
    $client = publishing_client($provider);
    if ($client['client_id'] === '' || $client['client_secret'] === '') throw new RuntimeException('Save this provider’s client ID and client secret first.');
    $state = bin2hex(random_bytes(32));
    $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    db()->prepare('DELETE FROM publishing_oauth_states WHERE expires_at < UTC_TIMESTAMP()')->execute();
    db()->prepare('INSERT INTO publishing_oauth_states(state_hash,service,user_id,session_hash,verifier_encrypted,expires_at) VALUES(?,?,?,?,?,?)')
        ->execute([hash('sha256',$state),$service,$userId,hash('sha256',session_id()),encrypt_secret($verifier),gmdate('Y-m-d H:i:s',time()+600)]);
    if($provider==='tiktok_business')return publishing_tiktok_business_authorization_url($state);
    $params = ['response_type'=>'code','client_id'=>$client['client_id'],'redirect_uri'=>publishing_callback_url($service),'state'=>$state,'scope'=>implode(' ',$definition['scopes'])];
    if (in_array($provider,['google','x'],true)) {
        $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256',$verifier,true)), '+/', '-_'), '=');
        $params['code_challenge_method'] = 'S256';
    }
    $endpoint = match ($provider) {
        'google'=>'https://accounts.google.com/o/oauth2/v2/auth',
        'linkedin'=>'https://www.linkedin.com/oauth/v2/authorization',
        'x'=>'https://x.com/i/oauth2/authorize',
        'instagram'=>'https://www.instagram.com/oauth/authorize',
        'facebook'=>'https://www.facebook.com/'.publishing_meta_version().'/dialog/oauth',
        'tiktok'=>'https://www.tiktok.com/v2/auth/authorize/',
        default=>throw new RuntimeException('Unknown OAuth provider.')
    };
    if ($provider === 'google') {
        $params += ['access_type'=>'offline','prompt'=>'consent select_account','include_granted_scopes'=>'false'];
    } elseif ($provider === 'instagram') {
        $params['scope'] = implode(',',$definition['scopes']);
        $params += ['enable_fb_login'=>'0','force_authentication'=>'1'];
    } elseif ($provider === 'facebook') {
        $params['scope'] = implode(',',$definition['scopes']);
        $params['auth_type'] = 'rerequest';
    } elseif ($provider === 'tiktok') {
        $callback=parse_url($params['redirect_uri']);
        if(($callback['scheme']??'')!=='https' || isset($callback['query']) || isset($callback['fragment']) || strlen($params['redirect_uri'])>=512) throw new RuntimeException('TikTok Login Kit requires a registered, static HTTPS callback address.');
        $params['client_key']=$params['client_id'];unset($params['client_id']);
        $params['scope']=implode(',',$definition['scopes']);
        $params['disable_auto_auth']=1;
    }
    return $endpoint.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}

function publishing_consume_state(string $service, string $state, int $userId): array {
    if (!preg_match('/^[a-f0-9]{64}$/',$state)) throw new RuntimeException('This authorization link is invalid or expired. Start again.');
    db()->beginTransaction();
    try {
        $st = db()->prepare('SELECT * FROM publishing_oauth_states WHERE state_hash=? FOR UPDATE');
        $st->execute([hash('sha256',$state)]);
        $row = $st->fetch();
        if (!$row || $row['consumed_at'] || $row['expires_at'] < gmdate('Y-m-d H:i:s') || $row['service'] !== $service || (int)$row['user_id'] !== $userId || !hash_equals($row['session_hash'],hash('sha256',session_id()))) {
            throw new RuntimeException('This authorization belongs to another session or has expired. Start again.');
        }
        db()->prepare('UPDATE publishing_oauth_states SET consumed_at=UTC_TIMESTAMP() WHERE state_hash=?')->execute([$row['state_hash']]);
        db()->commit();
        return $row;
    } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); throw $e; }
}

function publishing_oauth_complete(string $service, string $code, array $state, int $userId): void {
    $definition = publishing_services()[$service];
    $provider = $definition['provider'];
    $client = publishing_client($provider);
    if($provider==='tiktok_business') {
        $token=publishing_tiktok_business_token_request($client+['grant_type'=>'authorization_code','auth_code'=>$code,'redirect_uri'=>publishing_callback_url($service)]);
        $observed=publishing_tiktok_business_identity($token);
        publishing_save_observed_connection($service,$token,publishing_tiktok_business_granted_scopes($token['scope']),$observed,$userId);
        return;
    }
    if ($provider === 'tiktok') {
        $token=publishing_tiktok_token_request(['client_key'=>$client['client_id'],'client_secret'=>$client['client_secret'],
            'grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>publishing_callback_url($service)]);
        $observed=publishing_tiktok_identity($token);
        publishing_save_observed_connection($service,$token,publishing_tiktok_granted_scopes($token['scope']),$observed,$userId);
        return;
    }
    $body = $client + ['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>publishing_callback_url($service)];
    $headers = [];
    if (in_array($provider,['google','x'],true)) $body['code_verifier'] = decrypt_secret((string)$state['verifier_encrypted']);
    if ($provider === 'x') {
        $headers[] = 'Authorization: Basic '.base64_encode($client['client_id'].':'.$client['client_secret']);
        unset($body['client_secret']);
    }
    $endpoint = match ($provider) {
        'google'=>'https://oauth2.googleapis.com/token',
        'linkedin'=>'https://www.linkedin.com/oauth/v2/accessToken',
        'x'=>'https://api.x.com/2/oauth2/token',
        'instagram'=>'https://api.instagram.com/oauth/access_token',
        'facebook'=>'https://graph.facebook.com/'.publishing_meta_version().'/oauth/access_token',
    };
    $token = publishing_http('POST',$endpoint,$headers,$body,true)['data'];
    if (empty($token['access_token'])) throw new RuntimeException('The provider did not grant an access token.');
    if ($provider === 'instagram') {
        $long = publishing_http('GET','https://graph.instagram.com/access_token?'.http_build_query(['grant_type'=>'ig_exchange_token','client_secret'=>$client['client_secret'],'access_token'=>$token['access_token']],'','&',PHP_QUERY_RFC3986))['data'];
        if (empty($long['access_token'])) throw new RuntimeException('Instagram did not return a long-lived token.');
        $token = array_merge($token,$long);
    } elseif ($provider === 'facebook') {
        $long=publishing_http('GET','https://graph.facebook.com/'.publishing_meta_version().'/oauth/access_token?'.http_build_query($client+['grant_type'=>'fb_exchange_token','fb_exchange_token'=>$token['access_token']],'','&',PHP_QUERY_RFC3986))['data'];
        if(empty($long['access_token']))throw new RuntimeException('Facebook did not return a long-lived token.');
        $token=array_merge($token,$long);
    }
    $token['expires_at'] = !empty($token['expires_in']) ? time() + (int)$token['expires_in'] : null;
    $scopes = isset($token['scope']) ? preg_split('/[ ,]+/',trim((string)$token['scope'])) : $definition['scopes'];
    if ($provider === 'instagram' && !empty($token['permissions']) && is_array($token['permissions'])) $scopes = $token['permissions'];
    // OAuth 2.0: an omitted scope response means the requested scope is unchanged.
    // We still verify account/resource access by calling its provider below.
    $required = array_values(array_filter($definition['scopes'],static fn($s)=>!in_array($s,['openid','profile','email','offline.access'],true)));
    foreach ($required as $scope) if (!in_array($scope,$scopes,true)) throw new RuntimeException('A required permission was declined. Start the connection again when you want to enable this feature.');
    $observed = publishing_provider_identity($service,$token);
    publishing_save_observed_connection($service,$token,$scopes,$observed,$userId);
}

function publishing_provider_identity(string $service, array &$token): array {
    if($service==='tiktok_read') return publishing_tiktok_identity($token);
    if($service==='tiktok_business')return publishing_tiktok_business_identity($token);
    $access = publishing_bearer((string)$token['access_token']);
    $provider = publishing_services()[$service]['provider'];
    $resources = [];
    $metadata = [];
    if ($provider === 'google') {
        $who = publishing_http('GET','https://openidconnect.googleapis.com/v1/userinfo',$access)['data'];
        $id = (string)($who['sub'] ?? '');
        $name = trim((string)($who['name'] ?? '').' · '.(string)($who['email'] ?? ''));
        if ($service === 'google_search_console') {
            $response = publishing_http('GET','https://www.googleapis.com/webmasters/v3/sites',$access)['data'];
            foreach ($response['siteEntry'] ?? [] as $site) {
                if (($site['permissionLevel'] ?? '') !== 'siteUnverifiedUser') $resources[] = ['id'=>$site['siteUrl'],'name'=>$site['siteUrl'],'type'=>'site'];
            }
        } elseif ($service === 'google_analytics') {
            $page = '';
            for ($i=0;$i<10;$i++) {
                $response = publishing_http('GET','https://analyticsadmin.googleapis.com/v1beta/accountSummaries?pageSize=200'.($page !== ''?'&pageToken='.rawurlencode($page):''),$access)['data'];
                foreach ($response['accountSummaries'] ?? [] as $account) foreach ($account['propertySummaries'] ?? [] as $property) $resources[] = ['id'=>$property['property'],'name'=>$property['displayName'] ?? $property['property'],'type'=>'ga4_property'];
                $page = (string)($response['nextPageToken'] ?? ''); if ($page === '') break;
            }
        } else {
            $response = publishing_http('GET','https://www.googleapis.com/youtube/v3/channels?part=id,snippet&mine=true&maxResults=50',$access)['data'];
            foreach ($response['items'] ?? [] as $channel) $resources[] = ['id'=>$channel['id'],'name'=>$channel['snippet']['title'],'type'=>'youtube_channel','url'=>'https://www.youtube.com/channel/'.$channel['id']];
        }
    } elseif ($provider === 'linkedin') {
        $who = publishing_http('GET','https://api.linkedin.com/v2/userinfo',$access)['data'];
        $id = (string)($who['sub'] ?? ''); $name = (string)($who['name'] ?? 'LinkedIn member');
        $resources[] = ['id'=>$id,'name'=>$name,'type'=>'member'];
    } elseif ($provider === 'x') {
        $who = publishing_http('GET','https://api.x.com/2/users/me?user.fields=name,username',$access)['data']['data'] ?? [];
        $id = (string)($who['id'] ?? ''); $name = (string)($who['name'] ?? '').' @'.(string)($who['username'] ?? '');
        $resources[] = ['id'=>$id,'name'=>$name,'type'=>'professional','url'=>'https://x.com/'.rawurlencode((string)($who['username'] ?? ''))];
    } elseif ($provider === 'instagram') {
        $who = publishing_http('GET','https://graph.instagram.com/'.publishing_meta_version().'/me?fields=id,user_id,username,account_type',$access)['data'];
        $id = (string)($who['user_id'] ?? $who['id'] ?? ''); $name = '@'.(string)($who['username'] ?? '');
        $accountType = strtoupper((string)($who['account_type'] ?? ''));
        if (!in_array($accountType,['BUSINESS','CREATOR','MEDIA_CREATOR'],true)) throw new RuntimeException('Instagram did not return a Business or Creator account. Keep this channel assisted until the correct account is authorized.');
        $metadata['account_type'] = strtolower($accountType);
        $resources[] = ['id'=>$id,'name'=>$name,'type'=>'professional','url'=>'https://www.instagram.com/'.rawurlencode((string)($who['username'] ?? ''))];
    } elseif ($provider === 'facebook') {
        $base = 'https://graph.facebook.com/'.publishing_meta_version();
        $who = publishing_http('GET',$base.'/me?fields=id,name',$access)['data'];
        $id = (string)($who['id'] ?? ''); $name = (string)($who['name'] ?? 'Facebook user');
        $permissions = publishing_http('GET',$base.'/me/permissions',$access)['data']['data'] ?? [];
        $granted = array_column(array_filter($permissions,static fn($p)=>($p['status']??'')==='granted'),'permission');
        foreach (publishing_services()[$service]['scopes'] as $permission) if (!in_array($permission,$granted,true)) throw new RuntimeException('Facebook Page access is missing a required permission.');
        $response = publishing_http('GET',$base.'/me/accounts?fields=id,name,access_token,tasks,link&limit=100',$access)['data'];
        $token['page_tokens'] = [];
        foreach ($response['data'] ?? [] as $page) {
            if (empty($page['access_token']) || !array_intersect(['CREATE_CONTENT','MANAGE'], $page['tasks'] ?? [])) continue;
            $resources[] = ['id'=>$page['id'],'name'=>$page['name'],'type'=>'page','url'=>$page['link'] ?? 'https://www.facebook.com/'.$page['id']];
            $token['page_tokens'][(string)$page['id']] = $page['access_token'];
        }
        $metadata['pagination_note'] = !empty($response['paging']['next']) ? 'The first 100 Pages are shown. Use a provider integration if your Page is outside this list.' : '';
    } else throw new RuntimeException('Unsupported connection provider.');
    if ($id === '' || strlen($id) > 255) throw new RuntimeException('The provider did not return a usable account identity.');
    return ['id'=>$id,'name'=>mb_substr($name,0,255),'resources'=>$resources,'metadata'=>$metadata];
}

function publishing_save_observed_connection(string $service, array $credentials, array $scopes, array $observed, int $userId): void {
    $provider = publishing_services()[$service]['provider'];
    $st = db()->prepare("INSERT INTO publishing_connections(service,provider,status,external_id,display_name,credentials_encrypted,scopes_json,resources_json,metadata_json,verified_at,connected_by) VALUES(?,?,'pending_confirmation',?,?,?,?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE provider=VALUES(provider),status='pending_confirmation',external_id=VALUES(external_id),display_name=VALUES(display_name),credentials_encrypted=VALUES(credentials_encrypted),scopes_json=VALUES(scopes_json),resources_json=VALUES(resources_json),metadata_json=VALUES(metadata_json),selected_resource=NULL,verified_at=UTC_TIMESTAMP(),connected_by=VALUES(connected_by)");
    $st->execute([$service,$provider,$observed['id'],$observed['name'],encrypt_secret(publishing_json($credentials)),publishing_json(array_values($scopes)),publishing_json($observed['resources']),publishing_json($observed['metadata'] ?? []),$userId]);
    publishing_event(null,null,'connection_observed',publishing_services()[$service]['name'].' returned an account. Destination confirmation is pending.',$userId);
}

function publishing_confirm_connection(string $service, string $resource, int $userId): void {
    $connection = publishing_connection($service);
    if (!$connection || $connection['status'] !== 'pending_confirmation') throw new RuntimeException('Connect this service before choosing its destination.');
    $found = null;
    foreach (publishing_decode($connection['resources_json']) as $item) if ((string)$item['id'] === $resource) { $found = $item; break; }
    if (!$found) throw new RuntimeException('Choose a resource returned by the connected provider.');
    if ($service === 'facebook_pages') {
        $channel = publishing_channel('facebook');
        if ($channel['account_type'] !== 'page' || ($found['type'] ?? '') !== 'page') throw new RuntimeException('A real Facebook Page is required for this connection.');
    }
    $st=db()->prepare("UPDATE publishing_connections SET status='connected',selected_resource=?,connected_by=? WHERE id=? AND status='pending_confirmation' AND external_id=? AND verified_at=?");
    $st->execute([$resource,$userId,$connection['id'],$connection['external_id'],$connection['verified_at']]);
    if($st->rowCount()!==1)throw new RuntimeException('The observed connection changed. Reload it and review the returned destination again.');
    publishing_event(null,null,'connection_confirmed',publishing_services()[$service]['name'].' destination confirmed: '.mb_substr($found['name'],0,200),$userId);
}

function publishing_access_token(array $connection): string {
    if(($connection['provider']??'')==='tiktok') return publishing_tiktok_access_token($connection);
    if(($connection['provider']??'')==='tiktok_business')return publishing_tiktok_business_access_token($connection);
    if ($connection['status'] !== 'connected') throw new PublishingRemoteException('Reconnect and confirm the destination before publishing.',401);
    $credentials = publishing_credentials($connection);
    if (empty($credentials['access_token'])) throw new PublishingRemoteException('Reconnect this account to restore access.',401);
    $expires = $credentials['expires_at'] ?? null;
    if ($expires && (int)$expires <= time()+120) {
        $provider = $connection['provider'];
        $client = publishing_client($provider);
        $refresh = (string)($credentials['refresh_token'] ?? '');
        if ($refresh === '' || !in_array($provider,['google','x'],true)) throw new PublishingRemoteException('This access token has expired. Reconnect this account.',401);
        $body = $client + ['grant_type'=>'refresh_token','refresh_token'=>$refresh];
        $headers = [];
        if ($provider === 'x') { $headers[]='Authorization: Basic '.base64_encode($client['client_id'].':'.$client['client_secret']); unset($body['client_secret']); }
        try {
            $updated = publishing_http('POST',$provider==='google'?'https://oauth2.googleapis.com/token':'https://api.x.com/2/oauth2/token',$headers,$body,true)['data'];
        } catch (PublishingRemoteException $e) {
            if (in_array($e->httpStatus,[400,401,403],true)) throw new PublishingRemoteException('The authorization could not be refreshed. Reconnect this account.',401);
            throw $e;
        }
        if (empty($updated['access_token'])) throw new PublishingRemoteException('The provider did not refresh access. Reconnect this account.',401);
        $credentials = array_merge($credentials,$updated);
        $credentials['expires_at'] = time() + (int)($updated['expires_in'] ?? 3600);
        db()->prepare('UPDATE publishing_connections SET credentials_encrypted=? WHERE id=? AND external_id=?')->execute([encrypt_secret(publishing_json($credentials)),$connection['id'],$connection['external_id']]);
    }
    if ($connection['service'] === 'facebook_pages') {
        $pageToken = $credentials['page_tokens'][$connection['selected_resource']] ?? '';
        if ($pageToken === '') throw new PublishingRemoteException('Reconnect Facebook and select the Page again.',401);
        return $pageToken;
    }
    return (string)$credentials['access_token'];
}

function publishing_google_report(array $connection): array {
    if (($connection['provider']??'')!=='google'||($connection['status']??'')!=='connected'||empty($connection['selected_resource'])) throw new RuntimeException('Connect the relevant Google service and confirm its site, property or channel before loading a report.');
    $headers = publishing_bearer(publishing_access_token($connection));
    $resource = (string)$connection['selected_resource'];
    if ($resource === '') throw new RuntimeException('Choose a Google resource first.');
    return match ($connection['service']) {
        'google_search_console' => publishing_http('POST','https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($resource).'/searchAnalytics/query',$headers,['startDate'=>gmdate('Y-m-d',strtotime('-30 days')),'endDate'=>gmdate('Y-m-d',strtotime('-3 days')),'dimensions'=>['page'],'rowLimit'=>20])['data'],
        'google_analytics' => preg_match('~^properties/\d+$~',$resource) ? publishing_http('POST','https://analyticsdata.googleapis.com/v1beta/'.$resource.':runReport',$headers,['dateRanges'=>[['startDate'=>'28daysAgo','endDate'=>'yesterday']],'metrics'=>[['name'=>'activeUsers'],['name'=>'sessions'],['name'=>'screenPageViews']],'dimensions'=>[['name'=>'sessionDefaultChannelGroup']],'limit'=>20])['data'] : throw new RuntimeException('The selected GA4 property is invalid.'),
        'google_youtube_read','google_youtube_upload' => publishing_http('GET','https://www.googleapis.com/youtube/v3/channels?part=snippet,statistics&id='.rawurlencode($resource),$headers)['data'],
        default=>throw new RuntimeException('A report is not available for this connection.')
    };
}
