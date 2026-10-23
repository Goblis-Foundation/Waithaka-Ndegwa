<?php
declare(strict_types=1);
/** End-to-end local HTTP regression. Start tests/010_security_router.php with
 * APP_ENV=testing against the same disposable DB, then run this script with
 * WK_TEST_BASE_URL and WK_TEST_DB_* variables. No external providers are called.
 */
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/integrations.php';
$database=getenv('WK_TEST_DB_NAME')?:'';$base=rtrim(getenv('WK_TEST_BASE_URL')?:'http://127.0.0.1:8092','/');
if(!preg_match('/^[a-zA-Z0-9_]+_test$/D',$database)||parse_url($base,PHP_URL_HOST)!=='127.0.0.1'){fwrite(STDERR,"Use a local 127.0.0.1 server and database ending _test.\n");exit(2);}
$CONFIG=['app_key'=>'local-test-key-not-for-production-use','base_url'=>$base,'db'=>[
    'host'=>getenv('WK_TEST_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('WK_TEST_DB_PORT')?:3307),'name'=>$database,
    'user'=>getenv('WK_TEST_DB_USER')?:'wk_test','pass'=>getenv('WK_TEST_DB_PASS')?:'','charset'=>'utf8mb4'
]];
$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
$checks=0;$userId=0;$postId=0;$releaseId=0;$email='http-security-'.bin2hex(random_bytes(6)).'@example.test';$password=bin2hex(random_bytes(16));
$cookie=tempnam(sys_get_temp_dir(),'wk-test-cookie-');
function http_check(bool $condition,string $message):void{global $checks;$checks++;if(!$condition)throw new RuntimeException($message);}
function request_local(string $path,?array $post=null,bool $cookies=true):array{
    global $base,$cookie;
    $ch=curl_init($base.$path);$headers=[];
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>12,CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);}]);
    if($cookies)curl_setopt_array($ch,[CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);
    if($post!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);curl_close($ch);
    if($body===false)throw new RuntimeException('Local HTTP request failed: '.$error);
    return ['status'=>$status,'headers'=>$headers,'body'=>$body];
}
function response_csrf(array $response):string{if(!preg_match('/name="csrf" value="([a-f0-9]{64})"/',$response['body'],$m))throw new RuntimeException('CSRF field was not rendered.');return $m[1];}
try{
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['HTTP security test',$email,password_hash($password,PASSWORD_DEFAULT)]);$userId=(int)db()->lastInsertId();
    $category=(int)db()->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    $input=['id'=>0,'title'=>'HTTP original '.bin2hex(random_bytes(4)),'content_html'=>'<p>PUBLIC_SNAPSHOT_MARKER</p>','category_id'=>$category,'published_at'=>date('Y-m-d\TH:i',time()-300)];
    $saved=writer_save_post($input,$userId,true);$postId=$saved['id'];$slug=writer_post_by_id($postId)['slug'];
    $input['id']=$postId;$input['draft_version']=$saved['version'];$input['title']='Private HTTP title';$input['content_html']='<p>PRIVATE_PREVIEW_MARKER</p>';$input['writer_note']='PRIVATE_PLAN_DO_NOT_RENDER';
    $saved=writer_save_post($input,$userId,false);
    $token=bin2hex(random_bytes(32));db()->prepare("INSERT INTO media_releases(reference,public_token,title,status,created_by) VALUES(?,?,?,'closed',?)")->execute(['HTTP-'.bin2hex(random_bytes(8)),$token,'Closed fixture',$userId]);$releaseId=(int)db()->lastInsertId();

    $r=request_local('/write/preview/'.$postId,null,false);http_check($r['status']===302&&str_ends_with($r['headers']['location']??'','/write/login'),'Unauthenticated preview must redirect to login.');
    http_check(!str_contains($r['body'],'PRIVATE_PREVIEW_MARKER'),'Anonymous preview leaked private content.');
    $r=request_local('/write/login',['email'=>$email,'password'=>$password],false);http_check($r['status']===419,'Missing CSRF token must fail before login.');
    $r=request_local('/write/login');$csrf=response_csrf($r);http_check($r['status']===200,'Login page must render.');
    $r=request_local('/write/login',['csrf'=>$csrf,'email'=>$email,'password'=>$password]);http_check($r['status']===302,'Valid fixture login failed.');
    $r=request_local('/write/preview/'.$postId);http_check($r['status']===200&&str_contains($r['body'],'PRIVATE_PREVIEW_MARKER'),'Authenticated preview must show private saved draft.');
    http_check(str_contains($r['headers']['cache-control']??'','no-store')&&str_contains($r['headers']['x-robots-tag']??'','noindex'),'Preview must be private, no-store and noindex.');
    http_check(!str_contains($r['body'],'PRIVATE_PLAN_DO_NOT_RENDER')&&!str_contains($r['body'],'googletagmanager.com')&&!str_contains($r['body'],'application/ld+json'),'Preview must exclude private planning notes, analytics and public structured data.');
    $r=request_local('/my-thoughts/'.$slug,null,false);http_check($r['status']===200&&str_contains($r['body'],'PUBLIC_SNAPSHOT_MARKER')&&!str_contains($r['body'],'PRIVATE_PREVIEW_MARKER'),'Public article must keep its approved snapshot.');
    $r=request_local('/write/edit/'.$postId);$csrf=response_csrf($r);http_check($r['status']===200&&str_contains($r['body'],'/write/preview/'.$postId),'Editor must link to the authenticated preview.');
    http_check((bool)preg_match('~assets/js/write\.js\?v=[0-9]+~',$r['body']),'Writer script must be cache-versioned.');
    $input['csrf']=$csrf;$input['draft_version']=$saved['version'];$input['autosave']='1';$input['action']='publish';$input['content_html']='<p>AUTOSAVED_PRIVATE_MARKER</p>';
    $r=request_local('/write/save',$input);$json=json_decode($r['body'],true);http_check($r['status']===200&&!empty($json['ok']),'Autosave should succeed even with stale publish button data.');
    $r=request_local('/my-thoughts/'.$slug,null,false);http_check(str_contains($r['body'],'PUBLIC_SNAPSHOT_MARKER')&&!str_contains($r['body'],'AUTOSAVED_PRIVATE_MARKER'),'Autosave action=publish must not publish.');
    $input['draft_version']=$json['version'];unset($input['autosave']);$r=request_local('/write/save',$input);http_check($r['status']===302,'Explicit publish should succeed.');
    $r=request_local('/my-thoughts/'.$slug,null,false);http_check($r['status']===200&&str_contains($r['body'],'AUTOSAVED_PRIVATE_MARKER'),'Explicit publish must use the original public URL.');
    $input['autosave']='1';$input['content_html']='<p>STALE_SHOULD_NOT_EXIST</p>';$r=request_local('/write/save',$input);$json=json_decode($r['body'],true);http_check($r['status']===409&&!empty($json['conflict']),'Late pre-publish autosave must be rejected as a conflict.');
    $r=request_local('/write/preview/'.$postId);http_check(!str_contains($r['body'],'STALE_SHOULD_NOT_EXIST'),'Late autosave must not recreate or overwrite a private draft.');
    $r=request_local('/consent/'.$token,['csrf'=>$csrf,'affirm'=>1,'signer_name'=>'Fixture','signature_text'=>'Fixture','signer_capacity'=>'self','signer_attribution'=>'anonymous','consent_photo'=>1]);http_check($r['status']===410,'Closed consent POST must be rejected.');
    $q=db()->prepare('SELECT status,signed_at FROM media_releases WHERE id=?');$q->execute([$releaseId]);$release=$q->fetch();http_check($release['status']==='closed'&&$release['signed_at']===null,'Closed consent record was mutated.');
    $r=request_local('/sitemap.xml',null,false);$xml=new DOMDocument();http_check($r['status']===200&&$xml->loadXML($r['body'],LIBXML_NONET),'Sitemap endpoint must produce well-formed XML.');
    http_check(!str_contains($r['body'],'/write/')&&!str_contains($r['body'],'/consent/'),'Sitemap must exclude private routes.');
    foreach(['/startup-journeys','/founders','/founders/submit','/newsletter'] as $path){$r=request_local($path,null,false);http_check($r['status']===200,'Public shared-layout route failed: '.$path.' ('.$r['status'].')');}
    foreach(['/write','/write/journeys','/write/journeys/founders','/write/publishing','/write/connections'] as $path){$r=request_local($path);http_check($r['status']===200,'Authenticated shared-layout route failed: '.$path.' ('.$r['status'].')');}
    foreach(['/app/config.php','/storage/installed.lock','/tests/010_security_http.php','/.git/config'] as $path){$r=request_local($path,null,false);http_check($r['status']===404,'Test router exposed a private source path: '.$path);}
    $r=request_local('/write/logout');http_check($r['status']===200,'GET logout should render confirmation only.');
    $r=request_local('/write/preview/'.$postId);http_check($r['status']===200,'GET logout must not destroy the authenticated session.');
    $r=request_local('/write/logout',['csrf'=>$csrf]);http_check($r['status']===302,'CSRF-protected logout should succeed.');
    $r=request_local('/write/preview/'.$postId);http_check($r['status']===302,'Private preview must require login after logout.');
    echo "PASS: {$checks} HTTP security and shared-layout assertions\n";
}finally{
    if($releaseId)db()->prepare('DELETE FROM media_releases WHERE id=?')->execute([$releaseId]);
    if($postId)db()->prepare('DELETE FROM posts WHERE id=?')->execute([$postId]);
    db()->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$email]);
    if($userId)db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    if($cookie&&is_file($cookie))unlink($cookie);
}
