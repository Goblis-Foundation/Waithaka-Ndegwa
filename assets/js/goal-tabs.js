(()=>{'use strict';
 document.querySelectorAll('[data-goal-tabs]').forEach(root=>{
  const list=root.querySelector('.goal-tabs'),tabs=[...root.querySelectorAll('[data-goal-tab]')],panels=[...root.querySelectorAll('[data-goal-panel]')];
  if(!list||tabs.length!==panels.length||tabs.length===0)return;
  const ids=tabs.map(t=>t.getAttribute('href').slice(1));if(ids.some((id,i)=>panels[i].id!==id))return;
  list.setAttribute('role','tablist');list.setAttribute('aria-orientation','horizontal');
  tabs.forEach((tab,i)=>{tab.setAttribute('role','tab');tab.setAttribute('aria-controls',panels[i].id);panels[i].setAttribute('role','tabpanel');panels[i].setAttribute('aria-labelledby',tab.id);panels[i].setAttribute('tabindex','0');});
  function select(index,focus=false,changeUrl=false){
   tabs.forEach((tab,i)=>{const active=i===index;tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;panels[i].hidden=!active;});
   if(focus)tabs[index].focus();
   if(changeUrl)history.replaceState(null,'','#'+ids[index]);
  }
  tabs.forEach((tab,i)=>{
   tab.addEventListener('click',event=>{event.preventDefault();select(i,false,true);});
   tab.addEventListener('keydown',event=>{let index=i;
    if(event.key==='ArrowRight')index=(i+1)%tabs.length;else if(event.key==='ArrowLeft')index=(i+tabs.length-1)%tabs.length;else if(event.key==='Home')index=0;else if(event.key==='End')index=tabs.length-1;else if(event.key===' '){event.preventDefault();select(i,false,true);return;}else return;
    event.preventDefault();select(index,true,true);
   });
  });
  function fromHash(){const index=ids.indexOf(location.hash.slice(1));if(index>=0)select(index);}
  select(Math.max(0,ids.indexOf(location.hash.slice(1))));addEventListener('hashchange',fromHash);
 });
})();