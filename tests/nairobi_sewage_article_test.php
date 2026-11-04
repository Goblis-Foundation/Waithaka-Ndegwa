<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/functions.php';
require_once dirname(__DIR__).'/app/editorial.php';
require_once dirname(__DIR__).'/app/nairobi_sewage_article.php';
$root=dirname(__DIR__);$checks=0;
function nsa_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
$package=nairobi_sewage_article_package();$meta=$package['meta'];$body=$package['body'];
nsa_check($meta['title']==="The Gold That Is Nairobi's Sewage",'Owner title retained.');
nsa_check($meta['category_slug']==='national-infrastructure','Correct article category.');
foreach($meta['article_groups'] as $group)nsa_check(isset(article_groups()[$group]),'Reading group is supported.');
nsa_check($meta['media_mode']==='header-only'&&count($meta['assets'])===1,'Latest owner-approved media scope.');
nsa_check(!preg_match('~<(?:script|iframe|style|h1)\b~i',$body),'No active embeds or duplicate title.');
foreach(['Research cut-off:','cooling agent','nuclear power','Crostar','January 2027'] as $text)nsa_check(!str_contains($body,$text),'Excluded content: '.$text);
foreach(['Apeldoorn','Ruai','Ukambani','11,650','4,750','445','Amersfoort','magnesium ammonium phosphate','not automatically drinking water','not proof of safety'] as $text)nsa_check(str_contains($body,$text),'Evidence and safety distinction retained: '.$text);
$source=(string)file_get_contents($root.'/app/content/the-gold-that-is-nairobis-sewage.html');
$expected=preg_replace('~<figure>.*?</figure>\s*~s','',$source);
nsa_check(nairobi_sewage_header_only_html($source)===$expected,'Only optional figures removed; all prose and inline source links unchanged.');
try{nairobi_sewage_header_only_html($source.'<figure><img src="/unexpected.jpg"></figure>');nsa_check(false,'Unexpected source figure must not silently disappear.');}catch(RuntimeException $error){nsa_check(str_contains($error->getMessage(),'unexpected source illustration'),'Unexpected source figure fails closed.');}
$doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$body.'</body></html>',LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
nsa_check($doc->getElementsByTagName('img')->length===0&&$doc->getElementsByTagName('figure')->length===0,'No missing body images or placeholders.');
$external=0;foreach($doc->getElementsByTagName('a') as $link){if(str_starts_with($link->getAttribute('href'),'https://')){$external++;nsa_check($link->getAttribute('target')==='_blank'&&str_contains($link->getAttribute('rel'),'noopener'),'Sources open safely in a new tab.');}}
nsa_check($external>=16,'Inline authoritative research sources retained.');
nsa_check(count(editorial_toc($body)['items'])===12,'All twelve chapters have navigation.');
nsa_check(str_contains($meta['featured_image_caption'],'AI-generated'),'Header is labelled as an illustrative composite.');
$publisher=(string)file_get_contents($root.'/bin/publish-nairobi-sewage-article.php');
nsa_check(str_contains($publisher,'GET_LOCK')&&str_contains($publisher,'FOR UPDATE')&&str_contains($publisher,'RELEASE_LOCK'),'Serialized one-time creation.');
nsa_check(!preg_match('~(?:UPDATE|DELETE\s+FROM)\s+posts\b~i',$publisher),'No overwrite of existing Writer records.');
$home=(string)file_get_contents($root.'/views/home.php');
nsa_check(!str_contains($home,'openingEssay')&&!str_contains($home,'featured-thought')&&!str_contains($home,'partials_post_card'),'No homepage article promotion.');
echo 'PASS: '.$checks." sewage article, approved header, source-preservation and publication checks.\n";
