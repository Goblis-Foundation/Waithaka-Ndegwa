<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/editorial_banners.php';
function url(string $path=''):string{return 'https://banners.example.com/'.ltrim($path,'/');}
function e(string $text):string{return htmlspecialchars($text,ENT_QUOTES,'UTF-8');}
function featured_image_url(?string $source):string{return str_starts_with((string)$source,'https://')?(string)$source:url((string)$source);}
function responsive_image_attrs(?string $source,string $sizes='100vw'):string{return 'src="'.e((string)$source).'"';}
require dirname(__DIR__).'/app/article_banner_policy.php';
$n=0;function ebt(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$catalog=eb_catalog();$themes=[];$hashes=[];$versions=[];
foreach($catalog as$key=>$theme){
    $asset=eb_asset($key);$scene=eb_scene($key);$png=eb_png($key);$size=getimagesizefromstring($png);
    ebt(str_starts_with($png,"\x89PNG\r\n\x1a\n")&&$size[0]===1200&&$size[1]===630&&$size['mime']==='image/png','Valid social-preview PNG: '.$key);
    ebt(strlen($png)<1048576,'Bounded image size.');
    ebt(str_contains($asset['url'],'key='.rawurlencode($key))&&str_contains($asset['url'],'&v='),'Versioned catalogue URL.');
    parse_str((string)parse_url($asset['url'],PHP_URL_QUERY),$query);
    ebt(eb_key_from_version($query['v'])===$key,'Version alone resolves the correct origin illustration.');
    ebt(!isset($versions[$query['v']]),'No two catalogue entries share a CDN cache key.');$versions[$query['v']]=$key;
    ebt(eb_version($key)===$query['v'],'Asset URLs and the endpoint share the same identity.');
    ebt(str_starts_with($asset['alt'],'Editorial illustration of ')&&$scene['theme']===$theme,'Accurate illustration alternative text.');
    ebt(str_contains(eb_image_attrs($asset),'width="1200"')&&!str_contains(eb_image_attrs($asset),'social-default'),'No legacy image fallback in rendered attributes.');
    $themes[$theme]=true;$hashes[hash('sha256',$png)]=true;
}
ebt(count($themes)>=14&&count($hashes)>=14,'Different subjects have different artwork.');
foreach([null,'',[],['v'=>'bad'],str_repeat('0',24),'../app/config.php','https://unrelated.example.com']as$version)ebt(eb_key_from_version($version)===null,'Malformed or unknown version cannot choose an image.');
$edge=json_decode((string)file_get_contents(dirname(__DIR__).'/deploy/aws/edge-stack.json'),true,64,JSON_THROW_ON_ERROR);
$vary=$edge['Resources']['StaticCache']['Properties']['CachePolicyConfig']['ParametersInCacheKeyAndForwardedToOrigin']['QueryStringsConfig'];
ebt($vary['QueryStringBehavior']==='whitelist'&&in_array('v',$vary['QueryStrings'],true),'The deployed edge forwards and varies on banner identity.');
foreach(['','assets/images/social-default.png','assets/images/brand-lockup.webp','assets/images/wn-approved-monogram.svg','assets/images/legacy-lockup.jpg','assets/images/favicon.ico']as$source){
    $image=eb_post_image(['slug'=>'fresh-writing','category_slug'=>'agribusiness','featured_image'=>$source]);
    ebt($image['illustration']===true&&str_contains($image['url'],'topic-agriculture'),'Missing or old logo becomes relevant article art.');
}
$photo=['slug'=>'the-gold-that-is-nairobis-sewage','title'=>'Test article','featured_image'=>'assets/images/articles/approved-header.jpg','featured_image_alt'=>'Approved treatment plant photograph','featured_image_caption'=>'Original caption'];
$image=eb_post_image($photo);ebt($image['illustration']===false&&$image['url']===url($photo['featured_image'])&&$image['caption']==='Original caption','Approved article photographs and captions remain intact.');
$r=['kind'=>'opportunity','slug'=>'octopus-african-energy-entrepreneurship-awards-2026','public'=>['fields'=>['sponsored'=>false],'creatives'=>['landscape'=>['url'=>'https://banners.example.com/legacy-lockup.png','alt'=>'Legacy lockup']]]];
ebt(eb_record_image($r)['illustration']===true&&str_contains(eb_record_image($r)['url'],$r['slug']),'Known opportunities replace old saved logo creatives.');
$r=['kind'=>'sponsored','public'=>['fields'=>['sponsored'=>true],'creatives'=>['landscape'=>['url'=>'https://banners.example.com/client-approved.png','alt'=>'Approved client image']]]];
ebt(eb_record_image($r)===$r['public']['creatives']['landscape'],'Commercial approved creatives are not overwritten.');
foreach(['../app/config.php','https://unrelated.example.com/image.png','unknown']as$key){try{eb_asset($key);throw new RuntimeException('Unexpected banner key accepted.');}catch(InvalidArgumentException){$n++;}}
$entries=json_decode((string)file_get_contents(dirname(__DIR__).'/app/content/africa-opportunities-20261104.json'),true,64,JSON_THROW_ON_ERROR)['entries'];
$more=json_decode((string)file_get_contents(dirname(__DIR__).'/app/content/rolling-opportunities-20261105.json'),true,64,JSON_THROW_ON_ERROR)['entries'];
foreach(array_merge($entries,$more)as$entry)ebt(isset($catalog[$entry['slug']]),'Every published opportunity has a topic banner.');

// Article-specific banner policy: researched subject, one approved identity and no recycled old mark.
ebt(abp_is_approved_logo('/assets/images/brand-lockup.webp'),'Current approved horizontal identity is recognised.');
ebt(abp_is_approved_logo('/assets/images/wn-approved-monogram.svg'),'Current approved monogram is recognised.');
ebt(!abp_is_legacy_logo('/assets/images/brand-lockup.webp'),'Approved identity is not classified as legacy.');
ebt(abp_is_legacy_logo('/assets/images/logo.png')&&abp_is_legacy_logo('/assets/images/waithaka-wn-logo-proposal.svg'),'Former marks are classified as legacy.');
ebt(abp_is_logo_only('/assets/images/brand-lockup.webp')&&abp_is_logo_only('/assets/images/logo.png'),'A logo alone is never accepted as article artwork.');
$briefs=abp_briefs();
foreach(['impact-driven-inspiration','christmas-truce-humanity-leadership','tong-wenhong-long-term-value','kenya-visa-free-eta-2026','dhl-friction-logistics-lesson','sugar-beet-kenya-complement-not-cure','tomato-processing-cooperatives-kenya','electric-vehicles-biofuels-africa','tanzania-railways-vision-2050','the-gold-that-is-nairobis-sewage','from-impact-evidence-to-economic-demand','africas-fintechs-are-becoming-banks']as$slug){
    $brief=$briefs[$slug]??null;ebt(is_array($brief),'Curated visual brief exists for '.$slug);
    ebt(count($brief['motifs']??[])>=1&&count($brief['research_basis']??[])>=1,'Visual brief records motifs and research basis for '.$slug);
    ebt(mb_strlen((string)($brief['alt']??''))>=35&&str_contains((string)($brief['caption']??''),'Approved Waithaka Ndegwa branding.'),'Accessible description and approved-brand disclosure for '.$slug);
}
$fintech=abp_post_image(['slug'=>'africas-fintechs-are-becoming-banks','title'=>'Africa’s Fintechs Are Becoming Banks','featured_image'=>'/assets/images/logo.png']);
ebt(($fintech['scene']??'')==='fintech-banking'&&($fintech['policy']??'')==='legacy-logo-replaced','Old logo is replaced by the researched fintech scene.');
ebt(str_contains($fintech['url'],'/assets/article-banner.php?v='),'Article banner uses its dedicated cache-safe endpoint.');
parse_str((string)parse_url($fintech['url'],PHP_URL_QUERY),$articleQuery);$identity=abp_identity_from_token($articleQuery['v']??null);
ebt(($identity['slug']??'')==='africas-fintechs-are-becoming-banks'&&($identity['scene']??'')==='fintech-banking','Article token binds slug and researched scene.');
foreach([null,'','bad',str_repeat('a',24),'../secret.fintech-banking.'.str_repeat('a',24)]as$bad)ebt(abp_identity_from_token($bad)===null,'Malformed article banner token rejected.');
$articlePng=abp_png('africas-fintechs-are-becoming-banks','fintech-banking');$articleSize=getimagesizefromstring($articlePng);
ebt(str_starts_with($articlePng,"\x89PNG\r\n\x1a\n")&&$articleSize[0]===1200&&$articleSize[1]===630,'Researched article banner is a 1200×630 PNG.');
$articleImage=imagecreatefromstring($articlePng);$brandPixels=0;
for($y=548;$y<598;$y+=5)for($x=925;$x<1150;$x+=5){$rgb=imagecolorat($articleImage,$x,$y)&0xFFFFFF;if(in_array($rgb,[0x073B6A,0x2F6F3E],true))$brandPixels++;}
imagedestroy($articleImage);ebt($brandPixels>5,'Rendered banner contains the current approved blue/green identity in the restrained brand area.');
$custom=abp_post_image(['slug'=>'custom-article','title'=>'Custom article','featured_image'=>'/assets/images/articles/custom.jpg','featured_image_alt'=>'A relevant approved editorial photograph','featured_image_caption'=>'Photograph used with permission.']);
ebt(($custom['policy']??'')==='approved-custom-image'&&!empty($custom['url'])&&empty($custom['approved_brand']),'Relevant approved photographs remain available without forced logo overlay.');
$derived=abp_brief_for_post(['slug'=>'future-rail-analysis','title'=>'A new railway corridor for East Africa','standfirst'=>'Ports, freight and regional trade','content_html'=>'<p>Railway and port infrastructure.</p>']);
ebt(($derived['scene']??'')==='rail-port'&&!empty($derived['derived']),'Future articles receive a subject-derived visual brief rather than a recycled generic logo.');
$policySource=(string)file_get_contents(dirname(__DIR__).'/app/article_banner_policy.php');
ebt(str_contains($policySource,'hash_equals($expected')&&str_contains($policySource,'brand-lockup.webp'),'Only the hash-locked approved logo is stamped into generated banners.');

restore_error_handler();echo 'PASS: '.$n." opportunity artwork plus researched article-banner, cache, card and approved-logo policy assertions.\n";
