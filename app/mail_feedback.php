<?php
declare(strict_types=1);

class MailFeedbackValidationException extends InvalidArgumentException {}

function email_address_hash(string $email): string { return hash('sha256',strtolower(trim($email))); }
function email_address_suppressed(string $email): bool {
    $q=db()->prepare('SELECT 1 FROM mail_suppressions WHERE email_hash=? AND active=1');$q->execute([email_address_hash($email)]);return (bool)$q->fetchColumn();
}
function email_recipient_lock(string $email,int $timeout=0): bool {
    $q=db()->prepare('SELECT GET_LOCK(?,?)');$q->execute(['wk-mail-'.substr(email_address_hash($email),0,56),max(0,min(30,$timeout))]);return (int)$q->fetchColumn()===1;
}
function email_recipient_unlock(string $email): void {
    $q=db()->prepare('SELECT RELEASE_LOCK(?)');$q->execute(['wk-mail-'.substr(email_address_hash($email),0,56)]);
}
function mail_feedback_topic(): string { return (string)app_config('mail.sns_topic_arn',''); }
function mail_feedback_topic_parts(string $topic): array {
    if (!preg_match('/\Aarn:(aws|aws-cn|aws-us-gov):sns:([a-z]{2}(?:-[a-z]+)+-[0-9]):([0-9]{12}):([A-Za-z0-9_-]{1,256})\z/',$topic,$m)) {
        throw new MailFeedbackValidationException('The mail feedback topic is not configured.');
    }
    return ['partition'=>$m[1],'region'=>$m[2],'account'=>$m[3],'name'=>$m[4],
        'host'=>'sns.'.$m[2].($m[1]==='aws-cn'?'.amazonaws.com.cn':'.amazonaws.com')];
}
function mail_feedback_certificate_url(string $url,array $topic): bool {
    $parts=parse_url($url);
    return $parts && ($parts['scheme']??'')==='https' && ($parts['host']??'')===$topic['host']
        && !isset($parts['user'],$parts['pass']) && !isset($parts['user']) && !isset($parts['pass'])
        && !isset($parts['port']) && !isset($parts['query']) && !isset($parts['fragment'])
        && (bool)preg_match('~\A/SimpleNotificationService-[A-Za-z0-9]+\.pem\z~',(string)($parts['path']??''));
}
function mail_feedback_download_certificate(string $url): string {
    $buffer='';$curl=curl_init($url);
    curl_setopt_array($curl,[
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>12,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$buffer):int {
            if (strlen($buffer)+strlen($chunk)>32768) return 0;
            $buffer.=$chunk;return strlen($chunk);
        },
    ]);
    $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    if ($ok===false || $status!==200 || !str_contains($buffer,'-----BEGIN CERTIFICATE-----')) throw new RuntimeException('The SNS certificate could not be retrieved.');
    return $buffer;
}
/** Validate the exact configured topic before any certificate request or database mutation. */
function mail_feedback_verify(string $body,?callable $testCertificateLoader=null): array {
    if ($testCertificateLoader && app_config('app_env')!=='testing') throw new RuntimeException('Test certificate loading is disabled.');
    if (strlen($body)>262144 || $body==='') throw new MailFeedbackValidationException('Invalid feedback size.');
    try { $message=json_decode($body,true,32,JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new MailFeedbackValidationException('Invalid feedback JSON.'); }
    if (!is_array($message)) throw new MailFeedbackValidationException('Invalid feedback message.');
    foreach (['Message','MessageId','Timestamp','TopicArn','Type','Signature','SigningCertURL','SignatureVersion'] as $field) {
        if (!isset($message[$field]) || !is_string($message[$field]) || $message[$field]==='') throw new MailFeedbackValidationException('Missing feedback field.');
    }
    $topic=mail_feedback_topic();$parts=mail_feedback_topic_parts($topic);
    if (!hash_equals($topic,$message['TopicArn'])) throw new MailFeedbackValidationException('Unexpected feedback topic.');
    if (!in_array($message['Type'],['Notification','SubscriptionConfirmation','UnsubscribeConfirmation'],true)
        || !in_array($message['SignatureVersion'],['1','2'],true)
        || !preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/',$message['MessageId'])
        || !mail_feedback_certificate_url($message['SigningCertURL'],$parts)) throw new MailFeedbackValidationException('Invalid feedback envelope.');
    if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?Z\z/',$message['Timestamp'])
        || ($timestamp=strtotime($message['Timestamp']))===false || $timestamp>time()+300 || $timestamp<time()-35*86400) throw new MailFeedbackValidationException('Feedback timestamp is outside the accepted window.');
    if ($message['Type']!=='Notification') {
        foreach (['Token','SubscribeURL'] as $field) if (!isset($message[$field]) || !is_string($message[$field]) || $message[$field]==='') throw new MailFeedbackValidationException('Invalid subscription confirmation.');
        if (strlen($message['Token'])>4096) throw new MailFeedbackValidationException('Invalid confirmation token.');
    }
    if (!class_exists(\Aws\Sns\MessageValidator::class)) throw new RuntimeException('Install the AWS SNS message validator.');
    $loader=$testCertificateLoader??'mail_feedback_download_certificate';
    $validator=new \Aws\Sns\MessageValidator(static function($url) use ($loader,$parts): string {
        if (!is_string($url) || !mail_feedback_certificate_url($url,$parts)) throw new MailFeedbackValidationException('Unexpected certificate URL.');
        return $loader($url);
    });
    try { $validator->validate(new \Aws\Sns\Message($message)); }
    catch (\Aws\Sns\Exception\InvalidSnsMessageException $e) { throw new MailFeedbackValidationException('Feedback signature is invalid.'); }
    $message['_source_at']=gmdate('Y-m-d H:i:s',$timestamp);
    return $message;
}
function mail_feedback_record(array $message,string $event,string $providerId,?int $outboxId,?string $email,string $action): bool {
    $q=db()->prepare('INSERT IGNORE INTO mail_feedback_events(message_id,topic_hash,event_type,provider_id,outbox_id,recipient_hash,action,source_at,received_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    $q->execute([strtolower($message['MessageId']),hash('sha256',$message['TopicArn']),mb_substr($event,0,32),mb_substr($providerId,0,255),$outboxId,$email?email_address_hash($email):null,$action,$message['_source_at']]);
    return $q->rowCount()===1;
}
/** Must be called only with a signature-verified envelope returned above. */
function mail_feedback_apply(array $message): array {
    $q=db()->prepare('SELECT 1 FROM mail_feedback_events WHERE message_id=?');$q->execute([strtolower($message['MessageId'])]);
    if ($q->fetchColumn()) return ['action'=>'duplicate'];
    if ($message['Type']==='SubscriptionConfirmation') {
        db()->beginTransaction();
        try {
            if (mail_feedback_record($message,'SubscriptionConfirmation','',null,null,'confirmation_pending')) {
                db()->prepare("INSERT INTO mail_feedback_subscriptions(topic_hash,token_encrypted,requested_at,status) VALUES(?,?,?,'pending') ON DUPLICATE KEY UPDATE token_encrypted=VALUES(token_encrypted),requested_at=VALUES(requested_at),status='pending'")->execute([hash('sha256',$message['TopicArn']),encrypt_secret($message['Token']),$message['_source_at']]);
            }
            db()->commit();return ['action'=>'confirmation_pending'];
        } catch (Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
    }
    if ($message['Type']==='UnsubscribeConfirmation') {
        db()->beginTransaction();
        try {
            if (mail_feedback_record($message,'UnsubscribeConfirmation','',null,null,'subscription_removed')) db()->prepare("UPDATE mail_feedback_subscriptions SET status='removed',token_encrypted=NULL WHERE topic_hash=?")->execute([hash('sha256',$message['TopicArn'])]);
            db()->commit();return ['action'=>'subscription_removed'];
        } catch (Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
    }
    try { $event=json_decode($message['Message'],true,32,JSON_THROW_ON_ERROR); }
    catch(JsonException $e) { throw new MailFeedbackValidationException('Invalid SES event.'); }
    if (!is_array($event)) throw new MailFeedbackValidationException('Invalid SES event.');
    $type=$event['eventType']??$event['notificationType']??'';
    $providerId=$event['mail']['messageId']??'';
    if (!is_string($type) || !is_string($providerId) || strlen($providerId)>255) throw new MailFeedbackValidationException('Invalid SES event identity.');
    if (!in_array($type,['Delivery','Bounce','Complaint'],true) || $providerId==='') {
        mail_feedback_record($message,is_string($type)?$type:'Unknown','',null,null,'ignored_event');return ['action'=>'ignored_event'];
    }
    $tag=$event['mail']['tags']['delivery-key'][0]??'';
    if (!is_string($tag) || ($tag!=='' && !preg_match('/\A[a-f0-9]{64}\z/',$tag))) throw new MailFeedbackValidationException('Invalid delivery tag.');
    $q=db()->prepare("SELECT * FROM email_outbox WHERE provider_id=? AND status IN ('sending','ambiguous','sent') ORDER BY id LIMIT 2");$q->execute([$providerId]);$rows=$q->fetchAll();
    $row=count($rows)===1?$rows[0]:null;
    if (!$row && $tag!=='') { $q=db()->prepare("SELECT * FROM email_outbox WHERE dedupe_key=? AND status IN ('sending','ambiguous','sent') AND attempts>0");$q->execute([$tag]);$row=$q->fetch()?:null; }
    if (!$row || ($row['provider_id']!==null && $row['provider_id']!=='' && !hash_equals($row['provider_id'],$providerId)) || ($tag!=='' && !hash_equals($row['dedupe_key'],$tag))) {
        mail_feedback_record($message,$type,$providerId,null,null,'unmatched_delivery');return ['action'=>'unmatched_delivery'];
    }
    $payload=json_decode(decrypt_secret($row['payload']),true,32,JSON_THROW_ON_ERROR);$email=strtolower(trim((string)($payload['to']??'')));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The stored email recipient is invalid.');
    $recipients=match($type) {
        'Delivery'=>$event['delivery']['recipients']??[],
        'Bounce'=>array_column(is_array($event['bounce']['bouncedRecipients']??null)?$event['bounce']['bouncedRecipients']:[],'emailAddress'),
        'Complaint'=>array_column(is_array($event['complaint']['complainedRecipients']??null)?$event['complaint']['complainedRecipients']:[],'emailAddress'),
    };
    $normalize=static function(mixed $values):array {
        return is_array($values)?array_map(static fn($v)=>is_string($v)?strtolower(trim($v)):'',array_slice($values,0,100)):[];
    };
    if (!in_array($email,$normalize($recipients),true) || !in_array($email,$normalize($event['mail']['destination']??null),true)) {
        mail_feedback_record($message,$type,$providerId,(int)$row['id'],null,'recipient_mismatch');return ['action'=>'recipient_mismatch'];
    }
    if (!email_recipient_lock($email,5)) throw new RuntimeException('Delivery state is temporarily busy.');
    $subscriberId=null;$subscriberLocked=false;
    try {
        $q=db()->prepare('SELECT id FROM newsletter_subscribers WHERE email=?');$q->execute([$email]);$subscriberId=$q->fetchColumn();
        if ($subscriberId && function_exists('newsletter_subscriber_lock')) { newsletter_subscriber_lock((int)$subscriberId,5);$subscriberLocked=true; }
        db()->beginTransaction();
        try {
            if (!mail_feedback_record($message,$type,$providerId,(int)$row['id'],$email,'recorded')) { db()->commit();return ['action'=>'duplicate']; }
            $q=db()->prepare('SELECT status,provider_id,feedback_status FROM email_outbox WHERE id=? FOR UPDATE');$q->execute([$row['id']]);$latest=$q->fetch();
            if (!$latest || ($latest['provider_id']!==null && $latest['provider_id']!=='' && !hash_equals($latest['provider_id'],$providerId))) throw new RuntimeException('Delivery acknowledgement changed.');
            $feedback=['Delivery'=>'delivered','Bounce'=>'bounced','Complaint'=>'complained'][$type];
            $priority=['delivered'=>1,'bounced'=>2,'complained'=>3];
            $effective=($priority[$latest['feedback_status']??'']??0)>$priority[$feedback]?$latest['feedback_status']:$feedback;
            db()->prepare("UPDATE email_outbox SET status='sent',provider_id=?,sent_at=COALESCE(sent_at,NOW()),error_code=NULL,feedback_status=?,feedback_at=UTC_TIMESTAMP() WHERE id=?")->execute([$providerId,$effective,$row['id']]);
            if (function_exists('email_acknowledgement_dependents')) email_acknowledgement_dependents((int)$row['id'],$providerId,$payload);
            if (function_exists('newsletter_delivery_feedback')) newsletter_delivery_feedback((int)$row['id'],$feedback,$providerId);
            $reason=$type==='Complaint'?'complaint':($type==='Bounce' && ($event['bounce']['bounceType']??'')==='Permanent'?'hard_bounce':null);
            if ($reason) {
                db()->prepare("INSERT INTO mail_suppressions(email_hash,email_encrypted,reason,outbox_id,active,created_at,updated_at) VALUES(?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE reason=IF(reason='complaint',reason,VALUES(reason)),outbox_id=VALUES(outbox_id),active=1,updated_at=UTC_TIMESTAMP()")->execute([email_address_hash($email),encrypt_secret($email),$reason,$row['id']]);
                if ($subscriberId) db()->prepare("UPDATE newsletter_subscribers SET status='suppressed',suppressed_at=COALESCE(suppressed_at,UTC_TIMESTAMP()),suppression_reason=IF(suppression_reason='complaint',suppression_reason,?),suppression_note='Authenticated provider feedback',consent_revision=consent_revision+1,updated_at=NOW() WHERE id=?")->execute([$reason,$subscriberId]);
            }
            db()->commit();return ['action'=>'recorded','feedback'=>$effective,'suppressed'=>$reason!==null];
        } catch(Throwable $e) { if(db()->inTransaction())db()->rollBack();throw $e; }
    } finally {
        if ($subscriberLocked) newsletter_subscriber_unlock((int)$subscriberId);
        email_recipient_unlock($email);
    }
}
function mail_feedback_confirm_subscription(): void {
    $topic=mail_feedback_topic();$parts=mail_feedback_topic_parts($topic);
    $q=db()->prepare("SELECT * FROM mail_feedback_subscriptions WHERE topic_hash=? AND status='pending'");$q->execute([hash('sha256',$topic)]);$row=$q->fetch();
    if (!$row || strtotime($row['requested_at'].' UTC')<time()-3*86400) throw new MailFeedbackValidationException('Request a fresh subscription confirmation in Amazon SNS.');
    $client=new \Aws\Sns\SnsClient(['version'=>'2010-03-31','region'=>$parts['region'],'retries'=>0,'http'=>['connect_timeout'=>5,'timeout'=>20]]);
    // Never visit a SubscribeURL supplied in an incoming message.
    $result=$client->confirmSubscription(['TopicArn'=>$topic,'Token'=>decrypt_secret($row['token_encrypted']),'AuthenticateOnUnsubscribe'=>'true']);
    $arn=(string)($result['SubscriptionArn']??'');
    if (!str_starts_with($arn,$topic.':')) throw new RuntimeException('SNS subscription confirmation is unconfirmed.');
    db()->prepare("UPDATE mail_feedback_subscriptions SET status='confirmed',confirmed_at=UTC_TIMESTAMP(),subscription_arn=?,token_encrypted=NULL WHERE topic_hash=?")->execute([$arn,hash('sha256',$topic)]);
}
/** Called before starting a browser session: SNS never needs a Writer cookie. */
function mail_feedback_webhook(string $path): bool {
    if ($path!=='/webhooks/ses') return false;
    header('Cache-Control: no-store, private');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: application/json');
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { http_response_code(405);header('Allow: POST');echo '{"ok":false}';exit; }
    if (mail_feedback_topic()==='') { http_response_code(503);echo '{"ok":false}';exit; }
    try {
        $body=file_get_contents('php://input',false,null,0,262145);
        $message=mail_feedback_verify((string)$body);mail_feedback_apply($message);echo '{"ok":true}';
    } catch (MailFeedbackValidationException $e) { http_response_code(400);echo '{"ok":false}'; }
    catch (Throwable $e) { error_log('Mail feedback processing failed: '.get_class($e));http_response_code(503);echo '{"ok":false}'; }
    exit;
}
function mail_feedback_dispatch(string $path): bool {
    if ($path!=='/write/mail-feedback') return false;
    journeys_method(['GET','HEAD','POST']);require_auth();journeys_private_headers();$error=null;
    if (is_post()) {
        journeys_verify_csrf();
        try {
            if (($_POST['action']??'')!=='confirm') throw new MailFeedbackValidationException('Choose a valid feedback action.');
            mail_feedback_confirm_subscription();flash('success','The configured SNS subscription is confirmed. Verify a delivery event before enabling campaign sends.');redirect(url('/write/mail-feedback'));
        } catch(Throwable $e) {
            if ($e instanceof MailFeedbackValidationException) { http_response_code(422);$error=$e->getMessage(); }
            else $error=journeys_route_error($e);
        }
    }
    $q=db()->prepare('SELECT status,requested_at,confirmed_at FROM mail_feedback_subscriptions WHERE topic_hash=?');$q->execute([hash('sha256',mail_feedback_topic())]);
    $subscription=$q->fetch()?:null;
    $events=db()->query('SELECT event_type,action,outbox_id,source_at,received_at FROM mail_feedback_events ORDER BY received_at DESC LIMIT 100')->fetchAll();
    $suppressed=(int)db()->query('SELECT COUNT(*) FROM mail_suppressions WHERE active=1')->fetchColumn();
    render_write('mail_feedback',['writeTitle'=>'Mail delivery feedback','configured'=>mail_feedback_topic()!=='','subscription'=>$subscription,'events'=>$events,'suppressed'=>$suppressed,'error'=>$error]);exit;
}
