<?php
declare(strict_types=1);

$path=dirname(__DIR__).'/app/content/linkedin-article-summaries-20261106.json';
$data=json_decode((string)file_get_contents($path),true,64,JSON_THROW_ON_ERROR);
$fail=[];
$ok=function(bool $v,string $m)use(&$fail){if(!$v)$fail[]=$m;};

$ok(($data['release']??'')==='linkedin-article-summaries-20261106','release id');
$ok(($data['status']??'')==='prepared_not_provider_published','provider publication not fabricated');
$posts=$data['posts']??[];
$ok(count($posts)===7,'seven current article summaries');

$expected=[
 'christmas-truce-humanity-leadership',
 'tong-wenhong-long-term-value',
 'kenya-visa-free-eta-2026',
 'dhl-friction-logistics-lesson',
 'sugar-beet-kenya-complement-not-cure',
 'tomato-processing-cooperatives-kenya',
 'from-impact-evidence-to-economic-demand',
];
$ok(array_column($posts,'slug')===$expected,'stable article order/slugs');

foreach($posts as$post){
  $slug=(string)($post['slug']??'');
  $url=(string)($post['url']??'');
  $text=(string)($post['linkedin']??'');
  $ok($url==='https://www.waithakandegwa.com/my-thoughts/'.$slug,$slug.' canonical URL');
  $ok(str_contains($text,$url),$slug.' summary retains article link');
  $ok(mb_strlen($text)>=900,$slug.' summary is substantive enough to stand alone');
  $ok(!str_contains(strtolower($text),'earlier note'),$slug.' does not imply an unpublished predecessor');
}
$tomato=(string)($posts[5]['linkedin']??'');
$ok(str_contains($tomato,'less than 2%')&&str_contains($tomato,'processing'), 'tomato summary carries the processing/coordination argument');
$sugar=(string)($posts[4]['linkedin']??'');
$ok(str_contains($sugar,'30 November 2025')&&str_contains($sugar,'4 November 2026')&&str_contains($sugar,'COMESA'),'sugar summary preserves legal/political COMESA distinction');

$importer=(string)file_get_contents(dirname(__DIR__).'/bin/import-linkedin-article-summaries-20261106.php');
$ok(str_contains($importer,'publishing_default_draft')&&str_contains($importer,'publishing_save_package'),'Importer creates native Writer publishing drafts');
$ok(str_contains($importer,"['linkedin']['enabled']=true")||str_contains($importer,"['linkedin']['enabled'] = true"),'Importer enables only the reviewed LinkedIn variant');
$ok(!str_contains($importer,'publishing_approve(')&&!str_contains($importer,'publishing_schedule('),'Importer cannot approve or schedule provider publication');
$hook=(string)file_get_contents(dirname(__DIR__).'/.platform/hooks/postdeploy/46_linkedin_article_summaries_20261106.sh');
$ok(str_contains($hook,'import-linkedin-article-summaries-20261106.php'),'Production postdeploy invokes LinkedIn draft import');

if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}
echo "PASS: LinkedIn summaries are substantive, canonical and provider-safe.\n";
