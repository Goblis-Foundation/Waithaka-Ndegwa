<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
function base_url():string{return 'https://www.waithakandegwa.com';}
function e(?string $value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
require dirname(__DIR__).'/app/analytics.php';
require dirname(__DIR__).'/app/editorial.php';
$ga4='G-RN0YXC2WCH';
$seo=['canonical'=>base_url().'/my-thoughts/fixture','title'=>'A public editorial title'];
$_GET=['utm_source'=>'newsletter','utm_medium'=>'email','utm_campaign'=>'post-12','token'=>'PRIVATE_LINK','email'=>'PRIVATE_EMAIL','q'=>'PRIVATE_SEARCH'];
include dirname(__DIR__).'/views/partials_google_tag.php';
