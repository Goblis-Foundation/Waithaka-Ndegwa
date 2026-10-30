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
 // Delay enhancement until after first paint: the existing selector must not push either hero down.
 for(const [file,hero] of [['public-home.html','.home-hero'],['sitewide-start-here.html','.editorial-hero']]){
  await page.setViewportSize({width:390,height:844});
  let release;const ready=new Promise(resolve=>{release=resolve;});
  const pattern='**/assets/js/unified-experience.js*';
  await page.route(pattern,async route=>{await ready;await route.continue();});
  await page.goto(host+file,{waitUntil:'commit'});
  await page.locator('[data-page-outline-pending]').waitFor({state:'visible'});
  const before=await page.locator(hero).evaluate(el=>el.getBoundingClientRect().top);
  check(await page.locator('[data-page-outline-pending] select').isDisabled(),'Server reserves disabled section selector before enhancement: '+file);
  release();await page.waitForLoadState('load');await page.unroute(pattern);
  const after=await page.locator(hero).evaluate(el=>el.getBoundingClientRect().top);
  check(Math.abs(after-before)<=1,'Section selector does not move the painted hero: '+file+' ('+before+' → '+after+')');
  check(await page.locator('[data-page-outline]').count()===1&&await page.locator('[data-page-outline] option').count()>3,'Server selector is hydrated once with real section targets: '+file);
 }
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
 // Search is the public CONTENT area immediately after the menu, not a menu item.
 // The former 1100px search form cap left a visible 80px right-edge mismatch
 // against the site's 1180px header and results containers.
 const searchWidths=[320,360,390,440,600,740,768,820,1024,1180,1181,1280,1440];
 for(const width of searchWidths){
  await page.setViewportSize({width,height:900});
  await page.goto(host+'public-search.html');
  check(await page.locator('.search-page-form [name="q"]').count()===1,'Search input exists at '+width+'px');
  check(await page.locator('.search-page-form [name="type"]').count()===1,'Search type remains selectable at '+width+'px');
  const rects=await page.evaluate(()=>{
   const take=el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,bottom:r.bottom,width:r.width,height:r.height};};
   const read=selector=>take(document.querySelector(selector));
   const form=document.querySelector('.search-page-form');
   const inputs=['#public-search','#search-type','.search-submit'].map(read);
   return {
    header:read('.site-header .container'),
    intro:read('.page-intro > .search-intro-container'),
    form:read('.search-page-form'),help:read('#search-form-help'),filters:read('.topic-pills'),
    heading:read('.page-intro h1'),inputs,
    formDisplay:getComputedStyle(form).display,
    formMax:getComputedStyle(form).maxWidth,
    overflow:document.documentElement.scrollWidth-innerWidth
   };
  });
  const near=(a,b)=>Math.abs(a-b)<=2;
  check(rects.overflow<=2,'Search area has no horizontal overflow at '+width+'px');
  check(near(rects.header.left,rects.intro.left)&&near(rects.header.right,rects.intro.right),
   'Search intro uses both shared header edges at '+width+'px');
  check(near(rects.intro.left,rects.form.left)&&near(rects.intro.right,rects.form.right),
   'Search panel aligns on BOTH edges with page container at '+width+'px');
  check(near(rects.intro.left,rects.filters.left)&&near(rects.intro.right,rects.filters.right)
   &&near(rects.intro.left,rects.help.left),'Topic filters and help text align with search at '+width+'px');
  check(near(rects.intro.left,rects.heading.left),'Search heading begins on the shared page edge at '+width+'px');
  check(rects.formDisplay==='grid'&&rects.formMax==='none','Search has active responsive grid without a standalone max-width at '+width+'px');
  check(rects.inputs.every(v=>v.width>=44&&v.height>=44&&v.left>=rects.form.left-1&&v.right<=rects.form.right+1),
   'Search controls fit the form at '+width+'px');
  const overlap=[];
  for(let i=0;i<rects.inputs.length;i++)for(let j=i+1;j<rects.inputs.length;j++){
   const a=rects.inputs[i],b=rects.inputs[j];
   if(Math.min(a.right,b.right)-Math.max(a.left,b.left)>1
       &&Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top)>1)overlap.push([i,j]);
  }
  check(overlap.length===0,'Search controls never overlap at '+width+'px');
  if(width<=1180){
   await page.locator('[data-menu]').click();
   check(await page.locator('.nav-mobile-search').isVisible(),'Existing mobile search navigation preserved at '+width+'px');
   await page.keyboard.press('Escape');
  }
  // A search with results must use exactly the same edges as the search area.
  await page.goto(host+'public-search-results.html');
  const found=await page.evaluate(()=>{
   const edges=selector=>{const r=document.querySelector(selector).getBoundingClientRect();return {left:r.left,right:r.right};};
   return {header:edges('.site-header .container'),intro:edges('.search-intro-container'),
    form:edges('.search-page-form'),filters:edges('.topic-pills'),
    results:edges('.search-results-container'),heading:edges('.search-results-container .archive-label'),
    grid:edges('.search-results-container .post-grid'),overflow:document.documentElement.scrollWidth-innerWidth};
  });
  check([found.intro,found.form,found.filters,found.results,found.heading,found.grid].every(v=>
   near(v.left,found.header.left)&&near(v.right,found.header.right)),
   'Search RESULTS, filters and search input align with header on both sides at '+width+'px');
  check(found.overflow<=2,'Search with results has no horizontal overflow at '+width+'px');
 }
 check(await page.locator('.topic-pills [aria-current="page"]').count()===1,'Current result type is marked');
 check((await page.locator('#public-search').inputValue())==='enterprise','Search query retained for editing');
 check(await page.locator('.post-grid .post-card').count()===1,'Results grid is exercised using synthetic public content');
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
 // Real contact form: mandatory details stay visible and only the active country branch validates.
 await page.goto(host+'public-contact.html');
 const contact=page.locator('[data-contact-form]');
 for(const width of sizes){
  await page.setViewportSize({width,height:900});
  check(await contact.locator('[name="phone"]').isVisible()&&await contact.locator('[name="country"]').isVisible(),'Contact phone and country visible at '+width+'px');
  check(await page.evaluate(()=>document.documentElement.scrollWidth-innerWidth<=2),'Contact has no horizontal overflow at '+width+'px');
 }
 for(const name of ['first_name','last_name','email','phone_code','phone','country','county','sub_county','ward','city','message','enquiry_type'])check(await contact.locator('[name="'+name+'"]').evaluate(el=>el.required),'Contact requires '+name);
 check(!(await contact.evaluate(form=>form.checkValidity())),'Empty contact form cannot submit');
 for(const [name,value] of Object.entries({first_name:'Amina',last_name:'Fixture',email:'amina@example.invalid',phone:'0712345678',sub_county:'Kieni West',ward:'Mweiga',city:'Endarasha',message:'Synthetic local fixture with enough enquiry context.'}))await contact.locator('[name="'+name+'"]').fill(value);
 await contact.locator('[name="county"]').selectOption('Nyeri');await contact.locator('[name="not_robot"]').check();
 check(await contact.evaluate(form=>form.checkValidity()),'Complete Kenya contact form validates without sending');
 await contact.locator('[name="country"]').selectOption('United Kingdom');
 check(await contact.locator('[name="county"]').evaluate(el=>el.disabled&&!el.required),'Kenya fields do not block international enquiry');
 check(await contact.locator('[name="region"]').evaluate(el=>el.required&&!el.disabled),'International region becomes mandatory');
 check(!(await contact.evaluate(form=>form.checkValidity())),'International administrative details are required');
 await contact.locator('[name="region"]').fill('England');await contact.locator('[name="district"]').fill('Westminster');
 check(await contact.evaluate(form=>form.checkValidity()),'Residence is independent of a valid Kenyan calling code');
 await contact.locator('[name="country"]').selectOption('Kenya');
 check(await contact.evaluate(form=>form.checkValidity()),'Switching back restores complete Kenya fields');
 // No-JavaScript forms keep both labelled alternatives, with server choice controlling parsing.
 const noJS=await browser.newContext({javaScriptEnabled:false,viewport:{width:390,height:800}});
 const fallback=await noJS.newPage();
 await fallback.goto(host+'location-founder.html');
 check(await fallback.locator('[name="ward"]').isVisible()&&await fallback.locator('[name="region"]').isVisible(),'No-script fallback retains both explicit structures');
 await fallback.goto(host+'public-contact.html');
 check(await fallback.getByRole('button',{name:'Update country fields'}).isVisible(),'Contact offers a no-script country refresh without sending');
 check(await fallback.locator('[name="phone"]').isVisible()&&await fallback.locator('[name="region"]').isDisabled(),'No-script contact shows applicable Kenya fields');
 await noJS.close();

 const evidence=process.env.EXPERIENCE_EVIDENCE_DIR;
 if(evidence){
  fs.mkdirSync(evidence,{recursive:true});
  await page.goto(host+'public-home.html');
  for(const width of [390,1440]){
   await page.setViewportSize({width,height:900});
   await page.screenshot({path:evidence+'/public-navigation-'+width+'.png',fullPage:true});
   await page.goto(host+'public-contact.html');
   await page.screenshot({path:evidence+'/contact-required-'+width+'.png',fullPage:true});
   await page.goto(host+'public-search.html');
   await page.screenshot({path:evidence+'/search-section-alignment-'+width+'.png',fullPage:true});
   await page.goto(host+'public-search-results.html');
   await page.screenshot({path:evidence+'/search-results-alignment-'+width+'.png',fullPage:true});
  }
 }
 check(errors.length===0,'No browser errors: '+errors.join('; '));
 console.log('PASS: '+checks+' public responsive-layout checks across '+sizes.length+' viewport widths.');
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1);});
