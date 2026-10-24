<?php
declare(strict_types=1);
/** Renewable, host-only remembered-device credential; ordinary sessions remain short lived. */
function app_trust_epoch(int $uid): string {
 $q=db()->prepare('SELECT setting_value,is_secret FROM settings WHERE setting_key=?');$q->execute(['auth.password_epoch.'.$uid]);$r=$q->fetch();if(!$r)return '';
 return (int)$r['is_secret']?decrypt_secret((string)$r['setting_value']):(string)$r['setting_value'];
}
function app_trust_cookie_name(): string {return str_starts_with(base_url(),'https://')?'__Host-wn_trust':'wn_trust';}
function app_trust_cookie(string $value,int $expires): void {
 if(PHP_SAPI!=='cli')setcookie(app_trust_cookie_name(),$value,['expires'=>$expires,'path'=>'/','secure'=>str_starts_with(base_url(),'https://'),'httponly'=>true,'samesite'=>'Lax']);
 if($value==='')unset($_COOKIE[app_trust_cookie_name()]);else $_COOKIE[app_trust_cookie_name()]=$value;
}
function app_trust_parts(): ?array {
 $raw=$_COOKIE[app_trust_cookie_name()]??'';
 if(!is_string($raw)||!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D',$raw,$m))return null;
 return [hash('sha256',$m[1]),$m[2]];
}
function app_trust_issue(int $uid): void {
 if(!empty($_SESSION['trusted_id']))app_trust_revoke((string)$_SESSION['trusted_id'],$uid);
 $selector=bin2hex(random_bytes(16));$token=bin2hex(random_bytes(32));$id=hash('sha256',$selector);$now=time();
 $csrf=(string)($_SESSION['csrf']??bin2hex(random_bytes(32)));$_SESSION['csrf']=$csrf;
 $r=['csrf'=>$csrf,'id'=>$id,'user'=>$uid,'hash'=>hash('sha256',$token),'epoch'=>app_trust_epoch($uid),'created'=>$now,'seen'=>$now,'expires'=>$now+180*86400,'revoked'=>false];
 comm_put('trusted.'.$id,$r);$_SESSION['trusted_id']=$id;$_SESSION['trust_opt_out']=false;
 app_trust_cookie($selector.'.'.$token,$r['expires']);
}
function app_trust_valid(array $r,string $token,string $epoch,int $now): bool {
 return !empty($r['user'])&&empty($r['revoked'])&&(int)($r['expires']??0)>$now&&hash_equals((string)($r['hash']??''),hash('sha256',$token))&&hash_equals((string)($r['epoch']??''),$epoch);
}
function app_trust_restore(): bool {
 if(!empty($_SESSION['user_id']))return false;
 $parts=app_trust_parts();if(!$parts)return false;[$id,$token]=$parts;$r=comm_get('trusted.'.$id);
 if(!$r||!app_trust_valid($r,$token,app_trust_epoch((int)($r['user']??0)),time())||!empty(comm_get('session.'.$id)['revoked'])){app_trust_cookie('',time()-3600);return false;}
 $q=db()->prepare('SELECT id FROM users WHERE id=? AND is_active=1');$q->execute([$r['user']]);if(!$q->fetchColumn()){app_trust_cookie('',time()-3600);return false;}
 if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
 $_SESSION['user_id']=(int)$r['user'];$_SESSION['auth_epoch']=$r['epoch'];$_SESSION['csrf']=(string)($r['csrf']??bin2hex(random_bytes(32)));$_SESSION['interactive_at']=time();$_SESSION['trusted_id']=$id;
 app_trust_renew();return true;
}
function app_trust_renew(): void {
 $id=$_SESSION['trusted_id']??'';if(!$id)return;$parts=app_trust_parts();if(!$parts||$parts[0]!==$id)return;
 $r=comm_get('trusted.'.$id);if(!$r||!app_trust_valid($r,$parts[1],app_trust_epoch((int)$r['user']),time()))return;
 if((int)$r['seen']<time()-86400){$r['seen']=time();$r['expires']=time()+180*86400;comm_put('trusted.'.$id,$r);app_trust_cookie((string)$_COOKIE[app_trust_cookie_name()],$r['expires']);}
}
function app_trust_revoke(string $id,int $uid): void {
 if(!preg_match('/^[a-f0-9]{64}$/D',$id))return;$r=comm_get('trusted.'.$id);
 if($r&&(int)$r['user']===$uid){$r['revoked']=true;comm_put('trusted.'.$id,$r);}
}
function app_trust_forget(): void {
 if(!empty($_SESSION['trusted_id']))app_trust_revoke((string)$_SESSION['trusted_id'],(int)($_SESSION['user_id']??0));
 app_trust_cookie('',time()-3600);unset($_SESSION['trusted_id']);
}
