'use strict';
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
(async()=>{
 const base='https://www.waithakandegwa.com';
 const slug='electric-vehicles-biofuels-africa';
 const target=base+'/my-thoughts/'+slug;
 const out=process.env.ARTICLE_EVIDENCE_DIR||'/tmp/ev-biofuels-evidence';fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce',serviceWorkers:'block'});
 await context.route('**/*',route=>{const req=route.request();const u=new URL(req.url());return u.origin===base&&['GET','HEAD'].includes(req.method())?route.continue():route.abort();});
 const page=await context.newPage();const errors=[];const results=[];
 page.on('pageerror',e=>errors.push(e.message));
 try{
  for(const width of [360,390,768,1024,1440]){
   await page.setViewportSize({width,height:1000});
   const response=await page.goto(target,{waitUntil:'networkidle',timeout:45000});
   assert.equal(response.status(),200,'Published article returns HTTP 200');
   const consent=page.locator('.cookie.show [data-consent="necessary"]');if(await consent.count())await consent.click();
   assert.equal(await page.locator('main h1').count(),1,'Exactly one title');
   assert.ok((await page.locator('main h1').textContent()).includes('Dangote'),'News-led title is present');
   const body=await page.locator('.article-body').innerText();
   for(const text of ['4 November 2026','30 September 2026','3 November 2026','Turkana','US$99','Crostar Energy LTD','traceable renewable-oils'])assert.ok(body.includes(text),'Published content retains '+text);
   for(const text of ['January 2027','Jan 2027','Q4 2026','late-2026','CEOS'])assert.ok(!body.includes(text),'Excluded company detail stays off this article: '+text);
   assert.ok(await page.locator('.article-body h2').count()>=10,'Full article chapters published');
   assert.ok(await page.locator('.article-body a[href^="https://"]').count()>=20,'Inline research evidence survives publication');
   const links=await page.locator('.editorial-toc a').evaluateAll(nodes=>nodes.map(a=>({hash:a.hash,valid:!!document.getElementById(a.hash.slice(1))})));
   assert.ok(links.length>=10&&links.every(x=>x.valid),'All chapter links resolve');
   assert.equal(await page.locator('link[rel=canonical]').getAttribute('href'),target,'Canonical correct');
   assert.ok(await page.locator('.article-footer a[href$="/newsletter"]').count()>0,'Subscribe route retained');
   assert.ok(await page.locator('.article-footer a[href$="/contribute"]').count()>0,'Contribution route retained');
   const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);
   assert.ok(overflow<=2,'No horizontal overflow at '+width);
   const schema=await page.locator('script[type="application/ld+json"]').allTextContents();
   assert.ok(schema.some(s=>JSON.stringify(JSON.parse(s)).includes('BlogPosting')),'Article structured data present');
   if([390,1440].includes(width)){await page.screenshot({path:path.join(out,'article-'+width+'.png'),fullPage:true});await page.locator('.article-header').screenshot({path:path.join(out,'header-'+width+'.png')});}
   results.push({width,status:response.status(),overflow,chapters:links.length,words:body.split(/\s+/).length});
  }
  await page.goto(base+'/my-thoughts',{waitUntil:'networkidle'});
  assert.ok(await page.locator('a[href$="/my-thoughts/'+slug+'"]').count()>0,'Published in My Thoughts');
  await page.goto(base+'/',{waitUntil:'networkidle'});
  assert.equal(await page.locator('.inaugural-section,.featured-thought,.post-card,.article-group-discovery').count(),0,'No homepage article sections');
  assert.equal(await page.locator('a[href$="/my-thoughts/'+slug+'"]').count(),0,'No homepage article promotion');
  assert.deepEqual(errors,[],'No page JavaScript errors');
  fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,url:target,checkedAt:new Date().toISOString(),results},null,2));
  console.log('PASS: published researched EV/biofuels article, full chapters, citations, Crostar aims only, responsive layout, archive discovery and article-free homepage.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,errors},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}
 finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1)});
