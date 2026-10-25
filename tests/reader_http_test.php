<?php
declare(strict_types=1);

/** Real private/public forms on the runner's guarded local server. No external calls. */
function rh_env(string $primary, string $fallback): string|false { $v = getenv($primary); return $v !== false ? $v : getenv($fallback); }
$base = rtrim((string)rh_env('TEST_BASE_URL', 'WK_TEST_BASE_URL'), '/'); $parts = parse_url($base);
$host = rh_env('DB_HOST', 'WK_TEST_DB_HOST'); $port = rh_env('DB_PORT', 'WK_TEST_DB_PORT'); $name = rh_env('DB_DATABASE', 'WK_TEST_DB_NAME'); $user = rh_env('DB_USERNAME', 'WK_TEST_DB_USER'); $pass = rh_env('DB_PASSWORD', 'WK_TEST_DB_PASS');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('WK_TEST_ALLOW_WRITES') !== '1' || !is_string($name) || !preg_match('/^waithaka_test_[a-z0-9_]+_test$/D', $name) || !in_array($host, ['127.0.0.1', '::1'], true) || !is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || !is_string($user) || $user === '' || !is_string($pass) || ($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1' || !isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) { fwrite(STDERR, "Use explicit isolated testing DB_* credentials and TEST_BASE_URL=http://127.0.0.1:<port>.\n"); exit(2); }
$CONFIG = ['app_env' => 'testing', 'app_key' => getenv('APP_KEY') ?: 'local-test-key-not-for-production-use', 'base_url' => $base, 'timezone' => 'Africa/Nairobi', 'mail' => ['transport' => 'disabled'], 'db' => ['host' => $host, 'port' => (int)$port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4']];
$APP_INSTALLED = true; date_default_timezone_set('Africa/Nairobi');
foreach (['functions', 'journeys', 'newsletter', 'reader'] as $module) require_once __DIR__.'/../app/'.$module.'.php';
$checks = 0; $actor = 0; $subscriberIds = []; $founderIds = []; $contactIds = []; $sessionIds = []; $outboxIds = []; $topicId = 0;
$suffix = bin2hex(random_bytes(8)); $email = 'reader-http-'.$suffix.'@example.invalid'; $writerEmail = 'reader-writer-'.$suffix.'@example.invalid'; $password = bin2hex(random_bytes(20));
$visitorJar = tempnam(sys_get_temp_dir(), 'wk-reader-visitor-'); $writerJar = tempnam(sys_get_temp_dir(), 'wk-reader-writer-');
if ($visitorJar === false || $writerJar === false) throw new RuntimeException('Cannot allocate fixture cookie jars.');
function rh_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function rh_row(string $sql, array $params = []): array { $q = db()->prepare($sql); $q->execute($params); return $q->fetch() ?: []; }
function rh_count(string $sql, array $params = []): int { $q = db()->prepare($sql); $q->execute($params); return (int)$q->fetchColumn(); }
function rh_request(string $path, ?array $fields = null, bool $writer = false, string $method = 'GET'): array {
    global $base, $visitorJar, $writerJar, $sessionIds;
    if (!preg_match('~^/(?:write(?:/|$)|newsletter(?:/|\?|$)|enterprises(?:/|\?|$)|founders(?:/|\?|$)|contact(?:\?|$))~', $path) || preg_match('/[\r\n]/', $path)) throw new RuntimeException('Only the local reader routes are allowed.');
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
        if ($action === null || ($values['action'] ?? '') === $action) return $values;
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
try {
    $msPrivate=rh_request('/write/microsoft');rh_check(in_array($msPrivate['status'],[302,303],true)&&str_contains($msPrivate['headers']['location']??'','/write/login'),'Microsoft workspace requires authentication.');
    $enterpriseIndex=rh_request('/enterprises');rh_check($enterpriseIndex['status']===200&&str_contains($enterpriseIndex['body'],'Follow the work.'),'Enterprise directory is publicly readable with honest empty-state content.');
    $enterpriseMissing=rh_request('/enterprises/nonexistent-fixture');rh_check($enterpriseMissing['status']===404,'Unknown or unpublished enterprise profiles stay unavailable.');
    $enterprisePrivate=rh_request('/write/enterprises');rh_check(in_array($enterprisePrivate['status'],[302,303],true)&&str_contains($enterprisePrivate['headers']['location']??'','/write/login'),'Enterprise editing requires an authenticated owner.');
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Reader HTTP fixture', $writerEmail, password_hash($password, PASSWORD_DEFAULT)]); $actor = (int)db()->lastInsertId();
    db()->prepare('INSERT INTO categories(name,slug) VALUES(?,?)')->execute(['Reader HTTP topic '.$suffix, 'reader-http-'.$suffix]); $topicId = (int)db()->lastInsertId();
    $posts = []; foreach (['published', 'draft', 'scheduled'] as $status) { db()->prepare('INSERT INTO posts(user_id,category_id,title,slug,standfirst,content_html,status,published_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$actor, $topicId, 'Reader source '.$status.' '.$suffix, 'reader-source-'.$status.'-'.$suffix, 'Source summary '.$suffix, '<p>Source body.</p>', $status, date('Y-m-d H:i:s', time() + ($status === 'scheduled' ? 86400 : -60))]); $posts[$status] = (int)db()->lastInsertId(); }
    $response = rh_request('/newsletter'); $signup = rh_form($response, '/newsletter');
    rh_check($response['status'] === 200 && str_contains($response['body'], 'name="topic_ids[]"') && str_contains($response['body'], '/newsletter/preferences/request'), 'The actual signup form exposes subject choices and a management entry point.');
    rh_check(str_contains(rh_request('/newsletter')['body'],'autocomplete="given-name"')&&str_contains(rh_request('/newsletter')['body'],'autocomplete="family-name"'),'Subscription form exposes first and last name autofill fields');
    $fields = $signup + ['first_name' => 'PRIVATE READER', 'last_name' => 'NAME '.$suffix, 'email' => $email, 'subscribe_ack' => '1', 'receive_all' => '0', 'topic_ids' => [$topicId]];
    rh_check(rh_request('/newsletter', array_replace($fields, ['csrf' => 'bad']))['status'] === 419, 'Signup choices require the ordinary session CSRF token.');
    $response = rh_request('/newsletter', $fields); $subscriber = rh_row('SELECT * FROM newsletter_subscribers WHERE email=?', [$email]); $sid = (int)($subscriber['id'] ?? 0); if ($sid) $subscriberIds[] = $sid;
    rh_check(rh_redirect($response, '/newsletter') && $sid > 0 && $subscriber['status'] === 'pending' && reader_preferences($sid)['topic_ids'] === [$topicId], 'Real signup POST preserves choices without bypassing double opt-in.');
    rh_check($subscriber['first_name']==='PRIVATE READER'&&$subscriber['last_name']==='NAME '.$suffix,'Subscriber names are stored separately without guessing');
    $invalid=rh_request('/newsletter',array_replace($fields,['last_name'=>'']));rh_check($invalid['status']===422,'Public signup requires a last name on the server');
    $confirm = rh_latest_token($email, '/newsletter/confirm'); $response = rh_request('/newsletter/confirm?token='.$confirm); $form = rh_form($response, '/newsletter/confirm');
    rh_check(rh_redirect(rh_request('/newsletter/confirm', $form), '/newsletter') && newsletter_subscriber($sid)['status'] === 'confirmed', 'The existing confirmation form still activates the requested subscription.');
    $link = reader_issue_link('preferences', $sid); $path = '/newsletter/preferences?token='.$link['token']; $response = rh_request($path);
    rh_check($response['status'] === 200 && !str_contains($response['body'], $email) && !str_contains($response['body'], 'PRIVATE READER NAME'), 'The private preferences page never renders a subscriber address or name.'); rh_private($response, 'Preferences responses are no-store, no-referrer and non-indexable.');
    rh_check(!str_contains($response['body'], 'googletagmanager.com') && !preg_match('~<link[^>]+rel="canonical"[^>]+\?token=~', $response['body']), 'Private capabilities are absent from analytics loading and canonical metadata.');
    rh_check(rh_request($path, null, false, 'HEAD')['status'] === 200 && reader_link_record($link['token'], 'preferences') !== null, 'HEAD and GET do not consume a capability.');
    rh_check(rh_request($path, null, false, 'DELETE')['status'] === 405, 'Unsupported preference methods cannot mutate state.');
    $form = rh_form($response, '/newsletter/preferences', 'save');
    rh_check(rh_request('/newsletter/preferences', array_replace($form, ['csrf' => 'bad', 'receive_all' => '1']))['status'] === 419, 'A signed capability does not replace CSRF on human preference forms.');
    $response = rh_request('/newsletter/preferences', $form + ['receive_all' => '1']);
    rh_check(rh_redirect($response, '/newsletter/preferences/request') && reader_preferences($sid)['receive_all'] === 1, 'The protected form saves the reader’s chosen scope.');
    rh_check(rh_request($path)['status'] === 410 && rh_request('/newsletter/preferences?token=invalid')['status'] === 410, 'Consumed and malformed capabilities both return a closed unavailable response.');
    $link = reader_issue_link('preferences', $sid); $response = rh_request('/newsletter/preferences?token='.$link['token']); $form = rh_form($response, '/newsletter/preferences', 'unsubscribe');
    rh_check(rh_request('/newsletter/preferences', $form)['status'] === 422, 'Unsubscribe requires its explicit checkbox.');
    rh_check(rh_redirect(rh_request('/newsletter/preferences', $form + ['unsubscribe_ack' => '1']), '/newsletter/preferences/request') && newsletter_subscriber($sid)['status'] === 'unsubscribed', 'The private preference page can stop every newsletter edition.');
    $beforeQueue = rh_count('SELECT COUNT(*) FROM email_outbox');
    foreach ([$email, 'unknown-'.$suffix.'@example.invalid'] as $requestEmail) { $response = rh_request('/newsletter/preferences/request'); $form = rh_form($response, '/newsletter/preferences/request'); $response = rh_request('/newsletter/preferences/request', $form + ['email' => $requestEmail]); rh_check(rh_redirect($response, '/newsletter/preferences/request'), 'Eligible-record lookup does not expose address existence through its response.'); }
    rh_check(rh_count('SELECT COUNT(*) FROM email_outbox') === $beforeQueue && newsletter_subscriber($sid)['status'] === 'unsubscribed', 'Management requests neither enqueue to unsubscribed addresses nor reactivate them.');

    rh_check(rh_redirect(rh_request('/write/readers'), '/write/login'), 'The enquiry queue requires Writer authentication.');
    $response = rh_request('/write/login', null, true); $login = rh_form($response, '/write/login');
    rh_check(rh_redirect(rh_request('/write/login', $login + ['email' => $writerEmail, 'password' => $password], true), '/write'), 'The fixture author signs in through the real login flow.');
    $msDenied=rh_request('/write/microsoft',null,true);rh_check($msDenied['status']===403,'A signed-in non-owner cannot access Microsoft grants.');rh_private($msDenied,'Microsoft owner guard carries private response headers.');
    comm_put('config',['owner'=>$actor]);$msOwner=rh_request('/write/microsoft',null,true);rh_check($msOwner['status']===200&&str_contains($msOwner['body'],'Your accounts. One workspace.')&&str_contains($msOwner['body'],'isaac@goblisfoundation.com'),'The owner sees three independent Microsoft connection cards.');rh_private($msOwner,'Microsoft workspace is private.');
    $msCsrf=rh_request('/write/microsoft',['action'=>'connect','account'=>'personal'],true);rh_check($msCsrf['status']===419,'Microsoft OAuth start requires CSRF protection.');comm_put('config',[]);
    $response = rh_request('/write/readers', null, true); rh_check($response['status'] === 200, 'The shared Writer layout renders the new reader queue.'); rh_private($response, 'Writer reader records carry private response headers.');
    rh_check(rh_request('/write/readers', null, true, 'DELETE')['status'] === 405, 'Unsupported Writer queue methods are rejected.');
    $response = rh_request('/contact?type=speaking');rh_check(str_contains($response['body'],'data-contact-context-title>Invite me to speak')&&str_contains($response['body'],'name="contact_request"'),'Purpose links preselect the native enquiry form and include repeat protection');$form=rh_form($response,'/contact');
    $failed=rh_request('/contact',$form+['first_name'=>'KEEP THIS NAME','email'=>'invalid','message'=>'KEEP THIS PRIVATE MESSAGE','enquiry_type'=>'speaking','not_robot'=>'1']);rh_check(rh_redirect($failed,'/contact'),'Invalid address returns to the contact form');$restored=rh_request('/contact');rh_check(str_contains($restored['body'],'KEEP THIS NAME')&&str_contains($restored['body'],'KEEP THIS PRIVATE MESSAGE'),'Failed form retains entered text');
    $response = rh_request('/contact'); $form = rh_form($response, '/contact');
    $contactEmail = 'contact-'.$suffix.'@example.invalid'; $response = rh_request('/contact', $form + ['first_name' => 'Contact fixture', 'email' => $contactEmail, 'message' => 'PRIVATE CONTACT '.$suffix, 'enquiry_type' => 'correction', 'not_robot' => '1']);
    $contact = rh_row('SELECT * FROM contact_messages WHERE email=?', [$contactEmail]); if ($contact) $contactIds[] = (int)$contact['id'];
    $enquiry = $contact ? rh_row('SELECT * FROM reader_enquiries WHERE contact_id=?', [$contact['id']]) : [];
    rh_check(rh_redirect($response, '/contact') && $enquiry && $enquiry['enquiry_type'] === 'correction', 'A minimal contact form creates the durable typed enquiry without phone/location requirements.');
    rh_check(rh_count('SELECT COUNT(*) FROM newsletter_subscribers WHERE email=?', [$contactEmail]) === 0, 'Contact POST never creates a marketing record.');
    $receipt=rh_request('/contact');rh_check(str_contains($receipt['body'],'data-copy-contact')&&str_contains($receipt['body'],$contact['reference']),'Successful contact shows a copyable durable receipt reference');
    $repeat=rh_request('/contact',$form+['first_name'=>'Contact fixture','email'=>$contactEmail,'message'=>'PRIVATE CONTACT '.$suffix,'enquiry_type'=>'correction','not_robot'=>'1']);rh_check(rh_count('SELECT COUNT(*) FROM contact_messages WHERE email=?',[$contactEmail])===1,'Repeat of the same successful form creates no duplicate enquiry');
    rh_request('/contact',$form+['first_name'=>'Contact fixture','email'=>$contactEmail,'message'=>'CHANGED PRIVATE CONTACT '.$suffix,'enquiry_type'=>'correction','not_robot'=>'1']);$changed=rh_request('/contact');rh_check(str_contains($changed['body'],'CHANGED PRIVATE CONTACT '.$suffix)&&str_contains($changed['body'],'That form was already sent')&&rh_count('SELECT COUNT(*) FROM contact_messages WHERE email=?',[$contactEmail])===1,'Back-button edits remain in a fresh form instead of silently disappearing or creating a duplicate');

    $enquiryPath = '/write/readers/enquiries/'.$enquiry['id']; $response = rh_request($enquiryPath, null, true); $form = rh_form($response, $enquiryPath);
    rh_check($response['status'] === 200 && str_contains($response['body'], 'PRIVATE CONTACT '.$suffix), 'Only the authenticated enquiry view exposes the original message.');
    $edit = $form + ['enquiry_type' => 'correction', 'status' => 'waiting', 'next_action' => 'Review supporting source', 'note' => 'Writer fixture follow-up'];
    rh_check(rh_request($enquiryPath, array_replace($edit, ['csrf' => 'bad']), true)['status'] === 419, 'Enquiry workflow edits require CSRF.');
    rh_check(rh_redirect(rh_request($enquiryPath, $edit, true), $enquiryPath) && reader_enquiry((int)$enquiry['id'])['status'] === 'waiting', 'Writer can save status and next action through the protected form.');
    rh_check(rh_request($enquiryPath, $edit, true)['status'] === 409, 'A stale enquiry form cannot overwrite a newer follow-up.');

    $response = rh_request('/founders/submit'); $form = rh_form($response, '/founders/submit'); $founderEmail = 'founder-'.$suffix.'@example.invalid';
    $submission = $form + ['privacy_ack' => '1', 'submission_type' => 'founder', 'submitter_name' => 'Founder fixture', 'submitter_email' => $founderEmail, 'founder_name' => 'Founder fixture', 'venture_name' => 'Fixture venture '.$suffix, 'country' => 'Kenya', 'story_summary' => 'PRIVATE ORIGINAL STORY '.$suffix, 'metric_basis' => 'not_applicable', 'private_evidence_notes' => 'PRIVATE EVIDENCE '.$suffix];
    $response = rh_request('/founders/submit', $submission); $founder = rh_row('SELECT * FROM founder_submissions WHERE submitter_email=?', [$founderEmail]); $fid = (int)($founder['id'] ?? 0); if ($fid) $founderIds[] = $fid;
    rh_check(rh_redirect($response, '/founders/submit') && $fid > 0 && reader_founder($fid)['receipt_state'] === 'queued', 'Founder submission saves a private record and queues its receipt through HTTP.');
    $token = rh_latest_token($founderEmail, '/founders/follow-up'); $publicPath = '/founders/follow-up?token='.$token; $response = rh_request($publicPath);
    rh_check($response['status'] === 200 && str_contains($response['body'], $founder['reference']) && !str_contains($response['body'], $founderEmail) && !str_contains($response['body'], 'PRIVATE EVIDENCE') && !str_contains($response['body'], 'PRIVATE ORIGINAL STORY'), 'The capability exposes only the safe receipt/status and intentional correspondence.'); rh_private($response, 'Founder capability responses suppress caching, referrers and indexing.');
    rh_check(rh_request('/founders/follow-up?token=invalid')['status'] === 410, 'A guessed founder link cannot retrieve a submission.');
    $writerPath = '/write/readers/founders/'.$fid; $response = rh_request($writerPath, null, true); $form = rh_form($response, $writerPath, 'clarification');
    rh_check(rh_redirect(rh_request($writerPath, $form + ['body' => 'Please explain the observed period.'], true), $writerPath), 'Writer can save a visible clarification request and queue its private link.');
    $response = rh_request($publicPath); $form = rh_form($response, '/founders/follow-up', 'reply');
    rh_check(str_contains($response['body'], 'Please explain the observed period.'), 'The submitter can read the explicit editorial question.');
    rh_check(rh_request('/founders/follow-up', array_replace($form, ['csrf' => 'bad', 'body' => 'Twelve weeks.']))['status'] === 419, 'Private founder replies require CSRF in addition to the capability.');
    $response = rh_request('/founders/follow-up', $form + ['body' => 'Twelve observed weeks.']);
    rh_check(rh_redirect($response, '/founders/follow-up') && reader_founder($fid)['clarification_status'] === 'replied' && (int)reader_founder($fid)['is_public'] === 0, 'A recorded founder reply stays in moderation and never publishes a story.');
    rh_check(rh_request($publicPath)['status'] === 410, 'The previous founder capability cannot replay a response.');

    foreach ($posts as $status => $postId) {
        $response = rh_request('/write/newsletter/new?post='.$postId, null, true); $document = rh_document($response); $selected = false;
        foreach ($document->getElementsByTagName('select') as $select) if ($select->getAttribute('name') === 'source_post_id') foreach ($select->getElementsByTagName('option') as $option) if ($option->getAttribute('value') === (string)$postId && $option->hasAttribute('selected')) $selected = true;
        rh_check($response['status'] === 200 && $selected, 'Campaign handoff retains the selected '.$status.' source ID in the real composer.');
        if ($status === 'published') rh_check(str_contains($response['body'], 'utm_campaign=post-'.$postId) && str_contains($response['body'], 'Read the full article'), 'Public handoff provides a safe newsletter campaign link and summary.');
        else rh_check(str_contains($response['body'], 'publish it before saving') && !str_contains($response['body'], '/my-thoughts/reader-source-'.$status.'-'.$suffix), 'Private/future handoff explains the publication gate and exposes no public URL.');
    }
    rh_check(rh_count('SELECT COUNT(*) FROM newsletter_campaigns WHERE created_by=?', [$actor]) === 0, 'Every handoff remained a prefill, with no automatic draft save, approval or send.');
    rh_check(rh_count('SELECT COUNT(*) FROM newsletter_subscribers WHERE email=?', [$founderEmail]) === 0, 'Founder correspondence never becomes a newsletter signup.');
    echo 'Reader HTTP checks: '.$checks." passed.\n";
} finally {
    // Only random fixture-owned records are removed; no DB is dropped or reused here.
    $fixtureRecipients = [$email, 'founder-'.$suffix.'@example.invalid'];
    foreach (db()->query('SELECT id,payload FROM email_outbox')->fetchAll() as $row) {
        try { $payload = json_decode(decrypt_secret($row['payload']), true, 32, JSON_THROW_ON_ERROR); if (in_array($payload['to'] ?? '', $fixtureRecipients, true)) $outboxIds[(int)$row['id']] = true; } catch (Throwable $ignored) { /* Do not alter unrelated or unreadable jobs. */ }
    }
    foreach ($subscriberIds as $id) { db()->prepare('DELETE FROM newsletter_events WHERE subscriber_id=?')->execute([$id]); db()->prepare("DELETE FROM journey_audit_events WHERE entity_type='newsletter' AND entity_id=?")->execute([$id]); db()->prepare('DELETE FROM newsletter_subscribers WHERE id=?')->execute([$id]); }
    foreach ($founderIds as $id) { db()->prepare("DELETE FROM journey_audit_events WHERE entity_type='founder' AND entity_id=?")->execute([$id]); db()->prepare('DELETE FROM founder_submissions WHERE id=?')->execute([$id]); }
    foreach ($contactIds as $id) { $row = rh_row('SELECT reference FROM contact_messages WHERE id=?', [$id]); if ($row) db()->prepare('DELETE FROM email_outbox WHERE dedupe_key=?')->execute([hash('sha256', 'contact:'.$row['reference'])]); db()->prepare('DELETE FROM contact_messages WHERE id=?')->execute([$id]); }
    foreach (array_keys($outboxIds) as $id) db()->prepare('DELETE FROM email_outbox WHERE id=?')->execute([$id]);
    if ($actor) { db()->prepare('DELETE FROM posts WHERE user_id=?')->execute([$actor]); db()->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$writerEmail]); db()->prepare('DELETE FROM users WHERE id=?')->execute([$actor]); }
    if ($topicId) db()->prepare('DELETE FROM categories WHERE id=?')->execute([$topicId]);
    foreach (array_keys($sessionIds) as $id) db()->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$id]);
    foreach ([$visitorJar, $writerJar] as $file) if (is_file($file)) unlink($file);
}


