<?php
declare(strict_types=1);
/** One encrypted owner record; changing a footer is separate from authorizing delivery. */
function newsletter_postal_configuration(): array {
    $record=function_exists('comm_get')?comm_get('newsletter.sender-postal'):[];
    if(!$record)return ['version'=>0,'postal_address'=>trim((string)app_config('mail.postal_address',''))];
    if(!is_int($record['version']??null)||$record['version']<1||!is_int($record['owner']??null)||$record['owner']<1||($record['owner']!==(int)(comm_config()['owner']??0))||!is_string($record['postal_address']??null))throw new RuntimeException('Newsletter sender configuration requires owner review.');
    newsletter_text($record['postal_address'],'Mailing address',500);return $record;
}
function newsletter_postal_save(array $input,int $actor): void {
    $version=$input['version']??null;if(!is_string($version)||!preg_match('/^(?:0|[1-9][0-9]{0,8})$/D',$version))throw new NewsletterValidationException('Reload the current sender setup before saving.');
    if(($input['confirm_public_footer']??'')!=='1')throw new NewsletterValidationException('Review and confirm the subscriber-email footer address.');
    if(!is_string($input['postal_address']??null))throw new NewsletterValidationException('Enter the mailing address as text.');
    $address=newsletter_text(str_replace(["\r\n","\r"],"\n",$input['postal_address']),'Mailing address',500);
    comm_lock('newsletter.sender-postal',static function()use($address,$version,$actor){
        $owner=(int)(comm_config()['owner']??0);if($owner<1||$actor!==$owner)throw new NewsletterValidationException('Only the workspace owner can change the newsletter footer address.');
        $current=newsletter_postal_configuration();if((string)$current['version']!==$version)throw new NewsletterConflictException('The sender address changed in another session. Review its current value before saving.');
        comm_put('newsletter.sender-postal',['owner'=>$owner,'version'=>$current['version']+1,'postal_address'=>$address,'updated_at'=>time()]);
    });
}
function newsletter_sender_config_dispatch(string $path): bool {
    if($path!=='/write/newsletter/sender')return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$actor=(int)(current_user()['id']??0);$owner=(int)(comm_config()['owner']??0);
    if($actor<1||$owner<1||$actor!==$owner){http_response_code(403);echo 'Newsletter sender setup requires owner access.';return true;}
    if(($_SERVER['REQUEST_METHOD']??'GET')==='HEAD')return true;
    $error=null;$state=newsletter_postal_configuration();$value=$state['postal_address'];
    if(is_post()){
        verify_csrf();if(!journeys_rate_limit('newsletter-sender',(string)$actor,10,900)){http_response_code(429);echo 'Please wait before changing sender details again.';return true;}
        try{newsletter_postal_save($_POST,$actor);flash('success','Newsletter footer address saved. Changed sender details require fresh edition review; saving does not send mail or release a queue.');redirect(url($path));}
        catch(Throwable $e){$error=newsletter_route_error($e);$state=newsletter_postal_configuration();$value=$e instanceof NewsletterConflictException?$state['postal_address']:(is_string($_POST['postal_address']??null)?mb_substr($_POST['postal_address'],0,500):$state['postal_address']);}
    }
    render_write('newsletter_sender',['writeTitle'=>'Newsletter sender setup','sender'=>newsletter_sender(),'senderPostalValue'=>$value,'senderPostalVersion'=>$state['version'],'error'=>$error]);return true;
}
