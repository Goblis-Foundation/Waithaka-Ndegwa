'use strict';
const {chromium}=require('playwright'),axe=require('axe-core'),assert=require('node:assert/strict'),fs=require('node:fs');
(async()=>{
 const browser=await chromium.launch({headless:true});const context=await browser.newContext({reducedMotion:'reduce'}),page=await context.newPage();
 const host='http://127.0.0.1:8125/';await context.route('**/*',r=>r.request().url().startsWith(host)?r.continue():r.abort());
 const results=[],violations=[];
 for(const file of ['module-login','module-login-error','module-program','module-desk-0','module-desk-1','module-impact','module-register','module-google-actions','module-google-new','module-google-review','module-google-sync','module-google-meet','module-google-meet-select','module-google-meet-saved','module-youtube','module-youtube-playlist','module-youtube-playlist_item','module-youtube-caption','module-youtube-thumbnail','module-youtube-moderate','module-youtube-review','module-youtube-pending','module-publishing-studio','module-instagram-reel-new','module-instagram-reel-confirmed','module-verify','module-auth-settings','module-directory','public-home','public-search','sitewide-newsletter','sitewide-opportunities','sitewide-explore']){
  for(const width of [390,1363]){
   await page.setViewportSize({width,height:900});await page.goto(host+file+'.html');if(file.startsWith('module-instagram-reel-'))await page.locator('main details').evaluate(e=>{e.open=true;});await page.addScriptTag({content:axe.source});
   const result=await page.evaluate(async()=>axe.run(document,{runOnly:{type:'tag',values:['wcag2a','wcag2aa','wcag21a','wcag21aa','wcag22aa']}}));
   results.push({file,width,passes:result.passes.length,incomplete:result.incomplete,violations:result.violations});
   for(const v of result.violations)violations.push({file,width,id:v.id,impact:v.impact,help:v.help,nodes:v.nodes.map(n=>({target:n.target,summary:n.failureSummary}))});
  }
 }
 await browser.close();const dir=process.env.EXPERIENCE_EVIDENCE_DIR||'/tmp/experience-evidence';fs.mkdirSync(dir,{recursive:true});fs.writeFileSync(dir+'/accessibility.json',JSON.stringify({engine:axe.version,results},null,2));
 assert.equal(violations.length,0,JSON.stringify(violations,null,2));console.log('PASS: '+results.length+' automated accessibility scans. Manual screen-reader and physical-device acceptance remains separate.');
})().catch(e=>{console.error(e);process.exit(1);});
