<?php
declare(strict_types=1);

/** Writer authentication is separate from grants for publishing, mail or calendars. */
final class WriterAuthException extends RuntimeException {}
function wa_enforced(): bool {return app_config('app_env')==='production';}
function wa_owner_id(): int {return (int)(comm_config()['owner']??0);}
function wa_user(int $uid): ?array {$q=db()->prepare('SELECT * FROM users WHERE id=? AND is_active=1');$q->execute([$uid]);return $q->fetch()?:null;}
/** Owner-authorised aliases share the same workspace, data and administrative permissions. */
function wa_owner_aliases(): array {return ['isaac@goblisgroup.com','isaac@goblisfoundation.org'];}
function wa_find_user(string $email): ?array {
    $email=strtolower(trim($email));if(!filter_var($email,FILTER_VALIDATE_EMAIL))return null;
    if(in_array($email,wa_owner_aliases(),true))return wa_user(wa_owner_id());
    $q=db()->prepare('SELECT * FROM users WHERE email=? AND is_active=1 LIMIT 1');$q->execute([$email]);return $q->fetch()?:null;
}
function wa_addresses(array $user): array {return array_values(array_unique(array_merge([strtolower($user['email'])],(int)$user['id']===wa_owner_id()?wa_owner_aliases():[])));}
function wa_stepup_email(array $user,mixed $value=null): string {
    $allowed=wa_addresses($user);
    if($value===null){$preferred=$_SESSION['login_email']??'';return is_string($preferred)&&in_array($preferred,$allowed,true)?$preferred:$allowed[0];}
    if(!is_string($value)||strlen($value)>254)throw new WriterAuthException('Choose one of your approved sign-in addresses.');
    $email=strtolower(trim($value));if(!in_array($email,$allowed,true))throw new WriterAuthException('Choose one of your approved sign-in addresses.');return $email;
}
function wa_factors(int $uid): array {return comm_get('auth.factors.'.$uid);}
function wa_mac(string $value): string {return hash_hmac('sha256','writer-auth|'.$value,(string)app_config('app_key'));}
function wa_mask(string $email): string {[$name,$domain]=explode('@',$email,2);return mb_substr($name,0,2).'•••@'.$domain;}
function wa_base32(string $bytes): string {
    $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';foreach(str_split($bytes)as$c)$bits.=str_pad(decbin(ord($c)),8,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,5)as$chunk)$out.=$alphabet[bindec(str_pad($chunk,5,'0'))];return $out;
}
function wa_unbase32(string $secret): string {
    if(!preg_match('/^[A-Z2-7]{32}$/D',$secret))throw new WriterAuthException('Authenticator setup is invalid.');$bits='';foreach(str_split($secret)as$c)$bits.=str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',$c)),5,'0',STR_PAD_LEFT);$out='';foreach(str_split($bits,8)as$b)if(strlen($b)===8)$out.=chr(bindec($b));return $out;
}
function wa_totp(string $secret,int $counter): string {
    $hash=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),wa_unbase32($secret),true);$offset=ord($hash[19])&15;$value=unpack('N',substr($hash,$offset,4))[1]&0x7fffffff;return str_pad((string)($value%1000000),6,'0',STR_PAD_LEFT);
}
function wa_totp_counter(string $secret,string $code,int $now,int $last=-1): ?int {
    if(!preg_match('/^[0-9]{6}$/D',$code))return null;$step=intdiv($now,30);foreach([$step,$step-1,$step+1]as$c)if($c>$last&&hash_equals(wa_totp($secret,$c),$code))return $c;return null;
}
function wa_recovery_codes(): array {$codes=[];for($i=0;$i<8;$i++)$codes[]=implode('-',str_split(bin2hex(random_bytes(16)),8));return $codes;}
function wa_methods(array $challenge): array {
    if(!empty($challenge['link_identity']))return ['email'=>'Email code']; // Establish mailbox ownership before first provider binding.
    $f=wa_factors((int)$challenge['uid']);$out=['email'=>'Email code'];if(!empty($f['totp']))$out['totp']='Authenticator app';if(!empty($f['phone'])&&wa_sms_ready())$out['sms']='Text message';if(!empty($f['recovery']))$out['recovery']='Recovery code';return $out;
}
function wa_challenge_current(array $c,?int $now=null): bool {
    $now??=time();$u=wa_user((int)($c['uid']??0));$purpose=$c['purpose']??'login';return in_array($purpose,['login','manage','link'],true)&&($purpose==='login'||(int)($_SESSION['user_id']??0)===(int)($c['uid']??0))&&$u&&($c['expires']??0)>$now&&($c['attempts']??6)<6&&empty($c['used'])&&hash_equals((string)($c['session']??''),wa_mac(session_id()))&&hash_equals((string)($c['epoch']??''),app_trust_epoch((int)$u['id']))&&in_array($c['email']??'',wa_addresses($u),true);
}
function wa_pending(): array {
    $id=$_SESSION['wa_pending']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))throw new WriterAuthException('Start sign-in again.');$c=comm_get('auth.challenge.'.$id);if(!wa_challenge_current($c))throw new WriterAuthException('This verification expired or reached its attempt limit. Start sign-in again.');return $c;
}
function wa_begin(array $user,string $email,bool $trust,string $purpose='login',array $identity=[]): string {
    if(!in_array($email,wa_addresses($user),true)||!in_array($purpose,['login','manage','link'],true)||($purpose!=='login'&&(int)($_SESSION['user_id']??0)!==(int)$user['id'])||($purpose==='link'&&(!wa_stepup((int)$user['id'])||!$identity)))throw new WriterAuthException('This sign-in could not be verified.');
    $old=$_SESSION['wa_pending']??'';if(is_string($old)&&preg_match('/^[a-f0-9]{32}$/D',$old))comm_lock('wa-challenge-'.$old,static function()use($old){$c=comm_get('auth.challenge.'.$old);$c['used']=true;unset($c['code_hash']);comm_put('auth.challenge.'.$old,$c);});
    if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);$id=bin2hex(random_bytes(16));
    $c=['id'=>$id,'uid'=>(int)$user['id'],'email'=>$email,'trust'=>$trust,'purpose'=>$purpose,'session'=>wa_mac(session_id()),'epoch'=>app_trust_epoch((int)$user['id']),'expires'=>time()+600,'attempts'=>0,'used'=>false,'method'=>'email','sent_at'=>0,'delivery'=>'not_sent','link_identity'=>$identity];
    comm_put('auth.challenge.'.$id,$c);$_SESSION['wa_pending']=$id;$_SESSION['csrf']=bin2hex(random_bytes(32));
    try{wa_send_code('email');}catch(Throwable){/* Keep the challenge and alternate enrolled methods available. */}return '/write/verify';
}
/** A security code is delivered directly; it never releases or joins the marketing backlog. */
function wa_email_code(string $email,string $code,string $id): void {
    if(!email_transport_ready())throw new WriterAuthException('Email verification is temporarily unavailable. Try an enrolled authenticator or passkey recovery option.');
    $html=email_design('Your Writer verification code','<p>Use this code to finish your requested sign-in or security change:</p><p style="font-size:32px;font-weight:700;letter-spacing:6px">'.e($code).'</p><p>It expires in 10 minutes and can be used once. Do not share it. If you did not request it, ignore this email.</p>');
    $receipt=email_deliver(['to'=>$email,'subject'=>'Your Writer verification code','html'=>$html,'headers'=>[]],hash('sha256','writer-auth|'.$id.'|'.bin2hex(random_bytes(16))));if($receipt==='')throw new WriterAuthException('The email provider did not acknowledge this code.');
}
function wa_send_code(string $method,?callable $emailSender=null,?callable $smsSender=null): void {
    $pending=wa_pending();comm_lock('wa-challenge-'.$pending['id'],static function()use($pending,$method,$emailSender,$smsSender){
        $c=wa_pending();if(!isset(wa_methods($c)[$method]))throw new WriterAuthException('Choose an enrolled verification method.');
        if(in_array($method,['email','sms'],true)&&($c['sent_at']??0)>time()-60)throw new WriterAuthException('Wait one minute before requesting another code.');
        if(in_array($method,['email','sms'],true)&&(!journeys_rate_limit('auth-send-user',(string)$c['uid'],8,3600)||!journeys_rate_limit('auth-send-ip',ip_hash(),12,3600)))throw new WriterAuthException('The code request limit was reached. Try an enrolled authenticator or wait.');
        unset($c['code_hash'],$c['sms_sid']);$c['method']=$method;$c['delivery']='not_sent';
        if(!in_array($method,['email','sms'],true)){comm_put('auth.challenge.'.$c['id'],$c);return;}
        $c['sent_at']=time();$c['delivery']='sending';$code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);if($method==='email')$c['code_hash']=wa_mac($c['id'].'|'.$code);comm_put('auth.challenge.'.$c['id'],$c);
        try{if($method==='email')($emailSender??'wa_email_code')($c['email'],$code,$c['id']);else{$f=wa_factors($c['uid']);$c['sms_sid']=($smsSender??'wa_sms_start')($f['phone']);$c['phone_revision']=wa_mac($f['phone']);}$c['delivery']='accepted';comm_put('auth.challenge.'.$c['id'],$c);}
        catch(Throwable){unset($c['code_hash'],$c['sms_sid']);$c['delivery']='unavailable';comm_put('auth.challenge.'.$c['id'],$c);throw new WriterAuthException('The code service is unavailable. Use an enrolled authenticator or recovery code, or try again later.');}
    });
}
function wa_verify_code(string $code,?callable $smsCheck=null): array {
    $pending=wa_pending();return comm_lock('wa-challenge-'.$pending['id'],static function()use($code,$smsCheck){
        $c=wa_pending();if(!journeys_rate_limit('auth-verify-user',(string)$c['uid'],12,900)||!journeys_rate_limit('auth-verify-ip',ip_hash(),25,900))throw new WriterAuthException('Too many attempts. Wait before trying again.');
        $c['attempts']++;comm_put('auth.challenge.'.$c['id'],$c);$method=$c['method'];$ok=false;
        if($method==='email')$ok=($c['delivery']??'')==='accepted'&&preg_match('/^[0-9]{6}$/D',$code)&&hash_equals($c['code_hash']??'',wa_mac($c['id'].'|'.$code));
        elseif(in_array($method,['totp','recovery','sms'],true)&&empty($c['link_identity']))$ok=comm_lock('wa-factors-'.$c['uid'],static function()use($c,$method,$code,$smsCheck){$f=wa_factors($c['uid']);
            if($method==='sms')return !empty($f['phone'])&&hash_equals($c['phone_revision']??'',wa_mac($f['phone']))&&!empty($c['sms_sid'])&&($smsCheck??'wa_sms_check')($f['phone'],$c['sms_sid'],$code);
            if($method==='totp'){$counter=!empty($f['totp'])?wa_totp_counter($f['totp'],$code,time(),(int)($f['last_counter']??-1)):null;if($counter===null)return false;$f['last_counter']=$counter;}
            else{$hash=wa_mac(strtolower(trim($code)));$index=array_search($hash,$f['recovery']??[],true);if($index===false)return false;unset($f['recovery'][$index]);$f['recovery']=array_values($f['recovery']);}
            comm_put('auth.factors.'.$c['uid'],$f);return true;
        });
        if(!$ok)throw new WriterAuthException('That code was not accepted. Check the code and try again.');
        if($c['expires']<=time()||!hash_equals($c['epoch'],app_trust_epoch((int)$c['uid'])))throw new WriterAuthException('This verification expired or the account changed. Start again.');
        if(!empty($c['link_identity']))wa_bind_identity($c);
        $c['used']=true;unset($c['code_hash'],$c['sms_sid']);comm_put('auth.challenge.'.$c['id'],$c);unset($_SESSION['wa_pending']);return $c;
    });
}
function wa_finish(array $c): string {
    $user=wa_user((int)$c['uid']);if(!$user||!hash_equals($c['epoch'],app_trust_epoch((int)$c['uid'])))throw new WriterAuthException('The account changed. Start sign-in again.');
    if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);$_SESSION['wa_verified_at']=time();$_SESSION['wa_verified_uid']=(int)$c['uid'];$_SESSION['wa_auth_version']=1;$_SESSION['csrf']=bin2hex(random_bytes(32));
    if($c['purpose']==='manage')return '/write/security/sign-in';
    if($c['purpose']==='link'){
        if((int)($_SESSION['user_id']??0)!==(int)$c['uid'])throw new WriterAuthException('The signed-in account changed. Start again.');
        wa_security_changed((int)$c['uid']);flash('success','Provider sign-in linked to your existing account. Other remembered sessions were invalidated.');return '/write/security/sign-in';
    }
    $_SESSION['user_id']=(int)$c['uid'];$_SESSION['auth_epoch']=$c['epoch'];$_SESSION['interactive_at']=time();$_SESSION['login_email']=$c['email'];$_SESSION['trust_opt_out']=!$c['trust'];unset($_SESSION['login_form']);
    if($c['trust'])app_trust_issue((int)$c['uid']);else app_trust_forget();db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$c['uid']]);return writer_login_return();
}
function wa_stepup(int $uid): bool {return (int)($_SESSION['wa_verified_uid']??0)===$uid&&($_SESSION['wa_verified_at']??0)>time()-600;}
function wa_cleanup(): void {foreach(comm_records('auth.challenge',2000)as$c)if(($c['expires']??0)<time()-86400&&preg_match('/^[a-f0-9]{32}$/D',(string)($c['id']??'')))db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['communication.auth.challenge.'.$c['id']]);}
