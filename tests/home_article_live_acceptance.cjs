'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require('playwright');
const base = 'https://www.waithakandegwa.com';
const articlePath = '/my-thoughts/impact-driven-inspiration';
const dir = process.env.EVIDENCE_DIR;
if (!dir) throw new Error('EVIDENCE_DIR must point to disposable runner storage.');
fs.mkdirSync(dir, {recursive:true});
const report = {testedAt:new Date().toISOString(), sourceCommit:'ce1125e533a23bbe2399446804dcb827cd7dca47', readOnly:true, results:[], errors:[]};
(async () => {
  const browser = await chromium.launch({headless:true});
  try {
    const context = await browser.newContext({viewport:{width:1440,height:1000}, serviceWorkers:'block', extraHTTPHeaders:{'Cache-Control':'no-cache'}});
    await context.route('**/*', route => {
      const request = route.request();
      const url = new URL(request.url());
      return ['GET','HEAD'].includes(request.method()) && url.origin === base ? route.continue() : route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    let ready = false;
    for(let attempt=0; attempt<60; attempt++) {
      try {
        const response = await context.request.get(base+articlePath, {timeout:30000});
        if(response.status() === 200 && (await response.text()).includes('article-featured-uncropped')) { ready=true; break; }
      } catch(error) { console.log('Release readiness:', error.message.split('\n')[0]); }
      await new Promise(resolve => setTimeout(resolve,10000));
    }
    assert.ok(ready, 'New article template must be live before acceptance.');
    for(const width of [360,390,768,1024,1440]) {
      await page.setViewportSize({width,height:1000});
      for(const pathname of ['/',articlePath]) {
        const response = await page.goto(base+pathname, {waitUntil:'networkidle',timeout:45000});
        assert.equal(response.status(),200, pathname+' HTTP status');
        const metrics = await page.evaluate(() => ({overflow:document.documentElement.scrollWidth-innerWidth,h1:document.querySelectorAll('#main h1').length}));
        assert.equal(metrics.h1,1,'Exactly one public page heading');
        assert.ok(metrics.overflow <= 2, pathname+' horizontal overflow at '+width);
        const canonical = await page.locator('link[rel=canonical]').getAttribute('href');
        assert.equal(canonical,base+pathname,'Preserve canonical URL');
        const row = {path:pathname,width,status:response.status(),canonical,...metrics};
        if(pathname === '/') {
          assert.equal(await page.locator('#main .inaugural-section,#main .featured-thought,#main .post-card,#main .article-group-discovery').count(),0,'No homepage article sections');
          const articleLinks = await page.locator('#main a[href]').evaluateAll(links => links.map(a=>new URL(a.href).pathname).filter(p=>/^\/my-thoughts\/[^/]+/.test(p)));
          assert.deepEqual(articleLinks,[],'Homepage must not feature individual articles');
          assert.ok(await page.locator('a[href$="/my-thoughts"]').count()>0,'Retain My Thoughts navigation');
          row.individualArticleLinks=articleLinks;
        } else {
          assert.match(await page.locator('#main h1').innerText(),/Impact-Driven Inspiration/);
          const image = page.locator('.article-featured-uncropped img');
          await image.scrollIntoViewIfNeeded();
          await image.evaluate(img=>img.decode());
          const photo = await image.evaluate(img=>{const r=img.getBoundingClientRect(),s=getComputedStyle(img);return {source:img.currentSrc,naturalWidth:img.naturalWidth,naturalHeight:img.naturalHeight,width:r.width,height:r.height,left:r.left,right:r.right,fit:s.objectFit,aspect:s.aspectRatio};});
          assert.ok(photo.naturalWidth>0 && photo.naturalHeight>0,'Featured photograph loads');
          assert.equal(photo.fit,'contain','Complete photograph without cover cropping');
          assert.equal(photo.aspect,'auto','No forced image aspect ratio');
          assert.ok(photo.width>0 && photo.height>0 && photo.left>=-2 && photo.right<=width+2,'Photograph remains within viewport');
          const schema = await page.locator('script[type="application/ld+json"]').allTextContents();
          assert.ok(schema.some(s=>s.includes('BlogPosting')),'Preserve article structured data');
          row.photo=photo;
          if([390,1440].includes(width)) await page.locator('.article-featured-uncropped').screenshot({path:path.join(dir,'article-photo-'+width+'.png')});
        }
        await page.evaluate(()=>window.scrollTo(0,0));
        if([390,1440].includes(width)) await page.screenshot({path:path.join(dir,(pathname==='/'?'homepage':'article')+'-'+width+'.png'),fullPage:false});
        report.results.push(row);
      }
    }
    const archive = await page.goto(base+'/my-thoughts',{waitUntil:'domcontentloaded',timeout:45000});
    assert.equal(archive.status(),200,'My Thoughts remains accessible');
    assert.ok(await page.locator('a[href$="'+articlePath+'"]').count()>0,'Inaugural article remains in its archive');
    report.archive={status:archive.status(),inauguralArticlePresent:true};
    report.passed=true;
    console.log('PASS: no articles on homepage; article and archive preserved; complete photograph across five viewport widths.');
  } catch(error) {
    report.passed=false;
    report.errors.push(error.stack || error.message);
    throw error;
  } finally {
    fs.writeFileSync(path.join(dir,'acceptance.json'),JSON.stringify(report,null,2));
    await browser.close();
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
