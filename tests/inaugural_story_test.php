<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/inaugural_story.php';
$checks=0;
function ist_check(bool $pass,string $message):void{if(!$pass)throw new RuntimeException($message);$GLOBALS['checks']++;}
$post=['slug'=>'impact-driven-inspiration'];
$html='<p>Original introduction.</p><h2 id="first">The people who opened doors</h2><p>Original education paragraph.</p><h3 id="child">Nested heading</h3><p>Keep nested content.</p><h2 id="second">Changed title</h2><p>Edited Writer content remains authoritative.</p>';
$parts=inaugural_story_parts($post,['html'=>$html]);
ist_check($parts!==null,'The inaugural article opts in.');
ist_check(count($parts['chapters'])===2,'Only H2 chapters partition the article.');
ist_check($parts['intro'].implode('',array_column($parts['chapters'],'html'))===$html,'All sanitized article markup is preserved exactly and in order.');
ist_check($parts['chapters'][0]['id']==='first','Existing editorial anchors are preserved.');
ist_check(count($parts['chapters'][0]['photos'])===1,'A relevant chapter receives its approved photograph.');
ist_check($parts['chapters'][1]['photos']===[],'An edited heading is not given a guessed photograph.');
ist_check(inaugural_story_parts(['slug'=>'another-article'],['html'=>$html])===null,'Other articles keep their original layout.');
ist_check(inaugural_story_parts($post,['html'=>'<p>No chapters.</p>'])===null,'Articles without chapters use the safe legacy layout.');
ist_check(inaugural_story_parts($post,['html'=>'<h2>No anchor</h2><p>Keep content.</p>'])===null,'Missing anchors use the legacy layout rather than losing content.');
foreach(['conversation','early-learning','farm-cassava','travel-open-bus','skydiving']as$image)ist_check(is_file(dirname(__DIR__).'/assets/images/journey/'.$image.'.webp'),'Selected original photograph exists: '.$image);
$home=file_get_contents(dirname(__DIR__).'/views/home.php');
ist_check(!str_contains($home,'openingEssay')&&!str_contains($home,'featured-thought')&&!str_contains($home,'partials_post_card'),'Articles stay off the homepage.');
echo 'PASS: '.$checks." inaugural article content-preservation and asset checks.\n";
