'use strict';
// Responsive acceptance for the actual PHP-rendered public header and discovery
// navigation. No provider calls, authenticated actions, or real submissions.
const { chromium }=require('playwright');
const fs=require('node:fs');
(async()=>{
 const browser=await chromium.launch({headless:true});
 const context=await browser.newContext({viewport:{width:1440,height:900}});
 await context.route('**/*',route=>route.request().url().startsWith('http://127.0.0.1:8125/')?route.continue():route.abort());
 const page=await context.newPage();
 const errors=[];let checks=0;
 page.on('pageerror',e=>errors.push(e.message));
 function check(value,msg){if(!value)throw new Error(msg);checks++;}
 const host='http://127.0.0.1:8125/';
 const sizes=[320,360,390,440,600,740,768,820,1024,1180,1181,1280,1440];
 await page.goto(host+'public-home.html');
 check(await page.locator('.public-explore-links a').count()===4,'Four featured discovery destinations');
 check(await page.locator('.public-explore-all').count()===1,'Complete destination index remains linked');
 check(await page.locator('#site-main-nav a[href*="#goals-2030"]').count()===0,'Goal 2030 is not a standalone primary menu destination');
 check(await page.locator('.site-footer a[href*="#goals-2030"]').count()===1,'Goal 2030 remains discoverable as a content section from footer');
 check((await page.locator('link[href*="layout-refinement.css"]').count())===1,'Shared refinement stylesheet loads once');
 for(const width of sizes){
  await page.setViewportSize({width,height:900});
  await page.evaluate(()=>window.scrollTo(0,0));
  const info=await page.evaluate(()=>{
   const rect=el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};};
   const items=[...document.querySelectorAll('.public-explore-strip a')].map(rect);
   const header=rect(document.querySelector('.site-header'));
   const headerContent=rect(document.querySelector('.site-header .container'));
   const strip=rect(document.querySelector('.public-explore-strip'));
   const hero=rect(document.querySelector('.home-hero'));
   const heroContent=rect(document.querySelector('.home-hero > .container'));
   const linkOverlaps=[];
   for(let i=0;i<items.length;i++)for(let j=i+1;j<items.length;j++){
    const ix=Math.min(items[i].right,items[j].right)-Math.max(items[i].left,items[j].left);
    const iy=Math.min(items[i].bottom,items[j].bottom)-Math.max(items[i].top,items[j].top);
    if(ix>1&&iy>1)linkOverlaps.push([i,j]);
   }
   return {overflow:document.documentElement.scrollWidth-innerWidth,header,headerContent,strip,hero,heroContent,items,linkOverlaps};
  });
  if(info.overflow>2){const offenders=await page.evaluate(()=>[...document.body.querySelectorAll('*')].map(el=>({tag:el.tagName,cls:(el.className?.baseVal||el.className||'').toString().slice(0,90),rect:el.getBoundingClientRect().toJSON()})).filter(x=>x.rect.right>innerWidth+3||x.rect.left< -3).slice(0,18));console.log('OVERFLOW DIAGNOSTIC',width,JSON.stringify(offenders));}
  check(info.overflow<=2,'No horizontal document overflow at '+width+'px (overflow '+info.overflow+')');
  check(info.linkOverlaps.length===0,'Secondary navigation links never collide at '+width+'px: '+JSON.stringify(info.linkOverlaps));
  check(info.items.every(r=>r.width>=60&&r.height>=34&&r.left>=-1&&r.right<=width+1),'Discover links fit and remain clickable at '+width+'px');
  check(info.header.bottom<=info.strip.top+2&&info.strip.bottom<=info.hero.top+2,'Header, tabs and hero remain in document order at '+width+'px');
  check(Math.abs(info.headerContent.left-info.heroContent.left)<=2&&Math.abs(info.headerContent.right-info.heroContent.right)<=2,'Public sections share horizontal edges at '+width+'px');
  const menu=page.locator('[data-menu]'),nav=page.locator('[data-main-nav]');
  if(width<=1180){
   check(await menu.isVisible(),'Menu available at '+width+'px');
   await menu.click();
   check(await nav.isVisible(),'Menu expands at '+width+'px');
   if(width<=440)check(await nav.locator('.nav-mobile-book').isVisible(),'Booking accessible when compact header hides action at '+width+'px');
   await page.keyboard.press('Escape');
   check(!(await nav.isVisible()),'Escape closes the menu at '+width+'px');
  }else{
   check(!(await menu.isVisible())&&await nav.isVisible(),'Full navigation shown at '+width+'px');
  }
 }
 for(const width of [320,390,768,1180,1440]){
  await page.setViewportSize({width,height:900});
  await page.goto(host+'public-explore-layout.html');
  const widths=await page.evaluate(()=>{
   const a=document.querySelector('.site-header .container').getBoundingClientRect();
   const b=document.querySelector('.ux-page').getBoundingClientRect();
   return {left:Math.abs(a.left-b.left),right:Math.abs(a.right-b.right),overflow:document.documentElement.scrollWidth-innerWidth};
  });
  check(widths.left<=2&&widths.right<=2&&widths.overflow<=2,'New public pages match site container gutters at '+width+'px');
  check(await page.locator('.public-explore-all[aria-current="page"]').count()===1,'Current section is signalled in discovery navigation at '+width+'px');
 }
 // Public search: its live PHP view, not the obsolete .search-form selector.
 for(const width of [320,360,390,600,740,768,1024,1180,1440]){
  await page.setViewportSize({width,height:900});
  await page.goto(host+'public-search.html');
  check(await page.locator('.search-page-form [name="q"]').count()===1,'Search input exists at '+width+'px');
  check(await page.locator('.search-page-form [name="type"]').count()===1,'Search type remains selectable at '+width+'px');
  const rects=await page.evaluate(()=>{
   const take=el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};};
   const form=document.querySelector('.search-page-form'),query=document.querySelector('#public-search'),type=document.querySelector('#search-type'),button=document.querySelector('.search-submit');
   const head=document.querySelector('.site-header .container'),section=document.querySelector('.page-intro > .container');
   return{form:take(form),query:take(query),type:take(type),button:take(button),head:take(head),section:take(section),overflow:document.documentElement.scrollWidth-innerWidth};
  });
  check(rects.overflow<=2,'Public search has no viewport overflow at '+width+'px');
  check(Math.abs(rects.head.left-rects.section.left)<=2&&Math.abs(rects.head.right-rects.section.right)<=2,'Search page margins match site header at '+width+'px');
  check([rects.query,rects.type,rects.button].every(r=>r.width>=44&&r.height>=44&&r.left>=rects.form.left-1&&r.right<=rects.form.right+1),'All search controls fit inside form at '+width+'px');
  const forms=[rects.query,rects.type,rects.button],overlaps=[];
  for(let i=0;i<forms.length;i++)for(let j=i+1;j<forms.length;j++){
   const x=Math.min(forms[i].right,forms[j].right)-Math.max(forms[i].left,forms[j].left);
   const y=Math.min(forms[i].bottom,forms[j].bottom)-Math.max(forms[i].top,forms[j].top);
   if(x>1&&y>1)overlaps.push([i,j]);
  }
  check(overlaps.length===0,'Search controls do not overlap at '+width+'px');
  if(width<=1180){
   await page.locator('[data-menu]').click();
   check(await page.locator('.nav-mobile-search').isVisible(),'Search remains reachable from mobile menu at '+width+'px');
   await page.keyboard.press('Escape');
  }
 }
 await page.goto(host+'public-search-results.html');
 check(await page.locator('.topic-pills [aria-current="page"]').count()===1,'Current search-result filter is marked');
 check((await page.locator('#public-search').inputValue())==='enterprise','Search query retained for editing');
 // Repeatable location hierarchy from actual shared PHP partials for every eligible journey.
 for(const prefix of ['contact','booking','founder','consent','promote','opportunity','client-contact']){
  await page.setViewportSize({width:390,height:900});
  await page.goto(host+'location-'+prefix+'.html');
  const f=page.locator('[data-location-fields]');
  check((await f.locator('[name="country"]').inputValue())==='Kenya','Kenya residence default in '+prefix);
  check(await f.locator('[name="ward"]').isVisible(),'Ward available for Kenya in '+prefix);
  check(await f.locator('[name="county"] option').count()===48,'47 county choices and empty choice in '+prefix);
  if(prefix!=='opportunity')check((await f.locator('[name="phone_code"]').inputValue())==='+254','Independent +254 phone default in '+prefix);
  await f.locator('[name="county"]').selectOption('Nyeri');
  await f.locator('[name="sub_county"]').fill('Kieni West');
  await f.locator('[name="ward"]').fill('Mweiga');
  await f.locator('[name="city"]').fill('Endarasha');
  await f.locator('[name="country"]').selectOption('United Kingdom');
  check(await f.locator('[name="ward"]').isHidden()&&await f.locator('[name="ward"]').isDisabled(),'Kenyan Ward is inactive outside Kenya in '+prefix);
  check(await f.locator('[name="region"]').isVisible(),'International region is active in '+prefix);
  if(prefix!=='opportunity')check((await f.locator('[name="phone_code"]').inputValue())==='+254','Residence switch does not alter calling code in '+prefix);
  await f.locator('[name="region"]').fill('England');
  await f.locator('[name="country"]').selectOption('Kenya');
  check((await f.locator('[name="ward"]').inputValue())==='Mweiga'&&(await f.locator('[name="county"]').inputValue())==='Nyeri','Kenya location is retained on country switch in '+prefix);
  check(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth<=2),'No narrow-screen overflow in '+prefix+' form');
 }
 // No-JavaScript forms keep both labelled alternatives, with server choice controlling parsing.
 const noJS=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:800}});
 const fallback=await noJS.newPage();
 await fallback.goto(host+'location-founder.html');
 check(await fallback.locator('[name="ward"]').isVisible()&&await fallback.locator('[name="region"]').isVisible(),'No-script fallback retains both explicit structures');
 await noJS.close();

 const evidence=process.env.EXPERIENCE_EVIDENCE_DIR;
 if(evidence){
  fs.mkdirSync(evidence,{recursive:true});
  await page.goto(host+'public-home.html');
  for(const width of [390,1440]){
   await page.setViewportSize({width,height:900});
   await page.screenshot({path:evidence+'/public-navigation-'+width+'.png',fullPage:true});
  }
 }
 check(errors.length===0,'No browser errors: '+errors.join('; '));
 console.log('PASS: '+checks+' public responsive-layout checks across '+sizes.length+' viewport widths.');
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
