<?php
declare(strict_types=1);

/** Owned, immutable publishing assets. Remote URLs are never fetched by this module. */
class MediaLibraryException extends InvalidArgumentException {}

function media_library_max_bytes(): int { return 512 * 1024 * 1024; }
function media_library_image_max_bytes(): int { return 25 * 1024 * 1024; }
function media_library_types(): array {
    return [
        'image/jpeg'=>['kind'=>'image','extension'=>'jpg'],
        'image/png'=>['kind'=>'image','extension'=>'png'],
        'image/webp'=>['kind'=>'image','extension'=>'webp'],
        'video/mp4'=>['kind'=>'video','extension'=>'mp4'],
        'video/quicktime'=>['kind'=>'video','extension'=>'mov'],
        'video/webm'=>['kind'=>'video','extension'=>'webm'],
    ];
}
function media_library_inspect(string $path, int $maxBytes): array {
    if (!is_file($path) || is_link($path) || !is_readable($path)) throw new MediaLibraryException('The uploaded file is not readable.');
    $bytes=filesize($path);
    if ($bytes===false || $bytes<1 || $bytes>$maxBytes) throw new MediaLibraryException('The file is empty or exceeds the upload limit.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    $type=media_library_types()[$mime]??null;
    if (!$type) throw new MediaLibraryException('Choose a JPEG, PNG, WebP, MP4, MOV or WebM file.');
    if ($type['kind']==='image') {
        if ($bytes>media_library_image_max_bytes()) throw new MediaLibraryException('Images must be 25 MiB or smaller.');
        $size=@getimagesize($path);
        if (!$size || ($size['mime']??'')!==$mime || $size[0]<1 || $size[1]<1 || $size[0]>20000 || $size[1]>20000 || $size[0]*$size[1]>80000000) {
            throw new MediaLibraryException('The image is invalid or its dimensions are too large.');
        }
    } else {
        $handle=fopen($path,'rb');
        if (!$handle) throw new MediaLibraryException('The video cannot be read.');
        try { $head=fread($handle,64); } finally { fclose($handle); }
        if (($mime==='video/webm' && substr((string)$head,0,4)!=="\x1A\x45\xDF\xA3")
            || ($mime!=='video/webm' && !in_array(substr((string)$head,4,4),['ftyp','moov','mdat','wide','free'],true))) {
            throw new MediaLibraryException('The video container does not match its media type.');
        }
    }
    $sha=hash_file('sha256',$path);
    if (!$sha) throw new RuntimeException('Unable to fingerprint the uploaded file.');
    return ['bytes'=>(int)$bytes,'mime'=>$mime,'sha256'=>$sha]+$type;
}
function media_library_key_valid(string $key,string $visibility,string $extension): bool {
    if (!in_array($visibility,['public','private'],true) || !preg_match('/\A(?:jpg|png|webp|mp4|mov|webm)\z/',$extension)) return false;
    $prefix=$visibility==='public'?'assets/uploads/':'media-library/';
    return (bool)preg_match('~\A'.preg_quote($prefix,'~').'[0-9]{4}/(?:0[1-9]|1[0-2])/[a-f0-9]{32}\.'.preg_quote($extension,'~').'\z~',$key);
}
function media_library_local_path(array $asset): string {
    $key=(string)($asset['key']??$asset['storage_key']??'');
    $visibility=(string)($asset['visibility']??'');
    if (!media_library_key_valid($key,$visibility,(string)($asset['extension']??''))) throw new MediaLibraryException('Invalid stored media reference.');
    $root=dirname(__DIR__).($visibility==='private'?'/storage':'');
    $path=$root.'/'.$key;
    // The immutable object must stay within the intended tree, including parent directories.
    $boundary=realpath($root.($visibility==='private'?'/media-library':'/assets/uploads'));
    $real=realpath($path);
    if (!$boundary || !$real || !str_starts_with($real,$boundary.DIRECTORY_SEPARATOR) || is_link($path)) {
        throw new MediaLibraryException('The original media file is unavailable.');
    }
    return $real;
}
function media_library_public_url(string $key): string {
    $base=app_config('media.driver','local')==='s3'
        ?rtrim((string)app_config('media.public_url',''),'/'):rtrim(base_url(),'/');
    $parts=parse_url($base);
    if (!$parts || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))
        throw new MediaLibraryException('Configure a valid media domain first.');
    if (($parts['scheme']??'')!=='https' && app_config('app_env')!=='testing')
        throw new MediaLibraryException('Public media requires an HTTPS domain.');
    return $base.'/'.$key;
}
function media_library_store(array $file, array $input, int $actor): int {
    if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null)
        || !is_uploaded_file($file['tmp_name'])) throw new MediaLibraryException('Choose a file that fits the server upload limit.');
    return media_library_import($file['tmp_name'],(string)($file['name']??''),$input,$actor);
}
/** Internal import entry point; web callers must first pass is_uploaded_file(). */
function media_library_import(string $path,string $originalName,array $input,int $actor): int {
    $meta=media_library_inspect($path,media_library_max_bytes());
    $title=trim((string)($input['title']??''));
    if ($title==='' || mb_strlen($title)>240 || preg_match('/[\x00-\x1f]/u',$title)) throw new MediaLibraryException('Enter a media title of 1–240 characters.');
    $visibility=(string)($input['visibility']??'private');
    if (!in_array($visibility,['public','private'],true)) throw new MediaLibraryException('Choose public or private media.');
    if ($visibility==='public' && empty($input['public_ack'])) throw new MediaLibraryException('Confirm that this media may be publicly accessible.');
    $driver=(string)app_config('media.driver','local');
    if (!in_array($driver,['local','s3'],true)) throw new MediaLibraryException('The media storage driver is not configured.');
    $publicId=bin2hex(random_bytes(16));
    $key=($visibility==='public'?'assets/uploads/':'media-library/').gmdate('Y/m').'/'.$publicId.'.'.$meta['extension'];
    $publicUrl=$visibility==='public'?media_library_public_url($key):'';
    $bucket='';$version='';
    if ($driver==='s3') {
        $bucket=(string)app_config($visibility==='public'?'media.bucket':'media.private_bucket','');
        if ($bucket==='') throw new MediaLibraryException('Configure the selected S3 media bucket first.');
        $result=media_s3_client(110)->putObject([
            'Bucket'=>$bucket,'Key'=>$key,'SourceFile'=>$path,'ContentType'=>$meta['mime'],
            'CacheControl'=>$visibility==='public'?'public,max-age=31536000,immutable':'no-store,private',
            'ServerSideEncryption'=>'AES256','IfNoneMatch'=>'*','Metadata'=>['sha256'=>$meta['sha256']],
        ]);
        $version=(string)($result['VersionId']??'');
        if ($version==='null') $version='';
    } else {
        $dest=dirname(__DIR__).($visibility==='private'?'/storage':'').'/'.$key;
        if (!is_dir(dirname($dest)) && !mkdir(dirname($dest),$visibility==='private'?0700:0750,true)) throw new RuntimeException('Unable to create the media folder.');
        $target=fopen($dest,'xb');$source=fopen($path,'rb');
        if (!$target || !$source) { if (is_resource($target)) fclose($target);if (is_resource($source)) fclose($source);throw new RuntimeException('Unable to save the uploaded file.'); }
        try {
            if (stream_copy_to_stream($source,$target,$meta['bytes']+1)!==$meta['bytes']) throw new RuntimeException('The media file changed during upload.');
        } finally { fclose($source);fclose($target); }
        chmod($dest,$visibility==='private'?0600:0640);
        if (!hash_equals($meta['sha256'],(string)hash_file('sha256',$dest))) { unlink($dest);throw new RuntimeException('Media integrity verification failed.'); }
    }
    $originalName=mb_substr(preg_replace('/[\x00-\x1f\x7f]/u','',basename(str_replace('\\','/',$originalName)))??'upload',0,255);
    $query=db()->prepare('INSERT INTO media_assets(public_id,title,original_name,kind,mime,extension,bytes,sha256,driver,bucket,storage_key,version_id,visibility,public_url,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
    // An upload acknowledgment is not retried after a DB error. A possible orphan has a unique key.
    $query->execute([$publicId,$title,$originalName,$meta['kind'],$meta['mime'],$meta['extension'],$meta['bytes'],$meta['sha256'],$driver,$bucket,$key,$version,$visibility,$publicUrl,$actor]);
    $id=(int)db()->lastInsertId();
    if (function_exists('journeys_audit')) journeys_audit('media',$id,'uploaded',$actor);
    return $id;
}
function media_library_asset(int $id): ?array {
    $q=db()->prepare('SELECT * FROM media_assets WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;
}
function media_library_choices(): array {
    return db()->query("SELECT id,title,kind,mime,bytes,sha256,public_url,visibility FROM media_assets WHERE status='ready' ORDER BY created_at DESC,id DESC LIMIT 200")->fetchAll();
}
function media_library_snapshot(array $asset): array {
    return ['id'=>(int)$asset['id'],'driver'=>(string)$asset['driver'],'bucket'=>(string)$asset['bucket'],
        'key'=>(string)$asset['storage_key'],'version_id'=>(string)$asset['version_id'],'sha256'=>(string)$asset['sha256'],
        'bytes'=>(int)$asset['bytes'],'mime'=>(string)$asset['mime'],'extension'=>(string)$asset['extension'],
        'visibility'=>(string)$asset['visibility'],'public_url'=>(string)$asset['public_url'],'title'=>(string)$asset['title']];
}
function media_library_approved_asset(int $id): array {
    $asset=media_library_asset($id);
    if (!$asset || $asset['status']!=='ready') throw new MediaLibraryException('Choose a currently available asset from the media library.');
    return media_library_snapshot($asset);
}
function media_library_temp_directory(): string {
    $dir=media_library_temp_path();
    if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('Unable to create media staging space.');
    clearstatcache(true,$dir);
    if (is_link($dir) || !is_writable($dir) || fileowner($dir)!==media_library_owner_id()) throw new RuntimeException('Media staging space has an unexpected owner.');
    if(!chmod($dir,0700))throw new RuntimeException('Media staging permissions could not be secured.');
    clearstatcache(true,$dir);
    if((fileperms($dir)&0777)!==0700)throw new RuntimeException('Media staging permissions are not private.');
    return $dir;
}
function media_library_owner_id(): int {
    static $id=null;
    if($id!==null)return $id;
    if(function_exists('posix_geteuid'))return $id=posix_geteuid();
    // fstat on a newly created, exclusive temporary file identifies this process
    // without depending on the owner of the PHP source or an environment variable.
    $file=tmpfile();if(!$file)throw new RuntimeException('Cannot identify the media staging owner.');
    try{$stat=fstat($file);if(!isset($stat['uid']))throw new RuntimeException('Cannot identify the media staging owner.');return $id=(int)$stat['uid'];}
    finally{fclose($file);}
}
function media_library_temp_path(): string {
    return sys_get_temp_dir().'/waithaka-media-stage-'.media_library_owner_id();
}
/** Pin to a DB-owned object and verify a bounded, private copy before provider use. */
function media_library_materialize(array $approvedAsset,int $maxBytes): array {
    $id=$approvedAsset['id']??null;
    if (!is_int($id) || $id<1) throw new MediaLibraryException('Invalid approved media asset.');
    $asset=media_library_asset($id);
    if (!$asset || !in_array($asset['status'],['ready','archived'],true)) throw new MediaLibraryException('The approved media is unavailable.');
    $frozen=media_library_snapshot($asset);
    foreach ($frozen as $field=>$value) {
        if (!array_key_exists($field,$approvedAsset) || $approvedAsset[$field]!==$value) throw new MediaLibraryException('The media reference differs from the approved asset.');
    }
    if (!media_library_key_valid($frozen['key'],$frozen['visibility'],$frozen['extension'])
        || $frozen['bytes']<1 || $frozen['bytes']>min($maxBytes,media_library_max_bytes())) throw new MediaLibraryException('The approved media exceeds the delivery limit.');
    $source=null;$temporary=null;$target=null;$deadline=microtime(true)+240;
    try {
        if ($frozen['driver']==='s3') {
            // Refuse cross-bucket access even if a database row was accidentally moved.
            $expected=(string)app_config($frozen['visibility']==='public'?'media.bucket':'media.private_bucket','');
            if ($expected==='' || !hash_equals($expected,$frozen['bucket'])) throw new MediaLibraryException('The configured bucket does not contain this approved asset.');
            $args=['Bucket'=>$frozen['bucket'],'Key'=>$frozen['key'],'@http'=>['stream'=>true,'read_timeout'=>15]];
            if ($frozen['version_id']!=='') $args['VersionId']=$frozen['version_id'];
            $object=media_s3_client()->getObject($args);
            if ((int)($object['ContentLength']??-1)!==$frozen['bytes']) throw new MediaLibraryException('The stored media size differs from its approval.');
            $source=$object['Body']->detach();
        } elseif ($frozen['driver']==='local') $source=fopen(media_library_local_path($asset),'rb');
        else throw new MediaLibraryException('Unknown approved storage driver.');
        if (!is_resource($source)) throw new RuntimeException('Unable to read the approved media.');
        if($frozen['driver']==='s3')stream_set_timeout($source,15);
        $temporary=tempnam(media_library_temp_directory(),'asset-');
        if (!$temporary) throw new RuntimeException('Unable to stage the approved media.');
        chmod($temporary,0600);$target=fopen($temporary,'wb');$hash=hash_init('sha256');$bytes=0;
        if (!$target) throw new RuntimeException('Unable to write staged media.');
        while (!feof($source)) {
            if(microtime(true)>$deadline)throw new RuntimeException('Media staging exceeded its transfer deadline.');
            $chunk=fread($source,min(1048576,$frozen['bytes']-$bytes+1));
            if(!empty(stream_get_meta_data($source)['timed_out']))throw new RuntimeException('Media staging read timed out.');
            if ($chunk===false || ($chunk==='' && !feof($source))) throw new RuntimeException('Interrupted media transfer.');
            $bytes+=strlen($chunk);
            if ($bytes>$frozen['bytes']) throw new MediaLibraryException('The media file grew after approval.');
            if ($chunk!=='' && fwrite($target,$chunk)!==strlen($chunk)) throw new RuntimeException('Insufficient media staging space.');
            hash_update($hash,$chunk);
        }
        fclose($target);$target=null;
        $sha=hash_final($hash);$actualMime=(new finfo(FILEINFO_MIME_TYPE))->file($temporary);
        if ($bytes!==$frozen['bytes'] || !hash_equals($frozen['sha256'],$sha) || $actualMime!==$frozen['mime']) throw new MediaLibraryException('The media bytes do not match the approved fingerprint.');
        chmod($temporary,0400);
        return ['path'=>$temporary,'bytes'=>$bytes,'mime'=>$actualMime,'sha256'=>$sha,'temporary'=>true];
    } catch (Throwable $e) {
        if (is_resource($target)) fclose($target);
        if ($temporary && is_file($temporary)) unlink($temporary);
        throw $e;
    } finally { if (is_resource($source)) fclose($source); }
}
function media_library_release(array $materialized): void {
    $path=$materialized['path']??null;
    if (($materialized['temporary']??false)!==true || !is_string($path)) return;
    $dir=realpath(media_library_temp_path());$real=realpath($path);
    if ($dir && $real && str_starts_with($real,$dir.DIRECTORY_SEPARATOR) && basename($real)===basename($path)
        && preg_match('/\Aasset-[a-zA-Z0-9]+\z/',basename($real)) && !is_link($path)) unlink($real);
}
function media_library_archive(int $id,int $actor): void {
    $q=db()->prepare("UPDATE media_assets SET status='archived',archived_at=UTC_TIMESTAMP() WHERE id=? AND status='ready'");$q->execute([$id]);
    if ($q->rowCount() && function_exists('journeys_audit')) journeys_audit('media',$id,'archived',$actor);
}
function media_library_bytes(int $bytes): string {
    return $bytes>=1048576?number_format($bytes/1048576,1).' MiB':number_format($bytes/1024,1).' KiB';
}
