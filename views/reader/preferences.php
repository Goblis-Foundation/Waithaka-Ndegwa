<link rel="stylesheet" href="<?=asset('css/reader.css')?>">
<section class="journey-hero"><div class="container reader-narrow"><span class="eyebrow">Your private choices</span><h1>Email preferences</h1>
<?php require __DIR__.'/../journeys/notices.php'; ?>
<?php if ($validLink): ?>
<form class="journey-panel journey-form" method="post" action="<?=e(url('/newsletter/preferences'))?>"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>"><input type="hidden" name="action" value="save">
<?php require __DIR__.'/preference_fields.php'; ?>
<p class="journey-help">Choices apply to new editions. Changing them also skips editions already queued for your earlier choices. Saving cannot reactivate an unsubscribed or suppressed subscription.</p><button class="button" type="submit">Save choices</button>
</form>
<details class="journey-panel reader-space"><summary>Unsubscribe from every edition</summary><form class="journey-form" method="post" action="<?=e(url('/newsletter/preferences'))?>"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>"><input type="hidden" name="action" value="unsubscribe"><label class="journey-check"><input type="checkbox" name="unsubscribe_ack" value="1" required><span>Stop all newsletter editions for this subscription.</span></label><button class="button button-outline" type="submit">Unsubscribe</button></form></details>
<?php endif; ?><p><a href="<?=e(url('/newsletter/preferences/request'))?>">Request another private link</a></p></div></section>
