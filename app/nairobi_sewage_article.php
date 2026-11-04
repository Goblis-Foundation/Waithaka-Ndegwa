<?php
declare(strict_types=1);

/** Fixed, owner-approved article and media. No remote downloads at publication time. */
function nairobi_sewage_article_package(): array {
    $root=dirname(__DIR__);
    $slug='the-gold-that-is-nairobis-sewage';
    $meta=json_decode((string)file_get_contents(__DIR__.'/content/'.$slug.'.json'),true,512,JSON_THROW_ON_ERROR);
    if(($meta['slug']??'')!==$slug||count($meta['assets']??[])!==5)throw new RuntimeException('Unexpected sewage article manifest.');
    foreach($meta['assets'] as $asset){
        $path=(string)($asset['path']??'');
        if(!preg_match('~^/assets/images/articles/nairobi-sewage/[a-z0-9-]+\.jpg$~D',$path))throw new RuntimeException('Invalid article asset path.');
        $file=$root.$path;
        if(!is_file($file)||filesize($file)!==$asset['bytes']||!hash_equals($asset['sha256'],(string)hash_file('sha256',$file)))throw new RuntimeException('An approved article image is missing or changed.');
        $size=getimagesize($file);
        if(!$size||$size[0]!==$asset['width']||$size[1]!==$asset['height']||$size[2]!==IMAGETYPE_JPEG)throw new RuntimeException('Unexpected article image dimensions.');
    }
    $source=file_get_contents(__DIR__.'/content/'.$slug.'.html');
    if(!is_string($source)||strlen($source)<12000)throw new RuntimeException('Article source is missing or incomplete.');
    $body=sanitize_html($source);
    if(mb_strlen(plain_text($body))<10000||substr_count($body,'<h2>')!==12||substr_count($body,'<figure>')!==4)throw new RuntimeException('The complete article and four original photographs are required.');
    return ['meta'=>$meta,'body'=>$body];
}
