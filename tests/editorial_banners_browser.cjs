'use strict';
const {chromium}=require('playwright');
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
(async()=>{
 const root=path.resolve(__dirname,'..'),base='https://www.waithakandegwa.com';
 const out=process.env.ARTICLE_EVIDENCE_DIR||'/tmp/editorial-banners-evidence';fs.mkdirSync(out,{recursive:true});
 const original=JSON.parse(fs.readFileSync(path.join(root,'app/content/africa-opportunities-20261104.json'),'utf8')).entries;
 const added=JSON.parse(fs.readFileSync(path.join(root,'app/content/rolling-opportunities-20261105.json'),'utf8')).entries;
 const entries=[...original,...added],results=[],errors=[];
 const browser=await chromium.launch({headless:true}),context=await browser.newContext({serviceWorkers:'block',reducedMotion:'reduce'}),page=await context.newPage();
 await context.route('**/*',route=>{const request=route.request();return new URL(request.url()).origin===base&&['GET','HEAD'].includes(request.method())?route.continue():route.abort();});
 page.on('pageerror',error=>errors.push(error.message));
 try{
  for(const width of [390,1440]){
   await page.setViewportSize({width,height:1000});
   for(const [i,entry]of entries.entries()){
    const address=base+'/opportunities/'+entry.slug;
    const response=await page.goto(address,{waitUntil:'networkidle'});assert.equal(response.status(),200);
    const cookie=page.locator('.cookie.show [data-consent="necessary"]');if(await cookie.count())await cookie.click();
    assert.equal((await page.locator('main h1').innerText()).trim(),entry.title);
    assert.equal(await page.locator('link[rel=canonical]').getAttribute('href'),address);
    const image=page.locator('[data-editorial-banner] img');assert.equal(await image.count(),1);
    await image.scrollIntoViewIfNeeded();await image.evaluate(img=>img.decode());
    const src=await image.getAttribute('src');assert.ok(src.includes('/assets/editorial-banner.php?key='+entry.slug));
    assert.equal(await page.locator('meta[property="og:image"]').getAttribute('content'),src);
    assert.equal(await page.locator('meta[name="twitter:image"]').getAttribute('content'),src);
    assert.ok((await image.getAttribute('alt')).startsWith('Editorial illustration of '));
    const dimensions=await image.evaluate(img=>({width:img.naturalWidth,height:img.naturalHeight,ratio:img.getBoundingClientRect().width/img.getBoundingClientRect().height}));
    assert.equal(dimensions.width,1200);assert.equal(dimensions.height,630);assert.ok(Math.abs(dimensions.ratio-1200/630)<0.02,'Uncropped banner');
    const imageResponse=await context.request.get(src);assert.equal(imageResponse.status(),200);assert.ok(imageResponse.headers()['content-type'].startsWith('image/png'));
    const bytes=await imageResponse.body();assert.ok(bytes.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])));
    const status=await page.locator('[data-opportunity-status]').getAttribute('data-opportunity-status');
    assert.equal(await page.locator('.desk-cta a.desk-button.primary').count(),status==='current'?1:0);
    assert.equal(await page.locator('[data-opportunity-support]').count(),1);
    const words=(await page.locator('.desk-prose').innerText()).trim().split(/\s+/).length;assert.ok(words>=450);
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);assert.ok(overflow<=2);
    if(width===1440)await page.locator('[data-editorial-banner]').screenshot({path:path.join(out,entry.slug+'.png')});
    if(i===0||i>=original.length)await page.screenshot({path:path.join(out,entry.slug+'-'+width+'.png'),fullPage:true});
    results.push({slug:entry.slug,width,http:response.status(),status,words,overflow,image:src,dimensions});
   }
   await page.goto(base+'/my-thoughts',{waitUntil:'networkidle'});
   for(const entry of entries)assert.equal(await page.locator('.post-card a[href$="/'+entry.slug+'"]').count(),0,'No opportunities in My Thoughts');
   await page.screenshot({path:path.join(out,'my-thoughts-'+width+'.png'),fullPage:true});
  }
  for(const width of [390,768,1024,1440]){
   await page.setViewportSize({width,height:1000});await page.goto(base+'/opportunities',{waitUntil:'networkidle'});
   assert.equal(await page.locator('.editorial-directory-art').count(),1);assert.equal(await page.locator('main .desk-monogram').count(),0);
   assert.equal(await page.locator('[data-opportunity-support]').count(),1);
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
   if(width===390||width===1440)await page.screenshot({path:path.join(out,'directory-'+width+'.png'),fullPage:true});
  }
  for(const entry of added){
   await page.goto(base+'/opportunities?status=all&q='+encodeURIComponent(entry.title),{waitUntil:'networkidle'});
   assert.equal(await page.locator('.desk-feature-card h2 a[href$="/'+entry.slug+'"]').count(),1,'One listing per new opportunity');
   assert.equal(await page.locator('.editorial-card-art').count(),1);
  }
  for(const slug of ['electric-vehicles-biofuels-africa','the-gold-that-is-nairobis-sewage']){
   const response=await page.goto(base+'/my-thoughts/'+slug,{waitUntil:'networkidle'});assert.equal(response.status(),200);
   const image=page.locator('.article-featured img');assert.equal(await image.count(),1);await image.evaluate(img=>img.decode());
   assert.equal(await page.locator('meta[property="og:image"]').getAttribute('content'),await image.getAttribute('src'));
   assert.ok(!/social-default|monogram|lockup|favicon|old-logo/.test(await image.getAttribute('src')),'Article preview is not a logo');
  }
  const bad=await context.request.get(base+'/assets/editorial-banner.php?key=unknown');assert.equal(bad.status(),404);
  await page.goto(base+'/',{waitUntil:'networkidle'});for(const entry of entries)assert.equal(await page.locator('a[href$="/'+entry.slug+'"]').count(),0,'No homepage promotion');
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,checkedAt:new Date().toISOString(),opportunities:entries.length,newOpportunities:added.map(e=>e.slug),results,noSubmissions:true},null,2));
  console.log('PASS: logo-free opportunity artwork, article previews, two rolling calls and editorial boundaries.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,errors},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
