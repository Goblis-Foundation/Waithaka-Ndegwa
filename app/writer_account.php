<?php
declare(strict_types=1);

function writer_change_password(int $id,string $current,string $password,string $confirmation): void {
    $pdo=db();$st=$pdo->prepare('SELECT email,password_hash FROM users WHERE id=? AND is_active=1');$st->execute([$id]);$row=$st->fetch();
    if(!$row)throw new InvalidArgumentException('The Writer account is unavailable.');
    if(login_rate_limited($row['email']))throw new InvalidArgumentException('Too many failed attempts. Please wait 15 minutes before trying again.');
    if(!password_verify($current,$row['password_hash'])){
        $pdo->prepare('INSERT INTO login_attempts(email,ip_hash,success) VALUES(?,?,0)')->execute([$row['email'],ip_hash()]);
        throw new InvalidArgumentException('Your current password is incorrect.');
    }
    if(strlen($password)<14||strlen($password)>72||str_contains($password,"\0"))throw new InvalidArgumentException('Use a password between 14 and 72 bytes long. A long, unique passphrase works well.');
    if(!hash_equals($password,$confirmation))throw new InvalidArgumentException('The new passwords do not match.');
    if(password_verify($password,$row['password_hash']))throw new InvalidArgumentException('Choose a password different from your current one.');
    $hash=password_hash($password,PASSWORD_DEFAULT);$epoch=bin2hex(random_bytes(32));
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare('UPDATE users SET password_hash=? WHERE id=? AND password_hash=? AND is_active=1');$st->execute([$hash,$id,$row['password_hash']]);
        if($st->rowCount()!==1)throw new RuntimeException('Account changed during the update.');
        set_setting('auth.password_epoch.'.$id,$epoch);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function writer_account_route(string $path): bool {
    if($path!=='/write/account')return false;
    require_auth();private_response_headers();
    if(!in_array($_SERVER['REQUEST_METHOD']??'GET',['GET','HEAD','POST'],true)){http_response_code(405);return true;}
    if(is_post()){
        verify_csrf();
        try{
            writer_change_password((int)current_user()['id'],(string)($_POST['current_password']??''),(string)($_POST['new_password']??''),(string)($_POST['confirm_password']??''));
            $_SESSION=[];session_regenerate_id(true);flash('success','Your password has been changed. Sign in with your new password. Other Writer sessions have been signed out.');redirect(url('/write/login'));
        }catch(InvalidArgumentException $e){flash('error',$e->getMessage());}catch(Throwable $e){flash('error','Your password could not be changed. Please try again.');}
        redirect(url('/write/account'));
    }
    render_write('account',['writeTitle'=>'My account']);return true;
}
