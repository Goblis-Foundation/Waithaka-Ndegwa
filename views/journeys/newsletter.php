<link rel="stylesheet" href="<?=asset('css/reader.css')?>">
<section class="journey-hero"><div class="container journey-form-layout">
  <div><span class="eyebrow">Stay connected</span><h1>Follow the<br>next thought.</h1><p class="journey-lead">New writing, Startup Journeys and reflections from the work along the way, delivered to your inbox.</p><p>Confirm your email to join. You can leave at any time through the unsubscribe link in each edition.</p><div class="journey-rss"><span class="eyebrow">Prefer a feed reader?</span><p>Add the RSS feed to your reader to follow newly published articles.</p><a class="text-link" href="<?=e(url('/feed.xml'))?>">Open the RSS feed →</a></div></div>
  <div class="journey-panel"><h2>Subscribe by email</h2><?php require __DIR__.'/notices.php';?>
    <form method="post" action="<?=e(url('/newsletter'))?>" class="journey-form">
      <?=csrf_field()?>
      <div class="journey-honey" aria-hidden="true"><label for="newsletter-website">Leave this field empty</label><input id="newsletter-website" name="company_website" type="text" tabindex="-1" autocomplete="off"></div>
      <div class="newsletter-name-grid"><div class="field"><label for="newsletter-first-name">First name</label><input id="newsletter-first-name" name="first_name" type="text" maxlength="60" autocomplete="given-name" required value="<?=e(journeys_field($values,'first_name'))?>"></div><div class="field"><label for="newsletter-last-name">Last name</label><input id="newsletter-last-name" name="last_name" type="text" maxlength="59" autocomplete="family-name" required value="<?=e(journeys_field($values,'last_name'))?>"></div></div>
      <div class="field"><label for="newsletter-email">Email address</label><input id="newsletter-email" name="email" type="email" inputmode="email" autocapitalize="none" spellcheck="false" maxlength="190" autocomplete="email" required value="<?=e(journeys_field($values,'email'))?>"></div>
      <?php if (isset($preferenceChoices)): $choices = $preferenceChoices; require __DIR__.'/../reader/preference_fields.php'; endif; ?>
      <label class="journey-check"><input type="checkbox" name="subscribe_ack" value="1" required <?=journeys_checked($values['subscribe_ack']??null)?'checked':''?>><span>I would like to receive Waithaka Ndegwa’s writing by email. I have read the <a href="<?=e(url('/privacy'))?>">Privacy Policy</a>.</span></label>
      <button class="button" type="submit">Join My Thoughts →</button><p class="journey-help">Your subscription becomes active only after you use the confirmation link sent to your address.</p>
    </form><p class="journey-help">Already subscribed? <a href="<?=e(url('/newsletter/preferences/request'))?>">Manage topics, journeys or unsubscribe</a>. Asking for a management link does not resubscribe you.</p>
  </div>
</div></section>
