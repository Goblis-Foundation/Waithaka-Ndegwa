<?php
declare(strict_types=1);

function reader_route_error(Throwable $error): string {
    if ($error instanceof ReaderValidationException || $error instanceof JourneyValidationException || $error instanceof NewsletterValidationException) { http_response_code(422); return $error->getMessage(); }
    if ($error instanceof ReaderConflictException || $error instanceof JourneyConflictException || $error instanceof NewsletterConflictException) { http_response_code(409); return $error->getMessage(); }
    $reference = strtoupper(bin2hex(random_bytes(5))); error_log('Reader request '.$reference.' failed: '.get_class($error)); http_response_code(503);
    return 'This request could not be completed. Please retry later. Reference: '.$reference;
}
function reader_public_render(string $view, array $data, string $title, string $path): void {
    journeys_private_headers();
    render('reader/'.$view, $data + ['page' => 'reader', 'privatePage' => true, 'suppressAnalytics' => true, 'seo' => ['title' => $title.' · Waithaka Ndegwa', 'description' => 'Private reader correspondence and email choices.', 'canonical' => url($path), 'robots' => 'noindex,nofollow']]);
}
function reader_dispatch(string $path): bool {
    if ($path === '/newsletter') {
        journeys_private_headers(); journeys_method(['GET', 'HEAD', 'POST']); $error = null; $values = ['receive_all' => '1'];
        if (is_post()) {
            journeys_verify_csrf(); $values = $_POST;
            try {
                if (empty($_POST['company_website'])) {
                    if (!journeys_checked($_POST['subscribe_ack'] ?? null)) throw new ReaderValidationException('Please confirm that you want to receive these emails.');
                    $email = journeys_email($_POST['email'] ?? ''); $first=reader_text($_POST['first_name']??'', 'First name',60,true);$last=reader_text($_POST['last_name']??'', 'Last name',59,true);$name=$first.' '.$last;
                    if (!journeys_rate_limit('newsletter-ip', ip_hash(), 8) || !journeys_rate_limit('newsletter-email', $email, 3)) { http_response_code(429); header('Retry-After: 3600'); $error = 'Too many requests were received. Please try again later.'; }
                    else reader_request_signup($email, $name, $_POST);
                }
                if ($error === null) { flash('success', 'Your request has been recorded. A subscription becomes active only after email confirmation.'); redirect(url('/newsletter')); }
            } catch (Throwable $exception) { $error = reader_route_error($exception); }
        }
        journeys_public_render('journeys/newsletter', ['values' => $values, 'error' => $error, 'preferenceChoices' => reader_preference_choices()], 'Follow My Thoughts', 'Receive the writing and Startup Journeys you choose by email, or subscribe to the RSS feed.', '/newsletter', 'newsletter');
    }
    if (in_array($path, ['/newsletter/preferences/request', '/founders/follow-up/request'], true)) {
        journeys_private_headers(); journeys_method(['GET', 'HEAD', 'POST']); $founder = str_starts_with($path, '/founders/'); $error = null; $values = [];
        if (is_post()) {
            journeys_verify_csrf(); $values = $_POST;
            try {
                if (empty($_POST['company_website'])) {
                    $email = journeys_email($_POST['email'] ?? '');
                    $purpose = $founder ? 'founder-link' : 'reader-preferences';
                    if (!journeys_rate_limit($purpose.'-ip', ip_hash(), 8) || !journeys_rate_limit($purpose.'-email', $email, 3)) { http_response_code(429); header('Retry-After: 3600'); $error = 'Too many link requests. Please try again later.'; }
                    elseif ($founder) reader_founder_request_link(reader_text($_POST['reference'] ?? '', 'Submission reference', 40, true), $email);
                    else reader_request_preferences($email);
                }
                if ($error === null) { flash('success', 'If those details match an eligible record, a private link will be emailed. This request does not subscribe or resubscribe you.'); redirect(url($path)); }
            } catch (Throwable $exception) { $error = reader_route_error($exception); }
        }
        reader_public_render('request_link', ['founder' => $founder, 'values' => $values, 'error' => $error, 'requestPath' => $path], $founder ? 'Request a Founder Follow-up Link' : 'Request a Preferences Link', $path); return true;
    }
    if ($path === '/newsletter/preferences') {
        journeys_private_headers(); journeys_method(['GET', 'HEAD', 'POST']);
        $raw = is_post() ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''); $token = is_string($raw) ? $raw : ''; $error = null;
        if ($token === '' && !is_post()) redirect(url('/newsletter/preferences/request'));
        $link = reader_link_record($token, 'preferences'); $values = $link ? reader_preferences((int)$link['subscriber_id']) : [];
        if (!$link) { http_response_code(410); $error = 'This private link is unavailable, expired or already used. Request another link if you still receive the newsletter.'; }
        elseif (is_post()) {
            journeys_verify_csrf();
            try {
                if (reader_update_preferences($token, $_POST)) { flash('success', ($_POST['action'] ?? '') === 'unsubscribe' ? 'You have been unsubscribed from all newsletter editions. A fresh signup and confirmation are required to subscribe again.' : 'Your email choices are saved. Your subscription status has not changed. Editions already queued before this change will be skipped.'); redirect(url('/newsletter/preferences/request')); }
                http_response_code(410); $error = 'This private link is no longer available. Request another link.'; $link = null;
            } catch (Throwable $exception) { $error = reader_route_error($exception); }
        }
        reader_public_render('preferences', ['token' => $token, 'validLink' => (bool)$link, 'values' => $values, 'choices' => $link ? reader_preference_choices($values) : [], 'error' => $error], 'Email Preferences', $path); return true;
    }
    if ($path === '/founders/follow-up') {
        journeys_private_headers(); journeys_method(['GET', 'HEAD', 'POST']);
        $raw = is_post() ? ($_POST['token'] ?? '') : ($_GET['token'] ?? ''); $token = is_string($raw) ? $raw : ''; $error = null;
        if ($token === '' && !is_post()) redirect(url('/founders/follow-up/request'));
        $link = reader_link_record($token, 'founder');
        if (!$link) { http_response_code(410); $error = 'This private follow-up link is unavailable, expired or already used. Request another link using your submission reference.'; }
        elseif (is_post()) {
            journeys_verify_csrf();
            try {
                if (!journeys_rate_limit('founder-reply', (string)$link['founder_id'], 20)) { http_response_code(429); header('Retry-After: 3600'); $error = 'Too many replies. Please try again later.'; }
                elseif ($next = reader_founder_respond($token, $_POST)) { flash('success', 'Your response is recorded for editorial review. It does not publish the story.'); redirect($next); }
                else { http_response_code(410); $error = 'This private link is no longer available.'; $link = null; }
            } catch (Throwable $exception) { $error = reader_route_error($exception); }
        }
        $submission = $link ? reader_founder((int)$link['founder_id']) : null; $messages = [];
        if ($submission) { $query = db()->prepare('SELECT message_type,body,created_at FROM reader_founder_messages WHERE founder_id=? ORDER BY id DESC LIMIT 50'); $query->execute([$submission['id']]); $messages = $query->fetchAll(); }
        reader_public_render('founder_followup', ['token' => $token, 'submission' => $submission, 'messages' => $messages, 'error' => $error], 'Private Founder Follow-up', $path); return true;
    }
    if (!preg_match('~^/write/readers(?:/|$)~', $path)) return false;
    require_auth(); journeys_private_headers(); journeys_method(['GET', 'HEAD', 'POST']); $actor = (int)current_user()['id']; $error = null;
    if (is_post()) {
        journeys_verify_csrf();
        if (!journeys_rate_limit('reader-writer', (string)$actor, 90, 60)) { http_response_code(429); header('Retry-After: 60'); echo 'Too many changes. Please retry shortly.'; return true; }
    }
    if ($path === '/write/readers') {
        journeys_method(['GET', 'HEAD']); $queue = $_GET['queue'] ?? 'enquiries'; $filter = $_GET['status'] ?? '';
        if (!is_string($queue) || !in_array($queue, ['enquiries', 'founders'], true) || !is_string($filter)) { not_found(); return true; }
        $states = $queue === 'enquiries' ? reader_enquiry_states() : founders_review_states();
        if ($filter !== '' && !array_key_exists($filter, $states)) { not_found(); return true; }
        $from = $queue === 'enquiries' ? 'reader_enquiries r JOIN contact_messages c ON c.id=r.contact_id' : 'founder_submissions f JOIN reader_founder_followups r ON r.founder_id=f.id';
        $where = $filter !== '' ? ' WHERE '.($queue === 'enquiries' ? 'r.status' : 'f.review_status').'=?' : ''; $parameters = $filter !== '' ? [$filter] : [];
        if($queue==='enquiries')$where.=($where===''?' WHERE ':' AND ').'NOT EXISTS(SELECT 1 FROM reader_enquiry_trash t WHERE t.enquiry_id=r.id)';
        $query = db()->prepare('SELECT COUNT(*) FROM '.$from.$where); $query->execute($parameters);
        $pagination = archive_pagination(archive_page_number($_GET['page'] ?? null), (int)$query->fetchColumn(), 30, '/write/readers', ['queue' => $queue] + ($filter !== '' ? ['status' => $filter] : []));
        if ($pagination === null) { not_found(); return true; }
        $fields = $queue === 'enquiries' ? 'r.*,c.reference,c.first_name,c.last_name,c.email,c.email_sent' : 'f.id,f.reference,f.venture_name,f.review_status,r.clarification_status,r.permission_status,r.last_reply_at,r.receipt_state';
        $order = $queue === 'enquiries' ? 'r.updated_at DESC,r.id DESC' : 'r.updated_at DESC,f.id DESC';
        $query = db()->prepare('SELECT '.$fields.' FROM '.$from.$where.' ORDER BY '.$order.' LIMIT 30 OFFSET '.(int)$pagination['offset']); $query->execute($parameters);
        render_write('reader_queue', ['writeTitle' => 'Reader relationships', 'rows' => $query->fetchAll(), 'queue' => $queue, 'filter' => $filter, 'states' => $states, 'pagination' => $pagination, 'summary' => reader_operations_summary()]); return true;
    }
    if (preg_match('~^/write/readers/enquiries/([1-9][0-9]{0,9})$~', $path, $match)) {
        $id = reader_id($match[1]); $enquiry = reader_enquiry($id); if (!$enquiry) { not_found(); return true; }
        if (is_post()) {
            try { if(in_array($_POST['action']??'',['trash','restore'],true)){reader_enquiry_trash($id,(int)($_POST['row_version']??0),$actor,($_POST['action']??'')==='restore');flash('success',($_POST['action']==='restore'?'Message restored.':'Message moved to Trash. You can restore it.'));redirect(url('/write/communication?tab='.($_POST['action']==='restore'?'inbox':'trash')));}if(!empty($enquiry['is_trashed']))throw new ReaderValidationException('Restore this message before updating its follow-up record.');reader_enquiry_update($id, $_POST, $actor); flash('success', 'Enquiry record saved. No email or marketing subscription was created.'); redirect(url($path)); }
            catch (Throwable $exception) { $error = reader_route_error($exception); }
        }
        $query = db()->prepare('SELECT event_type,note,created_at FROM reader_enquiry_events WHERE enquiry_id=? ORDER BY id DESC LIMIT 100'); $query->execute([$id]);
        render_write('reader_enquiry', ['writeTitle' => 'Enquiry '.$enquiry['reference'], 'enquiry' => reader_enquiry($id), 'events' => $query->fetchAll(), 'error' => $error]); return true;
    }
    if (preg_match('~^/write/readers/founders/([1-9][0-9]{0,9})$~', $path, $match)) {
        $id = reader_id($match[1]); $submission = reader_founder($id); if (!$submission) { not_found(); return true; }
        if (is_post()) {
            try { $queued = reader_founder_writer_update($id, $_POST, $actor); flash($queued ? 'success' : 'error', $queued ? 'Follow-up saved. Any requested notification is queued; delivery is tracked by the outbox.' : 'The follow-up is saved, but the link notification could not be queued. Retry the link notification.'); redirect(url($path)); }
            catch (Throwable $exception) { $error = reader_route_error($exception); }
        }
        $query = db()->prepare('SELECT message_type,body,created_at FROM reader_founder_messages WHERE founder_id=? ORDER BY id DESC LIMIT 100'); $query->execute([$id]);
        render_write('reader_founder', ['writeTitle' => 'Founder follow-up', 'submission' => reader_founder($id), 'messages' => $query->fetchAll(), 'error' => $error]); return true;
    }
    not_found(); return true;
}
