<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('WK_TEST_ALLOW_WRITES')!=='1')throw new RuntimeException('Explicit disposable database permission required.');
require __DIR__.'/prepare.php';$name='waithaka_test_presence_'.bin2hex(random_bytes(6)).'_test';$settings=wk_test_database_settings();wk_test_prepare($name,false);
$dir=sys_get_temp_dir().'/wn-presence-http-'.bin2hex(random_bytes(6));if(!mkdir($dir,0700))throw new RuntimeException('Fixture directory unavailable.');
file_put_contents($dir.'/config.php',"<?php\nreturn [];\n");chmod($dir.'/config.php',0600);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$message);if(!$socket)throw new RuntimeException('Loopback port unavailable.');$address=stream_socket_get_name($socket,false);fclose($socket);$port=(int)substr(strrchr($address,':'),1);$base='http://127.0.0.1:'.$port;
$env=[];foreach(['PATH','HOME','TMPDIR','TEMP','PHP_INI_SCAN_DIR','PHPRC']as$key)if(is_string($v=getenv($key)))$env[$key]=$v;
$env+=['APP_CONFIG_FILE'=>$dir.'/config.php','APP_ENV'=>'testing','APP_READY'=>'1','APP_URL'=>$base,'APP_KEY'=>'local-test-key-not-for-production-use','APP_TIMEZONE'=>'Africa/Nairobi','APP_FORCE_CANONICAL'=>'0','APP_ALLOWED_HOSTS'=>'127.0.0.1','TRUSTED_PROXY_CIDRS'=>'','SESSION_DRIVER'=>'database','MAIL_TRANSPORT'=>'disabled','MEDIA_DRIVER'=>'local','WORKER_ENABLED'=>'0','DB_HOST'=>$settings['host'],'DB_PORT'=>(string)$settings['port'],'DB_DATABASE'=>$name,'DB_USERNAME'=>$settings['user'],'DB_PASSWORD'=>$settings['pass'],'DB_SSL_CA'=>''];
$process=null;$n=0;
function vh_assert(bool $ok,string $why):void{global $n;$n++;if(!$ok)throw new RuntimeException($why);}
function vh_call(string $path,string $jar,array $input=[],?string $origin=null,bool $post=false):array{
 global $base;$ch=curl_init($base.$path);$headers=['Accept: application/json,text/html'];if($origin!==null){$headers[]='Origin: '.$origin;$headers[]='Sec-Fetch-Site: same-origin';}
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>8,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_HTTPHEADER=>$headers]);if($post){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($input));}
 $body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);return [$code,is_string($body)?$body:''];
}
function vh_token(string $jar):string{[$code,$html]=vh_call('/',$jar);vh_assert($code===200,'Public fixture page loads.');vh_assert(preg_match('/data-presence-csrf="([a-f0-9]{64})"/',$html,$m)===1,'The public widget supplies a session-bound token.');return $m[1];}
function vh_presence(string $jar,string $csrf,string $tab,int $seq,string $state='visible',string $consent='analytics',?string $origin=null):array{global $base;return vh_call('/api/visitor-presence',$jar,['csrf'=>$csrf,'tab'=>$tab,'sequence'=>$seq,'state'=>$state,'consent'=>$consent],$origin??$base,true);}
function vh_now(array $response,int $wanted):void{vh_assert($response[0]===200,'Presence response is successful.');$p=json_decode($response[1],true,16,JSON_THROW_ON_ERROR);vh_assert($p['now']===$wanted,'Actual stored browser count matches.');vh_assert(!array_key_exists('visitors',$p)&&!array_key_exists('tab',$p),'Public response never exposes browser identifiers.');}
try{
 $process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,'-t',dirname(__DIR__),__DIR__.'/010_security_router.php'],[0=>['file','/dev/null','r'],1=>['file',$dir.'/server.log','a'],2=>['file',$dir.'/server.log','a']],$pipes,dirname(__DIR__),$env,['bypass_shell'=>true]);vh_assert(is_resource($process),'Isolated server starts.');
 $ready=false;for($i=0;$i<50;$i++){if(!proc_get_status($process)['running'])break;[$code,$body]=vh_call('/healthz',$dir.'/probe.cookie');if($code===200&&$body==='{"ok":true}'){$ready=true;break;}usleep(100000);}vh_assert($ready,'Isolated application is ready.');
 $a=$dir.'/a.cookie';$b=$dir.'/b.cookie';$ca=vh_token($a);$cb=vh_token($b);$ta=str_repeat('a',32);$tb=str_repeat('b',32);$tc=str_repeat('c',32);
 [$code,$body]=vh_call('/api/visitor-statistics',$a);vh_assert($code===200&&json_decode($body,true)['now']===0,'Page loading and polling do not invent visitors.');
 vh_assert(vh_presence($a,'wrong',$ta,1)[0]===419,'Missing or wrong CSRF is rejected.');vh_assert(vh_presence($a,$ca,$ta,1,'visible','analytics','https://foreign.example')[0]===403,'Cross-origin heartbeat rejected.');vh_assert(vh_presence($a,$ca,$ta,1,'visible','necessary')[0]===403,'No tracking without analytics consent.');
 vh_now(vh_presence($a,$ca,$ta,1),1);vh_now(vh_presence($a,$ca,$ta,1),1);vh_now(vh_presence($a,$ca,$tb,1),1);vh_now(vh_presence($b,$cb,$tc,1),2);
 vh_now(vh_presence($a,$ca,$ta,3,'left','necessary'),2);vh_now(vh_presence($a,$ca,$tb,2,'left'),1);vh_now(vh_presence($a,$ca,$ta,2),1);vh_now(vh_presence($b,$cb,$tc,2,'left'),0);
 echo 'PASS: '.$n." live-presence HTTP assertions through the actual router, session cookies, CSRF, consent, multi-tab deduplication and leaving. No production traffic or provider requests.\n";
}finally{
 if(is_resource($process)){proc_terminate($process);for($i=0;$i<20&&proc_get_status($process)['running'];$i++)usleep(50000);if(proc_get_status($process)['running'])proc_terminate($process,9);proc_close($process);}
 foreach(glob($dir.'/*')?:[]as$file)if(is_file($file))unlink($file);if(is_dir($dir))rmdir($dir);
}
