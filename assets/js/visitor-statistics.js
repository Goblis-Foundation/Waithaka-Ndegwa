/* Live aggregate display. This never increments visitor totals in the browser. */
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
   if(snapshot.enabled===false||!Number.isSafeInteger(value)||value<0||value>999999999999999||!Number.isFinite(at)||at>nowMs/1000+5||nowMs/1000-at>(key==='now'?150:900))continue;
   if(key==='month'&&snapshot.month_key!==year+'-'+month)continue;
   if(key==='year'&&snapshot.year_key!==year)continue;
   out[key]=value;
  }
  return out;
 }
 if(typeof module==='object'&&module.exports)module.exports={present};
 if(typeof document==='undefined')return;
 const root=document.querySelector('[data-visitor-statistics]');if(!root)return;
 let snapshot;try{snapshot=JSON.parse(root.dataset.statistics);}catch(_){return;}
 let offset=Number.isFinite(snapshot.server_time)?snapshot.server_time*1000-Date.now():0;
 let pending=false,controller=null;
 const number=new Intl.NumberFormat('en');
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
 async function poll(){
  draw();if(pending||document.visibilityState!=='visible'||navigator.onLine===false)return;
  pending=true;controller=new AbortController();const timeout=setTimeout(()=>controller?.abort(),8000);
  try{
   const target=new URL(root.dataset.endpoint,location.origin);if(target.origin!==location.origin)throw new Error('Foreign statistics endpoint');
   const response=await fetch(target.href,{cache:'no-store',credentials:'same-origin',redirect:'error',signal:controller.signal,headers:{Accept:'application/json'}});
   if(!response.ok||!response.headers.get('content-type')?.includes('application/json'))throw new Error('Statistics not confirmed');
   const data=await response.json();if(!Number.isFinite(data.server_time)||!present(data,data.server_time*1000))throw new Error('Invalid aggregate response');
   snapshot=data;offset=data.server_time*1000-Date.now();draw();
  }catch(_){draw();}finally{clearTimeout(timeout);pending=false;controller=null;}
 }
 function tick(){draw();if(document.visibilityState==='visible'){window.wkAnalytics?.presence?.();poll();}}
 draw();tick();setInterval(tick,30000);
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')tick();else controller?.abort();});
 window.addEventListener('online',tick);
 window.addEventListener('storage',event=>{if(event.key==='wk_consent'){try{window.wkAnalytics?.setConsent?.(localStorage.getItem('wk_consent'));}catch(_){}tick();}});
})();
