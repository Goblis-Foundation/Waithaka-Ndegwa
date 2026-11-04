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
  assert.equal(manifest.media_mode,'header-only');assert.equal(manifest.assets.length,1);
  for(const asset of manifest.assets){const response=await context.request.get(base+asset.path);assert.equal(response.status(),200,asset.path);assert.ok((response.headers()['content-type']||'').includes(asset.mime),'Correct image MIME type');assert.equal(createHash('sha256').update(await response.body()).digest('hex'),asset.sha256,'Exact approved header bytes');}
  for(const width of [360,390,768,1024,1440]){
   await page.setViewportSize({width,height:1000});
   const response=await page.goto(target,{waitUntil:'networkidle',timeout:45000});assert.equal(response.status(),200);
   const consent=page.locator('.cookie.show [data-consent="necessary"]');if(await consent.count())await consent.click();
   assert.equal(await page.locator('main h1').count(),1);assert.equal((await page.locator('main h1').innerText()).trim(),manifest.title);
   assert.equal(await page.locator('.article-sewage').count(),1);
   const body=await page.locator('.article-body').innerText();
   for(const text of ['Apeldoorn','Ruai','Ukambani','magnesium ammonium phosphate','not automatically drinking water'])assert.ok(body.includes(text),text);
   assert.ok(!body.includes('Research cut-off:')&&!body.includes('nuclear power'));
   assert.equal(await page.locator('.article-body figure,.article-body img').count(),0,'Body photos omitted as authorised');
   assert.ok(await page.locator('.article-body a[href^="https://"]').count()>=16);
   assert.equal(await page.locator('.article-featured img').count(),1,'Exactly one approved header');
   const image=page.locator('.article-featured img');await image.scrollIntoViewIfNeeded();await image.evaluate(i=>i.decode());
   const photo=await image.evaluate(i=>{const r=i.getBoundingClientRect();return {src:new URL(i.currentSrc).pathname,nw:i.naturalWidth,nh:i.naturalHeight,w:r.width,h:r.height,fit:getComputedStyle(i).objectFit,alt:i.alt};});
   const expected=manifest.assets[0];assert.equal(photo.src,expected.path);assert.equal(photo.nw,expected.width);assert.equal(photo.nh,expected.height);assert.equal(photo.fit,'contain');
   assert.ok(photo.w>0&&photo.h>0&&Math.abs(photo.w/photo.h-photo.nw/photo.nh)<0.02,'Complete uncropped header');assert.ok(photo.alt.length>25);
   assert.ok((await page.locator('.article-featured figcaption').innerText()).includes('AI-generated'));
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
    await page.screenshot({path:path.join(out,'article-full-'+width+'.png'),fullPage:true});
   }
   results.push({width,status:response.status(),overflow,chapters:anchors.length,photo,bodyPhotos:0});
  }
  await page.goto(base+'/my-thoughts',{waitUntil:'networkidle'});assert.ok(await page.locator('a[href$="/my-thoughts/'+manifest.slug+'"]').count()>0,'Archive entry');
  await page.goto(base+'/',{waitUntil:'networkidle'});assert.equal(await page.locator('a[href$="/my-thoughts/'+manifest.slug+'"]').count(),0);assert.equal(await page.locator('.inaugural-section,.featured-thought,.post-card,.article-group-discovery').count(),0);
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,url:target,checkedAt:new Date().toISOString(),mediaMode:manifest.media_mode,assets:manifest.assets,results},null,2));
  console.log('PASS: live sewage article with exact approved header only; responsive uncropped display; source links; archive and article-free homepage.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),errors,results},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}
 finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
