'use strict';
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {createHash}=require('node:crypto');
(async()=>{
 const base='https://www.waithakandegwa.com';
 const manifest=JSON.parse(fs.readFileSync(path.join(__dirname,'../app/content/the-gold-that-is-nairobis-sewage.json'),'utf8'));
 const target=base+'/my-thoughts/'+manifest.slug;
 const out=process.env.ARTICLE_EVIDENCE_DIR||'/tmp/nairobi-sewage-evidence';fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce',serviceWorkers:'block'});
 await context.route('**/*',r=>{const q=r.request();return new URL(q.url()).origin===base&&['GET','HEAD'].includes(q.method())?r.continue():r.abort();});
 const page=await context.newPage();const errors=[];const results=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  for(const asset of manifest.assets){const response=await context.request.get(base+asset.path);assert.equal(response.status(),200,asset.path);assert.equal(createHash('sha256').update(await response.body()).digest('hex'),asset.sha256,'Approved image bytes: '+asset.path);}
  for(const width of [360,390,768,1024,1440]){
   await page.setViewportSize({width,height:1000});
   const response=await page.goto(target,{waitUntil:'networkidle',timeout:45000});assert.equal(response.status(),200);
   const consent=page.locator('.cookie.show [data-consent="necessary"]');if(await consent.count())await consent.click();
   assert.equal(await page.locator('main h1').count(),1);assert.equal((await page.locator('main h1').innerText()).trim(),manifest.title);
   assert.equal(await page.locator('.article-sewage').count(),1);
   const body=await page.locator('.article-body').innerText();
   for(const text of ['Apeldoorn','Ruai','Ukambani','magnesium ammonium phosphate','not automatically drinking water'])assert.ok(body.includes(text),text);
   assert.ok(!body.includes('Research cut-off:')&&!body.includes('nuclear power'));
   assert.equal(await page.locator('.article-body figure').count(),4);
   assert.equal(await page.locator('.article-body figure img').count(),4);
   assert.ok(await page.locator('.article-body a[href^="https://"]').count()>=16);
   const photos=[];
   for(const image of await page.locator('.article-featured img,.article-body figure img').all()){
    await image.scrollIntoViewIfNeeded();await image.evaluate(i=>i.decode());
    const info=await image.evaluate(i=>{const r=i.getBoundingClientRect();return {src:new URL(i.currentSrc).pathname,nw:i.naturalWidth,nh:i.naturalHeight,w:r.width,h:r.height,fit:getComputedStyle(i).objectFit,alt:i.alt};});
    const expected=manifest.assets.find(a=>a.path===info.src);assert.ok(expected,'Known image');
    assert.equal(info.nw,expected.width);assert.equal(info.nh,expected.height);assert.equal(info.fit,'contain');
    assert.ok(info.w>0&&info.h>0&&Math.abs(info.w/info.h-info.nw/info.nh)<0.02,'Complete uncropped image');assert.ok(info.alt.length>25);
    photos.push(info);
   }
   assert.equal(photos.length,5);
   const anchors=await page.locator('.editorial-toc a').evaluateAll(nodes=>nodes.map(a=>({id:a.hash.slice(1),valid:!!document.getElementById(a.hash.slice(1))})));
   assert.equal(anchors.length,12);assert.ok(anchors.every(a=>a.valid));
   await page.locator('.editorial-toc a').last().click();assert.equal(new URL(page.url()).hash,'#'+anchors.at(-1).id);
   assert.equal(await page.locator('link[rel=canonical]').getAttribute('href'),target);
   assert.equal(await page.locator('meta[property="og:image"]').getAttribute('content'),base+manifest.featured_image);
   assert.equal(await page.locator('meta[name="twitter:image"]').getAttribute('content'),base+manifest.featured_image);
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);assert.ok(overflow<=2,'No horizontal overflow');
   if([390,1440].includes(width)){
    await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:path.join(out,'article-opening-'+width+'.png')});
    await page.locator('.article-featured').screenshot({path:path.join(out,'header-'+width+'.png')});
    await page.locator('.article-body figure').first().screenshot({path:path.join(out,'original-visit-'+width+'.png')});
    await page.locator('.article-body figure').last().screenshot({path:path.join(out,'original-material-'+width+'.png')});
    await page.screenshot({path:path.join(out,'article-full-'+width+'.png'),fullPage:true});
   }
   results.push({width,status:response.status(),overflow,chapters:anchors.length,photos});
  }
  await page.goto(base+'/my-thoughts',{waitUntil:'networkidle'});assert.ok(await page.locator('a[href$="/my-thoughts/'+manifest.slug+'"]').count()>0,'Archive entry');
  await page.goto(base+'/',{waitUntil:'networkidle'});assert.equal(await page.locator('a[href$="/my-thoughts/'+manifest.slug+'"]').count(),0);assert.equal(await page.locator('.inaugural-section,.featured-thought,.post-card,.article-group-discovery').count(),0);
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,url:target,checkedAt:new Date().toISOString(),assets:manifest.assets,results},null,2));
  console.log('PASS: published sewage article; exact approved header and four original photographs; responsive uncropped display; evidence links; archive and homepage policy.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),errors,results},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}
 finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
