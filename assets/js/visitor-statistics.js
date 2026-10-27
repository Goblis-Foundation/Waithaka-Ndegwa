/* Direct, consented live presence plus verified period totals. Never increment counters locally. */
(function(){
 'use strict';
 function present(snapshot,nowMs){
  const zone=typeof snapshot.timezone==='string'?snapshot.timezone:'Africa/Nairobi';
  let parts;try{parts=new Intl.DateTimeFormat('en-GB',{timeZone:zone,year:'numeric',month:'2-digit'}).formatToParts(new Date(nowMs));}catch(_){return null;}
  const year=parts.find(p=>p.type==='year').value,month=parts.find(p=>p.type==='month').value;
  const monthLabel=new Intl.DateTimeFormat('en',{timeZone:zone,month:'long'}).format(new Date(nowMs));
  const out={month_label:monthLabel,year_label:year,now:null,month:null,year:null};
  for(const key of ['now','month','year']){
   const value=snapshot[key],at=snapshot.as_of?.[key];
   if(snapshot.enabled===false||!Number.isSafeInteger(value)||value<0||value>999999999999999||!Number.isFinite(at)||at>nowMs/1000+5||nowMs/1000-at>(key==='now'?60:900))continue;
   if(key==='month'&&snapshot.month_key!==year+'-'+month)continue;
   if(key==='year'&&snapshot.year_key!==year)continue;
   out[key]=value;
  }
  return out;
 }
 function eligible(consent,visibility,online){return consent==='analytics'&&visibility==='visible'&&online!==false;}
 if(typeof module==='object'&&module.exports)module.exports={present,eligible};
 if(typeof document==='undefined')return;
 const root=document.querySelector('[data-visitor-statistics]');if(!root)return;
 let snapshot;try{snapshot=JSON.parse(root.dataset.statistics);}catch(_){return;}
 let offset=Number.isFinite(snapshot.server_time)?snapshot.server_time*1000-Date.now():0;
 let pending=false,controller=null,tab=null,sequence=0,participating=false,heartPending=false,lastHeartbeat=0;
 const number=new Intl.NumberFormat('en');
 function consent(){try{return localStorage.getItem('wk_consent');}catch(_){return null;}}
 function endpoint(value){const target=new URL(value,location.origin);if(target.origin!==location.origin)throw new Error('Foreign statistics endpoint');return target.href;}
 function draw(){
  const view=present(snapshot,Date.now()+offset);if(!view)return;
  for(const key of ['month','year']){const label=root.querySelector('[data-visitor-label="'+key+'"]');if(label)label.textContent=view[key+'_label'];}
  for(const key of ['now','month','year']){
   const item=root.querySelector('[data-visitor-period="'+key+'"]');if(!item)continue;
   const value=item.querySelector('[data-visitor-number]'),unit=item.querySelector('[data-visitor-unit]');
   if(value)value.textContent=view[key]===null?'—':number.format(view[key]);
   if(unit){unit.textContent=view[key]===1?'visitor':'visitors';unit.hidden=view[key]===null;}
   item.dataset.fresh=view[key]===null?'false':'true';
  }
  root.hidden=snapshot.enabled===false;
 }
 function accept(data){if(!Number.isFinite(data.server_time)||!present(data,data.server_time*1000))throw new Error('Invalid aggregate response');snapshot=data;offset=data.server_time*1000-Date.now();draw();}
 async function poll(){
  draw();if(pending||document.visibilityState!=='visible'||navigator.onLine===false)return;
  pending=true;controller=new AbortController();const timeout=setTimeout(()=>controller?.abort(),8000);
  try{
   const response=await fetch(endpoint(root.dataset.endpoint),{cache:'no-store',credentials:'same-origin',redirect:'error',signal:controller.signal,headers:{Accept:'application/json'}});
   if(!response.ok||!response.headers.get('content-type')?.includes('application/json'))throw new Error('Statistics not confirmed');accept(await response.json());
  }catch(_){draw();}finally{clearTimeout(timeout);pending=false;controller=null;}
 }
 function data(state){
  if(!tab){const bytes=new Uint8Array(16);crypto.getRandomValues(bytes);tab=Array.from(bytes,n=>n.toString(16).padStart(2,'0')).join('');}
  const body=new URLSearchParams();body.set('csrf',root.dataset.presenceCsrf);body.set('tab',tab);body.set('sequence',String(++sequence));body.set('state',state);body.set('consent',consent()==='analytics'?'analytics':'necessary');return body;
 }
 function leave(){
  if(!participating||!tab)return;participating=false;
  try{const body=data('left'),target=endpoint(root.dataset.presenceEndpoint);if(!navigator.sendBeacon?.(target,body))fetch(target,{method:'POST',body,credentials:'same-origin',cache:'no-store',keepalive:true,redirect:'error'}).catch(()=>{});}catch(_){}
 }
 async function heartbeat(){
  if(!eligible(consent(),document.visibilityState,navigator.onLine)||snapshot.enabled===false){leave();return;}
  if(heartPending||Date.now()-lastHeartbeat<15000)return;
  lastHeartbeat=Date.now();heartPending=true;participating=true;
  try{
   const response=await fetch(endpoint(root.dataset.presenceEndpoint),{method:'POST',body:data('visible'),credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'application/json'}});
   if(!response.ok)throw new Error('Presence not confirmed');const result=await response.json();if(result.ok!==true)throw new Error('Presence not confirmed');accept(result);
  }catch(_){}finally{heartPending=false;if(!eligible(consent(),document.visibilityState,navigator.onLine))leave();}
 }
 function tick(){draw();if(document.visibilityState==='visible'){window.wkAnalytics?.presence?.();heartbeat();poll();}else leave();}
 draw();tick();setInterval(tick,20000);
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'){lastHeartbeat=0;tick();}else{leave();controller?.abort();}});
 document.addEventListener('click',event=>{if(event.target.closest?.('[data-consent]'))setTimeout(()=>{lastHeartbeat=0;tick();},0);});
 window.addEventListener('pagehide',leave);window.addEventListener('pageshow',()=>{lastHeartbeat=0;tick();});window.addEventListener('online',()=>{lastHeartbeat=0;tick();});
 window.addEventListener('storage',event=>{if(event.key==='wk_consent'){try{window.wkAnalytics?.setConsent?.(consent());}catch(_){}lastHeartbeat=0;tick();}});
})();
