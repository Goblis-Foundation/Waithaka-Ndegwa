<?php if ($ga4 !== ''): ?>
<!-- Google tag (gtag.js): one public measurement destination -->
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){window.dataLayer.push(arguments);}
(function(){
  'use strict';
  const id=<?=json_encode($ga4, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  const page=<?=json_encode(['page_location'=>analytics_page_location((string)$seo['canonical'], $_GET), 'page_title'=>(string)$seo['title']], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES)?>;
  // Referrer attribution needs only the origin; tokens and searches stay private.
  try{const ref=new URL(document.referrer);page.page_referrer=/^https?:$/.test(ref.protocol)?ref.origin+'/':'';}catch(e){page.page_referrer='';}
  let choice=null,viewSent=false;
  try{choice=localStorage.getItem('wk_consent');}catch(e){}
  if(!['analytics','necessary'].includes(choice))choice=null;
  window['ga-disable-'+id]=choice!=='analytics';
  gtag('consent','default',{analytics_storage:choice==='analytics'?'granted':'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
  gtag('js',new Date());
  gtag('config',id,{...page,send_page_view:false,allow_google_signals:false,allow_ad_personalization_signals:false});
  const events=new Set(['episode_continue','resource_download','newsletter_signup_open','founder_submission_open']);
  const identifiers=new Set(<?=json_encode(analytics_known_content_ids(), JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>);
  const seen=new Set();
  window.wkAnalytics={
    setConsent(value){
      choice=value==='analytics'?'analytics':'necessary';
      window['ga-disable-'+id]=choice!=='analytics';
      gtag('consent','update',{analytics_storage:choice==='analytics'?'granted':'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
      if(choice==='analytics'&&!viewSent){viewSent=true;gtag('event','page_view',{...page,send_to:id});}
    },
    track(name,contentId){
      if(choice!=='analytics'||!events.has(name)||/[\r\n]/.test(contentId||'')||(!identifiers.has(contentId)&&!/^(?:post|journey|resource|watch|episode)-[1-9][0-9]{0,8}$/.test(contentId||'')))return;
      const key=name+':'+contentId;if(seen.has(key))return;seen.add(key);
      gtag('event',name,{...page,send_to:id,content_id:contentId});
    }
  };
  if(choice==='analytics')window.wkAnalytics.setConsent(choice);
})();
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?=e($ga4)?>"></script>
<?php endif; ?>
