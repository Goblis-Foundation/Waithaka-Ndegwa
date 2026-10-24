<?php
declare(strict_types=1);

/** Real private/public forms on the runner's guarded local server. No external calls. */
function rh_env(string $primary, string $fallback): string|false { $v = getenv($primary); return $v !== false ? $v : getenv($fallback); }
$base = rtrim((string)rh_env('TEST_BASE_URL', 'WK_TEST_BASE_URL'), '/'); $parts = parse_url($base);
$host = rh_env('DB_HOST', 'WK_TEST_DB_HOST'); $port = rh_env('DB_PORT', 'WK_TEST_DB_PORT'); $name = rh_env('DB_DATABASE', 'WK_TEST_DB_NAME'); $user = rh_env('DB_USERNAME', 'WK_TEST_DB_USER'); $pass = rh_env('DB_PASSWORD', 'WK_TEST_DB_PASS');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('WK_TEST_ALLOW_WRITES') !== '1' || !is_string($name) || !preg_match('/^waithaka_test_[a-z0-9_]+_test$/D', $name) || !in_array($host, ['127.0.0.1', '::1'], true) || !is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || !is_string($user) || $user === '' || !is_string($pass) || ($parts['scheme'] ?? '') !== 'http' || ($parts['host'] ?? '') !== '127.0.0.1' || !isset($parts['port']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) { fwrite(STDERR, "Use explicit isolated testing DB_* credentials and TEST_BASE_URL=http://127.0.0.1:<port>.\n"); exit(2); }
$CONFIG = ['app_env' => 'testing', 'app_key' => getenv('APP_KEY') ?: 'local-test-key-not-for-production-use', 'base_url' => $base, 'timezone' => 'Africa/Nairobi', 'mail' => ['transport' => 'disabled'], 'db' => ['host' => $host, 'port' => (int)$port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4']];
$APP_INSTALLED = true; date_default_timezone_set('Africa/Nairobi');
foreach (['functions', 'journeys', 'operations', 'newsletter', 'reader','founder_stories'] as $module) require_once __DIR__.'/../app/'.$module.'.php';

require_once dirname(__DIR__).'/app/communication.php';
$jar=tempnam(sys_get_temp_dir(),'wk-program-http-');if($jar===false)throw new RuntimeException('Cannot allocate fixture jar.');$sessionIds=[];$actor=0;$checks=0;
function ph_check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function ph_request(string $path,?array $fields=null):array{
 global $base,$jar,$sessionIds;if(!preg_match('~^/write(?:/|\?|$)~',$path)||preg_match('/[\r\n]/',$path))throw new RuntimeException('Only loopback Writer routes allowed.');
 $headers=[];$ch=curl_init($base.$path);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTP,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_HEADERFUNCTION=>static function($c,string $line)use(&$headers,&$sessionIds):int{$pair=explode(':',$line,2);if(count($pair)===2){$key=strtolower(trim($pair[0]));$headers[$key]=trim($pair[1]);if($key==='set-cookie'&&preg_match('/^PHPSESSID=([A-Za-z0-9,-]+)/',trim($pair[1]),$m))$sessionIds[$m[1]]=true;}return strlen($line);}]);
 if($fields!==null)curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($fields)]);
 $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);if($body===false)throw new RuntimeException('Loopback request failed.');return compact('body','status','headers');
}
function ph_form(string $body,string $action):array{
 $doc=new DOMDocument();@$doc->loadHTML($body,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xp=new DOMXPath($doc);$query=$action==='login'?'//form[@action="'.htmlspecialchars(url('/write/login'),ENT_QUOTES).'"]':'//form[.//input[@name="action" and @value="'.$action.'"]]';$form=$xp->query($query)->item(0);if(!$form)throw new RuntimeException('Fixture form not found: '.$action);$out=[];foreach($xp->query('.//input[@type="hidden"]',$form)as$input)$out[$input->getAttribute('name')]=$input->getAttribute('value');return $out;
}
function ph_expire_sessions():void{global $sessionIds;foreach(array_keys($sessionIds)as$id)db()->prepare('DELETE FROM app_sessions WHERE id=?')->execute([$id]);}
try{
 $email='program-http-'.bin2hex(random_bytes(8)).'@example.invalid';$password=bin2hex(random_bytes(20));db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Program HTTP fixture',$email,password_hash($password,PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
 ph_check(ph_request('/write/program')['status']===302,'Anonymous program is private');
 $login=ph_request('/write/login');ph_check(ph_request('/write/login',ph_form($login['body'],'login')+['email'=>$email,'password'=>$password,'trust_device'=>'1'])['status']===302,'Remembered fixture sign-in');
 $page=ph_request('/write/program?date=2026-11-02');ph_check($page['status']===200&&str_contains($page['body'],'Daily program'),'Daily program renders');ph_check(str_contains($page['headers']['cache-control']??'','no-store'),'Private program not cacheable');
 $save=ph_form($page['body'],'save');$save=array_replace($save,['title'=>'REVIEWED PRIVATE PROGRAM','start'=>'2026-11-02T09:00','end'=>'2026-11-02T09:30','timezone'=>'Africa/Nairobi','kind'=>'project','location'=>'Nairobi','reminder'=>'15']);
 ph_check(ph_request('/write/program?date=2026-11-02',$save)['status']===302,'Actual program form saves');$page=ph_request('/write/program/2026-11-02');ph_check($page['status']===200&&str_contains($page['body'],'REVIEWED PRIVATE PROGRAM'),'Dated program shows saved item');ph_check(str_contains($page['body'],'/write/program/2026-11-02'),'Printed reference keeps day');
 $jarText=file_get_contents($jar);ph_check(str_contains($jarText,'wn_trust'),'Persistent trust cookie issued');
 $trustCookie=null;foreach(explode("\n",$jarText)as$line){$parts=explode("\t",$line);if(count($parts)===7&&$parts[5]==='wn_trust')$trustCookie=$parts;}ph_check($trustCookie!==null&&$trustCookie[1]==='FALSE'&&(int)$trustCookie[4]>time()+170*86400,'Trust cookie has host-only persistent expiry');
 $before=ph_form($page['body'],'save');ph_expire_sessions();$restored=ph_request('/write/program?date=2026-11-02');ph_check($restored['status']===200&&str_contains($restored['body'],'REVIEWED PRIVATE PROGRAM'),'Ordinary session expiry does not require another login');$after=ph_form($restored['body'],'save');ph_check($before['csrf']===$after['csrf'],'Restoration preserves valid device CSRF forms');
 $trustRows=array_values(array_filter(comm_records('trusted',2000),static fn($r)=>(int)$r['user']===$actor&&empty($r['revoked'])));$currentTrust=$trustRows[0]['id'];$revoke=ph_form($restored['body'],'save');
 ph_check(ph_request('/write/security',['csrf'=>$revoke['csrf'],'action'=>'revoke_session','id'=>$currentTrust])['status']===302,'Device can be revoked through actual security form');ph_check(ph_request('/write/program')['status']===302,'Revoked active device cannot use the portal');
 $login=ph_request('/write/login');ph_request('/write/login',ph_form($login['body'],'login')+['email'=>$email,'password'=>$password,'trust_device'=>'1']);$restored=ph_request('/write/program');
 $csrf=ph_form($restored['body'],'save');ph_check(ph_request('/write/logout',['csrf'=>$csrf['csrf']])['status']===302,'Explicit sign-out');ph_check(ph_request('/write/program')['status']===302,'Sign-out cannot auto-restore');
 $login=ph_request('/write/login');ph_request('/write/login',ph_form($login['body'],'login')+['email'=>$email,'password'=>$password]);ph_expire_sessions();ph_check(ph_request('/write/program')['status']===302,'Shared-device opt-out is respected');
 echo 'Program and trusted-device HTTP checks: '.$checks.' passed.'.PHP_EOL;
}finally{
 ph_expire_sessions();
 if($actor){foreach(['trusted','session']as$prefix)foreach(comm_records($prefix,2000)as$r)if((int)($r['user']??0)===$actor)db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['communication.'.$prefix.'.'.$r['id']]);db()->prepare('DELETE FROM settings WHERE setting_key LIKE ?')->execute(['communication.planner.item.'.$actor.'.%']);db()->prepare('DELETE FROM users WHERE id=?')->execute([$actor]);}
 if(is_file($jar))unlink($jar);
}
