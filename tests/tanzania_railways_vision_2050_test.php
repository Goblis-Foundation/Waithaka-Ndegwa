<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/functions.php';
require_once dirname(__DIR__).'/app/editorial.php';
$root=dirname(__DIR__);$slug='tanzania-railways-vision-2050';$checks=0;
function trz_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;}
$meta=json_decode((string)file_get_contents($root.'/app/content/'.$slug.'.json'),true,512,JSON_THROW_ON_ERROR);
$html=(string)file_get_contents($root.'/app/content/'.$slug.'.html');$safe=sanitize_html($html);$plain=plain_text($safe);
trz_check(($meta['slug']??'')===$slug,'Fixed canonical slug.');
trz_check(($meta['research_cutoff']??'')==='2026-11-07','Current research cut-off.');
trz_check(($meta['category_slug']??'')==='national-infrastructure','National Infrastructure category.');
trz_check(in_array('ideas-perspectives',$meta['article_groups']??[],true),'Ideas & Perspectives group.');
trz_check(mb_strlen($plain)>8500,'Long-form article retained.');
trz_check(substr_count($safe,'<h2>')>=8,'Article has substantive chapters.');
trz_check(!preg_match('~<(?:script|iframe|style|h1)\b~i',$safe),'No active embeds or duplicate H1.');
foreach(['US$1.2 billion','Tabora–Kigoma','TAZARA','Mtwara–Mbamba Bay','Vision 2050','Tundu Lissu','human-rights','regional logistics'] as $term)trz_check(str_contains($plain,$term),'Required researched context retained: '.$term);
foreach(['earlier note','my earlier','updated view','revisit my'] as $term)trz_check(!str_contains(mb_strtolower($plain),$term),'Standalone first-publication framing.');
trz_check(str_contains($plain,'three different railway stories'),'Projects are explicitly distinguished.');
trz_check(str_contains($plain,'not yet a fully financed construction project'),'Mtwara corridor status is not overstated.');
trz_check(str_contains($plain,'project expectations, not achieved results'),'TAZARA forecasts are qualified.');
trz_check(str_contains($plain,'There is no contradiction in saying two things at once'),'Balanced development/rights framing retained.');
$doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$safe.'</body></html>',LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
$external=0;foreach($doc->getElementsByTagName('a')as$link){$href=$link->getAttribute('href');if(str_starts_with($href,'https://')){$external++;trz_check($link->getAttribute('target')==='_blank'&&str_contains($link->getAttribute('rel'),'noopener'),'External sources open safely.');}}
trz_check($external>=12,'Load-bearing claims have inline sources.');
trz_check(count(editorial_toc($safe)['items'])>=8,'Article navigation chapters generated.');
$home=(string)file_get_contents($root.'/views/home.php');
trz_check(!str_contains($home,$slug),'Article is not pinned on the homepage.');
$publisher=(string)file_get_contents($root.'/bin/publish-tanzania-railways-vision-2050.php');
trz_check(str_contains($publisher,'GET_LOCK')&&str_contains($publisher,'FOR UPDATE')&&str_contains($publisher,'RELEASE_LOCK'),'Serialized one-time publication.');
trz_check(!preg_match('~(?:UPDATE|DELETE\s+FROM)\s+posts\b~i',$publisher),'Existing Writer record is never overwritten.');
$hook=(string)file_get_contents($root.'/.platform/hooks/postdeploy/36_nairobi_sewage_article.sh');
trz_check(str_contains($hook,'publish-tanzania-railways-vision-2050.php'),'Executable postdeploy hook invokes article publisher.');
echo 'PASS: '.$checks." Tanzania railways, Vision 2050, rights balance and publication-contract checks.\n";
