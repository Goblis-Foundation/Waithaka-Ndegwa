<link rel="stylesheet" href="<?=asset('css/reader.css')?>">
<section class="journey-hero"><div class="container reader-narrow"><span class="eyebrow">Private reader services</span><h1><?=$founder ? 'Continue a founder conversation.' : 'Choose the writing you receive.'?></h1>
<p><?=$founder ? 'Use the reference shown when you submitted and the same contact email. A private link lets you read editorial questions and respond.' : 'Enter the email you used to confirm your subscription. A private link lets you choose topics and journeys, or unsubscribe.'?></p>
<?php require __DIR__.'/../journeys/notices.php'; ?>
<form class="journey-panel journey-form" method="post" action="<?=e(url($requestPath))?>"><?=csrf_field()?>
  <div class="journey-honey" aria-hidden="true"><label>Leave empty<input name="company_website" tabindex="-1" autocomplete="off"></label></div>
  <?php if ($founder): ?><div class="field"><label for="reader-reference">Submission reference</label><input id="reader-reference" name="reference" maxlength="40" required autocomplete="off" value="<?=e(journeys_field($values, 'reference'))?>"></div><?php endif; ?>
  <div class="field"><label for="reader-email">Email address</label><input id="reader-email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?=e(journeys_field($values, 'email'))?>"></div>
  <button class="button" type="submit">Request private link</button><p class="journey-help">The response is the same whether or not a matching record exists. This request does not subscribe you or restore an unsubscribed or suppressed address.</p>
</form><p><a href="<?=e(url($founder ? '/founders/submit' : '/newsletter'))?>"><?=$founder ? 'Start a new founder submission' : 'Request a new subscription'?></a> · <a href="<?=e(url('/contact'))?>">Contact the editor</a></p></div></section>
