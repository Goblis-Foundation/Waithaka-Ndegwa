(()=>{'use strict';
 const form=document.getElementById('draft');if(!form)return;
 const summary=document.querySelector('[data-studio-summary]');
 function updateSummary(){if(!summary)return;const n=form.querySelectorAll('[data-channel-enabled]:checked').length;summary.textContent=n===0?'Choose the channels for this release.':`${n} channel${n===1?'':'s'} selected · Save draft, then review and approve.`;}
 for(const card of form.querySelectorAll('[data-studio-channel]')){
  const slug=card.dataset.studioChannel,caption=card.querySelector('.pub-caption'),format=card.querySelector(`[id="format-${slug}"]`),asset=card.querySelector(`[id="media-asset-${slug}"]`),media=card.querySelector(`[id="media-url-${slug}"]`),enabled=card.querySelector('[data-channel-enabled]');
  const text=card.querySelector('[data-preview-copy]'),meta=card.querySelector('[data-preview-meta]'),state=card.querySelector('[data-preview-state]'),copy=card.querySelector('[data-copy-caption]');
  function update(){
   text.textContent=caption.value.trim()||'Your caption preview appears here.';
   meta.textContent=`${format.options[format.selectedIndex].text} · ${asset&&asset.value!=='0'?'Owned media selected':media.value.trim()?'External media URL':'Text / link'}`;
   state.textContent=enabled.checked?'Included in this draft':'Not selected';card.classList.toggle('pub-channel-selected',enabled.checked);updateSummary();
  }
  card.addEventListener('input',update);card.addEventListener('change',update);update();
  if(copy)copy.addEventListener('click',async()=>{const feedback=card.querySelector('[data-copy-status]');try{await navigator.clipboard.writeText(caption.value);feedback.textContent='Caption copied. Review the audience in the platform before posting.';}catch(_){caption.focus();caption.select();feedback.textContent='Select and copy the caption using your keyboard or device menu.';}});
 }
 let dirty=false;form.addEventListener('input',()=>{dirty=true;});form.addEventListener('change',()=>{dirty=true;});form.addEventListener('submit',()=>{dirty=false;});
 window.addEventListener('beforeunload',e=>{if(!dirty)return;e.preventDefault();e.returnValue='';});
})();
