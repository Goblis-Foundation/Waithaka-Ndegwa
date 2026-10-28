'use strict';
/* 15 actual PHP public templates rendered with empty/synthetic data.
 * Browser requests stay on the isolated local fixture server. No real intake,
 * payment, publishing, OAuth, provider call or production traffic is generated.
 */
const {chromium}=require('playwright'),fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:900}});
 await context.route('**/*',route=>route.request().url().startsWith('http://127.0.0.1:8125/')?route.continue():route.abort());
 const page=await context.newPage();
 const errors=[];let checks=0;
 page.on('pageerror',e=>errors.push(e.message));
 const ok=(value,label)=>{assert.ok(value,label);checks++;};
 const host='http://127.0.0.1:8125/';
 const pages=[
  'start-here','startup-journeys','founder-stories','resources','watch',
  'enterprises','employment','opportunities','partner-stories',
  'monthly-roundup','newsletter','explore','advertise','advertising-policy','promotion-intake'
 ];
 const widths=[320,360,390,440,600,740,820,1024,1180,1280,1440];
 const requiredLinks={
  'start-here':['/my-thoughts','/startup-journeys','/founders','/resources','/contact'],
  'startup-journeys':['/my-thoughts','/newsletter','/founders'],
  'founder-stories':['/founders/submit','/opportunities','/opportunities/submit'],
  'resources':['/start-here','/my-thoughts'],
  'watch':['/start-here','/my-thoughts'],
  'enterprises':['/about#goals-2030','/startup-journeys'],
  'employment':['/opportunities/submit','/opportunities','/enterprises'],
  'opportunities':['/partner-stories','/promote','/opportunities/jobs','/opportunities/submit'],
  'partner-stories':['/opportunities','/promote'],
  'monthly-roundup':['/newsletter'],
  'newsletter':['/newsletter/preferences/request','/feed.xml','/privacy'],
  'explore':['/opportunities','/opportunities/jobs','/founders/submit','/contact','/contribute'],
  'advertise':['/promote','/advertising-policy'],
  'advertising-policy':['/contact','/promote'],
  'promotion-intake':['/contact']
 };
 for(const key of pages){
  await page.setViewportSize({width:1180,height:900});
  await page.goto(host+'sitewide-'+key+'.html');
  const urls=await page.locator('main#main a[href]').evaluateAll(els=>els.map(el=>new URL(el.href).pathname+new URL(el.href).hash));
  for(const path of requiredLinks[key]||[])ok(urls.includes(path),key+' links to '+path+' without a dead-end');
  if(key==='resources')ok(await page.locator('.editorial-empty h2').innerText()==='No resources are published yet.','Resource-specific empty state');
  if(key==='watch')ok(await page.locator('.editorial-empty h2').innerText()==='No videos have been published yet.','Video-specific empty state');
  if(key==='partner-stories'){
   ok(await page.locator('.desk-filter-sponsored button[type=submit]').innerText()==='Find partner stories','Partner story search action is correctly labelled');
   ok(!(await page.locator('.desk-filter-sponsored').innerText()).includes('Closing within 14 days'),'Sponsored filter excludes irrelevant application deadlines');
   ok((await page.locator('.desk-filter-sponsored').evaluate(el=>getComputedStyle(el).gridTemplateColumns.split(' ').length))===3,'Sponsor filter uses exactly three active desktop columns');
  }
  if(key==='monthly-roundup')ok(await page.locator('main').count()===1,'Monthly roundups have only one main landmark');
  if(key==='newsletter')ok(await page.locator('form[action$="/newsletter"] input[name=subscribe_ack][required]').count()===1,'Newsletter requires explicit opt-in');
  if(key==='promotion-intake')ok(await page.locator('form').count()>=1,'Promotion flow retains its own form');
  for(const width of widths){
   await page.setViewportSize({width,height:900});
   await page.evaluate(()=>window.scrollTo(0,0));
   const stat=await page.evaluate(()=>{
    const rect=el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};};
    const site=rect(document.querySelector('.site-header .container'));
    const frames=[...document.querySelectorAll('#main .section > .container,#main .journey-hero > .container,#main .editorial-hero > .container,#main .enterprise-hero > .container,#main .desk.desk-public,#main .container.opp-shell,#main .ux-page:not(.ux-reading)')].map(el=>({tag:el.tagName,classes:el.className,rect:rect(el)}));
    const controls=[...document.querySelectorAll('#main input,#main select,#main textarea,#main button')].filter(el=>{
      if(el.closest('[hidden],[aria-hidden="true"],.journey-honey,.desk-honeypot'))return false;
      const style=getComputedStyle(el),r=el.getBoundingClientRect();
      return style.display!=='none'&&style.visibility!=='hidden'&&r.width>2&&r.height>2;
    }).map(el=>({tag:el.tagName,name:el.getAttribute('name')||'',rect:rect(el)}));
    const main=document.querySelectorAll('main').length;
    const heading=document.querySelectorAll('#main h1').length;
    return {site,frames,controls,main,heading,
      overflow:document.documentElement.scrollWidth-innerWidth,
      scrollWidth:document.documentElement.scrollWidth,
      viewport:innerWidth};
   });
   ok(stat.main===1,key+' has one main region at '+width+'px');
   ok(stat.heading===1,key+' has one meaningful h1 at '+width+'px');
   ok(stat.overflow<=2,key+' has no horizontal overflow at '+width+'px (overflow '+stat.overflow+')');
   const bad=stat.frames.filter(x=>Math.abs(x.rect.left-stat.site.left)>3||Math.abs(x.rect.right-stat.site.right)>3);
   ok(bad.length===0,key+' top-level content shares header gutters at '+width+'px: '+JSON.stringify(bad).slice(0,450));
   const badControls=stat.controls.filter(x=>x.rect.left< -2||x.rect.right>width+2);
   ok(badControls.length===0,key+' form controls stay within the viewport at '+width+'px: '+JSON.stringify(badControls).slice(0,450));
  }
 }
 // Check form-only paths never publish or charge through the browser fixture.
 ok(errors.length===0,'No page JavaScript exceptions: '+errors.join('; '));
 const dir=process.env.EXPERIENCE_EVIDENCE_DIR;
 if(dir){
  fs.mkdirSync(dir,{recursive:true});
  for(const key of ['opportunities','partner-stories','newsletter','founder-stories','monthly-roundup','advertise']){
   await page.goto(host+'sitewide-'+key+'.html');
   for(const width of [390,1180]){
    await page.setViewportSize({width,height:900});
    await page.screenshot({path:dir+'/sitewide-'+key+'-'+width+'.png',fullPage:true});
   }
  }
 }
 console.log('PASS: '+checks+' site-wide public-template layout, flow, form and landmark checks across '+pages.length+' pages / '+widths.length+' widths.');
 await browser.close();
})().catch(error=>{console.error(error);process.exit(1)});
