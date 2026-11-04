'use strict';
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
(async()=>{
 const live=process.env.STORY_LIVE==='1';
 const base=live?'https://www.waithakandegwa.com':'http://127.0.0.1:8125';
 const articlePath=live?'/my-thoughts/impact-driven-inspiration':'/story-article.html';
 const out=process.env.STORY_EVIDENCE_DIR||'/tmp/story-evidence';fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce'});
 const guard=async route=>{const req=route.request();const u=new URL(req.url());return u.origin===base&&['GET','HEAD'].includes(req.method())?route.continue():route.abort();};
 await context.route('**/*',guard);
 const page=await context.newPage();const errors=[];page.on('pageerror',error=>errors.push(error.message));
 const results=[];
 try{
  for(const width of [360,390,768,1024,1440]){
   await page.setViewportSize({width,height:1000});
   const response=await page.goto(base+articlePath,{waitUntil:'networkidle'});
   assert.equal(response.status(),200,'Article returns HTTP 200');
   // This isolated test browser declines analytics; it never changes a user's preferences.
   const consent=page.locator('[data-consent="necessary"]');if(await consent.isVisible())await consent.click();
   assert.equal(await page.locator('[data-inaugural-story]').count(),1,'Actual redesigned article is present');
   assert.equal(await page.locator('main h1').count(),1,'Single article H1');
   assert.equal((await page.locator('main h1').textContent()).replace(/\s+/g,' ').trim(),'Impact-Driven Inspiration: My Journey, Purpose and an Invitation to Connect','Original title preserved');
   assert.equal(await page.locator('.story-chapter').count(),8,'All original chapters are present');
   assert.equal(await page.locator('[data-page-outline]').count(),1,'No duplicate page navigation is injected');
   assert.equal(await page.locator('.story-photo img').count(),5,'Five selected original photographs');
   const imageData=[];
   for(const img of await page.locator('.story-photo img').all()){
    await img.scrollIntoViewIfNeeded();
    await img.evaluate(el=>el.decode());
    const data=await img.evaluate(el=>{const r=el.getBoundingClientRect(),s=getComputedStyle(el);return {src:el.currentSrc,width:r.width,height:r.height,naturalWidth:el.naturalWidth,naturalHeight:el.naturalHeight,fit:s.objectFit,alt:el.alt,left:r.left,right:r.right};});
    assert.ok(data.naturalWidth>0,'Photo loads successfully');
    assert.equal(data.fit,'contain','Full photograph rather than cover cropping');
    assert.ok(Math.abs(data.width/data.height-data.naturalWidth/data.naturalHeight)<.02,'Original photo proportions');
    assert.ok(data.left>=-1&&data.right<=width+1,'Photo stays inside the viewport');
    assert.ok(data.alt.length>15,'Meaningful photo alternative text');imageData.push(data);
   }
   const nav=await page.locator('[data-story-link]').evaluateAll(links=>links.map(a=>({href:a.getAttribute('href'),exists:!!document.getElementById(a.hash.slice(1))})));
   assert.ok(nav.every(item=>item.exists),'All chapter links resolve to original anchors');
   await page.evaluate(()=>scrollTo(0,0));
   if(width<=900){
    assert.equal(await page.locator('.story-index-details').getAttribute('open'),null,'Mobile chapter index starts collapsed');
    await page.locator('.story-index summary').click();
    await page.locator('[data-story-link]').first().click();
    assert.equal(await page.locator('.story-index-details').getAttribute('open'),null,'Mobile chapter index closes after selection');
   }else await page.locator('[data-story-link]').first().click();
   const position=await page.locator('.story-chapter h2').first().evaluate(el=>el.getBoundingClientRect().top);
   assert.ok(position>=90&&position<200,'Chapter navigation clears the sticky site header');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth<=1),'No horizontal overflow');
   if(live){
    assert.equal(await page.locator('link[rel=canonical]').getAttribute('href'),base+articlePath,'Canonical is unchanged');
    assert.ok(await page.locator('.article-footer .share-panel').count(),'Existing sharing controls remain');
    assert.ok(await page.locator('.article-footer a[href$="/newsletter"]').count(),'Existing email subscription remains');
    assert.ok(await page.locator('.article-footer a[href$="/contribute"]').count(),'Existing support route remains');
    const schema=await page.locator('script[type="application/ld+json"]').allTextContents();
    assert.ok(schema.some(text=>JSON.stringify(JSON.parse(text)).includes('BlogPosting')),'Existing article structured data remains');
   }
   await page.evaluate(()=>scrollTo(0,0));
   if([390,1440].includes(width)){
    await page.screenshot({path:path.join(out,'article-'+width+'.png'),fullPage:true});
    await page.locator('.story-header').screenshot({path:path.join(out,'article-header-'+width+'.png')});
   }
   results.push({width,chapters:nav.length,images:imageData,overflow:await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth)});
  }
  const nojs=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:844}});await nojs.route('**/*',guard);
  const plain=await nojs.newPage();await plain.goto(base+articlePath);
  assert.equal(await plain.locator('.story-chapter').count(),8,'Article works without JavaScript');
  assert.equal(await plain.locator('.story-index-details[open]').count(),1,'Chapter links available without JavaScript');
  await nojs.close();
  if(live){
   await page.goto(base+'/');
   assert.equal(await page.locator('.inaugural-section,.featured-thought,.post-card,.article-group-discovery').count(),0,'Homepage stays article-free');
   await page.goto(base+'/my-thoughts');
   assert.ok(await page.locator('a[href$="/my-thoughts/impact-driven-inspiration"]').count()>0,'Original article remains in My Thoughts');
  }
  assert.deepEqual(errors,[],'No page JavaScript errors');
  fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live,checkedAt:new Date().toISOString(),results},null,2));
  console.log('PASS: eight original chapters, five uncropped original photos, desktop/mobile navigation, no-script reading and '+(live?'live homepage/archive preservation':'isolated production-template rendering')+'.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,errors},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}
 finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
