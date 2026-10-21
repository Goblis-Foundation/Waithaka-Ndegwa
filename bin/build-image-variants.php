<?php
declare(strict_types=1);
/** Create smaller derivatives of the existing public photography, without replacing originals. */
if(PHP_SAPI!=='cli')exit(2);
if(!function_exists('imagecreatefromwebp')||!function_exists('imagewebp')){fwrite(STDERR,"PHP GD with WebP support is required.\n");exit(2);}
$root=realpath(dirname(__DIR__).'/assets/images/journey');
if(!$root)throw new RuntimeException('Public photography directory is missing.');
$created=0;$bytes=0;
foreach(glob($root.'/*.webp')as$file){
    if(is_link($file)||preg_match('/-[0-9]+w\.webp$/D',$file))continue;
    $size=getimagesize($file);if(!$size||$size[0]*$size[1]>40000000)continue;
    $source=imagecreatefromwebp($file);if(!$source)continue;
    try{foreach([480,960]as$width){
        if($width>=$size[0])continue;
        $destination=substr($file,0,-5).'-'.$width.'w.webp';if(is_file($destination))continue;
        $height=max(1,(int)round($size[1]*$width/$size[0]));$output=imagecreatetruecolor($width,$height);
        imagealphablending($output,false);imagesavealpha($output,true);
        imagecopyresampled($output,$source,0,0,0,0,$width,$height,$size[0],$size[1]);
        if(!imagewebp($output,$destination,80)){imagedestroy($output);throw new RuntimeException('Cannot write a public image derivative.');}
        imagedestroy($output);$created++;$bytes+=filesize($destination);
    }}finally{imagedestroy($source);}
}
echo json_encode(['created'=>$created,'bytes'=>$bytes,'originals_changed'=>false],JSON_THROW_ON_ERROR).PHP_EOL;
