<?php
declare(strict_types=1);

function media_library_serve(array $asset,bool $inline): never {
    require_auth();journeys_private_headers();
    // Close the session lock while transferring a large video.
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    set_time_limit(300);
    $file=media_library_materialize(media_library_snapshot($asset),media_library_max_bytes());
    try {
        $start=0;$end=$file['bytes']-1;$partial=false;
        $range=(string)($_SERVER['HTTP_RANGE']??'');
        if ($range!=='') {
            if (!preg_match('/\Abytes=([0-9]*)-([0-9]*)\z/',$range,$match) || ($match[1]==='' && $match[2]==='')) {
                http_response_code(416);header('Content-Range: bytes */'.$file['bytes']);media_library_release($file);exit;
            }
            if ($match[1]==='') { $suffix=(int)$match[2];$start=max(0,$file['bytes']-$suffix); }
            else { $start=(int)$match[1];if($match[2]!=='')$end=min($end,(int)$match[2]); }
            if ($start>$end || $start>=$file['bytes']) { http_response_code(416);header('Content-Range: bytes */'.$file['bytes']);media_library_release($file);exit; }
            $partial=true;
        }
        header('Content-Type: '.$file['mime']);header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: sandbox');
        header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="media-'.$asset['id'].'.'.$asset['extension'].'"');
        header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));
        if ($partial) { http_response_code(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$file['bytes']); }
        if (($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD') {
            $stream=fopen($file['path'],'rb');
            if (!$stream) throw new RuntimeException('Unable to open staged media.');
            try {
                if (fseek($stream,$start)!==0) throw new RuntimeException('Unable to seek media.');
                $remaining=$end-$start+1;
                while ($remaining>0 && !feof($stream)) {
                    $chunk=fread($stream,min(65536,$remaining));
                    if ($chunk===false || $chunk==='') break;
                    echo $chunk;$remaining-=strlen($chunk);
                }
            } finally { fclose($stream); }
        }
    } finally { media_library_release($file); }
    exit;
}
function media_library_dispatch(string $path): bool {
    if ($path==='/write/media') {
        journeys_method(['GET','HEAD','POST']);require_auth();journeys_private_headers();$error=null;
        if (is_post()) {
            journeys_verify_csrf();
            try {
                set_time_limit(300);
                $id=media_library_store($_FILES['media']??[],$_POST,(int)current_user()['id']);
                flash('success','Media uploaded. Choose this exact asset when preparing a publishing package.');
                redirect(url('/write/media/'.$id));
            } catch (Throwable $e) {
                if ($e instanceof MediaLibraryException) { http_response_code(422);$error=$e->getMessage(); }
                else $error=journeys_route_error($e);
            }
        }
        $assets=db()->query('SELECT * FROM media_assets ORDER BY created_at DESC,id DESC LIMIT 200')->fetchAll();
        render_write('media_library',['writeTitle'=>'Media library','assets'=>$assets,'error'=>$error]);exit;
    }
    if (preg_match('~\A/write/media/([1-9][0-9]*)(?:/(download|preview))?\z~',$path,$match)) {
        journeys_method(isset($match[2])?['GET','HEAD']:['GET','HEAD','POST']);require_auth();journeys_private_headers();
        $asset=media_library_asset((int)$match[1]);if (!$asset) journeys_not_found();
        if (isset($match[2])) media_library_serve($asset,$match[2]==='preview');
        $error=null;
        if (is_post()) {
            journeys_verify_csrf();
            if (($_POST['action']??'')!=='archive') { http_response_code(422);$error='Choose a valid media action.'; }
            else {
                media_library_archive((int)$asset['id'],(int)current_user()['id']);
                flash('success','Media archived from future selections. Existing approved packages keep their exact media reference.');
                redirect(url('/write/media/'.$asset['id']));
            }
        }
        render_write('media_detail',['writeTitle'=>$asset['title'],'mediaAsset'=>$asset,'error'=>$error]);exit;
    }
    return false;
}
