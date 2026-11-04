<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/ev_biofuels_header.php';
require_once dirname(__DIR__).'/app/functions.php';
function evh_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$spec=evb_header_spec();$file=dirname(__DIR__).$spec['path'];
evh_check(hash_file('sha256',$file)===$spec['sha256'],'Asset bytes match the approved illustration.');
$size=getimagesize($file);evh_check($size[0]===1672&&$size[1]===941,'Full uncropped image dimensions.');
evh_check(str_contains($spec['caption'],'AI-generated')&&str_contains($spec['caption'],'not a photograph'),'Synthetic illustration clearly labelled.');
$note='<p><em>'.evb_header_research_note().'</em></p>';
$body='<p>Keep the original story and <a href="https://example.com">source</a>.</p>';
foreach([$note,"<hr>\n".$note,"<hr />\n".$note."\n"] as $tail)evh_check(evb_header_clean_html($body.$tail)===$body,'Only the exact trailing note is removed.');
evh_check(evb_header_clean_html($body)===$body,'No-note articles are byte-for-byte unchanged.');
evh_check(evb_header_clean_html($note.$body)===$note.$body,'A moved note is not silently deleted.');
evh_check(evb_header_clean_html(str_replace('2026','2027',$note))===str_replace('2026','2027',$note),'Changed text is not broadly matched.');
$raw=(string)file_get_contents(dirname(__DIR__).'/app/content/electric-vehicles-biofuels-africa.html');
$safe=sanitize_html($raw);$clean=evb_header_clean_html($safe);
evh_check(!str_contains($clean,evb_header_research_note()),'Note removed from actual sanitized article.');
evh_check(substr_count($safe,'<h2>')===substr_count($clean,'<h2>'),'All article chapters preserved.');
evh_check(substr_count($safe,'href=')===substr_count($clean,'href='),'All citations and reader links preserved.');
evh_check(evb_header_clean_html($clean)===$clean,'Cleanup is idempotent.');
$publisher=(string)file_get_contents(dirname(__DIR__).'/bin/publish-ev-biofuels-article.php');
evh_check(str_contains($publisher,'evb_header_apply'),'Established deployment hook reaches the exact update.');
echo "PASS: approved image fingerprint, caption, exact-note removal, unchanged chapters/links and repeat safety.\n";
