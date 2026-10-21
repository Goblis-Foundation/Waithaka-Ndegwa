<?php
declare(strict_types=1);
require_once __DIR__.'/journeys.php';

function journeys_route_error(Throwable $error): string {
    if($error instanceof JourneyValidationException || $error instanceof JourneyConflictException){http_response_code($error instanceof JourneyConflictException?409:422);return $error->getMessage();}
    $reference=strtoupper(bin2hex(random_bytes(5)));error_log('Journeys request '.$reference.' failed: '.get_class($error));http_response_code(503);
    return 'This request could not be completed. Please try again later. Reference: '.$reference;
}
function journeys_not_found(): never { not_found(); exit; }
function journeys_public_render(string $view,array $data,string $title,string $description,string $canonical,string $page='journeys'): never {
    $seo=['title'=>$title.' · Waithaka Ndegwa','description'=>$description,'canonical'=>url($canonical)];
    if($page==='newsletter'||$canonical==='/founders/submit'){
        $seo['robots']='noindex,nofollow';$data['suppressAnalytics']=true;$data['privatePage']=true;
    }
    render($view,array_merge($data,['page'=>$page,'seo'=>$seo]));exit;
}

/** Call after bootstrap, installation check and the shared not_found() definition. */
function journeys_dispatch(string $path): bool {
    if($path==='/building-in-public'||str_starts_with($path,'/building-in-public/')){
        journeys_method(['GET','HEAD']);redirect(url('/startup-journeys'.substr($path,19)),301);
    }
    if($path==='/startup-journeys'){
        journeys_method(['GET','HEAD']);journeys_public_render('journeys/index',['seasons'=>journeys_public_seasons()],'Startup Journeys','Follow the starting points, decisions, experiments and lessons of ventures as they develop.','/startup-journeys');
    }
    if(preg_match('~^/startup-journeys/([a-z0-9]+(?:-[a-z0-9]+)*)$~',$path,$m)){
        journeys_method(['GET','HEAD']);$season=journeys_public_season($m[1]);if(!$season)journeys_not_found();
        journeys_public_render('journeys/detail',['season'=>$season,'episodes'=>journeys_public_episodes((int)$season['id'])],$season['title'],$season['summary'],'/startup-journeys/'.$season['slug']);
    }
    if($path==='/founders'){
        journeys_method(['GET','HEAD']);journeys_public_render('founders/index',['stories'=>founders_public_stories()],'Founder Stories','Accounts of building a venture, selected and edited with the founder’s permission.','/founders','founders');
    }
    if($path==='/founders/submit'){
        journeys_method(['GET','POST']);journeys_private_headers();$error=null;$values=[];
        if(is_post()){
            journeys_verify_csrf();$values=$_POST;
            if(!empty($_POST['company_website'])){flash('success','Thank you. Your submission has been received for review.');redirect(url('/founders/submit'));}
            try{
                $v=founders_submission_values($_POST);
                if(!journeys_rate_limit('founder-ip',ip_hash(),5)||!journeys_rate_limit('founder-email',$v['submitter_email'],3,86400)){http_response_code(429);header('Retry-After: 3600');$error='Too many submissions were received from this connection. Please try again later.';}
                else{$reference=founders_submit($v);flash('success','Your private submission is recorded. Reference: '.$reference.'. It will be reviewed before any story is published.');redirect(url('/founders/submit'));}
            }catch(Throwable $e){$error=journeys_route_error($e);}
        }
        journeys_public_render('founders/submit',['values'=>$values,'error'=>$error],'Share a Founder Story','Share your experience as a founder or nominate a founder for editorial consideration.','/founders/submit','founders');
    }
    if($path==='/newsletter'){
        journeys_method(['GET','POST']);journeys_private_headers();$error=null;$values=[];
        if(is_post()){
            journeys_verify_csrf();$values=$_POST;
            if(!empty($_POST['company_website'])){flash('success','Your request has been recorded. A subscription becomes active only after email confirmation.');redirect(url('/newsletter'));}
            try{
                if(!journeys_checked($_POST['subscribe_ack']??null))throw new JourneyValidationException('Please confirm that you want to receive these emails.');
                $email=journeys_email($_POST['email']??'');$name=journeys_text($_POST['name']??'','Name',120);
                if(!journeys_rate_limit('newsletter-ip',ip_hash(),8)||!journeys_rate_limit('newsletter-email',$email,3)){http_response_code(429);header('Retry-After: 3600');$error='Too many requests were received. Please try again later.';}
                else{newsletter_request_signup($email,$name);flash('success','Your request has been recorded. A subscription becomes active only after email confirmation.');redirect(url('/newsletter'));}
            }catch(Throwable $e){$error=journeys_route_error($e);}
        }
        journeys_public_render('journeys/newsletter',['values'=>$values,'error'=>$error],'Follow My Thoughts','Receive new writing and Startup Journeys by email, or subscribe to the RSS feed.','/newsletter','newsletter');
    }
    if($path==='/newsletter/confirm'||$path==='/newsletter/unsubscribe'){
        journeys_method(['GET','POST']);journeys_private_headers();$purpose=$path==='/newsletter/confirm'?'confirm':'unsubscribe';
        $raw=is_post()?($_POST['token']??''):($_GET['token']??'');$token=is_string($raw)?$raw:'';$record=newsletter_token_record($token,$purpose);$error=null;
        if(!$record){http_response_code(410);$error=$purpose==='confirm'?'This confirmation link has expired or was already used. You can request a new link.':'This unsubscribe link is not available. You can contact me for help.';}
        elseif(is_post()){
            journeys_verify_csrf();
            try{if(newsletter_apply_token($token,$purpose)){flash('success',$purpose==='confirm'?'Your subscription is confirmed. You can unsubscribe at any time.':'You have been unsubscribed. You will not receive future newsletter editions unless you subscribe and confirm again.');redirect(url('/newsletter'));}
                http_response_code(410);$error='This link is no longer valid. Please request a new confirmation link or contact me for help.';
            }catch(Throwable $e){$error=journeys_route_error($e);}
        }
        journeys_public_render('journeys/newsletter_action',['purpose'=>$purpose,'token'=>$token,'record'=>$record,'error'=>$error],$purpose==='confirm'?'Confirm Subscription':'Unsubscribe','Manage your subscription to Waithaka Ndegwa’s writing.',$path,'newsletter');
    }
    if($path==='/feed.xml'){
        journeys_method(['GET','HEAD']);$s=db()->prepare("SELECT id,title,slug,standfirst,content_html,status,published_at FROM posts WHERE status IN ('published','scheduled') AND published_at IS NOT NULL AND published_at<=? ORDER BY published_at DESC,id DESC LIMIT 50");$s->execute([journeys_now()]);
        header('Content-Type: application/rss+xml; charset=utf-8');header('X-Content-Type-Options: nosniff');
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo journeys_rss_xml($s->fetchAll());exit;
    }

    if($path==='/write/journeys'){
        journeys_method(['GET','HEAD']);require_auth();journeys_private_headers();
        $seasons=db()->query('SELECT s.*, (SELECT COUNT(*) FROM journey_episodes e WHERE e.season_id=s.id) episode_count FROM journey_seasons s ORDER BY s.updated_at DESC,s.id DESC')->fetchAll();
        $reviewCount=(int)db()->query("SELECT COUNT(*) FROM founder_submissions WHERE review_status IN ('new','reviewing','needs_info')")->fetchColumn();
        $newsletterCount=(int)db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed'")->fetchColumn();
        render_write('journeys',['writeTitle'=>'Startup Journeys','seasons'=>$seasons,'reviewCount'=>$reviewCount,'newsletterCount'=>$newsletterCount]);exit;
    }
    if($path==='/write/journeys/new'){
        journeys_method(['GET','POST']);require_auth();journeys_private_headers();$error=null;$season=[];
        if(is_post()){journeys_verify_csrf();$season=$_POST;try{$id=journeys_save_season($_POST,null,(int)current_user()['id']);flash('success','Journey created. Add chapters using existing posts.');redirect(url('/write/journeys/'.$id));}catch(Throwable $e){$error=journeys_route_error($e);}}
        render_write('journeys_edit',['writeTitle'=>'New Startup Journey','season'=>$season,'seasonId'=>null,'episodes'=>[],'postOptions'=>[],'error'=>$error]);exit;
    }
    if(preg_match('~^/write/journeys/([1-9][0-9]*)$~',$path,$m)){
        journeys_method(['GET','POST']);require_auth();journeys_private_headers();$id=(int)$m[1];$season=journeys_writer_season($id);if(!$season)journeys_not_found();$error=null;$episodeValues=null;
        if(is_post()){
            journeys_verify_csrf();$actor=(int)current_user()['id'];
            try{
                $action=journeys_text($_POST['action']??'save','Action',30);
                if($action==='save'){journeys_save_season($_POST,$id,$actor);flash('success','Journey saved.');}
                elseif($action==='save_episode'){journeys_save_episode($id,$_POST,$actor);flash('success','Chapter link saved. Public display also requires the journey and post to be published.');}
                elseif($action==='remove_episode'){journeys_remove_episode($id,(int)journeys_id($_POST['episode_id']??null,'chapter'),$actor);flash('success','Chapter link removed. The post is unchanged.');}
                elseif($action==='delete'){
                    if(!journeys_checked($_POST['confirm_delete']??null))throw new JourneyValidationException('Confirm that you want to remove this journey and its chapter links.');
                    journeys_delete_season($id,(int)journeys_id($_POST['row_version']??null,'version'),$actor);flash('success','Journey removed. Its posts are still in your writing library.');redirect(url('/write/journeys'));
                }else throw new JourneyValidationException('Choose a valid action.');
                redirect(url('/write/journeys/'.$id));
            }catch(Throwable $e){$error=journeys_route_error($e);if(($_POST['action']??'save')==='save')$season=array_merge($season,$_POST);if(($_POST['action']??'')==='save_episode')$episodeValues=$_POST;}
        }
        render_write('journeys_edit',['writeTitle'=>'Edit Startup Journey','season'=>$season,'seasonId'=>$id,'episodes'=>journeys_writer_episodes($id),'postOptions'=>journeys_recent_posts(),'episodeValues'=>$episodeValues,'error'=>$error]);exit;
    }
    if($path==='/write/journeys/founders'){
        journeys_method(['GET','HEAD']);require_auth();journeys_private_headers();$filter=$_GET['status']??'';
        if(!is_string($filter)||($filter!==''&&!array_key_exists($filter,founders_review_states())))$filter='';
        $s=db()->prepare('SELECT id,reference,submission_type,founder_name,venture_name,country,review_status,is_public,created_at FROM founder_submissions'.($filter!==''?' WHERE review_status=?':'').' ORDER BY created_at DESC,id DESC LIMIT 200');$s->execute($filter!==''?[$filter]:[]);
        render_write('journeys_founders',['writeTitle'=>'Founder submissions','submissions'=>$s->fetchAll(),'filter'=>$filter]);exit;
    }
    if(preg_match('~^/write/journeys/founders/([1-9][0-9]*)$~',$path,$m)){
        journeys_method(['GET','POST']);require_auth();journeys_private_headers();$id=(int)$m[1];$s=db()->prepare('SELECT * FROM founder_submissions WHERE id=?');$s->execute([$id]);$submission=$s->fetch();if(!$submission)journeys_not_found();$error=null;$review=$submission;
        if(is_post()){journeys_verify_csrf();try{founders_save_review($id,$_POST,(int)current_user()['id']);flash('success','Review saved. Only an approved story with permission and a published article can appear publicly.');redirect(url('/write/journeys/founders/'.$id));}catch(Throwable $e){$error=journeys_route_error($e);$review=array_merge($submission,$_POST);foreach(['permission_confirmed','claims_reviewed','is_public']as$key)$review[$key]=journeys_checked($_POST[$key]??null)?1:0;}}
        render_write('journeys_founder_review',['writeTitle'=>'Review founder submission','submission'=>$submission,'review'=>$review,'postOptions'=>journeys_recent_posts(),'error'=>$error]);exit;
    }
    return false;
}
