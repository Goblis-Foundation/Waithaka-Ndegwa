<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
function ac_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function impact_sources():array{return ['goblis_group'=>'Goblis Group','ubunifu'=>'Ubunifu','afritrace'=>'AfriTrace','goblis_foundation'=>'Foundation'];}
function url(string $path=''):string{return 'https://www.example.org/'.ltrim($path,'/');}
function asset(string $path):string{return '/assets/'.$path;}
function csrf_token():string{return 'fixture-csrf';}
function csrf_field():string{return '<input type="hidden" name="csrf" value="fixture-csrf">';}
function path_only():string{return '/write/activation';}
function e(string $value):string{return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function app_config(string $key,mixed $default=null):mixed{return $key==='worker_enabled'?false:$default;}
function comm_config():array{return ['owner'=>42];}
function current_user():array{return ['id'=>$GLOBALS['fixture_owner']??42];}
function require_auth():void{$GLOBALS['did_auth']=true;}
function private_response_headers():void{$GLOBALS['did_private_headers']=true;}
function journeys_method(array $methods):void{ac_check($methods===['GET','HEAD'],'Activation should be read-only.');}
function publishing_connection(string $service):?array{return null;}
function integration_hub_configured(string $name):bool{return false;}
function setting(string $key,mixed $default=null):mixed{return $default;}
function newsletter_sender():array{return ['from'=>'','name'=>'','reply_to'=>'','postal_address'=>''];}
function email_transport_ready():bool{return false;}
function impact_snapshot(string $name):array{return [];}
function google_calendar_accounts(int $owner):array{return [];}
function reconciliation_items():array{return array_fill(0,33,['id'=>'synthetic']);}
function render_write(string $view,array $data):void{$GLOBALS['rendered_view']=$view;$GLOBALS['rendered_data']=$data;}
require dirname(__DIR__).'/app/activation_centre.php';

function fitness_client_fingerprint(array $client):string{return hash('sha256',json_encode($client,JSON_THROW_ON_ERROR));}
$syntheticClient=['client_id'=>'fixture-client','client_secret'=>'SECRET_MUST_NOT_RENDER','dedicated_project'=>true];
$syntheticConnection=['status'=>'connected','connected_by'=>42,'selected_resource'=>'PRIVATE_TARGET','metadata_json'=>'{}','scopes_json'=>'["threads_basic","threads_content_publish"]'];
ac_check(activation_connection_state($syntheticConnection,42,'threads')==='connected','Threads needs exact owner and publishing scopes.');
ac_check(activation_connection_state($syntheticConnection,9,'threads')==='other_owner','Another Writer grant cannot become owner-ready.');
$declared=$syntheticConnection;$declared['metadata_json']='{"owner_declared":true}';ac_check(activation_connection_state($declared,42,'discord')==='assisted','An owner-declared label is assisted, not verified webhook access.');
$noScope=$syntheticConnection;$noScope['scopes_json']='[]';ac_check(activation_connection_state($noScope,42,'threads')==='permissions_missing','Login alone does not authorize publishing.');
$grant=['owner'=>42,'status'=>'connected','client'=>fitness_client_fingerprint($syntheticClient),'tokens'=>['access_token'=>'SECRET_MUST_NOT_RENDER']];
ac_check(activation_activity_state(42,'google_health',$grant,$syntheticClient)==='connected','Only the same configured dedicated client is confirmed.');
$changed=$syntheticClient;$changed['client_secret']='changed';ac_check(activation_activity_state(42,'google_health',$grant,$changed)==='reconnect','Changed health clients require reconnect.');
ac_check(activation_activity_state(9,'strava',$grant,$syntheticClient)==='other_owner','Health ownership never crosses Writers.');
$connectionRows=activation_connection_rows(['threads'=>'connected','signal'=>'assisted','google_health'=>'connected','samsung_health'=>'imported','unsafe'=>'SECRET_MUST_NOT_RENDER']);
ac_check(count($connectionRows)===9&&!str_contains(json_encode($connectionRows),'SECRET_MUST_NOT_RENDER'),'Nine exact services expose only allowlisted labels and next steps.');
$empty=activation_assess([]);
ac_check(count($empty)===7&&array_column($empty,'issue')===[70,71,72,73,74,75,76],'All seven acceptance gates must remain ordered and present.');
ac_check(str_contains($empty[4]['observed'],'incomplete')&&!str_contains($empty[4]['observed'],'0 approved'),'Unknown impact must remain unavailable, not numeric zero.');
ac_check(str_contains($empty[2]['observed'],'incomplete')&&!str_contains($empty[2]['observed'],'0/3'),'Unknown merchant inventory is not zero confirmed providers.');
$secret='DO-NOT-EXPOSE-SYNTHETIC-SECRET';
$facts=['services'=>['facebook_profile'=>'connected','linkedin'=>'connected','x'=>'disconnected','tiktok_read'=>'connected',
  'unsafe_credentials'=>$secret],'finance'=>['mpesa'=>'configured_live','pesapal'=>'configured_nonlive','paypal'=>'not_configured','token'=>$secret],
 'sender'=>['from_valid'=>true,'reply_valid'=>true,'name_present'=>true,'postal_present'=>false,'transport_ready'=>true,'mail_address'=>$secret],
 'impact'=>['goblis_group'=>'approved_snapshot','ubunifu'=>'no_approved_report','afritrace'=>'withdrawn','goblis_foundation'=>'source_unavailable'],
 'calendars'=>['connected'=>7,'pending'=>1,'other'=>0],'delivery_worker'=>false];
$rows=activation_assess($facts);
ac_check(str_contains($rows[0]['observed'],'saved')&&str_contains($rows[0]['observed'],'does not verify'),'Identity confirmation must not be described as WhatsApp activation.');
ac_check(str_contains($rows[1]['observed'],'2/3')&&str_contains($rows[1]['observed'],'permissions'),'Social count is a saved-connection count, not delivery proof.');
ac_check(str_contains($rows[2]['observed'],'1/3')&&str_contains($rows[2]['observed'],'settlement remains unverified'),'Live merchant credentials must not be settlement.');
ac_check(str_contains($rows[3]['observed'],'do not release subscriber mail'),'Missing sender postal address blocks delivery acceptance.');
ac_check(str_contains($rows[4]['observed'],'1/4')&&str_contains($rows[4]['observed'],'verification'),'Impact snapshot count is separate from an actual metric.');
ac_check(str_contains($rows[5]['observed'],'7 saved')&&str_contains($rows[5]['observed'],'1 awaiting'),'Calendar counts are evidence of saved connection states only.');
ac_check(!str_contains(json_encode($rows,JSON_THROW_ON_ERROR),$secret),'No credentials, mail addresses or remote IDs may enter the output.');

$activationRows=$rows;$activationObservedAt='2026-10-29T05:00:00+00:00';$activationWorker=false;$activationWorkAreas=33;
ob_start();include dirname(__DIR__).'/views/write/activation.php';$html=(string)ob_get_clean();
ac_check(substr_count($html,'<h1')===1&&substr_count($html,'class="activation-card"')===7,'Accessible rendered workspace must have one H1 and seven issue cards.');
ac_check(str_contains($html,'issues/70')&&str_contains($html,'issues/76')&&str_contains($html,'/write/monthly-roundup'),'Issue and local setup links must be available.');
ac_check(!str_contains($html,$secret),'Rendered view must not show supplied secret-like fields.');
ac_check(str_contains($html,'Paused in config')&&str_contains($html,'33'),'Delivery configuration and complete preserved register remain separate.');

$GLOBALS['fixture_owner']=9;
ob_start();$handled=activation_dispatch('/write/activation');$forbidden=ob_get_clean();
ac_check($handled&&http_response_code()===403&&str_contains($forbidden,'Owner access required.')&&!isset($GLOBALS['rendered_view']),'Non-owner cannot inspect the activation centre.');
http_response_code(200);$GLOBALS['fixture_owner']=42;
ac_check(!activation_dispatch('/write/unrelated'),'Other routes remain unchanged.');
$handled=activation_dispatch('/write/activation');
ac_check($handled&&($GLOBALS['rendered_view']??'')==='activation'&&count($GLOBALS['rendered_data']['activationRows']??[])===7,'Owner can view read-only source-safe local activation status.');
ac_check(!empty($GLOBALS['did_auth'])&&!empty($GLOBALS['did_private_headers']),'Protected route enforces authentication and private headers.');
$writeTitle='Private workspace';$content='<main id="synthetic">Fixture</main>';
$writerShellUser=['id'=>9,'name'=>'Other writer'];ob_start();include dirname(__DIR__).'/views/write/layout.php';$otherMenu=(string)ob_get_clean();
ac_check(!str_contains($otherMenu,'href="https://www.example.org/write/activation"'),'Non-owner navigation must not link to an owner-only activation route.');
$writerShellUser=['id'=>42,'name'=>'Configured owner'];ob_start();include dirname(__DIR__).'/views/write/layout.php';$ownerMenu=(string)ob_get_clean();
ac_check(str_contains($ownerMenu,'href="https://www.example.org/write/activation"'),'Owner navigation must retain activation access.');
echo "PASS: seven source-aware acceptance gates, output privacy, rendered layout, no-drop count and owner-only GET boundaries.\n";
