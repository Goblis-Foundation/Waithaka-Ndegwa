<?php
declare(strict_types=1);

/** Large, isolated archive fixtures verify discoverability and editorial privacy over real HTTP. */
$database=getenv('WK_TEST_DB_NAME')?:'';$base=rtrim(getenv('WK_TEST_BASE_URL')?:'','/');
$host=getenv('WK_TEST_DB_HOST');$port=(int)getenv('WK_TEST_DB_PORT');$parts=parse_url($base);
if(getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'
    ||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',$database)||!in_array($host,['127.0.0.1','::1'],true)
    ||$port<1||$port>65535||($parts['scheme']??'')!=='http'||($parts['host']??'')!=='127.0.0.1'
    ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])
    ||!in_array($parts['path']??'',['','/'],true))throw new RuntimeException('Use the isolated archive HTTP test runner.');
$CONFIG=['app_env'=>'testing','app_key'=>'local-test-key-not-for-production-use','base_url'=>$base,'db'=>[
    'host'=>$host,'port'=>$port,'name'=>$database,'user'=>getenv('WK_TEST_DB_USER'),'pass'=>getenv('WK_TEST_DB_PASS'),'charset'=>'utf8mb4']];
$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
require __DIR__.'/../app/functions.php';
require __DIR__.'/../app/journeys.php';
require __DIR__.'/../app/reader.php';
require __DIR__.'/../app/founder_stories.php';
$checks=0;$actor=0;$categoryIds=[];$postIds=[];$submissionIds=[];
$prefix='archive-'.bin2hex(random_bytes(5));$email=$prefix.'@example.invalid';$password=bin2hex(random_bytes(20));
$cookie=tempnam(sys_get_temp_dir(),'wk-archive-cookie-');if($cookie===false)throw new RuntimeException('Could not create the test cookie jar.');
function archive_check(bool $condition,string $label): void {
    if(!$condition)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;
}
function archive_request(string $path,?array $post=null,bool $authenticated=false): array {
    if(!str_starts_with($path,'/')||str_starts_with($path,'//')||preg_match('/[\r\n]/',$path))throw new RuntimeException('Only local test paths are allowed.');
    $handle=curl_init($GLOBALS['base'].$path);$headers=[];
    curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>15,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_PROXY=>'',
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers):int{$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);}]);
    if($authenticated)curl_setopt_array($handle,[CURLOPT_COOKIEFILE=>$GLOBALS['cookie'],CURLOPT_COOKIEJAR=>$GLOBALS['cookie']]);
    if($post!==null)curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
    if($body===false)throw new RuntimeException('Local archive HTTP request failed.');
    return ['status'=>$status,'headers'=>$headers,'body'=>$body];
}
function archive_document(array $response): DOMXPath {
    $document=new DOMDocument();$before=libxml_use_internal_errors(true);
    try{$document->loadHTML($response['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($before);}
    return new DOMXPath($document);
}
function archive_links(array $response,string $query): array {
    $links=[];foreach(archive_document($response)->query($query) as $node)$links[]=$node->getAttribute('href');return $links;
}
function archive_public_pages(string $path,array $expected,string $query): void {
    $total=count($expected);$pages=(int)ceil($total/24);$all=[];
    for($page=1;$page<=$pages;$page++){
        $pagePath=$path.($page>1?'?page='.$page:'');$response=archive_request($pagePath);
        archive_check($response['status']===200,'Public archive renders '.$pagePath);
        $actual=archive_links($response,$query);
        archive_check($actual===array_slice($expected,($page-1)*24,24),'Stable date/id order, correct scope and no duplicate or missing records on '.$pagePath);
        $all=array_merge($all,$actual);$xpath=archive_document($response);
        archive_check($xpath->evaluate('string(//link[@rel="canonical"]/@href)')===$GLOBALS['base'].$pagePath,'Every archive page has its own canonical URL');
        $nav='//nav[@aria-label="Archive pages"]';
        archive_check($xpath->query($nav)->length===1,'Archive navigation remains available without JavaScript');
        $next=$xpath->evaluate('string('.$nav.'//a[@rel="next"]/@href)');
        archive_check($next===($page<$pages?$GLOBALS['base'].$path.'?page='.($page+1):''),'Next page stops at the actual end');
        $previous=$xpath->evaluate('string('.$nav.'//a[@rel="prev"]/@href)');
        archive_check($previous===($page>1?$GLOBALS['base'].$path.($page>2?'?page='.($page-1):''):''),'Previous page uses the clean first-page address');
        archive_check(!str_contains($response['body'],'PRIVATE_ARCHIVE_MARKER')&&!str_contains($response['body'],'PRIVATE_FOUNDER_MARKER'),'Public pages omit private drafts, notes and submitter details');
    }
    archive_check($all===$expected,'Every expected record is reachable through the whole paginated archive');
    archive_check(archive_request($path.'?page='.($pages+1))['status']===404,'Pages beyond the real archive return404');
}
try {
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Archive test writer',$email,password_hash($password,PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
    $categoryInsert=db()->prepare('INSERT INTO categories(name,slug,description) VALUES(?,?,?)');
    foreach(['primary','secondary','empty'] as $category){$categoryInsert->execute(['Archive '.$category,$prefix.'-'.$category,'Fixture topic.']);$categoryIds[$category]=(int)db()->lastInsertId();}
    $insert=db()->prepare('INSERT INTO posts(user_id,category_id,title,slug,standfirst,content_html,status,published_at) VALUES(?,?,?,?,?,?,?,?)');
    $publicSlugs=[];$primarySlugs=[];
    for($i=0;$i<70;$i++){
        $slug=$prefix.'-post-'.$i;$category=$i<68?'primary':'secondary';$status=$i===0?'scheduled':'published';
        $insert->execute([$actor,$categoryIds[$category],'Archive article '.$i,$slug,'Public summary.','<p>Public article body.</p>',$status,'2000-01-02 00:00:00']);
        $postIds[]=(int)db()->lastInsertId();$publicSlugs[]=$slug;if($category==='primary')$primarySlugs[]=$slug;
    }
    foreach([['draft','2000-01-02 00:00:00'],['scheduled','2099-01-01 00:00:00'],['archived','2000-01-02 00:00:00'],['published',null]] as $i=>[$status,$published]){
        $insert->execute([$actor,$categoryIds['primary'],'PRIVATE_ARCHIVE_MARKER '.$i,$prefix.'-hidden-'.$i,'PRIVATE_ARCHIVE_MARKER','<p>PRIVATE_ARCHIVE_MARKER</p>',$status,$published]);$postIds[]=(int)db()->lastInsertId();
    }
    $submissionInsert=db()->prepare('INSERT INTO founder_submissions(reference,submission_type,submitter_name,submitter_email,founder_name,venture_name,country,story_summary,metric_basis,private_evidence_notes,privacy_version,privacy_accepted_at,ip_hash,review_status,consent_reference,permission_confirmed,claims_reviewed,associated_post_id,is_public,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $reviewingIds=[];
    for($i=0;$i<250;$i++){
        $approved=$i<65||$i===249;$status=$approved?'approved':($i>=75&&$i<=125?'reviewing':'new');
        $post=$i<65?$postIds[$i]:($i===249?$postIds[0]:null);$consent=$approved?'Synthetic recorded permission':null;$permission=$approved?1:0;$claims=$permission;$public=$permission;
        // Public submissions with an unpublished/future post or a missing editorial gate never enter the collection/count.
        if($i>=65&&$i<=72){$status='approved';$post=$i<=68?$postIds[$i+5]:$postIds[$i-4];$consent='Synthetic permission';$permission=1;$claims=1;$public=1;
            if($i===69)$permission=0;if($i===70)$claims=0;if($i===71)$consent='';if($i===72)$public=0;}
        $submissionInsert->execute(['ARCH-'.$prefix.'-'.$i,'founder','PRIVATE_FOUNDER_MARKER',$email,'Founder '.$i,'Venture '.$i,'Kenya','PRIVATE_FOUNDER_MARKER','not_applicable','PRIVATE_FOUNDER_MARKER','test','2000-01-02 00:00:00',hash('sha256',$prefix),$status,$consent,$permission,$claims,$post,$public,'2000-01-02 00:00:00','2000-01-02 00:00:00']);
        $submissionIds[]=(int)db()->lastInsertId();if($status==='reviewing')$reviewingIds[]=end($submissionIds);
    }
    // Final-copy grants are separate from the collection's older editorial/permission gates.
    // One designated founder workflow owns each article; duplicate nominations do not grant authority.
    $seenStoryPosts=[];
    foreach($submissionIds as$fid){
        $q=db()->prepare('SELECT associated_post_id FROM founder_submissions WHERE id=?');$q->execute([$fid]);$pid=(int)$q->fetchColumn();
        if(!$pid||isset($seenStoryPosts[$pid]))continue;$seenStoryPosts[$pid]=true;
        $rid=founder_story_request($fid,['founder_email'=>$email,'founder_whatsapp'=>'+254700000003','contact_evidence'=>'Synthetic archive approval fixture','contact_confirmed'=>1,'whatsapp_optin'=>1,'send_review_ack'=>1,'response_days'=>14],$actor);
        $q=db()->prepare("SELECT payload_encrypted FROM founder_story_deliveries WHERE review_id=? AND channel='whatsapp'");$q->execute([$rid]);$payload=json_decode(decrypt_secret((string)$q->fetchColumn()),true,32,JSON_THROW_ON_ERROR);parse_str((string)parse_url($payload['url'],PHP_URL_QUERY),$values);
        founder_story_respond($values['token'],['decision'=>'approve','approval_ack'=>1]);
        $w=founder_story_workflow($fid);db()->prepare('UPDATE posts SET founder_approval_hash=? WHERE id=?')->execute([$w['approved_hash'],$pid]);
    }
    $articleQuery='//article[@data-thought-item]//*[self::h2 or self::h3]/a';
    $urls=static fn(array $slugs):array=>array_map(static fn(string $slug):string=>$base.'/my-thoughts/'.$slug,array_reverse($slugs));
    $global=db()->query('SELECT p.slug FROM posts p WHERE '.published_where().' ORDER BY p.published_at DESC,p.id DESC')->fetchAll(PDO::FETCH_COLUMN);
    archive_public_pages('/my-thoughts',array_map(static fn(string $slug):string=>$base.'/my-thoughts/'.$slug,$global),$articleQuery);
    archive_public_pages('/topic/'.$prefix.'-primary',$urls($primarySlugs),$articleQuery);
    archive_public_pages('/founders',$urls(array_slice($publicSlugs,0,65)),'//article[contains(concat(" ",normalize-space(@class)," ")," journey-story-card ")]//h3/a');
    archive_check(founders_public_story_count()===65,'Duplicate nominations and unpublished/unapproved stories do not inflate the founder count');
    $r=archive_request('/topic/'.$prefix.'-primary');
    archive_check(str_contains($r['body'],'<strong>68</strong> published'),'The archive headline shows the total, including a due scheduled post, beyond the current24');
    archive_check(archive_request('/topic/'.$prefix.'-empty')['status']===200&&archive_request('/topic/'.$prefix.'-empty?page=2')['status']===404,'An empty topic has one valid empty page');
    // A one-article page has only the featured card, but filtering it still needs
    // the empty-result node consumed by site.js. Restore the fixture immediately.
    $move=db()->prepare('UPDATE posts SET category_id=? WHERE id=? AND user_id=?');
    try{
        $move->execute([$categoryIds['primary'],$postIds[69],$actor]);
        $r=archive_request('/topic/'.$prefix.'-secondary');$single=archive_document($r);
        archive_check($r['status']===200&&$single->query('//article[@data-thought-item]')->length===1,'The filter edge fixture renders exactly one article without an archive card list');
        archive_check($single->query('//*[@data-thought-filter]')->length===1&&$single->query('//*[@data-thought-empty and @hidden]')->length===1,'A one-article page provides the initially hidden empty-result node for the existing filter handler');
    }finally{$move->execute([$categoryIds['secondary'],$postIds[69],$actor]);}
    $r=archive_request('/writing?page=2');archive_check($r['status']===301&&($r['headers']['location']??'')===$base.'/my-thoughts?page=2','The recovered writing alias preserves the page number');
    $r=archive_request('/my-thoughts?page=1');archive_check(archive_document($r)->evaluate('string(//link[@rel="canonical"]/@href)')===$base.'/my-thoughts','An explicit page1 canonicalizes to the clean archive address');
    foreach(['0','-1','invalid','1.5','999999999999999999999999','%5B%5D=2'] as $value){
        $query=$value==='%5B%5D=2'?'page%5B%5D=2':'page='.$value;
        foreach(['/my-thoughts','/topic/'.$prefix.'-primary','/founders'] as $path)archive_check(archive_request($path.'?'.$query)['status']===404,'Malformed public page values return404');
    }
    $r=archive_request('/write/journeys/founders?page=5');archive_check($r['status']===302&&str_ends_with($r['headers']['location']??'','/write/login'),'Private archive pagination requires authentication');
    $r=archive_request('/write/login',null,true);$csrf=archive_document($r)->evaluate('string(//input[@name="csrf"]/@value)');
    $r=archive_request('/write/login',['csrf'=>$csrf,'email'=>$email,'password'=>$password],true);archive_check($r['status']===302,'Temporary archive reviewer logs in through the actual form');
    $expected=array_reverse($submissionIds);$inbox=[];$inboxQuery='//div[contains(concat(" ",normalize-space(@class)," ")," journey-writer-list ")]/article//h3/a';
    for($page=1;$page<=5;$page++){
        $r=archive_request('/write/journeys/founders'.($page>1?'?page='.$page:''),null,true);
        archive_check($r['status']===200&&str_contains($r['headers']['cache-control']??'','no-store')&&str_contains($r['headers']['x-robots-tag']??'','noindex'),'Every private inbox page is authenticated, non-cacheable and non-indexable');
        $ids=array_map(static fn(string $link):int=>(int)basename(parse_url($link,PHP_URL_PATH)),archive_links($r,$inboxQuery));
        archive_check($ids===array_slice($expected,($page-1)*50,50),'Inbox pages preserve stable order through all250 submissions');$inbox=array_merge($inbox,$ids);
        archive_check(!str_contains($r['body'],'PRIVATE_FOUNDER_MARKER')&&!str_contains($r['body'],$email),'Inbox previews omit private evidence and submitter contact details');
    }
    archive_check($inbox===$expected,'Records beyond the old200-item cutoff are reachable exactly once');
    $r=archive_request('/write/journeys/founders?status=reviewing&page=2',null,true);
    $ids=array_map(static fn(string $link):int=>(int)basename(parse_url($link,PHP_URL_PATH)),archive_links($r,$inboxQuery));
    archive_check($r['status']===200&&$ids===[$reviewingIds[0]],'Filtered reviewer inbox page2 contains exactly the remaining submission');
    archive_check(archive_document($r)->evaluate('string(//nav[@aria-label="Founder submission pages"]//a[@rel="prev"]/@href)')===$base.'/write/journeys/founders?status=reviewing','Private pagination preserves the status filter and clean page1 URL');
    archive_check(archive_request('/write/journeys/founders?status=declined',null,true)['status']===200,'An empty valid reviewer filter has one valid page');
    foreach(['page=6','page=0','page=-1','page=invalid','page%5B%5D=2','page=999999999999999999999999','status=declined&page=2','status=invalid','status%5B%5D=new'] as $query){
        $r=archive_request('/write/journeys/founders?'.$query,null,true);archive_check($r['status']===404&&str_contains($r['headers']['cache-control']??'','no-store')&&str_contains($r['headers']['x-robots-tag']??'','noindex'),'Invalid private pages/filters fail without losing private headers');
    }
    echo 'PASS: '.$checks." archive discovery, canonical URL and editorial privacy assertions\n";
}finally{
    if($submissionIds){foreach($submissionIds as$id){
        db()->prepare('DELETE d FROM founder_story_deliveries d JOIN founder_story_reviews r ON r.id=d.review_id WHERE r.founder_id=?')->execute([$id]);
        db()->prepare('DELETE FROM founder_story_reviews WHERE founder_id=?')->execute([$id]);
        db()->prepare('DELETE FROM founder_story_workflows WHERE founder_id=?')->execute([$id]);
        db()->prepare("DELETE FROM journey_audit_events WHERE entity_type='founder' AND entity_id=?")->execute([$id]);
        db()->prepare('DELETE FROM founder_submissions WHERE id=?')->execute([$id]);
    }}
    if($postIds){$delete=db()->prepare('DELETE FROM posts WHERE id=? AND user_id=?');foreach($postIds as $id)$delete->execute([$id,$actor]);}
    if($categoryIds){$delete=db()->prepare('DELETE FROM categories WHERE id=?');foreach($categoryIds as $id)$delete->execute([$id]);}
    db()->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$email]);
    if($actor)db()->prepare('DELETE FROM users WHERE id=?')->execute([$actor]);
    if(is_file($cookie))unlink($cookie);
}

