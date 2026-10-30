'use strict';
const {chromium}=require('playwright'),assert=require('node:assert/strict'),fs=require('node:fs');
(async()=>{
 const browser=await chromium.launch({headless:true});const context=await browser.newContext({viewport:{width:1363,height:900},reducedMotion:'reduce'});
 await context.route('**/*',route=>route.request().url().startsWith('http://127.0.0.1:8125/')?route.continue():route.abort());
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));let checks=0;const ok=(v,m)=>{assert.ok(v,m);checks++;};
 const host='http://127.0.0.1:8125/';const layoutFailures=[];
 for(const key of ['login','login-error','program','desk-0','desk-1','impact','register','google-actions','google-new','google-review','google-sync','google-meet','google-meet-select','google-meet-saved','youtube','youtube-playlist','youtube-playlist_item','youtube-caption','youtube-thumbnail','youtube-moderate','youtube-review','youtube-pending','publishing-studio','instagram-reel-new','instagram-reel-confirmed','verify','auth-settings','directory']){
  await page.goto(host+'module-'+key+'.html');
  if(key.startsWith('instagram-reel-'))await page.locator('main details').evaluate(e=>{e.open=true;});
  ok(await page.locator('main').count()===1,key+' has exactly one main landmark');
  ok(await page.locator('h1').count()===1,key+' has one page heading');
  for(const width of [320,360,390,600,760,900,1024,1180,1363,1440]){
   await page.setViewportSize({width,height:900});
   await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
   const bad=await page.evaluate(()=>({overflow:document.documentElement.scrollWidth-innerWidth,controls:[...document.querySelectorAll('main input,main button,main select')].filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&r.height>0&&getComputedStyle(e).visibility!=='hidden'&&(r.left< -2||r.right>innerWidth+2);}).map(e=>e.name||e.textContent.slice(0,45))}));
   if(bad.overflow>2||bad.controls.length){const elements=await page.evaluate(()=>[...document.querySelectorAll('body *')].filter(e=>{const r=e.getBoundingClientRect();return r.width>0&&r.right>innerWidth+2;}).slice(0,12).map(e=>({tag:e.tagName,cls:e.className,text:e.textContent.slice(0,70),right:e.getBoundingClientRect().right})));layoutFailures.push({key,width,...bad,elements});}else checks+=2;
  }
 }
 ok(layoutFailures.length===0,'Module layouts: '+JSON.stringify(layoutFailures));
 await page.goto(host+'module-publishing-studio.html');
 ok(await page.locator('[data-studio-channel]').count()===10,'All ten channel editors render');
 await page.locator('#text-x').fill('<script>literal preview</script>');
 ok(await page.locator('#studio-x [data-preview-copy]').textContent()==='<script>literal preview</script>','Preview treats source as literal text');
 ok(await page.locator('#studio-x [data-preview-copy] script').count()===0,'Preview cannot execute caption HTML');
 await page.locator('#studio-x [data-channel-enabled]').check();
 ok((await page.locator('[data-studio-summary]').textContent()).includes('1 channel selected'),'Selection summary updates');
 await page.locator('#format-facebook').selectOption('reel');
 ok((await page.locator('#studio-facebook [data-preview-meta]').textContent()).includes('Reel'),'Format preview updates');
 await page.locator('#facebook-audience').selectOption('subscribers');
 ok(await page.locator('#facebook-audience').inputValue()==='subscribers','Connect audience remains an explicit saved field');
 // Dismiss the unsaved-edit guard only in this isolated test fixture.
 page.once('dialog',dialog=>dialog.accept());
 await page.goto(host+'module-google-meet-select.html');
 ok(await page.locator('input[name=confirm][required]').count()===1,'Meet import requires explicit retention consent');
 ok(await page.locator('form:has(input[value=import]) input[name=fingerprint]').count()===1,'Meet import binds the reviewed account');
 await page.goto(host+'module-google-meet-saved.html');
 ok(await page.locator('pre').innerText().then(t=>t.includes('<script>Literal untrusted transcript content</script>')),'Meet transcript is rendered as literal text');
 await page.goto(host+'module-instagram-reel-new.html');
 ok(!await page.locator('[name="variants[instagram][instagram_share_to_feed]"]').isChecked(),'Reel feed sharing starts unchecked');
 ok(!await page.locator('[name="variants[instagram][instagram_settings_confirmed]"]').isChecked(),'No silent Reel audience confirmation');
 await page.goto(host+'module-instagram-reel-confirmed.html');
 ok(await page.locator('[name="variants[instagram][instagram_share_to_feed]"]').isChecked(),'Explicit Reel feed choice retained');
 ok(await page.locator('[name="variants[instagram][instagram_settings_confirmed]"]').isChecked(),'Explicit Reel review retained');
 await page.goto(host+'module-youtube-review.html');
 ok(await page.locator('main').innerText().then(t=>t.includes('<script>Literal untrusted comment</script>')),'YouTube comment is rendered as literal text');
 ok(await page.locator('form:has(input[value=apply]) input[name=confirm][required]').count()===1,'YouTube apply requires exact action approval');
 ok(await page.locator('main').innerText().then(t=>t.includes('cannot be undone here')),'Comment rejection explains its effect');
 await page.goto(host+'module-youtube-pending.html');
 ok(await page.getByRole('button',{name:'Read existing result'}).count()===1,'Pending captions expose read-only recheck');
 ok(await page.getByRole('button',{name:'Apply to YouTube',exact:true}).count()===0,'Pending action cannot be resent');
 await page.goto(host+'module-youtube-caption.html');
 ok(await page.getByLabel('Visibility',{exact:true}).inputValue()==='1','New caption defaults to hidden draft');
 ok(await page.locator('input[name=content_consent][required]').count()===1,'Preparation needs selected-content consent');
 await page.goto(host+'module-login-error.html');
 ok(await page.getByLabel('Writer email',{exact:true}).inputValue()==='fixture@example.invalid','Email retained after rejection');
 ok(!await page.locator('[name=trust_device]').isChecked(),'Shared-device choice retained after rejection');
 ok(await page.getByRole('alert').innerText()==='Email or password is incorrect.','Generic error announced');
 const password=page.getByLabel('Password',{exact:true});await password.fill('synthetic-fixture-only');
 await page.getByRole('button',{name:'Show',exact:true}).click();ok(await password.getAttribute('type')==='text','Show password');
 await page.getByRole('button',{name:'Hide',exact:true}).click();ok(await password.getAttribute('type')==='password','Hide password');
 ok(await password.getAttribute('autocomplete')==='current-password','Password manager support');
 // Cancelled passkey requests must be recoverable; duplicate activations must not overwrite a challenge.
 let requests=0;
 await page.route('**/write/passkey',async route=>{requests++;await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,options:{publicKey:{challenge:'AQID',allowCredentials:[]}}})});});
 await page.evaluate(()=>{Object.defineProperty(navigator.credentials,'get',{configurable:true,value:()=>Promise.reject(new DOMException('fixture','NotAllowedError'))});document.querySelector('[data-passkey-login]').click();document.querySelector('[data-passkey-login]').click();});
 await page.locator('[data-security-status]').filter({hasText:'cancelled or timed out'}).waitFor();
 ok(requests===1,'Only one passkey challenge is requested while busy');ok(await page.locator('[data-passkey-login]').isEnabled(),'Passkey retry restored');
 await page.goto(host+'module-register.html');
 const total=await page.locator('[data-register-item]').count();ok(total>=28,'Existing implementation inventory preserved');
 await page.locator('[data-register-search]').fill('monthly newsletter');ok(await page.locator('[data-register-item]:visible').count()>0,'Search finds the monthly newsletter commitment');
 await page.locator('[data-register-search]').fill('no-such-work-area-xyz');ok(await page.locator('[data-register-empty]').isVisible(),'No-results feedback');
 await page.locator('[data-register-search]').fill('');await page.locator('[data-register-filter]').selectOption('followup');
 for(const n of [70,71,72,73,74,75,76])ok(await page.locator('[data-register-item]:visible a[href$="/issues/'+n+'"]').count()>0,'Open issue '+n+' is retained in follow-up');
 const bare=await browser.newContext({javaScriptEnabled:false});await bare.route('**/*',route=>route.request().url().startsWith(host)?route.continue():route.abort());const fallback=await bare.newPage();await fallback.goto(host+'module-login.html');
 ok(await fallback.locator('[data-login-submit]').isVisible(),'Password sign-in works without JS');ok(!await fallback.locator('[data-passkey-area]').isVisible(),'No dead passkey action without JS');ok(!await fallback.locator('[data-password-toggle]').isVisible(),'No dead show/hide control without JS');
 await fallback.close();await bare.close();ok(errors.length===0,'No runtime errors: '+errors.join(';'));
 if(process.env.EXPERIENCE_EVIDENCE_DIR){fs.mkdirSync(process.env.EXPERIENCE_EVIDENCE_DIR,{recursive:true});await page.goto(host+'module-login.html');for(const width of [390,1180]){await page.setViewportSize({width,height:900});await page.screenshot({path:process.env.EXPERIENCE_EVIDENCE_DIR+'/writer-login-'+width+'.png',fullPage:true});}}
 console.log('PASS: '+checks+' module, login, passkey, no-drop register and progressive-enhancement checks.');await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
