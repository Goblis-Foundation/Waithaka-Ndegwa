'use strict';
// Execute the actual server-rendered bootstrap offline; no Google requests.
const assert=require('node:assert/strict');
const vm=require('node:vm');
const {execFileSync}=require('node:child_process');
const path=require('node:path');
const html=execFileSync('php',[path.join(__dirname,'analytics_tag_fixture.php')],{encoding:'utf8'});
assert.equal((html.match(/src="https:\/\/www\.googletagmanager\.com\/gtag\/js\?id=G-RN0YXC2WCH"/g)||[]).length,1);
assert.ok(!html.includes('PRIVATE_LINK')&&!html.includes('PRIVATE_EMAIL')&&!html.includes('PRIVATE_SEARCH'));
const script=html.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
let checks=2;
function check(test){assert.ok(test);checks++;}
function browser(choice,brokenStorage=false){
  const context={URL,Set,Date,document:{referrer:'https://example.org/private/path?token=NEVER_SEND#PRIVATE'},localStorage:{getItem(){if(brokenStorage)throw Error('blocked');return choice;}}};
  context.window=context;vm.createContext(context);vm.runInContext(script,context);
  context.commands=()=>Array.from(context.dataLayer,x=>Array.from(x));
  context.events=()=>context.commands().filter(x=>x[0]==='event');return context;
}
const fresh=browser(null);
check(fresh['ga-disable-G-RN0YXC2WCH']===true);
check(fresh.events().length===0);
check(fresh.commands().filter(x=>x[0]==='config').length===1);
const config=fresh.commands().find(x=>x[0]==='config');
check(config[1]==='G-RN0YXC2WCH'&&config[2].send_page_view===false);
check(config[2].page_referrer==='https://example.org/');
check(config[2].page_location==='https://www.waithakandegwa.com/my-thoughts/fixture?utm_source=newsletter&utm_medium=email&utm_campaign=post-12');
check(fresh.commands()[0][0]==='consent'&&fresh.commands()[0][2].analytics_storage==='denied');
fresh.wkAnalytics.track('episode_continue','post-1');check(fresh.events().length===0);
fresh.wkAnalytics.setConsent('analytics');fresh.wkAnalytics.setConsent('analytics');
check(fresh['ga-disable-G-RN0YXC2WCH']===false);
check(fresh.events().filter(x=>x[1]==='page_view').length===1);
fresh.wkAnalytics.track('episode_continue','post-1');fresh.wkAnalytics.track('episode_continue','post-1');
check(fresh.events().filter(x=>x[1]==='episode_continue').length===1);
const before=fresh.events().length;
for(const [name,id]of[['form_submit','post-1'],['resource_download','reader@example.com'],['resource_download','resource-3\nSECRET'],['resource_download','private-token']])fresh.wkAnalytics.track(name,id);
check(fresh.events().length===before);
fresh.wkAnalytics.setConsent('necessary');fresh.wkAnalytics.track('resource_download','resource-5');
check(fresh['ga-disable-G-RN0YXC2WCH']===true&&fresh.events().length===before);
fresh.wkAnalytics.setConsent('analytics');check(fresh.events().filter(x=>x[1]==='page_view').length===1);
for(const choice of ['necessary',null,'corrupt']){const page=browser(choice);check(page.events().length===0&&page['ga-disable-G-RN0YXC2WCH']===true);}
const returning=browser('analytics');check(returning.events().length===1&&returning.events()[0][1]==='page_view');
check(returning.commands()[0][2].ad_storage==='denied'&&returning.commands()[0][2].ad_user_data==='denied'&&returning.commands()[0][2].ad_personalization==='denied');
returning.wkAnalytics.track('resource_download','resource-template-problem-interview-worksheet');
check(returning.events().filter(x=>x[1]==='resource_download').length===1);
returning.wkAnalytics.track('resource_download','resource-template-unpublished-private-title');
check(returning.events().filter(x=>x[1]==='resource_download').length===1);
check(browser(null,true).events().length===0);
console.log(`Analytics browser-state checks passed: ${checks}`);
