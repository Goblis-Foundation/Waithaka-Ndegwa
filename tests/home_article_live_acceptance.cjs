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
  let page;
  try {
    const context = await browser.newContext({viewport:{width:1440,height:1000}, serviceWorkers:'block', extraHTTPHeaders:{'Cache-Control':'no-cache'}});
    await context.route('**/*', route => {
      const request = route.request();
      const url = new URL(request.url());
      return ['GET','HEAD'].includes(request.method()) && url.origin === base ? route.continue() : route.abort();
    });
    page = await context.newPage();
    page.setDefaultTimeout(20000);
    let ready = false, consecutive = 0;
    for(let attempt=0; attempt<60; attempt++) {
      try {
        const article = await context.request.get(base+articlePath, {timeout:30000});
        const home = await context.request.get(base+'/', {timeout:30000});
        const articleHtml = await article.text(), homeHtml = await home.text();
        const coherent = article.status()===200 && home.status()===200 && articleHtml.includes('article-featured-uncropped') && !/class="[^"]*(?:inaugural-section|featured-thought|post-card|article-group-discovery)/.test(homeHtml);
        consecutive = coherent ? consecutive+1 : 0;
        console.log('Read-only rollout readiness:', JSON.stringify({attempt:attempt+1,articleStatus:article.status(),homeStatus:home.status(),coherent,consecutive}));
        if(consecutive>=3) { ready=true; break; }
      } catch(error) { consecutive=0; console.log('Release readiness:', error.message.split('\n')[0]); }
      await new Promise(resolve => setTimeout(resolve,10000));
    }
    assert.ok(ready, 'Both homepage and article must serve the new release consistently.');
    for(const width of [360,390,768,1024,1440]) {
      await page.setViewportSize({width,height:1000});
      for(const pathname of ['/',articlePath]) {
        const response = await page.goto(base+pathname, {waitUntil:'networkidle',timeout:45000});
        assert.equal(response.status(),200, pathname+' HTTP status');
        const metrics = await page.evaluate(() => ({overflow:document.documentElement.scrollWidth-innerWidth,h1:document.querySelectorAll('#main h1').length}));
        const canonical = await page.locator('link[rel=canonical]').getAttribute('href');
        const row = {path:pathname,width,status:response.status(),canonical,...metrics};
        const headers = response.headers();
        row.cacheHeaders = Object.fromEntries(['cache-control','age','x-cache','etag','x-amz-cf-pop'].filter(k=>headers[k]).map(k=>[k,headers[k]]));
        report.results.push(row);
        assert.equal(metrics.h1,1,'Exactly one public page heading');
        assert.ok(metrics.overflow <= 2, pathname+' horizontal overflow at '+width);
        assert.equal(canonical,base+pathname,'Preserve canonical URL');
        if(pathname === '/') {
          row.articleSections = await page.locator('#main .inaugural-section,#main .featured-thought,#main .post-card,#main .article-group-discovery').evaluateAll(elements=>elements.map(el=>el.className));
          assert.deepEqual(row.articleSections,[],'No homepage article sections');
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
          row.photo=photo;
          assert.ok(photo.naturalWidth>0 && photo.naturalHeight>0,'Featured photograph loads');
          assert.equal(photo.fit,'contain','Complete photograph without cover cropping');
          assert.equal(photo.aspect,'auto','No forced image aspect ratio');
          assert.ok(photo.width>0 && photo.height>0 && photo.left>=-2 && photo.right<=width+2,'Photograph remains within viewport');
          const schema = await page.locator('script[type="application/ld+json"]').allTextContents();
          assert.ok(schema.some(s=>s.includes('BlogPosting')),'Preserve article structured data');
          if([390,1440].includes(width)) await page.locator('.article-featured-uncropped').screenshot({path:path.join(dir,'article-photo-'+width+'.png')});
        }
        await page.evaluate(()=>window.scrollTo(0,0));
        if([390,1440].includes(width)) await page.screenshot({path:path.join(dir,(pathname==='/'?'homepage':'article')+'-'+width+'.png'),fullPage:false});
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
    if(page) {
      await page.screenshot({path:path.join(dir,'failure.png'),fullPage:false}).catch(()=>{});
      fs.writeFileSync(path.join(dir,'failure-public-page.html'),await page.content().catch(()=>''));
    }
    throw error;
  } finally {
    console.log(JSON.stringify(report,null,2));
    fs.writeFileSync(path.join(dir,'acceptance.json'),JSON.stringify(report,null,2));
    await browser.close();
  }
})().catch(error=>{console.error(error);process.exitCode=1;});
