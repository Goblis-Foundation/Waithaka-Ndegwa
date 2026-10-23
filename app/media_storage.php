<?php
declare(strict_types=1);
function media_s3_client(int $timeout=45): \Aws\S3\S3Client {
    if (!class_exists(\Aws\S3\S3Client::class)) throw new RuntimeException('Install Composer dependencies before enabling S3.');
    return new \Aws\S3\S3Client(['version'=>'2006-03-01','region'=>app_config('aws_region'),'http'=>['connect_timeout'=>5,'timeout'=>max(5,min(300,$timeout))]]);
}
function media_safe_filename(string $filename): string {
    $name=basename($filename);if(!preg_match('/^[a-zA-Z0-9_-]+\.(?:webp|jpe?g|png|gif|pdf)$/',$name))throw new InvalidArgumentException('Invalid media filename.');return $name;
}
/** Validate stored proof references; preserve the existing object key during an S3 migration. */
function media_private_path(string $path,string $driver='local'): ?string {
    if(!preg_match('~\A(s3-private://|storage/)(release-proofs/[0-9]{4}/(?:0[1-9]|1[0-2])/[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|pdf))\z~',$path,$m))return null;
    if($m[1]==='s3-private://' || $driver==='s3')return 's3-private://'.$m[2];
    return 'storage/'.$m[2];
}
function media_store_upload(string $tempPath,string $filename,string $contentType): string {
    $filename=media_safe_filename($filename);$key='assets/uploads/'.date('Y/m').'/'.$filename;
    if (!is_file($tempPath)) throw new RuntimeException('Upload is missing.');
    if (app_config('media.driver','local')==='s3') {
        $base=rtrim((string)app_config('media.public_url',''),'/');
        if (!str_starts_with($base,'https://') || !app_config('media.bucket')) throw new RuntimeException('Configure the media CDN before publishing uploads.');
        media_s3_client()->putObject(['Bucket'=>app_config('media.bucket'),'Key'=>$key,'SourceFile'=>$tempPath,'ContentType'=>$contentType,'CacheControl'=>'public,max-age=31536000,immutable','ServerSideEncryption'=>'AES256']);
        return $base.'/'.$key;
    }
    $dest=__DIR__.'/../'.$key;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0750,true);
    if(realpath($tempPath)!==realpath($dest)&&!copy($tempPath,$dest))throw new RuntimeException('Unable to save media.');
    chmod($dest,0640);return '/'.$key;
}
function media_store_private_upload(string $tempPath,string $filename,string $contentType): string {
    $filename=media_safe_filename($filename);$key='release-proofs/'.date('Y/m').'/'.$filename;
    if (!is_file($tempPath)) throw new RuntimeException('Upload is missing.');
    if (app_config('media.driver','local')==='s3') {
        if(!app_config('media.private_bucket'))throw new RuntimeException('Private media bucket is not configured.');
        media_s3_client()->putObject(['Bucket'=>app_config('media.private_bucket'),'Key'=>$key,'SourceFile'=>$tempPath,'ContentType'=>$contentType,'CacheControl'=>'no-store,private','ServerSideEncryption'=>'AES256']);
        return 's3-private://'.$key;
    }
    $dest=__DIR__.'/../storage/'.$key;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);
    if(!copy($tempPath,$dest))throw new RuntimeException('Unable to save private media.');chmod($dest,0600);return 'storage/'.$key;
}
function media_serve_private(string $path,string $downloadName): never {
    require_auth();
    $path=media_private_path($path,(string)app_config('media.driver','local'));
    if($path===null||!str_starts_with($path,'s3-private://'))not_found();
    try{$object=media_s3_client()->getObject(['Bucket'=>app_config('media.private_bucket'),'Key'=>substr($path,strlen('s3-private://'))]);}catch(Throwable $e){not_found();}
    header('Cache-Control: no-store, private');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: '.($object['ContentType']?:'application/octet-stream'));
    header('Content-Disposition: attachment; filename="'.preg_replace('/[^a-zA-Z0-9_.-]/','-',basename($downloadName)).'"');header('Content-Length: '.(int)$object['ContentLength']);
    while(!$object['Body']->eof())echo $object['Body']->read(65536);exit;
}
