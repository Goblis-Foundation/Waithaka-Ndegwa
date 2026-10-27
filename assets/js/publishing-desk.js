/* Deterministic branded exports: approved photos are composited, never regenerated. */
(() => {
  'use strict';
  const presets = {landscape:[1200,630],square:[1080,1080],portrait:[1080,1350],story:[1080,1920]};
  const navy='#073b69',green='#257547';
  function wrap(ctx,text,width,maxLines) {
    const words=String(text).trim().split(/\s+/); const lines=[]; let line='';
    for(const word of words){const candidate=line?line+' '+word:word;if(line&&ctx.measureText(candidate).width>width){lines.push(line);line=word;}else line=candidate;}
    if(line)lines.push(line);return lines.length<=maxLines?lines:null;
  }
  function fittedLines(ctx,text,width,maxLines,maxSize,minSize) {
    for(let s=maxSize;s>=minSize;s-=2){ctx.font=`600 ${s}px Georgia, serif`;const lines=wrap(ctx,text,width,maxLines);if(lines&&lines.every(line=>ctx.measureText(line).width<=width))return {size:s,lines};}
    throw new Error('The headline is too long for this size. Shorten the banner headline; the article title stays unchanged.');
  }
  function multiline(ctx,lines,x,y,size,color){ctx.fillStyle=color;ctx.font=`600 ${size}px Georgia, serif`;for(const line of lines){ctx.fillText(line,x,y);y+=size*1.18;}return y;}
  function label(ctx,text,x,y,size,maxWidth,color=navy){let s=size;ctx.font=`600 ${s}px Arial, sans-serif`;while(ctx.measureText(text).width>maxWidth&&s>12){s-=1;ctx.font=`600 ${s}px Arial, sans-serif`;}ctx.fillStyle=color;ctx.fillText(text,x,y);}
  function scene(ctx,theme,x,y,w,h){
    ctx.save();ctx.translate(x,y);ctx.scale(w/500,h/500);ctx.lineCap='round';ctx.lineJoin='round';
    const bg=ctx.createLinearGradient(0,0,500,500);bg.addColorStop(0,'#e8f4f6');bg.addColorStop(1,'#b9dfce');ctx.fillStyle=bg;ctx.beginPath();ctx.arc(250,250,245,0,Math.PI*2);ctx.fill();
    ctx.strokeStyle=navy;ctx.fillStyle=navy;ctx.lineWidth=10;
    if(theme==='agriculture'){
      ctx.fillStyle=green;for(const [a,b,r]of[[190,180,-.7],[310,155,.7],[195,285,-.7]]){ctx.save();ctx.translate(a,b);ctx.rotate(r);ctx.beginPath();ctx.ellipse(0,0,50,100,0,0,Math.PI*2);ctx.fill();ctx.restore();}
      ctx.strokeStyle=green;ctx.beginPath();ctx.moveTo(250,400);ctx.quadraticCurveTo(215,270,260,95);ctx.stroke();ctx.strokeStyle=navy;for(let i=0;i<3;i++){ctx.beginPath();ctx.moveTo(95,390+i*27);ctx.quadraticCurveTo(250,330+i*27,405,390+i*27);ctx.stroke();}
    }else if(theme==='conference'){
      ctx.fillStyle=navy;for(const cx of[130,250,370]){ctx.beginPath();ctx.arc(cx,165,35,0,Math.PI*2);ctx.fill();ctx.beginPath();ctx.roundRect(cx-47,220,94,110,24);ctx.fill();}
      ctx.fillStyle=green;ctx.beginPath();ctx.roundRect(85,60,330,55,15);ctx.fill();ctx.strokeStyle=green;ctx.beginPath();ctx.moveTo(110,365);ctx.lineTo(390,365);ctx.stroke();
    }else if(theme==='innovation'){
      ctx.strokeStyle=navy;ctx.beginPath();ctx.arc(250,220,105,0,Math.PI*2);ctx.stroke();ctx.fillStyle=green;ctx.beginPath();ctx.moveTo(215,320);ctx.lineTo(285,320);ctx.lineTo(285,385);ctx.lineTo(215,385);ctx.closePath();ctx.fill();
      ctx.strokeStyle=green;for(let i=0;i<8;i++){const a=i*Math.PI/4;ctx.beginPath();ctx.moveTo(250+140*Math.cos(a),220+140*Math.sin(a));ctx.lineTo(250+170*Math.cos(a),220+170*Math.sin(a));ctx.stroke();}ctx.beginPath();ctx.moveTo(215,230);ctx.lineTo(244,260);ctx.lineTo(295,185);ctx.stroke();
    }else{
      for(let i=0;i<4;i++){ctx.fillStyle=i%2?green:navy;ctx.beginPath();ctx.roundRect(80+i*90,360-i*55,60,80+i*55,10);ctx.fill();}
      ctx.strokeStyle=green;ctx.beginPath();ctx.moveTo(85,260);ctx.lineTo(170,205);ctx.lineTo(245,210);ctx.lineTo(385,85);ctx.stroke();ctx.beginPath();ctx.moveTo(320,85);ctx.lineTo(385,85);ctx.lineTo(385,150);ctx.stroke();
    }
    ctx.restore();
  }
  async function image(url){return new Promise((resolve,reject)=>{const img=new Image();img.onload=()=>resolve(img);img.onerror=()=>reject(new Error('The selected image could not be read.'));img.src=url;});}
  function placePhoto(ctx,photo,x,y,w,h){const k=Math.min(w/photo.width,h/photo.height);const dw=photo.width*k,dh=photo.height*k;ctx.drawImage(photo,x+(w-dw)/2,y+h-dh,dw,dh);}
  for(const root of document.querySelectorAll('[data-desk-studio]')){
    let cfg;try{cfg=JSON.parse(root.dataset.config);}catch(_){continue;}
    const canvas=root.querySelector('[data-banner-canvas]'),ctx=canvas.getContext('2d');
    const title=root.querySelector('[data-banner-title]'),preset=root.querySelector('[data-banner-preset]'),theme=root.querySelector('[data-banner-theme]'),status=root.querySelector('[data-banner-status]');
    let photo=null,logo=null,valid=false,aiBackground=false;
    const setStatus=(text)=>{status.textContent=text;};
    function draw(){
      valid=false;try{
        const [w,h]=presets[preset.value];canvas.width=w;canvas.height=h;const vertical=h>w*1.4,wide=w>h*1.4;const m=wide?54:64;
        ctx.fillStyle='#fff';ctx.fillRect(0,0,w,h);const g=ctx.createLinearGradient(0,0,w,h);g.addColorStop(0,'#edf5f8');g.addColorStop(1,'#ffffff');ctx.fillStyle=g;ctx.fillRect(0,0,w,h);
        if(logo)ctx.drawImage(logo,m,vertical?140:45,110,52);label(ctx,'Waithaka Ndegwa',m+132,(vertical?140:45)+28,wide?25:31,w-m*2-132);
        label(ctx,'People · Ideas · Impact',m+132,(vertical?140:45)+56,18,w-m*2-132,green);
        const top=vertical?300:(wide?155:165);label(ctx,cfg.category+(cfg.sponsor?' · '+cfg.sponsor:''),m,top,wide?19:23,w-m*2,green);
        const tw=wide?w*.55:w-m*2;const fitted=fittedLines(ctx,title.value,tw,wide?4:4,wide?56:70,34);
        const end=multiline(ctx,fitted.lines,m,top+50,fitted.size,navy);
        if(cfg.deadline)label(ctx,'Deadline: '+cfg.deadline,m,end+25,wide?20:24,tw,green);
        const visual=wide?{x:w*.64,y:142,w:w*.32,h:h-230}:{x:m+40,y:Math.max(end+85,h*.44),w:w-m*2-80,h:vertical?600:Math.min(370,h*.35)};
        if(aiBackground)label(ctx,'Illustration',visual.x,visual.y-10,15,visual.w,green);
        if(photo)placePhoto(ctx,photo,visual.x,visual.y,visual.w,visual.h);else scene(ctx,theme.value,visual.x,visual.y,visual.w,visual.h);
        const fy=vertical?h-285:h-115;ctx.fillStyle=green;ctx.beginPath();ctx.moveTo(0,fy-20);ctx.bezierCurveTo(w*.3,fy-65,w*.6,fy+15,w,fy-25);ctx.lineTo(w,h);ctx.lineTo(0,h);ctx.closePath();ctx.fill();ctx.fillStyle=navy;ctx.beginPath();ctx.moveTo(0,fy);ctx.bezierCurveTo(w*.3,fy-40,w*.7,fy+40,w,fy);ctx.lineTo(w,h);ctx.lineTo(0,h);ctx.closePath();ctx.fill();
        if(wide){label(ctx,'www.waithakandegwa.com',m,fy+65,23,w*.63,'#fff');label(ctx,'@iwaithakaw',w*.75,fy+65,23,w*.23,'#fff');}
        else{label(ctx,'www.waithakandegwa.com',m,fy+58,26,w-m*2,'#fff');label(ctx,'@iwaithakaw',m,fy+96,25,w-m*2,'#fff');}
        valid=true;setStatus(`${w} × ${h} JPEG ready. Review the headline, deadline and image permissions before saving.`);
      }catch(e){setStatus(e.message);}
    }
    for(const field of[title,preset,theme])field.addEventListener('input',draw);
    root.querySelector('[data-banner-photo]').addEventListener('change',async(e)=>{const file=e.target.files[0];if(!file){photo=null;aiBackground=false;draw();return;}if(!['image/png','image/jpeg','image/webp'].includes(file.type)||file.size>12*1024*1024){setStatus('Choose a JPEG, PNG or WebP up to 12 MB.');return;}const local=URL.createObjectURL(file);try{photo=await image(local);aiBackground=false;if(photo.width*photo.height>40000000)throw new Error('Use an image up to 40 megapixels.');draw();}catch(err){photo=null;setStatus(err.message);}finally{URL.revokeObjectURL(local);}});
    async function blob(){if(!valid)throw new Error('Resolve the preview issue first.');return new Promise((resolve,reject)=>canvas.toBlob(b=>b?resolve(b):reject(new Error('Export failed.')),'image/jpeg',.95));}
    root.querySelector('[data-banner-download]').addEventListener('click',async()=>{try{const b=await blob();const link=document.createElement('a');link.download=`Waithaka-Ndegwa_${preset.value}_${canvas.width}x${canvas.height}.jpg`;link.href=URL.createObjectURL(b);link.click();setTimeout(()=>URL.revokeObjectURL(link.href),1500);}catch(e){setStatus(e.message);}});
    root.querySelector('[data-banner-save]').addEventListener('click',async(e)=>{
      if(!root.querySelector('[data-banner-ack]').checked){setStatus('Confirm the review and image permissions first.');return;}
      const alt=root.querySelector('[data-banner-alt]').value.trim();if(!alt){setStatus('Add an accurate description of the visible banner.');return;}
      const button=e.currentTarget;button.disabled=true;
      try{const b=await blob();const form=new FormData();form.set('csrf',cfg.csrf);form.set('version',String(cfg.version));form.set('preset',preset.value);form.set('alt',alt);form.set('media_ack','1');form.set('ai_generated',aiBackground?'1':'');form.set('banner',b,'banner.jpg');
        // Use the same CSRF field name as the server-rendered forms.
        const csrf=document.querySelector('input[type="hidden"][name*="csrf"]');if(csrf)form.set(csrf.name,csrf.value);
        const result=await fetch(cfg.endpoint,{method:'POST',body:form,credentials:'same-origin',redirect:'error',headers:{'Accept':'application/json'}});const data=await result.json();if(!result.ok||!data.ok)throw new Error(data.error||'The creative was not saved.');setStatus('Saved. Reloading the current record…');location.assign(location.pathname+'#creatives');location.reload();
      }catch(err){setStatus(err.message||'Upload could not be confirmed. Check the current record before retrying.');button.disabled=false;}
    });
    image(cfg.logo).then(async img=>{logo=img;if(cfg.background){photo=await image(cfg.background);aiBackground=true;}draw();}).catch(()=>{setStatus('The approved logo is unavailable. Do not save a substitute.');});
  }
})();
