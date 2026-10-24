/* Document links are generated locally; never sent to a QR service. */
(function(root){
'use strict';
function safeDocumentUrl(value, origin) {
 try {
  const u=new URL(value,origin), base=new URL(origin);
  if(u.origin!==base.origin || !/^https?:$/.test(u.protocol) || u.username || u.password || u.search || u.hash) return null;
  if(!/^\/[a-zA-Z0-9_\/-]*$/.test(u.pathname)) return null;
  if(/^\/(?:consent|founders\/story-review)(?:\/|$)/.test(u.pathname)) return null;
  return u.href;
 } catch (_) { return null; }
}
function svgFor(value, makeQr) {
 const qr=makeQr(0,'M');qr.addData(value,'Byte');qr.make();
 const n=qr.getModuleCount(), quiet=4, size=n+quiet*2;
 let path='';
 for(let row=0;row<n;row++) for(let col=0;col<n;col++)
  if(qr.isDark(row,col))path+='M'+(col+quiet)+','+(row+quiet)+'h1v1h-1z';
 return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '+size+' '+size+'" role="img" aria-label="QR code to the online document" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path d="'+path+'" fill="#000"/></svg>';
}
function init(doc, origin, makeQr) {
 doc.querySelectorAll('[data-document-qr]').forEach(function(box){
  const value=safeDocumentUrl(box.getAttribute('data-document-qr'),origin);
  const graphic=box.querySelector('[data-document-qr-graphic]');
  if(!value || !graphic || typeof makeQr!=='function')return;
  try { graphic.innerHTML=svgFor(value,makeQr);box.setAttribute('data-qr-ready','true'); }
  catch (_) { box.setAttribute('data-qr-ready','false'); }
 });
 doc.querySelectorAll('[data-document-print]').forEach(function(button){
  button.hidden=false;button.addEventListener('click',function(){root.print();});
 });
}
if(typeof module==='object' && module.exports)module.exports={safeDocumentUrl,svgFor,init};
if(root.document)init(root.document,root.location.origin,root.qrcode);
})(typeof window==='object'?window:globalThis);
