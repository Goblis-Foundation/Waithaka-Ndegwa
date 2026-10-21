<?php
declare(strict_types=1);

/** Durable outbox: enqueueing is not a claim that an email has been delivered. */
function enqueue_email(string $to,string $subject,string $html,string $dedupeKey): int {
    if (!filter_var($to,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$to.$subject) || $dedupeKey==='') throw new InvalidArgumentException('Invalid email request.');
    if (mb_strlen($subject)>250 || strlen($html)>500000) throw new InvalidArgumentException('Email is too large.');
    $payload=encrypt_secret(json_encode(['to'=>$to,'subject'=>$subject,'html'=>$html,'dedupe_key'=>$dedupeKey],JSON_THROW_ON_ERROR));
    $q=db()->prepare('INSERT INTO email_outbox(dedupe_key,payload) VALUES(?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
    $q->execute([hash('sha256',$dedupeKey),$payload]);return (int)db()->lastInsertId();
}
function email_transport_ready(): bool {
    $type=app_config('mail.transport','disabled');
    $from=app_config('mail.from','');
    if (!filter_var($from,FILTER_VALIDATE_EMAIL)) return false;
    return ($type==='ses' && class_exists(\Aws\SesV2\SesV2Client::class) && app_config('mail.ses_region',app_config('aws_region')))
        || ($type==='smtp' && class_exists(\PHPMailer\PHPMailer\PHPMailer::class) && app_config('mail.smtp_host'));
}
function email_deliver(array $payload,string $deliveryKey): string {
    $from=(string)app_config('mail.from'); $name=(string)app_config('mail.from_name','Waithaka Ndegwa');
    $text=html_entity_decode(strip_tags(str_replace(['</p>','<br>','<br />'],"\n",$payload['html'])),ENT_QUOTES|ENT_HTML5,'UTF-8');
    if (app_config('mail.transport')==='ses') {
        $client=new \Aws\SesV2\SesV2Client(['version'=>'2019-09-27','region'=>app_config('mail.ses_region',app_config('aws_region')),'retries'=>0,'http'=>['connect_timeout'=>5,'timeout'=>25]]);
        $args=['FromEmailAddress'=>$from,'Destination'=>['ToAddresses'=>[$payload['to']]],'Content'=>['Simple'=>[
            'Subject'=>['Data'=>$payload['subject'],'Charset'=>'UTF-8'], 'Body'=>['Html'=>['Data'=>$payload['html'],'Charset'=>'UTF-8'],'Text'=>['Data'=>$text,'Charset'=>'UTF-8']]]],
            'EmailTags'=>[['Name'=>'delivery-key','Value'=>$deliveryKey]]];
        if (app_config('mail.ses_configuration_set')) $args['ConfigurationSetName']=app_config('mail.ses_configuration_set');
        if (filter_var(app_config('mail.reply_to',''),FILTER_VALIDATE_EMAIL)) $args['ReplyToAddresses']=[app_config('mail.reply_to')];
        return (string)$client->sendEmail($args)['MessageId'];
    }
    if (app_config('mail.transport')!=='smtp') throw new RuntimeException('Email delivery is disabled.');
    $mail=new \PHPMailer\PHPMailer\PHPMailer(true); $mail->isSMTP();
    $mail->Host=(string)app_config('mail.smtp_host'); $mail->Port=(int)app_config('mail.smtp_port',587);
    $security=(string)app_config('mail.smtp_encryption','tls');
    if (!in_array($security,['tls','ssl'],true)) throw new RuntimeException('SMTP requires TLS.');
    $mail->SMTPSecure=$security; $mail->SMTPAuth=(string)app_config('mail.smtp_username','')!=='';
    $mail->Username=(string)app_config('mail.smtp_username',''); $mail->Password=(string)app_config('mail.smtp_password','');
    $mail->Timeout=25; $mail->CharSet='UTF-8';$mail->setFrom($from,$name);$mail->addAddress($payload['to']);
    if (filter_var(app_config('mail.reply_to',''),FILTER_VALIDATE_EMAIL)) $mail->addReplyTo(app_config('mail.reply_to'));
    $mail->MessageID='<'.$deliveryKey.'@'.substr(strrchr($from,'@'),1).'>';
    $mail->isHTML(true);$mail->Subject=$payload['subject'];$mail->Body=$payload['html'];$mail->AltBody=$text;
    $mail->send();return $mail->getLastMessageID();
}
/** Repair dependent records from durable provider acknowledgements without resending. */
function email_reconcile_delivery_state(): void {
    // The hash column is ASCII; source identifiers have a different Unicode collation.
    db()->exec("UPDATE contact_messages c JOIN email_outbox e ON BINARY e.dedupe_key=BINARY SHA2(CONCAT('contact:',c.reference),256) SET c.email_sent=1 WHERE c.email_sent=0 AND e.status='sent'");
    db()->exec("UPDATE publishing_jobs j JOIN email_outbox e ON BINARY e.dedupe_key=BINARY SHA2(CONCAT('publishing:',j.job_key),256) SET j.status='reminder_sent',j.last_error=NULL WHERE j.status='reminder_queued' AND e.status='sent'");
    db()->exec("UPDATE publishing_jobs j JOIN email_outbox e ON BINARY e.dedupe_key=BINARY SHA2(CONCAT('publishing:',j.job_key),256) SET j.status='assisted',j.last_error='Reminder delivery is unconfirmed. Complete the task in the platform and record its URL.' WHERE j.status='reminder_queued' AND e.status='ambiguous'");
}
function email_record_acknowledgement(int $id,string $providerId,array $payload): void {
    db()->beginTransaction();
    try {
        db()->prepare("UPDATE email_outbox SET status='sent',sent_at=NOW(),provider_id=?,error_code=NULL WHERE id=?")->execute([mb_substr($providerId,0,255),$id]);
        if (str_starts_with($payload['dedupe_key'],'contact:')) db()->prepare('UPDATE contact_messages SET email_sent=1 WHERE reference=?')->execute([substr($payload['dedupe_key'],8)]);
        if (str_starts_with($payload['dedupe_key'],'publishing:')) db()->prepare("UPDATE publishing_jobs SET status='reminder_sent',last_error=NULL WHERE job_key=? AND status='reminder_queued'")->execute([substr($payload['dedupe_key'],11)]);
        db()->commit();
    } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
}
function email_work(int $limit=10,?callable $testTransport=null): array {
    if ($testTransport && app_config('app_env')!=='testing') throw new RuntimeException('Test transport is disabled.');
    // A crashed worker may have sent already; never automatically resend uncertain email.
    db()->exec("UPDATE email_outbox SET status='ambiguous',error_code='worker_interrupted' WHERE status='sending' AND claimed_at<DATE_SUB(NOW(),INTERVAL 15 MINUTE)");
    email_reconcile_delivery_state();
    $enabled=(bool)$testTransport || email_transport_ready();
    $stats=['enabled'=>$enabled,'sent'=>0,'ambiguous'=>0,'bookkeeping_pending'=>0];
    if (!$enabled) return $stats;
    for ($i=0;$i<max(1,min(100,$limit));$i++) {
        db()->beginTransaction();
        try {
            $row=db()->query("SELECT * FROM email_outbox WHERE status='pending' AND available_at<=NOW() ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
            if (!$row) { db()->commit();break; }
            db()->prepare("UPDATE email_outbox SET status='sending',claimed_at=NOW(),attempts=attempts+1 WHERE id=?")->execute([$row['id']]);db()->commit();
        } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
        $payload=null;$publishLock=false;$providerId=null;
        try {
            $payload=json_decode(decrypt_secret($row['payload']),true,512,JSON_THROW_ON_ERROR);
            if (str_starts_with($payload['dedupe_key'],'newsletter-confirm:') && function_exists('newsletter_confirmation_deliverable') && !newsletter_confirmation_deliverable($payload['dedupe_key'])) {
                db()->prepare("UPDATE email_outbox SET status='suppressed',error_code='confirmation_obsolete' WHERE id=?")->execute([$row['id']]);
                continue;
            }
            if (str_starts_with($payload['dedupe_key'],'publishing:')) {
                $publishLock=(int)db()->query("SELECT GET_LOCK('waithaka_publishing_worker',0)")->fetchColumn()===1;
                if (!$publishLock) {
                    db()->prepare("UPDATE email_outbox SET status='pending',claimed_at=NULL,attempts=GREATEST(attempts-1,0),available_at=DATE_ADD(NOW(),INTERVAL 1 MINUTE) WHERE id=?")->execute([$row['id']]);
                    continue;
                }
                $q=db()->prepare('SELECT j.status,r.snapshot_json FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id WHERE j.job_key=?');$q->execute([substr($payload['dedupe_key'],11)]);$job=$q->fetch();
                if (!$job || $job['status']!=='reminder_queued') {
                    db()->prepare("UPDATE email_outbox SET status='suppressed',error_code='publishing_task_obsolete' WHERE id=?")->execute([$row['id']]);continue;
                }
                $snapshot=json_decode($job['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                if (!empty($snapshot['source_post_id'])) {
                    $q=db()->prepare("SELECT 1 FROM posts p WHERE p.id=? AND ".published_where());$q->execute([(int)$snapshot['source_post_id']]);
                    if (!$q->fetchColumn()) {
                        db()->prepare("UPDATE email_outbox SET status='suppressed',error_code='source_not_public' WHERE id=?")->execute([$row['id']]);
                        db()->prepare("UPDATE publishing_jobs SET status='failed',last_error='Source article is no longer public. Review before sharing.' WHERE job_key=?")->execute([substr($payload['dedupe_key'],11)]);continue;
                    }
                }
            }
            $providerId=$testTransport?$testTransport($payload,$row['dedupe_key']):email_deliver($payload,$row['dedupe_key']);
            if (!$providerId) throw new RuntimeException('No provider acknowledgement.');
            email_record_acknowledgement((int)$row['id'],(string)$providerId,$payload);
            $stats['sent']++;
        } catch(Throwable $e) {
            if ($providerId) {
                // A successful send must not become an uncertain send because local bookkeeping failed.
                // Reconciliation above repairs dependent records on the next worker invocation.
                try { db()->prepare("UPDATE email_outbox SET status='sent',sent_at=COALESCE(sent_at,NOW()),provider_id=?,error_code='bookkeeping_pending' WHERE id=?")->execute([mb_substr((string)$providerId,0,255),$row['id']]); }
                catch(Throwable $recordError) { error_log('Email outbox '.(int)$row['id'].' was acknowledged but its database record could not be saved. Reconcile provider records; do not resend.'); }
                $stats['sent']++;$stats['bookkeeping_pending']++;
                error_log('Email outbox '.(int)$row['id'].' acknowledgement received; dependent bookkeeping requires reconciliation.');
                continue;
            }
            db()->prepare("UPDATE email_outbox SET status='ambiguous',error_code='delivery_unconfirmed' WHERE id=?")->execute([$row['id']]);$stats['ambiguous']++;
            if (isset($payload['dedupe_key']) && str_starts_with($payload['dedupe_key'],'publishing:')) db()->prepare("UPDATE publishing_jobs SET status='assisted',last_error='Email reminder delivery is unconfirmed. Complete this task in the platform and record its URL.' WHERE job_key=? AND status='reminder_queued'")->execute([substr($payload['dedupe_key'],11)]);
            error_log('Email outbox '.(int)$row['id'].' delivery unconfirmed; inspect provider delivery logs before retrying.');
        } finally { if ($publishLock) db()->query("SELECT RELEASE_LOCK('waithaka_publishing_worker')"); }
    }
    return $stats;
}
function publishing_send_reminder(array $job,array $snapshot): bool {
    $to='';
    if (!empty($job['created_by'])) {$q=db()->prepare('SELECT email FROM users WHERE id=? AND is_active=1');$q->execute([(int)$job['created_by']]);$to=(string)$q->fetchColumn();}
    if (!$to) $to=(string)setting('contact.recipient','');
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)) return false;
    $dest=json_decode((string)($job['destination_json']??'{}'),true)?:[];$slug=(string)($dest['slug']??'');
    $variant=$snapshot['variants'][$slug]??[];
    $html='<p>A publishing task is ready for '.e($dest['name']??$slug).'.</p><h2>'.e($snapshot['title']??'Weekly update').'</h2><p>'.nl2br(e($variant['text']??'')).'</p><p><a href="'.e(url('/write/publishing')).'">Review the approved copy in Writer</a></p><p>This reminder does not mean that the social post has been published. Complete the platform steps and record its URL in Writer.</p>';
    $id=enqueue_email($to,'Publishing task ready · '.mb_substr($snapshot['title']??'Weekly update',0,150),$html,'publishing:'.$job['job_key']);
    $q=db()->prepare('SELECT status FROM email_outbox WHERE id=?');$q->execute([$id]);
    // A deduplicated sent/ambiguous/suppressed row is not a new queued reminder.
    return in_array($q->fetchColumn(),['pending','sending'],true);
}
function app_extension_route(string $path): bool {
    if (function_exists('journeys_dispatch') && journeys_dispatch($path)) return true;
    return function_exists('publishing_dispatch') && publishing_dispatch($path);
}
function app_sitemap_entries(): array {
    return function_exists('journeys_sitemap_entries') ? journeys_sitemap_entries() : [];
}
