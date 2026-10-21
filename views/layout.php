<?php
$seo = array_merge(seo_defaults(), $seo ?? []);
$schema = $schema ?? [];
$ga4 = trim((string)setting('google.ga4','')) ?: 'G-WCKVHN823V';
$verification = (string)setting('google.search_verification','');
$recaptchaSite = (string)setting('recaptcha.site_key','');
$isArticle = ($seo['type'] ?? '') === 'article';
?>
<!doctype html>
<html lang="en">
<head>
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}(()=>{let c=null;try{c=localStorage.getItem('wk_consent')}catch(e){}gtag('consent','default',{analytics_storage:c==='analytics'?'granted':'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',wait_for_update:500});})();gtag('js',new Date());gtag('config','<?=e($ga4)?>');
</script>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?=rawurlencode($ga4)?>"></script>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?=e($seo['title'])?></title>
<meta name="description" content="<?=e($seo['description'])?>">
<link rel="canonical" href="<?=e($seo['canonical'])?>">
<meta name="robots" content="<?=e($seo['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1')?>">
<meta name="author" content="Waithaka Ndegwa">
<meta name="theme-color" content="#173d2d">
<?php if($verification):?><meta name="google-site-verification" content="<?=e($verification)?>"><?php endif;?>
<meta property="og:site_name" content="Waithaka Ndegwa"><meta property="og:type" content="<?=e($seo['type'])?>"><meta property="og:title" content="<?=e($seo['title'])?>"><meta property="og:description" content="<?=e($seo['description'])?>"><meta property="og:url" content="<?=e($seo['canonical'])?>"><meta property="og:image" content="<?=e($seo['image'])?>"><meta property="og:image:alt" content="<?=e($seo['image_alt'] ?? 'Waithaka Ndegwa')?>"><meta property="og:locale" content="en_KE">
<meta property="og:locale:alternate" content="en_TZ"><meta property="og:locale:alternate" content="en_UG"><meta property="og:locale:alternate" content="en_RW"><meta property="og:locale:alternate" content="en_BI"><meta property="og:locale:alternate" content="en_GB"><meta property="og:locale:alternate" content="en_US"><meta property="og:locale:alternate" content="en_CA"><meta property="og:locale:alternate" content="en_IE"><meta property="og:locale:alternate" content="en_AU">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?=e($seo['title'])?>"><meta name="twitter:description" content="<?=e($seo['description'])?>"><meta name="twitter:image" content="<?=e($seo['image'])?>"><meta name="twitter:creator" content="@iwaithakaw">
<?php if($isArticle && !empty($post['published_at'])):?><meta property="article:published_time" content="<?=e(date(DATE_ATOM,strtotime($post['published_at'])))?>"><meta property="article:modified_time" content="<?=e(date(DATE_ATOM,strtotime($post['updated_at'])))?>"><meta property="article:author" content="Waithaka Ndegwa"><?php endif;?>
<link rel="icon" href="<?=asset('images/favicon.ico')?>"><link rel="icon" type="image/png" sizes="32x32" href="<?=asset('images/favicon-32.png')?>"><link rel="apple-touch-icon" href="<?=asset('images/apple-touch-icon.png')?>"><link rel="manifest" href="<?=url('/manifest.webmanifest')?>"><link rel="alternate" type="application/rss+xml" title="Waithaka Ndegwa — My Thoughts" href="<?=url('/feed.xml')?>">
<link rel="stylesheet" href="<?=asset('css/site.css')?>">
<?php foreach((array)$schema as $s):?><script type="application/ld+json"><?=json_encode($s,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)?></script><?php endforeach;?>
<?php if($recaptchaSite && $page==='contact'):?><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif;?>
</head>
<body data-ga4="<?=e($ga4)?>" class="page-<?=e($page)?>">
<a class="skip-link" href="#main">Skip to content</a>
<div class="reading-progress" data-reading-progress aria-hidden="true"></div>
<header class="site-header" data-site-header>
  <div class="container header-inner">
    <a class="brand" href="<?=url('/')?>" aria-label="Waithaka Ndegwa home"><img src="<?=asset('images/icon.png')?>" alt="" width="42" height="42"><span>Waithaka Ndegwa</span></a>
    <nav class="main-nav" data-main-nav aria-label="Main navigation">
      <a href="<?=url('/')?>" <?=$page==='home'?'aria-current="page"':''?>>Home</a>
      <a href="<?=url('/my-thoughts')?>" <?=$page==='thoughts'||$page==='post'?'aria-current="page"':''?>>My Thoughts</a>
      <a href="<?=url('/about')?>" <?=$page==='about'?'aria-current="page"':''?>>About</a>
      <a href="<?=url('/contact')?>" <?=$page==='contact'?'aria-current="page"':''?>>Contact</a>
    </nav>
    <div class="header-actions">
      <a class="icon-button" href="<?=url('/search')?>" aria-label="Search My Thoughts"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></svg></a>
      <a class="support-button" href="<?=url('/contribute')?>">Support My Thoughts</a>
      <button class="menu-button" data-menu type="button" aria-label="Open menu" aria-expanded="false"><span></span><span></span><span></span></button>
    </div>
  </div>
</header>
<main id="main"><?=$content?></main>
<footer class="site-footer">
  <div class="container footer-main">
    <div class="footer-about"><a class="footer-brand" href="<?=url('/')?>"><img src="<?=asset('images/icon.png')?>" alt="" width="44" height="44"><span>Waithaka Ndegwa</span></a><p>My experience and views on social entrepreneurship, agribusiness, infrastructure, innovation, and the world I encounter along the way.</p></div>
    <div class="footer-nav"><span>Explore</span><a href="<?=url('/my-thoughts')?>">My Thoughts</a><a href="<?=url('/about')?>">About</a><a href="<?=url('/contact')?>">Contact</a><a href="<?=url('/search')?>">Search</a></div>
    <div class="footer-nav"><span>Follow</span><?php foreach(social_links() as $name=>$link):?><a href="<?=e($link)?>" target="_blank" rel="me noopener noreferrer"><?=e($name)?> ↗</a><?php endforeach;?></div>
  </div>
  <div class="container footer-note"><p><strong>Personal views.</strong> Views expressed here are my own unless explicitly stated otherwise.</p><a href="<?=url('/editorial-independence')?>">Editorial independence →</a></div>
  <div class="container footer-bottom"><div>© <?=date('Y')?> Waithaka Ndegwa. All rights reserved.</div><nav aria-label="Legal links"><a href="<?=url('/privacy')?>">Privacy Policy</a><a href="<?=url('/legal')?>">Legal Policy</a><a href="<?=url('/cookies')?>">Cookies Policy</a></nav><a class="afrisol-credit" href="https://www.afrisoltech.co.ke/" target="_blank" rel="noopener noreferrer">WebApp by Afrisoltech</a></div>
</footer>
<div class="cookie" role="dialog" aria-label="Cookie preferences"><p>This site uses necessary cookies and, with your permission, analytics to understand how content is discovered and read. <a href="<?=url('/cookies')?>">Cookies Policy</a></p><div><button data-consent="necessary">Necessary only</button><button class="primary-cookie" data-consent="analytics">Allow analytics</button></div></div>
<script src="<?=asset('js/site.js')?>" defer></script>
</body>
</html>
