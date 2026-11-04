<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/functions.php';
require_once dirname(__DIR__).'/app/editorial.php';
$root=dirname(__DIR__);
$slug='electric-vehicles-biofuels-africa';
$meta=json_decode((string)file_get_contents($root.'/app/content/'.$slug.'.json'),true,512,JSON_THROW_ON_ERROR);
$html=(string)file_get_contents($root.'/app/content/'.$slug.'.html');
$checks=0;
function eba_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;}
eba_check($meta['slug']===$slug,'Fixed canonical article slug.');
eba_check($meta['research_cutoff']==='2026-11-04','Dated research cut-off.');
$safe=sanitize_html($html);
$plain=plain_text($safe);
eba_check(mb_strlen($plain)>10000,'Complete long-form article survives sanitization.');
eba_check(!str_contains($safe,'<script')&&!str_contains($safe,'<iframe'),'No active embedded article content.');
foreach(['4 November 2026','30 September 2026','3 November 2026','Turkana','US$99','29%','co-founder','Crostar Energy LTD','traceable renewable-oils']as$term)eba_check(str_contains($plain,$term),'Verified claim/context retained: '.$term);
foreach(['Croster','161,700','January 2027','Jan 2027','Q4 2026','late-2026','commissioning timetable','CEOS']as$term)eba_check(!str_contains($html,$term),'No stale anecdote, misspelling or excluded company rollout detail: '.$term);
preg_match('~<h2>The purpose behind Crostar Energy</h2>\s*(.*?)\s*<h2>~s',$html,$company);
eba_check(isset($company[1])&&substr_count($company[1],'<p>')===1,'Crostar is limited to one aims paragraph.');
eba_check(isset($company[1])&&str_word_count(strip_tags($company[1]))<100,'Crostar paragraph stays brief.');
$toc=editorial_toc($safe);
eba_check(count($toc['items'])>=10,'Navigable article chapters are generated.');
eba_check(!preg_match('~<h1\b~i',$html),'The template owns the only H1.');
$dom=new DOMDocument();$old=libxml_use_internal_errors(true);
$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$safe.'</body></html>',LIBXML_NONET);
libxml_clear_errors();libxml_use_internal_errors($old);
$external=0;
foreach($dom->getElementsByTagName('a')as$link){
    $href=$link->getAttribute('href');
    if(str_starts_with($href,'https://')){
        $external++;
        eba_check($link->getAttribute('target')==='_blank','External source opens in a new tab.');
        eba_check(str_contains($link->getAttribute('rel'),'noopener'),'External source has safe relationship.');
    }else eba_check(in_array($href,['/contact','/newsletter'],true),'Only approved internal calls to action.');
}
eba_check($external>=20,'Load-bearing facts have inline primary/news evidence.');
$home=(string)file_get_contents($root.'/views/home.php');
eba_check(!str_contains($home,'openingEssay')&&!str_contains($home,'featured-thought')&&!str_contains($home,'partials_post_card'),'Homepage stays article-free.');
$publisher=(string)file_get_contents($root.'/bin/publish-ev-biofuels-article.php');
eba_check(str_contains($publisher,'FOR UPDATE')&&str_contains($publisher,'GET_LOCK')&&str_contains($publisher,'RELEASE_LOCK'),'Serialized single-publication protection.');
eba_check(!preg_match('~UPDATE\s+posts\b~i',$publisher),'Existing article content is never overwritten.');
echo 'PASS: '.$checks." EV/biofuels article, source-link and publication-contract checks.\n";
