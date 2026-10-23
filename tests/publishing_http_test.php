<?php
declare(strict_types=1);

/** Actual Writer form regression against a local, disposable application.
 * The test runner provides APP_ENV=testing, TEST_BASE_URL (or WK_TEST_BASE_URL),
 * and DB_HOST/PORT/DATABASE/USERNAME/PASSWORD (or WK_TEST_DB_* equivalents).
 * Run after migrations with tests/010_security_router.php serving the same DB.
 * Only this test's temporary Writer user and packages are changed. The real
 * publishing worker is exercised with its email hook deliberately unloaded;
 * no OAuth, provider mutation, remote URL fetch or email transport is invoked.
 */

function pub_http_environment(string $preferred, string $fallback, string $default=''): string {
    $value=getenv($preferred);
    if($value!==false&&$value!=='')return $value;
    $value=getenv($fallback);
    return $value!==false?$value:$default;
}

$base=rtrim(pub_http_environment('TEST_BASE_URL','WK_TEST_BASE_URL'),'/');
$database=pub_http_environment('DB_DATABASE','WK_TEST_DB_NAME');
$databaseHost=pub_http_environment('DB_HOST','WK_TEST_DB_HOST');
$databasePort=(int)pub_http_environment('DB_PORT','WK_TEST_DB_PORT','3306');
$urlParts=parse_url($base);
if(getenv('APP_ENV')!=='testing'
    || !preg_match('/^[a-zA-Z0-9_]+_test$/D',$database)
    || !in_array($databaseHost,['127.0.0.1','::1'],true)
    || $databasePort<1 || $databasePort>65535
    || ($urlParts['scheme']??'')!=='http' || ($urlParts['host']??'')!=='127.0.0.1'
    || isset($urlParts['user']) || isset($urlParts['pass']) || isset($urlParts['query']) || isset($urlParts['fragment'])
    || !in_array($urlParts['path']??'',['','/'],true)) {
    fwrite(STDERR,"Use APP_ENV=testing, a numeric loopback DB ending _test and a local http://127.0.0.1 TEST_BASE_URL.\n");
    exit(2);
}
if(!extension_loaded('curl')||!extension_loaded('dom')) {
    fwrite(STDERR,"Publishing HTTP tests require PHP cURL and DOM.\n");exit(2);
}

$CONFIG=['app_env'=>'testing','app_key'=>getenv('APP_KEY')?:'local-test-key-not-for-production-use',
    'timezone'=>'Africa/Nairobi','base_url'=>$base,'db'=>[
        'host'=>$databaseHost,'port'=>$databasePort,'name'=>$database,
        'user'=>pub_http_environment('DB_USERNAME','WK_TEST_DB_USER'),
        'pass'=>pub_http_environment('DB_PASSWORD','WK_TEST_DB_PASS'),'charset'=>'utf8mb4'
    ]];
$APP_INSTALLED=true;
date_default_timezone_set('Africa/Nairobi');
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/publishing.php';

$checks=0;$userId=0;$packageId=0;$sessionIds=[];
$suffix=bin2hex(random_bytes(8));
$email='publishing-http-'.$suffix.'@example.test';
$password=bin2hex(random_bytes(20));
$cookie=tempnam(sys_get_temp_dir(),'wk-publishing-http-');
if($cookie===false)throw new RuntimeException('Cannot create a temporary HTTP cookie jar.');

function pub_http_check(bool $condition,string $message): void {
    global $checks;
    if(!$condition)throw new RuntimeException('FAIL: '.$message);
    $checks++;
}

function pub_http_request(string $path,?array $post=null,bool $authenticated=true): array {
    global $base,$cookie,$sessionIds;
    if(!str_starts_with($path,'/write/')||str_contains($path,"\r")||str_contains($path,"\n"))throw new RuntimeException('Only local Writer paths are allowed in this suite.');
    $handle=curl_init($base.$path);$headers=[];
    curl_setopt_array($handle,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers,&$sessionIds):int{
            $pair=explode(':',$line,2);
            if(count($pair)===2){
                $key=strtolower(trim($pair[0]));$headers[$key]=trim($pair[1]);
                // Track even the anonymous request's session for fixture cleanup.
                if($key==='set-cookie'&&preg_match('/^PHPSESSID=([A-Za-z0-9,-]+)(?:;|$)/',$headers[$key],$match))$sessionIds[$match[1]]=true;
            }
            return strlen($line);
        }]);
    if($authenticated)curl_setopt_array($handle,[CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);
    if($post!==null)curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $body=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);$error=curl_error($handle);
    foreach(curl_getinfo($handle,CURLINFO_COOKIELIST)?:[] as $line){
        $parts=explode("\t",$line);
        if(count($parts)===7&&$parts[5]==='PHPSESSID'&&preg_match('/^[A-Za-z0-9,-]+$/D',$parts[6]))$sessionIds[$parts[6]]=true;
    }
    curl_close($handle);
    if($body===false)throw new RuntimeException('Local HTTP request failed: '.$error);
    return ['status'=>$status,'headers'=>$headers,'body'=>$body];
}

function pub_http_document(array $response): DOMDocument {
    $previous=libxml_use_internal_errors(true);
    $document=new DOMDocument();
    try {
        if(!$document->loadHTML($response['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new RuntimeException('Writer returned unreadable HTML.');
    } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    return $document;
}

/** Read hidden fields from the rendered form, including the review hash. */
function pub_http_form(array $response,?string $action=null): array {
    $document=pub_http_document($response);
    foreach($document->getElementsByTagName('form') as $form) {
        if(strtolower($form->getAttribute('method'))!=='post')continue;
        if($action!==null&&parse_url($form->getAttribute('action'),PHP_URL_PATH)!==$action)continue;
        $fields=[];
        foreach($form->getElementsByTagName('input') as $input) {
            if(strtolower($input->getAttribute('type'))==='hidden')$fields[$input->getAttribute('name')]=$input->getAttribute('value');
        }
        if(isset($fields['csrf'])&&preg_match('/^[a-f0-9]{64}$/D',$fields['csrf']))return $fields;
    }
    throw new RuntimeException('The expected CSRF-protected Writer form was not rendered: '.($action??'login'));
}

function pub_http_redirect(array $response,string $path): bool {
    global $base;
    return $response['status']===302&&($response['headers']['location']??'')===$base.$path;
}

function pub_http_row(string $sql,array $values=[]): array {
    $query=db()->prepare($sql);$query->execute($values);return $query->fetch()?:[];
}

function pub_http_count(string $sql,array $values=[]): int {
    $query=db()->prepare($sql);$query->execute($values);return (int)$query->fetchColumn();
}

try {
    // This is an explicit temporary account, never a recovered or real credential.
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')
        ->execute(['Publishing HTTP fixture '.$suffix,$email,password_hash($password,PASSWORD_DEFAULT)]);
    $userId=(int)db()->lastInsertId();
    $facebook=pub_http_row("SELECT * FROM publishing_channels WHERE slug='facebook'");
    pub_http_check($facebook&&$facebook['delivery_method']==='manual'&&$facebook['account_type']==='professional_profile',
        'The disposable fixture must start with Facebook professional-profile assisted delivery.');
    $tiktok=pub_http_row("SELECT * FROM publishing_channels WHERE slug='tiktok'");
    pub_http_check($tiktok&&$tiktok['account_type']==='professional'&&$tiktok['delivery_method']==='manual',
        'The fully migrated fixture must record TikTok as professional while preserving assisted delivery.');
    pub_http_check(!function_exists('publishing_send_reminder'),'The HTTP worker test must not load an email hook.');

    $response=pub_http_request('/write/publishing/new',null,false);
    pub_http_check(pub_http_redirect($response,'/write/login'),'Anonymous publication editing must require Writer login.');
    $response=pub_http_request('/write/login');$login=pub_http_form($response);
    pub_http_check($response['status']===200,'The real login form must render.');
    $response=pub_http_request('/write/login',$login+['email'=>$email,'password'=>$password]);
    pub_http_check(pub_http_redirect($response,'/write'),'The temporary Writer account must log in through HTTP.');

    $response=pub_http_request('/write/connections');
    pub_http_check($response['status']===200&&str_contains($response['body'],'Facebook professional mode is a profile type'),
        'Connections must explain the professional-profile/Page distinction.');
    $connectionFields=pub_http_form($response,'/write/connections/settings');
    $connectionDocument=new DOMXPath(pub_http_document($response));
    pub_http_check($connectionDocument->query('//input[@name="tiktok_client_id"]')->length===1
        &&$connectionDocument->query('//input[@name="tiktok_client_secret"]')->length===1
        &&str_contains($response['body'],'/write/connections/callback/tiktok_read'),
        'TikTok Login Kit must expose client-key settings and the exact read-only callback.');
    pub_http_check(pub_http_redirect(pub_http_request('/write/connections/tiktok_read/report',[],false),'/write/login'),
        'TikTok account reports must require Writer authentication.');
    pub_http_check(pub_http_request('/write/connections/tiktok_read/report',['csrf'=>'invalid'])['status']===419,
        'TikTok report requests must be CSRF protected.');
    pub_http_check(pub_http_redirect(pub_http_request('/write/connections/tiktok_read/report',$connectionFields),'/write/connections'),
        'An unconnected TikTok account must not trigger a report or outgoing provider call.');
    pub_http_check(pub_http_request('/write/connections/tiktok_read/report')['status']===404,
        'A GET request must not trigger a private TikTok report.');
    $connectionXpath=new DOMXPath(pub_http_document($response));
    $tiktokHelp=$connectionXpath->evaluate("string(//article[.//h3[normalize-space()='TikTok']])");
    pub_http_check(str_contains($tiktokHelp,'Declared type: professional (owner-declared)')
        &&str_contains($response['body'],'does not verify a Business account or grant publishing access'),
        'The actual Connections page distinguishes the TikTok declaration from verified Business status and authorization.');
    pub_http_check($connectionXpath->query("//select[@id='fb-type' and @name='account_type']")->length===1
        &&$connectionXpath->query("//*[@id='fb-type']")->length===1,
        'The Facebook account-type form retains one unambiguous labeled select.');
    $response=pub_http_request('/write/publishing/new');$save=pub_http_form($response,'/write/publishing/save');
    pub_http_check($response['status']===200&&str_contains($response['headers']['cache-control']??'','no-store')
        &&str_contains($response['headers']['x-robots-tag']??'','noindex'),'Publication forms must be private and non-indexable.');
    $xpath=new DOMXPath(pub_http_document($response));
    pub_http_check(str_contains($xpath->evaluate("string(//strong[normalize-space()='TikTok']/following-sibling::small)"),'professional (owner-declared)'),
        'The publication composer uses the corrected TikTok declaration without claiming provider verification.');
    pub_http_check($xpath->query('//input[@type="checkbox" and starts-with(@name,"variants[") and contains(@name,"][enabled]")]')->length===6,
        'The editor must render an independent selection for each accepted channel.');

    $firstCopy='Initial HTTP copy '.$suffix;
    $reviewedCopy='Reviewed HTTP copy & next-week plan '.$suffix;
    $laterCopy='Later unapproved HTTP copy '.$suffix;
    $draft=['title'=>'Publishing HTTP fixture '.$suffix,'source_url'=>'','notes'=>'Private fixture note '.$suffix,
        'variants'=>['facebook'=>['enabled'=>'1','text'=>$firstCopy,'media_url'=>'','media_type'=>'image','alt_text'=>'','format'=>'post']]];
    $response=pub_http_request('/write/publishing/save',array_replace($save,$draft,['csrf'=>'invalid']));
    pub_http_check($response['status']===419,'Publishing draft saves must reject invalid CSRF.');
    pub_http_check(pub_http_count('SELECT COUNT(*) FROM publishing_packages WHERE created_by=?',[$userId])===0,
        'A rejected save must create no publication package.');
    $response=pub_http_request('/write/publishing/save',array_replace($save,$draft));
    $location=$response['headers']['location']??'';
    pub_http_check($response['status']===302&&(bool)preg_match('~^'.preg_quote($base,'~').'/write/publishing/([0-9]+)$~D',$location,$match),
        'A real draft save must redirect to its new package.');
    $packageId=(int)$match[1];$packagePath='/write/publishing/'.$packageId;
    $package=pub_http_row('SELECT * FROM publishing_packages WHERE id=?',[$packageId]);
    pub_http_check((int)$package['created_by']===$userId&&(int)$package['draft_version']===1&&$package['approved_revision_id']===null,
        'A saved package must start unapproved and belong to its Writer creator.');
    $response=pub_http_request($packagePath);
    $staleApproval=pub_http_form($response,$packagePath.'/approve');
    $edit=pub_http_form($response,'/write/publishing/save');
    pub_http_check($response['status']===200&&str_contains($response['body'],$firstCopy)
        &&preg_match('/^[a-f0-9]{64}$/D',$staleApproval['destinations_hash']??'')===1,
        'The saved review must render the actual draft and its destination-binding hash.');

    // Simulate two open tabs: the first review must not approve the later edit.
    $draft['variants']['facebook']['text']=$reviewedCopy;
    $response=pub_http_request('/write/publishing/save',array_replace($edit,$draft));
    pub_http_check(pub_http_redirect($response,$packagePath),'An edited draft must save through its rendered version field.');
    $response=pub_http_request($packagePath.'/approve',$staleApproval+['approve_content'=>'1','media_rights'=>'1']);
    pub_http_check(pub_http_redirect($response,$packagePath),'A stale approval must return the user to the review.');
    pub_http_check(pub_http_count('SELECT COUNT(*) FROM publishing_revisions WHERE package_id=?',[$packageId])===0,
        'Submitting an old review must not create an approved revision.');
    $response=pub_http_request($packagePath);
    pub_http_check(str_contains($response['body'],'This draft changed.'),'The stale-review conflict must be explained in the actual page.');
    $approval=pub_http_form($response,$packagePath.'/approve');
    $response=pub_http_request($packagePath.'/approve',$approval+['approve_content'=>'1']);
    pub_http_check(pub_http_redirect($response,$packagePath)
        &&pub_http_count('SELECT COUNT(*) FROM publishing_revisions WHERE package_id=?',[$packageId])===0,
        'Content approval without the media-permission acknowledgement must be rejected server-side.');
    $response=pub_http_request($packagePath.'/approve',$approval+['approve_content'=>'1','media_rights'=>'1']);
    pub_http_check(pub_http_redirect($response,$packagePath),'The current reviewed revision must approve through HTTP.');
    $revision=pub_http_row('SELECT * FROM publishing_revisions WHERE package_id=?',[$packageId]);
    $snapshot=json_decode($revision['snapshot_json']??'',true);
    pub_http_check((int)($revision['draft_version']??0)===2&&($snapshot['variants']['facebook']['text']??'')===$reviewedCopy
        &&($snapshot['destinations']['facebook']['method']??'')==='manual',
        'Approval must freeze the saved copy and the assisted destination, not an earlier tab.');
    $frozenJson=$revision['snapshot_json'];$revisionId=(int)$revision['id'];
    $response=pub_http_request($packagePath);
    $schedule=pub_http_form($response,$packagePath.'/schedule');
    $edit=pub_http_form($response,'/write/publishing/save');
    pub_http_check((int)$schedule['revision_id']===$revisionId&&str_contains($response['body'],'Revision 2 approved'),
        'The scheduling form must refer to the exact approved revision.');
    $release=(new DateTimeImmutable('now',new DateTimeZone('Africa/Nairobi')))->modify('+1 day');
    $localRelease=$release->format('Y-m-d\TH:i');
    $expectedUtc=$release->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:00');
    $response=pub_http_request($packagePath.'/schedule',array_replace($schedule,['csrf'=>'invalid','scheduled_at'=>$localRelease]));
    pub_http_check($response['status']===419&&pub_http_count('SELECT COUNT(*) FROM publishing_jobs WHERE revision_id=?',[$revisionId])===0,
        'Scheduling must reject invalid CSRF without queuing a job.');
    $response=pub_http_request($packagePath.'/schedule',$schedule+['scheduled_at'=>$localRelease]);
    pub_http_check(pub_http_redirect($response,$packagePath),'The approved package must schedule through HTTP.');
    $job=pub_http_row('SELECT * FROM publishing_jobs WHERE revision_id=?',[$revisionId]);$jobId=(int)($job['id']??0);
    pub_http_check($jobId>0&&$job['method']==='manual'&&$job['status']==='scheduled'&&$job['scheduled_at']===$expectedUtc
        &&$job['connection_id']===null&&$job['remote_id']===null,
        'The selected assisted channel must create one local job at the correct UTC release time.');
    $response=pub_http_request($packagePath.'/schedule',$schedule+['scheduled_at'=>$localRelease]);
    pub_http_check(pub_http_redirect($response,$packagePath)
        &&pub_http_count('SELECT COUNT(*) FROM publishing_jobs WHERE revision_id=?',[$revisionId])===1,
        'Submitting the same scheduling form twice must not create a duplicate delivery.');

    $draft['variants']['facebook']['text']=$laterCopy;
    $response=pub_http_request('/write/publishing/save',array_replace($edit,$draft));
    pub_http_check(pub_http_redirect($response,$packagePath),'A later draft must remain editable after scheduling.');
    $package=pub_http_row('SELECT * FROM publishing_packages WHERE id=?',[$packageId]);
    $unchanged=pub_http_row('SELECT snapshot_json FROM publishing_revisions WHERE id=?',[$revisionId]);
    pub_http_check($package['approved_revision_id']===null&&(int)$package['draft_version']===3&&$unchanged['snapshot_json']===$frozenJson,
        'Editing after scheduling must clear current approval while preserving the scheduled snapshot.');
    $response=pub_http_request($packagePath.'/schedule',$schedule+['scheduled_at'=>$localRelease]);
    pub_http_check(pub_http_redirect($response,$packagePath)
        &&pub_http_count('SELECT COUNT(*) FROM publishing_jobs WHERE revision_id=?',[$revisionId])===1,
        'An obsolete scheduling form must not schedule an unapproved newer draft.');

    // Advance only our local job's clock. Never run a broad worker against other
    // people's due jobs or a provider polling queue, even in a test database.
    $otherWork=pub_http_count("SELECT COUNT(*) FROM publishing_jobs WHERE id<>? AND (
        (status='scheduled' AND scheduled_at<=UTC_TIMESTAMP())
        OR (status='processing' AND locked_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE))
        OR (status IN ('delivered','reminder_sent') AND method<>'manual' AND (next_check_at IS NULL OR next_check_at<=UTC_TIMESTAMP())))",[$jobId]);
    pub_http_check($otherWork===0,'Use an isolated fixture with no other due deliveries or remote polling work.');
    db()->prepare("UPDATE publishing_jobs SET scheduled_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=? AND method='manual' AND status='scheduled'")->execute([$jobId]);
    $worked=publishing_work(1);
    pub_http_check($worked['processed']===1&&$worked['assisted']===1&&$worked['published']===0,
        'The actual worker must make an assisted task without claiming publication.');
    $job=pub_http_row('SELECT * FROM publishing_jobs WHERE id=?',[$jobId]);
    pub_http_check($job['status']==='assisted'&&$job['remote_id']===null&&$job['external_url']===null,
        'Assisted worker completion must contain no invented platform acknowledgement.');
    $response=pub_http_request($packagePath);
    $completion=pub_http_form($response,'/write/publishing/jobs/'.$jobId.'/complete');
    $xpath=new DOMXPath(pub_http_document($response));$copy=$xpath->query('//textarea[@id="copy-'.$jobId.'"]')->item(0);
    pub_http_check($response['status']===200&&$copy!==null&&$copy->textContent===$reviewedCopy,
        'The real completion screen must offer the frozen approved copy, not the newer unapproved draft.');

    $completePath='/write/publishing/jobs/'.$jobId.'/complete';
    $response=pub_http_request($completePath,$completion+['external_url'=>'https://www.facebook.com/iwaithakaw','confirm_published'=>'1']);
    pub_http_check(pub_http_redirect($response,$packagePath)
        &&pub_http_row('SELECT status FROM publishing_jobs WHERE id=?',[$jobId])['status']==='assisted',
        'A profile URL must not falsely complete an assisted publication.');
    // Synthetic post URL: stored as the fixture user's confirmation, never fetched.
    $postUrl='https://www.facebook.com/iwaithakaw/posts/123456789012345';
    $response=pub_http_request($completePath,$completion+['external_url'=>$postUrl,'confirm_published'=>'1']);
    pub_http_check(pub_http_redirect($response,$packagePath),'Manual completion must submit through the protected HTTP form.');
    $job=pub_http_row('SELECT * FROM publishing_jobs WHERE id=?',[$jobId]);
    pub_http_check($job['status']==='published'&&$job['external_url']===$postUrl&&$job['completed_at']!==null&&$job['remote_id']===null,
        'Manual completion must store the supplied URL and distinguish it from a provider-issued ID.');
    pub_http_check(pub_http_count("SELECT COUNT(*) FROM publishing_events WHERE job_id=? AND event_type='manual_completion' AND actor_id=?",[$jobId,$userId])===1,
        'The fixture Writer must be recorded as the person confirming completion.');
    $response=pub_http_request($packagePath);
    pub_http_check($response['status']===200&&str_contains($response['body'],$postUrl)&&str_contains($response['body'],'View post'),
        'The completed record must render its platform link.');
    $response=pub_http_request($completePath,$completion+['external_url'=>$postUrl,'confirm_published'=>'1']);
    pub_http_check(pub_http_redirect($response,$packagePath)
        &&pub_http_count("SELECT COUNT(*) FROM publishing_events WHERE job_id=? AND event_type='manual_completion'",[$jobId])===1,
        'Repeated completion must not create another completion event.');
    pub_http_check(pub_http_count('SELECT COUNT(*) FROM email_outbox WHERE dedupe_key=?',[hash('sha256','publishing:'.$job['job_key'])])===0
        &&pub_http_count('SELECT COUNT(*) FROM publishing_oauth_states WHERE user_id=?',[$userId])===0,
        'This workflow must create neither an email request nor an OAuth authorization request.');
    $response=pub_http_request('/write/logout',['csrf'=>$completion['csrf']]);
    pub_http_check(pub_http_redirect($response,'/write/login'),'The fixture session must sign out through the CSRF-protected form.');
    $response=pub_http_request($packagePath);
    pub_http_check(pub_http_redirect($response,'/write/login'),'Publication details must become private again after logout.');
    echo "PASS: {$checks} publishing HTTP workflow assertions\n";
} finally {
    // Delete only rows owned by this random fixture user, respecting FK order.
    if($userId) {
        $query=db()->prepare('SELECT j.job_key FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id WHERE p.created_by=?');$query->execute([$userId]);
        foreach($query->fetchAll() as $row)db()->prepare('DELETE FROM email_outbox WHERE dedupe_key=?')->execute([hash('sha256','publishing:'.$row['job_key'])]);
        db()->prepare('DELETE FROM publishing_events WHERE actor_id=? OR package_id IN (SELECT id FROM publishing_packages WHERE created_by=?)')->execute([$userId,$userId]);
        db()->prepare('DELETE j FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id WHERE p.created_by=?')->execute([$userId]);
        db()->prepare('DELETE r FROM publishing_revisions r JOIN publishing_packages p ON p.id=r.package_id WHERE p.created_by=?')->execute([$userId]);
        db()->prepare('DELETE FROM publishing_packages WHERE created_by=?')->execute([$userId]);
        db()->prepare('DELETE FROM publishing_oauth_states WHERE user_id=?')->execute([$userId]);
        db()->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$email]);
        db()->prepare('DELETE FROM users WHERE id=?')->execute([$userId]);
    }
    foreach(array_keys($sessionIds) as $sessionId)db()->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$sessionId]);
    if(is_file($cookie))unlink($cookie);
}
