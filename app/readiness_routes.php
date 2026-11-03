<?php
declare(strict_types=1);
function readiness_dispatch(string $path): bool {
    $diagnostic=preg_match('~^/write/operations/email/([1-9][0-9]{0,17})$~D',$path,$match)===1;
    if($path!=='/write/operations'&&!$diagnostic)return false;
    require_auth();
    header('Cache-Control: no-store, private, max-age=0');header('X-Robots-Tag: noindex, nofollow, noarchive');header('Referrer-Policy: no-referrer');
    $method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    if(!in_array($method,['GET','HEAD'],true)){http_response_code(405);header('Allow: GET, HEAD');echo 'Operations is read-only.';return true;}
    if($diagnostic){$owner=(int)(comm_config()['owner']??0);if($owner<1||(int)(current_user()['id']??0)!==$owner){http_response_code(403);echo 'Owner access required.';return true;}}
    if($method==='HEAD')return true;
    if($diagnostic){$emailReview=readiness_email_diagnostic((int)$match[1]);if(!$emailReview){http_response_code(404);echo 'Queue record unavailable.';return true;}render_write('email_origin',['writeTitle'=>'Email origin review','emailReview'=>$emailReview]);return true;}
    render_write('readiness',['writeTitle'=>'Operations','readiness'=>readiness_snapshot()]);
    return true;
}
