<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/functions.php';
require_once dirname(__DIR__).'/app/editorial.php';
$root=dirname(__DIR__);$slug='africas-fintechs-are-becoming-banks';$checks=0;
function afb_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;}
$meta=json_decode((string)file_get_contents($root.'/app/content/'.$slug.'.json'),true,512,JSON_THROW_ON_ERROR);
$html=(string)file_get_contents($root.'/app/content/'.$slug.'.html');$safe=sanitize_html($html);$plain=plain_text($safe);
afb_check(($meta['slug']??'')===$slug,'Fixed canonical slug.');
afb_check(($meta['research_cutoff']??'')==='2026-11-11','Current research cut-off.');
afb_check(($meta['category_slug']??'')==='innovation','Innovation category.');
afb_check(($meta['article_groups']??[])===['ideas-perspectives'],'Ideas & Perspectives only.');
afb_check(mb_strlen($plain)>8500,'Long-form article retained.');
afb_check(substr_count($safe,'<h2>')>=10,'Article has substantive chapters.');
afb_check(!preg_match('~<(?:script|iframe|style|h1)\b~i',$safe),'No active embeds or duplicate H1.');
foreach(['Flutterwave','Paystack','Moniepoint','Tyme','MTN','deposits','credit','banking licence','financial relationship'] as $term)afb_check(str_contains($plain,$term),'Required researched context retained: '.$term);
afb_check(str_contains($plain,'Tyme is slightly different'),'Tyme is correctly distinguished as a digital-native bank rather than a fintech newly becoming a bank.');
afb_check(str_contains($plain,'2025 review confirms')&&!preg_match('~Moniepoint.{0,240}January 2026~is',$plain),'Moniepoint national licence is grounded in the 2025 review, not falsely dated to January 2026.');
$doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="UTF-8"><html><body>'.$safe.'</body></html>',LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
$external=0;foreach($doc->getElementsByTagName('a')as$link){$href=$link->getAttribute('href');if(str_starts_with($href,'https://')){$external++;afb_check($link->getAttribute('target')==='_blank'&&str_contains($link->getAttribute('rel'),'noopener'),'External sources open safely.');}}
afb_check($external>=5,'Load-bearing claims have inline sources.');
afb_check(count(editorial_toc($safe)['items'])>=10,'Article navigation chapters generated.');
$publisher=(string)file_get_contents($root.'/bin/publish-'.$slug.'.php');
afb_check(str_contains($publisher,'GET_LOCK')&&str_contains($publisher,'FOR UPDATE')&&str_contains($publisher,'RELEASE_LOCK'),'Serialized one-time publication.');
afb_check(!preg_match('~(?:UPDATE|DELETE\s+FROM)\s+posts\b~i',$publisher),'Existing Writer record is never overwritten.');
$hook=(string)file_get_contents($root.'/.platform/hooks/postdeploy/51_africas_fintechs_banking.sh');
afb_check(str_contains($hook,'publish-'.$slug.'.php'),'Postdeploy hook invokes article publisher.');
$layout=(string)file_get_contents($root.'/views/layout.php');
afb_check(!str_contains($layout,'footer-visitor-statistics')&&!str_contains($layout,"You’re in good company"),'Visible footer visitor views stay removed while hidden analytics may remain.');
echo 'PASS: '.$checks." African fintech banking article and footer-retirement checks.\n";
