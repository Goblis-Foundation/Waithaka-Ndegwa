<?php
$status=$contribution['status']??null;
$methodLabels=['mpesa'=>'M-Pesa','pesapal'=>'Card via Pesapal','paypal'=>'PayPal'];
?>
<section class="support-hero">
  <div class="container support-hero-grid">
    <div data-reveal><span class="eyebrow">Support My Thoughts</span><h1>If the writing is useful to you, you can help create room for more of it.</h1><p>This is personal, independent writing. Support is voluntary, can be anonymous, and never buys access, influence or a particular conclusion.</p></div>
    <aside data-reveal><strong>Quiet support is still support.</strong><p>You do not need to add your name. M-Pesa and Pesapal still need the minimum transaction detail required to process payment, but anonymous card support is not saved here as a named contributor record.</p></aside>
  </div>
</section>

<section class="section surface support-main"><div class="container support-layout">
  <div class="support-context" data-reveal><span class="eyebrow">Choose what works for you</span><h2>Simple, secure and deliberately low-pressure.</h2><div class="support-method-summary"><div><img src="<?=asset('images/payments/mpesa.svg')?>" alt="M-Pesa"><span><strong>M-Pesa</strong><small>Kenya · KES</small></span></div><div><span class="card-mini"><img src="<?=asset('images/payments/visa.svg')?>" alt="Visa"><img src="<?=asset('images/payments/mastercard.svg')?>" alt="Mastercard"><img src="<?=asset('images/payments/amex.svg')?>" alt="American Express"></span><span><strong>Card</strong><small>Pesapal · KES / USD / EUR / GBP</small></span></div><div><img src="<?=asset('images/payments/paypal.svg')?>" alt="PayPal"><span><strong>PayPal</strong><small>International · USD</small></span></div><div><span aria-hidden="true">↗</span><span><strong>Wise bank transfer</strong><small>EUR · SEPA / USD · ACH / GBP · UK</small></span></div></div><div class="support-safety"><strong>Your payment credentials stay with the payment provider.</strong><p>This website keeps transaction references and status for reconciliation. It does not store card numbers, M-Pesa PINs or PayPal passwords.</p></div></div>

  <div class="support-form-card" data-reveal>
    <?php foreach(flashes() as $f):?><div class="notice <?=$f['type']==='error'?'error':($f['type']==='info'?'info':'')?>"><?=e($f['message'])?></div><?php endforeach;?>

    <?php if($contribution):?>
      <section class="contribution-status <?=e($status)?>" data-contribution-status data-reference="<?=e($contribution['reference'])?>" data-status="<?=e($status)?>">
        <div class="status-icon" aria-hidden="true"><?=($status==='completed'?'✓':($status==='failed'||$status==='cancelled'?'×':'…'))?></div>
        <span class="eyebrow"><?=($status==='completed'?'Thank you':($status==='failed'||$status==='cancelled'?'Not completed':'Confirming'))?></span>
        <?php if($status==='completed'):?>
          <h2>Thank you for backing the thinking.</h2>
          <p>Your support gives me more room to read, travel, observe, ask better questions and take the time to write carefully. You did not have to contribute, and I genuinely appreciate that you chose to.</p>
          <p class="status-note">If you contributed anonymously, that is completely fine. There is no public donor list and no recognition is expected.</p>
        <?php elseif($status==='pending'||$status==='initiated'):?>
          <h2>Thank you — your contribution is being confirmed.</h2>
          <p><?=($contribution['method']==='mpesa'?'Complete the M-Pesa prompt on your phone if it is still open. This page will update automatically once confirmation arrives.':'The payment provider is still confirming the transaction. This page will update automatically when the status changes.')?></p>
          <div class="status-live"><i></i><span>Waiting for confirmation</span></div>
        <?php else:?>
          <h2>The contribution was not completed.</h2>
          <p>No problem. You can try again below or simply return to My Thoughts.</p>
        <?php endif;?>
        <div class="status-meta"><span><?=e($methodLabels[$contribution['method']]??ucfirst($contribution['method']))?></span><span><?=e($contribution['currency'])?> <?=number_format((float)$contribution['amount'],2)?></span><span>Ref <?=e($contribution['reference'])?></span></div>
        <div class="button-row"><a class="button button-primary" href="<?=url('/my-thoughts')?>">Back to My Thoughts</a><?php if(in_array($status,['failed','cancelled'],true)):?><a class="button button-secondary" href="<?=url('/contribute')?>">Try again</a><?php endif;?></div>
      </section>
    <?php endif;?>

    <?php if(!$contribution || in_array($status,['failed','cancelled'],true)):?>
    <form method="post" action="<?=url('/contribute')?>" data-contribution-form><?=csrf_field()?>
      <div class="field"><label>Payment method</label><div class="payment-tabs"><label><input type="radio" name="method" value="mpesa" checked><span><img src="<?=asset('images/payments/mpesa.svg')?>" alt="M-Pesa"><small>KES</small></span></label><label><input type="radio" name="method" value="pesapal"><span><strong>Card</strong><span class="card-logos"><img src="<?=asset('images/payments/visa.svg')?>" alt="Visa"><img src="<?=asset('images/payments/mastercard.svg')?>" alt="Mastercard"><img src="<?=asset('images/payments/amex.svg')?>" alt="American Express"></span><small>Pesapal</small></span></label><label><input type="radio" name="method" value="paypal"><span><img src="<?=asset('images/payments/paypal.svg')?>" alt="PayPal"><small>USD</small></span></label><a class="wise-method" href="#wise-bank-transfer"><strong>Wise bank transfer</strong><small>EUR / USD / GBP</small></a></div></div>

      <div data-currency-panel="kes"><div class="field"><label>Amount · KES</label><div class="amount-grid"><?php foreach([250,500,1000,2500,5000] as $a):?><label><input type="radio" name="amount_choice_kes" value="<?=$a?>" <?=$a===1000?'checked':''?>><span><?=number_format($a)?></span></label><?php endforeach;?><label><input type="radio" name="amount_choice_kes" value="other"><span>Other</span></label></div></div><div class="field"><label for="amount_kes">Custom KES amount</label><input id="amount_kes" name="amount_kes" type="number" min="50" max="1000000" step="1" placeholder="e.g. 750"></div></div>

      <div data-currency-panel="card" hidden>
        <div class="currency-picker"><div><label for="card_currency">Card currency</label><select id="card_currency" name="card_currency" data-card-currency><option value="KES">KES · Kenyan Shilling</option><option value="USD">USD · US Dollar</option><option value="EUR">EUR · Euro</option><option value="GBP">GBP · British Pound</option></select></div><p>Choose the currency you want Pesapal to request. Your card issuer or payment provider may apply its own conversion where necessary.</p></div>
        <div class="field"><label>Amount · <span data-card-currency-label>KES</span></label><div class="amount-grid" data-card-presets><?php foreach([250,500,1000,2500,5000] as $a):?><label><input type="radio" name="amount_choice_card" value="<?=$a?>" <?=$a===1000?'checked':''?>><span><?=number_format($a)?></span></label><?php endforeach;?><label><input type="radio" name="amount_choice_card" value="other"><span>Other</span></label></div></div>
        <div class="field"><label for="amount_card">Custom <span data-card-currency-label>KES</span> amount</label><input id="amount_card" name="amount_card" type="number" min="1" max="1000000" step="0.01" placeholder="e.g. 1000"></div>
      </div>

      <div data-currency-panel="usd" hidden><div class="field"><label>Amount · USD</label><div class="amount-grid"><?php foreach([5,10,25,50,100] as $a):?><label><input type="radio" name="amount_choice_usd" value="<?=$a?>" <?=$a===10?'checked':''?>><span>$<?=number_format($a)?></span></label><?php endforeach;?><label><input type="radio" name="amount_choice_usd" value="other"><span>Other</span></label></div></div><div class="field"><label for="amount_usd">Custom USD amount</label><input id="amount_usd" name="amount_usd" type="number" min="1" max="10000" step="0.01" placeholder="e.g. 15"></div></div>

      <div class="payment-detail" data-method-fields="mpesa"><div class="payment-detail-head"><img src="<?=asset('images/payments/mpesa.svg')?>" alt="M-Pesa"><span>STK Push</span></div><div class="field"><label for="mpesa_phone">M-Pesa number</label><input id="mpesa_phone" name="mpesa_phone" type="tel" required placeholder="07XX XXX XXX" autocomplete="tel"></div><p>The phone number is required only to send the M-Pesa prompt.</p></div>

      <div class="payment-detail" data-method-fields="pesapal" hidden>
        <div class="payment-detail-head card-detail"><div><strong>Card payment</strong><small>Securely processed by <img src="<?=asset('images/payments/pesapal.svg')?>" alt="Pesapal"></small></div><span class="card-logos"><img src="<?=asset('images/payments/visa.svg')?>" alt="Visa"><img src="<?=asset('images/payments/mastercard.svg')?>" alt="Mastercard"><img src="<?=asset('images/payments/amex.svg')?>" alt="American Express"></span></div>
        <label class="anonymous-choice"><input type="checkbox" name="anonymous" value="1" checked data-anonymous-toggle><span><strong>Keep this contribution anonymous</strong><small>Your name is optional and there is no public supporter list.</small></span></label>
        <div class="payment-contact"><strong>One transaction contact is required by Pesapal.</strong><p>Choose either an email address or phone number. If anonymous is selected, this website sends it to Pesapal for checkout but does not retain it as your contributor identity.</p><div class="payment-contact-row"><select name="card_contact_type" data-card-contact-type aria-label="Payment contact type"><option value="email">Email</option><option value="phone">Phone</option></select><input name="card_payment_contact" data-card-payment-contact type="email" autocomplete="email" placeholder="you@example.com" required></div></div>
        <div class="optional-identity" data-optional-identity hidden><div class="form-grid"><div class="field"><label>First name <span class="optional">optional</span></label><input name="card_first_name" maxlength="120" autocomplete="given-name"></div><div class="field"><label>Last name <span class="optional">optional</span></label><input name="card_last_name" maxlength="120" autocomplete="family-name"></div></div></div>
        <p>Card details are entered on Pesapal's hosted checkout, not on this website. Pesapal may request other information it needs to process the card.</p>
      </div>

      <div class="payment-detail" data-method-fields="paypal" hidden><div class="payment-detail-head"><img src="<?=asset('images/payments/paypal.svg')?>" alt="PayPal"><span>PayPal checkout</span></div><p>You will be redirected to PayPal to approve the contribution. This website does not ask for your PayPal account details.</p></div>

      <label class="check acknowledgement"><input type="checkbox" name="contribution_acknowledgement" value="1" required><span>I understand this is a voluntary contribution and not a charitable donation, investment, purchase of goods or payment for a promised service.</span></label>
      <button class="button button-primary submit-button" type="submit">Continue securely</button>
    </form>
    <?php include __DIR__.'/wise_giving.php';?>
    <div class="support-terms"><span>Questions about how contributions are handled?</span><a href="<?=url('/contribution-terms')?>">Read contribution terms →</a></div>
    <?php endif;?>
  </div>
</div></section>
