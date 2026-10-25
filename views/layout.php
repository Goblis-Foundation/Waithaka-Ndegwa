<?php
$seo = array_merge(seo_defaults(), $seo ?? []);
$schema = $schema ?? [];
$globalPage=global_webpage_schema($seo,!empty($privatePage));if($globalPage)$schema[]=$globalPage;
$ga4 = analytics_measurement_id($seo, !empty($suppressAnalytics) || !empty($privatePage));
$verification = (string)setting('google.search_verification','');
$recaptchaSite = (string)setting('recaptcha.site_key','');
$isArticle = ($seo['type'] ?? '') === 'article';

?>
<!doctype html>
<html lang="en">
<head>
<?php include __DIR__.'/partials_google_tag.php'; ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?=e($seo['title'])?></title>
<meta name="description" content="<?=e($seo['description'])?>">
<link rel="canonical" href="<?=e($seo['canonical'])?>">
<meta name="robots" content="<?=e($seo['robots'] ?? 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1')?>">
<meta name="author" content="Waithaka Ndegwa">
<meta name="theme-color" content="#edf1eb">
<?php if($verification):?><meta name="google-site-verification" content="<?=e($verification)?>"><?php endif;?>
<meta property="og:site_name" content="Waithaka Ndegwa"><meta property="og:type" content="<?=e($seo['type'])?>"><meta property="og:title" content="<?=e($seo['title'])?>"><meta property="og:description" content="<?=e($seo['description'])?>"><meta property="og:url" content="<?=e($seo['canonical'])?>"><meta property="og:image" content="<?=e($seo['image'])?>"><meta property="og:image:alt" content="<?=e($seo['image_alt'] ?? 'Waithaka Ndegwa')?>"><meta property="og:locale" content="en_KE">

<meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?=e($seo['title'])?>"><meta name="twitter:description" content="<?=e($seo['description'])?>"><meta name="twitter:image" content="<?=e($seo['image'])?>"><meta name="twitter:creator" content="@iwaithakaw">
<?php if($isArticle && !empty($post['published_at'])):?><meta property="article:published_time" content="<?=e(date(DATE_ATOM,strtotime($post['published_at'])))?>"><meta property="article:modified_time" content="<?=e(date(DATE_ATOM,strtotime((function_exists('editorial_article_modified')?editorial_article_modified($post):null)?:$post['published_at'])))?>"><meta property="article:author" content="Waithaka Ndegwa"><?php endif;?>
<link rel="icon" href="<?=asset('images/favicon.ico')?>"><link rel="icon" type="image/png" sizes="32x32" href="<?=asset('images/favicon-32.png')?>"><link rel="apple-touch-icon" href="<?=asset('images/apple-touch-icon.png')?>"><link rel="manifest" href="<?=url('/manifest.webmanifest')?>"><link rel="alternate" type="application/rss+xml" title="Waithaka Ndegwa — My Thoughts" href="<?=url('/feed.xml')?>">
<link rel="stylesheet" href="<?=asset('css/site.css')?>"><link rel="stylesheet" href="<?=asset('css/experience.css')?>"><link rel="stylesheet" href="<?=asset('css/impact.css')?>"><link rel="stylesheet" href="<?=asset('css/journeys.css')?>">
<?php foreach(['editorial','reader','discovery']as$style)if(is_file(__DIR__.'/../assets/css/'.$style.'.css')):?><link rel="stylesheet" href="<?=asset('css/'.$style.'.css')?>"><?php endif;?>
<link rel="stylesheet" href="<?=asset('css/personal-refresh.css')?>">
<?php foreach((array)$schema as $s):?><script type="application/ld+json"><?=json_encode($s,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script><?php endforeach;?>
<?php if($recaptchaSite && in_array($page,['contact','booking'],true)):?><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif;?>
</head>
<body data-ga4="<?=e($ga4)?>" class="page-<?=e($page)?>">
<a class="skip-link" href="#main">Skip to content</a>
<div class="reading-progress" data-reading-progress aria-hidden="true"></div>
<header class="site-header" data-site-header>
  <div class="container header-inner">
    <a class="brand" href="<?=url('/')?>" aria-label="Waithaka Ndegwa home"><img src="<?=asset('images/icon.png')?>" alt="" width="42" height="42"><span>Waithaka Ndegwa</span></a>
    <nav id="site-main-nav" class="main-nav" data-main-nav aria-label="Main navigation">
      <a href="<?=url('/')?>" <?=path_only()==='/'?'aria-current="page"':''?>>Home</a>
      <a href="<?=url('/start-here')?>" <?=path_only()==='/start-here'?'aria-current="page"':''?>>Start here</a>
      <a href="<?=url('/my-thoughts')?>" <?=$page==='thoughts'||$page==='post'?'aria-current="page"':''?>>My Thoughts</a>
      <a href="<?=url('/about')?>" <?=$page==='about'?'aria-current="page"':''?>>About</a>
      <a href="<?=url('/about#goals-2030')?>">Goal 2030</a>
      <a href="<?=url('/contact')?>" <?=$page==='contact'?'aria-current="page"':''?>>Contact</a>
      <a class="nav-mobile-support" href="<?=url('/contribute')?>">Support the writing</a>
    </nav>
    <div class="header-actions">
      <a class="icon-button" href="<?=url('/search')?>" aria-label="Search My Thoughts"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4 4"></path></svg></a>
      <a class="header-support-link" href="<?=url('/contribute')?>">Support</a><a class="support-button header-book-button" href="<?=url('/book')?>">Book time</a>
      <button class="menu-button" data-menu type="button" aria-label="Open menu" aria-controls="site-main-nav" aria-expanded="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span class="menu-label">Menu</span></button>
    </div>
  </div>
</header>
<main id="main"><?php include __DIR__.'/partials_public_explore.php';?><?=$content?></main>
<footer class="site-footer">
  <div class="container footer-main">
    <div class="footer-about"><a class="footer-brand" href="<?=url('/')?>"><img src="<?=asset('images/icon.png')?>" alt="" width="44" height="44"><span>Waithaka Ndegwa</span></a><p>My experience and views on social entrepreneurship, agribusiness, infrastructure, innovation, and the world I encounter along the way.</p></div>
    <div class="footer-nav"><span>Explore</span><a href="<?=url('/')?>">Home</a><a href="<?=url('/book')?>">Book time</a><a href="<?=url('/start-here')?>">Start Here</a><a href="<?=url('/resources')?>">Resources</a><a href="<?=url('/watch')?>">Watch</a><a href="<?=url('/professional-information')?>">Professional information</a><a href="<?=url('/my-thoughts')?>">My Thoughts</a><a href="<?=url('/about')?>">About</a><a href="<?=url('/about#goals-2030')?>">Goal 2030</a><a href="<?=url('/contact')?>">Contact</a></div><div class="footer-nav"><span>Discover</span><a href="<?=url('/startup-journeys')?>">Startup Journeys</a><a href="<?=url('/founders')?>">Founder Stories</a><a href="<?=url('/newsletter')?>" data-analytics-event="newsletter_signup_open" data-content-id="weekly-updates">Weekly updates</a><a href="<?=url('/feed.xml')?>">RSS feed</a></div>
    <div class="footer-nav"><span>Follow</span><?php foreach(social_links() as $name=>$link):?><a href="<?=e($link)?>" target="_blank" rel="me noopener noreferrer"><?=e($name)?> ↗</a><?php endforeach;?></div>
  </div>
  <div class="container footer-note"><p><strong>Personal views.</strong> Views expressed here are my own unless explicitly stated otherwise.</p><a href="<?=url('/editorial-independence')?>">Editorial independence →</a></div>
  <?php $visitorStats=visitor_statistics_public();if($visitorStats):?><section class="container footer-visitor-statistics" aria-label="Website visitors"><div class="footer-activity-heading"><span class="footer-activity-dot" aria-hidden="true"></span><span class="footer-activity-title">You’re in good company</span></div><dl><?php foreach(['now'=>'Now','month'=>$visitorStats['month_label'],'year'=>$visitorStats['year_label']]as$key=>$label):?><div class="footer-visitor-period"><dt <?= $key==='now'?'title="Recent active visitors · last 30 minutes"':''?>><?=e($label)?></dt><dd><?php if(isset($visitorStats[$key])):?><strong><?=number_format($visitorStats[$key])?></strong><span><?=$visitorStats[$key]===1?'visitor':'visitors'?></span><?php else:?><span class="footer-visitors-pending" aria-label="Visitor count not currently available">—</span><?php endif;?></dd></div><?php endforeach;?></dl></section><?php endif;?>
  <div class="container footer-bottom"><div class="footer-copyright">© <?=date('Y')?> Waithaka Ndegwa. All rights reserved.</div><nav aria-label="Legal links"><a href="<?=url('/privacy')?>">Privacy Policy</a><a href="<?=url('/legal')?>">Legal Policy</a><a href="<?=url('/cookies')?>">Cookies Policy</a><button type="button" class="cookie-settings" data-cookie-settings>Cookie preferences</button></nav><a class="afrisol-credit" href="https://www.afrisoltech.co.ke/" target="_blank" rel="noopener noreferrer">WebApp by Afrisoltech</a></div>
</footer>
<div class="cookie" role="dialog" aria-label="Cookie preferences"><p>This site uses necessary cookies and, with your permission, analytics to understand how content is discovered and read. <a href="<?=url('/cookies')?>">Cookies Policy</a></p><div><button data-consent="necessary">Necessary only</button><button class="primary-cookie" data-consent="analytics">Allow analytics</button></div></div>
<script src="<?=asset('js/site.js')?>" defer></script>
<script src="<?=asset('js/navigation-rails.js')?>" defer></script>
<script src="<?=asset('js/app.js')?>" defer></script></body>
</html>

