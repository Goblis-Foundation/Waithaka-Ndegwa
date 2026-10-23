<?php
declare(strict_types=1);
function readiness_dispatch(string $path): bool {
    if($path!=='/write/operations')return false;
    require_auth();
    header('Cache-Control: no-store, private, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');header('Referrer-Policy: no-referrer');
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');echo 'Operations is read-only.';return true;}
    if($method==='HEAD')return true;
    render_write('readiness',['writeTitle'=>'Operations','readiness'=>readiness_snapshot()]);
    return true;
}
