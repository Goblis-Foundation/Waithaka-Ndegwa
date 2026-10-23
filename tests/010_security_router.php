<?php
declare(strict_types=1);
// Local test server only. Never expose source, private storage or test fixtures.
$root=dirname(__DIR__);
$path=rawurldecode((string)(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/'));
if(str_contains($path,'..')||str_contains($path,"\0")||preg_match('~(?:^|/)\.|^/(?:app|storage|bin|tests|vendor|install)(?:/|$)~i',$path)){
    http_response_code(404);header('Cache-Control: no-store');echo 'Not found';return true;
}
if(preg_match('~^/assets/[a-zA-Z0-9_./-]+\.(?:css|js|png|jpg|jpeg|webp|gif|ico|svg|woff|woff2)$~D',$path)){
    $file=realpath($root.$path);$assets=realpath($root.'/assets');
    if($file&&$assets&&str_starts_with($file,$assets.DIRECTORY_SEPARATOR)&&is_file($file))return false;
}
if($path==='/manifest.webmanifest')return false;
require $root.'/index.php';
