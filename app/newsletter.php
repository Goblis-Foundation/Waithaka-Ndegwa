<?php
declare(strict_types=1);

/** Approved newsletter editions and their immutable, consent-bound recipient snapshots. */
final class NewsletterValidationException extends InvalidArgumentException {}
final class NewsletterConflictException extends RuntimeException {}

require_once __DIR__.'/newsletter_sender_config.php';

function newsletter_json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function newsletter_text(mixed $value, string $label, int $max, bool $required = false): string {
    if (!is_scalar($value) && $value !== null) throw new NewsletterValidationException($label.' is invalid.');
    $value = trim((string)$value);
    if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new NewsletterValidationException($label.' contains invalid characters.');
    if (($required && $value === '') || mb_strlen($value) > $max) throw new NewsletterValidationException($label.' must contain '.($required ? '1–' : 'at most ').$max.' characters.');
    return $value;
}
function newsletter_id(mixed $value, bool $optional = false): ?int {
    if ($optional && ($value === null || $value === '' || $value === '0' || $value === 0)) return null;
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (int)$value > 2147483647) throw new NewsletterValidationException('Choose a valid record or version.');
    return (int)$value;
}
function newsletter_email_hash(string $email): string { return hash('sha256', strtolower(trim($email))); }
function newsletter_campaign(int $id): ?array { $q=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=?');$q->execute([$id]);return $q->fetch()?:null; }
function newsletter_revision(int $id): ?array { $q=db()->prepare('SELECT * FROM newsletter_revisions WHERE id=?');$q->execute([$id]);return $q->fetch()?:null; }
function newsletter_subscriber(int $id): ?array { $q=db()->prepare('SELECT * FROM newsletter_subscribers WHERE id=?');$q->execute([$id]);return $q->fetch()?:null; }
function newsletter_event(?int $campaign, ?int $subscriber, ?int $delivery, string $type, string $note, ?int $actor = null): void {
    db()->prepare('INSERT INTO newsletter_events(campaign_id,subscriber_id,delivery_id,event_type,note,actor_id,created_at) VALUES(?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$campaign,$subscriber,$delivery,$type,mb_substr($note,0,500),$actor]);
}
function newsletter_lock_name(string $kind, int $id): string { return 'wk-nl-'.substr(hash('sha256',(string)app_config('db.name','newsletter').'|'.$kind.'|'.$id),0,48); }
function newsletter_lock(string $name, int $timeout = 30): bool { $q=db()->prepare('SELECT GET_LOCK(?,?)');$q->execute([$name,max(0,min(30,$timeout))]);return (int)$q->fetchColumn()===1; }
function newsletter_unlock(string $name): void { db()->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]); }
function newsletter_subscriber_lock(int $id, int $timeout = 30): void { if(!newsletter_lock(newsletter_lock_name('subscriber',$id),$timeout))throw new NewsletterConflictException('A delivery is in progress. Please retry shortly.'); }
function newsletter_subscriber_unlock(int $id): void { newsletter_unlock(newsletter_lock_name('subscriber',$id)); }

/** Authored links never carry private preview, OAuth or signed-storage capabilities. */
function newsletter_public_url(string $value, bool $image = false): bool {
    if (strlen($value)>2000 || preg_match('/[\x00-\x20\x7F]/',$value)) return false;
    if (!$image && str_starts_with($value,'mailto:')) return filter_var(substr($value,7),FILTER_VALIDATE_EMAIL)!==false;
    $parts=parse_url($value);if(!$parts||isset($parts['user'])||isset($parts['pass']))return false;
    $origin=parse_url(base_url());
    $localTest=app_config('app_env')==='testing' && ($parts['scheme']??'')==='http' && ($parts['host']??'')===($origin['host']??'') && in_array($parts['host']??'',['127.0.0.1','::1'],true) && ($parts['port']??80)===($origin['port']??80);
    if (!$localTest && (!filter_var($value,FILTER_VALIDATE_URL)||($parts['scheme']??'')!=='https'||(isset($parts['port'])&&(int)$parts['port']!==443))) return false;
    $host=strtolower($parts['host']??'');
    if (!$localTest && ($host===''||!str_contains($host,'.')||preg_match('/(^|\.)(localhost|local|internal|test|invalid)$/',$host)||(filter_var(trim($host,'[]'),FILTER_VALIDATE_IP)&&!filter_var(trim($host,'[]'),FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)))) return false;
    $path=$parts['path']??'/';for($i=0;$i<4;$i++)$path=rawurldecode($path);
    if(preg_match('~/(write|consent|install|payments|app|storage)(/|$)|/newsletter/(confirm|unsubscribe|one-click|preferences)(/|$)|/founders/follow-up(/|$)~i',$path))return false;
    foreach(preg_split('/[&;]/',(string)($parts['query']??''))as$parameter){$key=explode('=',$parameter,2)[0];for($i=0;$i<4;$i++)$key=rawurldecode($key);$key=strtolower(preg_replace('/\[.*$/','',$key));if(preg_match('/^(preview|token|access_token|id_token|refresh_token|auth|authorization|api_?key|code|state|signature|sig|policy|key-pair-id|awsaccesskeyid|x-amz-.+|x-goog-.+)$/',$key))return false;}
    return true;
}
function newsletter_body(string $html): string {
    if(strlen($html)>180000)throw new NewsletterValidationException('Keep the email body below 180 KB.');
    $html=sanitize_html($html);if($html==='')return '';
    $document=new DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    try{$document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    foreach(['a'=>'href','img'=>'src']as$tag=>$attribute)foreach($document->getElementsByTagName($tag)as$node){
        if(!$node->hasAttribute($attribute))continue;$link=$node->getAttribute($attribute);
        if(str_starts_with($link,'/')&&!str_starts_with($link,'//'))$link=base_url().$link;
        if(!newsletter_public_url($link,$tag==='img'))throw new NewsletterValidationException('Email links and images must use public HTTPS URLs without private access tokens.');
        $node->setAttribute($attribute,$link);if($tag==='img'){$node->removeAttribute('loading');$node->setAttribute('style','max-width:100%;height:auto');}
    }
    $body=$document->getElementsByTagName('body')->item(0);$clean='';foreach($body?->childNodes??[]as$node)$clean.=$document->saveHTML($node);
    return trim($clean);
}
function newsletter_values(array $input): array {
    $title=newsletter_text($input['title']??'','Edition title',180,true);
    $subject=newsletter_text($input['subject']??'','Subject',200);$preheader=newsletter_text($input['preheader']??'','Preview text',240);
    if(preg_match('/[\r\n]/',$subject.$preheader))throw new NewsletterValidationException('Subject and preview text must be single lines.');
    $body=newsletter_body(newsletter_text($input['body_html']??'','Email body',180000));
    return ['title'=>$title,'subject'=>$subject,'preheader'=>$preheader,'body_html'=>$body,'source_post_id'=>newsletter_id($input['source_post_id']??null,true)];
}
function newsletter_public_article(int $id): ?array { $q=db()->prepare('SELECT p.id,p.title,p.slug,p.standfirst,p.content_html FROM posts p WHERE p.id=? AND '.published_where());$q->execute([$id]);return $q->fetch()?:null; }
function newsletter_article_values(int $postId): array {
    $post=newsletter_public_article($postId);if(!$post)throw new NewsletterValidationException('Choose an article that is already public. Private drafts and future posts cannot be imported.');
    $link=url('/my-thoughts/'.$post['slug']);
    return newsletter_values(['title'=>mb_substr($post['title'],0,180),'subject'=>mb_substr($post['title'],0,200),'preheader'=>mb_substr(plain_text((string)$post['standfirst']),0,240),
        'body_html'=>'<h2>'.e($post['title']).'</h2>'.($post['standfirst']?'<p>'.e($post['standfirst']).'</p>':'').$post['content_html'].'<p><a href="'.e($link).'">Read this article on the website</a></p>','source_post_id'=>$postId]);
}
/** Writer campaign hand-off: prefill only; private sources never produce a public link. */
function newsletter_source_prefill(int $postId): array {
    $query=db()->prepare('SELECT id,title,slug,standfirst,status,published_at FROM posts WHERE id=?');$query->execute([$postId]);$post=$query->fetch();
    if(!$post)throw new NewsletterValidationException('Source article not found.');
    $public=journeys_post_is_public($post);$body='';
    if($public){
        $path='/my-thoughts/'.$post['slug'];$link=function_exists('analytics_campaign_url')?analytics_campaign_url($path,'newsletter','email','post-'.$postId,'newsletter'):url($path);
        $body=($post['standfirst']!==''?'<p>'.e(plain_text((string)$post['standfirst'])).'</p>':'').'<p><a href="'.e($link).'">Read the full article</a></p>';
    }
    return ['title'=>mb_substr($post['title'],0,180),'subject'=>mb_substr($post['title'],0,200),'preheader'=>$public?mb_substr(plain_text((string)$post['standfirst']),0,240):'','body_html'=>$body,'source_post_id'=>$postId];
}
function newsletter_save_campaign(array $input, int $actor, ?int $id = null, ?int $expectedVersion = null): int {
    $values=newsletter_values($input);if($values['source_post_id']&&!newsletter_public_article($values['source_post_id']))throw new NewsletterValidationException('The linked article is no longer public. Remove the link before saving a standalone edition.');
    db()->beginTransaction();try{
        if($id){$q=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=? FOR UPDATE');$q->execute([$id]);$campaign=$q->fetch();
            if(!$campaign)throw new NewsletterValidationException('Edition not found.');
            if(!in_array($campaign['status'],['draft','approved'],true))throw new NewsletterConflictException('Scheduled editions are locked. Create a new edition to revise the content.');
            if((int)$campaign['draft_version']!==$expectedVersion)throw new NewsletterConflictException('This draft changed in another window. Reload before saving.');
            db()->prepare("UPDATE newsletter_campaigns SET title=?,subject=?,preheader=?,body_html=?,source_post_id=?,draft_version=draft_version+1,state_version=state_version+1,approved_revision_id=NULL,status='draft',updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([...array_values($values),$actor,$id]);
        }else{db()->prepare('INSERT INTO newsletter_campaigns(title,subject,preheader,body_html,source_post_id,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([...array_values($values),$actor,$actor]);$id=(int)db()->lastInsertId();}
        newsletter_event($id,null,null,'draft_saved','Saved a private newsletter draft.',$actor);db()->commit();return $id;
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
function newsletter_sender(): array {
    return ['from'=>trim((string)app_config('mail.from','')),'name'=>trim((string)app_config('mail.from_name','Waithaka Ndegwa')),'reply_to'=>trim((string)app_config('mail.reply_to','')),'postal_address'=>newsletter_postal_configuration()['postal_address']];
}
function newsletter_snapshot(array $campaign): array {
    $values=newsletter_values($campaign);$sender=newsletter_sender();
    if($values['subject']===''||plain_text($values['body_html'])==='')throw new NewsletterValidationException('Add a subject and email text before approval.');
    if(!filter_var($sender['from'],FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$sender['from'].$sender['name'].$sender['reply_to'])||($sender['reply_to']!==''&&!filter_var($sender['reply_to'],FILTER_VALIDATE_EMAIL)))throw new NewsletterValidationException('Configure a valid sender address, sender name and optional reply-to address before approval.');
    if($sender['name']===''||mb_strlen($sender['name'])>120||$sender['postal_address']===''||mb_strlen($sender['postal_address'])>500)throw new NewsletterValidationException('Configure the sender name and mailing address before approval.');
    $sourceUrl='';if($values['source_post_id']){$post=newsletter_public_article($values['source_post_id']);if(!$post)throw new NewsletterValidationException('The linked article must still be public.');$sourceUrl=url('/my-thoughts/'.$post['slug']);}
    $site=base_url();if(!newsletter_public_url($site.'/'))throw new NewsletterValidationException('Configure the canonical HTTPS website address before approval.');
    return $values+['draft_version'=>(int)$campaign['draft_version'],'source_url'=>$sourceUrl,'sender'=>$sender,'site_url'=>$site]+(function_exists('reader_newsletter_audience')?['reader_audience'=>reader_newsletter_audience($values['source_post_id'])]:[]);
}
function newsletter_approve(int $id,int $expectedVersion,string $reviewHash,int $actor): int {
    if(!preg_match('/^[a-f0-9]{64}$/D',$reviewHash))throw new NewsletterValidationException('Open the saved preview before approving.');
    db()->beginTransaction();try{
        $q=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=? FOR UPDATE');$q->execute([$id]);$campaign=$q->fetch();
        if(!$campaign||!in_array($campaign['status'],['draft','approved'],true)||(int)$campaign['draft_version']!==$expectedVersion)throw new NewsletterConflictException('The edition changed. Reload its saved preview before approval.');
        $json=newsletter_json(newsletter_snapshot($campaign));$hash=hash('sha256',$json);
        if(!hash_equals($hash,$reviewHash))throw new NewsletterConflictException('The saved content or sender settings changed after this preview. Review again.');
        $q=db()->prepare('SELECT id,content_hash FROM newsletter_revisions WHERE campaign_id=? AND draft_version=?');$q->execute([$id,$expectedVersion]);$existing=$q->fetch();
        if($existing){if(!hash_equals($existing['content_hash'],$hash))throw new NewsletterConflictException('Save a new draft version before approving changed sender settings.');$revision=(int)$existing['id'];}
        else{db()->prepare('INSERT INTO newsletter_revisions(campaign_id,draft_version,snapshot_json,content_hash,approved_by,approved_at) VALUES(?,?,?,?,?,UTC_TIMESTAMP())')->execute([$id,$expectedVersion,$json,$hash,$actor]);$revision=(int)db()->lastInsertId();newsletter_event($id,null,null,'approved','Approved the exact saved content, sender and unsubscribe footer.',$actor);}
        db()->prepare("UPDATE newsletter_campaigns SET approved_revision_id=?,status='approved',state_version=state_version+1,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$revision,$actor,$id]);db()->commit();return $revision;
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}
}
function newsletter_address_suppressed(string $email): bool {
    $email=strtolower(trim($email));if(function_exists('email_address_suppressed')&&email_address_suppressed($email))return true;
    $q=db()->prepare('SELECT suppressed_at FROM newsletter_subscribers WHERE email=?');$q->execute([$email]);return (bool)$q->fetchColumn();
}
function newsletter_recipient_eligible(array $subscriber, ?array $delivery = null): bool {
    if(($subscriber['status']??'')!=='confirmed'||empty($subscriber['confirmed_at'])||!empty($subscriber['suppressed_at'])||!filter_var($subscriber['email']??'',FILTER_VALIDATE_EMAIL))return false;
    if($delivery&&((int)$delivery['consent_revision']!==(int)$subscriber['consent_revision']||!hash_equals($delivery['email_hash'],newsletter_email_hash($subscriber['email']))))return false;
    return !function_exists('email_address_suppressed')||!email_address_suppressed($subscriber['email']);
}
function newsletter_schedule_time(string $local): string {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,new DateTimeZone((string)app_config('timezone','Africa/Nairobi')));
    if(!$date||$date->format('Y-m-d\TH:i')!==$local||$date->getTimestamp()<time()-60||$date->getTimestamp()>time()+366*86400)throw new NewsletterValidationException('Choose a valid release time within the next year.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function newsletter_schedule(int $id,int $revisionId,string $localTime,int $expectedState,int $actor): int {
    $utc=newsletter_schedule_time($localTime);$lock=newsletter_lock_name('campaign',$id);if(!newsletter_lock($lock))throw new NewsletterConflictException('This edition is busy. Retry shortly.');
    try{db()->beginTransaction();
        $q=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=? FOR UPDATE');$q->execute([$id]);$campaign=$q->fetch();
        if(!$campaign)throw new NewsletterValidationException('Edition not found.');
        if((int)$campaign['scheduled_revision_id']===$revisionId&&$campaign['scheduled_at']===$utc&&$campaign['status']!=='cancelled'){db()->commit();return (int)$campaign['audience_size'];}
        if($campaign['status']!=='approved'||(int)$campaign['approved_revision_id']!==$revisionId||(int)$campaign['state_version']!==$expectedState)throw new NewsletterConflictException('Approve the current saved edition and reload before scheduling.');
        $revision=newsletter_revision($revisionId);if(!$revision||(int)$revision['campaign_id']!==$id)throw new NewsletterValidationException('Approved revision not found.');
        $snapshot=json_decode($revision['snapshot_json'],true,512,JSON_THROW_ON_ERROR);newsletter_assert_snapshot_current($snapshot);
        $count=0;$lastId=0;
        do{$q=db()->prepare("SELECT * FROM newsletter_subscribers WHERE id>? AND status='confirmed' AND confirmed_at IS NOT NULL AND suppressed_at IS NULL ORDER BY id LIMIT 500");$q->execute([$lastId]);$subscribers=$q->fetchAll();
            foreach($subscribers as$subscriber){$lastId=(int)$subscriber['id'];if(!newsletter_recipient_eligible($subscriber)||(function_exists('reader_newsletter_matches')&&!reader_newsletter_matches((int)$subscriber['id'],$snapshot)))continue;
                $key=hash('sha256',$revisionId.':'.$subscriber['id'].':'.$subscriber['consent_revision'].':'.$revision['content_hash']);
                db()->prepare('INSERT INTO newsletter_deliveries(campaign_id,revision_id,subscriber_id,email_hash,consent_revision,consent_version,confirmed_at_snapshot,delivery_key,created_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())')->execute([$id,$revisionId,$subscriber['id'],newsletter_email_hash($subscriber['email']),$subscriber['consent_revision'],$subscriber['consent_version'],$subscriber['confirmed_at'],$key]);$count++;
            }
        }while(count($subscribers)===500);
        if(!$count)throw new NewsletterValidationException('There are no confirmed, eligible subscribers to receive this edition.');
        db()->prepare("UPDATE newsletter_campaigns SET status='scheduled',scheduled_revision_id=?,scheduled_at=?,audience_size=?,state_version=state_version+1,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$revisionId,$utc,$count,$actor,$id]);
        newsletter_event($id,null,null,'scheduled','Scheduled the approved revision for a frozen audience of '.$count.' confirmed subscribers.',$actor);db()->commit();return $count;
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}finally{newsletter_unlock($lock);}
}
function newsletter_assert_snapshot_current(array $snapshot): void {
    if(!hash_equals(hash('sha256',newsletter_json($snapshot['sender']??[])),hash('sha256',newsletter_json(newsletter_sender())))||($snapshot['site_url']??'')!==base_url())throw new NewsletterConflictException('Sender or website settings changed after approval. Restore the approved settings or cancel and prepare a new edition.');
    if(!empty($snapshot['source_post_id'])){
        $post=newsletter_public_article((int)$snapshot['source_post_id']);
        if(!$post)throw new NewsletterConflictException('The linked article is no longer public.');
        if(($snapshot['source_url']??'')!==url('/my-thoughts/'.$post['slug']))throw new NewsletterConflictException('The linked article address changed after approval. Prepare a new edition with its current public address.');
    }
}
function newsletter_transition(int $id,string $action,int $expectedState,int $actor,?string $localTime=null): void {
    if(!in_array($action,['pause','resume','cancel'],true))throw new NewsletterValidationException('Choose a valid edition action.');
    $lock=newsletter_lock_name('campaign',$id);if(!newsletter_lock($lock))throw new NewsletterConflictException('A delivery is in progress. Please retry shortly.');
    try{db()->beginTransaction();$q=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=? FOR UPDATE');$q->execute([$id]);$campaign=$q->fetch();
        if(!$campaign||(int)$campaign['state_version']!==$expectedState)throw new NewsletterConflictException('The edition status changed. Reload before continuing.');
        if($action==='pause'&&!in_array($campaign['status'],['scheduled','sending'],true))throw new NewsletterValidationException('Only a scheduled or sending edition can be paused.');
        if($action==='resume'&&$campaign['status']!=='paused')throw new NewsletterValidationException('Only a paused edition can be resumed.');
        if($action==='cancel'&&!in_array($campaign['status'],['draft','approved','scheduled','sending','paused'],true))throw new NewsletterValidationException('This edition has already finished or been cancelled.');
        $status=['pause'=>'paused','resume'=>'scheduled','cancel'=>'cancelled'][$action];$scheduled=$action==='resume'?newsletter_schedule_time((string)$localTime):$campaign['scheduled_at'];
        db()->prepare('UPDATE newsletter_campaigns SET status=?,scheduled_at=?,state_version=state_version+1,updated_by=?,updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$status,$scheduled,$actor,$id]);
        if($action==='cancel'){
            db()->prepare("UPDATE email_outbox e JOIN newsletter_deliveries d ON d.outbox_id=e.id SET e.status='suppressed',e.error_code='campaign_cancelled' WHERE d.campaign_id=? AND e.status='pending'")->execute([$id]);
            db()->prepare("UPDATE newsletter_deliveries SET status='cancelled',last_error='campaign_cancelled' WHERE campaign_id=? AND status IN ('pending','queued') AND (outbox_id IS NULL OR outbox_id IN (SELECT id FROM email_outbox WHERE status='suppressed'))")->execute([$id]);
        }
        newsletter_event($id,null,null,$status,$action==='pause'?'Paused future deliveries; previously accepted messages cannot be recalled.':($action==='resume'?'Resumed the same approved edition and original audience.':'Cancelled unsent deliveries; accepted or uncertain deliveries remain recorded.'),$actor);db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}finally{newsletter_unlock($lock);}
}
function newsletter_html(array $snapshot, ?string $unsubscribe = null, ?string $preferences = null, string $name = ''): string {
    $footer=$unsubscribe?'<a href="'.e($unsubscribe).'">Unsubscribe</a>':'<span>Unsubscribe link — unique to each recipient</span>';
    if(function_exists('reader_preference_url'))$footer.=$preferences?' · <a href="'.e($preferences).'">Email preferences</a>':' · <span>Email preferences — private recipient link</span>';
    return email_design($snapshot['subject'],$snapshot['body_html'],['name'=>$name,'preheader'=>$snapshot['preheader'],'site_url'=>$snapshot['site_url'],'postal_address'=>$snapshot['sender']['postal_address'],'footer'=>'<p>You receive this edition because you confirmed your subscription to Waithaka Ndegwa’s writing.</p><p>'.$footer.'</p>']);
}
function newsletter_lifecycle_html(string $kind,array $subscriber,?string $unsubscribe=null): string {
    $name=(string)(($subscriber['first_name']??'')?:($subscriber['name']??''));
    if($kind==='welcome')return email_design('Thank you for subscribing.','<p>I appreciate you making space for My Thoughts in your inbox. I look forward to sharing practical ideas, honest learning and the experience of turning challenges into lasting value.</p><p>Your subscription is now confirmed. You will receive new writing and Startup Journeys that match your preferences.</p>'.email_design_button('Explore My Thoughts',url('/my-thoughts')).'<p>Thank you for being part of the conversation.</p>',['name'=>$name,'preheader'=>'You are now subscribed to My Thoughts.','unsubscribe'=>$unsubscribe,'preferences'=>url('/newsletter/preferences/request')]);
    if($kind!=='goodbye')throw new InvalidArgumentException('Unknown subscription message.');
    return email_design('Your subscription has ended.','<p>Your request is complete. You will no longer receive newsletter editions from My Thoughts.</p><p>Thank you for the time you have shared with me. You are always welcome to read the website at your own pace.</p><p>There is nothing else you need to do.</p>',['name'=>$name,'preheader'=>'You have been unsubscribed. No further action is needed.','footer'=>'<p>This is the one-time unsubscribe receipt you requested. It does not restart your subscription. You will not receive further newsletter editions unless you subscribe and confirm again.</p>']);
}
function newsletter_queue_lifecycle(int $id,string $kind): void {
    $subscriber=newsletter_subscriber($id);if(!$subscriber)return;
    $unsubscribe=$kind==='welcome'?newsletter_unsubscribe_url($id):null;
    $html=newsletter_lifecycle_html($kind,$subscriber,$unsubscribe);
    $headers=$kind==='welcome'?['List-Unsubscribe'=>'<'.newsletter_one_click_url($id).'>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click']:[];
    enqueue_email($subscriber['email'],$kind==='welcome'?'Thank you for subscribing · Waithaka Ndegwa':'You are unsubscribed · Waithaka Ndegwa',$html,'newsletter-lifecycle:'.$kind.':'.$id.':'.$subscriber['consent_revision'],$headers);
}
function newsletter_lifecycle_identity(string $key): ?array {
    if(!preg_match('/\Anewsletter-lifecycle:(welcome|goodbye):([1-9][0-9]{0,18}):([1-9][0-9]{0,18})\z/',$key,$m))return null;
    return ['kind'=>$m[1],'id'=>(int)$m[2],'revision'=>(string)$m[3]];
}
function newsletter_lifecycle_deliverable(string $key,string $recipient): bool {
    $identity=newsletter_lifecycle_identity($key);if(!$identity)return false;
    $subscriber=newsletter_subscriber($identity['id']);
    return $subscriber && (string)$subscriber['consent_revision']===$identity['revision']
        && hash_equals(newsletter_email_hash($subscriber['email']),newsletter_email_hash($recipient))
        && $subscriber['status']===($identity['kind']==='welcome'?'confirmed':'unsubscribed')
        && empty($subscriber['suppressed_at']) && !newsletter_address_suppressed($recipient);
}
function newsletter_envelope_hash(array $payload): string {
    $headers=$payload['headers']??[];ksort($headers,SORT_STRING);
    return hash('sha256',newsletter_json(['to'=>strtolower(trim((string)($payload['to']??''))),'subject'=>(string)($payload['subject']??''),'html'=>(string)($payload['html']??''),'headers'=>$headers]));
}
function newsletter_one_click_url(int $subscriberId): string {
    if(!newsletter_subscriber($subscriberId))throw new NewsletterValidationException('Subscriber not found.');
    $token=bin2hex(random_bytes(32));db()->prepare("INSERT INTO newsletter_tokens(subscriber_id,purpose,token_hash,created_at) VALUES(?,'one_click',?,?)")->execute([$subscriberId,hash('sha256',$token),date('Y-m-d H:i:s')]);
    return url('/newsletter/one-click?token='.$token);
}
function newsletter_apply_one_click(string $token): bool {
    $hash=newsletter_token_hash($token);if(!$hash)return false;
    $q=db()->prepare("SELECT subscriber_id FROM newsletter_tokens WHERE token_hash=? AND purpose='one_click'");$q->execute([$hash]);$id=(int)$q->fetchColumn();if(!$id)return false;
    newsletter_subscriber_lock($id);try{db()->beginTransaction();$q=db()->prepare("SELECT s.* FROM newsletter_subscribers s JOIN newsletter_tokens t ON t.subscriber_id=s.id WHERE t.token_hash=? AND t.purpose='one_click' FOR UPDATE");$q->execute([$hash]);$subscriber=$q->fetch();if(!$subscriber){db()->rollBack();return false;}
        if($subscriber['status']!=='unsubscribed'){db()->prepare("UPDATE newsletter_subscribers SET status='unsubscribed',consent_revision=consent_revision+1,unsubscribed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);db()->prepare("UPDATE newsletter_tokens SET used_at=NOW() WHERE subscriber_id=? AND purpose='confirm' AND used_at IS NULL")->execute([$id]);newsletter_event(null,$id,null,'one_click_unsubscribed','Unsubscribed using the recipient-specific email header link.');}
        db()->commit();return true;
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}finally{newsletter_subscriber_unlock($id);}
}
function newsletter_manage_subscriber(int $id,string $action,string $note,int $actor,int $expectedConsent): void {
    if(!in_array($action,['unsubscribe','suppress','clear_suppression'],true))throw new NewsletterValidationException('Choose a valid subscriber action.');
    $note=newsletter_text($note,'Reason',500,$action!=='unsubscribe');newsletter_subscriber_lock($id);
    try{db()->beginTransaction();$q=db()->prepare('SELECT * FROM newsletter_subscribers WHERE id=? FOR UPDATE');$q->execute([$id]);$subscriber=$q->fetch();
        if(!$subscriber||(int)$subscriber['consent_revision']!==$expectedConsent)throw new NewsletterConflictException('This subscription changed. Reload before updating.');
        if($action==='clear_suppression'){
            if(!$subscriber['suppressed_at']||$subscriber['suppression_reason']!=='manual')throw new NewsletterValidationException('Only a manual newsletter suppression can be cleared here. Provider bounce or complaint suppression requires its separate review.');
            db()->prepare("UPDATE newsletter_subscribers SET suppressed_at=NULL,suppression_reason=NULL,suppression_note=NULL,status='unsubscribed',confirmed_at=NULL,consent_revision=consent_revision+1,unsubscribed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);
        }elseif($action==='suppress'){
            if($subscriber['suppressed_at']&&$subscriber['suppression_reason']!=='manual')throw new NewsletterValidationException('Provider suppression cannot be replaced by a manual preference. Review the original mail feedback separately.');
            db()->prepare("UPDATE newsletter_subscribers SET suppressed_at=NOW(),suppression_reason='manual',suppression_note=?,status='unsubscribed',consent_revision=consent_revision+1,unsubscribed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$note,$id]);
        }else db()->prepare("UPDATE newsletter_subscribers SET status='unsubscribed',consent_revision=consent_revision+1,unsubscribed_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$id]);
        db()->prepare("UPDATE newsletter_tokens SET used_at=NOW() WHERE subscriber_id=? AND purpose='confirm' AND used_at IS NULL")->execute([$id]);
        newsletter_event(null,$id,null,$action,$action==='clear_suppression'?'Manual suppression cleared; fresh double opt-in is still required.':($action==='suppress'?'Manual suppression: '.$note:'Unsubscribed by Writer.'),$actor);db()->commit();
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}finally{newsletter_subscriber_unlock($id);}
}

/** Queue a bounded batch. This function never calls an email provider. */
function newsletter_work(int $limit=100): array {
    newsletter_email_reconcile();$stats=['queued'=>0,'suppressed'=>0,'paused'=>0];
    $q=db()->query("SELECT id FROM newsletter_campaigns WHERE status IN ('scheduled','sending') AND scheduled_at<=UTC_TIMESTAMP() ORDER BY scheduled_at,id LIMIT 100");$campaignIds=$q->fetchAll(PDO::FETCH_COLUMN);
    foreach($campaignIds as$id){if($stats['queued']+$stats['suppressed']>=max(1,min(500,$limit)))break;$lock=newsletter_lock_name('campaign',(int)$id);if(!newsletter_lock($lock,0))continue;
        try{db()->beginTransaction();$q=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=? FOR UPDATE');$q->execute([$id]);$campaign=$q->fetch();
            if(!$campaign||!in_array($campaign['status'],['scheduled','sending'],true)||$campaign['scheduled_at']>gmdate('Y-m-d H:i:s')){db()->commit();continue;}
            $revision=newsletter_revision((int)$campaign['scheduled_revision_id']);if(!$revision)throw new RuntimeException('Scheduled newsletter revision missing.');
            $snapshot=json_decode($revision['snapshot_json'],true,512,JSON_THROW_ON_ERROR);if(!hash_equals($revision['content_hash'],hash('sha256',$revision['snapshot_json'])))throw new RuntimeException('Newsletter snapshot integrity check failed.');
            try{newsletter_assert_snapshot_current($snapshot);}catch(NewsletterConflictException $error){db()->prepare("UPDATE newsletter_campaigns SET status='paused',state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);newsletter_event((int)$id,null,null,'paused',$error->getMessage());db()->commit();$stats['paused']++;continue;}
            $remaining=max(1,min(500,$limit))-$stats['queued']-$stats['suppressed'];$q=db()->prepare("SELECT * FROM newsletter_deliveries WHERE campaign_id=? AND status='pending' ORDER BY id LIMIT ".$remaining.' FOR UPDATE');$q->execute([$id]);$deliveries=$q->fetchAll();
            foreach($deliveries as$delivery){$subscriber=$delivery['subscriber_id']?newsletter_subscriber((int)$delivery['subscriber_id']):null;
                if(!$subscriber||!newsletter_recipient_eligible($subscriber,$delivery)||(function_exists('reader_newsletter_matches')&&!reader_newsletter_matches((int)$subscriber['id'],$snapshot))){db()->prepare("UPDATE newsletter_deliveries SET status='suppressed',last_error='recipient_ineligible' WHERE id=?")->execute([$delivery['id']]);$stats['suppressed']++;continue;}
                $unsubscribe=newsletter_unsubscribe_url((int)$subscriber['id']);$oneClick=newsletter_one_click_url((int)$subscriber['id']);$headers=['List-Unsubscribe'=>'<'.$oneClick.'>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click'];
                $preferences=function_exists('reader_preference_url')?reader_preference_url((int)$subscriber['id']):null;
                $html=newsletter_html($snapshot,$unsubscribe,$preferences,(string)(($subscriber['first_name']??'')?:($subscriber['name']??'')));$payload=['to'=>$subscriber['email'],'subject'=>$snapshot['subject'],'html'=>$html,'headers'=>$headers];$outbox=enqueue_email($subscriber['email'],$snapshot['subject'],$html,'newsletter-campaign:'.$delivery['delivery_key'],$headers);
                db()->prepare("UPDATE newsletter_deliveries SET status='queued',outbox_id=?,envelope_hash=?,queued_at=UTC_TIMESTAMP() WHERE id=?")->execute([$outbox,newsletter_envelope_hash($payload),$delivery['id']]);$stats['queued']++;
            }
            db()->prepare("UPDATE newsletter_campaigns SET status='sending',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);db()->commit();
        }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();throw $error;}finally{newsletter_unlock($lock);}
    }
    newsletter_finish_campaigns();return $stats;
}
function newsletter_delivery_key(string $dedupeKey): ?string { return preg_match('/^newsletter-campaign:([a-f0-9]{64})$/D',$dedupeKey,$match)?$match[1]:null; }
function newsletter_email_unlock(array $gate): void { foreach(array_reverse($gate['locks']??[])as$lock)newsletter_unlock($lock); }
/** Hold returned campaign/subscriber locks until transport and its acknowledgement finish. */
function newsletter_email_gate(string $dedupeKey,array $payload,int $outboxId): array {
    $gate=['action'=>'suppress','reason'=>'unknown_newsletter_delivery','locks'=>[]];$key=newsletter_delivery_key($dedupeKey);if(!$key)return $gate;
    $q=db()->prepare('SELECT campaign_id,subscriber_id FROM newsletter_deliveries WHERE delivery_key=?');$q->execute([$key]);$identity=$q->fetch();if(!$identity)return $gate;
    try{
        foreach([['campaign',(int)$identity['campaign_id']],['subscriber',(int)$identity['subscriber_id']]]as[$kind,$id]){if(!$id)continue;$lock=newsletter_lock_name($kind,$id);if(!newsletter_lock($lock,0)){$gate['action']='defer';$gate['reason']='newsletter_busy';return $gate;}$gate['locks'][]=$lock;}
        $q=db()->prepare('SELECT d.*,c.status campaign_status,c.scheduled_at,c.scheduled_revision_id,r.snapshot_json,r.content_hash FROM newsletter_deliveries d JOIN newsletter_campaigns c ON c.id=d.campaign_id JOIN newsletter_revisions r ON r.id=d.revision_id WHERE d.delivery_key=?');$q->execute([$key]);$delivery=$q->fetch();
        if(!$delivery||(int)$delivery['outbox_id']!==$outboxId)return $gate;
        if($delivery['status']!=='queued'){$gate['reason']='newsletter_delivery_obsolete';return $gate;}
        if($delivery['campaign_status']==='paused'||$delivery['scheduled_at']>gmdate('Y-m-d H:i:s')){$gate['action']='defer';$gate['reason']='newsletter_paused_or_not_due';return $gate;}
        if(!in_array($delivery['campaign_status'],['scheduled','sending'],true)||(int)$delivery['scheduled_revision_id']!==(int)$delivery['revision_id']){$gate['reason']='campaign_cancelled';db()->prepare("UPDATE newsletter_deliveries SET status='cancelled',last_error=? WHERE id=?")->execute([$gate['reason'],$delivery['id']]);return $gate;}
        $subscriber=$delivery['subscriber_id']?newsletter_subscriber((int)$delivery['subscriber_id']):null;
        if(!$subscriber||!newsletter_recipient_eligible($subscriber,$delivery)){$gate['reason']='recipient_ineligible';db()->prepare("UPDATE newsletter_deliveries SET status='suppressed',last_error=? WHERE id=?")->execute([$gate['reason'],$delivery['id']]);return $gate;}
        if(!hash_equals((string)$delivery['envelope_hash'],newsletter_envelope_hash($payload))||!hash_equals($delivery['email_hash'],newsletter_email_hash((string)($payload['to']??'')))){$gate['reason']='newsletter_envelope_mismatch';db()->prepare("UPDATE newsletter_deliveries SET status='failed',last_error=? WHERE id=?")->execute([$gate['reason'],$delivery['id']]);return $gate;}
        if(!hash_equals($delivery['content_hash'],hash('sha256',$delivery['snapshot_json'])))throw new RuntimeException('Newsletter snapshot integrity check failed.');
        if(function_exists('reader_newsletter_matches')&&!reader_newsletter_matches((int)$subscriber['id'],json_decode($delivery['snapshot_json'],true,512,JSON_THROW_ON_ERROR))){$gate['reason']='reader_preferences_changed';db()->prepare("UPDATE newsletter_deliveries SET status='suppressed',last_error=? WHERE id=?")->execute([$gate['reason'],$delivery['id']]);return $gate;}
        try{newsletter_assert_snapshot_current(json_decode($delivery['snapshot_json'],true,512,JSON_THROW_ON_ERROR));}catch(NewsletterConflictException $error){db()->prepare("UPDATE newsletter_campaigns SET status='paused',state_version=state_version+1,updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$delivery['campaign_id']]);newsletter_event((int)$delivery['campaign_id'],null,null,'paused',$error->getMessage());$gate['action']='defer';$gate['reason']='newsletter_approval_changed';return $gate;}
        $gate['action']='send';$gate['reason']='';return $gate;
    }catch(Throwable $error){newsletter_email_unlock($gate);throw $error;}
}
function newsletter_email_acknowledged(string $dedupeKey,int $outboxId,string $providerId): void {
    $key=newsletter_delivery_key($dedupeKey);if(!$key)return;
    db()->prepare("UPDATE newsletter_deliveries SET status=CASE WHEN status IN ('delivered','bounced','complained') THEN status ELSE 'accepted' END,provider_id=?,accepted_at=COALESCE(accepted_at,UTC_TIMESTAMP()),last_error=NULL WHERE delivery_key=? AND outbox_id=?")->execute([mb_substr($providerId,0,255),$key,$outboxId]);
}
function newsletter_delivery_feedback(int $outboxId,string $event,string $providerId): void {
    if(!in_array($event,['delivered','bounced','complained'],true))throw new InvalidArgumentException('Unknown newsletter mail feedback.');
    $q=db()->prepare('SELECT * FROM newsletter_deliveries WHERE outbox_id=? FOR UPDATE');$q->execute([$outboxId]);$delivery=$q->fetch();if(!$delivery)return;
    $rank=['delivered'=>1,'bounced'=>2,'complained'=>3];if(($rank[$delivery['status']]??0)>=($rank[$event]??0))return;
    db()->prepare('UPDATE newsletter_deliveries SET status=?,provider_id=?,feedback_at=UTC_TIMESTAMP(),delivered_at=CASE WHEN ?=\'delivered\' THEN COALESCE(delivered_at,UTC_TIMESTAMP()) ELSE delivered_at END WHERE id=?')->execute([$event,mb_substr($providerId,0,255),$event,$delivery['id']]);
    newsletter_event((int)$delivery['campaign_id'],$delivery['subscriber_id']?(int)$delivery['subscriber_id']:null,(int)$delivery['id'],'provider_'.$event,'Verified email provider feedback: '.$event.'.');
}
function newsletter_email_reconcile(): void {
    db()->exec("UPDATE newsletter_deliveries d JOIN email_outbox e ON e.id=d.outbox_id SET d.status='accepted',d.provider_id=e.provider_id,d.accepted_at=COALESCE(d.accepted_at,CONVERT_TZ(e.sent_at,'+03:00','+00:00'),UTC_TIMESTAMP()),d.last_error=NULL WHERE e.status='sent' AND d.status NOT IN ('accepted','delivered','bounced','complained')");
    db()->exec("UPDATE newsletter_deliveries d JOIN email_outbox e ON e.id=d.outbox_id SET d.status='ambiguous',d.last_error='delivery_unconfirmed' WHERE e.status='ambiguous' AND d.status='queued'");
    db()->exec("UPDATE newsletter_deliveries d JOIN email_outbox e ON e.id=d.outbox_id SET d.status=CASE WHEN e.error_code='campaign_cancelled' THEN 'cancelled' ELSE 'suppressed' END,d.last_error=e.error_code WHERE e.status='suppressed' AND d.status='queued'");
    db()->exec("UPDATE newsletter_deliveries d JOIN email_outbox e ON e.id=d.outbox_id SET d.status='failed',d.last_error=e.error_code WHERE e.status='failed' AND d.status='queued'");
    newsletter_finish_campaigns();
}
function newsletter_finish_campaigns(): void {
    db()->exec("UPDATE newsletter_campaigns c SET c.status='completed',c.completed_at=COALESCE(c.completed_at,UTC_TIMESTAMP()),c.state_version=c.state_version+1,c.updated_at=UTC_TIMESTAMP() WHERE c.status IN ('scheduled','sending') AND c.scheduled_revision_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM newsletter_deliveries d WHERE d.campaign_id=c.id AND d.status IN ('pending','queued'))");
}
function newsletter_status_label(string $status): string {
    return ['draft'=>'Private draft','approved'=>'Approved','scheduled'=>'Scheduled','sending'=>'In progress','paused'=>'Paused','completed'=>'Processing complete','cancelled'=>'Cancelled','pending'=>'In audience snapshot','queued'=>'Queued for delivery','accepted'=>'Accepted by mail provider','delivered'=>'Delivery confirmed','bounced'=>'Bounced','complained'=>'Complaint received','suppressed'=>'Suppressed','ambiguous'=>'Outcome unknown','failed'=>'Needs attention'][$status]??ucfirst($status);
}
function newsletter_local_time(?string $utc): string { return $utc?(new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)app_config('timezone','Africa/Nairobi')))->format('j M Y, H:i T'):'—'; }
function newsletter_report(int $campaignId): array {
    $q=db()->prepare('SELECT status,COUNT(*) count FROM newsletter_deliveries WHERE campaign_id=? GROUP BY status');$q->execute([$campaignId]);$counts=[];foreach($q->fetchAll()as$row)$counts[$row['status']]=(int)$row['count'];
    return $counts;
}
