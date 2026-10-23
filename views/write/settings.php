<main class="write-main">
  <div class="writer-head"><div><h1>Setup & integrations</h1><div class="muted">Occasional configuration. Your normal work stays in the focused posting screen.</div></div></div>
  <?php foreach(flashes() as $f):?><div class="notice <?=$f['type']==='error'?'error':''?>"><?=e($f['message'])?></div><?php endforeach;?>
  <form method="post" action="<?=url('/write/settings')?>">
    <?=csrf_field()?>
    <div class="settings-grid">
      <section class="settings-card">
        <h2>Contact & email</h2>
        <div class="field"><label>Contact recipient</label><input type="email" name="contact_recipient" value="<?=e(setting('contact.recipient',''))?>" required></div>
        <div class="field"><label>From email</label><input type="email" name="mail_from" value="<?=e(setting('mail.from','noreply@waithakandegwa.com'))?>"></div>
      </section>

      <section class="settings-card">
        <h2>Google</h2>
        <div class="field"><label for="google-ga4">GA4 Measurement ID</label><small>Requested site tag: G-RN0YXC2WCH. One shared tag covers public editorial pages. A blank ID turns measurement off.</small><input id="google-ga4" name="google_ga4" value="<?=e(setting('google.ga4','G-RN0YXC2WCH'))?>" placeholder="G-RN0YXC2WCH" pattern="G-[A-Z0-9]{4,20}"></div>
        <div class="field"><label><input type="checkbox" name="google_analytics_enabled" value="1" <?=setting('google.analytics_enabled','1')==='1'?'checked':''?>> Enable public-page analytics after reader consent</label><small>Writer, private links, searches, contact and payment forms are excluded. Advertising storage and personalisation remain off.</small></div>
        <div class="field"><label>Search Console verification token</label><input name="google_search_verification" value="<?=e(setting('google.search_verification',''))?>"></div>
        <div class="field"><label>reCAPTCHA site key</label><input name="recaptcha_site_key" value="<?=e(setting('recaptcha.site_key',''))?>"></div>
        <div class="field"><label>reCAPTCHA secret</label><input type="password" name="recaptcha_secret" placeholder="Leave blank to keep current secret"></div>
      </section>

      <section class="settings-card">
        <h2>OpenAI</h2>
        <div class="field"><label>API key</label><input type="password" name="openai_api_key" placeholder="Leave blank to keep current key"></div>
        <div class="field"><label>Model</label><input name="openai_model" value="<?=e(setting('openai.model','gpt-5.4-mini'))?>"></div>
        <p class="help">Used for automatic tags and SEO metadata. Published text is never rewritten automatically.</p>
      </section>

      <section class="settings-card">
        <h2>M-Pesa · Daraja</h2>
        <div class="field"><label>Environment</label><select name="mpesa_environment"><option value="sandbox" <?=setting('mpesa.environment','sandbox')==='sandbox'?'selected':''?>>Sandbox</option><option value="live" <?=setting('mpesa.environment','sandbox')==='live'?'selected':''?>>Live</option></select></div>
        <div class="field"><label>Consumer key</label><input type="password" name="mpesa_consumer_key" placeholder="Leave blank to keep current"></div>
        <div class="field"><label>Consumer secret</label><input type="password" name="mpesa_consumer_secret" placeholder="Leave blank to keep current"></div>
        <div class="field"><label>Shortcode</label><input name="mpesa_shortcode" value="<?=e(setting('mpesa.shortcode',''))?>"></div>
        <div class="field"><label>Passkey</label><input type="password" name="mpesa_passkey" placeholder="Leave blank to keep current"></div>
        <div class="field"><label>Transaction type</label><select name="mpesa_transaction_type"><option value="CustomerPayBillOnline" <?=setting('mpesa.transaction_type','CustomerPayBillOnline')==='CustomerPayBillOnline'?'selected':''?>>PayBill</option><option value="CustomerBuyGoodsOnline" <?=setting('mpesa.transaction_type','')==='CustomerBuyGoodsOnline'?'selected':''?>>Buy Goods</option></select></div>
      </section>

      <section class="settings-card">
        <h2>Pesapal · Cards</h2>
        <div class="field"><label>Environment</label><select name="pesapal_environment"><option value="sandbox" <?=setting('pesapal.environment','sandbox')==='sandbox'?'selected':''?>>Sandbox</option><option value="live" <?=setting('pesapal.environment','sandbox')==='live'?'selected':''?>>Live</option></select></div>
        <div class="field"><label>Consumer key</label><input type="password" name="pesapal_consumer_key" placeholder="Leave blank to keep current"></div>
        <div class="field"><label>Consumer secret</label><input type="password" name="pesapal_consumer_secret" placeholder="Leave blank to keep current"></div>
        <div class="field"><label>IPN ID</label><input name="pesapal_ipn_id" value="<?=e(setting('pesapal.ipn_id',''))?>"></div>
        <button class="wbtn" type="submit" name="action" value="register_ipn">Save & register IPN</button>
        <p class="help">The public website presents this route as Card with Visa, Mastercard and American Express branding. Card support can request KES, USD, EUR or GBP. Pesapal requires either email or phone for the transaction request; names are optional and anonymous site records do not retain that contact as contributor identity. Pesapal controls the methods shown on its hosted checkout; if it also displays M-Pesa, ask Pesapal whether card-only checkout can be enabled for your merchant account.</p>
      </section>

      <section class="settings-card">
        <h2>PayPal</h2>
        <div class="field"><label>Environment</label><select name="paypal_environment"><option value="sandbox" <?=setting('paypal.environment','sandbox')==='sandbox'?'selected':''?>>Sandbox</option><option value="live" <?=setting('paypal.environment','sandbox')==='live'?'selected':''?>>Live</option></select></div>
        <div class="field"><label>Client ID</label><input type="password" name="paypal_client_id" placeholder="Leave blank to keep current"></div>
        <div class="field"><label>Client secret</label><input type="password" name="paypal_secret" placeholder="Leave blank to keep current"></div>
        <p class="help">PayPal uses server-side OAuth 2.0 and the Orders API. The public PayPal contribution amounts are in USD.</p>
      </section>
    </div>
    <div style="margin-top:18px"><button class="wbtn primary" type="submit" name="action" value="save">Save settings</button></div>
  </form>
</main>
