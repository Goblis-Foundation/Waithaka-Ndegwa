'use strict';
const fs=require('node:fs'),assert=require('node:assert/strict'),vm=require('node:vm');
const root=require('node:path').resolve(__dirname,'..');
const manifest=JSON.parse(fs.readFileSync(root+'/manifest.webmanifest','utf8'));
const writerManifest=JSON.parse(fs.readFileSync(root+'/writer.webmanifest','utf8'));
assert.equal(manifest.start_url,'/','Public PWA opens Home, not private Writer');
assert.equal(writerManifest.start_url,'/write/communication','Writer PWA opens private Communications Centre');
assert.equal(writerManifest.scope,'/write/');
assert.deepEqual(manifest.icons,writerManifest.icons,'Both PWA contexts share exact WN app icon identity');
assert.deepEqual(new Set(manifest.icons.map(i=>i.purpose)),new Set(['any','maskable']));
const edge=JSON.parse(fs.readFileSync(root+'/deploy/aws/edge-stack.json','utf8'));
const allowed=edge.Resources.StaticCache.Properties.CachePolicyConfig.ParametersInCacheKeyAndForwardedToOrigin.QueryStringsConfig.QueryStrings;
assert(allowed.includes('v'));
for(const icon of manifest.icons){const url=new URL(icon.src,'https://www.waithakandegwa.com');assert.equal(url.searchParams.get('v'),'20261028b');assert.deepEqual([...url.searchParams.keys()],['v']);}
for(const file of ['views/layout.php','views/write/layout.php']){
 const html=fs.readFileSync(root+'/'+file,'utf8');for(const name of ['favicon.ico','favicon.svg','apple-touch-icon.png'])assert(html.includes(name+'?v=20261028b'),file+' must version '+name);
}
const handlers={},keys=[],context={URL,Promise,self:{location:{origin:'https://www.waithakandegwa.com'},addEventListener:(type,fn)=>{handlers[type]=fn;}},caches:{open:async()=>({addAll:async urls=>keys.push(...urls)}),match:async key=>({key})},fetch:async()=>{throw new Error('Unexpected network in offline icon fixture');}};
vm.runInNewContext(fs.readFileSync(root+'/sw.js','utf8'),context);
(async()=>{
 let install;handlers.install({waitUntil:p=>{install=p;}});await install;assert(keys.includes('/assets/images/icon-192.png?v=20261028b'));
 let reply;handlers.fetch({request:{method:'GET',mode:'cors',url:'https://www.waithakandegwa.com/assets/images/icon-192.png'},respondWith:p=>{reply=p;}});assert.equal((await reply).key,'/assets/images/icon-192.png?v=20261028b');
 let cached=false;handlers.fetch({request:{method:'GET',mode:'cors',url:'https://www.waithakandegwa.com/api/visitor-statistics'},respondWith:()=>{cached=true;}});assert.equal(cached,false);
 console.log('PASS: CDN-recognised icon versioning, public/private favicon links, offline icon rollover and live-API cache exclusion.');
})().catch(error=>{console.error(error);process.exitCode=1;});
