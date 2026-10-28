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
$opportunityTempFile='';$opportunityEvidence=[];$opportunityFixtureId='';$opportunitySettings=[];$opportunityEmail='';$checks = 0; $actor = 0; $subscriberIds = []; $founderIds = []; $contactIds = []; $sessionIds = []; $outboxIds = []; $topicId = 0;
$suffix = bin2hex(random_bytes(8)); $email = 'reader-http-'.$suffix.'@example.invalid'; $writerEmail = 'reader-writer-'.$suffix.'@example.invalid'; $password = bin2hex(random_bytes(20));
$visitorJar = tempnam(sys_get_temp_dir(), 'wk-reader-visitor-'); $writerJar = tempnam(sys_get_temp_dir(), 'wk-reader-writer-');
if ($visitorJar === false || $writerJar === false) throw new RuntimeException('Cannot allocate fixture cookie jars.');
function rh_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function rh_row(string $sql, array $params = []): array { $q = db()->prepare($sql); $q->execute($params); return $q->fetch() ?: []; }
function rh_count(string $sql, array $params = []): int { $q = db()->prepare($sql); $q->execute($params); return (int)$q->fetchColumn(); }
function rh_request(string $path, ?array $fields = null, bool $writer = false, string $method = 'GET'): array {
    global $base, $visitorJar, $writerJar, $sessionIds;
    if (!preg_match('~^/(?:write(?:/|$)|newsletter(?:/|\?|$)|enterprises(?:/|\?|$)|opportunities(?:/|\?|$)|hooks/google-calendar(?:$)|webhooks/whatsapp/opportunities(?:\?|$)|founders(?:/|\?|$)|contact(?:\?|$))~', $path) || preg_match('/[\r\n]/', $path)) throw new RuntimeException('Only the local reader routes are allowed.');
    $headers = []; $curl = curl_init($base.$path); $jar = $writer ? $writerJar : $visitorJar;
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROXY => '', CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 15, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_HEADERFUNCTION => static function($handle, string $line) use (&$headers, &$sessionIds): int { $pair = explode(':', $line, 2); if (count($pair) === 2) { $key = strtolower(trim($pair[0])); $headers[$key] = trim($pair[1]); if ($key === 'set-cookie' && preg_match('/^PHPSESSID=([A-Za-z0-9,-]+)/', $headers[$key], $m)) $sessionIds[$m[1]] = true; } return strlen($line); }]);
    if ($fields !== null) { $multipart=false;foreach($fields as$value)if($value instanceof CURLFile)$multipart=true;curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $multipart?$fields:http_build_query($fields)]); }
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
    $msPrivate=rh_request('/write/microsoft');rh_check(in_array($msPrivate['status'],[302,303],true)&&str_contains($msPrivate['headers']['location']??'','/write/login'),'Microsoft workspace requires authentication.');$msActionPrivate=rh_request('/write/microsoft/actions');rh_check(rh_redirect($msActionPrivate,'/write/login'),'Microsoft actions require authentication.');
    foreach(['/write/google/actions','/write/google/actions/new','/write/google/sync','/write/security/sign-in']as$route)rh_check(rh_redirect(rh_request($route),'/write/login'),'Google private route requires sign in: '.$route);
    rh_check(rh_request('/hooks/google-calendar')['status']===405,'Notification endpoint rejects GET');rh_check(rh_request('/hooks/google-calendar',['spoofed'=>'event'])['status']===204,'Unauthenticated notification is ignored without data disclosure');
    foreach(['/write/auth/google','/write/auth/microsoft','/write/verify']as$route)rh_check(rh_request($route,['action'=>'verify','code'=>'000000'])['status']===419,'Authentication actions require CSRF: '.$route);
    $oppPrivate=rh_request('/write/opportunities');rh_check(rh_redirect($oppPrivate,'/write/login'),'Opportunity review inbox requires authentication.');
    $oppIndex=rh_request('/opportunities');rh_check($oppIndex['status']===200&&!str_contains($oppIndex['body'],'JobPosting'), 'Empty opportunity directory renders without fake job schema.');
    $oppFormPage=rh_request('/opportunities/submit');rh_check($oppFormPage['status']===200&&str_contains($oppFormPage['body'],'Save private draft'),'Opportunity submission form renders.');rh_private($oppFormPage,'Private submissions are non-indexed and no-store.');
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
    set_setting('communication.config',json_encode(['owner'=>$actor],JSON_THROW_ON_ERROR),true);$msOwner=rh_request('/write/microsoft',null,true);rh_check($msOwner['status']===200&&str_contains($msOwner['body'],'Your accounts. One workspace.')&&str_contains($msOwner['body'],'isaac@goblisfoundation.com'),'The owner sees three independent Microsoft connection cards.');rh_private($msOwner,'Microsoft workspace is private.');
    foreach(['communication.opportunity.index','communication.opportunity.duplicates']as$key)$opportunitySettings[$key]=rh_row('SELECT * FROM settings WHERE setting_key=?',[$key]);
    $opportunityEmail='opportunity-'.$suffix.'@example.invalid';$oppInput=['contact_name'=>'HTTP Founder','contact_email'=>$opportunityEmail,'relationship'=>'Founder','organisation'=>'HTTP Enterprise','title'=>'HTTP reviewed role','purpose'=>'PRIVATE PURPOSE '.$suffix,'responsibilities'=>'Responsibilities','essential'=>'Experience','application_process'=>'Apply to employer','country'=>'Kenya','county'=>'Nyeri','sub_county'=>'Kieni West','city'=>'Endarasha','salary_min'=>'50000','salary_max'=>'60000','currency'=>'KES','apply_url'=>'https://example.org/jobs','closing_local'=>date('Y-m-d\TH:i',time()+30*86400),'timezone'=>'Africa/Nairobi','no_fee_ack'=>'1','privacy_ack'=>'1','private_evidence'=>'PRIVATE EVIDENCE '.$suffix,'action'=>'submit'];
    $oppStart=rh_request('/opportunities/submit');$oppHidden=rh_form($oppStart,'/opportunities/submit');
    $opportunityTempFile=tempnam(sys_get_temp_dir(),'wk-opportunity-evidence-');if($opportunityTempFile===false)throw new RuntimeException('Evidence fixture allocation failed.');$evidenceBytes="%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF";file_put_contents($opportunityTempFile,$evidenceBytes);
    $oppSaved=rh_request('/opportunities/submit',$oppHidden+$oppInput+['evidence'=>new CURLFile($opportunityTempFile,'application/pdf','private-authority.pdf'),'evidence_ack'=>'1']);rh_check(rh_redirect($oppSaved,'/opportunities/submit'),'Real opportunity form saves privately and redirects.');
    foreach(db()->query("SELECT setting_key,setting_value,is_secret FROM settings WHERE setting_key LIKE 'communication.opportunity.record.%'")->fetchAll()as$row){$candidate=json_decode(decrypt_secret($row['setting_value']),true,32,JSON_THROW_ON_ERROR);if(($candidate['contact']['email']??'')===$opportunityEmail){$opportunityFixtureId=$candidate['id'];$oppRecord=$candidate;$opportunityEvidence=$candidate['evidence']??[];rh_check((int)$row['is_secret']===1,'Opportunity record is encrypted.');break;}}
    rh_check($opportunityFixtureId!=='','Saved opportunity can be independently located.');$oppPath='/write/opportunities/'.$opportunityFixtureId;
    $oppOwner=rh_request($oppPath,null,true);rh_check($oppOwner['status']===200&&str_contains($oppOwner['body'],'PRIVATE EVIDENCE '.$suffix),'Owner can review private authority evidence.');rh_private($oppOwner,'Owner opportunity records stay private.');
    rh_check(count($opportunityEvidence)===1&&$opportunityEvidence[0]['sha256']===hash('sha256',$evidenceBytes),'Multipart evidence is stored with integrity metadata.');
    $docPath=$oppPath.'/evidence/'.$opportunityEvidence[0]['id'];$download=rh_request($docPath,null,true);rh_check($download['status']===200&&$download['body']===$evidenceBytes&&str_contains($download['headers']['content-disposition']??'','attachment'),'Owner evidence download is integrity checked and attachment-only.');rh_private($download,'Evidence downloads are private and non-indexable.');
    rh_check(rh_redirect(rh_request($docPath),'/write/login'),'Owner evidence route does not accept unauthenticated access.');
    $founderDoc='/opportunities/evidence?id='.$opportunityFixtureId.'&file='.$opportunityEvidence[0]['id'].'&token='.$oppRecord['link']['token'];$docResponse=rh_request($founderDoc);rh_check($docResponse['status']===200&&$docResponse['body']===$evidenceBytes,'Only the matching founder capability can download evidence.');
    rh_check(rh_request('/opportunities/evidence?id='.$opportunityFixtureId.'&file='.$opportunityEvidence[0]['id'].'&token='.str_repeat('0',64))['status']===410,'A foreign evidence capability fails closed.');
    $deliveryPage=rh_request('/write/opportunities/delivery',null,true);rh_check($deliveryPage['status']===200&&str_contains($deliveryPage['body'],'connect WhatsApp delivery'),'Owner delivery connection form renders without exposing stored credentials.');rh_private($deliveryPage,'Cloud API configuration stays private.');
    rh_check(rh_request('/write/opportunities/delivery',['enabled'=>'1'],true)['status']===419,'Delivery configuration is protected by CSRF.');
    rh_check(rh_request('/webhooks/whatsapp/opportunities',['fake'=>'receipt'])['status']===403,'Unsigned WhatsApp callback is rejected.');
    $oppCsrf=rh_request($oppPath,['action'=>'publish','version'=>1],true);rh_check($oppCsrf['status']===419,'Opportunity mutation requires CSRF.');
    $oppDraftPublic=rh_request('/opportunities/'.$opportunityFixtureId);rh_check($oppDraftPublic['status']===410&&!str_contains($oppDraftPublic['body'],'PRIVATE PURPOSE'),'Unpublished listing never exposes private content.');
    $oppOwnerForm=rh_form($oppOwner,$oppPath);$oppApproval=rh_request($oppPath,$oppOwnerForm+array_replace($oppInput,['action'=>'request_approval','contact_confirmed'=>'1','send_ack'=>'1']),true);rh_check(rh_redirect($oppApproval,$oppPath),'Owner queues exact final-copy approval.');
    $raw=rh_row('SELECT setting_value FROM settings WHERE setting_key=?',['communication.opportunity.record.'.$opportunityFixtureId]);$oppRecord=json_decode(decrypt_secret($raw['setting_value']),true,32,JSON_THROW_ON_ERROR);$oppToken=$oppRecord['link']['token'];
    $oppReview=rh_request('/opportunities/review?id='.$opportunityFixtureId.'&token='.$oppToken);rh_check($oppReview['status']===200&&str_contains($oppReview['body'],'Approve this exact listing'),'Founder sees exact-copy approval page.');rh_private($oppReview,'Founder capability pages suppress indexing and referrers.');
    $oppResponse=rh_form($oppReview,'/opportunities/review');$oppApproved=rh_request('/opportunities/review',$oppResponse+['decision'=>'approve','approval_ack'=>'1']);rh_check($oppApproved['status']===200&&str_contains($oppApproved['body'],'Your response is saved.'),'Founder approves with an explicit protected POST.');
    $oppReplay=rh_request('/opportunities/review?id='.$opportunityFixtureId.'&token='.$oppToken);rh_check($oppReplay['status']===410,'Used founder approval token cannot replay.');
    $oppOwner=rh_request($oppPath,null,true);$oppSchedule=rh_request($oppPath,rh_form($oppOwner,$oppPath)+['action'=>'schedule','publish_ack'=>'1','schedule_local'=>gmdate('Y-m-d\TH:i',time()+7200),'schedule_timezone'=>'UTC'],true);rh_check(rh_redirect($oppSchedule,$oppPath),'Owner can schedule an exact founder-approved listing.');
    $oppOwner=rh_request($oppPath,null,true);rh_check(str_contains($oppOwner['body'],'Cancel schedule'),'Scheduled listing exposes cancellation control.');rh_check(rh_request('/opportunities/'.$opportunityFixtureId)['status']===410,'Future scheduled listing stays private.');
    $oppUnschedule=rh_request($oppPath,rh_form($oppOwner,$oppPath)+['action'=>'unschedule'],true);rh_check(rh_redirect($oppUnschedule,$oppPath),'Owner can cancel scheduled publication without publishing.');
    $oppOwner=rh_request($oppPath,null,true);$oppPublish=rh_request($oppPath,rh_form($oppOwner,$oppPath)+['action'=>'publish','publish_ack'=>'1'],true);rh_check(rh_redirect($oppPublish,$oppPath),'Owner publishes only after founder approval.');
    $oppLive=rh_request('/opportunities/'.$opportunityFixtureId);rh_check($oppLive['status']===200&&str_contains($oppLive['body'],'JobPosting')&&!str_contains($oppLive['body'],'PRIVATE EVIDENCE')&&!str_contains($oppLive['body'],$opportunityEmail),'Published listing has live job schema but no private identity or evidence.');
    $raw=rh_row('SELECT setting_value FROM settings WHERE setting_key=?',['communication.opportunity.record.'.$opportunityFixtureId]);$oppRecord=json_decode(decrypt_secret($raw['setting_value']),true,32,JSON_THROW_ON_ERROR);$statusReview=rh_request('/opportunities/review?id='.$opportunityFixtureId.'&token='.$oppRecord['link']['token']);rh_check($statusReview['status']===200&&str_contains($statusReview['body'],'Withdraw this opportunity'),'Publication renews founder status and withdrawal access.');
    $oppWithdraw=rh_request('/opportunities/review',rh_form($statusReview,'/opportunities/review')+['decision'=>'withdraw']);rh_check($oppWithdraw['status']===200&&str_contains($oppWithdraw['body'],'Your response is saved.'),'Founder can withdraw a live listing through an explicit protected POST.');
    $oppClosed=rh_request('/opportunities/'.$opportunityFixtureId);rh_check($oppClosed['status']===410&&!str_contains($oppClosed['body'],'JobPosting'),'Withdrawn listing has no application link or job schema.');
    $msCsrf=rh_request('/write/microsoft',['action'=>'connect','account'=>'personal'],true);rh_check($msCsrf['status']===419,'Microsoft OAuth start requires CSRF protection.');
    $actionHome=rh_request('/write/microsoft/actions',null,true);rh_check($actionHome['status']===200&&str_contains($actionHome['body'],'Action history'),'The Microsoft action studio renders for the owner.');rh_private($actionHome,'Action access and history stay private.');
    foreach(['/write/google/actions'=>'Google action studio','/write/google/actions/new'=>'Prepare your content','/write/google/sync'=>'Private calendar refresh','/write/security/sign-in'=>'Sign-in & verification']as$route=>$heading){$r=rh_request($route,null,true);rh_check($r['status']===200&&str_contains($r['body'],$heading),'Google owner page renders: '.$route);rh_private($r,'Google workspace response is private');rh_check(!str_contains($r['body'],'googletagmanager.com'),'Google private content has no analytics');rh_check(rh_request($route,['action'=>'settings'],true)['status']===419,'Google POST requires CSRF');}
    set_setting('communication.config',json_encode(['owner'=>$actor+1000000],JSON_THROW_ON_ERROR),true);
    try {
        foreach(['/write/google/actions','/write/google/sync']as$route)rh_check(rh_request($route,null,true)['status']===403,'A signed-in non-owner cannot access Google actions: '.$route);
        $ownSecurity=rh_request('/write/security/sign-in',null,true);
        rh_check($ownSecurity['status']===200&&str_contains($ownSecurity['body'],'Sign-in & verification'),'Each signed-in user can manage their own verification settings');
        rh_private($ownSecurity,'Personal verification settings remain private');
    } finally {
        set_setting('communication.config',json_encode(['owner'=>$actor],JSON_THROW_ON_ERROR),true);
    }
    set_setting('communication.microsoft.workspace.personal',json_encode(['owner'=>$actor,'status'=>'connected','identity'=>['id'=>'http-fixture','email'=>'iwaithakaw@gmail.com']],JSON_THROW_ON_ERROR),true);
    $newAction=rh_request('/write/microsoft/actions/new',null,true);$actionForm=rh_form($newAction,'/write/microsoft/actions/new');$outboxBefore=rh_count('SELECT COUNT(*) FROM email_outbox');
    $prepared=rh_request('/write/microsoft/actions/new',$actionForm+['action'=>'prepare','kind'=>'email_draft','account'=>'personal','subject'=>'Private action fixture','body'=>'PRIVATE ACTION '.$suffix,'recipients'=>'fixture@example.invalid','recipient_name'=>'Fixture'],true);$actionPath=(string)parse_url($prepared['headers']['location']??'',PHP_URL_PATH);rh_check($prepared['status']===302&&preg_match('~^/write/microsoft/actions/([a-f0-9]{32})$~D',$actionPath,$actionMatch)===1,'A private plan can be prepared before write consent.');
    $actionPreview=rh_request($actionPath,null,true);rh_check($actionPreview['status']===200&&str_contains($actionPreview['body'],'PRIVATE ACTION '.$suffix)&&str_contains($actionPreview['body'],'sandbox=""'),'The owner sees an escaped, sandboxed branded preview.');rh_private($actionPreview,'Action previews are no-store and non-indexable.');
    $actionKey='communication.microsoft.action.'.$actionMatch[1];$nativePlan=rh_row('SELECT setting_value,is_secret FROM settings WHERE setting_key=?',[$actionKey]);$plan=json_decode(decrypt_secret($nativePlan['setting_value']),true,32,JSON_THROW_ON_ERROR);rh_check((int)$nativePlan['is_secret']===1&&$plan['state']==='draft'&&rh_count('SELECT COUNT(*) FROM email_outbox')===$outboxBefore,'Preparation is encrypted and sends no email.');
    $reviewForm=rh_form($actionPreview,$actionPath);$noGrant=rh_request($actionPath,$reviewForm+['action'=>'review','version'=>'1','acknowledge'=>'1'],true);rh_check($noGrant['status']===200&&str_contains($noGrant['body'],'Connect and confirm Outlook drafts access'),'Review cannot bypass the separate write grant.');
    $badActionCsrf=rh_request($actionPath,['action'=>'apply','version'=>'1','apply_confirm'=>'1'],true);rh_check($badActionCsrf['status']===419,'Native Microsoft application requires CSRF protection.');db()->prepare('DELETE FROM settings WHERE setting_key IN (?,?,?)')->execute([$actionKey,'communication.microsoft.workspace.personal','communication.microsoft.action_index.'.$actor]);set_setting('communication.config','[]',true);
    $response = rh_request('/write/readers', null, true); rh_check($response['status'] === 200, 'The shared Writer layout renders the new reader queue.'); rh_private($response, 'Writer reader records carry private response headers.');
    rh_check(rh_request('/write/readers', null, true, 'DELETE')['status'] === 405, 'Unsupported Writer queue methods are rejected.');
    $retired=rh_request('/contact?type=speaking');rh_check($retired['status']===200&&!str_contains($retired['body'],'Invite me to speak')&&!str_contains($retired['body'],'value="speaking"'),'Retired purpose bookmarks fall back without restoring a speaking invitation');
    $response=rh_request('/contact?type=media');rh_check(str_contains($response['body'],'data-contact-context-title>Arrange an interview')&&str_contains($response['body'],'name="contact_request"'),'Current purpose links preselect the enquiry form with repeat protection');$form=rh_form($response,'/contact');
    $failed=rh_request('/contact',$form+['first_name'=>'KEEP THIS NAME','email'=>'invalid','message'=>'KEEP THIS PRIVATE MESSAGE','enquiry_type'=>'media','not_robot'=>'1']);rh_check(rh_redirect($failed,'/contact'),'Invalid address returns to the contact form');$restored=rh_request('/contact');rh_check(str_contains($restored['body'],'KEEP THIS NAME')&&str_contains($restored['body'],'KEEP THIS PRIVATE MESSAGE'),'Failed form retains entered text');
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
    foreach($opportunityEvidence as$doc){$path=$doc['path']??'';if(preg_match('~^storage/release-proofs/[0-9]{4}/[0-9]{2}/opportunity-[a-f0-9]{32}\.(?:pdf|jpg|png)$~D',$path)&&is_file(dirname(__DIR__).'/'.$path))unlink(dirname(__DIR__).'/'.$path);}
    if($opportunityTempFile!==''&&is_file($opportunityTempFile))unlink($opportunityTempFile);
    $fixtureRecipients = [$email, 'founder-'.$suffix.'@example.invalid',$opportunityEmail];
    if($opportunityFixtureId!=='')db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['communication.opportunity.record.'.$opportunityFixtureId]);
    foreach($opportunitySettings as$key=>$old){if($old)db()->prepare('UPDATE settings SET setting_value=?,is_secret=? WHERE setting_key=?')->execute([$old['setting_value'],$old['is_secret'],$key]);else db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute([$key]);}
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
