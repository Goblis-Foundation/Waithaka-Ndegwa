'use strict';
const VERSION='wn-app-v6';const OFFLINE='/offline.html';
const BRAND_VERSION='20261029c';
const ICON_PATHS=['/assets/images/icon-192.png','/assets/images/icon-512.png'];
const iconKey=path=>path+'?v='+BRAND_VERSION;
self.addEventListener('install',e=>e.waitUntil(caches.open(VERSION).then(c=>c.addAll([OFFLINE,...ICON_PATHS.map(iconKey)]))));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('wn-app-')&&k!==VERSION).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('message',e=>{if(e.data?.type==='ACTIVATE')self.skipWaiting();});
// No private HTML, API responses or live counters in CacheStorage. Legacy icon
// paths resolve to the current CDN-versioned approved icon, including offline.
self.addEventListener('fetch',e=>{
 if(e.request.method!=='GET'||new URL(e.request.url).origin!==self.location.origin)return;
 if(e.request.mode==='navigate'){e.respondWith(fetch(e.request).catch(()=>caches.match(OFFLINE)));return;}
 const path=new URL(e.request.url).pathname;
 if(ICON_PATHS.includes(path)){const key=iconKey(path);e.respondWith(caches.match(key).then(r=>r||fetch(key)));return;}
 if(path===OFFLINE)e.respondWith(caches.match(OFFLINE).then(r=>r||fetch(e.request)));
});
self.addEventListener('push',e=>e.waitUntil((async()=>{let target='/write/communication',body='There is an update in your Communication Centre.';try{const r=await fetch('/write/communication/notification',{credentials:'include',cache:'no-store'});if(r.ok&&r.headers.get('content-type')?.includes('application/json')){const j=await r.json();if(j.category==='planner')body='A meeting or program item is coming up. Open your daily program.';if(typeof j.url==='string'&&j.url.startsWith('/write/communication?'))target=j.url;}}catch{}await self.registration.showNotification('Waithaka Ndegwa',{body:body,icon:iconKey('/assets/images/icon-192.png'),badge:iconKey('/assets/images/icon-192.png'),tag:'communication-update',data:{url:target}});})()));
self.addEventListener('notificationclick',e=>{e.notification.close();e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(async windows=>{const target=e.notification.data?.url;const safe=typeof target==='string'&&target.startsWith('/write/communication')?target:'/write/communication';const url=new URL(safe,self.location.origin).href;for(const w of windows)if(new URL(w.url).origin===self.location.origin){await w.navigate(url);return w.focus();}return self.clients.openWindow(url);}));});
