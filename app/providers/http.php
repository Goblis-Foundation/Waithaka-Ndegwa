<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/response_limits.php';

final class PublishingRemoteException extends RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly bool $ambiguous = false) {
        parent::__construct($message);
    }
}

/** Provider URLs are fixed in adapters. Never forward credentials to arbitrary media URLs. */
function publishing_http(string $method, string $url, array $headers = [], ?array $body = null, bool $form = false, bool $publishingMutation = false): array {
    $allowed = ['graph.threads.net','meet.googleapis.com','verify.twilio.com','gmail.googleapis.com','tasks.googleapis.com','sheets.googleapis.com','zoom.us','api.zoom.us','login.microsoftonline.com','graph.microsoft.com','webexapis.com','api.buffer.com','accounts.google.com','oauth2.googleapis.com','openidconnect.googleapis.com','www.googleapis.com','analyticsadmin.googleapis.com','analyticsdata.googleapis.com','youtube.googleapis.com','youtubeanalytics.googleapis.com','searchconsole.googleapis.com','calendar.googleapis.com','gmailpostmastertools.googleapis.com','pagespeedonline.googleapis.com','chromeuxreport.googleapis.com','generativelanguage.googleapis.com','www.linkedin.com','api.linkedin.com','api.x.com','api.instagram.com','graph.instagram.com','graph.facebook.com','open.tiktokapis.com','business-api.tiktok.com'];
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), $allowed, true) || isset($parts['user']) || isset($parts['port'])) {
        throw new RuntimeException('Provider endpoint is not allowed.');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('The server needs the PHP cURL extension for provider connections.');
    $fixtureUrl=publishing_http_tiktok_fixture_url($url);
    if($fixtureUrl===$url&&function_exists('publishing_http_tiktok_business_fixture_url'))$fixtureUrl=publishing_http_tiktok_business_fixture_url($url);
    $fixture=$fixtureUrl!==$url;
    $responseHeaders = [];$raw='';$headerBytes=0;$oversized=false;
    $ch = curl_init($fixtureUrl);
    $headers[] = 'Accept: application/json';
    $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => $fixture?CURLPROTO_HTTP:CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION => static function($handle,string $chunk)use(&$raw,&$oversized):int{$n=response_append_bounded($raw,$chunk,16*1024*1024);if($n!==strlen($chunk))$oversized=true;return $n;},
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders,&$headerBytes,&$oversized): int {
            $headerBytes+=strlen($line);if($headerBytes>65536){$oversized=true;return 0;}
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
            return strlen($line);
        }];
    if($fixture) $options[CURLOPT_PROXY]='';
    if ($body !== null) {
        $headers[] = $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json';
        $options[CURLOPT_POSTFIELDS] = $form ? http_build_query($body, '', '&', PHP_QUERY_RFC3986) : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $options);
    $received = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlCode = curl_errno($ch);
    $failed = $received === false || $curlCode !== 0;
    curl_close($ch);
    // Do not expose provider bodies: they can echo tokens, media URLs or private account data.
    if($oversized)throw new PublishingRemoteException('The provider response exceeded the safe size limit. Review the remote outcome before another attempt.',0,$publishingMutation);
    if ($failed) throw new PublishingRemoteException($curlCode === CURLE_OPERATION_TIMEDOUT ? 'The provider request timed out. No complete response was received. Check the remote account before another attempt.' : 'The provider did not return a complete response. Check the remote account before another attempt.', 0, $publishingMutation);
    if ($code >= 300) {
        $message = match (true) {
            $code === 401 => 'The provider requires reconnection.',
            $code === 403 => 'The provider denied this action. Check the granted permissions and account eligibility.',
            $code === 429 => 'The provider rate limit was reached. Review the job before retrying.',
            $code >= 500 => 'The provider returned a server error. Check its publication history before retrying.',
            default => 'The provider rejected the request (HTTP '.$code.'). Check the format, account and application settings.'
        };
        if ($code === 403 && in_array(strtolower($parts['host'] ?? ''), ['www.googleapis.com','calendar.googleapis.com','gmail.googleapis.com','generativelanguage.googleapis.com'], true)) $message = publishing_google_denial_message((string)$raw);
        if (strtolower($parts['host'] ?? '') === 'gmailpostmastertools.googleapis.com') $message = publishing_postmaster_error_message((string)$raw, $code);
        throw new PublishingRemoteException($message, $code, $publishingMutation && $code >= 500);
    }
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        if (trim((string)$raw) === '' && $code >= 200 && $code < 300) $data = [];
        else throw new PublishingRemoteException('The provider returned an unreadable response.', $code, $publishingMutation);
    }
    return ['data' => $data, 'headers' => $responseHeaders, 'status' => $code];
}

/** Numeric loopback fixture only; no configurable production API destination. */
function publishing_http_tiktok_fixture_url(string $url): string {
    $base=getenv('TIKTOK_TEST_BASE_URL');
    if(!$base || parse_url($url,PHP_URL_HOST)!=='open.tiktokapis.com') return $url;
    if(app_config('app_env')!=='testing' || !preg_match('~\Ahttp://127\.0\.0\.1:([1-9][0-9]{0,4})\z~',$base,$match) || (int)$match[1]>65535) {
        throw new RuntimeException('TikTok test endpoints are restricted to an explicit testing environment and numeric loopback port.');
    }
    $parts=parse_url($url);
    if(($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['port']) || isset($parts['fragment'])
        || !in_array($parts['path']??'',['/v2/oauth/token/','/v2/user/info/','/v2/video/list/'],true)) throw new RuntimeException('Unsupported TikTok fixture endpoint.');
    return $base.$parts['path'].(isset($parts['query'])?'?'.$parts['query']:'');
}

function publishing_bearer(string $token): array {
    if ($token === '' || preg_match('/[\r\n]/', $token)) throw new RuntimeException('The saved access token is invalid. Reconnect this account.');
    return ['Authorization: Bearer '.$token];
}

function publishing_json(array $data): string {
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function publishing_decode(?string $value): array {
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
}

/** Map only documented machine codes to fixed text; never echo remote bodies or messages. */
function publishing_google_denial_message(string $raw): string {
    $fallback='The provider denied this action. Check the granted permissions and account eligibility.';
    $data=json_decode($raw,true);if(!is_array($data))return $fallback;
    $reasons=[];foreach(($data['error']['errors']??[])as$item)if(is_array($item)&&is_string($item['reason']??null))$reasons[]=$item['reason'];
    foreach(($data['error']['details']??[])as$item)if(is_array($item)&&is_string($item['reason']??null))$reasons[]=$item['reason'];
    foreach($reasons as$reason){$message=match($reason){
        'accessNotConfigured','SERVICE_DISABLED','API_DISABLED'=>'Google denied access because the requested API is disabled or has not been enabled in this app’s Google Cloud project.',
        'insufficientPermissions','ACCESS_TOKEN_SCOPE_INSUFFICIENT'=>'Google denied access because this connection is missing a required permission. Reconnect and approve this feature’s requested permissions.',
        'domainPolicy','ORG_RESTRICTION_VIOLATION'=>'Google denied access because an organization policy restricts this app or service. Ask the account’s Workspace administrator to review the restriction.',
        'dailyLimitExceeded','quotaExceeded','rateLimitExceeded','userRateLimitExceeded','RATE_LIMIT_EXCEEDED'=>'Google denied access because a quota or rate limit was reached. Review the project’s API quota before retrying.',
        default=>null};if($message!==null)return $message;
    }return $fallback;
}

/** Fixed diagnostic vocabulary only: never expose Google messages, metadata or credentials. */
function publishing_postmaster_error_message(string $raw, int $httpStatus): string {
    $prefix = 'Google Postmaster HTTP '.$httpStatus.'. ';
    $data = json_decode($raw, true);
    $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
    $statuses = ['RESOURCE_EXHAUSTED','PERMISSION_DENIED','UNAUTHENTICATED','INVALID_ARGUMENT','NOT_FOUND','UNAVAILABLE','INTERNAL','FAILED_PRECONDITION'];
    if (is_string($error['status'] ?? null) && in_array($error['status'], $statuses, true)) $prefix .= 'Status: '.$error['status'].'. ';
    $messages = [
        'SERVICE_DISABLED'=>'Enable Gmail Postmaster Tools API in the OAuth client project.',
        'API_DISABLED'=>'Enable Gmail Postmaster Tools API in the OAuth client project.',
        'accessNotConfigured'=>'Enable Gmail Postmaster Tools API in the OAuth client project.',
        'ACCESS_TOKEN_SCOPE_INSUFFICIENT'=>'Reconnect with the Postmaster read permission.',
        'insufficientPermissions'=>'Review the granted Postmaster permission and account domain access.',
        'RATE_LIMIT_EXCEEDED'=>'Google reports a rate limit. Check per-user and project request limits before retrying.',
        'rateLimitExceeded'=>'Google reports a rate limit. Check per-user and project request limits before retrying.',
        'userRateLimitExceeded'=>'Google reports a per-user rate limit. Check other clients using this account before retrying.',
        'quotaExceeded'=>'Google reports a quota limit. Inspect this API’s quota metrics in the OAuth client project.',
        'QUOTA_EXCEEDED'=>'Google reports a quota limit. Inspect this API’s quota metrics in the OAuth client project.',
        'dailyLimitExceeded'=>'Google reports a daily quota limit. Check the quota in the OAuth client project.',
        'domainPolicy'=>'Review the account’s Workspace policy for this service.',
        'ORG_RESTRICTION_VIOLATION'=>'Review the account’s Workspace policy for this service.',
        'backendError'=>'Google reports a backend error. Retry later.',
    ];
    foreach (['errors','details'] as $field) {
        if (!is_array($error[$field] ?? null)) continue;
        foreach ($error[$field] as $item) {
            $reason = is_array($item) ? ($item['reason'] ?? null) : null;
            if (is_string($reason) && isset($messages[$reason])) return $prefix.'Reason: '.$reason.'. '.$messages[$reason];
        }
    }
    return $prefix.'Google supplied no recognized safe reason code. The response does not establish an exhausted project quota. Inspect the API error metrics or contact Google support; repeated reconnection is not a diagnosis.';
}
