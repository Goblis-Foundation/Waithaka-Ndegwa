<?php
declare(strict_types=1);
function founder_story_dispatch(string $path): bool {
    if($path==='/founders/story-review'){
        journeys_private_headers();journeys_method(['GET','HEAD','POST']);$error=null;$done=false;
        $token=is_string($_POST['token']??$_GET['token']??null)?($_POST['token']??$_GET['token']):'';
        if(is_post()){
            journeys_verify_csrf();
            try{if(!journeys_rate_limit('founder-story-response',ip_hash(),30))throw new JourneyValidationException('Too many responses. Try again later.');founder_story_respond($token,$_POST);$done=true;}
            catch(Throwable $e){$error=reader_route_error($e);}
        }
        $review=$done?null:founder_story_token($token);if(!$review&&!$done)http_response_code(410);$snapshot=$review?json_decode($review['snapshot_json'],true,32,JSON_THROW_ON_ERROR):null;
        reader_public_render('founder_story_review',['review'=>$review,'snapshot'=>$snapshot,'token'=>$token,'done'=>$done,'error'=>$error],'Review your Founder Story',$path);return true;
    }
    if($path==='/write/founder-stories/whatsapp'&&is_post()){
        require_auth();journeys_verify_csrf();journeys_private_headers();
        try{
            $phone=reader_text($_POST['phone_id']??'','WhatsApp sender phone ID',30);$template=reader_text($_POST['template']??'','Approved template name',100);$language=reader_text($_POST['language']??'en','Template language',10,true);
            if(($phone!==''&&!preg_match('/^[0-9]{5,30}$/D',$phone))||($template!==''&&!preg_match('/^[a-z0-9_]+$/D',$template))||!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$language))throw new JourneyValidationException('Check the sender ID, approved template name and language.');
            $token=reader_text($_POST['access_token']??'','WhatsApp access token',10000);
            if($token!==''&&preg_match('/[\x00-\x20\x7f]/',$token))throw new JourneyValidationException('The WhatsApp token must not contain whitespace or header characters.');
            db()->beginTransaction();foreach(['whatsapp_phone_id'=>$phone,'whatsapp_template'=>$template,'whatsapp_language'=>$language,'whatsapp_enabled'=>empty($_POST['enabled'])?'0':'1']as$key=>$value)set_setting('founder_story.'.$key,$value);
            if($token!=='')set_setting('founder_story.whatsapp_token',$token,true);db()->commit();flash('success','WhatsApp review settings saved. No message was sent.');
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'The review settings could not be saved.');}
        redirect(url('/write/journeys/founders'));return true;
    }
    if(preg_match('~^/write/founder-stories/([1-9][0-9]*)/(request|apply-edits|retry)$~',$path,$m)){
        require_auth();journeys_method(['POST']);journeys_verify_csrf();journeys_private_headers();$id=(int)$m[1];$actor=(int)current_user()['id'];
        try{
            if($m[2]==='request')founder_story_request($id,$_POST,$actor);
            elseif($m[2]==='apply-edits')founder_story_apply_edits($id,$actor);
            else founder_story_retry($id,$actor,!empty($_POST['provider_checked']));
            flash('success',$m[2]==='request'?'Final-copy review created. Email and WhatsApp delivery are tracked separately.':($m[2]==='apply-edits'?'Founder edits were imported into the private draft. Review them in the editor, then send the next round.':'Eligible notifications were requeued. An accepted message is not resent.'));
        }catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException||$e instanceof JourneyConflictException?$e->getMessage():'The story review could not be saved. Reload and try again.');}
        redirect(url('/write/journeys/founders/'.$id));return true;
    }return false;
}
