<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$records=[];$owner=42;$actor=42;$checks=0;$CONFIG=['mail'=>['from'=>'newsletter@example.invalid','from_name'=>'Synthetic sender','reply_to'=>'','postal_address'=>'Deployment fixture address']];
function app_config(string $key,mixed $default=null):mixed{$value=$GLOBALS['CONFIG'];foreach(explode('.',$key)as$part){if(!is_array($value)||!array_key_exists($part,$value))return $default;$value=$value[$part];}return $value;}
function comm_get(string $key,array $default=[]):array{return $GLOBALS['records'][$key]??$default;}
function comm_put(string $key,array $value):void{$GLOBALS['records'][$key]=$value;}
function comm_config():array{return ['owner'=>$GLOBALS['owner']];}
function comm_lock(string $key,callable $work):mixed{return $work();}
function base_url():string{return 'https://www.example.invalid';}
function url(string $path=''):string{return base_url().$path;}
function asset(string $path):string{return '/assets/'.$path;}
function e(string $text):string{return htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function csrf_field():string{return '<input type="hidden" name="csrf" value="fixture">';}
function flashes():array{return [];}
function require_auth():void{$GLOBALS['authenticated']=true;}
function private_response_headers():void{$GLOBALS['private']=true;}
function journeys_method(array $methods):void{if($methods!==['GET','HEAD','POST'])throw new RuntimeException('Unexpected methods');}
function current_user():array{return ['id'=>$GLOBALS['actor']];}
function is_post():bool{return false;}
function render_write(string $view,array $data):void{$GLOBALS['rendered']=$data;}
require dirname(__DIR__).'/app/newsletter.php';
function ss(bool $ok,string $message):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function ss_denied(callable $work,string $message):void{try{$work();}catch(NewsletterValidationException|NewsletterConflictException){ss(true,$message);return;}throw new RuntimeException($message);}
ss(newsletter_sender()['postal_address']==='Deployment fixture address','Initial deployments keep the configured address without any mutation.');
$snapshot=['sender'=>newsletter_sender(),'site_url'=>base_url()];newsletter_assert_snapshot_current($snapshot);
$input=['version'=>'0','postal_address'=>"Reviewed <address>\r\nPostal line",'confirm_public_footer'=>'1'];
ss_denied(fn()=>newsletter_postal_save($input,43),'Another Writer cannot change the footer.');ss(!$records,'Rejected owner cannot create a record.');
ss_denied(fn()=>newsletter_postal_save(array_replace($input,['confirm_public_footer'=>'']),42),'Explicit newsletter footer review is required.');
foreach(['version'=>['01',[],null,-1],'postal_address'=>[[],str_repeat('x',501),"bad\0address","bad\xffaddress"]]as$key=>$values)foreach($values as$value)ss_denied(fn()=>newsletter_postal_save(array_replace($input,[$key=>$value]),42),'Malformed version/address never persists.');
newsletter_postal_save($input,42);ss(newsletter_sender()['postal_address']==="Reviewed <address>\nPostal line",'Approved multiline address is normalized and used by snapshots.');
ss(count($records)===1&&$records['newsletter.sender-postal']['version']===1,'Only the single sender configuration is written.');
ss_denied(fn()=>newsletter_assert_snapshot_current($snapshot),'A changed footer invalidates previously approved sender snapshots before delivery.');
ss_denied(fn()=>newsletter_postal_save($input,42),'Stale tabs cannot overwrite the newer footer.');
$current=['sender'=>newsletter_sender(),'site_url'=>base_url()];newsletter_assert_snapshot_current($current);
newsletter_postal_save(['version'=>'1','postal_address'=>'','confirm_public_footer'=>'1'],42);ss(newsletter_sender()['postal_address']==='','An explicit blank overrides deployment fallback and blocks address-dependent approval.');
ss_denied(fn()=>newsletter_assert_snapshot_current($current),'Removing the address holds an earlier approved sender too.');
$record=$records['newsletter.sender-postal'];$owner=43;try{newsletter_sender();throw new LogicException('Cross-owner sender accepted');}catch(RuntimeException $e){ss(!($e instanceof LogicException),'A new owner cannot inherit another owner’s footer disclosure.');}$owner=42;
$actor=43;ob_start();ss(newsletter_sender_config_dispatch('/write/newsletter/sender'),'Sender route handled');$denied=ob_get_clean();ss(http_response_code()===403&&str_contains($denied,'owner access')&&!isset($rendered),'Owner-only route hides all sender fields from another Writer.');
$actor=42;http_response_code(200);ss(newsletter_sender_config_dispatch('/write/newsletter/sender')&&isset($rendered)&&$private&&$authenticated,'Owner GET uses private authenticated response.');
$sender=newsletter_sender();$senderPostalValue='<script>PRIVATE_POSTAL</script>';$senderPostalVersion=2;$error=null;ob_start();include dirname(__DIR__).'/views/write/newsletter_sender.php';$html=ob_get_clean();
ss(substr_count($html,'<h1')===1&&str_contains($html,'confirm_public_footer')&&str_contains($html,'data-no-form-assist'),'Rendered setup uses one explicit owner-reviewed form.');ss(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;PRIVATE_POSTAL'),'Address text is escaped, never executed.');ss(str_contains($html,'Saving this page sends no test message'),'Saving is separate from sending or subscriber enrollment.');
echo 'PASS: '.$checks." newsletter owner address, version, snapshot invalidation, privacy and rendering checks. No mail sent.\n";
