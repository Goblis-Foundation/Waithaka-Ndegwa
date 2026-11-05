<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/editorial_banners.php';
function url(string $path=''):string{return 'https://banners.example.com/'.ltrim($path,'/');}
function e(string $text):string{return htmlspecialchars($text,ENT_QUOTES,'UTF-8');}
function featured_image_url(?string $source):string{return str_starts_with((string)$source,'https://')?(string)$source:url((string)$source);}
function responsive_image_attrs(?string $source,string $sizes='100vw'):string{return 'src="'.e((string)$source).'"';}
$n=0;function ebt(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$catalog=eb_catalog();$themes=[];$hashes=[];
foreach($catalog as$key=>$theme){
    $asset=eb_asset($key);$scene=eb_scene($key);$png=eb_png($key);$size=getimagesizefromstring($png);
    ebt(str_starts_with($png,"\x89PNG\r\n\x1a\n")&&$size[0]===1200&&$size[1]===630&&$size['mime']==='image/png','Valid social-preview PNG: '.$key);
    ebt(strlen($png)<1048576,'Bounded image size.');
    ebt(str_contains($asset['url'],'key='.rawurlencode($key))&&str_contains($asset['url'],'&v='),'Versioned catalogue URL.');
    ebt(str_starts_with($asset['alt'],'Editorial illustration of ')&&$scene['theme']===$theme,'Accurate illustration alternative text.');
    ebt(str_contains(eb_image_attrs($asset),'width="1200"')&&!str_contains(eb_image_attrs($asset),'social-default'),'No legacy image fallback in rendered attributes.');
    $themes[$theme]=true;$hashes[hash('sha256',$png)]=true;
}
ebt(count($themes)>=14&&count($hashes)>=14,'Different subjects have different artwork.');
foreach(['','assets/images/social-default.png','assets/images/brand-lockup.webp','assets/images/wn-approved-monogram.svg','assets/images/old-logo.jpg','assets/images/favicon.ico']as$source){
    $image=eb_post_image(['slug'=>'fresh-writing','category_slug'=>'agribusiness','featured_image'=>$source]);
    ebt($image['illustration']===true&&str_contains($image['url'],'topic-agriculture'),'Missing or old logo becomes relevant article art.');
}
$photo=['slug'=>'the-gold-that-is-nairobis-sewage','title'=>'Test article','featured_image'=>'assets/images/articles/approved-header.jpg','featured_image_alt'=>'Approved treatment plant photograph','featured_image_caption'=>'Original caption'];
$image=eb_post_image($photo);ebt($image['illustration']===false&&$image['url']===url($photo['featured_image'])&&$image['caption']==='Original caption','Approved article photographs and captions remain intact.');
$r=['kind'=>'opportunity','slug'=>'octopus-african-energy-entrepreneurship-awards-2026','public'=>['fields'=>['sponsored'=>false],'creatives'=>['landscape'=>['url'=>'https://banners.example.com/old-logo.png','alt'=>'Old logo']]]];
ebt(eb_record_image($r)['illustration']===true&&str_contains(eb_record_image($r)['url'],$r['slug']),'Known opportunities replace old saved logo creatives.');
$r=['kind'=>'sponsored','public'=>['fields'=>['sponsored'=>true],'creatives'=>['landscape'=>['url'=>'https://banners.example.com/client-approved.png','alt'=>'Approved client image']]]];
ebt(eb_record_image($r)===$r['public']['creatives']['landscape'],'Commercial approved creatives are not overwritten.');
foreach(['../app/config.php','https://unrelated.example.com/image.png','unknown']as$key){try{eb_asset($key);throw new RuntimeException('Unexpected banner key accepted.');}catch(InvalidArgumentException){$n++;}}
$entries=json_decode((string)file_get_contents(dirname(__DIR__).'/app/content/africa-opportunities-20261104.json'),true,64,JSON_THROW_ON_ERROR)['entries'];
$more=json_decode((string)file_get_contents(dirname(__DIR__).'/app/content/rolling-opportunities-20261105.json'),true,64,JSON_THROW_ON_ERROR)['entries'];
foreach(array_merge($entries,$more)as$entry)ebt(isset($catalog[$entry['slug']]),'Every published opportunity has a topic banner.');
restore_error_handler();echo 'PASS: '.$n." banner catalogue, image and preservation assertions.\n";
