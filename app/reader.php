<?php
declare(strict_types=1);
require_once __DIR__.'/location.php';

/** Private reader choices and editorial correspondence. No provider calls here. */
final class ReaderValidationException extends InvalidArgumentException {}
final class ReaderConflictException extends RuntimeException {}

function reader_text(mixed $value, string $label, int $max, bool $required = false): string {
    if (!is_scalar($value) && $value !== null) throw new ReaderValidationException($label.' is invalid.');
    $value = trim(str_replace(["\r\n", "\r"], "\n", (string)$value));
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new ReaderValidationException($label.' contains invalid characters.');
    if (($required && $value === '') || mb_strlen($value) > $max) throw new ReaderValidationException($label.' must contain '.($required ? '1–' : 'at most ').$max.' characters.');
    return $value;
}
function reader_id(mixed $value): int {
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (int)$value > 2147483647) throw new ReaderValidationException('Choose a valid record or version.');
    return (int)$value;
}
function reader_ids(mixed $values, int $limit = 50): array {
    if (!is_array($values) || count($values) > $limit) throw new ReaderValidationException('Choose a bounded list of topics or journeys.');
    $result = [];
    foreach ($values as $value) $result[] = reader_id($value);
    $result = array_values(array_unique($result)); sort($result, SORT_NUMERIC); return $result;
}
function reader_choice(mixed $value, array $choices, string $label): string {
    if (!is_string($value) || !array_key_exists($value, $choices)) throw new ReaderValidationException('Choose a valid '.$label.'.');
    return $value;
}
function reader_json(array $values): string { return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
function reader_utc_label(?string $value): string { return $value ? (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)app_config('timezone', 'Africa/Nairobi')))->format('j M Y, H:i T') : '—'; }

/** The random capability contains no address, subscriber ID or founder ID. */
function reader_sign_link(string $purpose, string $nonce, int $expires): string {
    if (!in_array($purpose, ['preferences', 'founder'], true) || !preg_match('/^[a-f0-9]{64}$/D', $nonce)) throw new InvalidArgumentException('Invalid reader capability.');
    $key = (string)app_config('app_key', '');
    if (strlen($key) < 32) throw new RuntimeException('Reader links require the installed application key.');
    $value = 'v1.'.$nonce.'.'.$expires;
    return $value.'.'.hash_hmac('sha256', 'reader|'.$purpose.'|'.$value, $key);
}
function reader_parse_link(string $token, string $purpose, ?int $now = null): ?array {
    if (!in_array($purpose, ['preferences', 'founder'], true) || !preg_match('/^v1\.([a-f0-9]{64})\.([0-9]{10})\.([a-f0-9]{64})$/D', $token, $match)) return null;
    $now ??= time(); $expires = (int)$match[2];
    if ($expires <= $now || $expires > $now + 31 * 86400) return null;
    if (!hash_equals(reader_sign_link($purpose, $match[1], $expires), $token)) return null;
    return ['nonce_hash' => hash('sha256', $match[1]), 'expires_at' => gmdate('Y-m-d H:i:s', $expires)];
}
function reader_link_subject(array $link, bool $lock = false): ?array {
    $table = $link['purpose'] === 'preferences' ? 'newsletter_subscribers' : 'founder_submissions';
    $id = $link['purpose'] === 'preferences' ? $link['subscriber_id'] : $link['founder_id'];
    $query = db()->prepare('SELECT * FROM '.$table.' WHERE id=?'.($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id]); return $query->fetch() ?: null;
}
function reader_link_usable(array $link, ?array $subject = null): bool {
    if (!in_array($link['purpose'] ?? '', ['preferences', 'founder'], true) || !empty($link['used_at']) || !empty($link['revoked_at']) || ($link['expires_at'] ?? '') <= gmdate('Y-m-d H:i:s')) return false;
    $subject ??= reader_link_subject($link); if (!$subject) return false;
    $email = $link['purpose'] === 'preferences' ? $subject['email'] : $subject['submitter_email'];
    if (!hash_equals((string)$link['issued_email_hash'], newsletter_email_hash($email))) return false;
    return $link['purpose'] !== 'preferences' || (newsletter_recipient_eligible($subject) && (int)$link['consent_revision'] === (int)$subject['consent_revision']);
}
function reader_link_record(string $token, string $purpose, bool $lock = false): ?array {
    $parsed = reader_parse_link($token, $purpose); if (!$parsed) return null;
    $query = db()->prepare('SELECT * FROM reader_links WHERE nonce_hash=? AND purpose=?'.($lock ? ' FOR UPDATE' : ''));
    $query->execute([$parsed['nonce_hash'], $purpose]); $link = $query->fetch();
    if (!$link || !hash_equals($parsed['expires_at'], $link['expires_at'])) return null;
    $subject = reader_link_subject($link, $lock);
    if (!reader_link_usable($link, $subject)) return null;
    $link['subject'] = $subject; return $link;
}
function reader_issue_link(string $purpose, int $id, int $ttl = 604800, ?int $fixedExpiry = null): array {
    if (!in_array($purpose, ['preferences', 'founder'], true)) throw new InvalidArgumentException('Unknown reader link purpose.');
    $identity = ['purpose' => $purpose, 'subscriber_id' => $purpose === 'preferences' ? $id : null, 'founder_id' => $purpose === 'founder' ? $id : null];
    $subject = reader_link_subject($identity);
    if (!$subject || ($purpose === 'preferences' && !newsletter_recipient_eligible($subject))) throw new ReaderConflictException('This private link is not available.');
    $expires = $fixedExpiry ?? (time() + max(60, min(30 * 86400, $ttl)));
    if ($expires <= time() || $expires > time() + 30 * 86400) throw new ReaderConflictException('This private link has expired. Request another link.');
    $nonce = bin2hex(random_bytes(32)); $hash = hash('sha256', $nonce);
    $email = $purpose === 'preferences' ? $subject['email'] : $subject['submitter_email'];
    db()->prepare('INSERT INTO reader_links(purpose,subscriber_id,founder_id,nonce_hash,issued_email_hash,consent_revision,expires_at,created_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())')
        ->execute([$purpose, $identity['subscriber_id'], $identity['founder_id'], $hash, newsletter_email_hash($email), $purpose === 'preferences' ? $subject['consent_revision'] : null, gmdate('Y-m-d H:i:s', $expires)]);
    $token = reader_sign_link($purpose, $nonce, $expires);
    return ['id' => (int)db()->lastInsertId(), 'nonce_hash' => $hash, 'token' => $token, 'url' => url(($purpose === 'preferences' ? '/newsletter/preferences' : '/founders/follow-up').'?token='.$token), 'email' => $email,'name'=>(string)(($subject['first_name']??'')?:($subject['name']??($subject['submitter_name']??'')))];
}
function reader_queue_link(array $link, string $subject, string $intro): int {
    $html = '<p>'.e($intro).'</p><p><a href="'.e($link['url']).'">Open your private link</a></p><p>This link expires in seven days. Keep it private. If you did not request this message, you can ignore it. This message does not subscribe you to marketing emails.</p>';
    $html=email_design($subject,$html,['name'=>(string)($link['name']??'')]);
    $id = enqueue_email($link['email'], $subject.' · Waithaka Ndegwa', $html, 'reader-link:'.$link['nonce_hash']);
    db()->prepare('UPDATE reader_links SET outbox_id=? WHERE id=?')->execute([$id, $link['id']]); return $id;
}

/** Called by the outbox while its existing recipient lock is held. */
function reader_email_gate(string $dedupeKey, array $payload, int $outboxId): array {
    $gate = ['action' => 'suppress', 'reason' => 'reader_link_obsolete', 'locks' => []];
    if (!preg_match('/^reader-link:([a-f0-9]{64})$/D', $dedupeKey, $match)) return $gate;
    $query = db()->prepare('SELECT * FROM reader_links WHERE nonce_hash=? AND outbox_id=?'); $query->execute([$match[1], $outboxId]); $link = $query->fetch();
    if (!$link) return $gate;
    try {
        if ($link['purpose'] === 'preferences') {
            $lock = newsletter_lock_name('subscriber', (int)$link['subscriber_id']);
            if (!newsletter_lock($lock, 0)) { $gate['action'] = 'defer'; $gate['reason'] = 'reader_subscriber_busy'; return $gate; }
            $gate['locks'][] = $lock;
        }
        $query->execute([$match[1], $outboxId]); $link = $query->fetch();
        if (!$link || !reader_link_usable($link) || !hash_equals($link['issued_email_hash'], newsletter_email_hash((string)($payload['to'] ?? '')))) return $gate;
        $gate['action'] = 'send'; $gate['reason'] = ''; return $gate;
    } catch (Throwable $error) { reader_email_unlock($gate); throw $error; }
}
function reader_email_unlock(array $gate): void { foreach (array_reverse($gate['locks'] ?? []) as $lock) newsletter_unlock($lock); }

function reader_preferences(int $subscriberId): array {
    $query = db()->prepare('SELECT * FROM reader_preferences WHERE subscriber_id=?'); $query->execute([$subscriberId]); $row = $query->fetch();
    if (!$row) return ['receive_all' => 1, 'topic_ids' => [], 'journey_ids' => [], 'row_version' => 0];
    return ['receive_all' => (int)$row['receive_all'], 'topic_ids' => json_decode($row['topic_ids_json'], true, 16, JSON_THROW_ON_ERROR), 'journey_ids' => json_decode($row['journey_ids_json'], true, 16, JSON_THROW_ON_ERROR), 'row_version' => (int)$row['row_version']];
}
function reader_preference_choices(array $previous = []): array {
    $topics = db()->query('SELECT id,name FROM categories ORDER BY sort_order,name')->fetchAll();
    $seasons = journeys_public_seasons(100, true); $journeys = [];
    foreach ($seasons as $season) $journeys[] = ['id' => (int)$season['id'], 'name' => $season['title']];
    $visible = array_column($journeys, 'id');
    foreach ($previous['journey_ids'] ?? [] as $id) if (!in_array((int)$id, $visible, true)) $journeys[] = ['id' => (int)$id, 'name' => 'Previously selected journey (currently unavailable)'];
    $visible = array_map('intval', array_column($topics, 'id'));
    foreach ($previous['topic_ids'] ?? [] as $id) if (!in_array((int)$id, $visible, true)) $topics[] = ['id' => (int)$id, 'name' => 'Previously selected topic (currently unavailable)'];
    return ['topics' => $topics, 'journeys' => $journeys];
}
function reader_preference_values(array $input, array $previous = []): array {
    $all = $input['receive_all'] ?? '0';
    if (!in_array($all, [0, 1, '0', '1'], true)) throw new ReaderValidationException('Choose a valid email preference.');
    $topics = reader_ids($input['topic_ids'] ?? [], 30); $journeys = reader_ids($input['journey_ids'] ?? [], 50);
    $choices = reader_preference_choices($previous);
    if (array_diff($topics, array_map('intval', array_column($choices['topics'], 'id'))) || array_diff($journeys, array_map('intval', array_column($choices['journeys'], 'id')))) throw new ReaderValidationException('Choose topics and journeys shown on this form.');
    return ['receive_all' => (int)$all, 'topic_ids' => $topics, 'journey_ids' => $journeys];
}
function reader_store_preferences(int $subscriberId, array $values): void {
    db()->prepare('INSERT INTO reader_preferences(subscriber_id,receive_all,topic_ids_json,journey_ids_json,updated_at) VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE receive_all=VALUES(receive_all),topic_ids_json=VALUES(topic_ids_json),journey_ids_json=VALUES(journey_ids_json),row_version=row_version+1,updated_at=UTC_TIMESTAMP()')
        ->execute([$subscriberId, $values['receive_all'], reader_json($values['topic_ids']), reader_json($values['journey_ids'])]);
}
function reader_request_signup(string $email, string $name, array $input): void {
    $GLOBALS['reader_signup_preferences'] = reader_preference_values($input);
    try { newsletter_request_signup($email, $name,isset($input['first_name'],$input['last_name'])?['first_name'=>$input['first_name'],'last_name'=>$input['last_name']]:null); } finally { unset($GLOBALS['reader_signup_preferences']); }
}
/** Hook inside the existing signup transaction; confirmed addresses return first. */
function reader_capture_signup_preferences(int $subscriberId): void {
    if (isset($GLOBALS['reader_signup_preferences'])) reader_store_preferences($subscriberId, $GLOBALS['reader_signup_preferences']);
}
function reader_request_preferences(string $email): void {
    $query = db()->prepare('SELECT * FROM newsletter_subscribers WHERE email=?'); $query->execute([journeys_email($email)]); $subscriber = $query->fetch();
    if (!$subscriber || !newsletter_recipient_eligible($subscriber)) return;
    newsletter_subscriber_lock((int)$subscriber['id']);
    try {
        db()->beginTransaction(); $current = newsletter_subscriber((int)$subscriber['id']);
        if ($current && newsletter_recipient_eligible($current)) reader_queue_link(reader_issue_link('preferences', (int)$current['id']), 'Manage your email preferences', 'You requested a private link to choose the topics and journeys you receive.');
        db()->commit();
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Reader preference link request failed: '.get_class($error));
    } finally { newsletter_subscriber_unlock((int)$subscriber['id']); }
}
function reader_update_preferences(string $token, array $input): bool {
    $initial = reader_link_record($token, 'preferences'); if (!$initial) return false;
    $id = (int)$initial['subscriber_id']; newsletter_subscriber_lock($id);
    try {
        db()->beginTransaction(); $link = reader_link_record($token, 'preferences', true);
        if (!$link) { db()->rollBack(); return false; }
        $previous = reader_preferences($id);
        $unsubscribe = ($input['action'] ?? 'save') === 'unsubscribe';
        if ($unsubscribe && !journeys_checked($input['unsubscribe_ack'] ?? null)) throw new ReaderValidationException('Confirm that you want to unsubscribe from every newsletter edition.');
        $values = $unsubscribe ? array_intersect_key($previous, ['receive_all' => 1, 'topic_ids' => 1, 'journey_ids' => 1]) : reader_preference_values($input, $previous);
        $comparable = array_intersect_key($previous, $values);
        if ($unsubscribe) {
            db()->prepare("UPDATE newsletter_subscribers SET status='unsubscribed',unsubscribed_at=NOW(),updated_at=NOW(),consent_revision=consent_revision+1 WHERE id=?")->execute([$id]);
            db()->prepare("UPDATE newsletter_tokens SET used_at=NOW() WHERE subscriber_id=? AND purpose='confirm' AND used_at IS NULL")->execute([$id]);
            newsletter_event(null, $id, null, 'preferences_unsubscribed', 'The reader explicitly unsubscribed from the private preferences form.');
        } elseif ($values !== $comparable) {
            reader_store_preferences($id, $values);
            // A change invalidates already-frozen deliveries. It never changes status.
            db()->prepare('UPDATE newsletter_subscribers SET consent_revision=consent_revision+1,updated_at=NOW() WHERE id=?')->execute([$id]);
            newsletter_event(null, $id, null, 'preferences_changed', 'The reader updated topic and journey choices; previously frozen deliveries require a new audience snapshot.');
        }
        db()->prepare('UPDATE reader_links SET used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$link['id']]);
        db()->prepare("UPDATE reader_links SET revoked_at=UTC_TIMESTAMP() WHERE subscriber_id=? AND purpose='preferences' AND used_at IS NULL AND revoked_at IS NULL")->execute([$id]);
        db()->commit(); return true;
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    finally { newsletter_subscriber_unlock($id); }
}
function reader_preference_url(int $subscriberId): string { return reader_issue_link('preferences', $subscriberId, 30 * 86400)['url']; }

/** Audience meaning is frozen with the approved edition, not inferred at send time. */
function reader_newsletter_audience(?int $postId): array {
    $scope = ['topic_ids' => [], 'journey_ids' => [], 'labels' => [], 'general' => $postId === null];
    if ($postId === null) return $scope;
    $query = db()->prepare('SELECT c.id,c.name FROM posts p JOIN categories c ON c.id=p.category_id WHERE p.id=?'); $query->execute([$postId]);
    if ($topic = $query->fetch()) { $scope['topic_ids'][] = (int)$topic['id']; $scope['labels'][] = $topic['name']; }
    $query = db()->prepare("SELECT s.id,s.title FROM journey_episodes e JOIN journey_seasons s ON s.id=e.season_id WHERE e.post_id=? AND e.is_published=1 AND s.publication_status='published' AND s.published_at IS NOT NULL AND s.published_at<=? ORDER BY s.id");
    $query->execute([$postId, journeys_now()]);
    foreach ($query->fetchAll() as $season) { $scope['journey_ids'][] = (int)$season['id']; $scope['labels'][] = $season['title']; }
    return $scope;
}
function reader_preference_matches(array $preferences, array $scope): bool {
    if ((int)($preferences['receive_all'] ?? 1) === 1) return true;
    return (bool)(array_intersect($preferences['topic_ids'] ?? [], $scope['topic_ids'] ?? []) || array_intersect($preferences['journey_ids'] ?? [], $scope['journey_ids'] ?? []));
}
function reader_newsletter_matches(int $subscriberId, array $snapshot): bool {
    // Legacy approvals have no frozen topic scope: send only to all-writing readers.
    return reader_preference_matches(reader_preferences($subscriberId), $snapshot['reader_audience'] ?? []);
}

function reader_enquiry_types(): array { return ['general' => 'Something else', 'speaking' => 'Speaking / event invitation', 'collaboration' => 'Collaboration', 'reader_question' => 'Response to My Thoughts', 'media' => 'Media / interview', 'correction' => 'Correction or source question']; }
function reader_enquiry_states(): array { return ['new' => 'New', 'in_review' => 'In review', 'waiting' => 'Waiting for a reply', 'responded' => 'Responded', 'closed' => 'Closed']; }
function reader_contact_values(array $message, array $input = []): array {
    $values = [];
    foreach (['reference' => 32, 'first_name' => 120, 'last_name' => 120, 'email' => 190, 'phone_code' => 12, 'phone' => 60, 'country' => 120, 'city' => 120, 'message' => 10000] as $field => $limit) $values[$field] = reader_text($message[$field] ?? '', ucwords(str_replace('_', ' ', $field)), $limit, in_array($field, ['reference', 'first_name', 'email', 'message'], true));
    if (!preg_match('/^[A-Z0-9-]{5,32}$/D', $values['reference']) || !filter_var($values['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $values['email'])) throw new ReaderValidationException('Enter a valid contact address and reference.');
    $values['enquiry_type'] = reader_choice($input['enquiry_type'] ?? 'general', reader_enquiry_types(), 'enquiry type');
    return $values;
}
/** Save the contact and work item atomically, commit, then attempt notification. */
function reader_contact_submit(array $message, array $input = [], bool $notify = true): int {
    $values = reader_contact_values($message, $input);
    $profile=null;if(array_intersect(['county','sub_county','region','district'],array_keys($input))){try{$profile=location_fields($input+['country'=>$values['country'],'city'=>$values['city']]);}catch(InvalidArgumentException $e){throw new ReaderValidationException($e->getMessage());}$values['country']=$profile['country'];$values['city']=$profile['city'];}
    $pdo = db(); $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('INSERT INTO contact_messages(reference,first_name,last_name,email,phone_code,phone,country,city,message,ip_hash,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
        $query->execute([$values['reference'], $values['first_name'], $values['last_name'], $values['email'], $values['phone_code'], $values['phone'], $values['country'], $values['city'], $values['message'], ip_hash(), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
        $contactId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO reader_enquiries(contact_id,enquiry_type,created_at,updated_at) VALUES(?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$contactId, $values['enquiry_type']]);
        $id = (int)$pdo->lastInsertId(); if($profile!==null)location_record_save('enquiry',$id,$profile); $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    if($notify)reader_contact_notifications($id,$values);
    return $id;
}
function reader_contact_notifications(int $id,array $values): void {
    $pdo=db();
    try {
        $queued = send_contact_email($values);
        $pdo->prepare('UPDATE reader_enquiries SET notification_state=? WHERE id=?')->execute([$queued ? 'queued' : 'failed', $id]);
    } catch (Throwable $error) {
        error_log('Recorded enquiry notification failed: '.get_class($error));
        try { $pdo->prepare("UPDATE reader_enquiries SET notification_state='failed' WHERE id=?")->execute([$id]); } catch (Throwable $ignored) { /* The committed enquiry remains the authoritative receipt. */ }
    }
    try {
        $html=email_design('Thank you for getting in touch.','<p>Your message has been received and saved for review.</p><p style="padding:16px;background:#f3f1eb;border-left:3px solid #be955f"><strong>Your reference</strong><br>'.e($values['reference']).'</p><p>I appreciate you taking the time to write. If a response is needed, I will follow up using the contact details you provided.</p><p>Please keep this reference if you contact me about the same enquiry.</p>',['name'=>$values['first_name'],'preheader'=>'Your enquiry has been received.']);
        enqueue_email($values['email'],'Message received · '.$values['reference'].' · Waithaka Ndegwa',$html,'contact-receipt:'.$values['reference']);
    } catch(Throwable $error){error_log('Enquiry receipt queue failed: '.get_class($error));}
}

function reader_enquiry(int $id): ?array {
    $query = db()->prepare('SELECT r.*,c.reference,c.first_name,c.last_name,c.email,c.phone_code,c.phone,c.country,c.city,c.message,c.email_sent,EXISTS(SELECT 1 FROM reader_enquiry_trash t WHERE t.enquiry_id=r.id) is_trashed FROM reader_enquiries r JOIN contact_messages c ON c.id=r.contact_id WHERE r.id=?');
    $query->execute([$id]); return $query->fetch() ?: null;
}
function reader_enquiry_update(int $id, array $input, int $actor): void {
    $version = reader_id($input['row_version'] ?? null); $status = reader_choice($input['status'] ?? '', reader_enquiry_states(), 'enquiry status');
    $type = reader_choice($input['enquiry_type'] ?? '', reader_enquiry_types(), 'enquiry type');
    $next = reader_text($input['next_action'] ?? '', 'Next action', 1000); $note = reader_text($input['note'] ?? '', 'Private note or response record', 5000);
    $responded = journeys_checked($input['record_response'] ?? null);
    if ($responded && ($note === '' || !journeys_checked($input['response_sent_ack'] ?? null))) throw new ReaderValidationException('Record the response and confirm it was actually sent outside this form.');
    $due = reader_text($input['next_action_at'] ?? '', 'Next action date', 16); $utc = null;
    if ($due !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $due, new DateTimeZone((string)app_config('timezone', 'Africa/Nairobi')));
        if (!$date || $date->format('Y-m-d\TH:i') !== $due) throw new ReaderValidationException('Enter a valid next action date.');
        $utc = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
    db()->beginTransaction();
    try {
        $query = db()->prepare('SELECT * FROM reader_enquiries WHERE id=? FOR UPDATE'); $query->execute([$id]); $record = $query->fetch();
        if (!$record || (int)$record['row_version'] !== $version) throw new ReaderConflictException('This enquiry changed in another window. Reload before saving.');
        db()->prepare('UPDATE reader_enquiries SET enquiry_type=?,status=?,next_action=?,next_action_at=?,last_response_at=?,row_version=row_version+1,updated_at=UTC_TIMESTAMP() WHERE id=?')
            ->execute([$type, $status, $next, $utc, $responded ? gmdate('Y-m-d H:i:s') : $record['last_response_at'], $id]);
        db()->prepare('INSERT INTO reader_enquiry_events(enquiry_id,event_type,note,actor_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')
            ->execute([$id, $responded ? 'response_recorded' : 'workflow_updated', $note !== '' ? $note : 'Status: '.reader_enquiry_states()[$status].'.', $actor]);
        db()->commit();
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
}

function reader_founder_ensure(int $id): void {
    db()->prepare("INSERT IGNORE INTO reader_founder_followups(founder_id,clarification_question,permission_request,updated_at) VALUES(?,'','',UTC_TIMESTAMP())")->execute([$id]);
}
function reader_founder(int $id): ?array {
    $query = db()->prepare('SELECT f.*,r.clarification_status,r.permission_status,r.clarification_question,r.permission_request,r.receipt_state,r.last_reply_at,r.row_version followup_version FROM founder_submissions f JOIN reader_founder_followups r ON r.founder_id=f.id WHERE f.id=?');
    $query->execute([$id]); return $query->fetch() ?: null;
}
function reader_founder_message(int $id, string $type, string $body, ?int $actor = null): void {
    db()->prepare('INSERT INTO reader_founder_messages(founder_id,message_type,body,actor_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$id, $type, $body, $actor]);
}
function reader_founder_notify(int $id, bool $initialOnly = false): bool {
    try {
        db()->beginTransaction();
        $query = db()->prepare('SELECT receipt_state FROM reader_founder_followups WHERE founder_id=? FOR UPDATE'); $query->execute([$id]); $state = $query->fetchColumn();
        if ($state === false || ($initialOnly && $state !== 'pending')) { db()->commit(); return false; }
        $founder = reader_founder($id);
        if (!$founder) throw new ReaderConflictException('Submission not found.');
        reader_queue_link(reader_issue_link('founder', $id), 'Your founder submission · '.$founder['reference'], 'Your private submission is recorded. Use this link to view its editorial status and any questions or permission requests. Submission does not guarantee publication.');
        db()->prepare("UPDATE reader_founder_followups SET receipt_state='queued' WHERE founder_id=?")->execute([$id]);
        db()->commit(); return true;
    } catch (Throwable $error) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('Recorded founder link notification failed: '.get_class($error));
        try { db()->prepare("UPDATE reader_founder_followups SET receipt_state='failed' WHERE founder_id=?")->execute([$id]); } catch (Throwable $ignored) { /* The submission is already durable. */ }
        return false;
    }
}
/** Called after founders_submit has stored the original submission. */
function reader_founder_received(string $reference): void {
    $query = db()->prepare('SELECT id FROM founder_submissions WHERE reference=?'); $query->execute([$reference]); $id = (int)$query->fetchColumn();
    if (!$id) throw new ReaderConflictException('Submission not found.');
    reader_founder_ensure($id); reader_founder_notify($id, true);
}
/** Called inside the existing moderation transaction, so rejected permission rolls back. */
function reader_founder_reviewed(int $id, int $actor): void {
    reader_founder_ensure($id); $founder = reader_founder($id);
    if (!$founder) throw new JourneyValidationException('Submission not found.');
    // Historical/offline permission can use ordinary moderation until this
    // protected workflow is opened. A new request cannot erase a refusal or
    // withdrawal: only the founder's fresh response moves it back to granted.
    if ($founder['permission_confirmed'] && !in_array($founder['permission_status'], ['not_requested', 'granted'], true)) throw new JourneyValidationException('This permission request needs a fresh grant through the founder’s private follow-up link before publication permission can be confirmed.');
    db()->prepare('UPDATE reader_founder_followups SET row_version=row_version+1,updated_at=UTC_TIMESTAMP() WHERE founder_id=?')->execute([$id]);
    reader_founder_message($id, 'review_status', 'Editorial status: '.(founders_review_states()[$founder['review_status']] ?? 'In review').'.', $actor);
}
function reader_founder_request_link(string $reference, string $email): void {
    $reference = reader_text($reference, 'Submission reference', 40, true); $email = journeys_email($email);
    $query = db()->prepare('SELECT id FROM founder_submissions WHERE reference=? AND submitter_email=?'); $query->execute([$reference, $email]); $id = (int)$query->fetchColumn();
    if ($id) { reader_founder_ensure($id); reader_founder_notify($id); }
}
function reader_founder_writer_update(int $id, array $input, int $actor): bool {
    $action = reader_choice($input['action'] ?? '', ['clarification' => '', 'permission' => '', 'close_clarification' => '', 'send_link' => ''], 'follow-up action');
    $version = reader_id($input['followup_version'] ?? null);
    $body = reader_text($input['body'] ?? '', 'Question or permission scope', 4000, in_array($action, ['clarification', 'permission'], true));
    db()->beginTransaction();
    try {
        $query = db()->prepare('SELECT * FROM founder_submissions WHERE id=? FOR UPDATE'); $query->execute([$id]); $submission = $query->fetch();
        $query = db()->prepare('SELECT * FROM reader_founder_followups WHERE founder_id=? FOR UPDATE'); $query->execute([$id]); $followup = $query->fetch();
        if (!$submission || !$followup || (int)$followup['row_version'] !== $version) throw new ReaderConflictException('This follow-up changed. Reload before saving.');
        if ($action === 'permission' && $submission['submission_type'] !== 'founder') throw new ReaderValidationException('A nominator cannot grant another founder’s publication permission. Obtain it separately and document it in moderation.');
        if (in_array($submission['review_status'], ['declined', 'withdrawn'], true) && in_array($action, ['clarification', 'permission'], true)) throw new ReaderValidationException('Reopen the editorial review before requesting further information or permission.');
        if ($action === 'clarification') {
            db()->prepare("UPDATE reader_founder_followups SET clarification_status='requested',clarification_question=? WHERE founder_id=?")->execute([$body, $id]);
            db()->prepare("UPDATE founder_submissions SET review_status=IF(review_status IN ('new','reviewing','needs_info'),'needs_info',review_status),row_version=row_version+1,updated_at=NOW() WHERE id=?")->execute([$id]);
            reader_founder_message($id, 'editor_question', $body, $actor);
        } elseif ($action === 'permission') {
            db()->prepare("UPDATE reader_founder_followups SET permission_status='requested',permission_request=? WHERE founder_id=?")->execute([$body, $id]);
            // The new scope needs its own grant and subsequent moderation.
            // Keep the previous evidence reference for history, never as an
            // active publication gate while the new response is outstanding.
            db()->prepare('UPDATE founder_submissions SET founder_publication_permission=0,permission_confirmed=0,is_public=0,row_version=row_version+1,updated_at=NOW() WHERE id=?')->execute([$id]);
            reader_founder_message($id, 'permission_request', $body, $actor);
        } elseif ($action === 'close_clarification') {
            db()->prepare("UPDATE reader_founder_followups SET clarification_status='closed' WHERE founder_id=?")->execute([$id]);
            reader_founder_message($id, 'clarification_closed', 'The editor marked the clarification complete.', $actor);
        }
        db()->prepare('UPDATE reader_founder_followups SET row_version=row_version+1,updated_at=UTC_TIMESTAMP() WHERE founder_id=?')->execute([$id]);
        db()->commit();
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
    return $action === 'close_clarification' || reader_founder_notify($id);
}
function reader_founder_respond(string $token, array $input): ?string {
    $initial = reader_link_record($token, 'founder'); if (!$initial) return null;
    $action = reader_choice($input['action'] ?? '', ['reply' => '', 'grant' => '', 'decline' => '', 'withdraw' => ''], 'response');
    $body = reader_text($input['body'] ?? '', 'Your reply', 5000, $action === 'reply');
    $version = reader_id($input['followup_version'] ?? null); $id = (int)$initial['founder_id'];
    db()->beginTransaction();
    try {
        if(function_exists('founder_story_lock_post')&&!empty($initial['subject']['associated_post_id']))founder_story_lock_post((int)$initial['subject']['associated_post_id']);
        // Story-aware callers lock the post before the submission and follow-up.
        $query = db()->prepare('SELECT * FROM founder_submissions WHERE id=? FOR UPDATE'); $query->execute([$id]); $submission = $query->fetch();
        $query = db()->prepare('SELECT * FROM reader_founder_followups WHERE founder_id=? FOR UPDATE'); $query->execute([$id]); $followup = $query->fetch();
        $link = reader_link_record($token, 'founder', true);
        if (!$link) { db()->rollBack(); return null; }
        if (!$submission || !$followup || (int)$followup['row_version'] !== $version) throw new ReaderConflictException('The editor updated this follow-up. Reload the private page before responding.');
        if ($action !== 'withdraw' && in_array($submission['review_status'], ['declined', 'withdrawn'], true)) throw new ReaderValidationException('This editorial review is closed. You may contact the editor if something needs to change.');
        if ($action === 'reply') {
            if ($followup['clarification_status'] !== 'requested') throw new ReaderValidationException('There is no open clarification request.');
            db()->prepare("UPDATE reader_founder_followups SET clarification_status='replied' WHERE founder_id=?")->execute([$id]);
            reader_founder_message($id, 'submitter_reply', $body);
        } else {
            if ($submission['submission_type'] !== 'founder') throw new ReaderValidationException('A nomination cannot grant or change the founder’s publication permission.');
            if ($action !== 'withdraw' && ($followup['permission_status'] !== 'requested' || $followup['permission_request'] === '')) throw new ReaderValidationException('There is no current permission request.');
            if ($action === 'grant' && !journeys_checked($input['permission_ack'] ?? null)) throw new ReaderValidationException('Confirm that you are the founder and agree to the exact publication scope shown.');
            if ($action === 'withdraw' && !journeys_checked($input['withdraw_ack'] ?? null)) throw new ReaderValidationException('Confirm that you want to withdraw publication permission.');
            $status = ['grant' => 'granted', 'decline' => 'declined', 'withdraw' => 'withdrawn'][$action];
            db()->prepare('UPDATE reader_founder_followups SET permission_status=? WHERE founder_id=?')->execute([$status, $id]);
            $record = 'Publication scope shown: '.$followup['permission_request']."\n\n".'Submitter response: '.$status.'.'.($body !== '' ? "\n\n".$body : '');
            reader_founder_message($id, 'permission_'.$status, $record);
            if ($action !== 'grant') db()->prepare('UPDATE founder_submissions SET founder_publication_permission=0,permission_confirmed=0,is_public=0,row_version=row_version+1,updated_at=NOW() WHERE id=?')->execute([$id]);
            if($action!=='grant'&&function_exists('founder_story_workflow')){$storyWorkflow=founder_story_workflow($id,true);if($storyWorkflow)founder_story_discard($storyWorkflow,'permission_'.$status);}
            // A grant is evidence for the editor, never automatic moderation/publication.
        }
        db()->prepare('UPDATE reader_founder_followups SET last_reply_at=UTC_TIMESTAMP(),row_version=row_version+1,updated_at=UTC_TIMESTAMP() WHERE founder_id=?')->execute([$id]);
        db()->prepare('UPDATE reader_links SET used_at=UTC_TIMESTAMP() WHERE id=?')->execute([$link['id']]);
        $expires = (new DateTimeImmutable($link['expires_at'], new DateTimeZone('UTC')))->getTimestamp();
        $next = reader_issue_link('founder', $id, 604800, $expires); db()->commit(); return $next['url'];
    } catch (Throwable $error) { if (db()->inTransaction()) db()->rollBack(); throw $error; }
}
function reader_operations_summary(): array {
    $row = db()->query("SELECT SUM(status NOT IN ('closed','responded')) enquiries_open,SUM(status NOT IN ('closed','responded') AND next_action_at<UTC_TIMESTAMP()) enquiries_overdue FROM reader_enquiries r WHERE NOT EXISTS(SELECT 1 FROM reader_enquiry_trash t WHERE t.enquiry_id=r.id)")->fetch();
    $founders = db()->query("SELECT SUM(clarification_status='requested' OR permission_status='requested') founder_waiting,SUM(clarification_status='replied' OR permission_status='granted') founder_replies_pending FROM reader_founder_followups r JOIN founder_submissions f ON f.id=r.founder_id WHERE f.review_status NOT IN ('approved','declined','withdrawn')")->fetch();
    return array_map('intval', array_merge($row ?: [], $founders ?: []));
}


function reader_enquiry_trash(int $id,int $version,int $actor,bool $restore=false): void {
 if($actor!==(int)comm_config()['owner'])throw new ReaderValidationException('Owner access required.');
 db()->beginTransaction();try{
  $q=db()->prepare('SELECT row_version FROM reader_enquiries WHERE id=? FOR UPDATE');$q->execute([$id]);$current=$q->fetchColumn();
  if($current===false||(int)$current!==$version)throw new ReaderConflictException('This message changed. Reload before continuing.');
  if($restore)db()->prepare('DELETE FROM reader_enquiry_trash WHERE enquiry_id=?')->execute([$id]);
  else db()->prepare('INSERT INTO reader_enquiry_trash(enquiry_id,deleted_by,deleted_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE enquiry_id=VALUES(enquiry_id)')->execute([$id,$actor]);
  db()->prepare('UPDATE reader_enquiries SET row_version=row_version+1,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);
  db()->prepare('INSERT INTO reader_enquiry_events(enquiry_id,event_type,note,actor_id,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$id,$restore?'restored':'moved_to_trash',$restore?'Message restored. No notification was re-sent.':'Message moved to Trash. Content and follow-up history retained.',$actor]);
  db()->commit();
 }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
