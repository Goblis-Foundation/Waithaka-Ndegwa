<section class="section"><div class="container journey-action-wrap"><div class="journey-panel">
  <span class="eyebrow">Your email subscription</span><h1><?=$purpose==='confirm'?'One more step.':'Your inbox. Your choice.'?></h1>
  <?php require __DIR__.'/notices.php';?>
  <?php if($record && !$error):?>
    <p><?=$purpose==='confirm'?'Confirm below to receive new writing and Startup Journeys from Waithaka Ndegwa.':'Confirm below to stop receiving newsletter editions. This also cancels a pending subscription request.'?></p>
    <form method="post" action="<?=e(url('/newsletter/'.$purpose))?>" class="journey-form"><?=csrf_field()?><input type="hidden" name="token" value="<?=e($token)?>"><?php if($purpose==='unsubscribe'):?><label class="journey-check"><input type="checkbox" name="email_receipt" value="1"><span>Email me a one-time confirmation of this change (optional).</span></label><p class="journey-help">Unsubscribing is immediate. No sign-in, reason or confirmation email is required.</p><?php endif;?><button class="button" type="submit"><?=$purpose==='confirm'?'Confirm my subscription':'Unsubscribe'?></button></form>
  <?php else:?><a class="button" href="<?=e(url('/newsletter'))?>">Return to subscriptions →</a><?php endif;?>
  <p class="journey-help"><a href="<?=e(url('/contact'))?>">Contact me</a> if you need help with your subscription.</p>
</div></div></section>
