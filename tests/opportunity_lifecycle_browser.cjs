'use strict';
const {chromium}=require('playwright');const fs=require('node:fs');const path=require('node:path');const assert=require('node:assert/strict');
(async()=>{
 const root=path.resolve(__dirname,'..'),base='https://www.waithakandegwa.com',out=process.env.ARTICLE_EVIDENCE_DIR||'/tmp/opportunity-lifecycle-evidence';fs.mkdirSync(out,{recursive:true});
 const entries=JSON.parse(fs.readFileSync(path.join(root,'app/content/africa-opportunities-20261104.json'),'utf8')).entries;
 const browser=await chromium.launch({headless:true}),context=await browser.newContext({serviceWorkers:'block',reducedMotion:'reduce'}),page=await context.newPage(),errors=[],results=[];
 await context.route('**/*',route=>{const r=route.request();return new URL(r.url()).origin===base&&['GET','HEAD'].includes(r.method())?route.continue():route.abort();});page.on('pageerror',e=>errors.push(e.message));
 try{
  for(const width of [390,1440]){await page.setViewportSize({width,height:1000});
   for(const [i,e] of entries.entries()){
    const response=await page.goto(base+'/opportunities/'+e.slug,{waitUntil:'networkidle'});assert.equal(response.status(),200);
    const cookie=page.locator('.cookie.show [data-consent="necessary"]');if(await cookie.count())await cookie.click();
    const status=await page.locator('[data-opportunity-status]').getAttribute('data-opportunity-status');assert.ok(['current','closed','archived'].includes(status));
    assert.equal(await page.locator('main h1').count(),1);assert.equal(await page.locator('[data-opportunity-support]').count(),1);
    assert.equal(await page.locator('.desk-cta a.desk-button.primary').count(),status==='current'?1:0);
    assert.ok((await page.locator('.article-body').innerText()).split(/\s+/).length>=450);
    const overflow=await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth);assert.ok(overflow<=2);
    if(i===0)await page.screenshot({path:path.join(out,'lifecycle-'+width+'.png'),fullPage:true});results.push({slug:e.slug,width,status,overflow});
   }
   await page.goto(base+'/opportunities',{waitUntil:'networkidle'});assert.equal(await page.locator('select[name=status] option[value=archived]').count(),1);
   await page.goto(base+'/opportunities?status=archived',{waitUntil:'networkidle'});assert.equal(await page.locator('[data-opportunity-support]').count(),1);
  }
  await page.goto(base+'/write/desk/reviews',{waitUntil:'networkidle'});assert.ok(!((await page.content()).includes('saved evidence</summary>')),'Owner source evidence is private');
  assert.deepEqual(errors,[]);fs.writeFileSync(path.join(out,'acceptance.json'),JSON.stringify({passed:true,live:true,checkedAt:new Date().toISOString(),pages:entries.length,results,noSubmissions:true},null,2));
 }catch(error){fs.writeFileSync(path.join(out,'failure.json'),JSON.stringify({error:String(error),results,errors},null,2));await page.screenshot({path:path.join(out,'failure.png'),fullPage:true}).catch(()=>{});throw error;}finally{await browser.close();}
})().catch(e=>{console.error(e);process.exit(1)});
