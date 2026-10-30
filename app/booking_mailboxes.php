<?php
declare(strict_types=1);
require_once __DIR__.'/owner_identity_aliases.php';
/** Separate send-only OAuth grants; calendar read access never becomes mail access. */
function booking_mailbox_id(mixed $id): string {if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Choose a booking mailbox.');return $id;}
function booking_mailbox_owned(int $uid,mixed $id,bool $ready=true): array {$id=booking_mailbox_id($id);$m=comm_get('booking-mailbox.'.$uid.'.'.$id);if(!$m||(int)$m['user']!==$uid||($ready&&($m['status']!=='connected'||owner_identity_is_alias((string)$m['email']))))throw new RuntimeException('Confirm or reconnect this booking mailbox.');return $m;}
function booking_mailboxes(int $uid): array {return array_values(array_filter(comm_records('booking-mailbox.'.$uid,30),static fn($m)=>!owner_identity_is_alias((string)($m['email']??''))));}
function booking_mailbox_observed(array $credentials,array $scopes,array $observed,int $uid): void {
 $email=strtolower((string)($observed['metadata']['email']??''));owner_identity_require_primary($email);if(!filter_var($email,FILTER_VALIDATE_EMAIL)||empty($observed['metadata']['email_verified'])||!in_array('https://www.googleapis.com/auth/gmail.send',$scopes,true))throw new RuntimeException('Google did not verify this sender and its send-only permission.');
 $id=substr(hash('sha256',$uid.'|'.$observed['id'].'|'.$email),0,32);comm_lock('booking-mailbox.'.$uid,function()use($id,$uid,$email,$credentials,$scopes,$observed){$old=comm_get('booking-mailbox.'.$uid.'.'.$id);if(!$old&&count(booking_mailboxes($uid))>=30)throw new RuntimeException('Booking mailbox limit reached.');
 comm_put('booking-mailbox.'.$uid.'.'.$id,['id'=>$id,'user'=>$uid,'email'=>$email,'subject'=>$observed['id'],'label'=>$old['label']??'Waithaka Ndegwa','status'=>'pending_confirmation','credentials'=>$credentials,'scopes'=>$scopes,'updated'=>time(),'revision'=>($old['revision']??0)+1]);});
}
function booking_mailbox_snapshot(array $cfg,mixed $id): array {
 if($id===null||$id==='')return ['id'=>'','email'=>(string)app_config('mail.from'),'label'=>'Waithaka Ndegwa'];
 $m=booking_mailbox_owned((int)$cfg['owner'],$id);return ['id'=>$m['id'],'email'=>$m['email'],'label'=>$m['label']];
}
function booking_mailbox_token(int $uid,string $id,string $expectedEmail): string {
 return comm_lock('booking-mailbox.'.$uid,function()use($uid,$id,$expectedEmail){$m=booking_mailbox_owned($uid,$id);if(!hash_equals($expectedEmail,$m['email']))throw new RuntimeException('Booking sender identity changed. Review before sending.');$c=$m['credentials'];
 if(empty($c['access_token']))throw new RuntimeException('Reconnect this booking mailbox.');
 if((int)($c['expires_at']??0)<=time()+120){if(empty($c['refresh_token']))throw new RuntimeException('Reconnect this booking mailbox.');$r=publishing_http('POST','https://oauth2.googleapis.com/token',[],publishing_client('google')+['grant_type'=>'refresh_token','refresh_token'=>$c['refresh_token']],true)['data'];if(empty($r['access_token']))throw new RuntimeException('Booking mailbox refresh was unavailable.');$c=array_merge($c,$r);$c['expires_at']=time()+(int)($r['expires_in']??3600);$m['credentials']=$c;comm_put('booking-mailbox.'.$uid.'.'.$id,$m);}return $c['access_token'];});
}
function booking_mailbox_mime(array $payload,string $from,string $key): string {
 if(!filter_var($from,FILTER_VALIDATE_EMAIL)||!filter_var($payload['to'],FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$from.$payload['to'].$payload['subject']))throw new InvalidArgumentException('Invalid booking email identity.');
 $mail=new \PHPMailer\PHPMailer\PHPMailer(true);$mail->CharSet='UTF-8';$mail->setFrom($from,'Waithaka Ndegwa');$mail->addReplyTo($from,'Waithaka Ndegwa');$mail->addAddress($payload['to']);$mail->MessageID='<'.$key.'@'.substr(strrchr($from,'@'),1).'>';$mail->isHTML(true);$mail->Subject=$payload['subject'];$mail->Body=$payload['html'];$mail->AltBody=email_design_text($payload['html']);if(!$mail->preSend())throw new RuntimeException('Booking email could not be prepared.');return $mail->getSentMIMEMessage();
}
function booking_mailbox_deliver(array $b,array $cfg,array $payload,string $key): string {
 $identity=$b['host_mailbox']??['id'=>'','email'=>(string)app_config('mail.from')];
 if(($identity['id']??'')===''){if(!hash_equals((string)($identity['email']??''),(string)app_config('mail.from')))throw new RuntimeException('Original booking sender changed. Review instead of switching accounts.');return email_deliver($payload,$key);}
 $token=booking_mailbox_token((int)$cfg['owner'],$identity['id'],$identity['email']);$raw=booking_mailbox_mime($payload,$identity['email'],$key);
 $r=publishing_http('POST','https://gmail.googleapis.com/gmail/v1/users/me/messages/send',['Authorization: Bearer '.$token],['raw'=>comm_b64($raw)],false,true)['data'];if(empty($r['id']))throw new RuntimeException('Mail delivery is unconfirmed. Check the original mailbox before retrying.');return (string)$r['id'];
}
function booking_mailboxes_dispatch(string $path): bool {
 if($path!=='/write/booking-mailboxes')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];$error='';
 if(is_post()){verify_csrf();try{$id=booking_mailbox_id($_POST['id']??null);$action=$_POST['action']??'';comm_lock('booking-mailbox.'.$uid,function()use($uid,$id,$action){$m=booking_mailbox_owned($uid,$id,false);if($action==='confirm'){owner_identity_require_primary((string)$m['email']);if(($_POST['sending_consent']??'')!=='1')throw new InvalidArgumentException('Confirm booking-service sending from this mailbox.');$m['label']=reader_text($_POST['label']??'','Public booking label',120,true);$m['status']='connected';}elseif($action==='disconnect'){$m['status']='disconnected';$m['credentials']=[];}else throw new InvalidArgumentException('Choose a mailbox action.');$m['updated']=time();comm_put('booking-mailbox.'.$uid.'.'.$id,$m);});flash('success','Booking mailbox updated. Existing bookings retain their original sender identity.');redirect(url($path));}catch(Throwable $e){$error=$e instanceof InvalidArgumentException||get_class($e)===RuntimeException::class?$e->getMessage():'Mailbox could not be updated.';}}
 render_write('booking_mailboxes',['writeTitle'=>'Booking email identities','mailboxes'=>booking_mailboxes($uid),'error'=>$error]);return true;
}
