<?php
declare(strict_types=1);
$root=dirname(__DIR__).'/app/content/';
$data=json_decode((string)file_get_contents($root.'personalized-source-articles-20261106.json'),true,256,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $m)use(&$fail){if(!$v)$fail[]=$m;};
$ok(($data['release']??'')==='personalized-source-articles-20261106','Release identity');
$ok(($data['research_cutoff']??'')==='2026-11-06','Research cutoff');
$entries=$data['entries']??[];$ok(count($entries)===6,'Six attached-source articles');
$expected=['christmas-truce-humanity-leadership','tong-wenhong-long-term-value','kenya-visa-free-eta-2026','dhl-friction-logistics-lesson','sugar-beet-kenya-complement-not-cure','tomato-processing-cooperatives-kenya'];
$ok(array_column($entries,'slug')===$expected,'Exact reviewed article set');
foreach($entries as$meta){
  $slug=$meta['slug'];$html=(string)file_get_contents($root.$slug.'.html');$plain=trim(strip_tags($html));$words=count(preg_split('/\s+/u',$plain,-1,PREG_SPLIT_NO_EMPTY));
  $ok($words>=550,$slug.' has useful depth');$ok(substr_count($html,'<h2>')>=5,$slug.' is structured');
  $ok(str_contains($html,'Sources checked')||str_contains($html,'Official information checked'),$slug.' names current evidence');
  $ok(str_contains($html,'6 November 2026'),$slug.' discloses current check date');
  $ok(($meta['article_groups']??[])===['ideas-perspectives'],$slug.' remains in My Thoughts ideas/perspectives');
  $ok(!preg_match('~earlier (?:note|notes|personal notes|draft)|updates earlier|revisit(?:s|ed)?|updated view~i',$html),$slug.' reads as a standalone first publication');
  $ok(in_array($meta['category_slug'],['social-entrepreneurship','agribusiness','national-infrastructure','travel'],true),$slug.' uses an approved topic');
}
$tong=(string)file_get_contents($root.'tong-wenhong-long-term-value.html');$ok(str_contains($tong,'not Tong—is the Group’s current Chief People Officer')&&!str_contains($tong,'currently serves as Alibaba’s chief people officer'),'Tong article corrects stale role');
$visa=(string)file_get_contents($root.'kenya-visa-free-eta-2026.html');$ok(str_contains($visa,'Visa-free does not mean paperwork-free')&&str_contains($visa,'2025 immigration-rule amendments'),'Kenya travel article reflects current eTA framework');
$sugar=(string)file_get_contents($root.'sugar-beet-kenya-complement-not-cure.html');$ok(str_contains($sugar,'beet-sugar production at zero')&&str_contains($sugar,'not “the solution” today'),'Sugar beet article rejects unsupported cure claim');
$tomato=(string)file_get_contents($root.'tomato-processing-cooperatives-kenya.html');$ok(str_contains($tomato,'less than 2%')&&str_contains($tomato,'about 93 tomato processors'),'Tomato article uses current KALRO evidence');
$dhl=(string)file_get_contents($root.'dhl-friction-logistics-lesson.html');$ok(str_contains($dhl,'€300 million')&&str_contains($dhl,'approximately 584,000'),'DHL article uses current Group scale and Africa investment');
$truce=(string)file_get_contents($root.'christmas-truce-humanity-leadership.html');$ok(str_contains($truce,'not one centrally organised truce')&&str_contains($truce,'no major repeat'),'Christmas Truce article avoids popular simplification');
$legacyRoot=dirname(__DIR__).'/assets/images/';$ok(!is_file($legacyRoot.'logo.png')&&!is_file($legacyRoot.'waithaka-wn-logo-proposal.svg'),'Legacy Waithaka logo assets are absent from the repository');
$bannerModule=(string)file_get_contents(dirname(__DIR__).'/app/editorial_banners.php');foreach($expected as$slug)$ok(str_contains($bannerModule,"'".$slug."'=>"),$slug.' has a dedicated editorial banner mapping');
$publisher=(string)file_get_contents(dirname(__DIR__).'/bin/publish-personalized-source-articles.php');$ok(str_contains($publisher,"'published',0,NOW()")||str_contains($publisher,"'published',0,NOW(),"),'Publisher keeps every article off homepage selection');$ok(str_contains($publisher,"SELECT id,user_id,title,standfirst,content_html,status,published_at FROM posts WHERE slug=?"),'Publisher locates existing article by slug');$ok(str_contains($publisher,"featured_image='',featured_image_alt='',featured_image_caption=''"),'Publisher clears stale featured-image and legacy-logo fields');$ok(str_contains($publisher,'INSERT INTO post_revisions'),'Publisher preserves the previous public body before refresh');$ok(!str_contains($publisher,'earlier personal notes'),'Publisher metadata does not claim an unpublished predecessor');
$publicArticleFiles=glob($root.'*.html')?:[];foreach($publicArticleFiles as$file){$html=(string)file_get_contents($file);$name=basename($file);$ok(!preg_match('~(?:my )?earlier (?:note|notes|personal notes|draft)|updates? (?:an )?earlier (?:note|notes|personal notes|draft)|revisit(?:s|ed)? (?:my |an |the )?(?:earlier )?(?:note|notes|draft)|my updated view~i',$html),$name.' does not assert an unpublished predecessor');}
if($fail){fwrite(STDERR,'FAIL: '.implode('; ',$fail)."\n");exit(1);}echo "PASS: six attached-source articles are current, personalized, evidence-linked and homepage-excluded; public articles do not assert unpublished predecessors.\n";
