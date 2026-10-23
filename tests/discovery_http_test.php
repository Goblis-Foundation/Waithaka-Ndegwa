<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing' || getenv('WK_TEST_ALLOW_WRITES')!=='1' || getenv('DB_HOST')!=='127.0.0.1'
    || !preg_match('/\Awaithaka_test_[a-z0-9_]+_test\z/',(string)getenv('DB_DATABASE')) || !ctype_digit((string)getenv('DB_PORT')) || (int)getenv('DB_PORT')<1 || (int)getenv('DB_PORT')>65535)exit(2);
$base=rtrim((string)getenv('TEST_BASE_URL'),'/');$parts=parse_url($base);
if(($parts['scheme']??'')!=='http' || ($parts['host']??'')!=='127.0.0.1' || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass']) || !in_array($parts['path']??'',['','/'],true))exit(2);
require dirname(__DIR__).'/app/bootstrap.php';
if(app_config('app_env')!=='testing' || base_url()!==$base || (string)db()->query('SELECT DATABASE()')->fetchColumn()!==getenv('DB_DATABASE'))throw new RuntimeException('Unexpected discovery HTTP fixture.');
$checks=0;$prefix='discovery-http-'.bin2hex(random_bytes(5));$private='PRIVATE_SEARCH_'.bin2hex(random_bytes(5));$postIds=[];$actor=0;$founder=0;
$cookie=tempnam(sys_get_temp_dir(),'wk-discovery-cookie-');if($cookie===false)throw new RuntimeException('Cannot create test cookie jar.');
$settingsBefore=db()->query("SELECT * FROM settings WHERE setting_key IN ('discovery.indexnow.enabled','discovery.indexnow.key')")->fetchAll();
$publicBefore=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM discovery_public_urls')->fetchColumn();$queueBefore=(int)db()->query('SELECT COALESCE(MAX(id),0) FROM discovery_indexnow_queue')->fetchColumn();
function discovery_http_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function discovery_http_request(string $path,?array $post=null,bool $auth=false):array {
    if(!str_starts_with($path,'/') || str_starts_with($path,'//') || preg_match('/[\r\n]/',$path))throw new RuntimeException('Only local fixture paths are allowed.');
    $headers=[];$curl=curl_init($GLOBALS['base'].$path);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_PROXY=>'',
        CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);}]);
    if($auth)curl_setopt_array($curl,[CURLOPT_COOKIEFILE=>$GLOBALS['cookie'],CURLOPT_COOKIEJAR=>$GLOBALS['cookie']]);
    if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);if($body===false)throw new RuntimeException('The local HTTP fixture did not respond.');return compact('body','status','headers');
}
function discovery_http_dom(array $response):DOMXPath {$document=new DOMDocument();$old=libxml_use_internal_errors(true);try{$document->loadHTML($response['body']);}finally{libxml_clear_errors();libxml_use_internal_errors($old);}return new DOMXPath($document);}
function discovery_http_csrf(array $response):string {if(!preg_match('/name="csrf"\s+value="([a-f0-9]{64})"/',$response['body'],$match))throw new RuntimeException('Missing Writer CSRF field.');return $match[1];}
try {
    $email=$prefix.'@example.invalid';$password=bin2hex(random_bytes(20));db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Discovery HTTP fixture',$email,password_hash($password,PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
    $insert=db()->prepare('INSERT INTO posts(user_id,title,slug,standfirst,content_html,status,published_at) VALUES(?,?,?,?,?,?,?)');
    for($i=0;$i<65;$i++){$insert->execute([$actor,$prefix.' article '.$i,$prefix.'-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'Public HTTP summary.','<p>Public HTTP body.</p>',$i===0?'scheduled':'published','2000-01-02 00:00:00']);$postIds[]=(int)db()->lastInsertId();}
    foreach([['draft','2000-01-02 00:00:00'],['scheduled','2099-01-01 00:00:00'],['published',null],['archived','2000-01-02 00:00:00']]as$i=>[$status,$date]){$insert->execute([$actor,$prefix.' '.$private,$prefix.'-private-'.$i,$private,$private,$status,$date]);$postIds[]=(int)db()->lastInsertId();}
    db()->prepare('INSERT INTO post_drafts(post_id,payload,version,updated_by) VALUES(?,?,1,?)')->execute([$postIds[0],json_encode(['title'=>$private],JSON_THROW_ON_ERROR),$actor]);
    db()->prepare('INSERT INTO founder_submissions(reference,submission_type,submitter_name,submitter_email,founder_name,venture_name,country,story_summary,metric_basis,private_evidence_notes,privacy_version,privacy_accepted_at,ip_hash,review_status,consent_reference,permission_confirmed,claims_reviewed,associated_post_id,is_public,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$prefix,'founder',$private,$email,$private,$private,'Kenya',$private,'not_applicable',$private,'test','2000-01-01 00:00:00',hash('sha256',$prefix),'approved','Synthetic consent',1,1,$postIds[0],1,'2000-01-01 00:00:00','2000-01-01 00:00:00']);$founder=(int)db()->lastInsertId();
    $urls=[];
    foreach([1,2,3]as$page){$response=discovery_http_request('/search?q='.$prefix.($page>1?'&page='.$page:''));$dom=discovery_http_dom($response);
        discovery_http_check($response['status']===200,'Every real search page renders.');$nodes=$dom->query('//article[contains(concat(" ",normalize-space(@class)," ")," post-card ")]//h3/a');
        discovery_http_check($nodes->length===($page<3?24:17),'Search page size uses the full65-row count.');foreach($nodes as$node)$urls[]=$node->getAttribute('href');
        discovery_http_check(str_contains($response['body'],'65 results') && !str_contains($response['body'],$private) && !str_contains($response['body'],$email),'Counts are accurate and no private draft/contact text leaks.');
        discovery_http_check(str_contains($response['headers']['x-robots-tag']??'','noindex'),'Search variants are not indexed as duplicate content.');
        discovery_http_check($dom->evaluate('string(//link[@rel="canonical"]/@href)')===$base.'/search?q='.$prefix.($page>1?'&page='.$page:''),'Each paginated search canonical retains its actual query and page.');
    }
    discovery_http_check(count(array_unique($urls))===65,'All65 results are reachable without duplicates.');
    $response=discovery_http_request('/search?q='.$prefix.'&type=founder');discovery_http_check($response['status']===200 && str_contains($response['body'],'1 result for') && discovery_http_dom($response)->query('//article//h3/a')->length===1,'Founder filter uses the approved associated public post.');
    $response=discovery_http_request('/search?q='.rawurlencode($private));discovery_http_check($response['status']===200 && str_contains($response['body'],'0 results for'),'Searching a private marker cannot disclose its existence through counts.');
    $response=discovery_http_request('/search?q='.rawurlencode('Problem interview worksheet').'&type=resource');discovery_http_check($response['status']===200 && str_contains($response['body'],'/resources/problem-interview-worksheet') && str_contains($response['body'],'Blank working tool'),'Public built-in resources are searchable without fictional dates.');
    foreach(['?q='.$prefix.'&page=4','?q='.$prefix.'&page=0','?q='.$prefix.'&page=1.5','?q='.$prefix.'&page%5B%5D=2','?q%5B%5D=needle','?q='.$prefix.'&type%5B%5D=post','?q='.$prefix.'&type=private','?page=2']as$query)discovery_http_check(discovery_http_request('/search'.$query)['status']===404,'Malformed or out-of-range public searches return a real404.');
    $response=discovery_http_request('/search?q='.rawurlencode('<script>alert(1)</script>'));discovery_http_check($response['status']===200 && !str_contains($response['body'],'<script>alert(1)</script>'),'Search phrases are escaped in text, inputs and metadata.');
    discovery_http_check(discovery_http_request('/search',['q'=>$prefix])['status']===405,'Public search is GET/HEAD only.');
    foreach(['/write/discovery','/write/discovery/report','/write/discovery/indexnow','/write/discovery/reconcile','/write/discovery/retry']as$path){$response=discovery_http_request($path);discovery_http_check($response['status']===302 && ($response['headers']['location']??'')===$base.'/write/login','Every private discovery route requires authentication.');}
    $login=discovery_http_request('/write/login',null,true);$csrf=discovery_http_csrf($login);$response=discovery_http_request('/write/login',['csrf'=>$csrf,'email'=>$email,'password'=>$password],true);discovery_http_check($response['status']===302,'The fixture authenticates through the real Writer session.');
    $response=discovery_http_request('/write/discovery',null,true);$csrf=discovery_http_csrf($response);
    discovery_http_check($response['status']===200 && str_contains($response['body'],'Discovery and reports'),'Private discovery reports render from the real application.');
    discovery_http_check(str_contains($response['headers']['cache-control']??'','no-store') && str_contains($response['headers']['x-robots-tag']??'','noindex'),'Discovery reporting remains private and uncacheable.');
    discovery_http_check(!str_contains($response['body'],(string)app_config('app_key')) && !str_contains($response['body'],$email),'Report and exception screens omit credentials and private addresses.');
    foreach(['/report','/indexnow','/reconcile','/retry']as$suffix){$response=discovery_http_request('/write/discovery'.$suffix,['csrf'=>'invalid','days'=>'28','enabled'=>'1','id'=>'1'],true);discovery_http_check($response['status']===419,'Every discovery mutation rejects invalid CSRF.');discovery_http_check(discovery_http_request('/write/discovery'.$suffix,null,true)['status']===405,'Every mutation endpoint rejects GET.');}
    discovery_http_check(discovery_http_request('/write/discovery?days%5B%5D=28',null,true)['status']===404 && discovery_http_request('/write/discovery?page=999999',null,true)['status']===404,'Private reporting validates filters and queue pagination.');
    $response=discovery_http_request('/write/discovery/indexnow',['csrf'=>$csrf,'enabled'=>'1'],true);discovery_http_check($response['status']===302 && discovery_indexnow_settings()['enabled'],'Explicit Writer opt-in is saved through the protected form.');
    $key=discovery_indexnow_settings()['key'];$proof=discovery_http_request('/'.$key.'.txt');discovery_http_check($proof['status']===200 && $proof['body']===$key && str_contains($proof['headers']['content-type']??'','text/plain'),'Only the exact saved ownership proof is publicly served as plaintext.');
    discovery_http_check(discovery_http_request('/'.str_repeat('z',64).'.txt')['status']===404,'An unknown proof key returns404.');
    $response=discovery_http_request('/write/discovery/reconcile',['csrf'=>$csrf],true);discovery_http_check($response['status']===302,'Writer can reconcile current public URLs without a remote submission.');
    discovery_http_check((int)db()->query('SELECT COUNT(*) FROM discovery_indexnow_attempts')->fetchColumn()===0,'HTTP reconciliation never invokes the transport or fabricates attempt receipts.');
    $response=discovery_http_request('/write/discovery',null,true);discovery_http_check($response['status']===200 && str_contains($response['body'],'No HTTP receipt') && str_contains($response['body'],'Page 1 of'),'Notification pagination shows queued state separately from actual receipts.');
    $before=(int)db()->query('SELECT COUNT(*) FROM discovery_gsc_reports')->fetchColumn();$response=discovery_http_request('/write/discovery/report',['csrf'=>$csrf,'days'=>'28'],true);
    discovery_http_check($response['status']===302 && (int)db()->query('SELECT COUNT(*) FROM discovery_gsc_reports')->fetchColumn()===$before,'Test environments cannot invoke a live Google report through HTTP.');
    $response=discovery_http_request('/write/discovery/indexnow',['csrf'=>$csrf],true);discovery_http_check($response['status']===302 && !discovery_indexnow_settings()['enabled'] && discovery_http_request('/'.$key.'.txt')['status']===404,'Pausing notification permission disables the public ownership proof.');
    echo 'Discovery HTTP checks passed: '.$checks." assertions.\n";
}finally {
    db()->prepare('DELETE a FROM discovery_indexnow_attempts a JOIN discovery_indexnow_queue q ON q.id=a.queue_id WHERE q.id>?')->execute([$queueBefore]);
    db()->prepare('DELETE FROM discovery_indexnow_queue WHERE id>?')->execute([$queueBefore]);db()->prepare('DELETE FROM discovery_public_urls WHERE id>?')->execute([$publicBefore]);
    db()->exec("DELETE FROM settings WHERE setting_key IN ('discovery.indexnow.enabled','discovery.indexnow.key')");foreach($settingsBefore as$row)db()->prepare('INSERT INTO settings(setting_key,setting_value,is_secret) VALUES(?,?,?)')->execute([$row['setting_key'],$row['setting_value'],$row['is_secret']]);
    if($founder)db()->prepare('DELETE FROM founder_submissions WHERE id=?')->execute([$founder]);
    foreach($postIds as$id)db()->prepare('DELETE FROM posts WHERE id=? AND user_id=?')->execute([$id,$actor]);
    if($actor){db()->prepare("DELETE FROM journey_audit_events WHERE actor_id=? AND entity_type='discovery'")->execute([$actor]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$actor]);}
    if(is_file($cookie))unlink($cookie);
}
