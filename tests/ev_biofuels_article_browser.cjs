'use strict';
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {createHash}=require('node:crypto');
(async()=>{
 const base='https://www.waithakandegwa.com';
 const slug='electric-vehicles-biofuels-africa';
 const target=base+'/my-thoughts/'+slug;
 const imagePath='/assets/images/articles/ev-biofuels-approved-20261104.jpg';
 const expectedHash='b66997140fb97b2431ade46771cab6e97c187d69ab55d71312382824e2fc9a57';
 const out=process.env.ARTICLE_EVIDENCE_DIR||'/tmp/ev-biofuels-evidence';fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce',serviceWorkers:'block'});
 await context.route('**/*',route=>{const req=route.request();const u=new URL(req.url());return u.origin===base&&['GET','HEAD'].includes(req.method())?route.continue():route.abort();});
 const page=await context.newPage();const errors=[];const results=[];
 page.on('pageerror',e=>errors.push(e.message));
 try{
  const photo=await context.request.get(base+imagePath);assert.equal(photo.status(),200);
  assert.equal(createHash('sha256').update(await photo.body()).digest('hex'),expectedHash,'Live asset is exactly the approved image');
  for(const width of [360,390,768,1024,1440]){
   await page.setViewportSize({width,height:1000});
   const response=await page.goto(target,{waitUntil:'networkidle',timeout:45000});
   assert.equal(response.status(),200,'Published article returns HTTP 200');
   const consent=page.locator('.cookie.show [data-consent="necessary"]');if(await consent.count())await consent.click();
   assert.equal(await page.locator('main h1').count(),1,'Exactly one title');
   assert.ok((await page.locator('main h1').textContent()).includes('Dangote'),'Original title is present');
   const body=await page.locator('.article-body').innerText();
   for(const text of ['4 November 2026','30 September 2026','3 November 2026','Turkana','US$99','Crostar Energy LTD','traceable renewable-oils'])assert.ok(body.includes(text),'Published content retains '+text);
   for(const text of ['Research cut-off:','Sources are linked beside the relevant claims.','This article updates an earlier 2023 draft.','January 2027','Jan 2027','Q4 2026','late-2026','CEOS'])assert.ok(!body.includes(text),'Excluded content stays off article: '+text);
   const hero=page.locator('.article-featured img');assert.equal(await hero.count(),1,'One article header image');
   await hero.scrollIntoViewIfNeeded();await hero.evaluate(img=>img.decode());
   const image=await hero.evaluate(img=>{const r=img.getBoundingClientRect();return {src:img.currentSrc,width:r.width,height:r.height,naturalWidth:img.naturalWidth,naturalHeight:img.naturalHeight,fit:getComputedStyle(img).objectFit,alt:img.alt};});
   assert.ok(image.src.endsWith(imagePath),'Approved image replaces previous header');
   assert.equal(image.naturalWidth,1672);assert.equal(image.naturalHeight,941);
   assert.equal(image.fit,'contain');assert.ok(Math.abs(image.width/image.height-1672/941)<0.01,'No cropping or distortion');
   assert.ok(image.alt.includes('Illustration'),'Meaningful image description');
   assert.ok((await page.locator('.article-featured figcaption').innerText()).includes('AI-generated'),'Generated illustration disclosed');
   assert.equal(await page.locator('meta[property="og:image"]').getAttribute('content'),base+imagePath,'Open Graph image updated');
   assert.equal(await page.locator('meta[name="twitter:image"]').getAttribute('content'),base+imagePath,'Social preview image updated');
   assert.ok(await page.locator('.article-body a[href^="https://"]').count()>=20,'Research evidence retained');
   const links=await page.locator('.editorial-toc a').evaluateAll(nodes=>nodes.map(a=>({hash:a.hash,valid:!!document.getElementById(a.hash.slice(1))})));
   assert.equal(links.length,12);assert.ok(links.every(x=>x.valid),'Chapter links resolve');
   assert.equal(await page.locator('link[rel=canonical]').getAttribute('href'),target,'Canonical preserved');
   assert.ok(await page.locator('.article-footer a[href$="/newsletter"]').count()>0);
   assert.ok(await page.locator('.article-footer a[href$="/contribute"]').count()>0);
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);assert.ok(overflow<=2,'No horizontal overflow at '+width);
   const schema=await page.locator('script[type="application/ld+json"]').allTextContents();
   assert.ok(schema.some(s=>JSON.stringify(JSON.parse(s)).includes('BlogPosting')),'Article structured data retained');
   if([390,1440].includes(width)){
    await page.locator('.article-featured').screenshot({path:path.join(out,'approved-header-'+width+'.png')});
    await page.evaluate(()=>scrollTo(0,0));await page.screenshot({path:path.join(out,'article-'+width+'.png'),fullPage:true});
   }
   results.push({width,status:response.status(),overflow,chapters:links.length,image,researchNoteAbsent:true});
  }
  await page.goto(base+'/my-thoughts',{waitUntil:'networkidle'});
  assert.ok(await page.locator('a[href$="/my-thoughts/'+slug+'"]').count()>0,'Original article remains in My Thoughts');
  await page.goto(base+'/',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.inaugural-section,.featured-thought,.post-card,.article-group-discovery').count(),0,'No homepage article sections');
  assert.equal(await page.locator('a[href$="/my-thoughts/'+slug+'"]').count(),0,'No homepage article promotion');
  assert.deepEqual(errors,[],'No page JavaScript errors');
  fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,url:target,assetSha256:expectedHash,checkedAt:new Date().toISOString(),results},null,2));
  console.log('PASS: exact approved article header, uncropped mobile/desktop presentation, social previews, removed research note and retained article/homepage boundaries.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,errors},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}
 finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
