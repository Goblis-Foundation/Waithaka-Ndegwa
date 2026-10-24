<?php
declare(strict_types=1);

/** General permission never substitutes for a founder's approval of the final copy. */
function founder_story_snapshot(array $post): array {
    $keys=['title','standfirst','content_html','featured_image','featured_image_alt','featured_image_caption','video_url','series_name','travel_country','travel_city'];
    $out=[];foreach($keys as$key)$out[$key]=(string)($post[$key]??'');
    $out['seo_title']=trim((string)($post['seo_title']??''))?:$out['title'];
    $out['seo_description']=trim((string)($post['seo_description']??''))?:excerpt($out['standfirst']?:$out['content_html'],155);
    $out['public_evidence']=isset($post['id'])?founder_story_public_evidence((int)$post['id']):($post['public_evidence']??['evidence'=>[],'updates'=>[]]);return $out;
}
function founder_story_public_evidence(int $post): array {
    $q=db()->prepare('SELECT public_json FROM editorial_post_meta WHERE post_id=?');$q->execute([$post]);$raw=$q->fetchColumn();$data=$raw?json_decode($raw,true,32,JSON_THROW_ON_ERROR):[];$cards=[];
    foreach(($data['evidence']??[])as$card){$public=[];foreach(['label','basis','summary','url','limitations','observed_on']as$key)$public[$key]=(string)($card[$key]??'');$cards[]=$public;}
    $q=db()->prepare('SELECT update_kind,note,published_at FROM editorial_post_updates WHERE post_id=? ORDER BY id');$q->execute([$post]);return ['evidence'=>$cards,'updates'=>$q->fetchAll()];
}
function founder_story_hash(array $post,?int $id=null): string { if($id!==null)$post['id']=$id;return hash('sha256',reader_json(founder_story_snapshot($post))); }
function founder_story_workflow(int $id,bool $lock=false): ?array {
    $q=db()->prepare('SELECT * FROM founder_story_workflows WHERE founder_id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);return $q->fetch()?:null;
}
function founder_story_lock_post(int $id): array {
    $q=db()->prepare('SELECT * FROM posts WHERE id=? FOR UPDATE');$q->execute([$id]);$post=$q->fetch();if(!$post)throw new JourneyValidationException('Story draft not found.');return $post;
}
function founder_story_attach(int $founder,int $post): void {
    $q=db()->prepare('SELECT * FROM founder_submissions WHERE id=? FOR UPDATE');$q->execute([$founder]);$f=$q->fetch();
    if(!$f||in_array($f['review_status'],['declined','withdrawn'],true))throw new JourneyValidationException('This submission is not open for a story.');
    if($f['associated_post_id']&&(int)$f['associated_post_id']!==$post)throw new JourneyValidationException('This founder already has a linked story.');
    $w=founder_story_workflow($founder,true);if($w&&(int)$w['post_id']!==$post)throw new JourneyValidationException('An approval workflow cannot be moved to another story.');
    $q=db()->prepare('SELECT id FROM founder_submissions WHERE associated_post_id=? AND id<>? LIMIT 1 FOR UPDATE');$q->execute([$post,$founder]);if($q->fetchColumn())throw new JourneyValidationException('Use a separate story draft for this founder.');
    db()->prepare('UPDATE founder_submissions SET associated_post_id=?,row_version=row_version+1 WHERE id=?')->execute([$post,$founder]);
    $current=founder_story_lock_post($post);
    if(!$w||!is_string($w['approved_hash'])||!hash_equals($w['approved_hash'],founder_story_hash($current)))db()->prepare("UPDATE posts SET status='draft',founder_approval_hash=NULL WHERE id=?")->execute([$post]);
}
function founder_story_guard_publish(int $id,array $fields): ?string {
    $q=db()->prepare('SELECT id FROM founder_submissions WHERE associated_post_id=? ORDER BY id FOR UPDATE');$q->execute([$id]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);if(!$ids)return null;
    $hash=founder_story_hash($fields,$id);
    foreach($ids as$founder){$w=founder_story_workflow((int)$founder,true);
        if(!$w||$w['state']!=='approved'||!is_string($w['approved_hash'])||!hash_equals($w['approved_hash'],$hash))throw new JourneyValidationException('Founder approval of this exact story version is required before publication. Send the final draft from Founder Stories.');
        $q=db()->prepare("SELECT content_hash,state FROM founder_story_reviews WHERE id=? AND founder_id=?");$q->execute([$w['current_review_id'],$founder]);$review=$q->fetch();
        if(!$review||$review['state']!=='approved'||!hash_equals($review['content_hash'],$hash))throw new JourneyValidationException('The founder has not approved this final version.');
    }return $hash;
}
function founder_story_public_sql(string $alias='p'): string {
    if(!preg_match('/^[a-z]+$/D',$alias))throw new InvalidArgumentException('Invalid story query alias.');
    return "NOT EXISTS (SELECT 1 FROM founder_submissions fs WHERE fs.associated_post_id=$alias.id AND NOT EXISTS (SELECT 1 FROM founder_story_workflows fw WHERE fw.founder_id=fs.id AND fw.post_id=$alias.id AND fw.state<>'discarded' AND fw.approved_hash=$alias.founder_approval_hash))";
}
function founder_story_discard(array $w,string $reason,?int $actor=null): void {
    db()->prepare("UPDATE founder_story_workflows SET state='discarded',approved_hash=NULL,updated_at=UTC_TIMESTAMP() WHERE founder_id=?")->execute([$w['founder_id']]);
    db()->prepare("UPDATE founder_submissions SET review_status='declined',is_public=0,permission_confirmed=0,row_version=row_version+1 WHERE id=?")->execute([$w['founder_id']]);
    db()->prepare("UPDATE posts SET status='archived',founder_approval_hash=NULL,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$w['post_id']]);
    db()->prepare("UPDATE founder_story_deliveries d JOIN founder_story_reviews r ON r.id=d.review_id SET d.state='cancelled' WHERE r.founder_id=? AND d.state IN ('pending','blocked')")->execute([$w['founder_id']]);
    db()->prepare("UPDATE founder_story_reviews SET state='revoked' WHERE founder_id=? AND state='pending'")->execute([$w['founder_id']]);
    journeys_audit('founder',(int)$w['founder_id'],'story_discarded:'.$reason,$actor);
}
function founder_story_request(int $id,array $input,int $actor): int {
    $q=db()->prepare('SELECT associated_post_id FROM founder_submissions WHERE id=?');$q->execute([$id]);$postId=(int)$q->fetchColumn();if(!$postId)throw new JourneyValidationException('Link a private story draft first.');
    $days=filter_var($input['response_days']??14,FILTER_VALIDATE_INT);if($days===false||$days<1||$days>30)throw new JourneyValidationException('Choose a response window of 1–30 days.');
    $email=journeys_email($input['founder_email']??'');$phone=reader_text($input['founder_whatsapp']??'','Founder WhatsApp number',20,true);
    if(!preg_match('/^\+[1-9][0-9]{7,14}$/D',$phone)||empty($input['contact_confirmed'])||empty($input['whatsapp_optin'])||empty($input['send_review_ack']))throw new JourneyValidationException('Confirm the founder’s own email, international WhatsApp number, notification permission and the request to send this exact draft.');
    $evidence=reader_text($input['contact_evidence']??'','Contact and WhatsApp permission record',500,true);
    $pdo=db();$pdo->beginTransaction();
    try {
        $post=founder_story_lock_post($postId);$q=$pdo->prepare('SELECT * FROM founder_submissions WHERE id=? FOR UPDATE');$q->execute([$id]);$founder=$q->fetch();
        if(!$founder||(int)$founder['associated_post_id']!==$postId||in_array($founder['review_status'],['declined','withdrawn'],true))throw new JourneyValidationException('This submission is no longer open.');
        $w=founder_story_workflow($id,true);
        if($w&&($w['state']==='discarded'||(int)$w['rounds_used']>=3))throw new JourneyValidationException('This story has exhausted its three reviews or was discarded.');
        if($w&&($w['email']!==$email||$w['whatsapp']!==$phone))throw new JourneyValidationException('Recorded founder contacts cannot change during this workflow. Resolve identity/contact corrections privately before continuing.');
        $post=writer_post_by_id($postId);$snapshot=founder_story_snapshot($post);if(trim($snapshot['title'])===''||plain_text($snapshot['content_html'])==='')throw new JourneyValidationException('Save the complete story draft first.');
        if($w&&$w['state']==='pending'){
            $q=$pdo->prepare('SELECT content_hash FROM founder_story_reviews WHERE id=? FOR UPDATE');$q->execute([$w['current_review_id']]);
            if(hash_equals((string)$q->fetchColumn(),founder_story_hash($snapshot)))throw new JourneyValidationException('This exact version is already awaiting review. Retry its notifications instead.');
            $pdo->prepare("UPDATE founder_story_reviews SET state='superseded' WHERE id=? AND state='pending'")->execute([$w['current_review_id']]);
            $pdo->prepare("UPDATE founder_story_deliveries SET state='cancelled' WHERE review_id=? AND state IN ('pending','blocked')")->execute([$w['current_review_id']]);
        }
        if(!$w){$pdo->prepare('INSERT INTO founder_story_workflows(founder_id,post_id,email,whatsapp,contact_evidence,created_at,updated_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$id,$postId,$email,$phone,$evidence]);$w=founder_story_workflow($id,true);}
        $round=(int)$w['rounds_used']+1;$token=bin2hex(random_bytes(32));$hash=founder_story_hash($snapshot);
        $pdo->prepare("INSERT INTO founder_story_reviews(founder_id,round_no,token_hash,contact_hash,snapshot_json,content_hash,response_days,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())")->execute([$id,$round,hash('sha256',$token),hash('sha256',$email.'|'.$phone),reader_json($snapshot),$hash,$days,$actor]);$review=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE founder_story_workflows SET state='pending',rounds_used=?,current_review_id=?,updated_at=UTC_TIMESTAMP() WHERE founder_id=?")->execute([$round,$review,$id]);
        $link=url('/founders/story-review?token='.$token);
        $html=email_design('Your Founder Story is ready to review','<p>Please review the exact story version shown in your private link. This is review <strong>'.$round.' of 3</strong>.</p>'.email_design_button('Review your story',$link).'<p>You can approve, propose edits or decline. Nothing is published by opening the link. The response window is '.$days.' days once both notifications have been accepted by their providers. Your response does not subscribe you to newsletters.</p>',['name'=>$founder['founder_name']]);
        foreach(['email'=>['to'=>$email,'subject'=>'Review your Founder Story · Waithaka Ndegwa','html'=>$html,'dedupe_key'=>'founder-story:'.$review],
            'whatsapp'=>['to'=>$phone,'name'=>$founder['founder_name'],'title'=>$snapshot['title'],'round'=>$round,'url'=>$link]] as$channel=>$payload){
            $pdo->prepare('INSERT INTO founder_story_deliveries(review_id,channel,payload_encrypted) VALUES(?,?,?)')->execute([$review,$channel,encrypt_secret(reader_json($payload))]);
        }
        journeys_audit('founder',$id,'story_review_requested:'.$round,$actor);$pdo->commit();return $review;
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function founder_story_token(string $token,bool $lock=false): ?array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$token))return null;
    $q=db()->prepare('SELECT * FROM founder_story_reviews WHERE token_hash=?');$q->execute([hash('sha256',$token)]);$review=$q->fetch();if(!$review)return null;
    $w=founder_story_workflow((int)$review['founder_id']);
    if(!$w||$w['state']!=='pending'||(int)$w['current_review_id']!==(int)$review['id']||$review['state']!=='pending'||!hash_equals($review['contact_hash'],hash('sha256',$w['email'].'|'.$w['whatsapp']))
        ||($review['expires_at']!==null&&$review['expires_at']<=gmdate('Y-m-d H:i:s'))||($review['expires_at']===null&&strtotime($review['created_at'].' UTC')<time()-30*86400))return null;
    $snapshot=json_decode($review['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
    return is_array($snapshot)&&hash_equals($review['content_hash'],founder_story_hash($snapshot))?$review:null;
}
function founder_story_respond(string $token,array $input): void {
    $review=founder_story_token($token);if(!$review)throw new JourneyValidationException('This review link is no longer available.');
    $w=founder_story_workflow((int)$review['founder_id']);$pdo=db();$pdo->beginTransaction();
    try {
        founder_story_lock_post((int)$w['post_id']);$w=founder_story_workflow((int)$w['founder_id'],true);
        $q=$pdo->prepare('SELECT * FROM founder_story_reviews WHERE id=? FOR UPDATE');$q->execute([$review['id']]);$review=$q->fetch();
        if(!founder_story_token($token))throw new JourneyValidationException('This review was answered, replaced or expired.');
        $action=$input['decision']??'';if(!in_array($action,['approve','changes','decline'],true))throw new JourneyValidationException('Choose approve, propose edits or decline.');
        $note=reader_text($input['note']??'','Response note',5000);
        $proposal=null;
        if($action==='approve'&&empty($input['approval_ack']))throw new JourneyValidationException('Confirm approval of the exact story and media shown.');
        if($action==='changes'){
            $proposal=json_decode($review['snapshot_json'],true,32,JSON_THROW_ON_ERROR);
            $proposal['title']=reader_text($input['proposed_title']??'','Proposed title',240,true);
            $proposal['standfirst']=reader_text($input['proposed_standfirst']??'','Proposed introduction',8000);
            $copy=reader_text($input['proposed_copy']??'','Proposed story',100000,true);
            $proposal['content_html']='<p>'.str_replace("\n\n",'</p><p>',nl2br(e($copy),false)).'</p>';
        }
        $state=match($action){'approve'=>'approved','changes'=>'changes_requested',default=>'declined'};
        $pdo->prepare('UPDATE founder_story_reviews SET state=?,proposed_json=?,response_note=?,responded_at=UTC_TIMESTAMP() WHERE id=?')->execute([$state,$proposal?reader_json($proposal):null,$note,$review['id']]);
        if($action==='approve')$pdo->prepare("UPDATE founder_story_workflows SET state='approved',approved_hash=?,updated_at=UTC_TIMESTAMP() WHERE founder_id=?")->execute([$review['content_hash'],$w['founder_id']]);
        elseif($action==='decline'||(int)$w['rounds_used']>=3)founder_story_discard($w,$action==='decline'?'founder_declined':'three_reviews_without_approval');
        else $pdo->prepare("UPDATE founder_story_workflows SET state='changes_requested',updated_at=UTC_TIMESTAMP() WHERE founder_id=?")->execute([$w['founder_id']]);
        $pdo->prepare("UPDATE founder_story_deliveries SET state='cancelled' WHERE review_id=? AND state IN ('pending','blocked')")->execute([$review['id']]);
        journeys_audit('founder',(int)$w['founder_id'],'story_founder_response:'.$state,null);$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function founder_story_apply_edits(int $id,int $actor): void {
    $w=founder_story_workflow($id);if(!$w)throw new JourneyValidationException('Story workflow not found.');$pdo=db();$pdo->beginTransaction();
    try {
        $post=founder_story_lock_post((int)$w['post_id']);$w=founder_story_workflow($id,true);
        $q=$pdo->prepare('SELECT * FROM founder_story_reviews WHERE id=? FOR UPDATE');$q->execute([$w['current_review_id']]);$review=$q->fetch();
        if($w['state']!=='changes_requested'||!$review||!$review['proposed_json'])throw new JourneyValidationException('No founder edits are available to import.');
        $proposal=json_decode($review['proposed_json'],true,32,JSON_THROW_ON_ERROR);$working=writer_post_by_id((int)$w['post_id']);
        foreach(['title','standfirst','content_html']as$key)$working[$key]=$proposal[$key];
        $fields=array_intersect_key($working,array_flip(writer_editable_fields()));
        $pdo->prepare('INSERT INTO post_drafts(post_id,payload,version,updated_by) VALUES(?,?,1,?) ON DUPLICATE KEY UPDATE payload=VALUES(payload),version=version+1,updated_by=VALUES(updated_by),updated_at=NOW()')->execute([$w['post_id'],reader_json($fields),$actor]);
        journeys_audit('founder',$id,'story_proposed_edits_imported',$actor);$pdo->commit();
    }catch(Throwable $error){if($pdo->inTransaction())$pdo->rollBack();throw $error;}
}
function founder_story_whatsapp_ready(): bool {
    return setting('founder_story.whatsapp_enabled','0')==='1'&&preg_match('/^[0-9]{5,30}$/D',(string)setting('founder_story.whatsapp_phone_id',''))
        &&preg_match('/^[a-z0-9_]{1,100}$/D',(string)setting('founder_story.whatsapp_template',''))&&(string)setting('founder_story.whatsapp_token','')!=='';
}
function founder_story_whatsapp_send(array $payload): string {
    $version=(string)setting('publishing.meta.version','v23.0');if(!preg_match('/^v[0-9]+\.[0-9]+$/D',$version))throw new RuntimeException('whatsapp_configuration_invalid');
    $language=(string)setting('founder_story.whatsapp_language','en');if(!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$language))throw new RuntimeException('whatsapp_configuration_invalid');
    $params=[];foreach([$payload['name'],$payload['title'],(string)$payload['round'],$payload['url']]as$value)$params[]=['type'=>'text','text'=>$value];
    $response=publishing_http('POST','https://graph.facebook.com/'.$version.'/'.setting('founder_story.whatsapp_phone_id').'/messages',publishing_bearer((string)setting('founder_story.whatsapp_token')),
        ['messaging_product'=>'whatsapp','to'=>ltrim($payload['to'],'+'),'type'=>'template','template'=>['name'=>setting('founder_story.whatsapp_template'),'language'=>['code'=>$language],'components'=>[['type'=>'body','parameters'=>$params]]]],false,true);
    $id=$response['data']['messages'][0]['id']??null;if(!is_string($id)||$id==='')throw new RuntimeException('whatsapp_acceptance_unconfirmed');return $id;
}
function founder_story_reconcile_deadlines(): void {
    db()->exec("UPDATE founder_story_reviews r SET r.expires_at=DATE_ADD((SELECT MAX(d.accepted_at) FROM founder_story_deliveries d WHERE d.review_id=r.id),INTERVAL r.response_days DAY) WHERE r.state='pending' AND r.expires_at IS NULL AND (SELECT COUNT(*) FROM founder_story_deliveries d WHERE d.review_id=r.id AND d.state='accepted')=2");
}
/** Dedicated opt-in editorial delivery; never drains newsletter/social/payment queues. */
function founder_story_work(int $limit=5,?callable $testTransport=null): array {
    if($testTransport&&app_config('app_env')!=='testing')throw new RuntimeException('Test transport unavailable.');
    $result=['accepted'=>0,'blocked'=>0,'ambiguous'=>0,'discarded'=>0];
    founder_story_reconcile_deadlines();
    db()->exec("UPDATE founder_story_deliveries SET state='ambiguous',error_code='worker_interrupted' WHERE state='sending' AND claimed_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)");
    $expiry="((expires_at IS NOT NULL AND expires_at<=UTC_TIMESTAMP()) OR (expires_at IS NULL AND created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)))";
    $due=db()->query("SELECT w.* FROM founder_story_workflows w JOIN founder_story_reviews r ON r.id=w.current_review_id WHERE w.state='pending' AND r.state='pending' AND ((r.expires_at IS NOT NULL AND r.expires_at<=UTC_TIMESTAMP()) OR (r.expires_at IS NULL AND r.created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY))) LIMIT 50")->fetchAll();
    foreach($due as$w){db()->beginTransaction();try{founder_story_lock_post((int)$w['post_id']);$w=founder_story_workflow((int)$w['founder_id'],true);$q=db()->prepare("SELECT * FROM founder_story_reviews WHERE id=? AND state='pending' AND ".$expiry.' FOR UPDATE');$q->execute([$w['current_review_id']]);$expired=$q->fetch();if($w['state']==='pending'&&$expired){founder_story_discard($w,$expired['expires_at']===null?'delivery_window_expired':'review_expired');$result['discarded']++;}db()->commit();}catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}}
    for($i=0;$i<max(1,min(20,$limit));$i++){
        db()->beginTransaction();
        try{$row=db()->query("SELECT d.* FROM founder_story_deliveries d JOIN founder_story_reviews r ON r.id=d.review_id JOIN founder_story_workflows w ON w.current_review_id=r.id WHERE d.state='pending' AND r.state='pending' AND w.state='pending' ORDER BY d.id LIMIT 1 FOR UPDATE SKIP LOCKED")->fetch();
            if(!$row){db()->commit();break;}
            $ready=$testTransport||($row['channel']==='email'?email_transport_ready():founder_story_whatsapp_ready());
            db()->prepare('UPDATE founder_story_deliveries SET state=?,error_code=?,claimed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$ready?'sending':'blocked',$ready?null:'provider_not_configured',$row['id']]);db()->commit();
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
        if(!$ready){$result['blocked']++;continue;}
        $recipientLock=false;$payload=null;
        try{
            $payload=json_decode(decrypt_secret($row['payload_encrypted']),true,32,JSON_THROW_ON_ERROR);
            if(!$testTransport&&$row['channel']==='email'&&function_exists('email_recipient_lock')){
                $recipientLock=email_recipient_lock($payload['to']);
                if(!$recipientLock||email_address_suppressed($payload['to'])){
                    db()->prepare("UPDATE founder_story_deliveries SET state='blocked',error_code=? WHERE id=?")->execute([$recipientLock?'recipient_suppressed':'recipient_busy',$row['id']]);$result['blocked']++;continue;
                }
            }
            $provider=$testTransport?$testTransport($row['channel'],$payload):($row['channel']==='email'?email_deliver($payload,hash('sha256','founder-story:'.$row['id'])):founder_story_whatsapp_send($payload));
            if(!is_string($provider)||$provider==='')throw new RuntimeException('provider_acceptance_unconfirmed');
            db()->prepare("UPDATE founder_story_deliveries SET state='accepted',provider_id=?,accepted_at=UTC_TIMESTAMP(),error_code=NULL WHERE id=? AND state='sending'")->execute([mb_substr($provider,0,255),$row['id']]);$result['accepted']++;
            founder_story_reconcile_deadlines();
        }catch(Throwable $error){
            $rejected=$error instanceof PublishingRemoteException&&!$error->ambiguous&&$error->httpStatus>=400&&$error->httpStatus<500;
            db()->prepare("UPDATE founder_story_deliveries SET state=?,error_code=? WHERE id=? AND state='sending'")->execute([$rejected?'blocked':'ambiguous',$rejected?'provider_rejected':'provider_acceptance_unconfirmed',$row['id']]);$result[$rejected?'blocked':'ambiguous']++;
        }finally{if($recipientLock&&is_array($payload))email_recipient_unlock($payload['to']);}
    }return $result;
}
function founder_story_retry(int $id,int $actor,bool $reconciled=false): void {
    $w=founder_story_workflow($id);if(!$w)throw new JourneyValidationException('Story workflow not found.');db()->beginTransaction();
    try{founder_story_lock_post((int)$w['post_id']);$w=founder_story_workflow($id,true);if($w['state']!=='pending')throw new JourneyValidationException('This story is not awaiting review.');
        $q=db()->prepare("SELECT * FROM founder_story_deliveries WHERE review_id=? FOR UPDATE");$q->execute([$w['current_review_id']]);$rows=$q->fetchAll();
        foreach($rows as$row){if($row['state']==='ambiguous'&&!$reconciled)throw new JourneyValidationException('Check provider records and confirm that an uncertain notification was not accepted before retrying.');
            if(in_array($row['state'],['blocked','ambiguous'],true))db()->prepare("UPDATE founder_story_deliveries SET state='pending',error_code=NULL WHERE id=?")->execute([$row['id']]);}
        journeys_audit('founder',$id,'story_notifications_retried',$actor);db()->commit();
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
