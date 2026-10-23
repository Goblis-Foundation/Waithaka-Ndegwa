<main class="write-main narrow"><h1>My account</h1><p>Signed in as <strong><?=e(current_user()['email'])?></strong>.</p>
<?php foreach(flashes()as$f):?><div class="notice <?=$f['type']==='error'?'error':''?>"><?=e($f['message'])?></div><?php endforeach;?>
<section class="login-card"><h2>Change your password</h2><p>Use your initial password for the first change. Choose a unique passphrase of at least 14 characters. After saving, sign in again using your new password.</p>
<form method="post" action="<?=url('/write/account')?>"><?=csrf_field()?>
<div class="field"><label for="current-password">Current password</label><input id="current-password" type="password" name="current_password" autocomplete="current-password" required></div>
<div class="field"><label for="new-password">New password</label><input id="new-password" type="password" name="new_password" autocomplete="new-password" minlength="14" required><small>Maximum 72 bytes; multi-byte characters can use more than one byte.</small></div>
<div class="field"><label for="confirm-password">Confirm new password</label><input id="confirm-password" type="password" name="confirm_password" autocomplete="new-password" minlength="14" required></div>
<button class="wbtn primary" type="submit">Change password and sign out</button></form></section></main>
