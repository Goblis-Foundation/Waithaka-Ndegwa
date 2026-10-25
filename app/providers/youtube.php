<?php
declare(strict_types=1);

/**
 * Native YouTube resumable uploads. The saved session is the idempotency boundary:
 * after any interrupted PUT we query that same session before sending more bytes.
 * We never initialize a replacement upload for a job that already has a session.
 *
 * Official protocol and metadata references, checked 2026-10-21:
 * https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol
 * https://developers.google.com/youtube/v3/docs/videos/insert
 * https://developers.google.com/youtube/v3/docs/videos
 */

const PUBLISHING_YOUTUBE_MAX_BYTES = 536870912; // Application limit: 512 MiB.
const PUBLISHING_YOUTUBE_CHUNK_BYTES = 8388608; // Multiple of 256 KiB.

function publishing_youtube_connection_ready(array $connection): bool {
    $scopes = publishing_decode($connection['scopes_json'] ?? '');
    return ($connection['service'] ?? '') === 'google_youtube_upload'
        && ($connection['status'] ?? '') === 'connected'
        && !empty($connection['external_id'])
        && (bool)preg_match('/\AUC[A-Za-z0-9_-]{22}\z/', (string)($connection['selected_resource'] ?? ''))
        && in_array('https://www.googleapis.com/auth/youtube.upload', $scopes, true)
        && in_array('https://www.googleapis.com/auth/youtube.readonly', $scopes, true);
}

function publishing_youtube_validate_variant(array $variant, bool $requireAsset = true): void {
    $title = (string)($variant['title'] ?? '');
    $description = (string)($variant['text'] ?? '');
    if (!mb_check_encoding($title, 'UTF-8') || trim($title) === '' || mb_strlen($title) > 100 || preg_match('/[<>\x00]/u', $title)) {
        throw new RuntimeException('YouTube needs a title of 1–100 characters without angle brackets.');
    }
    if (!mb_check_encoding($description, 'UTF-8') || strlen($description) > 5000 || preg_match('/[<>\x00]/u', $description)) {
        throw new RuntimeException('The YouTube description must fit 5,000 UTF-8 bytes and cannot contain angle brackets.');
    }
    if (!in_array($variant['privacy'] ?? null, ['private','unlisted','public'], true)) {
        throw new RuntimeException('Choose the exact YouTube visibility before approval.');
    }
    foreach (['made_for_kids','ai_generated','notify_subscribers'] as $field) {
        if (!array_key_exists($field, $variant) || !is_bool($variant[$field])) {
            throw new RuntimeException('Save and review the YouTube audience, disclosure and subscriber-notification choices.');
        }
    }
    if (($variant['media_type'] ?? '') !== 'video' || !in_array($variant['format'] ?? '', ['video','short'], true)) {
        throw new RuntimeException('Choose a YouTube video or Short and a video asset. YouTube determines Short eligibility.');
    }
    if (!$requireAsset) return;
    $asset = $variant['media_asset'] ?? null;
    if (!is_array($asset) || (int)($asset['id'] ?? 0) < 1 || (int)($variant['media_asset_id'] ?? 0) !== (int)$asset['id']) {
        throw new RuntimeException('Direct YouTube upload requires an owned video selected from the media library. A public URL or watch link alone is not accepted.');
    }
    if (!is_int($asset['bytes'] ?? null) || $asset['bytes'] < 1 || $asset['bytes'] > PUBLISHING_YOUTUBE_MAX_BYTES
        || !preg_match('/\A[a-f0-9]{64}\z/', (string)($asset['sha256'] ?? ''))
        || !in_array($asset['mime'] ?? '', ['video/mp4','video/quicktime','video/webm'], true)) {
        throw new RuntimeException('Choose an approved MP4, MOV or WebM video of at most 512 MiB from the media library.');
    }
}

function publishing_youtube_metadata(array $variant): array {
    publishing_youtube_validate_variant($variant);
    return [
        'snippet'=>['title'=>$variant['title'],'description'=>$variant['text'],'categoryId'=>'27']+(!empty($variant['platform_options']['tags'])?['tags'=>array_values(array_filter(array_map('trim',explode(',',$variant['platform_options']['tags']))))]:[])+(!empty($variant['platform_options']['language'])?['defaultLanguage'=>$variant['platform_options']['language']]:[]),
        'status'=>[
            // Explicit even when false/private: never rely on account or API defaults.
            'privacyStatus'=>$variant['privacy'],
            'selfDeclaredMadeForKids'=>$variant['made_for_kids'],
            'containsSyntheticMedia'=>$variant['ai_generated'],
        ],
    ];
}

/** The only endpoint override is a numeric loopback fixture in APP_ENV=testing. */
function publishing_youtube_base(): string {
    $fixture = getenv('YOUTUBE_TEST_BASE_URL');
    if ($fixture !== false && $fixture !== '') {
        $parts = parse_url($fixture);
        if (app_config('app_env') !== 'testing' || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1'
            || empty($parts['port']) || $parts['port'] < 1024 || $parts['port'] > 65535
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new PublishingRemoteException('The YouTube test endpoint is not allowed in this environment.', 422);
        }
        return 'http://127.0.0.1:'.$parts['port'];
    }
    return 'https://www.googleapis.com';
}

function publishing_youtube_endpoint(string $path, array $query = []): string {
    if (!in_array($path, ['/upload/youtube/v3/videos','/youtube/v3/channels','/youtube/v3/videos'], true)) {
        throw new LogicException('Unexpected YouTube API path.');
    }
    return publishing_youtube_base().$path.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
}

/** A Location header is untrusted until it passes the same credential-host guard. */
function publishing_youtube_url_allowed(string $url, bool $session = false): bool {
    if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f]/', $url)) return false;
    $parts = parse_url($url);
    $base = parse_url(publishing_youtube_base());
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== $base['scheme']
        || ($parts['host'] ?? '') !== $base['host']
        || ($parts['port'] ?? null) !== ($base['port'] ?? null)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) return false;
    $paths = $session ? ['/upload/youtube/v3/videos'] : ['/upload/youtube/v3/videos','/youtube/v3/channels','/youtube/v3/videos'];
    if (!in_array($parts['path'] ?? '', $paths, true)) return false;
    if ($session) {
        parse_str((string)($parts['query'] ?? ''), $query);
        if (($query['uploadType'] ?? '') !== 'resumable' || !is_string($query['upload_id'] ?? null)
            || $query['upload_id'] === '' || strlen($query['upload_id']) > 4096) return false;
    }
    return true;
}

/**
 * Bounded transport. Uploads stream from a prevalidated local handle; arbitrary
 * URL downloads and redirects are never supported here. Response bodies are
 * bounded and never included in exception messages or publication events.
 */
function publishing_youtube_request(string $method, string $url, string $token, array $headers = [], ?string $body = null, ?array $upload = null): array {
    if (!publishing_youtube_url_allowed($url)) throw new PublishingRemoteException('YouTube returned an unsafe endpoint. No credentials were sent.', 422);
    if (!function_exists('curl_init')) throw new PublishingRemoteException('The server needs the PHP cURL extension for YouTube uploads.', 422);
    $responseHeaders = [];
    $raw = '';
    $headerBytes = 0;
    $ch = curl_init($url);
    $options = [
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_CONNECTTIMEOUT=>10,
        CURLOPT_TIMEOUT=>$upload === null ? 20 : 30,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_MAXREDIRS=>0,
        CURLOPT_PROTOCOLS=>str_starts_with($url, 'https:') ? CURLPROTO_HTTPS : CURLPROTO_HTTP,
        CURLOPT_HTTPHEADER=>array_merge(publishing_bearer($token), ['Accept: application/json','Expect:'], $headers),
        CURLOPT_HEADERFUNCTION=>static function ($handle, string $line) use (&$responseHeaders, &$headerBytes): int {
            $headerBytes += strlen($line);
            if ($headerBytes > 65536) return 0;
            if (str_starts_with($line, 'HTTP/')) $responseHeaders = [];
            $pair = explode(':', $line, 2);
            if (count($pair) === 2) $responseHeaders[strtolower(trim($pair[0]))] = trim($pair[1]);
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION=>static function ($handle, string $chunk) use (&$raw): int {
            if (strlen($raw) + strlen($chunk) > 1048576) return 0;
            $raw .= $chunk;
            return strlen($chunk);
        },
    ];
    if ($upload !== null) {
        if (!is_resource($upload['handle'] ?? null) || !is_int($upload['length'] ?? null) || $upload['length'] < 1
            || $upload['length'] > PUBLISHING_YOUTUBE_CHUNK_BYTES || fseek($upload['handle'], $upload['offset'], SEEK_SET) !== 0) {
            curl_close($ch);
            throw new PublishingRemoteException('The approved video could not be read at its confirmed upload offset.', 422);
        }
        $remaining = $upload['length'];
        $options[CURLOPT_UPLOAD] = true;
        $options[CURLOPT_INFILESIZE] = $remaining;
        $options[CURLOPT_READFUNCTION] = static function ($curl, $unused, int $length) use (&$remaining, $upload): string {
            if ($remaining === 0) return '';
            $chunk = fread($upload['handle'], min($length, $remaining));
            if ($chunk === false) return '';
            $remaining -= strlen($chunk);
            return $chunk;
        };
    } elseif ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch) !== false && curl_errno($ch) === 0;
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = json_decode($raw, true);
    return ['ok'=>$ok,'status'=>$code,'headers'=>$responseHeaders,'data'=>is_array($data) ? $data : null];
}

function publishing_youtube_state(array $job): array {
    $state = publishing_decode($job['provider_state_json'] ?? '');
    if (($state['adapter'] ?? '') !== 'youtube_resumable_v1') return [];
    return $state;
}

function publishing_youtube_can_resume(array $job): bool {
    $destination = publishing_decode($job['destination_json'] ?? '');
    $state = publishing_youtube_state($job);
    if (($job['method'] ?? '') !== 'direct' || ($destination['slug'] ?? '') !== 'youtube') return false;
    if (in_array($state['phase'] ?? '', ['processing','complete'], true)) return (bool)preg_match('/\A[A-Za-z0-9_-]{11}\z/', (string)($state['remote_id'] ?? ''));
    return ($state['phase'] ?? '') === 'uploading' && !empty($state['session_uri_encrypted']);
}

function publishing_youtube_can_restart_rejected_init(array $job): bool {
    $destination = publishing_decode($job['destination_json'] ?? '');
    $state = publishing_youtube_state($job);
    return ($job['method'] ?? '') === 'direct' && ($destination['slug'] ?? '') === 'youtube'
        && ($state['phase'] ?? '') === 'initialization_rejected'
        && ($state['max_sent'] ?? -1) === 0 && empty($state['session_uri_encrypted']) && empty($job['remote_id']);
}

/** Store recovery information before the corresponding network side effect. */
function publishing_youtube_checkpoint(array &$job, array $state, array $extra = []): void {
    $fields = ['provider_state_json'=>publishing_json($state)] + $extra;
    publishing_job_update((int)$job['id'], $fields);
    $job = array_replace($job, $fields);
}

function publishing_youtube_pending(array $state, ?string $message = null, int $delay = 60): array {
    return ['status'=>'delivered','provider_state_json'=>publishing_json($state),
        'last_error'=>$message,'next_check_at'=>gmdate('Y-m-d H:i:s', time() + max(15, min(3600, $delay)))];
}

function publishing_youtube_wait(array $state, array $response, string $message): array {
    $state['retries'] = (int)($state['retries'] ?? 0) + 1;
    if ($state['retries'] > 8) {
        return ['status'=>'ambiguous','provider_state_json'=>publishing_json($state),'next_check_at'=>null,
            'last_error'=>$message.' Automatic checks paused. Resume the same upload after reviewing YouTube; no replacement upload was created.'];
    }
    $delay = min(3600, 60 * (2 ** min(6, $state['retries'] - 1)));
    $retry = (string)($response['headers']['retry-after'] ?? '');
    if (ctype_digit($retry)) $delay = max($delay, min(3600, (int)$retry));
    elseif ($retry !== '' && ($time = strtotime($retry)) !== false) $delay = max($delay, min(3600, $time - time()));
    return publishing_youtube_pending($state, $message.' The next check uses the saved upload session.', $delay);
}

function publishing_youtube_expected(array $job, array $snapshot, array $connection): array {
    if (!publishing_youtube_connection_ready($connection)) throw new PublishingRemoteException('Reconnect YouTube upload access and confirm the approved channel, including upload and read permissions.', 401);
    $destination = publishing_decode($job['destination_json'] ?? '');
    if (($destination['slug'] ?? '') !== 'youtube' || ($destination['method'] ?? '') !== 'direct'
        || ($destination['service'] ?? '') !== 'google_youtube_upload'
        || (string)($destination['resource'] ?? '') !== (string)$connection['selected_resource']
        || (string)($job['connection_external_id'] ?? '') !== (string)$connection['external_id']) {
        throw new PublishingRemoteException('The YouTube destination no longer matches this approved job.', 422);
    }
    if (empty($snapshot['destinations']['youtube']) || publishing_json($snapshot['destinations']['youtube']) !== publishing_json($destination)) {
        throw new PublishingRemoteException('The YouTube destination does not match the immutable approval.', 422);
    }
    $variant = $snapshot['variants']['youtube'] ?? [];
    try { publishing_youtube_validate_variant($variant); }
    catch (RuntimeException $e) { throw new PublishingRemoteException($e->getMessage(), 422); }
    if (($destination['visibility'] ?? '') !== $variant['privacy']) throw new PublishingRemoteException('The approved YouTube visibility is inconsistent. Create a fresh reviewed revision.', 422);
    return [$destination, $variant];
}

function publishing_youtube_current_channel(string $token, string $expected): void {
    $response = publishing_youtube_request('GET', publishing_youtube_endpoint('/youtube/v3/channels', ['part'=>'id','mine'=>'true','maxResults'=>50]), $token);
    if (!$response['ok'] || $response['status'] >= 500 || $response['status'] === 429) {
        throw new PublishingRemoteException('YouTube channel verification is temporarily unavailable. No new video bytes were sent.', $response['status'] ?: 503);
    }
    if ($response['status'] === 401) throw new PublishingRemoteException('Reconnect the approved YouTube account.', 401);
    $items = $response['data']['items'] ?? [];
    // videos.insert targets the channel represented by the OAuth grant; it is not
    // a general-purpose arbitrary channel selector (CMS delegation is excluded).
    if ($response['status'] !== 200 || count($items) !== 1 || (string)($items[0]['id'] ?? '') !== $expected) {
        throw new PublishingRemoteException('The current Google grant does not identify exactly the approved YouTube channel. Reconnect and choose that channel.', 422);
    }
}

function publishing_youtube_deliver(array $job, array $snapshot, array $connection): array {
    if (!empty($job['provider_state_json']) || !empty($job['remote_id'])) {
        // A reviewed resume must never call videos.insert for a second time.
        $job['snapshot_json'] = publishing_json($snapshot);
        return publishing_youtube_check($job, $connection);
    }
    [$destination, $variant] = publishing_youtube_expected($job, $snapshot, $connection);
    if (!function_exists('media_library_materialize') || !function_exists('media_library_release')) {
        throw new PublishingRemoteException('The media library must be installed before enabling direct YouTube upload.', 422);
    }
    $materialized = null;
    $handle = null;
    try {
        [$materialized, $handle] = publishing_youtube_open_asset($variant['media_asset']);
        $token = publishing_access_token($connection);
        publishing_youtube_current_channel($token, $destination['resource']);
        $metadata = publishing_youtube_metadata($variant);
        $state = [
            'adapter'=>'youtube_resumable_v1','phase'=>'initializing',
            'bytes'=>$materialized['bytes'],'sha256'=>$materialized['sha256'],'mime'=>$materialized['mime'],
            'metadata_hash'=>hash('sha256', publishing_json([$metadata, $variant['notify_subscribers']])),
            'channel_id'=>$destination['resource'],'offset'=>0,'max_sent'=>0,'chunk_bytes'=>PUBLISHING_YOUTUBE_CHUNK_BYTES,
            'pending'=>false,'retries'=>0,'created_at'=>time(),
        ];
        publishing_youtube_checkpoint($job, $state);
        $json = publishing_json($metadata);
        $response = publishing_youtube_request('POST', publishing_youtube_endpoint('/upload/youtube/v3/videos', [
            'uploadType'=>'resumable','part'=>'snippet,status','notifySubscribers'=>$variant['notify_subscribers'] ? 'true' : 'false',
        ]), $token, ['Content-Type: application/json; charset=UTF-8','Content-Length: '.strlen($json),
            'X-Upload-Content-Length: '.$state['bytes'],'X-Upload-Content-Type: '.$state['mime']], $json);
        if (!$response['ok'] || $response['status'] >= 500) {
            throw new PublishingRemoteException('YouTube did not return a usable upload-session response. No video bytes were sent. Review Studio before creating another revision.', $response['status'], true);
        }
        if (!in_array($response['status'], [200,201], true)) {
            $state['phase'] = 'initialization_rejected';
            publishing_youtube_checkpoint($job, $state);
            if ($response['status'] === 401) throw new PublishingRemoteException('YouTube requires reconnection before an upload can begin.', 401);
            throw new PublishingRemoteException('YouTube rejected upload initialization (HTTP '.$response['status'].'). Review the channel, metadata and quota. No video bytes were sent.', $response['status']);
        }
        $session = (string)($response['headers']['location'] ?? '');
        if (!publishing_youtube_url_allowed($session, true)) {
            throw new PublishingRemoteException('YouTube did not return an allowed resumable session. No video bytes were sent. Review this job before creating another revision.', 0, true);
        }
        $state['session_uri_encrypted'] = encrypt_secret($session);
        $state['phase'] = 'uploading';
        publishing_youtube_checkpoint($job, $state, ['status'=>'delivered','next_check_at'=>gmdate('Y-m-d H:i:s', time()+60)]);
        return publishing_youtube_transfer($job, $state, $session, $token, $handle);
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (is_array($materialized)) media_library_release($materialized);
    }
}

function publishing_youtube_open_asset(array $asset): array {
    $materialized = null;
    $handle = null;
    try {
        $materialized = media_library_materialize($asset, PUBLISHING_YOUTUBE_MAX_BYTES);
        $path = (string)($materialized['path'] ?? '');
        if ($path === '' || !is_file($path) || is_link($path) || !is_readable($path)) throw new RuntimeException('The approved video is not a readable local media-library file.');
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new RuntimeException('The approved video could not be opened.');
        $stat = fstat($handle);
        if (!$stat || $stat['size'] !== $asset['bytes'] || $stat['size'] < 1 || $stat['size'] > PUBLISHING_YOUTUBE_MAX_BYTES) throw new RuntimeException('The video size changed after approval. Upload and approve a new asset.');
        $context = hash_init('sha256');
        if (hash_update_stream($context, $handle) !== $asset['bytes'] || !hash_equals($asset['sha256'], hash_final($context))) throw new RuntimeException('The video contents changed after approval. Upload and approve a new asset.');
        rewind($handle);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if ($mime !== $asset['mime'] || !in_array($mime, ['video/mp4','video/quicktime','video/webm'], true)) throw new RuntimeException('The video MIME type does not match the approved asset.');
        $materialized['bytes'] = $asset['bytes'];
        $materialized['mime'] = $mime;
        $materialized['sha256'] = $asset['sha256'];
        return [$materialized, $handle];
    } catch (Throwable $e) {
        if (is_resource($handle)) fclose($handle);
        if (is_array($materialized)) media_library_release($materialized);
        throw new PublishingRemoteException($e instanceof PDOException ? 'The approved media asset could not be loaded.' : $e->getMessage(), 422);
    }
}

function publishing_youtube_range(array $response, array $state): int {
    $range = (string)($response['headers']['range'] ?? '');
    if ($range === '') $offset = 0;
    elseif (preg_match('/\Abytes=0-([0-9]{1,12})\z/i', $range, $match)) $offset = (int)$match[1] + 1;
    else throw new PublishingRemoteException('YouTube returned an invalid upload range. Resume only after reviewing the existing upload.', 0, true);
    if ($offset < (int)$state['offset'] || $offset > (int)$state['bytes'] || $offset > (int)$state['max_sent']) {
        throw new PublishingRemoteException('YouTube reported an unexpected upload position. No bytes will be replayed or skipped automatically.', 0, true);
    }
    return $offset;
}

function publishing_youtube_upload_response(array &$job, array &$state, array $response): ?array {
    if (!$response['ok'] || in_array($response['status'], [0,408,429,500,502,503,504], true)) {
        return publishing_youtube_wait($state, $response, 'The last upload response was incomplete or temporarily unavailable.');
    }
    if ($response['status'] === 401) throw new PublishingRemoteException('Reconnect the same YouTube account to resume this saved upload.', 401);
    if (in_array($response['status'], [404,410], true)) {
        $state['phase'] = 'expired';
        $uncertain = $state['max_sent'] >= $state['bytes'];
        return ['status'=>$uncertain ? 'ambiguous' : 'failed','provider_state_json'=>publishing_json($state),'next_check_at'=>null,
            'last_error'=>$uncertain
                ? 'The saved upload session expired after the final bytes may have arrived. Check YouTube Studio and record the existing video; no replacement upload will be created.'
                : 'The saved upload session expired before a complete video was sent. Review Studio, then approve a new revision to start again.'];
    }
    if (in_array($response['status'], [200,201], true)) {
        $id = (string)($response['data']['id'] ?? '');
        if (!preg_match('/\A[A-Za-z0-9_-]{11}\z/', $id)) {
            return publishing_youtube_wait($state, $response, 'YouTube accepted the request but did not return a usable video ID.');
        }
        if ($state['max_sent'] < $state['bytes']) {
            $state['phase'] = 'remote_mismatch';
            $state['remote_id'] = $id;
            $fields = ['status'=>'ambiguous','provider_state_json'=>publishing_json($state),'remote_id'=>$id,
                'external_url'=>'https://www.youtube.com/watch?v='.$id,'next_check_at'=>null,
                'last_error'=>'YouTube returned a video before this job attempted the complete approved file. Review that video in Studio; no upload will be replayed.'];
            publishing_youtube_checkpoint($job, $state, $fields);
            return $fields;
        }
        $state['phase'] = 'processing';
        $state['remote_id'] = $id;
        $state['offset'] = $state['bytes'];
        $state['pending'] = false;
        $state['retries'] = 0;
        $state['processing_started_at'] = time();
        // Persist the returned ID before any subsequent read can fail.
        $fields = publishing_youtube_pending($state) + ['remote_id'=>$id,'external_url'=>'https://www.youtube.com/watch?v='.$id];
        if (isset($response['data']['snippet']['channelId']) && $response['data']['snippet']['channelId'] !== $state['channel_id']) {
            $fields['status']='ambiguous';$fields['next_check_at']=null;
            $fields['last_error']='YouTube returned a video for a different channel. The video ID was retained. Review Studio before resuming its status check.';
        }
        publishing_youtube_checkpoint($job, $state, $fields);
        return $fields;
    }
    if ($response['status'] !== 308) {
        return ['status'=>'failed','provider_state_json'=>publishing_json($state),'next_check_at'=>null,
            'last_error'=>'YouTube rejected the existing upload (HTTP '.$response['status'].'). Review the saved upload and permissions; resuming will query this same session.'];
    }
    $oldOffset = $state['offset'];
    $state['offset'] = publishing_youtube_range($response, $state);
    $state['pending'] = false;
    if ($state['offset'] > $oldOffset) $state['retries'] = 0;
    publishing_youtube_checkpoint($job, $state, ['status'=>'delivered']);
    if (!empty($response['headers']['retry-after'])) {
        $fields = publishing_youtube_wait($state, $response, 'YouTube asked the uploader to wait.');
        return $fields;
    }
    return null;
}

/** At most four chunks and 45 seconds of work per invocation, apart from the bounded in-flight request. */
function publishing_youtube_transfer(array $job, array $state, string $session, string $token, $handle): array {
    $deadline = microtime(true) + 45;
    for ($count = 0; $count < 4 && microtime(true) < $deadline; $count++) {
        $offset = (int)$state['offset'];
        if ($offset >= $state['bytes']) return publishing_youtube_wait($state, ['headers'=>[]], 'All bytes are acknowledged, but YouTube has not returned the existing video ID.');
        $length = min((int)$state['chunk_bytes'], $state['bytes'] - $offset);
        $state['max_sent'] = max((int)$state['max_sent'], $offset + $length);
        $state['pending'] = true;
        // A crash here leaves a queryable session, not a replayable initial POST.
        publishing_youtube_checkpoint($job, $state, ['status'=>'delivered','next_check_at'=>gmdate('Y-m-d H:i:s', time()+60)]);
        $response = publishing_youtube_request('PUT', $session, $token, [
            'Content-Type: '.$state['mime'],'Content-Length: '.$length,
            'Content-Range: bytes '.$offset.'-'.($offset+$length-1).'/'.$state['bytes'],
        ], null, ['handle'=>$handle,'offset'=>$offset,'length'=>$length]);
        $fields = publishing_youtube_upload_response($job, $state, $response);
        if ($fields !== null) return $fields;
        if ($state['offset'] <= $offset) return publishing_youtube_wait($state, $response, 'YouTube has not acknowledged progress on the saved upload.');
    }
    return publishing_youtube_pending($state);
}

function publishing_youtube_check(array $job, array $connection): array {
    $json = (string)($job['snapshot_json'] ?? '');
    if ($json === '' || (!empty($job['content_hash']) && !hash_equals($job['content_hash'], hash('sha256', $json)))) {
        throw new PublishingRemoteException('The approved YouTube revision could not be verified.', 422);
    }
    [$destination, $variant] = publishing_youtube_expected($job, publishing_decode($json), $connection);
    $state = publishing_youtube_state($job);
    $metadataHash = hash('sha256', publishing_json([publishing_youtube_metadata($variant), $variant['notify_subscribers']]));
    if (!$state || !hash_equals((string)($state['metadata_hash'] ?? ''), $metadataHash)
        || ($state['channel_id'] ?? '') !== $destination['resource']
        || ($state['bytes'] ?? 0) !== $variant['media_asset']['bytes']
        || ($state['sha256'] ?? '') !== $variant['media_asset']['sha256']
        || ($state['mime'] ?? '') !== $variant['media_asset']['mime']
        || ($state['chunk_bytes'] ?? 0) !== PUBLISHING_YOUTUBE_CHUNK_BYTES) {
        throw new PublishingRemoteException('The saved upload does not match the approved video and settings. No replacement upload was created.', 422);
    }
    $token = publishing_access_token($connection);
    if (in_array($state['phase'], ['processing','complete'], true)) return publishing_youtube_processing($job, $state, $variant, $token);
    if (!publishing_youtube_can_resume($job)) throw new PublishingRemoteException('This YouTube job has no safely resumable session. Review Studio before approving another revision.', 422);
    $session = decrypt_secret((string)$state['session_uri_encrypted']);
    if (!publishing_youtube_url_allowed($session, true)) throw new PublishingRemoteException('The stored YouTube upload session is invalid. No credentials were sent.', 422);
    // This query recovers a lost final acknowledgement without uploading again.
    $response = publishing_youtube_request('PUT', $session, $token, ['Content-Length: 0','Content-Range: bytes */'.$state['bytes']], '');
    $fields = publishing_youtube_upload_response($job, $state, $response);
    if ($fields !== null) return $fields;
    if ($state['offset'] >= $state['bytes']) return publishing_youtube_wait($state, $response, 'All bytes are acknowledged, but YouTube has not returned the existing video ID.');
    publishing_youtube_current_channel($token, $destination['resource']);
    $materialized = null;
    $handle = null;
    try {
        [$materialized, $handle] = publishing_youtube_open_asset($variant['media_asset']);
        return publishing_youtube_transfer($job, $state, $session, $token, $handle);
    } finally {
        if (is_resource($handle)) fclose($handle);
        if (is_array($materialized)) media_library_release($materialized);
    }
}

function publishing_youtube_processing(array $job, array $state, array $variant, string $token): array {
    $id = (string)($state['remote_id'] ?? '');
    if (!preg_match('/\A[A-Za-z0-9_-]{11}\z/', $id)) throw new PublishingRemoteException('The saved YouTube video ID is invalid.', 422);
    $response = publishing_youtube_request('GET', publishing_youtube_endpoint('/youtube/v3/videos', [
        'part'=>'snippet,status,processingDetails','id'=>$id,
    ]), $token);
    if ($response['status'] === 401) throw new PublishingRemoteException('Reconnect the approved YouTube account to check the existing video.', 401);
    if (!$response['ok'] || in_array($response['status'], [0,408,429,500,502,503,504], true)) {
        return publishing_youtube_wait($state, $response, 'The existing YouTube video could not be checked yet.');
    }
    $video = $response['data']['items'][0] ?? null;
    $base = ['remote_id'=>$id,'external_url'=>'https://www.youtube.com/watch?v='.$id];
    if ($response['status'] !== 200 || !is_array($video) || ($video['id'] ?? '') !== $id) {
        return $base + ['status'=>'failed','provider_state_json'=>publishing_json($state),'next_check_at'=>null,
            'last_error'=>'YouTube did not return the existing video. Review it in Studio; this job will not upload another copy.'];
    }
    if (($video['snippet']['channelId'] ?? '') !== $state['channel_id']) {
        return $base + ['status'=>'ambiguous','provider_state_json'=>publishing_json($state),'next_check_at'=>null,
            'last_error'=>'The existing video is not on the approved channel. Review the provider record before closing this job.'];
    }
    $status = $video['status'] ?? [];
    $processing = (string)($video['processingDetails']['processingStatus'] ?? '');
    $upload = (string)($status['uploadStatus'] ?? '');
    $state['observed_visibility'] = (string)($status['privacyStatus'] ?? 'unknown');
    $state['observed_made_for_kids'] = $status['selfDeclaredMadeForKids'] ?? null;
    $state['observed_ai_generated'] = $status['containsSyntheticMedia'] ?? null;
    $state['upload_status'] = $upload;
    $state['processing_status'] = $processing;
    $state['retries'] = 0;
    if (in_array($upload, ['failed','rejected','deleted'], true) || $processing === 'failed') {
        return $base + ['status'=>'failed','provider_state_json'=>publishing_json($state),'next_check_at'=>null,
            'last_error'=>'YouTube reports that this existing video failed, was rejected or was deleted. Review Studio; no automatic re-upload is attempted.'];
    }
    if ($upload !== 'processed' && $processing !== 'succeeded') {
        if (time() - (int)($state['processing_started_at'] ?? $state['created_at']) > 86400) {
            return $base + ['status'=>'failed','provider_state_json'=>publishing_json($state),'next_check_at'=>null,
                'last_error'=>'YouTube has not confirmed processing after 24 hours. Review the existing video in Studio and resume its status check when ready.'];
        }
        return $base + publishing_youtube_pending($state, 'The upload is complete; YouTube is still processing the existing video.', 180);
    }
    $exact = ($status['privacyStatus'] ?? null) === $variant['privacy']
        && ($status['selfDeclaredMadeForKids'] ?? null) === $variant['made_for_kids']
        && ($status['containsSyntheticMedia'] ?? null) === $variant['ai_generated'];
    if (!$exact) {
        return $base + ['status'=>'failed','provider_state_json'=>publishing_json($state),'next_check_at'=>null,
            'last_error'=>'The existing video processed, but YouTube did not confirm the approved visibility, audience and altered-content disclosure. Review those settings in Studio, then resume this record to check them. No duplicate upload or automatic visibility change will occur.'];
    }
    $state['phase'] = 'complete';
    return $base + ['status'=>'published','provider_state_json'=>publishing_json($state),'next_check_at'=>null,'last_error'=>null];
}
