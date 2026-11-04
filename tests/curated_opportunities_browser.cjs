'use strict';
const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');const {chromium}=require('playwright');
(async()=>{
 const catalog=JSON.parse(fs.readFileSync(path.join(__dirname,'../app/content/africa-opportunities-20261104.json'),'utf8'));
 const base='https://www.waithakandegwa.com';const out=process.env.ARTICLE_EVIDENCE_DIR||'/tmp/curated-opportunities-evidence';fs.mkdirSync(out,{recursive:true});
 const browser=await chromium.launch({headless:true});const context=await browser.newContext({viewport:{width:1440,height:1000},reducedMotion:'reduce',serviceWorkers:'block'});
 await context.route('**/*',route=>{const r=route.request();return new URL(r.url()).origin===base&&['GET','HEAD'].includes(r.method())?route.continue():route.abort();});
 const page=await context.newPage();const errors=[];const results=[];page.on('pageerror',e=>errors.push(e.message));
 try{
  for(const entry of catalog.entries){
   const checks=[];
   for(const width of [390,1440]){
    await page.setViewportSize({width,height:1000});const url=base+'/opportunities/'+entry.slug;
    const response=await page.goto(url,{waitUntil:'networkidle',timeout:45000});assert.equal(response.status(),200,entry.slug+' HTTP 200');
    const consent=page.locator('.cookie.show [data-consent="necessary"]');if(await consent.count())await consent.click();
    assert.equal((await page.locator('main h1').innerText()).trim(),entry.title);assert.equal(await page.locator('main h1').count(),1);
    assert.equal(await page.locator('link[rel="canonical"]').getAttribute('href'),url);
    assert.equal(await page.locator('.curated-opportunity').count(),1,'Native designed header');
    const body=await page.locator('.desk-prose').innerText();assert.ok(body.includes(entry.deadline_label)||await page.locator('.desk-alert').filter({hasText:'Deadline revised:'}).count()>0,'Original or reviewed deadline shown');assert.ok(body.includes(entry.support));
    assert.equal(await page.locator('.desk-prose h2').count(),9,'Expanded guide standard');
    assert.ok(body.includes('Prepare before you apply')&&body.includes('editorial guidance'),'Clearly labelled practical advice');
    if(entry.notice)assert.ok((await page.locator('.curated-notice').innerText()).includes(entry.notice));
    const sourceLinks=await page.locator('.desk-prose a').evaluateAll(nodes=>nodes.map(a=>({href:a.href,target:a.target,rel:a.rel})));
    assert.ok(sourceLinks.length>=1&&sourceLinks.every(a=>a.href.startsWith('https://')&&a.target==='_blank'&&a.rel.includes('noopener')));
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);assert.ok(overflow<=2,'No overflow '+entry.slug+' '+width);
    assert.ok(!body.includes('Research cut-off')&&!body.includes('PRIVATE-EDITORIAL'));
    const lifecycle=await page.locator('[data-opportunity-status]').getAttribute('data-opportunity-status');
    assert.equal(await page.locator('.desk-cta a.desk-button.primary').count(),lifecycle==='current'?1:0,'Expiry-aware provider action');
    if([catalog.entries[0].slug,catalog.entries[1].slug,catalog.entries[15].slug].includes(entry.slug))await page.screenshot({path:path.join(out,entry.slug+'-'+width+'.png'),fullPage:true});
    checks.push({width,status:response.status(),overflow,lifecycle});
   }
   results.push({slug:entry.slug,url:base+'/opportunities/'+entry.slug,checks,notice:!!entry.notice});
  }
  for(const entry of catalog.entries){await page.goto(base+'/opportunities?q='+encodeURIComponent(entry.title)+'&status='+((results.find(r=>r.slug===entry.slug).checks[0].lifecycle==='archived')?'archived':'all'),{waitUntil:'networkidle'});assert.equal(await page.locator('.desk-feature-card h2 a[href$="/'+entry.slug+'"]').count(),1,'Exactly one discoverable archive card');}
  const sitemap=await context.request.get(base+'/publishing-sitemap.xml');assert.equal(sitemap.status(),200);const xml=await sitemap.text();for(const entry of catalog.entries)assert.equal(xml.includes('/opportunities/'+entry.slug),results.find(r=>r.slug===entry.slug).checks[0].lifecycle!=='archived','Current sitemap excludes old archives');
  await page.goto(base+'/',{waitUntil:'networkidle'});assert.equal(await page.locator('.inaugural-section,.featured-thought,.post-card,.article-group-discovery').count(),0);for(const entry of catalog.entries)assert.equal(await page.locator('a[href$="/'+entry.slug+'"]').count(),0,'No homepage promotion');
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,checkedAt:new Date().toISOString(),count:results.length,results},null,2));
  console.log('PASS: sixteen individually published, source-linked, responsive native opportunities; archive, sitemap and homepage boundaries.');
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,errors},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}finally{await browser.close();}
})().catch(error=>{console.error(error);process.exit(1);});
