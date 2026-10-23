<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
require __DIR__.'/prepare.php';
$fixtureSettings=wk_test_database_settings();
$fixtureDatabase=(string)getenv('DB_DATABASE');wk_test_assert_database_name($fixtureDatabase);
if(getenv('WK_TEST_DB_NAME')!==$fixtureDatabase||getenv('DB_HOST')!==$fixtureSettings['host']
    ||getenv('DB_PORT')!==(string)$fixtureSettings['port']||getenv('DB_USERNAME')!==$fixtureSettings['user']
    ||getenv('DB_PASSWORD')!==$fixtureSettings['pass']||getenv('APP_READY')!=='1'){
    throw new RuntimeException('Use the explicit isolated database environment from tests/run.php.');
}
$fixtureConfiguration=getenv('APP_CONFIG_FILE');
if($fixtureConfiguration===false||!is_file($fixtureConfiguration)
    ||realpath($fixtureConfiguration)===realpath(dirname(__DIR__).'/app/config.php')
    ||preg_match('/\A<\?php\s+return\s+\[\]\s*;\s*\z/',(string)file_get_contents($fixtureConfiguration))!==1){
    throw new RuntimeException('APP_CONFIG_FILE must point to an isolated empty fixture configuration.');
}
$base=rtrim((string)getenv('TEST_BASE_URL'),'/');$parsed=parse_url($base);
if(($parsed['scheme']??'')!=='http'||($parsed['host']??'')!=='127.0.0.1'
    ||!isset($parsed['port'])||$parsed['port']<1||$parsed['port']>65535
    ||isset($parsed['user'])||isset($parsed['pass'])||isset($parsed['query'])||isset($parsed['fragment'])||isset($parsed['path']))exit(2);
require dirname(__DIR__).'/app/bootstrap.php';
if(app_config('app_env')!=='testing'||!installed()||base_url()!==$base
    ||app_config('db.host')!==$fixtureSettings['host']||(int)app_config('db.port')!==$fixtureSettings['port']
    ||app_config('db.name')!==$fixtureDatabase||app_config('db.user')!==$fixtureSettings['user']
    ||app_config('db.pass')!==$fixtureSettings['pass']){
    throw new RuntimeException('The loaded configuration does not match the isolated fixture environment.');
}
if(db()->query('SELECT DATABASE()')->fetchColumn()!==$fixtureDatabase)throw new RuntimeException('The connected database is not the explicit fixture database.');
$checks=0;$prefix='growth-http-'.bin2hex(random_bytes(5));$cookie=tempnam(sys_get_temp_dir(),'wk-growth-cookie-');
if($cookie===false)throw new RuntimeException('Cannot create the isolated HTTP cookie jar.');
function growth_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function growth_request(string $path,?array $post=null):array{
    global$base,$cookie;$headers=[];$c=curl_init($base.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>20,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_COOKIEFILE=>$cookie,CURLOPT_COOKIEJAR=>$cookie,CURLOPT_HEADERFUNCTION=>static function($handle,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);}]);
    if($post!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);$body=curl_exec($c);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);if($body===false)throw new RuntimeException('Local HTTP fixture failed.');return compact('headers','body','status');
}
function growth_csrf(array $response):string{if(!preg_match('/name="csrf"\s+value="([a-f0-9]{64})"/',$response['body'],$match))throw new RuntimeException('Missing CSRF field.');return $match[1];}
$password=bin2hex(random_bytes(18));$email=$prefix.'@example.invalid';
db()->prepare('INSERT INTO users(name,email,password_hash)VALUES(?,?,?)')->execute(['Growth HTTP fixture',$email,password_hash($password,PASSWORD_DEFAULT)]);$user=(int)db()->lastInsertId();
db()->prepare("INSERT INTO posts(user_id,title,slug,standfirst,content_html,status,published_at,writer_note,source_notes)VALUES(?,?,?,?,?,'published',DATE_SUB(NOW(),INTERVAL 1 DAY),?,?)")->execute([$user,'A public test article',$prefix,'A test introduction.','<h2>Question</h2><p>First section.</p><h2>Experiment</h2><p>Second section.</p><h2>Decision</h2><p>Third section.</p>','PRIVATE_WRITER_NOTE','PRIVATE_SOURCE_NOTES']);$post=(int)db()->lastInsertId();
$prior=db()->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('google.ga4','google.analytics_enabled')")->fetchAll(PDO::FETCH_KEY_PAIR);
try{
    db()->exec("UPDATE settings SET setting_value='G-RN0YXC2WCH' WHERE setting_key='google.ga4'");db()->exec("UPDATE settings SET setting_value='1' WHERE setting_key='google.analytics_enabled'");
    foreach(['/','/about','/my-thoughts','/my-thoughts/'.$prefix,'/startup-journeys','/founders','/start-here','/resources','/watch','/privacy','/cookies','/legal']as$route){
        $r=growth_request($route);growth_check($r['status']===200,'Public route renders: '.$route);
        growth_check(substr_count($r['body'],'src="https://www.googletagmanager.com/gtag/js?id=G-RN0YXC2WCH"')===1,'Exactly one supplied tag is rendered: '.$route);
        growth_check(preg_match('~<head>\s*<!-- Google tag \(gtag\.js\)~',$r['body'])===1,'The shared tag starts immediately after head: '.$route);
        growth_check(!str_contains($r['body'],'G-WCKVHN823V'),'The old property does not appear: '.$route);
        growth_check(!str_contains($r['body'],'PRIVATE_WRITER_NOTE')&&!str_contains($r['body'],'PRIVATE_SOURCE_NOTES'),'Private editorial notes are excluded: '.$route);
    }
    foreach(['/newsletter'=>200,'/contact'=>200,'/contribute'=>200,'/search?q=PRIVATE_SEARCH'=>200,'/newsletter/preferences?token=PRIVATE_TOKEN'=>410,'/founders/follow-up?token=PRIVATE_TOKEN'=>410,'/newsletter/confirm?token=PRIVATE_TOKEN'=>410,'/write/login'=>200]as$route=>$expectedStatus){
        $r=growth_request($route);growth_check($r['status']===$expectedStatus,'Sensitive route returns its expected response: '.$route);
        growth_check(!str_contains($r['body'],'googletagmanager.com'),'Sensitive routes do not load the tag: '.$route);
    }
    $r=growth_request('/?utm_source=instagram&utm_medium=social&utm_campaign=post-'.$post.'&token=PRIVATE_QUERY&email=PRIVATE_EMAIL');
    preg_match('~<script>\s*(.*?)</script>~s',$r['body'],$script);growth_check(isset($script[1])&&!str_contains($script[1],'PRIVATE_QUERY')&&!str_contains($script[1],'PRIVATE_EMAIL')&&str_contains($script[1],'utm_source=instagram'),'Only approved campaign identifiers reach the tag.');
    $about=growth_request('/about');preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s',$about['body'],$schemas);
    $profilePages=[];foreach($schemas[1]as$json){$value=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(($value['@type']??'')==='ProfilePage')$profilePages[]=$value;}
    growth_check(count($profilePages)===1,'About includes exactly one ProfilePage schema.');
    growth_check(!array_key_exists('dateModified',$profilePages[0]),'About does not invent a modification timestamp.');
    $sitemap=growth_request('/sitemap.xml');$xml=simplexml_load_string($sitemap['body']);growth_check($sitemap['status']===200&&$xml!==false,'The public sitemap is valid XML.');
    $locations=[];foreach($xml->url as$item)$locations[]=(string)$item->loc;growth_check(count($locations)===count(array_unique($locations)),'Sitemap canonical entries are not duplicated.');
    growth_check(in_array($base.'/start-here',$locations,true)&&in_array($base.'/resources',$locations,true),'New public collections are discoverable in the sitemap.');
    $home=growth_request('/');growth_check(str_contains($home['body'],'srcset=')&&str_contains($home['body'],'480w'),'Actual smaller public photography is offered to responsive browsers.');
    db()->exec("UPDATE settings SET setting_value='' WHERE setting_key='google.ga4'");growth_check(!str_contains(growth_request('/')['body'],'googletagmanager.com'),'A blank setting has no fallback tag.');
    db()->exec("UPDATE settings SET setting_value='G-RN0YXC2WCH' WHERE setting_key='google.ga4'");db()->exec("UPDATE settings SET setting_value='0' WHERE setting_key='google.analytics_enabled'");growth_check(!str_contains(growth_request('/')['body'],'googletagmanager.com'),'The explicit off switch disables the shared tag.');
    $r=growth_request('/write/account');growth_check($r['status']===302,'Account controls require authentication.');
    foreach(['/write/studio','/write/capture','/write/assist','/write/media-centre']as$privateRoute){$r=growth_request($privateRoute);growth_check($r['status']===302&&str_ends_with($r['headers']['location']??'','/write/login'),'Studio endpoints require authentication: '.$privateRoute);}
    $r=growth_request('/write/impact');growth_check($r['status']===302&&str_ends_with($r['headers']['location']??'','/write/login'),'Reporting connections require Writer authentication.');
    $r=growth_request('/write/campaigns');growth_check($r['status']===302&&str_ends_with($r['headers']['location']??'','/write/login'),'Campaigns require Writer authentication.');
    $login=growth_request('/write/login');$r=growth_request('/write/login',['csrf'=>growth_csrf($login),'email'=>$email,'password'=>$password]);growth_check($r['status']===302,'The synthetic Writer can authenticate.');
    $centre=growth_request('/write/media-centre');growth_check($centre['status']===200&&str_contains($centre['headers']['cache-control']??'','no-store')&&!str_contains($centre['body'],'googletagmanager.com'),'Media centre is private and has no analytics tracker.');
    foreach(['LinkedIn','Facebook','Instagram','X','YouTube','TikTok']as$platform)growth_check(str_contains($centre['body'],'<h3>'.$platform.'</h3>'),'Media centre covers '.$platform);
    growth_check(substr_count($centre['body'],'>Connect</button>')===6,'Unconnected dashboard presents exactly six primary Connect buttons.');
    $mediaCsrf=growth_csrf($centre);
    $r=growth_request('/write/media-centre/report',['csrf'=>'invalid','action'=>'record']);growth_check($r['status']===419,'Social report writes require CSRF.');
    $r=growth_request('/write/media-centre/report',['csrf'=>$mediaCsrf,'action'=>'record','platform'=>'facebook','account'=>'PRIVATE_METRIC_ACCOUNT','start'=>'2026-01-01','end'=>'2026-01-28','followers'=>'0','views'=>'1234','basis'=>'Synthetic native dashboard fixture']);growth_check($r['status']===302,'Native analytics can be recorded through the protected form.');
    $centre=growth_request('/write/media-centre');growth_check(str_contains($centre['body'],'PRIVATE_METRIC_ACCOUNT')&&str_contains($centre['body'],'Owner-recorded native report')&&str_contains($centre['body'],'1,234'),'Recorded metrics retain identity, provenance and actual values.');
    $r=growth_request('/write/media-centre/report',['csrf'=>$mediaCsrf,'action'=>'refresh','platform'=>'x']);growth_check($r['status']===302,'Unconnected API report fails recoverably.');
    $centre=growth_request('/write/media-centre');growth_check(str_contains($centre['body'],'PRIVATE_METRIC_ACCOUNT'),'Failed report requests retain the last native report.');
    $public=growth_request('/about');growth_check(str_contains($public['body'],'10,150')&&str_contains($public['body'],'Indirect beneficiaries')&&!str_contains($public['body'],'PRIVATE_METRIC_ACCOUNT'),'Employment target and beneficiary definitions are public; account reports stay private.');
    growth_check(!str_contains($public['body'],'>goblisgroup.com')&&!str_contains($public['body'],'opens in a new tab'),'Organisation links mask raw domains and remove visible new-tab wording.');
    $studio=growth_request('/write/studio');growth_check($studio['status']===200&&str_contains($studio['headers']['cache-control']??'','no-store')&&!str_contains($studio['body'],'googletagmanager.com'),'Studio is private and excludes analytics.');
    $studioCsrf=growth_csrf($studio);
    $r=growth_request('/write/capture',['csrf'=>'invalid','idea'=>'A private idea']);growth_check($r['status']===419,'Capture requires CSRF.');
    $r=growth_request('/write/capture',['csrf'=>$studioCsrf,'title'=>$prefix.' idea','idea'=>"PRIVATE_CAPTURE <script>alert(1)</script>\nA second observation."]);growth_check($r['status']===302&&preg_match('~/write/edit/(\d+)$~',$r['headers']['location']??'',$captureMatch)===1,'Capture creates a real saved draft.');
    $capturedId=(int)$captureMatch[1];$captured=writer_post_by_id($capturedId);growth_check($captured['status']==='draft'&&str_contains($captured['content_html'],'&lt;script&gt;'),'Captured text is escaped and remains private.');
    $public=growth_request('/my-thoughts');growth_check(!str_contains($public['body'],'PRIVATE_CAPTURE'),'Captured ideas never appear in public archive.');
    $r=growth_request('/write/assist',['csrf'=>$studioCsrf,'selected_text'=>'Test notes','mode'=>'outline']);growth_check($r['status']===200&&str_contains($r['body'],'Confirm that this selected text'),'AI requires explicit request consent.');
    $r=growth_request('/write/assist',['csrf'=>$studioCsrf,'selected_text'=>'Test notes','mode'=>'outline','ai_consent'=>'1']);growth_check($r['status']===200&&str_contains($r['body'],'AI is not configured'),'Missing API configuration produces honest recoverable feedback.');
    db()->prepare('DELETE FROM posts WHERE id=?')->execute([$capturedId]);
    $r=growth_request('/media-centre');growth_check($r['status']===301&&str_ends_with($r['headers']['location']??'','/contact'),'Former public media URL redirects to contact; media centre belongs to Writer.');
    growth_check(!in_array($base.'/media-centre',$locations,true),'Private media centre is not advertised in sitemap.');
    $r=growth_request('/write/impact');growth_check($r['status']===200&&str_contains($r['headers']['cache-control']??'','no-store')&&str_contains($r['headers']['x-robots-tag']??'','noindex')&&!str_contains($r['body'],'googletagmanager.com'),'Reporting controls are private and render without analytics.');
    $impactCsrf=growth_csrf($r);
    $r=growth_request('/write/impact',['action'=>'save','csrf'=>'invalid']);growth_check($r['status']===419,'Reporting writes require CSRF.');
    $r=growth_request('/write/impact',['action'=>'save','csrf'=>$impactCsrf,'goblis_group_url'=>'http://127.0.0.1/internal']);growth_check($r['status']===302,'Unsafe reporting connection is rejected through the form.');
    growth_check(!setting('impact.url.goblis_group',''),'Unsafe reporting URL was not persisted.');
    foreach(['/','/about'] as $goalRoute){$r=growth_request($goalRoute);growth_check($r['status']===200&&str_contains($r['body'],'goals-2030')&&str_contains($r['body'],'30,000')&&str_contains($r['body'],'2,030')&&str_contains($r['body'],'Awaiting source data'),'Goals distinguish targets and unavailable progress: '.$goalRoute);}
    foreach(['/','/about'] as $logoRoute){
        $r=growth_request($logoRoute);$document=new DOMDocument();@$document->loadHTML($r['body']);$xpath=new DOMXPath($document);
        foreach(impact_organisations()as$org){$nodes=$xpath->query('//div[contains(@class,"impact-groups")]//a[@href="'.$org['url'].'"]');growth_check($nodes->length===1,'Each organisation has one website link under the introduction.');$node=$nodes->item(0);growth_check($node->getAttribute('target')==='_blank'&&str_contains($node->getAttribute('rel'),'noopener')&&$node->getElementsByTagName('img')->length===1,'Organisation logo links open a new tab safely.');}
    }
    $r=growth_request('/');growth_check(str_contains($r['body'],'Writer sign in')&&str_contains($r['body'],'/write/login'),'Public footer makes the Writer login discoverable.');
    $r=growth_request('/write/campaigns/'.$post);growth_check($r['status']===200&&str_contains($r['headers']['cache-control']??'','no-store')&&!str_contains($r['body'],'googletagmanager.com'),'Campaign plans render privately without analytics.');$csrf=growth_csrf($r);
    $r=growth_request('/write/campaigns/'.$post,['csrf'=>'invalid','version'=>'0','objective'=>'read','success_measure'=>'Readership']);growth_check($r['status']===419,'Campaign saves enforce CSRF.');
    $r=growth_request('/write/campaigns/'.$post,['csrf'=>$csrf,'version'=>'0','objective'=>'subscribe','success_measure'=>'Confirmed subscription count']);growth_check($r['status']===302&&campaign_plan($post)['objective']==='subscribe','Campaign objectives persist through the real Writer form.');
    $r=growth_request('/write/campaigns/'.$post);growth_check(str_contains($r['body'],'utm_source=instagram')&&str_contains($r['body'],'utm_source=tiktok'),'Professional channels have campaign links in the real UI.');
    $account=growth_request('/write/account');growth_check($account['status']===200&&str_contains($account['headers']['cache-control']??'','no-store'),'Account form renders privately.');$accountCsrf=growth_csrf($account);
    $newPassword=bin2hex(random_bytes(18));
    $r=growth_request('/write/account',['csrf'=>'invalid','current_password'=>$password,'new_password'=>$newPassword,'confirm_password'=>$newPassword]);growth_check($r['status']===419,'Password change enforces CSRF.');
    $r=growth_request('/write/account',['csrf'=>$accountCsrf,'current_password'=>'wrong','new_password'=>$newPassword,'confirm_password'=>$newPassword]);growth_check($r['status']===302,'Incorrect current password is rejected.');
    $q=db()->prepare('SELECT password_hash FROM users WHERE id=?');$q->execute([$user]);growth_check(password_verify($password,$q->fetchColumn()),'Wrong current password does not modify the account.');
    $r=growth_request('/write/account',['csrf'=>$accountCsrf,'current_password'=>$password,'new_password'=>$newPassword,'confirm_password'=>'different']);growth_check($r['status']===302,'Mismatched new passwords are rejected.');
    $primaryCookie=$cookie;$secondaryCookie=tempnam(sys_get_temp_dir(),'wk-secondary-cookie-');$cookie=$secondaryCookie;
    $login=growth_request('/write/login');$r=growth_request('/write/login',['csrf'=>growth_csrf($login),'email'=>$email,'password'=>$password]);growth_check($r['status']===302,'Second fixture session can sign in before password rotation.');$cookie=$primaryCookie;
    $r=growth_request('/write/account',['csrf'=>$accountCsrf,'current_password'=>$password,'new_password'=>$newPassword,'confirm_password'=>$newPassword]);growth_check($r['status']===302&&str_ends_with($r['headers']['location']??'','/write/login'),'Password change signs out the current session.');
    $q->execute([$user]);growth_check(password_verify($newPassword,$q->fetchColumn()),'New password hash is saved.');
    $cookie=$secondaryCookie;$r=growth_request('/write/account');growth_check($r['status']===302,'Password rotation invalidates the other Writer session.');$cookie=$primaryCookie;
    $login=growth_request('/write/login');$r=growth_request('/write/login',['csrf'=>growth_csrf($login),'email'=>$email,'password'=>$password]);growth_check(str_ends_with($r['headers']['location']??'','/write/login'),'Old password no longer authenticates.');
    $login=growth_request('/write/login');$r=growth_request('/write/login',['csrf'=>growth_csrf($login),'email'=>$email,'password'=>$newPassword]);growth_check(str_ends_with($r['headers']['location']??'','/write'),'New password authenticates.');
    $r=growth_request('/write/account');growth_check($r['status']===200,'The refreshed session works after password rotation.');
    echo "Growth and Google tag HTTP checks passed: $checks\n";
}finally{
    if(isset($secondaryCookie)&&is_file($secondaryCookie))unlink($secondaryCookie);
    db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['auth.password_epoch.'.$user]);
    foreach($prior as$key=>$value)db()->prepare('UPDATE settings SET setting_value=? WHERE setting_key=?')->execute([$value,$key]);
    db()->prepare('DELETE FROM posts WHERE id=?')->execute([$post]);db()->prepare('DELETE FROM users WHERE id=?')->execute([$user]);if(is_file($cookie))unlink($cookie);
}
