<?php
declare(strict_types=1);
require_once __DIR__.'/../app/article_banner_policy.php';

$method=strtoupper($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');header('Cache-Control: no-store');exit;}
$identity=abp_identity_from_token($_GET['v']??null);
if($identity===null){http_response_code(404);header('Cache-Control: no-store');exit;}
$token=(string)$_GET['v'];$etag='"'.hash('sha256',$token).'"';
header('Content-Type: image/png');header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');header('ETag: '.$etag);
header('X-Article-Banner-Slug: '.$identity['slug']);header('X-Article-Banner-Scene: '.$identity['scene']);
header('X-Article-Banner-Brand: approved-waithaka-ndegwa');
if(($_SERVER['HTTP_IF_NONE_MATCH']??'')===$etag){http_response_code(304);exit;}
if($method==='HEAD')exit;
try{
    $hash=hash('sha256',$token);$directory=sys_get_temp_dir().'/wn-article-banners';$file=$directory.'/'.$hash.'.png';
    $cacheable=!is_link($directory)&&(!file_exists($directory)||is_dir($directory));
    if($cacheable&&!is_dir($directory))$cacheable=@mkdir($directory,0700,true)||is_dir($directory);
    if($cacheable&&!is_link($file)&&is_file($file)&&filesize($file)>8&&filesize($file)<1500000){header('Content-Length: '.filesize($file));readfile($file);exit;}
    $png=abp_png($identity['slug'],$identity['scene']);
    if($cacheable&&is_writable($directory)&&!is_link($file)){
        $temporary=tempnam($directory,'render-');
        if($temporary!==false){if(file_put_contents($temporary,$png)===strlen($png)){chmod($temporary,0600);if(!@rename($temporary,$file))@unlink($temporary);}else @unlink($temporary);}
    }
    header('Content-Length: '.strlen($png));echo $png;
}catch(Throwable){http_response_code(503);header('Content-Type: text/plain; charset=utf-8');header('Cache-Control: no-store');echo 'Article image temporarily unavailable.';}
