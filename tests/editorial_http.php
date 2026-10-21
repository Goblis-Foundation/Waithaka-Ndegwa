<?php
declare(strict_types=1);
/** Real local HTTP forms, publication/privacy gates and download/preview responses. */
$base=rtrim((string)(getenv('TEST_BASE_URL')?:getenv('WK_TEST_BASE_URL')),'/');$parts=parse_url($base);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'||!in_array(getenv('DB_HOST'),['127.0.0.1','::1'],true)||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',(string)getenv('DB_DATABASE'))||($parts['scheme']??'')!=='http'||($parts['host']??'')!=='127.0.0.1'||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($parts['path']??'',['','/'],true)){fwrite(STDERR,"Use a freshly migrated isolated test database and numeric loopback HTTP application.\n");exit(2);}
require dirname(__DIR__).'/app/bootstrap.php';restore_exception_handler();
if((string)db()->query('SELECT DATABASE()')->fetchColumn()!==getenv('DB_DATABASE'))throw new RuntimeException('Unexpected fixture database.');
$checks=0;$actor=0;$postId=0;$owned=[];$sessions=[];$cookie=tempnam(sys_get_temp_dir(),'ed-http-cookie-');$prefix='ed-http-'.bin2hex(random_bytes(6));$email=$prefix.'@example.invalid';$password=bin2hex(random_bytes(16));
function eh_check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function eh_request(string $path,?array $post=null,bool $cookies=true,string $method='GET'): array {
    $ch=curl_init($GLOBALS['base'].$path);$headers=[];curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>15,CURLOPT_PROXY=>'',CURLOPT_HEADERFUNCTION=>static function($c,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);}]);
    if($cookies)curl_setopt_array($ch,[CURLOPT_COOKIEFILE=>$GLOBALS['cookie'],CURLOPT_COOKIEJAR=>$GLOBALS['cookie']]);
    if($post!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);elseif($method!=='GET')curl_setopt($ch,CURLOPT_CUSTOMREQUEST,$method);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_error($ch);foreach(curl_getinfo($ch,CURLINFO_COOKIELIST)?:[] as $line){$p=explode("\t",$line);if(count($p)===7&&$p[5]==='PHPSESSID')$GLOBALS['sessions'][$p[6]]=true;}curl_close($ch);
    if($body===false)throw new RuntimeException('Loopback request failed: '.$error);return ['status'=>$status,'headers'=>$headers,'body'=>$body];
}
function eh_hidden(array $response): array {
    $old=libxml_use_internal_errors(true);$document=new DOMDocument();try{$document->loadHTML($response['body'],LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}$xpath=new DOMXPath($document);$values=[];foreach($xpath->query('//form[translate(@method,"POST","post")="post"]//input[@type="hidden"]') as $input)$values[$input->getAttribute('name')]=$input->getAttribute('value');return $values;
}
function eh_redirect(array $response,string $path): bool {return $response['status']===302&&($response['headers']['location']??'')===$GLOBALS['base'].$path;}
try{
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Synthetic editorial HTTP writer',$email,password_hash($password,PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
    eh_check(eh_redirect(eh_request('/write/editorial',null,false),'/write/login'),'The editorial centre requires Writer authentication');
    $r=eh_request('/start-here',null,false);eh_check($r['status']===200&&str_contains($r['body'],'One startup at a time.'),'Start Here offers actual public learning paths');
    $r=eh_request('/resources',null,false);eh_check($r['status']===200&&str_contains($r['body'],'Problem interview worksheet'),'The public collection includes the original blank tools');
    $r=eh_request('/resources/problem-interview-worksheet/download',null,false);eh_check($r['status']===200&&str_starts_with($r['headers']['content-type']??'','text/plain')&&str_contains($r['headers']['content-disposition']??'','attachment;'),'Blank resources offer a real plain-text attachment');
    eh_check(str_contains($r['body'],'Who experiences the problem?')&&($r['headers']['x-robots-tag']??'')==='noindex','The download is useful and directs indexing to the main resource page');
    $r=eh_request('/watch',null,false);eh_check($r['status']===200&&!str_contains($r['body'],'"@type": "VideoObject"'),'An empty video collection does not invent a VideoObject');
    $login=eh_hidden(eh_request('/write/login'));eh_check(isset($login['csrf']),'The real login form supplies a CSRF token');
    eh_check(eh_redirect(eh_request('/write/login',$login+['email'=>$email,'password'=>$password]),'/write'),'The temporary Writer logs in through the actual HTTP route');
    $r=eh_request('/write/editorial/items/new?type=resource');$fields=eh_hidden($r);eh_check($r['status']===200&&isset($fields['row_version']),'The real resource form exposes its review version');
    $input=['type'=>'resource','title'=>'HTTP approved resource','slug'=>$prefix.'-resource','summary'=>'Synthetic HTTP fixture only.','content_html'=>'<h2>Question</h2><p>PUBLIC_RESOURCE_CANARY with a useful prompt for examining the next piece of evidence.</p>','published_at'=>date('Y-m-d\TH:i',time()-3600),'action'=>'draft'];
    eh_check(eh_request('/write/editorial/items/new',$input)['status']===419,'An authenticated mutation still requires CSRF');
    $r=eh_request('/write/editorial/items/new',$fields+$input);eh_check($r['status']===302&&preg_match('~/write/editorial/items/([1-9][0-9]*)$~',$r['headers']['location']??'',$m)===1,'A CSRF-protected draft save creates the item');$id=(int)$m[1];$owned[]=$id;$path='/write/editorial/items/'.$id;
    eh_check(eh_request('/resources/'.$input['slug'],null,false)['status']===404&&eh_request('/resources/'.$input['slug'].'/download',null,false)['status']===404,'Neither page nor download reveals a private draft');
    eh_check(eh_redirect(eh_request($path.'/preview',null,false),'/write/login'),'Private editorial previews require authentication');
    $r=eh_request($path.'/preview');eh_check($r['status']===200&&str_contains($r['body'],'PUBLIC_RESOURCE_CANARY'),'The authenticated saved preview shows the working copy');
    eh_check(str_contains($r['headers']['cache-control']??'','no-store')&&str_contains($r['headers']['x-robots-tag']??'','noindex'),'The saved preview is no-store and noindex');
    eh_check(!str_contains($r['body'],'googletagmanager.com')&&!str_contains($r['body'],'application/ld+json'),'A private preview has neither analytics loader nor public schema');
    $fields=eh_hidden(eh_request($path));$publish=array_merge($fields,$input,['action'=>'publish']);
    eh_check(eh_request($path,$publish)['status']===422,'Publication requires the separate review acknowledgment');
    $publish['publish_ack']='1';eh_check(eh_redirect(eh_request($path,$publish),$path),'Explicit approved publication succeeds');
    $r=eh_request('/resources/'.$input['slug'],null,false);eh_check($r['status']===200&&str_contains($r['body'],'PUBLIC_RESOURCE_CANARY')&&str_contains($r['body'],'data-analytics-event="resource_download"'),'The released resource has real content and the consent-gated download action hook');
    $r=eh_request('/resources/'.$input['slug'].'/download',null,false);eh_check($r['status']===200&&str_contains($r['body'],'PUBLIC_RESOURCE_CANARY')&&!str_contains($r['body'],'<h2>'),'The authored resource download uses its released plain text');
    $fields=eh_hidden(eh_request($path));$draft=array_merge($fields,$input,['action'=>'draft','content_html'=>'<p>PRIVATE_RESOURCE_CANARY with enough text for an actual saved working copy.</p>']);
    eh_check(eh_redirect(eh_request($path,$draft),$path),'A later draft save succeeds');
    eh_check(eh_request($path,$publish)['status']===409,'A stale publication form cannot replace a newer draft');
    foreach(['/resources/'.$input['slug'],'/resources/'.$input['slug'].'/download'] as $publicPath){$r=eh_request($publicPath,null,false);eh_check(str_contains($r['body'],'PUBLIC_RESOURCE_CANARY')&&!str_contains($r['body'],'PRIVATE_RESOURCE_CANARY'),'Public pages and downloads keep the previous approved version');}
    $r=eh_request($path.'/preview');eh_check(str_contains($r['body'],'PRIVATE_RESOURCE_CANARY'),'The new working copy is available only in the authenticated preview');
    $r=eh_request('/resources/'.$input['slug'],[],false);eh_check($r['status']===405&&str_contains($r['headers']['allow']??'','GET'),'Public resource paths do not accept mutation methods');
    $fields=eh_hidden(eh_request($path));eh_check(eh_redirect(eh_request($path,['csrf'=>$fields['csrf'],'row_version'=>$fields['row_version'],'action'=>'archive']),$path),'Archiving works through its protected action');
    eh_check(eh_request('/resources/'.$input['slug'],null,false)['status']===404&&eh_request('/resources/'.$input['slug'].'/download',null,false)['status']===404,'Archiving removes both page and downloadable attachment');

    $fields=eh_hidden(eh_request('/write/editorial/items/new?type=resource'));$future=array_merge($input,['slug'=>$prefix.'-future','title'=>'FUTURE_RESOURCE_CANARY','published_at'=>date('Y-m-d\TH:i',time()+86400),'action'=>'publish','publish_ack'=>'1']);$r=eh_request('/write/editorial/items/new',$fields+$future);
    eh_check($r['status']===302&&preg_match('~/write/editorial/items/([1-9][0-9]*)$~',$r['headers']['location']??'',$m)===1,'A reviewed future publication can be scheduled');$owned[]=(int)$m[1];
    eh_check(eh_request('/resources/'.$future['slug'],null,false)['status']===404,'A future resource cannot be opened early');
    $r=eh_request('/sitemap.xml',null,false);eh_check($r['status']===200&&!str_contains($r['body'],$future['slug'])&&!str_contains($r['body'],$input['slug']),'Sitemap excludes scheduled and archived editorial items');

    db()->prepare('INSERT INTO posts(user_id,title,slug,standfirst,content_html,writer_note,source_notes,status,published_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$actor,'Synthetic HTTP article',$prefix.'-article','Synthetic article test only.','<h2>First question</h2><p>Body.</p><h2>Second question</h2><p>More body.</p>','PRIVATE_ARTICLE_PLAN','PRIVATE_ARTICLE_SOURCES','published',date('Y-m-d H:i:s',time()-86400)]);$postId=(int)db()->lastInsertId();
    $articlePath='/write/editorial/posts/'.$postId;$fields=eh_hidden(eh_request($articlePath));
    $card=['label'=>'PUBLIC_EVIDENCE_CANARY','basis'=>'records','summary'=>'Synthetic evidence for an isolated HTTP test.','limitations'=>'Not a real founder result.'];$notes=['evidence'=>[$card],'update_kind'=>'correction','update_note'=>'PUBLIC_CORRECTION_CANARY: the sample description has been clarified.','action'=>'draft'];
    eh_check(eh_redirect(eh_request($articlePath,$fields+$notes),$articlePath),'Article evidence has a protected private draft route');
    $r=eh_request('/my-thoughts/'.$prefix.'-article',null,false);eh_check($r['status']===200&&!str_contains($r['body'],'PUBLIC_EVIDENCE_CANARY')&&!str_contains($r['body'],'PUBLIC_CORRECTION_CANARY'),'Saving evidence does not release a card or correction note');
    eh_check(str_contains($r['body'],'On this page')&&str_contains($r['body'],'href="#article-section-first-question"')&&str_contains($r['body'],'id="article-section-first-question"'),'Article table of contents links to real generated heading anchors');
    eh_check(str_contains($r['body'],'Receive the next chapter.')&&str_contains($r['body'],'/newsletter'),'Published articles end with a working newsletter path');
    $fields=eh_hidden(eh_request($articlePath));eh_check(eh_redirect(eh_request($articlePath,array_merge($fields,$notes,['action'=>'publish','publish_ack'=>'1'])),$articlePath),'An explicit evidence review releases cards and the meaningful correction note');
    $r=eh_request('/my-thoughts/'.$prefix.'-article',null,false);eh_check(str_contains($r['body'],'PUBLIC_EVIDENCE_CANARY')&&str_contains($r['body'],'PUBLIC_CORRECTION_CANARY')&&str_contains($r['body'],'Updates and corrections.'),'Released evidence and correction history are visible in the actual article');
    eh_check(!str_contains($r['body'],'PRIVATE_ARTICLE_PLAN')&&!str_contains($r['body'],'PRIVATE_ARTICLE_SOURCES'),'Public rendering does not expose private article planning fields');
    $r=eh_request('/write/edit/'.$postId);eh_check($r['status']===200&&str_contains($r['body'],$articlePath),'The existing article editor links to the separate public-evidence editor');
    eh_request('/write/logout',['csrf'=>$fields['csrf']]);
    echo 'Editorial HTTP checks passed: '.$checks."\n";
}finally{
    if(db()->inTransaction())db()->rollBack();
    if($owned){$q=db()->prepare('DELETE FROM editorial_items WHERE id IN ('.implode(',',array_fill(0,count($owned),'?')).')');$q->execute($owned);}
    if($postId)db()->prepare('DELETE FROM posts WHERE id=?')->execute([$postId]);
    if($actor){db()->prepare('DELETE FROM journey_audit_events WHERE actor_id=?')->execute([$actor]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$actor]);}
    db()->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$email]);foreach(array_keys($sessions) as $sessionId)db()->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$sessionId]);
    if(is_file($cookie))unlink($cookie);
}
