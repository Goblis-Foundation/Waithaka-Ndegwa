<?php
declare(strict_types=1);

function newsletter_route_error(Throwable $error): string {
    if($error instanceof NewsletterValidationException||$error instanceof NewsletterConflictException){http_response_code($error instanceof NewsletterConflictException?409:422);return $error->getMessage();}
    $reference=strtoupper(bin2hex(random_bytes(5)));error_log('Newsletter request '.$reference.' failed: '.get_class($error));http_response_code(503);return 'This request could not be completed. Reference: '.$reference.'. Please retry shortly.';
}
function newsletter_editor_data(?array $campaign,array $values=[],?string $error=null): array {
    $values=$values?:($campaign??['title'=>'','subject'=>'','preheader'=>'','body_html'=>'','source_post_id'=>null]);
    $revision=$campaign&&$campaign['approved_revision_id']?newsletter_revision((int)$campaign['approved_revision_id']):null;
    $snapshot=null;$reviewError=null;
    if($campaign){try{$snapshot=$revision?json_decode($revision['snapshot_json'],true,512,JSON_THROW_ON_ERROR):newsletter_snapshot($campaign);}catch(NewsletterValidationException|NewsletterConflictException $exception){$reviewError=$exception->getMessage();}}
    $posts=db()->query('SELECT p.id,p.title FROM posts p WHERE '.published_where().' ORDER BY p.published_at DESC,p.id DESC LIMIT 100')->fetchAll();
    return ['writeTitle'=>$campaign?'Newsletter edition':'New newsletter','campaign'=>$campaign,'values'=>$values,'error'=>$error,'revision'=>$revision,'snapshot'=>$snapshot,'reviewError'=>$reviewError,'posts'=>$posts];
}
function newsletter_dispatch(string $path): bool {
    if($path==='/newsletter/one-click'){
        // RFC 8058: the URL capability authorizes this fixed POST operation. It
        // deliberately does not require session cookies, login or a CSRF field.
        header('Cache-Control: no-store, private');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: text/plain; charset=UTF-8');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');http_response_code(405);echo 'Unsubscription requires the email client’s explicit one-click POST.';return true;}
        if((int)($_SERVER['CONTENT_LENGTH']??0)>2048){http_response_code(413);echo 'Request is too large.';return true;}
        if(($_POST['List-Unsubscribe']??null)!=='One-Click'||!is_string($_GET['token']??null)){http_response_code(400);echo 'Invalid unsubscribe request.';return true;}
        try{if(newsletter_apply_one_click($_GET['token']))echo 'Unsubscribed.';else{http_response_code(410);echo 'This unsubscribe link is unavailable.';}}
        catch(Throwable $error){http_response_code(503);header('Retry-After: 60');error_log('One-click unsubscribe failed: '.get_class($error));echo 'Please retry shortly.';}
        return true;
    }
    if(!preg_match('~^/write/newsletter(?:/|$)~',$path))return false;
    require_auth();journeys_private_headers();journeys_method(['GET','HEAD','POST']);$actor=(int)current_user()['id'];$error=null;
    if(is_post()){
        verify_csrf();
        if(!journeys_rate_limit('newsletter-writer',(string)$actor,120,60)){http_response_code(429);header('Retry-After: 60');echo 'Too many changes. Please retry in a minute.';return true;}
        if(!empty($_POST['company_website'])){http_response_code(422);echo 'Invalid form.';return true;}
    }
    if($path==='/write/newsletter'){
        journeys_method(['GET','HEAD']);
        $campaigns=db()->query('SELECT * FROM newsletter_campaigns ORDER BY updated_at DESC,id DESC LIMIT 100')->fetchAll();
        $counts=db()->query("SELECT status,COUNT(*) count FROM newsletter_subscribers GROUP BY status")->fetchAll();$subscriberCounts=[];foreach($counts as$row)$subscriberCounts[$row['status']]=(int)$row['count'];
        render_write('newsletter',['writeTitle'=>'Newsletter','campaigns'=>$campaigns,'subscriberCounts'=>$subscriberCounts]);return true;
    }
    if($path==='/write/newsletter/subscribers'){
        journeys_method(['GET','HEAD']);$search=is_string($_GET['q']??null)?trim(mb_substr($_GET['q'],0,120)):'';$filter=is_string($_GET['status']??null)?$_GET['status']:'';
        if(!in_array($filter,['','confirmed','pending','unsubscribed','suppressed'],true))$filter='';$page=max(1,min(100000,(int)($_GET['page']??1)));$where=[];$params=[];
        if($search!==''){$where[]='(email LIKE ? OR name LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
        if($filter==='suppressed')$where[]='suppressed_at IS NOT NULL';elseif($filter!==''){$where[]='status=?';$params[]=$filter;}
        $clause=$where?' WHERE '.implode(' AND ',$where):'';$q=db()->prepare('SELECT COUNT(*) FROM newsletter_subscribers'.$clause);$q->execute($params);$total=(int)$q->fetchColumn();
        $q=db()->prepare('SELECT * FROM newsletter_subscribers'.$clause.' ORDER BY id DESC LIMIT 50 OFFSET '.(($page-1)*50));$q->execute($params);
        render_write('newsletter_subscribers',['writeTitle'=>'Newsletter subscribers','subscribers'=>$q->fetchAll(),'search'=>$search,'filter'=>$filter,'pageNumber'=>$page,'total'=>$total,'error'=>null]);return true;
    }
    if(preg_match('~^/write/newsletter/subscribers/([1-9][0-9]*)$~',$path,$match)){
        journeys_method(['GET','HEAD','POST']);$id=(int)$match[1];$subscriber=newsletter_subscriber($id);if(!$subscriber){not_found();return true;}
        if(is_post())try{newsletter_manage_subscriber($id,newsletter_text($_POST['action']??'','Action',30,true),newsletter_text($_POST['note']??'','Reason',500),$actor,(int)newsletter_id($_POST['consent_revision']??null));flash('success','Subscription preference updated. No message was sent.');redirect(url($path));}catch(Throwable $exception){$error=newsletter_route_error($exception);}
        $q=db()->prepare('SELECT * FROM newsletter_events WHERE subscriber_id=? ORDER BY id DESC LIMIT 50');$q->execute([$id]);render_write('newsletter_subscriber',['writeTitle'=>'Subscription record','subscriber'=>$subscriber,'events'=>$q->fetchAll(),'error'=>$error]);return true;
    }
    if($path==='/write/newsletter/new'){
        $values=[];
        if(is_post())try{
            if(($_POST['action']??'')==='import')$values=newsletter_article_values((int)newsletter_id($_POST['post_id']??null));
            else{$values=$_POST;$values['body_html']=$_POST['body_html']??($_POST['content_html']??'');}
            $id=newsletter_save_campaign($values,$actor);flash('success','Private newsletter draft saved. Review it before approval.');redirect(url('/write/newsletter/'.$id));
        }catch(Throwable $exception){$error=newsletter_route_error($exception);}
        render_write('newsletter_editor',newsletter_editor_data(null,$values,$error));return true;
    }
    if(preg_match('~^/write/newsletter/([1-9][0-9]*)(?:/(preview|report|approve|schedule|pause|resume|cancel|duplicate))?$~',$path,$match)){
        $id=(int)$match[1];$action=$match[2]??'';$campaign=newsletter_campaign($id);if(!$campaign){not_found();return true;}
        if($action==='report'){
            journeys_method(['GET','HEAD']);$page=max(1,min(100000,(int)($_GET['page']??1)));$q=db()->prepare('SELECT d.*,s.email,s.name FROM newsletter_deliveries d LEFT JOIN newsletter_subscribers s ON s.id=d.subscriber_id WHERE d.campaign_id=? ORDER BY d.id LIMIT 50 OFFSET '.(($page-1)*50));$q->execute([$id]);$deliveries=$q->fetchAll();
            $q=db()->prepare('SELECT * FROM newsletter_events WHERE campaign_id=? ORDER BY id DESC LIMIT 100');$q->execute([$id]);render_write('newsletter_report',['writeTitle'=>'Newsletter report','campaign'=>$campaign,'counts'=>newsletter_report($id),'deliveries'=>$deliveries,'events'=>$q->fetchAll(),'pageNumber'=>$page]);return true;
        }
        if($action==='preview'){
            journeys_method(['GET','HEAD']);$data=newsletter_editor_data($campaign);render_write('newsletter_preview',$data);return true;
        }
        if($action!==''&&!is_post()){header('Allow: POST');http_response_code(405);echo 'Use the edition form to change its state.';return true;}
        $values=[];
        if(is_post())try{
            if($action===''){
                $values=($_POST['action']??'')==='import'?newsletter_article_values((int)newsletter_id($_POST['post_id']??null)):$_POST;
                if(($_POST['action']??'')!=='import')$values['body_html']=$_POST['body_html']??($_POST['content_html']??'');
                newsletter_save_campaign($values,$actor,$id,(int)newsletter_id($_POST['draft_version']??null));flash('success','Draft saved. Any previous approval was cleared.');
            }elseif($action==='approve'){
                if(($_POST['approve_content']??'')!=='1'||($_POST['permission_ack']??'')!=='1')throw new NewsletterValidationException('Confirm the saved content, sender and permissions before approving.');
                newsletter_approve($id,(int)newsletter_id($_POST['draft_version']??null),newsletter_text($_POST['review_hash']??'','Review hash',64,true),$actor);flash('success','The saved newsletter revision is approved. It has not been sent.');
            }elseif($action==='schedule'){
                if(($_POST['audience_ack']??'')!=='1')throw new NewsletterValidationException('Confirm that this edition should go to the current eligible, confirmed subscriber list.');
                $count=newsletter_schedule($id,(int)newsletter_id($_POST['revision_id']??null),newsletter_text($_POST['scheduled_at']??'','Release time',16,true),(int)newsletter_id($_POST['state_version']??null),$actor);flash('success','Scheduled for a snapshot of '.$count.' confirmed subscribers. Delivery remains subject to current eligibility and configured mail transport.');
            }elseif($action==='duplicate'){
                $copy=['title'=>mb_substr('Copy of '.$campaign['title'],0,180),'subject'=>$campaign['subject'],'preheader'=>$campaign['preheader'],'body_html'=>$campaign['body_html'],'source_post_id'=>$campaign['source_post_id']];
                $newId=newsletter_save_campaign($copy,$actor);flash('success','Created a new private draft. It has no approval, schedule or recipients.');redirect(url('/write/newsletter/'.$newId));
            }else{if($action==='cancel'&&($_POST['confirm_cancel']??'')!=='1')throw new NewsletterValidationException('Confirm cancellation of the remaining deliveries.');newsletter_transition($id,$action,(int)newsletter_id($_POST['state_version']??null),$actor,newsletter_text($_POST['scheduled_at']??'','Release time',16));flash('success','Edition state updated. Review its delivery report for accepted or uncertain messages.');}
            redirect(url('/write/newsletter/'.$id));
        }catch(Throwable $exception){$error=newsletter_route_error($exception);}
        render_write('newsletter_editor',newsletter_editor_data(newsletter_campaign($id),$values,$error));return true;
    }
    not_found();return true;
}
