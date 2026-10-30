/* Local-only progressive enhancement. No storage, tracking, autosave or provider mutations. */
(() => {
 'use strict';
 const make=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el;};
 const button=(text,cls='ux-button')=>{const b=make('button',text,cls);b.type='button';return b;};
 const reduced=()=>window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
 const shown=el=>!el.disabled&&!el.closest('[hidden]')&&el.getClientRects().length>0;
 const secret=el=>el.type==='password'||/password|secret|token|csrf|nonce|otp|verification_code|api.?key|auth_code|account_number|bank_reference|card_number/i.test(el.name||'');
 // Collapsing optional details must not remove entered data from the review.
 // Inactive country fields, hidden capabilities and disabled fieldsets stay excluded.
 function reviewable(el,form){
  if(el.matches(':disabled')||secret(el)||el.type==='hidden'||el.closest('[hidden],[inert],[aria-hidden="true"]'))return false;
  for(let p=el;p&&p!==form.parentElement;p=p.parentElement){const style=getComputedStyle(p);if(style.display==='none'||style.visibility==='hidden'||style.visibility==='collapse')return false;}
  return true;
 }
 function labelOf(el){
  const label=el.labels?.[0];if(label){const clone=label.cloneNode(true);clone.querySelectorAll('input,select,textarea,button,small').forEach(n=>n.remove());const text=clone.textContent.trim().replace(/\s+/g,' ');if(text)return text;}
  return el.getAttribute('aria-label')||el.name.replace(/[_\[\]]+/g,' ');
 }
 function reveal(el){let parent=el.parentElement;while(parent){if(parent.tagName==='DETAILS')parent.open=true;parent=parent.parentElement;}el.focus({preventScroll:true});el.scrollIntoView({behavior:reduced()?'auto':'smooth',block:'center'});}
 function dialog(title){
  const d=make('dialog',undefined,'ux-dialog');d.setAttribute('aria-label',title);const heading=make('h2',title),close=button('Close','ux-button');const head=make('div',undefined,'ux-dialog-heading');head.append(heading,close);d.append(head);document.body.append(d);
  close.addEventListener('click',()=>d.close());
  d.addEventListener('click',e=>{if(e.target===d)d.close();});
  // Search inputs can consume their first Escape to clear a value. Dialog dismissal
  // must still work immediately, with native focus restoration and no form action.
  d.addEventListener('keydown',e=>{if(e.key==='Escape'&&!e.isComposing){e.preventDefault();e.stopPropagation();d.close();}});
  return d;
 }
 // One task launcher from the existing navigation. It never invents grant-dependent actions.
 const sidebar=document.querySelector('.workspace-auth .workspace-nav');
 if(sidebar&&typeof HTMLDialogElement!=='undefined'){
  const links=Array.from(sidebar.querySelectorAll('a[data-workspace-link]')).map(a=>({name:a.textContent.trim(),href:a.href,group:a.closest('details')?.querySelector('summary')?.textContent.trim()||'Workspace'})).filter(a=>{try{const u=new URL(a.href);return u.origin===location.origin&&u.pathname.startsWith('/write')&&!/token|secret|code=/i.test(u.search);}catch(_){return false;}});
  const launcher=button('Find a workspace · Ctrl/Cmd + K','wbtn ux-command-button');document.querySelector('.workspace-top-actions')?.prepend(launcher);
  const d=dialog('Find a workspace'),input=make('input'),results=make('div',undefined,'ux-command-results'),status=make('p','', 'ux-muted');input.type='search';input.placeholder='Writing, mail, meetings, connections…';input.setAttribute('aria-label','Search workspace destinations');status.setAttribute('role','status');d.append(input,results,status);
  function draw(){const q=input.value.toLocaleLowerCase().trim();const found=links.filter(l=>(l.name+' '+l.group).toLocaleLowerCase().includes(q));results.replaceChildren();for(const l of found){const a=make('a',undefined,'ux-command-result');a.href=l.href;a.append(make('strong',l.name),make('small',l.group));results.append(a);}status.textContent=found.length+' destinations. Choose one to open it.';}
  function open(){input.value='';draw();d.showModal();input.focus();}launcher.addEventListener('click',open);input.addEventListener('input',draw);input.addEventListener('keydown',e=>{if(e.key==='ArrowDown'){e.preventDefault();results.querySelector('a')?.focus();}});document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&!e.altKey&&e.key.toLowerCase()==='k'){e.preventDefault();if(d.open)d.close();else open();}});
 }
 // Public journey chooser retains all original crawlable links without JavaScript.
 const intents=document.querySelector('[data-ux-intents]');if(intents){intents.hidden=false;intents.addEventListener('click',e=>{const b=e.target.closest('[data-ux-intent]');if(!b)return;const key=b.dataset.uxIntent;let n=0;document.querySelectorAll('[data-ux-journey]').forEach(card=>{card.hidden=key!=='all'&&card.dataset.uxJourney!==key;if(!card.hidden)n++;});intents.querySelectorAll('button').forEach(item=>item.setAttribute('aria-pressed',String(item===b)));const status=document.querySelector('[data-ux-intent-status]');if(status)status.textContent=n+' journeys shown. Your selection does not submit or enrol you.';});}
 // In-page navigation, not another menu full of actions. Content remains visible and printable.
 const content=document.querySelector('#writer-content')||document.querySelector('#main');
 if(content){const headings=Array.from(content.querySelectorAll('h2')).filter(h=>shown(h)&&!h.closest('.promote-experience,.ux-page-heading,.ux-stat-grid,dialog,[data-no-outline]'));
  if(headings.length>=3&&!content.querySelector('.personal-page-nav,[data-page-outline]')){const nav=make('nav',undefined,'ux-page-outline');nav.setAttribute('aria-label','On this page');const label=make('label','On this page'),select=make('select');select.setAttribute('aria-label','Jump to a section');select.append(new Option('Choose a section…',''));headings.slice(0,30).forEach((h,i)=>{if(!h.id){let id='ux-section-'+i;while(document.getElementById(id))id+='x';h.id=id;}select.append(new Option(h.textContent.trim().slice(0,100),h.id));});label.append(select);nav.append(label);select.addEventListener('change',()=>{const target=document.getElementById(select.value);if(target){target.tabIndex=-1;reveal(target);}});// The public selector is a page-level section index, NOT content inside
   // the first hero/section. An inner placement bypasses .container gutters
   // and produces the full-bleed bar seen on Home and Start Here.
   const top=content.querySelector('main,article,section');
   nav.setAttribute('data-page-outline','');
   if(content.id==='main'&&top){
     nav.classList.add('container');
     let pageRoot=top;
     while(pageRoot.parentElement&&pageRoot.parentElement!==content)pageRoot=pageRoot.parentElement;
     pageRoot.before(nav);
   }else{
     // Preserve the existing private Writer layout, including its margins.
     (top||content).prepend(nav);
   }}
 }
 // Long forms get local completion and review assistance. We leave original submit buttons,
 // names, server validation, consent, draft saves and existing specialised wizards untouched.
 document.querySelectorAll('form[method="post"]').forEach(form=>{
  if(/\/write\/(?:integration|connections|security|account|google|microsoft|booking-mailboxes)(?:\/|$)/.test(location.pathname)||form.matches('[data-promote-form],[data-no-form-assist]')||form.querySelector('input[type="password"],input[name*="secret"],input[name*="api_key"]')||form.matches('[data-autosave],[data-editor-form]')||form.querySelector('[contenteditable="true"]'))return;
  const inputs=Array.from(form.querySelectorAll('input,select,textarea')).filter(el=>!['hidden','submit','button'].includes(el.type)&&!secret(el));
  if(inputs.length<7||form.querySelector('.ux-form-assist'))return;
  const bar=make('div',undefined,'ux-form-assist'),progress=make('p','', 'ux-form-progress'),review=button('Review my entries','ux-button');progress.setAttribute('role','status');progress.setAttribute('aria-live','polite');bar.append(progress,review);form.prepend(bar);
  const update=()=>{const unique=new Map();for(const el of inputs)if(el.required&&reviewable(el,form)){const key=el.type==='radio'?el.name:el.id||el.name;unique.set(key,el.validity.valid);}const total=unique.size,done=Array.from(unique.values()).filter(Boolean).length;progress.textContent=total?done+' of '+total+' required fields complete. Nothing is saved by this check.':'Optional details can be left blank. Review before using the form’s final action.';};
  form.addEventListener('input',update);form.addEventListener('change',update);update();
  if(typeof HTMLDialogElement==='undefined'){review.hidden=true;return;}
  const d=dialog('Review your entries'),body=make('dl',undefined,'ux-entry-review'),note=make('p','This preview includes entered optional details even when their section is collapsed. It is not a save, payment, publication or approval. Edit a field or close this preview and use the form’s own final action.','ux-muted');d.append(body,note);
  review.addEventListener('click',()=>{
   body.replaceChildren();const radioGroups=new Set();
   for(const el of inputs){
    if(!reviewable(el,form))continue;
    let value;
    if(el.type==='radio'){
     if(el.checked)value=el.value;
     else if(el.required&&!el.validity.valid&&!radioGroups.has(el.name)){radioGroups.add(el.name);value='Required — no choice selected';}
     else continue;
    }else if(el.type==='checkbox'){if(el.checked)value='Selected';else if(el.required)value='Required — not confirmed';else continue;}
    else if(el.tagName==='SELECT')value=Array.from(el.selectedOptions||[]).filter(option=>option.value!=='').map(option=>option.textContent.trim()).join(', ');
    else if(el.type==='file')value=Array.from(el.files||[]).map(f=>f.name).join(', ');
    else value=el.value;
    if(!String(value||'').trim()){if(!el.required)continue;value='Required — not completed';}
    const label=labelOf(el),row=make('div'),term=make('dt',label),detail=make('dd',String(value)),edit=button('Edit','ux-button');
    edit.setAttribute('aria-label','Edit '+label);edit.dataset.uxEntryEdit=el.name||el.id;
    // Finish native focus restoration before focusing the selected original control.
    edit.addEventListener('click',()=>{d.close();requestAnimationFrame(()=>{if(el.isConnected&&reviewable(el,form))reveal(el);});});
    detail.append(document.createElement('br'),edit);row.append(term,detail);body.append(row);
   }
   if(!body.children.length)body.append(make('p','No active details entered yet.'));d.showModal();
  });
 });
 // Expand collapsed optional sections when native validation needs a field inside them.
 document.addEventListener('invalid',e=>{if(e.target instanceof HTMLElement&&e.target.closest('form')){let p=e.target.parentElement;while(p){if(p.tagName==='DETAILS')p.open=true;p=p.parentElement;}}},true);
 // Owner table filtering is explicitly page-local; it neither fetches nor hides source records.
 document.querySelectorAll('.workspace-auth table,[data-ux-filter-table]').forEach((table,index)=>{
  const rows=Array.from(table.tBodies?.[0]?.rows||[]);if(rows.length<5||table.dataset.uxFiltered)return;table.dataset.uxFiltered='true';
  const bar=make('div',undefined,'ux-table-filter'),label=make('label','Filter rows on this page'),input=make('input'),clear=button('Clear filter','ux-button'),status=make('span','', 'ux-muted');input.type='search';input.setAttribute('aria-label','Filter visible table '+(index+1));status.setAttribute('role','status');label.append(input);bar.append(label,clear,status);table.parentElement.insertBefore(bar,table);
  function filter(){const q=input.value.trim().toLocaleLowerCase();let n=0;rows.forEach(row=>{row.hidden=q!==''&&!row.textContent.toLocaleLowerCase().includes(q);if(!row.hidden)n++;});status.textContent=n+' of '+rows.length+' rows on this page';clear.hidden=q==='';}
  input.addEventListener('input',filter);clear.addEventListener('click',()=>{input.value='';filter();input.focus();});input.addEventListener('keydown',e=>{if(e.key==='Escape'&&input.value){e.preventDefault();input.value='';filter();}});filter();
 });
})();
