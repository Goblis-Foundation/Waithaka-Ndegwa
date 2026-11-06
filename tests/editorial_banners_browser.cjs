'use strict';
const {chromium}=require('playwright');
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');const crypto=require('node:crypto');const {execFileSync}=require('node:child_process');
(async()=>{
 const root=path.resolve(__dirname,'..'),base='https://www.waithakandegwa.com';
 const out=process.env.ARTICLE_EVIDENCE_DIR||'/tmp/editorial-banners-evidence';fs.mkdirSync(out,{recursive:true});
 const original=JSON.parse(fs.readFileSync(path.join(root,'app/content/africa-opportunities-20261104.json'),'utf8')).entries;
 const added=JSON.parse(fs.readFileSync(path.join(root,'app/content/rolling-opportunities-20261105.json'),'utf8')).entries;
 const catalog=JSON.parse(execFileSync('php',['-r','require "app/editorial_banners.php"; echo json_encode(eb_catalog(),JSON_THROW_ON_ERROR);'],{cwd:root,encoding:'utf8'}));
 const revision=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'app/editorial_banners.php'))).digest('hex');
 const entries=[...original,...added],results=[],errors=[],xmlChecks=[],hashTopics=new Map(),versions=new Set();
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
    const expectedVersion=crypto.createHash('sha256').update(revision+':'+entry.slug).digest('hex').slice(0,24);
    assert.equal(new URL(src).searchParams.get('v'),expectedVersion,'Content-specific CDN cache identity');
    if(width===390){assert.ok(!versions.has(expectedVersion),'Independent cache entry per listing');versions.add(expectedVersion);}
    assert.equal(await page.locator('meta[property="og:image"]').getAttribute('content'),src);
    assert.equal(await page.locator('meta[name="twitter:image"]').getAttribute('content'),src);
    assert.ok((await image.getAttribute('alt')).startsWith('Editorial illustration of '));
    const dimensions=await image.evaluate(img=>({width:img.naturalWidth,height:img.naturalHeight,ratio:img.getBoundingClientRect().width/img.getBoundingClientRect().height}));
    assert.equal(dimensions.width,1200);assert.equal(dimensions.height,630);assert.ok(Math.abs(dimensions.ratio-1200/630)<0.02,'Uncropped banner');
    const imageResponse=await context.request.get(src);assert.equal(imageResponse.status(),200);assert.ok(imageResponse.headers()['content-type'].startsWith('image/png'));
    assert.equal(imageResponse.headers()['x-editorial-banner-key'],entry.slug,'The actual served image belongs to this listing');
    assert.equal(imageResponse.headers()['x-editorial-banner-theme'],catalog[entry.slug],'The actual served topic matches the catalogue');
    const bytes=await imageResponse.body();assert.ok(bytes.subarray(0,8).equals(Buffer.from([137,80,78,71,13,10,26,10])));
    const imageHash=crypto.createHash('sha256').update(bytes).digest('hex');
    if(hashTopics.has(imageHash))assert.equal(hashTopics.get(imageHash),catalog[entry.slug],'Different topics cannot share cached image bytes');
    hashTopics.set(imageHash,catalog[entry.slug]);
    if(width===390&&i===0){
     const versionOnly=new URL(src);versionOnly.searchParams.delete('key');const same=await context.request.get(versionOnly.href);assert.equal(same.status(),200);assert.equal(same.headers()['x-editorial-banner-key'],entry.slug);assert.deepEqual(await same.body(),bytes,'Version alone yields identical content');
     versionOnly.searchParams.set('key','topic-conservation');const altered=await context.request.get(versionOnly.href);assert.equal(altered.status(),200);assert.deepEqual(await altered.body(),bytes,'A non-cache-key parameter cannot replace another banner');
    }
    const status=await page.locator('[data-opportunity-status]').getAttribute('data-opportunity-status');
    assert.equal(await page.locator('.desk-cta a.desk-button.primary').count(),status==='current'?1:0);
    assert.equal(await page.locator('[data-opportunity-support]').count(),1);
    const words=(await page.locator('.desk-prose').innerText()).trim().split(/\s+/).length;assert.ok(words>=450);
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);assert.ok(overflow<=2);
    if(width===1440)await page.locator('[data-editorial-banner]').screenshot({path:path.join(out,entry.slug+'.png')});
    if(i===0||i>=original.length)await page.screenshot({path:path.join(out,entry.slug+'-'+width+'.png'),fullPage:true});
    results.push({slug:entry.slug,width,http:response.status(),status,words,overflow,image:src,theme:catalog[entry.slug],imageHash,dimensions});
   }
   await page.goto(base+'/my-thoughts',{waitUntil:'networkidle'});
   for(const entry of entries)assert.equal(await page.locator('.post-card a[href$="/'+entry.slug+'"]').count(),0,'No opportunities in My Thoughts');
   await page.screenshot({path:path.join(out,'my-thoughts-'+width+'.png'),fullPage:true});
  }
  for(const width of [390,768,1024,1440]){
   await page.setViewportSize({width,height:1000});await page.goto(base+'/opportunities',{waitUntil:'networkidle'});
   assert.equal(await page.locator('.editorial-directory-art').count(),1);assert.equal(await page.locator('main .desk-monogram').count(),0);
   assert.equal(await page.locator('[data-opportunity-support]').count(),0,'Compact opportunities landing omits the contribution callout');
   assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2));
   const fullDirectory=await page.goto(base+'/opportunities?browse=all',{waitUntil:'networkidle'});assert.equal(fullDirectory.status(),200);assert.equal(await page.locator('[data-opportunity-support]').count(),1,'Full opportunity directory retains voluntary support');
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
  for(const xmlPath of ['/feed.xml','/sitemap.xml','/publishing-sitemap.xml']){
   const response=await context.request.get(base+xmlPath);assert.equal(response.status(),200);const text=await response.text();
   for(const entry of entries)assert.ok(!text.includes('/my-thoughts/'+entry.slug),xmlPath+' must not misclassify '+entry.slug);
   if(xmlPath==='/publishing-sitemap.xml')for(const entry of entries){const state=results.find(r=>r.slug===entry.slug).status;assert.equal(text.includes('/opportunities/'+entry.slug),state!=='archived','Canonical publishing sitemap is lifecycle-aware');}
   else assert.ok(text.includes('/my-thoughts/the-gold-that-is-nairobis-sewage'),'Real editorial content retained');
   xmlChecks.push({path:xmlPath,status:response.status(),misplacedOpportunities:0});
  }
  const bad=await context.request.get(base+'/assets/editorial-banner.php?v=invalid-version-20261105');assert.equal(bad.status(),404);
  await page.goto(base+'/',{waitUntil:'networkidle'});for(const entry of entries)assert.equal(await page.locator('a[href$="/'+entry.slug+'"]').count(),0,'No homepage promotion');
  assert.deepEqual(errors,[]);
  fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,checkedAt:new Date().toISOString(),opportunities:entries.length,newOpportunities:added.map(e=>e.slug),results,xmlChecks,distinctImageHashes:hashTopics.size,noSubmissions:true},null,2));
  console.log('PASS: topic-correct cached banners, article previews, eighteen opportunities and editorial XML boundaries.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,xmlChecks,errors},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
