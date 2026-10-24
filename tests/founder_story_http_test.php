<?php
declare(strict_types=1);

/** Real private/public forms on the runner's guarded local server. No external calls. */
function rh_env(string $primary, string $fallback): string|false { $v = getenv($primary); return $v !== false ? $v : getenv($fallback); }
$base = rtrim((string)rh_env('TEST_BASE_URL', 'WK_TEST_BASE_URL'), '/'); $parts = parse_url($base);
$host = rh_env('DB_HOST', 'WK_TEST_DB_HOST'); $port = rh_env('DB_PORT', 'WK_TEST_DB_PORT'); $name = rh_env('DB_DATABASE', 'WK_TEST_DB_NAME'); $user = rh_env('DB_USERNAME', 'WK_TEST_DB_USER'); $pass = rh_env('DB_PASSWORD', 'WK_TEST_DB_PASS');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('WK_TEST_ALLOW_WRITES') !== '1' || !is_string($name) || !preg_match('/^waithaka_test_[a-z0-9_]+_test$/D', $name) || !in_array($host, ['127.0.0.1', '::1'], true) || !is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || !is_string($user) || $user === '' || !is_string($pass) || ($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1' || !isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) { fwrite(STDERR, "Use explicit isolated testing DB_* credentials and TEST_BASE_URL=http://127.0.0.1:<port>.\n"); exit(2); }
$CONFIG = ['app_env' => 'testing', 'app_key' => getenv('APP_KEY') ?: 'local-test-key-not-for-production-use', 'base_url' => $base, 'timezone' => 'Africa/Nairobi', 'mail' => ['transport' => 'disabled'], 'db' => ['host' => $host, 'port' => (int)$port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4']];
$APP_INSTALLED = true; date_default_timezone_set('Africa/Nairobi');
foreach (['functions', 'journeys', 'operations', 'newsletter', 'reader','founder_stories'] as $module) require_once __DIR__.'/../app/'.$module.'.php';
$checks = 0; $actor = 0; $subscriberIds = []; $founderIds = []; $contactIds = []; $sessionIds = []; $outboxIds = []; $topicId = 0;
$suffix = bin2hex(random_bytes(8)); $email = 'reader-http-'.$suffix.'@example.invalid'; $writerEmail = 'reader-writer-'.$suffix.'@example.invalid'; $password = bin2hex(random_bytes(20));
$visitorJar = tempnam(sys_get_temp_dir(), 'wk-reader-visitor-'); $writerJar = tempnam(sys_get_temp_dir(), 'wk-reader-writer-');
if ($visitorJar === false || $writerJar === false) throw new RuntimeException('Cannot allocate fixture cookie jars.');
function rh_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function rh_row(string $sql, array $params = []): array { $q = db()->prepare($sql); $q->execute($params); return $q->fetch() ?: []; }
function rh_count(string $sql, array $params = []): int { $q = db()->prepare($sql); $q->execute($params); return (int)$q->fetchColumn(); }
function rh_request(string $path, ?array $fields = null, bool $writer = false, string $method = 'GET'): array {
    global $base, $visitorJar, $writerJar, $sessionIds;
    if (!preg_match('~^/(?:write(?:/|$)|newsletter(?:/|\?|$)|founders(?:/|\?|$)|contact(?:\?|$))~', $path) || preg_match('/[\r\n]/', $path)) throw new RuntimeException('Only the local reader routes are allowed.');
    $headers = []; $curl = curl_init($base.$path); $jar = $writer ? $writerJar : $visitorJar;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HEADERFUNCTION => static function($handle, string $line) use (&$headers, &$sessionIds): int { $pair = explode(':', $line, 2); if (count($pair) === 2) { $key = strtolower(trim($pair[0])); $headers[$key] = trim($pair[1]); if ($key === 'set-cookie' && preg_match('/^PHPSESSID=([A-Za-z0-9,-]+)/', $headers[$key], $m)) $sessionIds[$m[1]] = true; } return strlen($line); }]);
    if ($fields !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields)]);
    if ($method === 'HEAD') curl_setopt($curl, CURLOPT_NOBODY, true); elseif (!in_array($method, ['GET', 'POST'], true)) curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
    if ($body === false) throw new RuntimeException('Local reader HTTP request failed: '.$error);
    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}
function rh_document(array $response): DOMDocument { $previous = libxml_use_internal_errors(true); $doc = new DOMDocument(); try { $doc->loadHTML($response['body'], LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); } return $doc; }
function rh_form(array $response, string $path, ?string $action = null): array {
    foreach (rh_document($response)->getElementsByTagName('form') as $form) {
        if (strtolower($form->getAttribute('method')) !== 'post' || parse_url($form->getAttribute('action'), PHP_URL_PATH) !== $path) continue;
        $values = []; foreach ($form->getElementsByTagName('input') as $input) if ($input->getAttribute('type') === 'hidden' && $input->getAttribute('name') !== '') $values[$input->getAttribute('name')] = $input->getAttribute('value');
        if ($action === null || ($values['action'] ?? $values['decision'] ?? '') === $action) return $values;
    }
    throw new RuntimeException('Expected protected form missing for '.$path.'.');
}
function rh_redirect(array $response, string $path): bool { return $response['status'] === 302 && parse_url($response['headers']['location'] ?? '', PHP_URL_PATH) === $path; }
function rh_private(array $response, string $label): void { rh_check(str_contains($response['headers']['cache-control'] ?? '', 'no-store') && str_contains($response['headers']['x-robots-tag'] ?? '', 'noindex') && ($response['headers']['referrer-policy'] ?? '') === 'no-referrer', $label); }
function rh_latest_token(string $recipient, string $path): string {
    global $outboxIds;
    foreach (db()->query('SELECT id,payload FROM email_outbox ORDER BY id DESC')->fetchAll() as $row) { $payload = json_decode(decrypt_secret($row['payload']), true, 32, JSON_THROW_ON_ERROR); if ($payload['to'] !== $recipient) continue; $outboxIds[(int)$row['id']] = true; if (preg_match('~'.preg_quote($path, '~').'\?token=([^"< ]+)~', $payload['html'], $m)) return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'); }
    throw new RuntimeException('Expected fixture link not found.');
}

$postId=0;$founderId=0;
try {
 db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Founder review HTTP fixture',$writerEmail,password_hash($password,PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
 db()->prepare('INSERT INTO categories(name,slug) VALUES(?,?)')->execute(['Story HTTP '.$suffix,'story-http-'.$suffix]);$topicId=(int)db()->lastInsertId();
 db()->prepare("INSERT INTO posts(user_id,category_id,title,slug,standfirst,content_html,writer_note,source_notes,status,published_at) VALUES(?,?,?,?,?,?,?,?,'draft',?)")->execute([$actor,$topicId,'FINAL COPY '.$suffix,'story-review-'.$suffix,'Founder-visible introduction','<p>Founder-visible story body.</p>','PRIVATE WRITER PLAN','PRIVATE RESEARCH NOTES',date('Y-m-d H:i:s',time()-60)]);$postId=(int)db()->lastInsertId();
 db()->prepare("INSERT INTO founder_submissions(reference,submission_type,submitter_name,submitter_email,founder_name,venture_name,country,story_summary,metric_basis,privacy_version,privacy_accepted_at,ip_hash,associated_post_id,created_at,updated_at) VALUES(?,'founder','Synthetic founder',?,'Synthetic founder','Fixture venture','Kenya','PRIVATE INTAKE','not_applicable','fixture',NOW(),?,?,NOW(),NOW())")->execute(['FS-HTTP-'.$suffix,'founder-'.$suffix.'@example.invalid',str_repeat('0',64),$postId]);$founderId=(int)db()->lastInsertId();
 $writerPath='/write/journeys/founders/'.$founderId;$requestPath='/write/founder-stories/'.$founderId.'/request';
 rh_check(rh_redirect(rh_request($writerPath),'/write/login'),'Story moderation is authenticated.');
 $login=rh_request('/write/login',null,true);rh_check(rh_redirect(rh_request('/write/login',rh_form($login,'/write/login')+['email'=>$writerEmail,'password'=>$password],true),'/write'),'Fixture author can sign in.');
 $page=rh_request($writerPath,null,true);rh_check($page['status']===200&&str_contains($page['body'],'Final-copy approval'),'The real Writer review includes the final-copy workflow.');
 $request=rh_form($page,$requestPath)+['founder_email'=>'founder-'.$suffix.'@example.invalid','founder_whatsapp'=>'+254700000002','contact_evidence'=>'Synthetic verified contact','contact_confirmed'=>1,'whatsapp_optin'=>1,'send_review_ack'=>1,'response_days'=>14];
 rh_check(rh_request($requestPath,array_replace($request,['csrf'=>'bad']),true)['status']===419,'Review invitations require CSRF.');
 rh_check(rh_redirect(rh_request($requestPath,$request,true),$writerPath),'The authenticated form creates the exact review and both notification jobs.');
 $workflow=founder_story_workflow($founderId);$rid=(int)$workflow['current_review_id'];$delivery=rh_row("SELECT payload_encrypted FROM founder_story_deliveries WHERE review_id=? AND channel='whatsapp'",[$rid]);$payload=json_decode(decrypt_secret($delivery['payload_encrypted']),true,32,JSON_THROW_ON_ERROR);parse_str((string)parse_url($payload['url'],PHP_URL_QUERY),$params);$token=$params['token'];$reviewPath='/founders/story-review?token='.$token;
 $page=rh_request($reviewPath);rh_check($page['status']===200&&str_contains($page['body'],'FINAL COPY '.$suffix)&&str_contains($page['body'],'Founder-visible story body.'),'The founder can read the frozen final copy.');
 rh_check(!str_contains($page['body'],'PRIVATE WRITER PLAN')&&!str_contains($page['body'],'PRIVATE RESEARCH NOTES')&&!str_contains($page['body'],'PRIVATE INTAKE')&&!str_contains($page['body'],'founder-'.$suffix.'@example.invalid'),'Only public-intended copy is shared; contacts, intake and internal notes stay private.');
 rh_private($page,'Review capabilities are no-store, no-referrer and noindex.');
 rh_check(!str_contains($page['body'],'googletagmanager.com')&&!preg_match('~rel="canonical"[^>]+token=~',$page['body']),'Review links do not enter analytics or canonical URLs.');
 rh_check(rh_request($reviewPath,null,false,'HEAD')['status']===200&&founder_story_workflow($founderId)['state']==='pending','GET/HEAD never consume or approve a review.');
 rh_check(rh_request($reviewPath,null,false,'DELETE')['status']===405,'Unsupported review methods are rejected.');
 $approve=rh_form($page,'/founders/story-review','approve');rh_check(rh_request('/founders/story-review',array_replace($approve,['csrf'=>'bad','approval_ack'=>1]))['status']===419,'Approval needs CSRF as well as the private capability.');
 $changes=rh_form($page,'/founders/story-review','changes')+['proposed_title'=>'FOUNDER EDITED '.$suffix,'proposed_standfirst'=>'Edited introduction','proposed_copy'=>'Edited story body with <script>unsafe proposal</script>.','note'=>'Please use this corrected copy.'];
 rh_check(rh_request('/founders/story-review',$changes)['status']===200&&founder_story_workflow($founderId)['state']==='changes_requested','A founder can return edited copy for editorial review.');
 rh_check(rh_row('SELECT title FROM posts WHERE id=?',[$postId])['title']==='FINAL COPY '.$suffix,'Proposed edits do not change the public post.');
 rh_check(rh_request($reviewPath)['status']===410,'An answered link cannot be replayed.');
 $page=rh_request($writerPath,null,true);$applyPath='/write/founder-stories/'.$founderId.'/apply-edits';rh_check(str_contains($page['body'],'FOUNDER EDITED '.$suffix),'Writer sees the proposed edits.');
 rh_check(rh_redirect(rh_request($applyPath,rh_form($page,$applyPath),true),$writerPath)&&writer_post_by_id($postId)['title']==='FOUNDER EDITED '.$suffix,'Writer can import the proposal only into the private draft.');
 $page=rh_request($writerPath,null,true);$request['csrf']=rh_form($page,$requestPath)['csrf'];rh_check(rh_redirect(rh_request($requestPath,$request,true),$writerPath),'The edited story is sent for a fresh review.');
 $workflow=founder_story_workflow($founderId);$rid=(int)$workflow['current_review_id'];$delivery=rh_row("SELECT payload_encrypted FROM founder_story_deliveries WHERE review_id=? AND channel='whatsapp'",[$rid]);$payload=json_decode(decrypt_secret($delivery['payload_encrypted']),true,32,JSON_THROW_ON_ERROR);parse_str((string)parse_url($payload['url'],PHP_URL_QUERY),$params);$token=$params['token'];
 $page=rh_request('/founders/story-review?token='.$token);rh_check(str_contains($page['body'],'FOUNDER EDITED '.$suffix),'The second review shows the edited frozen version.');
 $approve=rh_form($page,'/founders/story-review','approve')+['approval_ack'=>1];rh_check(rh_request('/founders/story-review',$approve)['status']===200&&founder_story_workflow($founderId)['state']==='approved','Explicit founder approval is recorded through the real form.');
 rh_check(rh_row('SELECT status FROM posts WHERE id=?',[$postId])['status']==='draft','Founder approval does not automatically publish the article.');
 rh_check(rh_request('/founders/story-review?token=not-a-capability')['status']===410,'Unknown capabilities disclose no story.');
 $settings=rh_request('/write/journeys/founders',null,true);rh_check($settings['status']===200&&str_contains($settings['body'],'WhatsApp Business setup'),'Writer exposes the delivery configuration without printing tokens.');
 echo 'Founder story HTTP checks: '.$checks.' passed.'.PHP_EOL;
} finally {
 if(db()->inTransaction())db()->rollBack();
 if($founderId){
  db()->prepare('DELETE d FROM founder_story_deliveries d JOIN founder_story_reviews r ON r.id=d.review_id WHERE r.founder_id=?')->execute([$founderId]);
  db()->prepare('DELETE FROM founder_story_reviews WHERE founder_id=?')->execute([$founderId]);
  db()->prepare('DELETE FROM founder_story_workflows WHERE founder_id=?')->execute([$founderId]);
  db()->prepare("DELETE FROM journey_audit_events WHERE entity_type='founder' AND entity_id=?")->execute([$founderId]);
  db()->prepare('DELETE FROM founder_submissions WHERE id=?')->execute([$founderId]);
 }
 if($postId){db()->prepare('DELETE FROM post_drafts WHERE post_id=?')->execute([$postId]);db()->prepare('DELETE FROM posts WHERE id=?')->execute([$postId]);}
 if($topicId)db()->prepare('DELETE FROM categories WHERE id=?')->execute([$topicId]);
 foreach(array_keys($sessionIds)as$sid)db()->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$sid]);
 if($actor){db()->prepare('DELETE FROM journey_audit_events WHERE actor_id=?')->execute([$actor]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$actor]);}
 foreach([$visitorJar,$writerJar]as$jar)if(is_file($jar))unlink($jar);
}

