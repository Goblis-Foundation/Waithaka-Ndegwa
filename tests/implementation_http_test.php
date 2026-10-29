<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',(string)getenv('DB_DATABASE'))){fwrite(STDERR,"Use the isolated application HTTP test runner.\n");exit(2);}
$base=rtrim((string)getenv('TEST_BASE_URL'),'/');$parts=parse_url($base);
if(($parts['scheme']??'')!=='http'||($parts['host']??'')!=='127.0.0.1'||isset($parts['query'])||isset($parts['fragment'])||isset($parts['user'])||isset($parts['pass']))exit(2);
require dirname(__DIR__).'/app/bootstrap.php';
$checks=0;$prefix='full-http-'.bin2hex(random_bytes(6));$cookie=tempnam(sys_get_temp_dir(),'wk-impl-cookie-');$source=tempnam(sys_get_temp_dir(),'wk-impl-image-');$assetId=null;$path=null;
function impl_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
function impl_request(string $path,?array $post=null,bool $auth=true,?string $range=null):array{
    global$base,$cookie;$headers=[];$curl=curl_init($base.$path);
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,
        CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);}]);
    if($auth)curl_setopt_array($curl,[CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);
    if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>array_filter($post,fn($v)=>$v instanceof CURLFile)?$post:http_build_query($post)]);
    if($range!==null)curl_setopt($curl,CURLOPT_HTTPHEADER,['Range: '.$range]);
    $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
    if($body===false)throw new RuntimeException('The local HTTP fixture did not respond.');
    return compact('status','headers','body');
}
function impl_csrf(array $response):string{
    if(!preg_match('/name="csrf"\s+value="([a-f0-9]{64})"/',$response['body'],$match))throw new RuntimeException('Expected a real Writer CSRF field.');
    return $match[1];
}
$password=bin2hex(random_bytes(20));$email=$prefix.'@example.invalid';
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Full implementation fixture',$email,password_hash($password,PASSWORD_DEFAULT)]);$user=(int)db()->lastInsertId();
try{
    foreach(['/write/media','/write/operations','/write/mail-feedback','/write/newsletter']as$route){
        $response=impl_request($route,null,false);impl_check($response['status']===302&&($response['headers']['location']??'')===$base.'/write/login','New Writer tools must require authentication: '.$route);
    }
    $login=impl_request('/write/login');$csrf=impl_csrf($login);
    $response=impl_request('/write/login',['csrf'=>$csrf,'email'=>$email,'password'=>$password]);
    impl_check($response['status']===302,'The synthetic Writer must authenticate through the real form.');
    $response=impl_request('/write/media');$csrf=impl_csrf($response);
    impl_check($response['status']===200&&str_contains($response['body'],'Upload an asset'),'Media library must render the upload workflow.');
// The synthetic Writer may edit its own drafts, but not the owner's protected source
// endpoints or sitewide Google billing/API preferences.
$forbiddenImpact=impl_request('/write/impact');
impl_check($forbiddenImpact['status']===403&&!str_contains($forbiddenImpact['body'],'Approved reporting URL'),'Other Writers cannot read or edit source reporting tokens and URLs.');
$forbiddenSync=impl_request('/write/impact',['csrf'=>$csrf,'action'=>'sync']);
impl_check($forbiddenSync['status']===403,'Other Writers cannot initiate the source feed check.');
$forbiddenGoogle=impl_request('/write/google',['csrf'=>$csrf,'action'=>'settings']);
impl_check($forbiddenGoogle['status']===403,'Other Writers cannot rewrite sitewide Google credentials or defaults.');
    impl_check(str_contains($response['headers']['cache-control']??'','no-store')&&str_contains($response['headers']['x-robots-tag']??'','noindex'),'Private media tools must be uncacheable and non-indexable.');
    $image=imagecreatetruecolor(2,2);imagepng($image,$source);imagedestroy($image);$original=(string)file_get_contents($source);
    $form=['csrf'=>'invalid','title'=>'Private HTTP image','visibility'=>'private','media'=>new CURLFile($source,'image/png','unsafe-original.php')];
    $response=impl_request('/write/media',$form);impl_check($response['status']===419,'A multipart media upload must reject invalid CSRF before storing bytes.');
    $form['csrf']=$csrf;$response=impl_request('/write/media',$form);
    impl_check($response['status']===302&&preg_match('~/write/media/([1-9][0-9]*)$~',$response['headers']['location']??'',$match)===1,'A real multipart upload must create an owned asset.');
    $assetId=(int)$match[1];$asset=media_library_asset($assetId);$path=media_library_local_path($asset);
    impl_check($asset['visibility']==='private'&&$asset['public_url']===''&&$asset['mime']==='image/png','HTTP upload must use actual image content and default private access.');
    $response=impl_request('/write/media/'.$assetId);
    impl_check($response['status']===200&&str_contains($response['body'],'Private HTTP image'),'The uploaded asset’s review page must render.');
    $response=impl_request('/write/media/'.$assetId.'/preview');
    impl_check($response['status']===200&&$response['body']===$original&&str_contains($response['headers']['content-type']??'','image/png'),'Authenticated preview must return the exact asset.');
    $response=impl_request('/write/media/'.$assetId.'/preview',null,true,'bytes=1-9');
    impl_check($response['status']===206&&$response['body']===substr($original,1,9)&&str_starts_with($response['headers']['content-range']??'','bytes 1-9/'),'Media range requests must use correct bounded byte offsets.');
    $response=impl_request('/write/media/'.$assetId.'/preview',null,true,'bytes=999999-');
    impl_check($response['status']===416,'Out-of-bounds media ranges must be refused.');
    $response=impl_request('/write/media/'.$assetId.'/download',null,false);
    impl_check($response['status']===302,'A private media download must never bypass Writer authentication.');
    $response=impl_request('/storage/'.$asset['storage_key'],null,false);
    impl_check(in_array($response['status'],[403,404],true),'The direct private filesystem path must remain blocked.');
    $response=impl_request('/write/operations');
    impl_check($response['status']===200&&str_contains($response['body'],'Operations'),'Operations must render local health without contacting providers.');
    impl_check(str_contains($response['headers']['cache-control']??'','no-store')&&str_contains($response['headers']['x-robots-tag']??'','noindex'),'Operations diagnostics must remain private and uncacheable.');
    impl_check(!str_contains($response['body'],getenv('APP_KEY'))&&!str_contains($response['body'],$email),'Operational diagnostics must not expose secrets or private recipient addresses.');
    $response=impl_request('/write/operations',['csrf'=>$csrf,'action'=>'run']);
    impl_check($response['status']===405,'The read-only Operations page must reject a mutation even from an authenticated Writer.');
    $response=impl_request('/write/mail-feedback');
    impl_check($response['status']===200&&str_contains($response['body'],'Mail delivery feedback'),'Mail feedback setup and events must be reviewable in Writer.');
    $response=impl_request('/webhooks/ses',null,false);
    impl_check($response['status']===405&&!isset($response['headers']['set-cookie']),'The SNS endpoint must reject GET without creating a browser session.');
    $response=impl_request('/webhooks/ses',['invalid'=>'fixture'],false);
    impl_check($response['status']===503&&!isset($response['headers']['set-cookie']),'An unconfigured SNS topic must fail closed without cookies.');
    $response=impl_request('/write/media/'.$assetId);$csrf=impl_csrf($response);
    $response=impl_request('/write/media/'.$assetId,['csrf'=>$csrf,'action'=>'archive']);
    impl_check($response['status']===302&&media_library_asset($assetId)['status']==='archived','Archiving must persist through the actual CSRF-protected form.');
    echo "Full implementation HTTP checks passed: $checks\n";
}finally{
    if($path&&(is_file($path)||is_link($path)))unlink($path);
    if($assetId)db()->prepare('DELETE FROM media_assets WHERE id=?')->execute([$assetId]);
    db()->prepare('DELETE FROM users WHERE id=?')->execute([$user]);
    foreach([$cookie,$source]as$file)if(is_file($file))unlink($file);
}
