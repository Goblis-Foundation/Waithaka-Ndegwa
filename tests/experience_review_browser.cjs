'use strict';
const {chromium}=require('playwright');
(async()=>{
 const browser=await chromium.launch({headless:true});let checks=0;const errors=[];
 const check=(ok,why)=>{if(!ok)throw new Error(why);checks++;};
 const context=await browser.newContext({viewport:{width:390,height:900},reducedMotion:'reduce'});
 await context.route('**/*',route=>route.request().url().startsWith('http://127.0.0.1:8125/')?route.continue():route.abort());
 const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 await page.goto('http://127.0.0.1:8125/workspace.html');
 // A new isolated form is attached before re-running the idempotent per-form enhancement.
 await page.evaluate(()=>{
  const form=document.createElement('form');form.id='review-regression';form.method='post';
  form.innerHTML='<label for="review-name">Name</label><input id="review-name" name="name" value="Fixture reader" required>'+ 
   '<details id="review-extra"><summary>Optional contact details</summary><label for="review-area">Town/Area</label><input id="review-area" name="city" value="Endarasha"><details id="review-nested"><summary>Other details</summary><label for="review-notes">Notes</label><textarea id="review-notes" name="notes">PRIVATE-FIXTURE-ONLY</textarea></details></details>'+ 
   '<label for="review-interests">Topics</label><select id="review-interests" name="topics[]" multiple><option value="agriculture" selected>Agriculture</option><option value="education" selected>Education</option></select>'+ 
   '<label for="review-email">Email</label><input id="review-email" type="email" name="email" required>'+ 
   '<label><input type="checkbox" name="approval" required>Confirm this request</label>'+ 
   '<label for="review-file">File</label><input id="review-file" type="file" name="evidence">'+ 
   '<fieldset disabled><input name="inactive" value="DISABLED-PRIVATE-MARKER" required></fieldset>'+ 
   '<div hidden><input name="region" value="INACTIVE-COUNTRY-MARKER"></div>'+ 
   '<div style="display:none"><input name="css_hidden" value="CSS-HIDDEN-MARKER"></div>'+ 
   '<input type="hidden" name="csrf" value="CAPABILITY-MARKER"><input name="access_token" value="SECRET-MARKER">'+ 
   '<button type="submit">Original final action</button>';
  form.addEventListener('submit',e=>{e.preventDefault();window.__fixtureSubmits=(window.__fixtureSubmits||0)+1;});
  document.body.append(form);
 });
 await page.addScriptTag({url:'http://127.0.0.1:8125/assets/js/unified-experience.js'});
 const form=page.locator('#review-regression'),open=()=>form.locator('.ux-form-assist .ux-button').click();
 await open();let text=await page.locator('dialog[open] .ux-entry-review').innerText();
 check(text.includes('Endarasha')&&text.includes('PRIVATE-FIXTURE-ONLY'),'Collapsed and nested optional values are included, not dropped');
 check(text.includes('Agriculture, Education'),'All selected topics are retained');
 check(text.includes('Required — not completed')&&text.includes('Required — not confirmed'),'Missing required entries are explained without submitting');
 for(const marker of ['DISABLED-PRIVATE-MARKER','INACTIVE-COUNTRY-MARKER','CSS-HIDDEN-MARKER','CAPABILITY-MARKER','SECRET-MARKER'])check(!text.includes(marker),'Excluded private/inactive field: '+marker);
 check((await form.locator('.ux-form-progress').innerText()).startsWith('1 of 3'),'Disabled required fields do not inflate completion requirements');
 await page.locator('dialog[open] [data-ux-entry-edit="city"]').click();
 await page.waitForFunction(()=>document.activeElement?.id==='review-area');
 check(await page.locator('dialog[open]').count()===0&&await page.locator('#review-extra').getAttribute('open')!==null,'Edit closes the review and reveals its original optional section');
 await page.locator('#review-area').fill('Nyeri Town');await page.evaluate(()=>document.querySelector('#review-extra').open=false);await open();
 check((await page.locator('dialog[open] .ux-entry-review').innerText()).includes('Nyeri Town'),'Review reflects the edited field after its section is collapsed again');
 await page.locator('dialog[open] [data-ux-entry-edit="notes"]').click();await page.waitForFunction(()=>document.activeElement?.id==='review-notes');
 check(await page.locator('#review-extra').getAttribute('open')!==null&&await page.locator('#review-nested').getAttribute('open')!==null,'Edit opens all enclosing optional sections');
 await page.locator('#review-notes').fill('<img src=x onerror="window.__BAD=true">');await open();
 check(await page.locator('dialog[open] .ux-entry-review img').count()===0&&!await page.evaluate(()=>Boolean(window.__BAD)),'Review text never becomes executable HTML');
 check(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Review remains usable at mobile width');
 await page.keyboard.press('Escape');
 check(await page.evaluate(()=>!window.__fixtureSubmits),'Preview, edit and closing cause no form submission');
 await page.locator('.ux-table-filter input').first().fill('Beta');check(await page.locator('tbody tr:visible').count()===1,'Page-local table filtering preserved');
 await page.locator('.ux-table-filter .ux-button').first().click();check(await page.locator('tbody tr:visible').count()>=5,'Clear restores the filtered page rows');
 await page.locator('.ux-table-filter input').first().fill('Beta');await page.locator('.ux-table-filter input').first().press('Escape');check(await page.locator('tbody tr:visible').count()>=5,'Escape clears local filtering without a mutation');
 check(errors.length===0,'No browser errors: '+errors.join('; '));
 console.log('PASS: '+checks+' shared-journey review, privacy, required-field, mobile, edit/focus and filtering regressions. Synthetic fixture only; no email, charge, publishing or provider call.');
 await browser.close();
})().catch(error=>{console.error(error);process.exit(1);});
