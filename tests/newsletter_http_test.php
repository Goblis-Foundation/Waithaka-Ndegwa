<?php
declare(strict_types=1);

/** Actual Writer and RFC 8058 routes on an isolated local application.
 * APP_ENV=testing WK_TEST_ALLOW_WRITES=1 TEST_BASE_URL=http://127.0.0.1:<port>
 * and explicit DB_* (or WK_TEST_DB_*) variables are required. Server must use
 * disabled mail transport, a fixture MAIL_POSTAL_ADDRESS, and the same DB.
 * This test creates and removes only its own random synthetic fixture records.
 */
function nl_http_env(string $preferred,string $fallback):string|false{$value=getenv($preferred);return $value!==false?$value:getenv($fallback);}
$base=rtrim((string)nl_http_env('TEST_BASE_URL','WK_TEST_BASE_URL'),'/');$parts=parse_url($base);
$dbName=nl_http_env('DB_DATABASE','WK_TEST_DB_NAME');$dbHost=nl_http_env('DB_HOST','WK_TEST_DB_HOST');$dbPort=nl_http_env('DB_PORT','WK_TEST_DB_PORT');
$dbUser=nl_http_env('DB_USERNAME','WK_TEST_DB_USER');$dbPass=nl_http_env('DB_PASSWORD','WK_TEST_DB_PASS');
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing'||getenv('WK_TEST_ALLOW_WRITES')!=='1'
    ||!is_string($dbName)||!preg_match('/^waithaka_test_[a-z0-9_]+_test$/D',$dbName)
    ||!in_array($dbHost,['127.0.0.1','::1'],true)||!is_string($dbPort)||!ctype_digit($dbPort)||(int)$dbPort<1||(int)$dbPort>65535
    ||!is_string($dbUser)||$dbUser===''||!is_string($dbPass)
    ||($parts['scheme']??'')!=='http'||($parts['host']??'')!=='127.0.0.1'||!isset($parts['port'])
    ||isset($parts['user'])||isset($parts['pass'])||isset($parts['query'])||isset($parts['fragment'])||!in_array($parts['path']??'',['','/'],true)){
    fwrite(STDERR,"Use APP_ENV=testing, WK_TEST_ALLOW_WRITES=1, explicit numeric-loopback DB_* credentials ending _test, and TEST_BASE_URL=http://127.0.0.1:<port>.\n");exit(2);
}
if(!extension_loaded('curl')||!extension_loaded('dom'))throw new RuntimeException('The HTTP suite requires PHP cURL and DOM.');
$CONFIG=['app_env'=>'testing','app_key'=>getenv('APP_KEY')?:'local-test-key-not-for-production-use','base_url'=>$base,'timezone'=>'Africa/Nairobi',
    'db'=>['host'=>$dbHost,'port'=>(int)$dbPort,'name'=>$dbName,'user'=>$dbUser,'pass'=>$dbPass,'charset'=>'utf8mb4']];
$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
foreach(['functions','journeys','newsletter']as$module)require_once __DIR__.'/../app/'.$module.'.php';
$checks=0;$actor=0;$subscriberIds=[];$sessionIds=[];$bucketKeys=[];
$suffix=bin2hex(random_bytes(8));$writerEmail='newsletter-http-'.$suffix.'@example.invalid';$password=bin2hex(random_bytes(20));
$cookie=tempnam(sys_get_temp_dir(),'wk-newsletter-http-');if($cookie===false)throw new RuntimeException('Cannot create the fixture cookie jar.');
function nl_http_check(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;}
function nl_http_request(string $path,?array $fields=null,bool $authenticated=true,string $method='GET',bool $multipart=false):array{
    global $base,$cookie,$sessionIds;
    if(!preg_match('~^/(?:write(?:/|$)|newsletter(?:/|\?|$))~',$path)||preg_match('/[\r\n]/',$path))throw new RuntimeException('Only local newsletter and Writer routes are allowed.');
    $curl=curl_init($base.$path);$headers=[];
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$headers,&$sessionIds):int{
            $pair=explode(':',$line,2);if(count($pair)===2){$key=strtolower(trim($pair[0]));$headers[$key]=trim($pair[1]);if($key==='set-cookie'&&preg_match('/^PHPSESSID=([A-Za-z0-9,-]+)(?:;|$)/',$headers[$key],$match))$sessionIds[$match[1]]=true;}return strlen($line);
        }]);
    if($authenticated)curl_setopt_array($curl,[CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie]);
    if($fields!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$multipart?$fields:http_build_query($fields)]);
    if($method==='HEAD')curl_setopt($curl,CURLOPT_NOBODY,true);
    elseif($method!=='GET'&&$method!=='POST')curl_setopt($curl,CURLOPT_CUSTOMREQUEST,$method);
    $body=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);
    foreach(curl_getinfo($curl,CURLINFO_COOKIELIST)?:[]as$line){$row=explode("\t",$line);if(count($row)===7&&$row[5]==='PHPSESSID'&&preg_match('/^[A-Za-z0-9,-]+$/D',$row[6]))$sessionIds[$row[6]]=true;}
    curl_close($curl);if($body===false)throw new RuntimeException('Local HTTP request failed: '.$error);
    return ['status'=>$status,'headers'=>$headers,'body'=>$body];
}
function nl_http_document(array $response):DOMDocument{
    $previous=libxml_use_internal_errors(true);$document=new DOMDocument();
    try{if(!$document->loadHTML($response['body'],LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new RuntimeException('Unreadable fixture HTML.');}
    finally{libxml_clear_errors();libxml_use_internal_errors($previous);}return $document;
}
function nl_http_form(array $response,?string $action=null,?string $id=null,?string $formAction=null):array{
    foreach(nl_http_document($response)->getElementsByTagName('form')as$form){
        if(strtolower($form->getAttribute('method'))!=='post'||($action!==null&&parse_url($form->getAttribute('action'),PHP_URL_PATH)!==$action)||($id!==null&&$form->getAttribute('id')!==$id))continue;
        $fields=[];foreach($form->getElementsByTagName('input')as$input)if(strtolower($input->getAttribute('type'))==='hidden')$fields[$input->getAttribute('name')]=$input->getAttribute('value');
        if($formAction!==null&&($fields['action']??'')!==$formAction)continue;
        if(isset($fields['csrf'])&&preg_match('/^[a-f0-9]{64}$/D',$fields['csrf']))return $fields;
    }throw new RuntimeException('Expected protected form was not rendered: '.($action??'login').' '.($id??''));
}
function nl_http_redirect(array $response,string $path):bool{global $base;return $response['status']===302&&($response['headers']['location']??'')===$base.$path;}
function nl_http_row(string $sql,array $values=[]):array{$q=db()->prepare($sql);$q->execute($values);return $q->fetch()?:[];}
function nl_http_count(string $sql,array $values=[]):int{$q=db()->prepare($sql);$q->execute($values);return (int)$q->fetchColumn();}
function nl_http_subscriber(string $label,string $status='confirmed'):int{
    global $suffix,$subscriberIds;
    db()->prepare('INSERT INTO newsletter_subscribers(email,name,status,consent_version,requested_at,confirmed_at,created_at,updated_at) VALUES(?,?,?,?,NOW(),?,NOW(),NOW())')
        ->execute([$label.'-'.$suffix.'@example.invalid','HTTP fixture '.$label,$status,'synthetic-http-v1',$status==='confirmed'?date('Y-m-d H:i:s'):null]);
    $id=(int)db()->lastInsertId();$subscriberIds[]=$id;return $id;
}
try{
    nl_http_check(nl_http_count("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed'")===0,'The shared HTTP fixture contains no real or unrelated confirmed newsletter audience.');
    db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Newsletter HTTP fixture '.$suffix,$writerEmail,password_hash($password,PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
    $postIds=[];foreach(['published','draft']as$status){db()->prepare('INSERT INTO posts(user_id,title,slug,standfirst,content_html,status,published_at) VALUES(?,?,?,?,?,?,NOW())')->execute([$actor,'HTTP article '.$status.' '.$suffix,'nl-http-'.$status.'-'.$suffix,'Public fixture summary','<p>Imported public fixture body.</p>',$status]);$postIds[$status]=(int)db()->lastInsertId();}
    $confirmed=nl_http_subscriber('confirmed');$pending=nl_http_subscriber('pending','pending');
    $response=nl_http_request('/write/newsletter/new',null,false);
    nl_http_check(nl_http_redirect($response,'/write/login'),'Anonymous newsletter editing requires Writer authentication.');
    $response=nl_http_request('/write/login');$login=nl_http_form($response);
    nl_http_check($response['status']===200,'The real Writer login form loads.');
    $response=nl_http_request('/write/login',$login+['email'=>$writerEmail,'password'=>$password]);
    nl_http_check(nl_http_redirect($response,'/write'),'The temporary Writer signs in through HTTP.');
    $response=nl_http_request('/write/newsletter/new');$save=nl_http_form($response,'/write/newsletter/new','compose');
    nl_http_check($response['status']===200&&str_contains($response['headers']['cache-control']??'','no-store')&&str_contains($response['headers']['x-robots-tag']??'','noindex'),'Newsletter Writer screens are private and non-indexable.');
    nl_http_check(str_contains($response['body'],'contenteditable="true"')&&str_contains($response['body'],'data-editor-form'),'The actual composer provides the shared rich-text editor contract.');
    $draft=['title'=>'Newsletter HTTP fixture '.$suffix,'subject'=>'First saved subject','preheader'=>'Fixture preview','content_html'=>'<p>First saved body '.$suffix.'.</p>','source_post_id'=>''];
    $response=nl_http_request('/write/newsletter/new',array_replace($save,$draft,['csrf'=>'invalid']));
    nl_http_check($response['status']===419&&nl_http_count('SELECT COUNT(*) FROM newsletter_campaigns WHERE created_by=?',[$actor])===0,'Invalid CSRF cannot save an edition.');
    $response=nl_http_request('/write/newsletter/new',array_replace($save,$draft,['company_website'=>'filled honeypot']));
    nl_http_check($response['status']===422&&nl_http_count('SELECT COUNT(*) FROM newsletter_campaigns WHERE created_by=?',[$actor])===0,'A honeypot-filled Writer request cannot create an edition.');
    $response=nl_http_request('/write/newsletter/new',['csrf'=>$save['csrf'],'action'=>'import','post_id'=>(string)$postIds['draft']]);
    nl_http_check($response['status']===422&&!str_contains($response['body'],'Imported public fixture body.'),'HTTP article import cannot expose a private post.');
    $response=nl_http_request('/write/newsletter/new',array_replace($save,$draft));
    $campaign=nl_http_row('SELECT * FROM newsletter_campaigns WHERE created_by=? ORDER BY id DESC LIMIT 1',[$actor]);$campaignId=(int)($campaign['id']??0);$path='/write/newsletter/'.$campaignId;
    nl_http_check($campaignId>0&&nl_http_redirect($response,$path)&&$campaign['status']==='draft','A real composer submission saves only a private draft.');
    $response=nl_http_request($path);$staleApproval=nl_http_form($response,$path.'/approve');$edit=nl_http_form($response,$path,'compose');
    nl_http_check(str_contains($response['body'],'Mail transport is disabled')&&str_contains($response['body'],'Background work is disabled'),'The UI accurately reports disabled outbound delivery and worker state.');
    $response=nl_http_request($path.'/preview');
    nl_http_check($response['status']===200&&str_contains($response['body'],'First saved body '.$suffix)&&!str_contains($response['body'],'?token='),'Saved preview renders the stored email without any recipient unsubscribe capability.');
    nl_http_check(str_contains($response['body'],'sandbox=""')&&str_contains($response['headers']['cache-control']??'','no-store'),'Email preview is sandboxed and privately cached.');
    nl_http_check(nl_http_request($path.'/approve')['status']===405,'GET cannot approve a campaign.');
    nl_http_check(nl_http_request('/write/newsletter/2147483647')['status']===404,'Unknown editions return a real 404.');
    $draft['subject']='Reviewed HTTP subject';$draft['content_html']='<p>Reviewed HTTP body '.$suffix.'.</p>';
    $response=nl_http_request($path,array_replace($edit,$draft));
    nl_http_check(nl_http_redirect($response,$path)&&(int)newsletter_campaign($campaignId)['draft_version']===2,'Editing saves a new draft version.');
    $response=nl_http_request($path.'/approve',$staleApproval+['approve_content'=>'1','permission_ack'=>'1']);
    nl_http_check($response['status']===409&&newsletter_campaign($campaignId)['approved_revision_id']===null,'A stale rendered approval form cannot approve changed content.');
    $response=nl_http_request($path);$approval=nl_http_form($response,$path.'/approve');
    $response=nl_http_request($path.'/approve',$approval+['approve_content'=>'1']);
    nl_http_check($response['status']===422&&nl_http_count('SELECT COUNT(*) FROM newsletter_revisions WHERE campaign_id=?',[$campaignId])===0,'Approval requires the explicit permissions acknowledgement.');
    $response=nl_http_request($path.'/approve',$approval+['approve_content'=>'1','permission_ack'=>'1']);
    $campaign=newsletter_campaign($campaignId);$revisionId=(int)$campaign['approved_revision_id'];$revision=newsletter_revision($revisionId);$frozen=$revision['snapshot_json'];
    nl_http_check(nl_http_redirect($response,$path)&&$campaign['status']==='approved'&&str_contains($frozen,'Reviewed HTTP body'),'The exact saved revision is frozen through the protected approval form.');
    $response=nl_http_request($path);$schedule=nl_http_form($response,$path.'/schedule');$when=(new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i');
    $response=nl_http_request($path.'/schedule',$schedule+['scheduled_at'=>$when]);
    nl_http_check($response['status']===422&&nl_http_count('SELECT COUNT(*) FROM newsletter_deliveries WHERE campaign_id=?',[$campaignId])===0,'Scheduling requires explicit audience acknowledgement.');
    $response=nl_http_request($path.'/schedule',array_replace($schedule,['csrf'=>'bad','scheduled_at'=>$when,'audience_ack'=>'1']));
    nl_http_check($response['status']===419,'Schedule mutations require a valid session CSRF token.');
    $response=nl_http_request($path.'/schedule',$schedule+['scheduled_at'=>$when,'audience_ack'=>'1']);$campaign=newsletter_campaign($campaignId);
    nl_http_check(nl_http_redirect($response,$path)&&$campaign['status']==='scheduled'&&(int)$campaign['audience_size']===1,'Scheduling snapshots only the confirmed fixture recipient.');
    $response=nl_http_request($path.'/schedule',$schedule+['scheduled_at'=>$when,'audience_ack'=>'1']);
    nl_http_check(nl_http_redirect($response,$path)&&nl_http_count('SELECT COUNT(*) FROM newsletter_deliveries WHERE campaign_id=?',[$campaignId])===1,'A repeated scheduling POST does not duplicate the audience.');
    $response=nl_http_request($path,array_replace($edit,$draft,['draft_version'=>'2','subject'=>'Unapproved later edit']));
    nl_http_check($response['status']===409&&newsletter_revision($revisionId)['snapshot_json']===$frozen,'Scheduled content stays immutable after an attempted later save.');
    $response=nl_http_request($path.'/report');
    nl_http_check($response['status']===200&&str_contains($response['body'],'confirmed-'.$suffix.'@example.invalid')&&!str_contains($response['body'],'pending-'.$suffix.'@example.invalid'),'The private report shows exactly the frozen recipient membership.');
    nl_http_check(str_contains($response['body'],'does not mean every message reached an inbox'),'Reporting distinguishes processing from confirmed inbox delivery.');
    $response=nl_http_request($path);$pause=nl_http_form($response,$path.'/pause');
    nl_http_check(nl_http_request($path.'/pause',array_replace($pause,['csrf'=>'wrong']))['status']===419,'Pausing requires valid CSRF.');
    $response=nl_http_request($path.'/pause',$pause);
    nl_http_check(nl_http_redirect($response,$path)&&newsletter_campaign($campaignId)['status']==='paused','The Writer can pause remaining deliveries through HTTP.');
    $response=nl_http_request($path);$resume=nl_http_form($response,$path.'/resume');$response=nl_http_request($path.'/resume',$resume+['scheduled_at'=>$when]);
    nl_http_check(nl_http_redirect($response,$path)&&newsletter_campaign($campaignId)['status']==='scheduled'&&nl_http_count('SELECT COUNT(*) FROM newsletter_deliveries WHERE campaign_id=?',[$campaignId])===1,'Resuming retains the original approved revision and audience.');
    $response=nl_http_request($path);$cancel=nl_http_form($response,$path.'/cancel');
    nl_http_check(nl_http_request($path.'/cancel',$cancel)['status']===422,'Cancelling requires a separate explicit acknowledgement.');
    $response=nl_http_request($path.'/cancel',$cancel+['confirm_cancel'=>'1']);
    nl_http_check(nl_http_redirect($response,$path)&&newsletter_campaign($campaignId)['status']==='cancelled','The Writer can cancel the remaining snapshot.');
    nl_http_check(nl_http_row('SELECT status FROM newsletter_deliveries WHERE campaign_id=?',[$campaignId])['status']==='cancelled','Cancellation is reflected in the recipient report records.');
    $response=nl_http_request($path);$copy=nl_http_form($response,$path.'/duplicate');$response=nl_http_request($path.'/duplicate',$copy);
    $copied=nl_http_row('SELECT * FROM newsletter_campaigns WHERE created_by=? AND id<>? ORDER BY id DESC LIMIT 1',[$actor,$campaignId]);
    nl_http_check(nl_http_redirect($response,'/write/newsletter/'.$copied['id'])&&$copied['status']==='draft'&&$copied['approved_revision_id']===null&&(int)$copied['audience_size']===0,'Copying a locked edition starts an independent unapproved draft without recipients.');

    // Authoritative article import occurs only on the public post snapshot.
    $response=nl_http_request('/write/newsletter/new',['csrf'=>$save['csrf'],'action'=>'import','post_id'=>(string)$postIds['published']]);
    $imported=nl_http_row('SELECT * FROM newsletter_campaigns WHERE created_by=? AND source_post_id=?',[$actor,$postIds['published']]);
    nl_http_check($imported&&nl_http_redirect($response,'/write/newsletter/'.$imported['id'])&&str_contains($imported['body_html'],$base.'/my-thoughts/'),'HTTP public-article import saves a reviewable draft with the correct absolute website link.');
    $subscriberPath='/write/newsletter/subscribers/'.$confirmed;$response=nl_http_request($subscriberPath);$suppress=nl_http_form($response,$subscriberPath,null,'suppress');
    nl_http_check(nl_http_request($subscriberPath,array_replace($suppress,['csrf'=>'bad','note'=>'Fixture']))['status']===419,'Subscriber preference changes require session CSRF.');
    $response=nl_http_request($subscriberPath,$suppress+['note'=>'Synthetic manual request']);
    nl_http_check(nl_http_redirect($response,$subscriberPath)&&newsletter_subscriber($confirmed)['suppression_reason']==='manual'&&newsletter_subscriber($confirmed)['status']==='unsubscribed','Manual suppression stops newsletter subscription through the real form.');
    $response=nl_http_request($subscriberPath);$clear=nl_http_form($response,$subscriberPath,null,'clear_suppression');$response=nl_http_request($subscriberPath,$clear+['note'=>'Synthetic resolved request']);
    nl_http_check(nl_http_redirect($response,$subscriberPath)&&newsletter_subscriber($confirmed)['confirmed_at']===null&&newsletter_subscriber($confirmed)['status']==='unsubscribed','Clearing suppression never manufactures renewed consent.');
    $response=nl_http_request('/write/newsletter/subscribers?q='.rawurlencode($suffix));
    nl_http_check($response['status']===200&&str_contains($response['body'],'confirmed-'.$suffix.'@example.invalid'),'The authenticated subscriber directory supports bounded search.');
    nl_http_check(nl_http_redirect(nl_http_request($subscriberPath,null,false),'/write/login'),'Private subscriber identity is unavailable without Writer login.');

    // RFC 8058: GET is inert; fixed POST has no cookie, session or redirect.
    $oneClickSubscriber=nl_http_subscriber('one-click');$oneClickUrl=newsletter_one_click_url($oneClickSubscriber);$oneClickPath=(string)parse_url($oneClickUrl,PHP_URL_PATH).'?'.parse_url($oneClickUrl,PHP_URL_QUERY);
    $response=nl_http_request($oneClickPath,null,false);
    nl_http_check($response['status']===405&&newsletter_subscriber($oneClickSubscriber)['status']==='confirmed','Following the one-click URL as GET cannot unsubscribe.');
    nl_http_check(($response['headers']['allow']??'')==='POST'&&!isset($response['headers']['set-cookie']),'Inert one-click GET advertises POST and never starts a browser session.');
    nl_http_check(nl_http_request($oneClickPath,null,false,'HEAD')['status']===405,'HEAD also cannot mutate one-click subscription state.');
    $response=nl_http_request($oneClickPath,['List-Unsubscribe'=>'anything-else'],false);
    nl_http_check($response['status']===400&&newsletter_subscriber($oneClickSubscriber)['status']==='confirmed','The one-click endpoint accepts only the fixed RFC body.');
    $response=nl_http_request('/newsletter/one-click?token='.str_repeat('0',64),['List-Unsubscribe'=>'One-Click'],false);
    nl_http_check($response['status']===410,'An unknown capability cannot unsubscribe any recipient.');
    $response=nl_http_request($oneClickPath,['List-Unsubscribe'=>'One-Click'],false);
    nl_http_check($response['status']===200&&$response['body']==='Unsubscribed.'&&newsletter_subscriber($oneClickSubscriber)['status']==='unsubscribed','A cookie-free form-encoded POST performs exactly one unsubscription.');
    nl_http_check(!isset($response['headers']['set-cookie'])&&!isset($response['headers']['location'])&&str_contains($response['headers']['cache-control']??'','no-store'),'A successful one-click response creates no cookie or redirect and remains private.');
    $consent=(int)newsletter_subscriber($oneClickSubscriber)['consent_revision'];
    $response=nl_http_request($oneClickPath,['List-Unsubscribe'=>'One-Click'],false,'POST',true);
    nl_http_check($response['status']===200&&(int)newsletter_subscriber($oneClickSubscriber)['consent_revision']===$consent,'Repeated multipart one-click POSTs remain idempotent.');

    // Legacy human links still require an explicit, CSRF-protected confirmation.
    $human=nl_http_subscriber('human-link');$humanUrl=newsletter_unsubscribe_url($human);$humanPath=(string)parse_url($humanUrl,PHP_URL_PATH).'?'.parse_url($humanUrl,PHP_URL_QUERY);
    $response=nl_http_request($humanPath);$humanForm=nl_http_form($response,'/newsletter/unsubscribe');
    nl_http_check($response['status']===200&&newsletter_subscriber($human)['status']==='confirmed','An ordinary unsubscribe GET only opens the human confirmation form.');
    $response=nl_http_request('/newsletter/unsubscribe',$humanForm);
    nl_http_check(nl_http_redirect($response,'/newsletter')&&newsletter_subscriber($human)['status']==='unsubscribed','The legacy human unsubscribe form remains functional.');

    // Bound authenticated mutations without issuing a burst of test traffic.
    foreach([(int)floor(time()/60),(int)floor(time()/60)+1]as$window){$key=hash_hmac('sha256','newsletter-writer|'.$actor.'|'.$window,(string)app_config('app_key'));$bucketKeys[]=$key;db()->prepare("INSERT INTO journey_rate_limits(bucket_key,purpose,hits,expires_at) VALUES(?,'newsletter-writer',120,?) ON DUPLICATE KEY UPDATE hits=120")->execute([$key,date('Y-m-d H:i:s',($window+2)*60)]);}
    $response=nl_http_request('/write/newsletter/new',array_replace($save,$draft));
    nl_http_check($response['status']===429&&($response['headers']['retry-after']??'')==='60','The Writer newsletter mutation limit is enforced before another save.');
    nl_http_check(nl_http_count('SELECT COUNT(*) FROM email_outbox')===0,'The full HTTP workflow has not sent or fabricated any outbound email.');
    $response=nl_http_request('/write/logout',['csrf'=>$save['csrf']]);
    nl_http_check(nl_http_redirect($response,'/write/login')&&nl_http_redirect(nl_http_request($path.'/preview'),'/write/login'),'Newsletter previews become inaccessible again after sign-out.');
    echo "PASS: {$checks} newsletter HTTP workflow assertions\n";
}finally{
    // Delete only this random fixture's rows, respecting campaign foreign keys.
    if($actor){
        db()->prepare('DELETE FROM newsletter_events WHERE actor_id=? OR campaign_id IN (SELECT id FROM newsletter_campaigns WHERE created_by=?)')->execute([$actor,$actor]);
        db()->prepare('DELETE d FROM newsletter_deliveries d JOIN newsletter_campaigns c ON c.id=d.campaign_id WHERE c.created_by=?')->execute([$actor]);
        db()->prepare('DELETE r FROM newsletter_revisions r JOIN newsletter_campaigns c ON c.id=r.campaign_id WHERE c.created_by=?')->execute([$actor]);
        db()->prepare('DELETE FROM newsletter_campaigns WHERE created_by=?')->execute([$actor]);
    }
    foreach($subscriberIds as$id){db()->prepare('DELETE FROM newsletter_events WHERE subscriber_id=?')->execute([$id]);db()->prepare("DELETE FROM journey_audit_events WHERE entity_type='newsletter' AND entity_id=?")->execute([$id]);db()->prepare('DELETE FROM newsletter_subscribers WHERE id=?')->execute([$id]);}
    if($actor){db()->prepare('DELETE FROM posts WHERE user_id=?')->execute([$actor]);db()->prepare('DELETE FROM login_attempts WHERE email=?')->execute([$writerEmail]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$actor]);}
    foreach($bucketKeys as$key)db()->prepare('DELETE FROM journey_rate_limits WHERE bucket_key=?')->execute([$key]);
    foreach(array_keys($sessionIds)as$id)db()->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$id]);
    if(is_file($cookie))unlink($cookie);
}
