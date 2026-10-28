(()=>{'use strict';
 document.querySelectorAll('[data-auth-qr]').forEach(box=>{
  try{const uri=box.dataset.authQr;if(!/^otpauth:\/\/totp\//.test(uri)||typeof qrcode!=='function')return;
   const qr=qrcode(0,'M');qr.addData(uri,'Byte');qr.make();const n=qr.getModuleCount(),ns='http://www.w3.org/2000/svg',svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 '+(n+8)+' '+(n+8));svg.setAttribute('role','img');svg.setAttribute('aria-label','Scan with your authenticator app');
   const bg=document.createElementNS(ns,'rect');bg.setAttribute('width','100%');bg.setAttribute('height','100%');bg.setAttribute('fill','#fff');svg.append(bg);let d='';
   for(let r=0;r<n;r++)for(let c=0;c<n;c++)if(qr.isDark(r,c))d+='M'+(c+4)+','+(r+4)+'h1v1h-1z';const path=document.createElementNS(ns,'path');path.setAttribute('d',d);path.setAttribute('fill','#000');svg.append(path);box.replaceChildren(svg);box.removeAttribute('data-auth-qr');
  }catch(_){box.textContent='Enter the setup key manually in your authenticator.';}
 });
})();
