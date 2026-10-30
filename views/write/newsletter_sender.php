<link rel="stylesheet" href="<?=asset('css/newsletter.css')?>">
<main class="write-main nl-main" aria-labelledby="newsletter-sender-title">
  <header class="writer-head"><div><h1 id="newsletter-sender-title">Newsletter sender setup</h1><p class="nl-lead">Review the mailing address readers will see in subscriber emails. Save the real address you approve for that purpose.</p></div><a class="wbtn" href="<?=url('/write/monthly-roundup')?>">Monthly edition</a></header>
  <nav class="nl-nav" aria-label="Newsletter sender setup"><a href="<?=url('/write/newsletter')?>">All editions</a><a href="<?=url('/write/activation')?>">Launch readiness</a><a href="<?=url('/write/operations')?>">Delivery operations</a></nav>
  <?php foreach(flashes()as$f):?><p class="notice" role="status"><?=e($f['message'])?></p><?php endforeach;?>
  <?php if($error):?><p class="notice error" role="alert"><?=e($error)?></p><?php endif;?>
  <section class="nl-card" aria-labelledby="newsletter-sender-identity"><h2 id="newsletter-sender-identity">Current sending identity</h2><dl class="nl-meta"><dt>Sender</dt><dd><?=e($sender['name']?:'Not configured')?></dd><dt>From address</dt><dd><?=e($sender['from']?:'Not configured')?></dd><dt>Reply to</dt><dd><?=e($sender['reply_to']?:'Uses the From address')?></dd></dl><p class="nl-help">Changing the footer address does not connect a mailbox or verify inbox delivery. The sending identity and transport are configured separately.</p></section>
  <section class="nl-section nl-card" aria-labelledby="newsletter-postal-title"><h2 id="newsletter-postal-title">Subscriber-email footer address</h2>
    <form data-no-form-assist method="post" action="<?=url('/write/newsletter/sender')?>"><?=csrf_field()?>
      <input type="hidden" name="version" value="<?=(int)$senderPostalVersion?>">
      <div class="field"><label for="newsletter-postal-address">Approved mailing address</label><textarea id="newsletter-postal-address" name="postal_address" rows="4" maxlength="500" aria-describedby="newsletter-postal-help"><?=e($senderPostalValue)?></textarea><small id="newsletter-postal-help">Up to 500 characters. Use your approved postal or business mailing address. A blank value blocks newsletter approval until an address is supplied.</small></div>
      <label class="nl-check"><input type="checkbox" name="confirm_public_footer" value="1" required><span>I reviewed this address for the footer of subscriber emails. If I leave it blank, newsletter approval must remain blocked.</span></label>
      <button class="wbtn primary" type="submit">Save footer address</button>
    </form>
  </section>
  <section class="nl-section" aria-labelledby="newsletter-sender-after-save"><h2 id="newsletter-sender-after-save">After saving</h2><p>Review the monthly policy or edition again when its sender details change. Future delivery checks hold editions whose approved sender no longer matches. Messages already accepted by a provider cannot be recalled.</p><p>Saving this page sends no test message, subscribes no one and releases no pending mail. Keep inbox delivery, suppression checks and queue review as separate steps in <a href="<?=url('/write/operations')?>">Delivery operations</a>.</p></section>
</main>
