<?php
declare(strict_types=1);

function wa_security_changed(int $uid): void {
    $epoch=bin2hex(random_bytes(32));set_setting('auth.password_epoch.'.$uid,$epoch);$_SESSION['auth_epoch']=$epoch;app_trust_forget();$_SESSION['trust_opt_out']=true;
}
function wa_security_dispatch(): bool {
    require_auth();journeys_method(['GET','HEAD','POST']);$user=current_user();$uid=(int)$user['id'];$error='';
    if(is_post()){
        verify_csrf();try{
            if(!journeys_rate_limit('auth-settings',(string)$uid,25,900))throw new WriterAuthException('Wait before another security change.');$action=$_POST['action']??'';
            if($action==='stepup'){$email=wa_stepup_email($user,$_POST['verification_email']??null);app_password_check($uid,$_POST['password']??'');redirect(url(wa_begin($user,$email,false,'manage')));}
            if(!wa_stepup($uid))throw new WriterAuthException('Verify your identity below before changing sign-in methods.');
            if($action==='link_google'||$action==='link_microsoft')redirect(wa_oauth_start(substr($action,5),false,'link'));
            if($action==='totp_start'){$_SESSION['wa_totp_setup']=['secret'=>wa_base32(random_bytes(20)),'uid'=>$uid,'expires'=>time()+600];flash('success','Scan the QR code or enter the setup key, then confirm a code from your authenticator.');}
            elseif($action==='totp_finish'){
                $s=$_SESSION['wa_totp_setup']??[];if(($s['uid']??0)!==$uid||($s['expires']??0)<=time()||($counter=wa_totp_counter($s['secret'],wa_input('code',32),time()))===null)throw new WriterAuthException('Check the authenticator code or start setup again.');
                comm_lock('wa-factors-'.$uid,static function()use($uid,$s,$counter){$f=wa_factors($uid);$codes=wa_recovery_codes();$f['totp']=$s['secret'];$f['last_counter']=$counter;$f['recovery']=array_map('wa_mac',$codes);comm_put('auth.factors.'.$uid,$f);$_SESSION['wa_recovery_display']=$codes;wa_security_changed($uid);});unset($_SESSION['wa_totp_setup']);flash('success','Authenticator verified. Save the recovery codes shown once below.');
            }elseif(in_array($action,['totp_remove','phone_remove','recovery_new'],true)){
                comm_lock('wa-factors-'.$uid,static function()use($uid,$action){$f=wa_factors($uid);if($action==='totp_remove'){unset($f['totp'],$f['last_counter']);}elseif($action==='phone_remove')unset($f['phone'],$f['phone_verified_at']);else{$codes=wa_recovery_codes();$f['recovery']=array_map('wa_mac',$codes);$_SESSION['wa_recovery_display']=$codes;}comm_put('auth.factors.'.$uid,$f);wa_security_changed($uid);});flash('success','Sign-in methods updated. Other remembered sessions were invalidated.');
            }elseif($action==='phone_start'){
                if(!journeys_rate_limit('auth-phone-enrol',(string)$uid,4,3600))throw new WriterAuthException('Wait before requesting another phone setup code.');$phone=wa_phone(wa_input('phone',30));$sid=wa_sms_start($phone);$_SESSION['wa_phone_setup']=['phone'=>$phone,'sid'=>$sid,'uid'=>$uid,'expires'=>time()+600,'attempts'=>0];flash('success','A verification text was requested. Enter the code to confirm your number.');
            }elseif($action==='phone_finish'){
                $s=$_SESSION['wa_phone_setup']??[];if(($s['uid']??0)!==$uid||($s['expires']??0)<=time()||($s['attempts']??6)>=6)throw new WriterAuthException('Phone verification expired. Start again.');$_SESSION['wa_phone_setup']['attempts']++;if(!wa_sms_check($s['phone'],$s['sid'],wa_input('code',32)))throw new WriterAuthException('The text code was not accepted.');
                comm_lock('wa-factors-'.$uid,static function()use($uid,$s){$f=wa_factors($uid);$f['phone']=$s['phone'];$f['phone_verified_at']=time();comm_put('auth.factors.'.$uid,$f);wa_security_changed($uid);});unset($_SESSION['wa_phone_setup']);flash('success','Your phone number is verified for sign-in codes.');
            }elseif($action==='sms_settings'){
                if($uid!==wa_owner_id())throw new WriterAuthException('Only the owner can configure text delivery.');$old=wa_sms_config();$account=wa_input('account',40);$service=wa_input('service',40);$token=wa_input('token',200);if($token===''){$token=$old['token']??'';if($account!==($old['account']??''))throw new WriterAuthException('Enter the token for the changed Twilio account.');}
                if(!preg_match('/^AC[a-f0-9]{32}$/Di',$account)||!preg_match('/^VA[a-f0-9]{32}$/Di',$service)||$token===''||preg_match('/\s/',$token))throw new WriterAuthException('Enter valid Twilio account, Verify service and authentication credentials.');
                comm_put('auth.sms',['account'=>$account,'service'=>$service,'token'=>$token,'enabled'=>($_POST['enabled']??'')==='1']);flash('success','Text verification setup saved. Verify your own phone to enable its sign-in option.');
            }elseif($action==='unlink'){
                $key=wa_input('identity',64);if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new WriterAuthException('Choose one of your linked identities.');comm_lock('wa-identities',static function()use($uid,$key){$r=comm_get('auth.identity.'.$key);if((int)($r['uid']??0)!==$uid)throw new WriterAuthException('Choose your own identity.');foreach(['auth.identity.'.$key,'auth.identity_email.'.hash('sha256',$r['provider'].'|'.$r['email'])]as$k)db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['communication.'.$k]);wa_security_changed($uid);});flash('success','Provider sign-in link removed. Workspace integration permissions are managed separately.');
            }else throw new WriterAuthException('Choose a supported security action.');redirect(url('/write/security/sign-in'));
        }catch(WriterAuthException|InvalidArgumentException $e){$error=$e->getMessage();}catch(Throwable){$error='The security change could not be completed. Review your connection and try again.';}
    }
    $factors=wa_factors($uid);$setup=$_SESSION['wa_totp_setup']??[];if(($setup['uid']??0)!==$uid||($setup['expires']??0)<=time())$setup=[];
    $phoneSetup=$_SESSION['wa_phone_setup']??[];if(($phoneSetup['uid']??0)!==$uid||($phoneSetup['expires']??0)<=time())$phoneSetup=[];
    $codes=$_SESSION['wa_recovery_display']??[];unset($_SESSION['wa_recovery_display']);$identities=array_filter(comm_records('auth.identity',100),static fn($r)=>(int)($r['uid']??0)===$uid);
    render_write('sign_in_security',compact('user','uid','error','factors','setup','phoneSetup','codes','identities')+['writeTitle'=>'Sign-in & verification']);return true;
}
function wa_input(string $key,int $max): string {$v=$_POST[$key]??'';if(!is_string($v)||strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v))throw new WriterAuthException('Check the entered value.');return trim($v);}
function wa_dispatch(string $path): bool {
    if($path==='/write/security/sign-in'){private_response_headers();return wa_security_dispatch();}
    foreach(wa_providers()as$provider=>$p)if($path==='/write/connections/callback/'.$p['callback']&&($_SESSION['wa_oauth']['provider']??'')===$provider){
        private_response_headers();journeys_method(['GET']);$returnTo=($_SESSION['wa_oauth']['purpose']??'login')==='link'?'/write/security/sign-in':'/write/login';try{$f=wa_oauth_consume($provider,$_GET['state']??null);if(isset($_GET['error']))throw new WriterAuthException('Provider sign-in was cancelled. Choose another method or try again.');$code=$_GET['code']??'';if(!is_string($code))throw new WriterAuthException('Invalid sign-in response.');redirect(url(wa_oauth_complete($provider,$f,$code)));}catch(WriterAuthException $e){flash('error',$e->getMessage());}catch(Throwable){flash('error','Provider sign-in could not be completed. Use your Writer password or try again.');}redirect(url($returnTo));
    }
    if(preg_match('~^/write/auth/(google|microsoft)$~D',$path,$m)){
        private_response_headers();journeys_method(['POST']);verify_csrf();if(current_user())redirect(url('/write/security/sign-in'));try{redirect(wa_oauth_start($m[1],app_trust_choice($_POST['trust_device']??null)));}catch(WriterAuthException $e){flash('error',$e->getMessage());}catch(Throwable){flash('error','Provider sign-in is unavailable. Use your Writer password.');}redirect(url('/write/login'));
    }
    if($path!=='/write/verify')return false;private_response_headers();journeys_method(['GET','HEAD','POST']);$error='';$challenge=[];
    if(is_post())verify_csrf();try{
        if(is_post()&&($_POST['action']??'')==='cancel'){
            $returnTo=!empty($_SESSION['user_id'])?'/write/security/sign-in':'/write/login';
            try{$c=wa_pending();comm_lock('wa-challenge-'.$c['id'],static function()use($c){$c['used']=true;unset($c['code_hash'],$c['sms_sid']);comm_put('auth.challenge.'.$c['id'],$c);});}catch(WriterAuthException){}unset($_SESSION['wa_pending']);redirect(url($returnTo));
        }
        $challenge=wa_pending();if(is_post()){
            $action=wa_input('action',20);if($action==='verify')redirect(url(wa_finish(wa_verify_code(wa_input('code',80)))));
            if($action==='method'){wa_send_code(wa_input('method',20));redirect(url('/write/verify'));}else throw new WriterAuthException('Choose a verification action.');
        }
    }catch(WriterAuthException $e){$error=$e->getMessage();try{$challenge=wa_pending();}catch(WriterAuthException){$challenge=[];}}
    catch(Throwable){$error='Verification could not be completed. Try an enrolled method or start again.';}
    render_write('verify_login',compact('challenge','error')+['writeTitle'=>'Verify your sign-in']);return true;
}
