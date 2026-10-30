<?php
declare(strict_types=1);
/** One reviewed owned attachment. No arbitrary media URL download or audience inference. */
function publishing_discord_media_validate(array $v): void {
    $a=$v['media_asset']??[];$video=($v['media_type']??'')==='video';
    if(!is_array($a)||(int)($a['id']??0)<1||(int)($v['media_asset_id']??0)!==(int)$a['id']||!in_array($v['media_type']??'',['image','video'],true)||!in_array($a['mime']??'',$video?['video/mp4']:['image/jpeg','image/png'],true)||($a['visibility']??'')!=='public'||($a['public_url']??'')!==($v['media_url']??'')||!publishing_public_url((string)($v['media_url']??''))||!is_int($a['bytes']??null)||$a['bytes']<1||$a['bytes']>10000000||!preg_match('/^[a-f0-9]{64}$/D',(string)($a['sha256']??'')))throw new RuntimeException('Choose one owned public JPEG, PNG or MP4 up to this workspace’s 10 MB Discord limit. Private files and external media URLs stay assisted.');
    if(!is_string($v['alt_text']??'')||!mb_check_encoding($v['alt_text']??'','UTF-8')||mb_strlen($v['alt_text']??'')>500)throw new RuntimeException('Use a Discord attachment description of up to 500 characters.');
}
function publishing_discord_filename(array $a): string {
    $ext=match($a['mime']??''){'image/jpeg'=>'jpg','image/png'=>'png','video/mp4'=>'mp4',default=>throw new RuntimeException('Unsupported Discord attachment type.')};
    if(!preg_match('/^[a-f0-9]{64}$/D',(string)($a['sha256']??'')))throw new RuntimeException('Missing approved Discord media fingerprint.');return 'wn-'.$a['sha256'].'.'.$ext;
}
/** Only the verified staged copy can enter multipart; never serialize server paths into payload_json. */
function publishing_discord_multipart(array $body): array {
    $file=$body['_file']??[];$path=$file['path']??'';$a=$file['asset']??[];
    if(!is_string($path)||!is_file($path)||is_link($path)||!is_readable($path)||!is_int($a['bytes']??null)||$a['bytes']<1||$a['bytes']>10000000||filesize($path)!==$a['bytes']||!hash_equals((string)$a['sha256'],(string)hash_file('sha256',$path)))throw new RuntimeException('The staged Discord file changed. No upload was sent.');
    $name=publishing_discord_filename($a);unset($body['_file']);
    if(($body['allowed_mentions']??null)!==['parse'=>[]]||($body['flags']??null)!==4||($body['attachments'][0]['filename']??'')!==$name||count($body['attachments']??[])!==1)throw new RuntimeException('Review the Discord attachment and mention controls.');
    return ['payload_json'=>publishing_json($body),'files[0]'=>new CURLFile($path,$a['mime'],$name)];
}
function publishing_discord_media_send(array $v,array $creds,callable $http,array &$job,array $state): array {
    publishing_discord_media_validate($v);$a=$v['media_asset'];$opened=null;
    try{
        try{$opened=media_library_materialize($a,10000000);}catch(Throwable){throw new PublishingRemoteException('The approved Discord media could not be staged. No upload was attempted.',422);}
        if(($opened['bytes']??null)!==$a['bytes']||($opened['mime']??'')!==$a['mime']||($opened['sha256']??'')!==$a['sha256'])throw new PublishingRemoteException('The staged Discord attachment differs from its approval.',422);
        $attachment=['id'=>0,'filename'=>publishing_discord_filename($a)];if(($v['alt_text']??'')!=='')$attachment['description']=$v['alt_text'];
        $body=['content'=>$v['text'],'allowed_mentions'=>['parse'=>[]],'flags'=>4,'attachments'=>[$attachment],'_file'=>['path'=>$opened['path'],'asset'=>$a]];
        try{publishing_discord_multipart($body);}catch(Throwable){throw new PublishingRemoteException('The staged Discord file failed its final preflight. No upload was attempted.',422);}
        $snapshot=publishing_decode($job['snapshot_json']);if(!empty($snapshot['source_post_id'])){$q=db()->prepare('SELECT p.id FROM posts p WHERE p.id=? AND '.published_where());$q->execute([$snapshot['source_post_id']]);if(!$q->fetchColumn())throw new PublishingRemoteException('The linked article is no longer public. No Discord upload was attempted.',422);}
        publishing_community_checkpoint($job,$state);
        return $http('discord','POST','?wait=true',$creds,$body);
    }finally{if(is_array($opened))media_library_release($opened);}
}
/** Validate a freshly returned signed URL against the already verified channel and attachment. */
function publishing_discord_attachment_url(string $url,string $channel,string $id,string $filename): bool {
    if(strlen($url)>2048||preg_match('/[\x00-\x20\x7f]/',$url)||!preg_match('/^[0-9]{1,20}$/D',$channel)||!preg_match('/^[0-9]{1,20}$/D',$id))return false;
    $p=parse_url($url);if(!$p||($p['scheme']??'')!=='https'||($p['host']??'')!=='cdn.discordapp.com'||isset($p['port'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||($p['path']??'')!=='/attachments/'.$channel.'/'.$id.'/'.$filename)return false;
    parse_str($p['query']??'',$q);foreach($q as$key=>$value)if(!in_array($key,['ex','is','hm'],true)||!is_string($value)||!preg_match('/^[a-fA-F0-9]{1,128}$/D',$value))return false;return true;
}
/** Credential-free, bounded byte read; never retain a signed CDN capability or raw attachment. */
function publishing_discord_attachment_bytes(string $url,array $a): bool {
    $name=publishing_discord_filename($a);$p=parse_url($url);$parts=explode('/',trim((string)($p['path']??''),'/'));
    if(count($parts)!==4||!publishing_discord_attachment_url($url,$parts[1],$parts[2],$name)||!is_int($a['bytes']??null)||$a['bytes']<1||$a['bytes']>10000000)return false;
    $hash=hash_init('sha256');$bytes=0;$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$bytes,$a,$hash):int{$bytes+=strlen($chunk);if($bytes>$a['bytes'])return 0;hash_update($hash,$chunk);return strlen($chunk);}]);
    $ok=curl_exec($ch)!==false;$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return $ok&&$code===200&&$bytes===$a['bytes']&&hash_equals($a['sha256'],hash_final($hash));
}
function publishing_discord_attachment_matches(array $response,array $v,string $channel,?callable $read=null): bool {
    $attachments=$response['attachments']??null;if(!is_array($attachments)||!array_is_list($attachments)||count($attachments)!==1)return false;$r=$attachments[0];$a=$v['media_asset'];$name=publishing_discord_filename($a);$id=$r['id']??'';$url=$r['url']??'';
    if(!is_string($id)||!is_string($url)||($r['filename']??'')!==$name||($r['size']??null)!==$a['bytes']||($r['content_type']??'')!==$a['mime']||($r['description']??'')!==($v['alt_text']??'')||!empty($r['ephemeral'])||!publishing_discord_attachment_url($url,$channel,$id,$name))return false;
    return ($read??'publishing_discord_attachment_bytes')($url,$a)===true;
}
