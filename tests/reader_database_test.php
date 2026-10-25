<?php
declare(strict_types=1);

/** Prepared, fresh loopback fixture DB only. Email uses the encrypted local outbox. */
function rd_env(string $primary, string $fallback): string|false { $v = getenv($primary); return $v !== false ? $v : getenv($fallback); }
$host = rd_env('DB_HOST', 'WK_TEST_DB_HOST'); $port = rd_env('DB_PORT', 'WK_TEST_DB_PORT'); $name = rd_env('DB_DATABASE', 'WK_TEST_DB_NAME'); $user = rd_env('DB_USERNAME', 'WK_TEST_DB_USER'); $pass = rd_env('DB_PASSWORD', 'WK_TEST_DB_PASS');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('WK_TEST_ALLOW_WRITES') !== '1' || !is_string($name) || !preg_match('/^waithaka_test_[a-z0-9_]+_test$/D', $name) || !in_array($host, ['127.0.0.1', '::1'], true) || !is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || !is_string($user) || $user === '' || !is_string($pass)) { fwrite(STDERR, "Use explicit testing/write opt-in, numeric-loopback DB_* credentials and a fresh waithaka_test_*_test schema.\n"); exit(2); }
$CONFIG = ['app_env' => 'testing', 'app_key' => 'reader-database-fixture-key-not-production', 'base_url' => 'https://www.waithakandegwa.com', 'timezone' => 'Africa/Nairobi', 'worker_enabled' => false, 'db' => ['host' => $host, 'port' => (int)$port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4'], 'mail' => ['transport' => 'disabled', 'from' => 'fixture@example.invalid', 'from_name' => 'Reader fixture', 'reply_to' => '', 'postal_address' => 'Synthetic fixture address']];
$APP_INSTALLED = true; date_default_timezone_set('Africa/Nairobi');
foreach (['functions', 'journeys', 'operations', 'mail_feedback', 'newsletter', 'newsletter_routes', 'reader', 'communication', 'contact_experience', 'enterprises'] as $module) require_once __DIR__.'/../app/'.$module.'.php';
$checks = 0;
function rd_check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException('FAIL: '.$label); $checks++; }
function rd_reject(callable $action, string $label): void { try { $action(); } catch (ReaderValidationException|ReaderConflictException|JourneyValidationException|JourneyConflictException|NewsletterValidationException|NewsletterConflictException $error) { rd_check(true, $label); return; } rd_check(false, $label); }
function rd_row(string $sql, array $params = []): array { $q = db()->prepare($sql); $q->execute($params); return $q->fetch() ?: []; }
function rd_scalar(string $sql, array $params = []): mixed { $q = db()->prepare($sql); $q->execute($params); return $q->fetchColumn(); }
function rd_subscriber(string $label, string $status = 'confirmed'): int { db()->prepare('INSERT INTO newsletter_subscribers(email,name,status,consent_version,requested_at,confirmed_at,created_at,updated_at) VALUES(?,?,?,?,NOW(),?,NOW(),NOW())')->execute([$label.'@example.invalid', $label, $status, 'fixture-v1', $status === 'confirmed' ? date('Y-m-d H:i:s') : null]); return (int)db()->lastInsertId(); }
function rd_token(string $url): string { parse_str((string)parse_url($url, PHP_URL_QUERY), $values); return (string)($values['token'] ?? ''); }
function rd_latest_payload(): array { return json_decode(decrypt_secret((string)rd_scalar('SELECT payload FROM email_outbox ORDER BY id DESC LIMIT 1')), true, 32, JSON_THROW_ON_ERROR); }
function rd_payload_token(array $payload, string $path): string { if (!preg_match('~'.preg_quote($path, '~').'\?token=([^"< ]+)~', $payload['html'], $m)) throw new RuntimeException('Fixture token missing.'); return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'); }
function rd_reader_gate(array $link, array $payload, int $outbox): array { $gate = reader_email_gate('reader-link:'.$link['nonce_hash'], $payload, $outbox); try { return $gate; } finally { reader_email_unlock($gate); } }
foreach (['newsletter_subscribers', 'founder_submissions', 'contact_messages', 'email_outbox', 'reader_links', 'newsletter_campaigns'] as $table) if ((int)rd_scalar('SELECT COUNT(*) FROM '.$table) !== 0) throw new RuntimeException('Use a fresh prepared fixture database; '.$table.' is not empty.');
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Reader fixture', 'reader-writer@example.invalid', password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT)]); $actor = (int)db()->lastInsertId();
db()->exec("INSERT INTO categories(name,slug) VALUES('Reader fixture topic','reader-fixture-topic'),('Other fixture topic','reader-other-topic')");
$topic = (int)rd_scalar("SELECT id FROM categories WHERE slug='reader-fixture-topic'"); $other = (int)rd_scalar("SELECT id FROM categories WHERE slug='reader-other-topic'");
$posts = [];
foreach (['published', 'draft', 'scheduled'] as $status) { db()->prepare('INSERT INTO posts(user_id,category_id,title,slug,standfirst,content_html,status,published_at) VALUES(?,?,?,?,?,?,?,?)')->execute([$actor, $topic, 'Reader '.$status, 'reader-'.$status, 'Safe <summary> text', '<p>Full article.</p>', $status, date('Y-m-d H:i:s', time() + ($status === 'scheduled' ? 86400 : -120))]); $posts[$status] = (int)db()->lastInsertId(); }
$seasons = [];
foreach (['published', 'draft'] as $status) { db()->prepare('INSERT INTO journey_seasons(slug,title,venture_name,summary,baseline,publication_status,published_at,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())')->execute(['reader-season-'.$status, 'Reader season '.$status, 'Synthetic venture', 'Summary', 'Baseline', $status, date('Y-m-d H:i:s', time() - 60), $actor]); $seasons[$status] = (int)db()->lastInsertId(); db()->prepare('INSERT INTO journey_episodes(season_id,post_id,episode_order,is_published,created_at,updated_at) VALUES(?,?,1,1,NOW(),NOW())')->execute([$seasons[$status], $posts['published']]); }
$choices = reader_preference_choices();
rd_check(in_array($seasons['published'], array_column($choices['journeys'], 'id'), true) && !in_array($seasons['draft'], array_column($choices['journeys'], 'id'), true), 'Only public journeys appear as new reader choices.');
rd_reject(fn() => reader_preference_values(['receive_all' => 0, 'journey_ids' => [$seasons['draft']]]), 'A guessed private journey cannot be selected.');
$preserved = reader_preference_choices(['journey_ids' => [$seasons['draft']]]);
rd_check(!str_contains(reader_json($preserved), 'Reader season draft') && str_contains(reader_json($preserved), 'currently unavailable'), 'Previously selected unavailable journeys preserve their ID without leaking a private title.');

reader_request_signup('new-reader@example.invalid', 'Synthetic', ['receive_all' => 0, 'topic_ids' => [$topic]]);
$new = (int)rd_scalar("SELECT id FROM newsletter_subscribers WHERE email='new-reader@example.invalid'");
rd_check(newsletter_subscriber($new)['status'] === 'pending' && reader_preferences($new)['topic_ids'] === [$topic], 'Signup stores choices while keeping the subscription unconfirmed.');
rd_reject(fn() => reader_issue_link('preferences', $new), 'Pending subscribers cannot obtain preference capabilities.');
$confirmation = rd_payload_token(rd_latest_payload(), '/newsletter/confirm');
rd_check(newsletter_apply_token($confirmation, 'confirm'), 'Existing double opt-in confirms the exact new subscriber.');
reader_request_signup('new-reader@example.invalid', 'Someone else', ['receive_all' => 1]);
rd_check(reader_preferences($new)['receive_all'] === 0 && reader_preferences($new)['topic_ids'] === [$topic], 'An anonymous signup cannot overwrite an already-confirmed reader’s choices.');
$link = reader_issue_link('preferences', $new); $otherLink = reader_issue_link('preferences', $new);
rd_check(reader_link_record($link['token'], 'preferences') !== null && rd_scalar('SELECT used_at FROM reader_links WHERE id=?', [$link['id']]) === null, 'Opening a preference link is read-only.');
rd_check(!str_contains(reader_json(rd_row('SELECT * FROM reader_links WHERE id=?', [$link['id']])), explode('.', $link['token'])[1]), 'The random bearer secret is not stored in the link table.');
$before = (int)newsletter_subscriber($new)['consent_revision'];
rd_check(reader_update_preferences($link['token'], ['receive_all' => 0, 'journey_ids' => [$seasons['published']]]), 'A valid signed POST changes the reader’s choices.');
rd_check(newsletter_subscriber($new)['status'] === 'confirmed' && (int)newsletter_subscriber($new)['consent_revision'] === $before + 1, 'Choice changes retain confirmation but invalidate the previous delivery consent snapshot.');
rd_check(!reader_update_preferences($link['token'], ['receive_all' => 1]) && reader_link_record($otherLink['token'], 'preferences') === null, 'Used and older preference links cannot replay or overwrite newer choices.');
$link = reader_issue_link('preferences', $new);
db()->prepare('UPDATE newsletter_subscribers SET email=? WHERE id=?')->execute(['changed-reader@example.invalid', $new]);
rd_check(reader_link_record($link['token'], 'preferences') === null, 'A changed recipient address invalidates the old link.');
db()->prepare('UPDATE newsletter_subscribers SET email=? WHERE id=?')->execute(['new-reader@example.invalid', $new]);
$link = reader_issue_link('preferences', $new); $unsubscribe = rd_token(newsletter_one_click_url($new));
rd_check(newsletter_apply_one_click($unsubscribe) && !reader_update_preferences($link['token'], ['receive_all' => 1]), 'One-click unsubscribe makes existing preference links powerless.');
rd_check(newsletter_subscriber($new)['status'] === 'unsubscribed', 'Managing preferences cannot accidentally resubscribe.');
$queueBefore = (int)rd_scalar('SELECT COUNT(*) FROM email_outbox');
reader_request_preferences('unknown-reader@example.invalid'); reader_request_preferences('new-reader@example.invalid');
rd_check((int)rd_scalar('SELECT COUNT(*) FROM email_outbox') === $queueBefore, 'Unknown and unsubscribed requests both create no management email.');
$suppressed = rd_subscriber('suppressed-reader'); $suppressedLink = reader_issue_link('preferences', $suppressed);
db()->prepare("UPDATE newsletter_subscribers SET suppressed_at=NOW(),suppression_reason='manual' WHERE id=?")->execute([$suppressed]);
rd_check(!reader_update_preferences($suppressedLink['token'], ['receive_all' => 1]), 'Suppression blocks preferences even when a previously issued link is validly signed.');
reader_request_signup('suppressed-reader@example.invalid', 'No change', ['receive_all' => 0]);
rd_check(newsletter_subscriber($suppressed)['suppressed_at'] !== null, 'Signup does not clear suppression.');

$requestId = rd_subscriber('management-reader'); reader_request_preferences('management-reader@example.invalid');
$queued = rd_row('SELECT * FROM reader_links WHERE subscriber_id=? ORDER BY id DESC LIMIT 1', [$requestId]); $payload = rd_latest_payload();
rd_check((int)$queued['outbox_id'] > 0 && rd_reader_gate($queued, $payload, (int)$queued['outbox_id'])['action'] === 'send', 'Management mail uses the encrypted outbox and a current recipient-bound gate.');
$otherPdo = new PDO('mysql:host='.$host.';port='.$port.';dbname='.$name.';charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$lock = newsletter_lock_name('subscriber', $requestId); $q = $otherPdo->prepare('SELECT GET_LOCK(?,0)'); $q->execute([$lock]);
rd_check(rd_reader_gate($queued, $payload, (int)$queued['outbox_id'])['action'] === 'defer', 'An in-flight subscription mutation defers a management email.');
$q = $otherPdo->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lock]);
db()->prepare('UPDATE reader_links SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$queued['id']]);
rd_check(rd_reader_gate($queued, $payload, (int)$queued['outbox_id'])['action'] === 'suppress', 'The worker rejects an expired management link.');
$allStop = reader_issue_link('preferences', $requestId);
rd_reject(fn() => reader_update_preferences($allStop['token'], ['action' => 'unsubscribe']), 'Unsubscribe from preferences requires an explicit acknowledgement.');
rd_check(reader_update_preferences($allStop['token'], ['action' => 'unsubscribe', 'unsubscribe_ack' => '1']) && newsletter_subscriber($requestId)['status'] === 'unsubscribed', 'The protected preference form uses the same unsubscribe state and revision boundary.');

db()->exec("UPDATE newsletter_subscribers SET status='unsubscribed',consent_revision=consent_revision+1");
$all = rd_subscriber('all-writing'); $topicReader = rd_subscriber('topic-reader'); $journeyReader = rd_subscriber('journey-reader'); $unrelated = rd_subscriber('unrelated-reader');
reader_store_preferences($topicReader, ['receive_all' => 0, 'topic_ids' => [$topic], 'journey_ids' => []]);
reader_store_preferences($journeyReader, ['receive_all' => 0, 'topic_ids' => [], 'journey_ids' => [$seasons['published']]]);
reader_store_preferences($unrelated, ['receive_all' => 0, 'topic_ids' => [$other], 'journey_ids' => []]);
$campaign = newsletter_save_campaign(['title' => 'Reader scope fixture', 'subject' => 'Reader scope fixture', 'body_html' => '<p>Approved edition.</p>', 'source_post_id' => $posts['published']], $actor);
$snapshot = newsletter_snapshot(newsletter_campaign($campaign));
rd_check($snapshot['reader_audience']['topic_ids'] === [$topic] && $snapshot['reader_audience']['journey_ids'] === [$seasons['published']], 'Approval freezes public topic/journey scope and excludes a private journey.');
$revision = newsletter_approve($campaign, 1, hash('sha256', newsletter_json($snapshot)), $actor);
$count = newsletter_schedule($campaign, $revision, date('Y-m-d\TH:i', time() + 3600), (int)newsletter_campaign($campaign)['state_version'], $actor);
rd_check($count === 3 && (int)rd_scalar('SELECT COUNT(*) FROM newsletter_deliveries WHERE subscriber_id=?', [$unrelated]) === 0, 'The audience includes all-writing, topic and journey matches but excludes unrelated preferences.');
db()->prepare('UPDATE newsletter_campaigns SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=?')->execute([$campaign]);
rd_check(newsletter_work(10)['queued'] === 3, 'Only the approved matching audience is queued.');
$delivery = rd_row('SELECT * FROM newsletter_deliveries WHERE campaign_id=? AND subscriber_id=?', [$campaign, $topicReader]);
$deliveryPayload = json_decode(decrypt_secret(rd_scalar('SELECT payload FROM email_outbox WHERE id=?', [$delivery['outbox_id']])), true, 32, JSON_THROW_ON_ERROR);
rd_check(str_contains($deliveryPayload['html'], '/newsletter/preferences?token=') && isset($deliveryPayload['headers']['List-Unsubscribe-Post']), 'Real queued editions contain a private preference link and preserve one-click unsubscribe headers.');
$change = reader_issue_link('preferences', $topicReader); reader_update_preferences($change['token'], ['receive_all' => 0, 'topic_ids' => [$other]]);
$gate = newsletter_email_gate('newsletter-campaign:'.$delivery['delivery_key'], $deliveryPayload, (int)$delivery['outbox_id']);
try { rd_check($gate['action'] === 'suppress', 'A preference change after queuing prevents provider dispatch of the old edition.'); } finally { newsletter_email_unlock($gate); }
rd_check(!reader_newsletter_matches($journeyReader, ['subject' => 'Legacy approval']), 'Legacy unscoped approvals fail closed for restricted readers.');
$prefill = newsletter_source_prefill($posts['published']);
rd_check(str_contains($prefill['body_html'], '/my-thoughts/reader-published') && !str_contains($prefill['body_html'], '<summary>'), 'Public article handoff contains escaped summary and full-article link.');
foreach (['draft', 'scheduled'] as $status) {
    $prefill = newsletter_source_prefill($posts[$status]); $data = newsletter_editor_data(null, $prefill);
    rd_check($prefill['source_post_id'] === $posts[$status] && $prefill['body_html'] === '' && $data['sourceNotice'] !== null && in_array($posts[$status], array_map('intval', array_column($data['posts'], 'id')), true), 'Private/future newsletter handoffs retain their source ID without a private URL or silent selection drop.');
}

set_setting('contact.recipient', 'editor@example.invalid');
$enterpriseArticles=enterprise_articles(array_values($posts));rd_check(array_map('intval',array_column($enterpriseArticles,'id'))===[$posts['published']],'Enterprise areas link only published articles; drafts and future scheduled versions stay private.');
$beforeSubscribers = (int)rd_scalar('SELECT COUNT(*) FROM newsletter_subscribers');
$message = ['reference' => 'WK-READER-000001', 'first_name' => 'Questioner', 'email' => 'questioner@example.invalid', 'message' => 'Please clarify the source.'];
$enquiry = reader_contact_submit($message, ['enquiry_type' => 'correction']);
$geo=reader_contact_submit(array_replace($message,['reference'=>'WK-LOCATION-000001']),['enquiry_type'=>'general','country'=>'Kenya','county'=>'Nyeri','sub_county'=>'Kieni West','city'=>'Endarasha'],false);
rd_check(location_record('enquiry',$geo)['sub_county']==='Kieni West','Enquiry location survives private persistence.');
$geoRaw=rd_row('SELECT setting_value,is_secret FROM settings WHERE setting_key=?',['private.location.enquiry.'.$geo]);
rd_check((int)$geoRaw['is_secret']===1&&!str_contains($geoRaw['setting_value'],'Endarasha'),'Location metadata is encrypted at rest.');

rd_check(reader_enquiry($enquiry)['enquiry_type'] === 'correction' && reader_enquiry($enquiry)['notification_state'] === 'queued' && (int)reader_enquiry($enquiry)['email_sent'] === 0, 'Contact and typed enquiry are durable before notification; queueing is not delivery.');
rd_check((int)rd_scalar('SELECT COUNT(*) FROM newsletter_subscribers') === $beforeSubscribers, 'An enquiry never becomes a marketing subscription.');
db()->exec("CREATE TRIGGER reader_fixture_outbox_failure BEFORE INSERT ON email_outbox FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic fixture queue failure'");
try {
    $failed = reader_contact_submit(array_replace($message, ['reference' => 'WK-READER-000002']));
    rd_check(reader_enquiry($failed)['notification_state'] === 'failed' && reader_enquiry($failed)['message'] === $message['message'], 'A failed email queue cannot lose the already committed enquiry.');
} finally { db()->exec('DROP TRIGGER reader_fixture_outbox_failure'); }
$edit = ['row_version' => 1, 'enquiry_type' => 'correction', 'status' => 'responded', 'next_action' => 'Check the published correction', 'next_action_at' => date('Y-m-d\TH:i', time() - 3600), 'record_response' => '1', 'note' => 'Sent a source clarification by email.'];
rd_reject(fn() => reader_enquiry_update($enquiry, $edit, $actor), 'A claimed response cannot be recorded without acknowledging an actual send.');
reader_enquiry_update($enquiry, $edit + ['response_sent_ack' => '1'], $actor);
rd_check(reader_enquiry($enquiry)['last_response_at'] !== null && (int)rd_scalar('SELECT COUNT(*) FROM reader_enquiry_events WHERE enquiry_id=?', [$enquiry]) === 1, 'Writer records the response time and durable history.');
rd_reject(fn() => reader_enquiry_update($enquiry, $edit + ['response_sent_ack' => '1'], $actor), 'Concurrent enquiry edits use optimistic version checks.');

$submission = ['privacy_ack' => '1', 'submission_type' => 'founder', 'submitter_name' => 'Founder fixture', 'submitter_email' => 'founder@example.invalid', 'founder_name' => 'Founder fixture', 'venture_name' => 'Synthetic venture', 'country' => 'Kenya', 'story_summary' => 'Original private account.', 'metric_basis' => 'not_applicable', 'private_evidence_notes' => 'PRIVATE EVIDENCE NEVER SHARED'];
$submission+=['county'=>'Nyeri','sub_county'=>'Kieni West','city'=>'Endarasha','phone_code'=>'+254','phone'=>'0712345678'];
$reference = founders_submit(founders_submission_values($submission)); $founder = (int)rd_scalar('SELECT id FROM founder_submissions WHERE reference=?', [$reference]); rd_check(location_record('founder',$founder)['phone']==='+254712345678'&&location_record('founder',$founder)['county']==='Nyeri','Founder phone and structured location are saved privately.'); $receipt = rd_latest_payload(); $founderToken = rd_payload_token($receipt, '/founders/follow-up');
rd_check(reader_founder($founder)['receipt_state'] === 'queued' && str_contains($receipt['html'], $reference), 'Founder submission automatically gets a durable reference and queued private receipt.');
rd_check(!str_contains($receipt['html'], $submission['private_evidence_notes']) && (int)rd_scalar('SELECT COUNT(*) FROM newsletter_subscribers') === $beforeSubscribers, 'Founder receipts disclose no private evidence and never subscribe the sender.');
$queueBefore = (int)rd_scalar('SELECT COUNT(*) FROM email_outbox'); reader_founder_received($reference);
rd_check((int)rd_scalar('SELECT COUNT(*) FROM email_outbox') === $queueBefore, 'Repeated receipt hooks do not duplicate the initial email.');
rd_reject(fn() => reader_founder_respond($founderToken, ['action' => 'grant', 'permission_ack' => '1', 'followup_version' => 1]), 'A founder cannot grant unspecified publication permission.');
reader_founder_writer_update($founder, ['action' => 'clarification', 'body' => 'What was the observation period?', 'followup_version' => reader_founder($founder)['followup_version']], $actor);
rd_check(reader_founder($founder)['review_status'] === 'needs_info', 'A request uses the existing editorial needs-information state.');
rd_reject(fn() => reader_founder_respond($founderToken, ['action' => 'reply', 'body' => 'Twelve weeks.', 'followup_version' => 1]), 'A stale public form cannot answer a newer editorial request.');
$next = reader_founder_respond($founderToken, ['action' => 'reply', 'body' => 'Twelve observed weeks.', 'followup_version' => reader_founder($founder)['followup_version']]);
rd_check($next !== null && reader_founder($founder)['clarification_status'] === 'replied' && reader_link_record($founderToken, 'founder') === null, 'A private reply is recorded once and consumes the old capability.');
$founderToken = rd_token($next); $oldExpiry = reader_link_record($founderToken, 'founder')['expires_at'];
reader_founder_writer_update($founder, ['action' => 'permission', 'body' => 'Article version 2, named quotations, website only. No photographs.', 'followup_version' => reader_founder($founder)['followup_version']], $actor);
rd_reject(fn() => reader_founder_respond($founderToken, ['action' => 'grant', 'followup_version' => reader_founder($founder)['followup_version']]), 'The exact permission scope needs explicit founder acknowledgement.');
$next = reader_founder_respond($founderToken, ['action' => 'grant', 'permission_ack' => '1', 'followup_version' => reader_founder($founder)['followup_version']]); $founderToken = rd_token($next);
rd_check(reader_founder($founder)['permission_status'] === 'granted' && (int)reader_founder($founder)['permission_confirmed'] === 0 && (int)reader_founder($founder)['is_public'] === 0, 'A submitter permission response never auto-approves or publishes the story.');
rd_check(reader_link_record($founderToken, 'founder')['expires_at'] === $oldExpiry, 'Response rotation does not extend the capability lifetime.');
$review = ['row_version' => reader_founder($founder)['row_version'], 'review_status' => 'approved', 'review_notes' => 'PRIVATE MODERATOR NOTES', 'permission_confirmed' => '1', 'claims_reviewed' => '1', 'is_public' => '1', 'associated_post_id' => $posts['published'], 'consent_reference' => 'Reader permission response recorded for article version 2'];
founders_save_review($founder, $review, $actor);
rd_check((int)reader_founder($founder)['is_public'] === 1, 'Existing moderation still independently checks permission, claims and a public article.');
$next = reader_founder_respond($founderToken, ['action' => 'withdraw', 'withdraw_ack' => '1', 'followup_version' => reader_founder($founder)['followup_version']]);
rd_check(reader_founder($founder)['permission_status'] === 'withdrawn' && (int)reader_founder($founder)['is_public'] === 0 && (int)reader_founder($founder)['permission_confirmed'] === 0, 'Withdrawal immediately disables the Founder Stories publication gate.');
$review['row_version'] = reader_founder($founder)['row_version'];
rd_reject(fn() => founders_save_review($founder, $review, $actor), 'Old permission cannot be silently reused after a recorded withdrawal.');
rd_check((int)reader_founder($founder)['is_public'] === 0 && (int)reader_founder($founder)['row_version'] === (int)$review['row_version'], 'Rejected moderation rolls back publication and the optimistic version.');
$founderToken = rd_token($next);
reader_founder_writer_update($founder, ['action' => 'permission', 'body' => 'Article version 3, revised quotations, website only.', 'followup_version' => reader_founder($founder)['followup_version']], $actor);
$requested = reader_founder($founder);
rd_check($requested['permission_status'] === 'requested' && $requested['consent_reference'] === $review['consent_reference'] && (int)$requested['permission_confirmed'] === 0 && (int)$requested['is_public'] === 0, 'Re-requesting permission retains historical evidence without restoring withdrawn publication gates.');
$review['row_version'] = $requested['row_version'];
rd_reject(fn() => founders_save_review($founder, $review, $actor), 'Opening a new request cannot reuse the withdrawn consent reference before a fresh founder grant.');
rd_reject(fn() => founders_save_review($founder, array_replace($review, ['consent_reference' => 'Different reference text, without a new founder grant']), $actor), 'Changing the reference text cannot bypass the outstanding protected permission response.');
rd_check((int)reader_founder($founder)['is_public'] === 0 && (int)reader_founder($founder)['row_version'] === (int)$review['row_version'] && reader_founder($founder)['consent_reference'] === $requested['consent_reference'], 'Pending-request rejections roll back publication, version and attempted reference changes.');
$next = reader_founder_respond($founderToken, ['action' => 'grant', 'permission_ack' => '1', 'followup_version' => reader_founder($founder)['followup_version']]); $founderToken = rd_token($next);
rd_check(reader_founder($founder)['permission_status'] === 'granted' && (int)reader_founder($founder)['permission_confirmed'] === 0 && (int)reader_founder($founder)['is_public'] === 0, 'A fresh private-link grant after withdrawal remains evidence pending manual moderation.');
$review['row_version'] = reader_founder($founder)['row_version']; $review['consent_reference'] = 'Reader permission response recorded for article version 3';
founders_save_review($founder, $review, $actor);
rd_check((int)reader_founder($founder)['is_public'] === 1 && (int)reader_founder($founder)['permission_confirmed'] === 1, 'Fresh permission can be independently reviewed and approved after a withdrawal.');
$review['row_version'] = reader_founder($founder)['row_version'];
reader_founder_writer_update($founder, ['action' => 'permission', 'body' => 'Article version 4, additional named quotation, website only.', 'followup_version' => reader_founder($founder)['followup_version']], $actor);
rd_check(reader_founder($founder)['permission_status'] === 'requested' && (int)reader_founder($founder)['permission_confirmed'] === 0 && (int)reader_founder($founder)['is_public'] === 0, 'A changed permission request suspends the previous collection approval while awaiting its own grant.');
rd_reject(fn() => founders_save_review($founder, $review, $actor), 'A permission request advances the moderation version and rejects the old approval form.');
$next = reader_founder_respond($founderToken, ['action' => 'decline', 'followup_version' => reader_founder($founder)['followup_version']]);
reader_founder_writer_update($founder, ['action' => 'permission', 'body' => 'Article version 5, narrowed scope, website only.', 'followup_version' => reader_founder($founder)['followup_version']], $actor);
$review['row_version'] = reader_founder($founder)['row_version'];
rd_reject(fn() => founders_save_review($founder, $review, $actor), 'Re-requesting after a decline also needs a fresh grant before confirming publication.');
$nomination = founders_submit(founders_submission_values(array_replace($submission, ['submission_type' => 'nomination', 'submitter_email' => 'nominator@example.invalid']))); $nominee = (int)rd_scalar('SELECT id FROM founder_submissions WHERE reference=?', [$nomination]);
rd_reject(fn() => reader_founder_writer_update($nominee, ['action' => 'permission', 'body' => 'Permission?', 'followup_version' => reader_founder($nominee)['followup_version']], $actor), 'A nominator cannot receive a founder permission grant form.');
$offlineReview = array_replace($review, ['row_version' => reader_founder($nominee)['row_version'], 'consent_reference' => 'Separate founder permission fixture: written grant dated 2026-10-21, evidence record 1']);
founders_save_review($nominee, $offlineReview, $actor);
rd_check(reader_founder($nominee)['permission_status'] === 'not_requested' && (int)reader_founder($nominee)['permission_confirmed'] === 1, 'A separately documented founder grant for a nomination retains the original offline moderation path.');
rd_check((int)rd_scalar('SELECT COUNT(*) FROM newsletter_subscribers') === $beforeSubscribers, 'All founder follow-up transitions preserve the independent marketing audience.');
rd_check(array_keys(reader_operations_summary()) === ['enquiries_open', 'enquiries_overdue', 'founder_waiting', 'founder_replies_pending'], 'Operations receives aggregate counts without contact or subscriber data.');
rd_check(!email_transport_ready(), 'Every test remained on disabled mail transport.');
echo 'Reader database checks: '.$checks." passed.\n";


set_setting('contact.recipient','contact-host-fixture@example.invalid');$reference='WK-SCOPED-'.strtoupper(bin2hex(random_bytes(5)));$contactId=reader_contact_submit(['reference'=>$reference,'first_name'=>'Synthetic','last_name'=>'Fixture','email'=>'contact-receipt-fixture@example.invalid','message'=>'Synthetic scoped receipt fixture'],['enquiry_type'=>'collaboration']);
$legacy=enqueue_email('legacy-fixture@example.invalid','Historical fixture','<p>Do not send.</p>','legacy-unregistered-contact-fixture');contact_delivery_register($contactId,$reference);$scoped=contact_delivery_ids($contactId,$reference);rd_check(count($scoped)===2&&!in_array($legacy,$scoped,true),'Only new contact owner/receipt messages are registered');rd_check(contact_delivery_ids($contactId,'WK-WRONG')===[],'A foreign reference cannot select delivery ids');contact_delivery_work();rd_check(rd_scalar('SELECT status FROM email_outbox WHERE id=?',[$scoped[0]])==='pending','Disabled transport does not falsely acknowledge a receipt');
$deliveries=0;contact_delivery_work(static function(array $payload,string $key)use(&$deliveries):string{$deliveries++;return 'synthetic-contact-ack-'.$deliveries;});rd_check($deliveries===2,'Only the two explicitly registered new-contact messages use the test transport');rd_check(rd_scalar('SELECT status FROM email_outbox WHERE id=?',[$legacy])==='pending','Historical unrelated outbox remains paused');contact_delivery_work(static function()use(&$deliveries):string{$deliveries++;return 'unexpected';});rd_check($deliveries===2,'Acknowledged contact delivery does not send twice');rd_check((int)rd_scalar('SELECT email_sent FROM contact_messages WHERE id=?',[$contactId])===1,'Existing contact acknowledgement bookkeeping is preserved');
echo "PASS: scoped future contact receipts, owner notifications and historical queue isolation.\n";

$longContact=['reference'=>'WK-LONGNAME-FIXTURE','first_name'=>str_repeat('A',120),'last_name'=>str_repeat('B',120),'email'=>'long-contact@example.invalid','phone_code'=>'','phone'=>'','country'=>'','city'=>'','message'=>'Synthetic valid maximum-name fixture'];rd_check(send_contact_email($longContact),'Valid long names still queue the host notification within subject limits');

$guardMessage=['reference'=>'WK-GUARD-HELD','first_name'=>'Synthetic','email'=>'guard-fixture@example.invalid','message'=>'Retain this genuine enquiry for review'];
$before=(int)rd_scalar('SELECT COUNT(*) FROM email_outbox');
$heldId=contact_guard_receive($guardMessage,['website'=>'trap','enquiry_type'=>'general'],true,true);
$held=reader_enquiry($heldId);rd_check($held&&$held['message']===$guardMessage['message']&&$held['notification_state']==='held','Hidden-field submissions are retained with held notification state');
rd_check((int)rd_scalar('SELECT COUNT(*) FROM email_outbox')===$before,'Held submissions create no owner or sender notification');
$meta=contact_guard_meta($heldId);contact_delivery_register((int)$held['contact_id'],$held['reference']);rd_check(comm_get('contact.delivery.'.$held['contact_id'])===[],'Registration cannot bypass a held review');
$reject=false;try{contact_guard_review($actor,['id'=>$heldId,'version'=>$meta['version'],'action'=>'release','review_note'=>'Checked']);}catch(InvalidArgumentException){$reject=true;}rd_check($reject,'Release requires explicit confirmation');
contact_guard_review($actor,['id'=>$heldId,'version'=>$meta['version'],'action'=>'release','review_note'=>'Synthetic reviewer confirmed','review_consent'=>'1']);
rd_check(contact_guard_meta($heldId)['state']==='released'&&count(contact_delivery_ids((int)$held['contact_id'],$held['reference']))===2,'Reviewed release queues exactly owner and sender service messages');
$reject=false;try{contact_guard_review($actor,['id'=>$heldId,'version'=>$meta['version'],'action'=>'release','review_note'=>'Again','review_consent'=>'1']);}catch(RuntimeException){$reject=true;}rd_check($reject,'Already released messages cannot be released twice');
$duplicateId=contact_guard_receive(array_replace($guardMessage,['reference'=>'WK-GUARD-DUPLICATE']),['enquiry_type'=>'general'],true,true);
rd_check(in_array('Duplicate message',contact_guard_meta($duplicateId)['reasons'],true)&&reader_enquiry($duplicateId)['notification_state']==='held','Duplicate enquiries remain saved while notifications are suppressed');
$limits=contact_guard_config();rd_check(in_array('IP limit: more than five messages per minute',contact_guard_reasons([],true,true,5,0,0,false,$limits),true),'Sixth submission in a minute is held');
$_SESSION['contact_issued']=[];$nonce=contact_form_issue();rd_check(contact_form_consume($nonce)&&!contact_form_consume($nonce),'Server-issued contact token is consumed exactly once');
echo "PASS: durable held enquiries, zero pre-review mail, explicit deduplicated release, duplicate detection, IP limit and one-use token.\n";

$trashCfg=comm_get('config');comm_put('config',array_replace($trashCfg,['owner'=>$actor]));
$trashBefore=reader_enquiry($heldId);$openBefore=reader_operations_summary()['enquiries_open'];
reader_enquiry_trash($heldId,(int)$trashBefore['row_version'],$actor);
rd_check((bool)reader_enquiry($heldId)['is_trashed'],'Deleted message is in Trash');
rd_check(reader_enquiry($heldId)['message']===$trashBefore['message'],'Deletion retains original content for restore');
rd_check(contact_delivery_ids((int)$trashBefore['contact_id'],$trashBefore['reference'])===[],'Trashed messages do not release scoped notifications');
rd_reject(fn()=>reader_enquiry_trash($heldId,(int)$trashBefore['row_version'],$actor,true),'Stale message cannot be restored over current state');
rd_reject(fn()=>reader_enquiry_trash($heldId,(int)reader_enquiry($heldId)['row_version'],$actor+1000,true),'Another account cannot restore owner correspondence');
reader_enquiry_trash($heldId,(int)reader_enquiry($heldId)['row_version'],$actor,true);
rd_check(!reader_enquiry($heldId)['is_trashed'],'Restore returns message to inbox');
rd_check((int)rd_scalar("SELECT COUNT(*) FROM reader_enquiry_events WHERE enquiry_id=? AND event_type IN ('moved_to_trash','restored')",[$heldId])===2,'Delete and restore remain auditable');
comm_put('config',$trashCfg);
echo "PASS: owner-only message Trash, original-content retention, notification exclusion, version conflicts and restore.\n";

require_once dirname(__DIR__).'/app/migrations.php';require_once dirname(__DIR__).'/app/message_trash_migration.php';
message_trash_apply_migration(db(),dirname(__DIR__).'/app/migrations/190_message_trash.sql');
message_trash_apply_migration(db(),dirname(__DIR__).'/app/migrations/190_message_trash.sql');
rd_check((string)rd_scalar("SELECT sha256 FROM schema_migrations WHERE name='190_message_trash.sql'")===hash_file('sha256',dirname(__DIR__).'/app/migrations/190_message_trash.sql'),'Production Trash upgrade is idempotent and records the exact migration');
echo "PASS: explicit production Trash migration verification and repeat-run safety.\n";
