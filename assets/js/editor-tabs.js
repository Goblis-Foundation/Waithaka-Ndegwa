/* Settings stay usable without JavaScript; enhancement adds accessible tabs. */
(()=>{'use strict';
 const tabs=[...document.querySelectorAll('[data-editor-tab]')],panels=[...document.querySelectorAll('[data-editor-panel]')];
 if(!tabs.length||tabs.length!==panels.length)return;
 const names=tabs.map(t=>t.dataset.editorTab);
 if(names.some((name,i)=>name!==panels[i].dataset.editorPanel))return;
 const list=tabs[0].parentElement;list.hidden=false;
 list.setAttribute('role','tablist');list.setAttribute('aria-label','Post settings');
 tabs.forEach((tab,i)=>{tab.setAttribute('role','tab');tab.id='editor-tab-'+names[i];tab.setAttribute('aria-controls','editor-panel-'+names[i]);panels[i].id='editor-panel-'+names[i];panels[i].setAttribute('role','tabpanel');panels[i].setAttribute('aria-labelledby',tab.id);panels[i].tabIndex=0;});
 function show(index,focus=false,update=false){
  tabs.forEach((tab,i)=>{const active=i===index;tab.classList.toggle('active',active);tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;panels[i].hidden=!active;panels[i].classList.toggle('active',active);});
  if(focus)tabs[index].focus();
  if(update)history.replaceState(history.state,'','#editor-panel-'+names[index]);
 }
 function fromHash(){const index=names.findIndex(n=>location.hash==='#editor-panel-'+n);if(index>=0)show(index);}
 tabs.forEach((tab,i)=>{
  tab.addEventListener('click',()=>show(i,false,true));
  tab.addEventListener('keydown',event=>{let next=i;
   if(event.key==='ArrowRight')next=(i+1)%tabs.length;else if(event.key==='ArrowLeft')next=(i+tabs.length-1)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;
   event.preventDefault();show(next,true,true);
  });
 });
 // Native validation fires before submit. Reveal the first invalid field's panel
 // synchronously so the browser can focus it and show its own validation message.
 let firstInvalid=null;
 document.querySelector('[data-editor-form]')?.addEventListener('invalid',event=>{
  if(firstInvalid)return;firstInvalid=event.target;
  const index=panels.findIndex(panel=>panel.contains(event.target));if(index>=0)show(index,false,true);
  setTimeout(()=>{firstInvalid?.focus();firstInvalid=null;},0);
 },true);
 show(0);fromHash();addEventListener('hashchange',fromHash);
})();
