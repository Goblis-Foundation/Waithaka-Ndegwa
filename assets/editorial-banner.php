<?php
declare(strict_types=1);
require_once __DIR__.'/../app/editorial_banners.php';
// Public, fixed illustrations only. No database, uploads, credentials or remote URLs.
$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Cache-Control: no-store');exit;}
// The edge varies and forwards v. The human-readable key is deliberately not
// authoritative, so a missing or changed key cannot poison another banner's cache.
$key=eb_key_from_version($_GET['v']??null);
if($key===null){http_response_code(404);header('Cache-Control: no-store');exit;}
$revision=hash_file('sha256',__DIR__.'/../app/editorial_banners.php');
$hash=hash('sha256',$revision.':'.$key);$etag='"'.$hash.'"';
header('Content-Type: image/png');header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');header('ETag: '.$etag);
header('X-Editorial-Banner-Key: '.$key);header('X-Editorial-Banner-Theme: '.eb_catalog()[$key]);
if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);exit;}
if($method==='HEAD')exit;
try{
    $directory=sys_get_temp_dir().'/wn-editorial-banners';$file=$directory.'/'.$hash.'.png';
    $cacheable=!is_link($directory)&&(!file_exists($directory)||is_dir($directory));
    if($cacheable&&!is_dir($directory))$cacheable=@mkdir($directory,0700,true)||is_dir($directory);
    if($cacheable&&!is_link($file)&&is_file($file)&&filesize($file)>8&&filesize($file)<1048576){header('Content-Length: '.filesize($file));readfile($file);exit;}
    $png=eb_png($key);
    if($cacheable&&is_writable($directory)&&!is_link($file)){
        $temporary=tempnam($directory,'render-');
        if($temporary!==false){if(file_put_contents($temporary,$png)===strlen($png)){chmod($temporary,0600);if(!@rename($temporary,$file))@unlink($temporary);}else @unlink($temporary);}
    }
    header('Content-Length: '.strlen($png));echo $png;
}catch(Throwable){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');echo 'Editorial image temporarily unavailable.';}
