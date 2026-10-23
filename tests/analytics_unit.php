<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$checks=0;$settings=[];
function setting(string $key,mixed $default=null):mixed{return array_key_exists($key,$GLOBALS['settings'])?$GLOBALS['settings'][$key]:$default;}
function base_url():string{return 'https://www.waithakandegwa.com';}
function url(string $path=''):string{return base_url().'/'.ltrim($path,'/');}
function path_only():string{return '/';}
function analytics_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
require dirname(__DIR__).'/app/analytics.php';
analytics_check(analytics_measurement_id()==='G-RN0YXC2WCH','Fresh installations use the owner-supplied measurement property.');
$settings=['google.ga4'=>''];analytics_check(analytics_measurement_id()==='','Blank settings stay disabled.');
$settings=['google.ga4'=>'G-RN0YXC2WCH','google.analytics_enabled'=>'0'];analytics_check(analytics_measurement_id()==='','The explicit switch turns the tag off.');
foreach(['bad','G-123<script>','G-ABC\n',[],null]as$id){$settings=['google.ga4'=>is_array($id)?'invalid':$id];analytics_check(analytics_measurement_id()==='','Invalid destinations never render a tag.');}
$settings=[];
foreach(['/write','/write/login','/consent/private','/payments/status','/contact','/contribute','/search','/newsletter','/newsletter/confirm','/newsletter/unsubscribe','/newsletter/one-click','/newsletter/preferences','/newsletter/preferences/request','/founders/submit','/founders/follow-up','/webhooks/ses']as$path)analytics_check(analytics_measurement_id([],false,$path)==='','Sensitive paths must never include the Google tag: '.$path);
foreach(['/','/about','/my-thoughts','/my-thoughts/real-article','/startup-journeys','/founders','/resources','/watch','/start-here']as$path)analytics_check(analytics_measurement_id([],false,$path)==='G-RN0YXC2WCH','Public editorial pages share the supplied tag: '.$path);
analytics_check(analytics_measurement_id(['robots'=>'noindex,follow'])==='','Non-indexable content does not collect reader analytics.');
analytics_check(analytics_measurement_id([],true)==='','Private views explicitly suppress measurement.');
$_GET['preview']='1';analytics_check(analytics_measurement_id()==='','Preview query parameters suppress measurement.');$_GET=[];
$valid=['utm_source'=>'instagram','utm_medium'=>'social','utm_campaign'=>'journey-12-week-3','utm_content'=>'short'];
analytics_check(analytics_campaign_parameters($valid)===$valid,'Recognised editorial campaign codes survive.');
foreach(['email@example.com','+254700000000','Jane Doe','secret_token_0123456789','G-RN0YXC2WCH',[],str_repeat('x',800)]as$value){$bad=$valid;$bad['utm_campaign']=$value;analytics_check(analytics_campaign_parameters($bad)===[],'Unknown or identifying campaign values must be dropped.');}
$result=analytics_page_location(base_url().'/my-thoughts/hello?token=secret&page=2',$valid+['email'=>'reader@example.com','q'=>'private search','token'=>'private-token','utm_term'=>'identifying words']);
analytics_check($result===base_url().'/my-thoughts/hello?page=2&utm_source=instagram&utm_medium=social&utm_campaign=journey-12-week-3&utm_content=short','Only pagination and controlled campaign labels reach measurement.');
analytics_check(analytics_page_location('https://wrong.invalid/hello?token=private#secret')===base_url().'/hello','Measurement destinations use the canonical origin and no fragments.');
analytics_check(analytics_campaign_url('/my-thoughts/hello','newsletter','email','post-4','article')===base_url().'/my-thoughts/hello?utm_source=newsletter&utm_medium=email&utm_campaign=post-4&utm_content=article','Campaign links use public destinations and stable IDs.');
foreach(['https://outside.invalid/x','//outside.invalid/x','/write','/newsletter/confirm','/my-thoughts/x?token=private','/my-thoughts/x#fragment']as$path){try{analytics_campaign_url($path,'newsletter','email','post-4');throw new LogicException('Unsafe destination accepted.');}catch(InvalidArgumentException $e){$checks++;}}
echo "Analytics policy checks passed: $checks\n";
