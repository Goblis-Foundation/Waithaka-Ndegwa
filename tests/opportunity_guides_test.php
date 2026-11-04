<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/functions.php';
require_once dirname(__DIR__).'/app/publishing_desk_policy.php';
require_once dirname(__DIR__).'/app/curated_opportunities_release.php';
require_once dirname(__DIR__).'/app/opportunity_guides.php';
$n=0;function ogt(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}
$catalog=curated_release_catalog();$guides=opportunity_guides_catalog();$slugs=array_column($catalog['entries'],'slug');$keys=array_keys($guides['entries']);sort($slugs);sort($keys);ogt($slugs===$keys,'All sixteen guides match the exact source catalog.');
$words=[];
foreach($catalog['entries']as$entry){
    $body=opportunity_guide_body($entry,$guides['entries'][$entry['slug']],$guides['guidance_note']);$plain=plain_text($body);$words[$entry['slug']]=str_word_count($plain);
    ogt($words[$entry['slug']]>=450,'Substantive guide: '.$entry['slug']);
    ogt(substr_count($body,'<h2>')===9,'Nine clearly separated sections.');
    ogt(str_contains($plain,$guides['guidance_note']),'Editorial advice is labelled, not new official criteria.');
    foreach(['benefits','eligibility','conditions','steps']as$key)foreach($entry[$key]as$text)ogt(str_contains($plain,plain_text('<p>'.e($text).'</p>')),'Source-reviewed detail preserved: '.$key);
    foreach(['deadline_label','support','countries']as$key)ogt(str_contains($plain,plain_text('<p>'.e($entry[$key]).'</p>')),'Original factual label preserved.');
    if($entry['notice']!=='')ogt(str_contains($plain,plain_text('<p>'.e($entry['notice']).'</p>')),'Verification limitation remains explicit.');
    ogt(!preg_match('~<script|<iframe|Research cut-off|\[VERIFY\]|/contribute~i',$body),'No active content, internal footer or payment solicitation inside application advice.');
    $dom=new DOMDocument();$old=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$body.'</body></html>',LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
    foreach($dom->getElementsByTagName('a')as$a){ogt(str_starts_with($a->getAttribute('href'),'https://'),'Public source links.');ogt($a->getAttribute('target')==='_blank'&&str_contains($a->getAttribute('rel'),'noopener'),'Safe new tabs.');}
}
$root=dirname(__DIR__);$support=(string)file_get_contents($root.'/views/desk/partials_opportunity_support.php');
foreach(['Support this work','entirely voluntary','not an application fee','does not affect eligibility',"url('/contribute')"]as$term)ogt(str_contains($support,$term),'Support disclosure and correct destination: '.$term);
ogt(!preg_match('~<form|<input|<script|<iframe~i',$support),'No embedded payment or gate.');
$view=(string)file_get_contents($root.'/views/desk/article.php');ogt(str_contains($view,"\$record['kind']==='opportunity'&&!\$sponsored&&!\$preview"),'Support only on public independent opportunities.');
$code=(string)file_get_contents($root.'/app/opportunity_guides_release.php');
foreach(['private_draft_preserved','editorial_changes_preserved','closed_or_private_preserved',"'action'=>'approve'","'action'=>'publish'"]as$term)ogt(str_contains($code,$term),'Publication safeguard: '.$term);
foreach(['enqueue_email(','desk_schedule_campaign(','curl_exec(','desk_send_client(']as$term)ogt(!str_contains($code,$term),'No extra delivery/provider side effects.');
echo 'PASS: '.$n.' guide and support checks. Words: '.min($words).'-'.max($words).PHP_EOL;
