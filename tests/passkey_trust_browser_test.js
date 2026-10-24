'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../assets/js/app-security.js'),'utf8');
async function check(checked){
 let click;const requests=[],notice={textContent:''},location={href:''};
 const document={body:{dataset:{csrf:'fixture-csrf',writerId:''}},querySelector(selector){if(selector==='[data-security-status]')return notice;if(selector==='[data-passkey-login]')return {addEventListener(name,fn){click=fn;}};if(selector==='[name="trust_device"]')return {checked};return null;}};
 const bytes=new Uint8Array([1,2,3]).buffer;
 const context={document,location,window:{PublicKeyCredential:function(){}},navigator:{credentials:{async get(){return {rawId:bytes,response:{clientDataJSON:bytes,authenticatorData:bytes,signature:bytes,userHandle:bytes}};}}},FormData,Uint8Array,atob,btoa,fetch:async(url,options)=>{requests.push({url,form:options.body});return {json:async()=>options.body.get('action')==='login_options'?{ok:true,options:{publicKey:{challenge:'AQID',allowCredentials:[]}}}:{ok:true}}};
 vm.runInNewContext(source,context);assert.equal(typeof click,'function');await click();assert.equal(notice.textContent,'');assert.equal(requests.length,2);assert.equal(requests[1].form.get('trust_device'),checked?'1':'0');assert.equal(requests[1].form.get('csrf'),'fixture-csrf');assert.equal(location.href,'/write/communication');
}
(async()=>{await check(false);await check(true);console.log('PASS: passkey sign-in submits both shared/private device choices explicitly.');})().catch(e=>{console.error(e);process.exit(1);});
