'use strict';
const {chromium}=require('playwright');const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
(async()=>{
 const root=path.resolve(__dirname,'..'),base='https://www.waithakandegwa.com';const data=JSON.parse(fs.readFileSync(path.join(root,'app/content/africa-opportunities-20261104.json'),'utf8'));
 const out=process.env.ARTICLE_EVIDENCE_DIR||'/tmp/opportunity-guides-evidence';fs.mkdirSync(out,{recursive:true});const results=[];
 const browser=await chromium.launch({headless:true});const context=await browser.newContext({reducedMotion:'reduce',serviceWorkers:'block'});
 await context.route('**/*',route=>{const r=route.request();return new URL(r.url()).origin===base&&['GET','HEAD'].includes(r.method())?route.continue():route.abort();});
 const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  for(const width of [390,1440]){
   await page.setViewportSize({width,height:1000});
   for(const [index,entry]of data.entries.entries()){
    const url=base+'/opportunities/'+entry.slug;const response=await page.goto(url,{waitUntil:'networkidle',timeout:45000});assert.equal(response.status(),200,url);
    const consent=page.locator('.cookie.show [data-consent="necessary"]');if(await consent.count())await consent.click();
    assert.equal(await page.locator('main h1').count(),1);assert.equal((await page.locator('main h1').innerText()).trim(),entry.title);
    const body=await page.locator('.article-body').innerText();const words=body.trim().split(/\s+/).length;assert.ok(words>=450,'Expanded guide '+entry.slug);
    for(const heading of ['About this opportunity','Is this a good fit for you?','Prepare before you apply','How to build a clearer application','Sources and official information'])assert.ok(body.includes(heading),'Guide section '+heading);
    assert.ok(body.includes('editorial guidance'),'Advice labelled');assert.ok(!body.includes('Research cut-off'));
    if(entry.notice)assert.ok((await page.locator('.curated-notice').innerText()).includes(entry.notice),'Existing limitation preserved');
    const support=page.locator('[data-opportunity-support]');assert.equal(await support.count(),1);assert.ok((await support.innerText()).includes('entirely voluntary'));assert.ok((await support.innerText()).includes('not an application fee'));
    assert.equal(await support.locator('a').getAttribute('href'),base+'/contribute');assert.equal(await support.locator('form,input').count(),0);
    const official=page.locator('.desk-cta a.desk-button.primary');assert.equal(await official.getAttribute('href'),entry.apply_url);assert.equal(await official.getAttribute('target'),'_blank');
    assert.equal(await page.locator('link[rel=canonical]').getAttribute('href'),url);
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);assert.ok(overflow<=2,'No overflow '+entry.slug+' '+width);
    const order=await page.evaluate(()=>!!(document.querySelector('.desk-cta').compareDocumentPosition(document.querySelector('[data-opportunity-support]'))&Node.DOCUMENT_POSITION_FOLLOWING));assert.ok(order,'Application action precedes optional support');
    if(index===0){await page.locator('.desk-article>header').screenshot({path:path.join(out,'header-'+width+'.png')});await support.screenshot({path:path.join(out,'support-'+width+'.png')});await page.screenshot({path:path.join(out,'article-'+width+'.png'),fullPage:true});}
    results.push({slug:entry.slug,width,status:response.status(),words,overflow,support:true});
   }
   const archive=await page.goto(base+'/opportunities',{waitUntil:'networkidle'});assert.equal(archive.status(),200);assert.equal(await page.locator('[data-opportunity-support]').count(),1);
   for(const e of data.entries)assert.ok(await page.locator('a[href$="/opportunities/'+e.slug+'"]').count()>0,'Archive retains '+e.slug);
   await page.locator('[data-opportunity-support]').screenshot({path:path.join(out,'directory-support-'+width+'.png')});
  }
  for(const width of [360,768,1024]){await page.setViewportSize({width,height:1000});await page.goto(base+'/opportunities/'+data.entries[0].slug,{waitUntil:'networkidle'});assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));}
  const contribution=await page.goto(base+'/contribute',{waitUntil:'networkidle'});assert.equal(contribution.status(),200,'Existing contribution journey accessible');
  await page.goto(base+'/partner-stories',{waitUntil:'networkidle'});assert.equal(await page.locator('[data-opportunity-support]').count(),0,'No request in paid-feature directory');
  const sitemap=await context.request.get(base+'/publishing-sitemap.xml');assert.equal(sitemap.status(),200);const xml=await sitemap.text();for(const e of data.entries)assert.ok(xml.includes('/opportunities/'+e.slug));
  await page.goto(base+'/',{waitUntil:'networkidle'});for(const e of data.entries)assert.equal(await page.locator('a[href$="/opportunities/'+e.slug+'"]').count(),0,'No homepage promotion');
  assert.deepEqual(errors,[],'No page JavaScript errors');
  fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,checkedAt:new Date().toISOString(),pages:16,results,providerLinksPreserved:true,voluntarySupport:true,noPaymentSubmitted:true},null,2));
  console.log('PASS: sixteen enriched live guides, provider/support separation, directory, canonical, sitemap and responsive layout.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,errors},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
