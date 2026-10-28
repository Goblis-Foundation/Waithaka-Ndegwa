'use strict';
/* Centering and placement acceptance for the JS-generated "On this page"
 * selector. Offline mode uses 16 isolated PHP fixtures; LIVE mode checks real
 * public pages with GET-only, first-party requests. No form, payment, account
 * or provider action is performed. */
const {chromium}=require('playwright');
const fs=require('node:fs');
const live=process.env.OUTLINE_LIVE==='1';
const base=live?'https://www.waithakandegwa.com':'http://127.0.0.1:8125';
const fixturePaths=[
 '/public-home.html',
 ...['start-here','startup-journeys','founder-stories','resources','watch',
 'enterprises','employment','opportunities','partner-stories','monthly-roundup',
 'newsletter','explore','advertise','advertising-policy','promotion-intake']
 .map(x=>'/sitewide-'+x+'.html')
];
const livePaths=['/','/start-here','/my-thoughts','/startup-journeys','/founders',
 '/founders/submit','/resources','/watch','/enterprises','/opportunities',
 '/opportunities/jobs','/opportunities/submit','/partner-stories',
 '/advertise','/advertising-policy','/promote','/monthly-roundup',
 '/about','/contact','/newsletter','/contribute','/book','/explore','/search',
 '/professional-information'];
const pages=live?livePaths:fixturePaths;
const keyPages=new Set(live?['/','/start-here']:['/public-home.html','/sitewide-start-here.html']);
const checksWidths=[320,360,390,440,600,740,768,820,1024,1180,1181,1280,1440];
(async()=>{
 let checks=0;
 const check=(value,why)=>{if(!value)throw new Error(why);checks++;};
 const browser=await chromium.launch({headless:true});
 const used=new Set();
 let externalBlocked=0,blockedMethods=0;
 try{
  const context=await browser.newContext({viewport:{width:1440,height:900},reducedMotion:'reduce',serviceWorkers:'block'});
  await context.route('**/*',route=>{
   const q=route.request(),url=q.url();
   if(!url.startsWith(base+'/')){externalBlocked++;return route.abort();}
   if(q.method()!=='GET'||(live&&!['document','stylesheet','script','image','font'].includes(q.resourceType()))){
    blockedMethods++;return route.abort();
   }
   return route.continue();
  });
  const page=await context.newPage();
  const images=process.env.OUTLINE_EVIDENCE_DIR||'';
  if(images)fs.mkdirSync(images,{recursive:true});
  for(const path of pages){
   const widths=keyPages.has(path)?checksWidths:[390,1440];
   for(const width of widths){
    await page.setViewportSize({width,height:900});
    const response=await page.goto(base+path,{waitUntil:'load',timeout:35000});
    check(!!response&&response.status()===200, path+' returns HTTP 200 at '+width+'px');
    const state=await page.evaluate(()=>{
     const content=document.querySelector('#main');
     const rect=el=>{const r=el.getBoundingClientRect();return {left:r.left,right:r.right,top:r.top,width:r.width,height:r.height};};
     const header=document.querySelector('.site-header .container');
     const navs=[...content.querySelectorAll('nav.ux-page-outline')];
     const nav=navs[0]||null;
     const selectors=[...content.querySelectorAll('h2')].filter(h=>h.getClientRects().length>0
       &&!h.closest('[hidden],.promote-experience,.ux-page-heading,.ux-stat-grid,dialog,[data-no-outline]'));
     const explicit=!!content.querySelector('.personal-page-nav');
     const first=content.querySelector('main,article,section');
     let root=first;
     while(root&&root.parentElement&&root.parentElement!==content)root=root.parentElement;
     const options=nav?[...nav.querySelectorAll('option')].map(o=>({value:o.value,text:o.textContent})):null;
     return{
      viewWidth:innerWidth,overflow:document.documentElement.scrollWidth-innerWidth,
      generated:navs.length,headingCount:selectors.length,explicit,
      navRoot:!!nav&&nav.parentElement===content,
      rootBefore:!!nav&&!!root&&nav.nextElementSibling===root,
      classes:nav?.className||'',marker:nav?.hasAttribute('data-page-outline')||false,
      label:nav?.querySelector('label')?.textContent?.trim().slice(0,20)||'',
      nav:nav?rect(nav):null,header:header?rect(header):null,
      select:nav?.querySelector('select')?rect(nav.querySelector('select')):null,
      options
     };
    });
    const expected=state.headingCount>=3&&!state.explicit;
    check(state.generated===(expected?1:0),
      path+' has '+(expected?'one correctly generated':'no unnecessary')+' page selector at '+width+
      'px; headings='+state.headingCount+' explicit='+state.explicit+' actual='+state.generated);
    check(state.overflow<=2,path+' has no horizontal overflow at '+width+'px');
    if(keyPages.has(path))check(expected,path+' has eligible section headings to build the selector');
    if(state.generated){
     used.add(path);
     check(state.navRoot&&state.rootBefore,path+' selector is a sibling BEFORE the content surface, not inside the hero');
     check(state.classes.split(/\s+/).includes('container')&&state.marker,
       path+' selector uses centered site container and is idempotent');
     check(!!state.nav&&!!state.header&&Math.abs(state.nav.left-state.header.left)<=3
         &&Math.abs(state.nav.right-state.header.right)<=3,
       path+' selector aligns with BOTH site header margins at '+width+'px: '+JSON.stringify(state).slice(0,430));
     check(state.select&&state.select.left>=state.nav.left-1&&state.select.right<=state.nav.right+1
         &&state.select.height>=44,
       path+' dropdown fits inside panel at '+width+'px');
     check(state.options&&state.options.length>=4&&state.options[0].value==='',
       path+' section choices are useful and start with a non-navigation placeholder');
    }
    if(keyPages.has(path)&&width===390){
     const outline=page.locator('#main > nav.ux-page-outline');
     await outline.locator('select').selectOption({index:1});
     const focus=await page.evaluate(()=>{
      const s=document.querySelector('#main > nav.ux-page-outline select');
      return document.activeElement?.id===s.value&&!!s.value;
     });
     check(focus,path+' dropdown focuses the chosen on-page heading');
    }
    if(images&&keyPages.has(path)&&[390,1440].includes(width)){
     const name=(path.includes('start-here')?'start-here':'home')+'-'+width+'.png';
     await page.screenshot({path:images+'/section-selector-'+name,fullPage:false,animations:'disabled'});
    }
   }
  }
  const note=[...used].join(', ');
  check(used.size>=2,'Home and Start Here must both have the centered selector');
  console.log('PASS: '+checks+' '+(live?'LIVE read-only':'isolated PHP fixture')+
    ' on-page selector checks across '+pages.length+' pages.');
  console.log('Selector visible on these checked pages: '+note);
  console.log('No form, payment, email, social publication or provider call. External requests blocked: '+
    externalBlocked+'; non-read/browser API requests blocked: '+blockedMethods+'.');
 }finally{await browser.close();}
})().catch(err=>{console.error(err);process.exitCode=1;});
