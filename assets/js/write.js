(()=>{
  document.querySelectorAll('[data-cmd]').forEach(btn=>btn.addEventListener('click',()=>{
    const cmd=btn.dataset.cmd,value=btn.dataset.value||null;
    document.execCommand(cmd,false,value);document.querySelector('[data-editor-body]')?.focus();
  }));

  const body=document.querySelector('[data-editor-body]'),hidden=document.querySelector('input[name="content_html"]'),form=document.querySelector('[data-editor-form]'),state=document.querySelector('.save-state');
  const title=document.querySelector('input[name="title"]'),standfirst=document.querySelector('textarea[name="standfirst"]');
  const wordCount=document.querySelector('[data-word-count]'),readTime=document.querySelector('[data-read-time]');
  const seoTitle=document.querySelector('input[name="seo_title"]'),seoDescription=document.querySelector('textarea[name="seo_description"]');
  const seoPreviewTitle=document.querySelector('[data-seo-preview-title]'),seoPreviewDescription=document.querySelector('[data-seo-preview-description]');
  const sync=()=>{
    if(body&&hidden)hidden.value=body.innerHTML;
    if(body){const text=(body.innerText||'').trim(),words=text?text.split(/\s+/).filter(Boolean).length:0;if(wordCount)wordCount.textContent=`${words.toLocaleString()} words`;if(readTime)readTime.textContent=`${Math.max(1,Math.ceil(words/220))} min read`;}
    if(seoPreviewTitle)seoPreviewTitle.textContent=(seoTitle?.value||title?.value||'Your title will appear here').trim();
    if(seoPreviewDescription)seoPreviewDescription.textContent=(seoDescription?.value||standfirst?.value||'Your standfirst will help form the search description.').trim();
  };
  if(body&&hidden){document.execCommand('defaultParagraphSeparator',false,'p');body.addEventListener('input',sync);form?.addEventListener('submit',sync);[title,standfirst,seoTitle,seoDescription].forEach(x=>x?.addEventListener('input',sync));sync();}

  let timer,pending=null,queued=false,submitting=false,committing=false,blocked=false,dirty=false,editVersion=0;
  const markDirty=()=>{dirty=true;editVersion++;};form?.addEventListener('input',markDirty);
  addEventListener('beforeunload',event=>{if(dirty&&!committing){event.preventDefault();event.returnValue='';}});
  if(form&&Number(form.dataset.postId)===0)form.addEventListener('submit',()=>{committing=true;});
  addEventListener('offline',()=>{if(state)state.textContent='Offline. Keep this page open; download a copy before leaving.';});
  document.querySelector('[data-download-draft]')?.addEventListener('click',()=>{sync();const text=[title?.value||'Untitled',standfirst?.value||'',body?.innerText||''].join('\n\n');const link=document.createElement('a');link.href=URL.createObjectURL(new Blob([text],{type:'text/plain;charset=utf-8'}));link.download='my-thought-draft.txt';link.click();setTimeout(()=>URL.revokeObjectURL(link.href),1000);});
  if(form&&Number(form.dataset.postId)>0){
    const version=form.querySelector('input[name="draft_version"]');
    const saveDraft=async()=>{
      if(submitting||blocked)return;
      if(pending){queued=true;return pending;}
      sync();const sendingVersion=editVersion;const hasNewFile=Boolean(form.querySelector('input[type=file]')?.files?.length);const fd=new FormData(form);fd.set('autosave','1');fd.set('action','draft');fd.delete('featured_image_file');
      if(state)state.textContent='Saving private draft…';
      pending=(async()=>{
        try{
          const r=await fetch(form.action,{method:'POST',headers:{'X-Requested-With':'fetch'},body:fd,credentials:'same-origin'});
          const j=await r.json();
          if(r.ok&&j.ok){
            if(version)version.value=String(j.version);
            if(editVersion===sendingVersion&&!hasNewFile)dirty=false;
            if(state)state.textContent='Private draft saved '+new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
          }else{
            if(r.status===409||r.status===419){blocked=true;clearTimeout(timer);}
            if(state)state.textContent=j.error||'Save failed. Keep this page open and copy your changes.';
          }
        }catch(e){if(state)state.textContent='Save not confirmed. Keep this page open and retry before leaving.';}
      })();
      await pending;pending=null;
      if(queued&&!submitting&&!blocked){queued=false;clearTimeout(timer);timer=setTimeout(saveDraft,350);}
    };
    const schedule=()=>{if(submitting||blocked)return;clearTimeout(timer);if(pending)queued=true;timer=setTimeout(saveDraft,1100);};
    form.querySelectorAll('input,textarea,select').forEach(x=>x.addEventListener('input',schedule));body?.addEventListener('input',schedule);
    form.addEventListener('submit',async event=>{
      if(committing)return;
      event.preventDefault();clearTimeout(timer);queued=false;submitting=true;
      const button=event.submitter;
      // Let an in-flight autosave return its version before explicit save/publish.
      // A cancelled fetch may still commit on the server, so never abort it here.
      if(pending)await pending;
      if(blocked){submitting=false;if(state)state.textContent='A newer draft exists or the session expired. Copy your changes before reloading.';return;}
      sync();committing=true;form.requestSubmit(button||undefined);
    });
  }

  const cat=document.querySelector('select[name="category_id"]'),travel=document.querySelector('[data-travel-fields]');
  const syncTravel=()=>{if(!cat||!travel)return;travel.hidden=cat.options[cat.selectedIndex]?.dataset.slug!=='travel'};cat?.addEventListener('change',syncTravel);syncTravel();

  const tabs=[...document.querySelectorAll('[data-editor-tab]')],panels=[...document.querySelectorAll('[data-editor-panel]')];
  const showTab=name=>{tabs.forEach(t=>{const active=t.dataset.editorTab===name;t.classList.toggle('active',active);t.setAttribute('aria-selected',String(active));t.tabIndex=active?0:-1;});panels.forEach(p=>{const active=p.dataset.editorPanel===name;p.hidden=!active;p.classList.toggle('active',active)});};
  tabs.forEach((t,index)=>{t.setAttribute('role','tab');t.id='editor-tab-'+t.dataset.editorTab;t.setAttribute('aria-controls','editor-panel-'+t.dataset.editorTab);t.addEventListener('click',()=>showTab(t.dataset.editorTab));t.addEventListener('keydown',event=>{let next=index;if(event.key==='ArrowRight')next=(index+1)%tabs.length;else if(event.key==='ArrowLeft')next=(index+tabs.length-1)%tabs.length;else if(event.key==='Home')next=0;else if(event.key==='End')next=tabs.length-1;else return;event.preventDefault();showTab(tabs[next].dataset.editorTab);tabs[next].focus();});});panels.forEach(panel=>{panel.setAttribute('role','tabpanel');panel.id='editor-panel-'+panel.dataset.editorPanel;panel.setAttribute('aria-labelledby','editor-tab-'+panel.dataset.editorPanel);});if(tabs.length)showTab(tabs[0].dataset.editorTab);

  const writerSearch=document.querySelector('[data-writer-search]'),writerStatus=document.querySelector('[data-writer-status]'),writerPosts=[...document.querySelectorAll('[data-writer-post]')],writerEmpty=document.querySelector('[data-writer-empty]');
  const filterWriter=()=>{const q=(writerSearch?.value||'').trim().toLowerCase(),f=writerStatus?.value||'all';let visible=0;writerPosts.forEach(p=>{const status=p.dataset.status||'',selected=p.dataset.selected==='1';const statusMatch=f==='all'||status===f||(f==='selected'&&selected);const textMatch=!q||(p.dataset.search||'').includes(q);const show=statusMatch&&textMatch;p.hidden=!show;if(show)visible++});if(writerEmpty)writerEmpty.hidden=visible!==0;};
  writerSearch?.addEventListener('input',filterWriter);writerStatus?.addEventListener('change',filterWriter);
  const statButtons=[...document.querySelectorAll('[data-writer-filter]')];
  const syncStatActive=()=>{const f=writerStatus?.value||'all';statButtons.forEach(b=>b.classList.toggle('active',b.dataset.writerFilter===f));};
  statButtons.forEach(b=>b.addEventListener('click',()=>{if(writerStatus)writerStatus.value=b.dataset.writerFilter||'all';syncStatActive();filterWriter();document.querySelector('.writer-library')?.scrollIntoView({behavior:'smooth',block:'start'})}));
  writerStatus?.addEventListener('change',syncStatActive);syncStatActive();

  if(form&&Number(form.dataset.postId)===0&&body&&title&&standfirst){
    const key='wk_writer_new_draft_v1';
    try{const raw=sessionStorage.getItem(key);if(raw){const d=JSON.parse(raw);if(!title.value&&!standfirst.value&&(body.innerText||'').trim()===''){title.value=d.title||'';standfirst.value=d.standfirst||'';body.innerHTML=d.html||'<p></p>';if(state)state.textContent='Recovered unsaved draft';sync();}}}catch(e){}
    let localTimer;const localSave=()=>{clearTimeout(localTimer);localTimer=setTimeout(()=>{try{sessionStorage.setItem(key,JSON.stringify({title:title.value,standfirst:standfirst.value,html:body.innerHTML,at:Date.now()}));if(state)state.textContent='Saved in this browser'}catch(e){}},700)};
    [title,standfirst].forEach(x=>x.addEventListener('input',localSave));body.addEventListener('input',localSave);form.addEventListener('submit',()=>{try{sessionStorage.removeItem(key)}catch(e){}});
  }
})();

(()=>{
  const rows=[...document.querySelectorAll('[data-release-row]')],search=document.querySelector('[data-release-search]'),empty=document.querySelector('[data-release-empty]'),buttons=[...document.querySelectorAll('[data-release-filter]')];
  let filter='all';
  const run=()=>{const q=(search?.value||'').trim().toLowerCase();let n=0;rows.forEach(r=>{const show=(filter==='all'||r.dataset.status===filter)&&(!q||(r.dataset.search||'').includes(q));r.hidden=!show;if(show)n++});if(empty)empty.hidden=n!==0;buttons.forEach(b=>b.classList.toggle('active',b.dataset.releaseFilter===filter));};
  buttons.forEach(b=>b.addEventListener('click',()=>{filter=b.dataset.releaseFilter||'all';run()}));search?.addEventListener('input',run);
  document.querySelector('[data-copy-release]')?.addEventListener('click',async e=>{const input=document.querySelector('#release-link');if(!input)return;try{await navigator.clipboard.writeText(input.value);e.currentTarget.textContent='Copied';setTimeout(()=>e.currentTarget.textContent='Copy link',1400)}catch(err){input.select();document.execCommand('copy')}});
})();

(()=>{const toggle=document.querySelector('[data-writer-menu]'),nav=document.getElementById('writer-navigation');if(toggle&&nav){document.body.classList.add('writer-enhanced');toggle.hidden=false;toggle.addEventListener('click',()=>{const open=nav.classList.toggle('is-open');toggle.setAttribute('aria-expanded',String(open));});document.addEventListener('keydown',event=>{if(event.key==='Escape'&&nav.classList.contains('is-open')){nav.classList.remove('is-open');toggle.setAttribute('aria-expanded','false');toggle.focus();}});}const copy=document.querySelector('[data-studio-copy]');copy?.addEventListener('click',async()=>{const result=document.querySelector('[data-ai-result]'),status=document.querySelector('[data-studio-status]');try{await navigator.clipboard.writeText(result.value);status.textContent='Copied. Review before using.';}catch{result.focus();result.select();status.textContent='Select and copy the suggestion using your device menu.';}});})();

(()=>{const input=document.querySelector('[data-media-search]'),cards=[...document.querySelectorAll('[data-media-card]')],status=document.querySelector('[data-media-search-status]');input?.addEventListener('input',()=>{const query=input.value.trim().toLowerCase();let count=0;cards.forEach(card=>{const match=card.dataset.search.includes(query);card.hidden=!match;if(match)count++;});if(status)status.textContent=count+' matching assets';});})();
