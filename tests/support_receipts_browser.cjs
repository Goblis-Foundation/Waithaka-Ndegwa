'use strict';
const {chromium}=require('playwright'),assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true});const context=await browser.newContext();const host='http://127.0.0.1:8125/';let writes=0;
 await context.route('**/*',r=>{if(r.request().method()!=='GET'){writes++;return r.abort();}return r.request().url().startsWith(host)?r.continue():r.abort();});
 await context.route('**/contribute/recent-support',r=>r.fulfill({json:{supporters:[{name:'Synthetic <img src=x onerror=alert(1)>',amount:'25.00',currency:'USD'},{name:'Anonymous',amount:'500.00',currency:'KES'}]}}));
 const page=await context.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(host+'support-contribute.html');
 await page.locator('[data-support-feed-list] li').first().waitFor();assert.equal(await page.locator('[data-support-feed-list] li').count(),2);assert.equal(await page.locator('[data-support-feed-list] img').count(),0);assert.ok(!(await page.locator('input[name=public_support_ack]').isChecked()));
 await page.getByRole('radio',{name:'Company / Institution',exact:true}).check();
 await page.getByLabel('Company or institution name',{exact:true}).fill('Synthetic Institute <img src=x onerror=alert(1)>');
 await page.getByLabel('Purpose',{exact:true}).selectOption('sponsorship');
 assert.equal(await page.locator('[data-receipt-name]').innerText(),'Synthetic Institute <img src=x onerror=alert(1)>');
 assert.equal(await page.locator('[data-receipt-name] img').count(),0);
 assert.equal(await page.locator('[data-receipt-purpose]').innerText(),'Sponsorship of independent writing');
 assert.equal(await page.getByLabel('Company or institution name',{exact:true}).getAttribute('required'),'');
 await page.getByRole('radio',{name:'Individual',exact:true}).check();
 await page.getByLabel('First name',{exact:true}).fill('Isaac');await page.getByLabel('Middle / family name (optional)',{exact:true}).fill('Waithaka');await page.getByLabel('Last name / surname',{exact:true}).fill('Ndegwa');
 assert.equal(await page.locator('[data-receipt-name]').innerText(),'Isaac Waithaka Ndegwa');
 await page.getByRole('radio',{name:'Anonymous',exact:true}).check();
 const sentFields=await page.locator('[data-contribution-form]').evaluate(f=>Array.from(new FormData(f).keys()));
 for(const key of ['organisation_name','supporter_first','supporter_last','receipt_address','receipt_email'])assert.ok(!sentFields.includes(key));
 await page.getByRole('radio',{name:'Company / Institution',exact:true}).check();
 await page.locator('.payment-tabs label').filter({has:page.locator('input[name=method][value=wise]')}).click();assert.equal(await page.locator('input[name=method][value=wise]').isChecked(),true);
 assert.ok(await page.getByRole('button',{name:'Create private bank reference'}).isVisible());
 assert.ok(await page.getByLabel('Company or institution name',{exact:true}).isEnabled());
 for(const file of ['support-contribute','support-monthly','support-receipt-0','support-receipt-1']){
  await page.goto(host+file+'.html');if(file==='support-contribute'||file==='support-monthly')await page.getByRole('radio',{name:'Company / Institution',exact:true}).check();
  for(const width of [320,390,768,1366]){await page.setViewportSize({width,height:900});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),file+' fits '+width);}
 }
 await page.goto(host+'support-receipt-0.html');assert.equal(await page.getByRole('button',{name:'Print / Save PDF'}).count(),0);assert.ok((await page.locator('main').innerText()).includes('not proof of payment'));
 await page.goto(host+'support-receipt-1.html');assert.ok(await page.getByRole('button',{name:'Print / Save PDF'}).isVisible());assert.equal(await page.locator('.support-receipt script').count(),0);assert.ok(!(await page.content()).includes('PRIVATE-ACCOUNTS@example.invalid'));
 await page.emulateMedia({media:'print'});assert.ok(await page.locator('.support-receipt').isVisible());assert.ok(!(await page.locator('.receipt-actions').first().isVisible()));
 assert.equal(writes,0);assert.deepEqual(errors,[]);await browser.close();console.log('PASS: payer choices, safe preview, anonymous field exclusion, bank flow, responsive receipts and print privacy. No submission or provider call.');
})().catch(e=>{console.error(e);process.exit(1);});
