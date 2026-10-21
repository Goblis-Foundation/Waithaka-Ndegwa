(()=>{
  const header=document.querySelector('[data-site-header]');
  const updateHeader=()=>header?.classList.toggle('scrolled',scrollY>8);
  addEventListener('scroll',updateHeader,{passive:true});updateHeader();

  const menu=document.querySelector('[data-menu]');
  const nav=document.querySelector('[data-main-nav]');
  if(menu&&nav){menu.addEventListener('click',()=>{const open=nav.classList.toggle('open');menu.setAttribute('aria-expanded',open?'true':'false')});nav.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{nav.classList.remove('open');menu.setAttribute('aria-expanded','false')}));addEventListener('resize',()=>{if(innerWidth>820){nav.classList.remove('open');menu.setAttribute('aria-expanded','false')}})}

  document.querySelectorAll('[data-copy]').forEach(b=>b.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(b.dataset.copy||location.href);const old=b.textContent;b.textContent='Copied';setTimeout(()=>b.textContent=old,1200)}catch(e){}}));

  const cookie=document.querySelector('.cookie');
  let consent=null;try{consent=localStorage.getItem('wk_consent')}catch(e){}
  if(cookie&&!consent)cookie.classList.add('show');
  document.querySelectorAll('[data-consent]').forEach(b=>b.addEventListener('click',()=>{const choice=b.dataset.consent;try{localStorage.setItem('wk_consent',choice)}catch(e){}cookie?.classList.remove('show');if(typeof window.gtag==='function'){window.gtag('consent','update',{analytics_storage:choice==='analytics'?'granted':'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'})}}));

  const methods=[...document.querySelectorAll('input[name="method"]')];
  const methodPanels=[...document.querySelectorAll('[data-method-fields]')];
  const currencyPanels=[...document.querySelectorAll('[data-currency-panel]')];
  const syncPayments=()=>{const v=document.querySelector('input[name="method"]:checked')?.value||'mpesa';methodPanels.forEach(p=>{const active=p.dataset.methodFields===v;p.hidden=!active;p.querySelectorAll('input,select,textarea').forEach(el=>el.disabled=!active)});const target=v==='mpesa'?'kes':v==='pesapal'?'card':'usd';currencyPanels.forEach(p=>{const active=p.dataset.currencyPanel===target;p.hidden=!active;p.querySelectorAll('input,select,textarea').forEach(el=>el.disabled=!active)})};
  methods.forEach(x=>x.addEventListener('change',syncPayments));syncPayments();

  const cardCurrency=document.querySelector('[data-card-currency]');
  const cardPresets=document.querySelector('[data-card-presets]');
  const cardLabels=[...document.querySelectorAll('[data-card-currency-label]')];
  const cardCustom=document.querySelector('#amount_card');
  const presetMap={KES:[250,500,1000,2500,5000],USD:[5,10,25,50,100],EUR:[5,10,25,50,100],GBP:[5,10,25,50,100]};
  const symbolMap={KES:'',USD:'$',EUR:'€',GBP:'£'};
  const syncCardCurrency=()=>{if(!cardCurrency||!cardPresets)return;const cur=cardCurrency.value||'KES',vals=presetMap[cur]||presetMap.KES,sym=symbolMap[cur]||'';cardLabels.forEach(x=>x.textContent=cur);cardPresets.innerHTML=vals.map((v,i)=>`<label><input type="radio" name="amount_choice_card" value="${v}" ${i===2?'checked':''}><span>${sym}${Number(v).toLocaleString()}</span></label>`).join('')+'<label><input type="radio" name="amount_choice_card" value="other"><span>Other</span></label>';if(cardCustom){cardCustom.min=cur==='KES'?'50':'1';cardCustom.max=cur==='KES'?'1000000':'100000';cardCustom.placeholder=cur==='KES'?'e.g. 1000':'e.g. 25'}};
  cardCurrency?.addEventListener('change',syncCardCurrency);syncCardCurrency();

  const anonymous=document.querySelector('[data-anonymous-toggle]'),identity=document.querySelector('[data-optional-identity]');
  const syncAnonymous=()=>{if(identity&&anonymous)identity.hidden=anonymous.checked};anonymous?.addEventListener('change',syncAnonymous);syncAnonymous();
  const contactType=document.querySelector('[data-card-contact-type]'),paymentContact=document.querySelector('[data-card-payment-contact]');
  const syncCardContact=()=>{if(!contactType||!paymentContact)return;const phone=contactType.value==='phone';paymentContact.type=phone?'tel':'email';paymentContact.placeholder=phone?'+254 7XX XXX XXX':'you@example.com';paymentContact.autocomplete=phone?'tel':'email';};contactType?.addEventListener('change',syncCardContact);syncCardContact();

  const contributionStatus=document.querySelector('[data-contribution-status]');
  if(contributionStatus&&['pending','initiated'].includes(contributionStatus.dataset.status||'')){const ref=contributionStatus.dataset.reference;let attempts=0;const poll=async()=>{if(!ref||attempts++>45)return;try{const r=await fetch(`${location.origin}${location.pathname.replace(/\/contribute\/?$/,'')}/payments/status?ref=${encodeURIComponent(ref)}`,{headers:{'Accept':'application/json'}});const j=await r.json();if(j.ok&&j.status==='completed'){location.reload();return}if(j.ok&&['failed','cancelled'].includes(j.status)){location.reload();return}}catch(e){}setTimeout(poll,4000)};setTimeout(poll,3500)}

  const contactForm=document.querySelector('[data-contact-form]');
  if(contactForm){const message=contactForm.querySelector('#message');const counter=document.querySelector('[data-message-count]');const reason=document.querySelector('[data-contact-reason]');let previousPrefix='';const updateCount=()=>{if(counter&&message)counter.textContent=(message.value.length).toLocaleString()+' / 10,000'};reason?.addEventListener('change',()=>{if(!message)return;const val=reason.value;if(previousPrefix&&message.value.startsWith(previousPrefix))message.value=message.value.slice(previousPrefix.length).replace(/^\s+/, '');previousPrefix=val?`Regarding: ${val}\n\n`:'';if(previousPrefix)message.value=previousPrefix+message.value;message.focus();message.setSelectionRange(message.value.length,message.value.length);updateCount()});message?.addEventListener('input',updateCount);updateCount()}

  const reveals=[...document.querySelectorAll('[data-reveal]')];
  if('IntersectionObserver'in window&&!matchMedia('(prefers-reduced-motion: reduce)').matches){const io=new IntersectionObserver(entries=>entries.forEach(e=>{if(e.isIntersecting){e.target.classList.add('revealed');io.unobserve(e.target)}}),{threshold:.07,rootMargin:'0px 0px -28px 0px'});reveals.forEach(el=>io.observe(el))}else reveals.forEach(el=>el.classList.add('revealed'));

  const progress=document.querySelector('[data-reading-progress]');const article=document.querySelector('[data-article]');
  if(progress&&article){const update=()=>{const r=article.getBoundingClientRect();const total=Math.max(1,article.offsetHeight-innerHeight);const read=Math.min(total,Math.max(0,-r.top));progress.style.width=(read/total*100)+'%'};addEventListener('scroll',update,{passive:true});addEventListener('resize',update);update()}

  const filter=document.querySelector('[data-thought-filter]');const sort=document.querySelector('[data-thought-sort]');const items=[...document.querySelectorAll('[data-thought-item]')];const list=document.querySelector('[data-thought-list]');const count=document.querySelector('[data-thought-count]');const empty=document.querySelector('[data-thought-empty]');
  const filterThoughts=()=>{if(!items.length)return;const q=(filter?.value||'').trim().toLocaleLowerCase();let visible=0;items.forEach(item=>{const match=!q||(item.dataset.title||'').includes(q);item.classList.toggle('is-filtered-out',!match);if(match)visible++});if(count)count.textContent=visible+' '+(visible===1?'thought':'thoughts');if(empty)empty.hidden=visible!==0};filter?.addEventListener('input',filterThoughts);
  sort?.addEventListener('change',()=>{if(!list)return;const mode=sort.value;const cards=[...list.querySelectorAll('[data-thought-item]')];cards.sort((a,b)=>mode==='oldest'?Number(a.dataset.published||0)-Number(b.dataset.published||0):mode==='title'?(a.dataset.title||'').localeCompare(b.dataset.title||''):Number(b.dataset.published||0)-Number(a.dataset.published||0));cards.forEach(c=>list.appendChild(c))});
  if(filter)addEventListener('keydown',e=>{const t=(document.activeElement?.tagName||'').toLowerCase();if(e.key==='/'&&!['input','textarea','select'].includes(t)){e.preventDefault();filter.focus()}});
})();
