<?php
declare(strict_types=1);

/** The owner approved publication with the existing header and without the body photos. */
function nairobi_sewage_header_only_html(string $source): string {
    $found=[];
    $clean=preg_replace_callback('~<figure>.*?</figure>\s*~s',static function(array $match)use(&$found):string{
        if(!preg_match('~<img src="/assets/images/articles/nairobi-sewage/(apeldoorn-(?:visit|equipment|process-board|material)\.jpg)"~',$match[0],$image))throw new RuntimeException('An unexpected source illustration requires review.');
        $found[]=$image[1];
        return '';
    },$source);
    sort($found);
    if(!is_string($clean)||$found!==['apeldoorn-equipment.jpg','apeldoorn-material.jpg','apeldoorn-process-board.jpg','apeldoorn-visit.jpg'])throw new RuntimeException('The original optional photo blocks have changed.');
    return $clean;
}

/** Fixed, owner-approved article and local header. No remote downloads or Writer overrides. */
function nairobi_sewage_article_package(): array {
    $root=dirname(__DIR__);
    $slug='the-gold-that-is-nairobis-sewage';
    $meta=json_decode((string)file_get_contents(__DIR__.'/content/'.$slug.'.json'),true,512,JSON_THROW_ON_ERROR);
    if(($meta['slug']??'')!==$slug||($meta['media_mode']??'')!=='header-only'||count($meta['assets']??[])!==1)throw new RuntimeException('Unexpected sewage article manifest.');
    $asset=$meta['assets'][0];
    $path='/assets/images/articles/nairobi-sewage/header-approved.avif';
    if(($asset['path']??'')!==$path||($meta['featured_image']??'')!==$path||($asset['mime']??'')!=='image/avif')throw new RuntimeException('Invalid approved header path.');
    $file=$root.$path;
    if(!is_file($file)||filesize($file)!==$asset['bytes']||!hash_equals($asset['sha256'],(string)hash_file('sha256',$file)))throw new RuntimeException('The approved article header is missing or changed.');
    $size=getimagesize($file);
    if(!$size||$size[0]!==$asset['width']||$size[1]!==$asset['height']||($size['mime']??'')!=='image/avif')throw new RuntimeException('Unexpected article header dimensions or type.');
    $source=file_get_contents(__DIR__.'/content/'.$slug.'.html');
    if(!is_string($source)||strlen($source)<12000)throw new RuntimeException('Article source is missing or incomplete.');
    // Only the four optional source figures and their captions are omitted. Prose and evidence stay intact.
    $body=sanitize_html(nairobi_sewage_header_only_html($source));
    if(mb_strlen(plain_text($body))<10000||substr_count($body,'<h2>')!==12||str_contains($body,'<figure')||str_contains($body,'<img'))throw new RuntimeException('The complete header-only article is required.');
    return ['meta'=>$meta,'body'=>$body];
}
