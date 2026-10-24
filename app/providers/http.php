<?php
declare(strict_types=1);

final class PublishingRemoteException extends RuntimeException {
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly bool $ambiguous = false) {
        parent::__construct($message);
    }
}

/** Provider URLs are fixed in adapters. Never forward credentials to arbitrary media URLs. */
function publishing_http(string $method, string $url, array $headers = [], ?array $body = null, bool $form = false, bool $publishingMutation = false): array {
    $allowed = ['gmail.googleapis.com','zoom.us','api.zoom.us','login.microsoftonline.com','graph.microsoft.com','webexapis.com','api.buffer.com','accounts.google.com','oauth2.googleapis.com','openidconnect.googleapis.com','www.googleapis.com','analyticsadmin.googleapis.com','analyticsdata.googleapis.com','youtube.googleapis.com','youtubeanalytics.googleapis.com','searchconsole.googleapis.com','calendar.googleapis.com','gmailpostmastertools.googleapis.com','pagespeedonline.googleapis.com','chromeuxreport.googleapis.com','generativelanguage.googleapis.com','www.linkedin.com','api.linkedin.com','api.x.com','api.instagram.com','graph.instagram.com','graph.facebook.com','open.tiktokapis.com','business-api.tiktok.com'];
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), $allowed, true) || isset($parts['user']) || isset($parts['port'])) {
        throw new RuntimeException('Provider endpoint is not allowed.');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('The server needs the PHP cURL extension for provider connections.');
    $fixtureUrl=publishing_http_tiktok_fixture_url($url);
    if($fixtureUrl===$url&&function_exists('publishing_http_tiktok_business_fixture_url'))$fixtureUrl=publishing_http_tiktok_business_fixture_url($url);
    $fixture=$fixtureUrl!==$url;
    $responseHeaders = [];
    $ch = curl_init($fixtureUrl);
    $headers[] = 'Accept: application/json';
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => $fixture?CURLPROTO_HTTP:CURLPROTO_HTTPS,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
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
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlCode = curl_errno($ch);
    $failed = $raw === false || $curlCode !== 0;
    curl_close($ch);
    // Do not expose provider bodies: they can echo tokens, media URLs or private account data.
    if ($failed) throw new PublishingRemoteException($curlCode === CURLE_OPERATION_TIMEDOUT ? 'The provider request timed out. No complete response was received. Check the remote account before another attempt.' : 'The provider did not return a complete response. Check the remote account before another attempt.', 0, $publishingMutation);
    if ($code >= 300) {
        $message = match (true) {
            $code === 401 => 'The provider requires reconnection.',
            $code === 403 => 'The provider denied this action. Check the granted permissions and account eligibility.',
            $code === 429 => 'The provider rate limit was reached. Review the job before retrying.',
            $code >= 500 => 'The provider returned a server error. Check its publication history before retrying.',
            default => 'The provider rejected the request (HTTP '.$code.'). Check the format, account and application settings.'
        };
        if ($code === 403 && in_array(strtolower($parts['host'] ?? ''), ['www.googleapis.com','calendar.googleapis.com','generativelanguage.googleapis.com'], true)) $message = publishing_google_denial_message((string)$raw);
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
        'insufficientPermissions','ACCESS_TOKEN_SCOPE_INSUFFICIENT'=>'Google denied access because this connection is missing a required permission. Reconnect and approve the requested calendar permissions.',
        'domainPolicy','ORG_RESTRICTION_VIOLATION'=>'Google denied access because an organization policy restricts this app or service. Ask the account’s Workspace administrator to review the restriction.',
        'dailyLimitExceeded','quotaExceeded','rateLimitExceeded','userRateLimitExceeded','RATE_LIMIT_EXCEEDED'=>'Google denied access because a quota or rate limit was reached. Review the project’s API quota before retrying.',
        default=>null};if($message!==null)return $message;
    }return $fallback;
}
