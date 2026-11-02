<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/search_review_schedule.php';
$store=[];$checks=0;$owner=7;$uid=7;$reads=0;$connection=['id'=>1,'service'=>'google_search_console','provider'=>'google','status'=>'connected','external_id'=>'subject','selected_resource'=>'sc-domain:example.invalid','connected_by'=>7,'verified_at'=>'2026-10-01 00:00:00','scopes_json'=>'["read"]'];
function sc(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function refuse(callable $fn,string $label):void {try{$fn();}catch(Throwable){sc(true,$label);return;}throw new LogicException($label);}
function comm_get(string $key,array $default=[]):array{return $GLOBALS['store'][$key]??$default;}
function comm_put(string $key,array $v):void{$GLOBALS['store'][$key]=$v;}
function comm_lock(string $key,callable $fn):mixed{return $fn();}
function comm_config():array{return ['owner'=>$GLOBALS['owner']];}
function publishing_client(string $provider):array{return ['client_id'=>'fixture','client_secret'=>$GLOBALS['client_secret']??'NEVER_RENDER_SECRET'];}
function google_workspace_connection(string $service,int $uid):array {
    $c=$GLOBALS['connection'];if(!$c||$c['status']!=='connected'||$c['connected_by']!==$uid)throw new RuntimeException('Unavailable connection.');return $c;
}
function require_auth():void{$GLOBALS['auth']=true;}
function private_response_headers():void{$GLOBALS['private']=true;}
function journeys_method(array $methods):void{sc(in_array($_SERVER['REQUEST_METHOD']??'GET',$methods,true),'Method checked.');}
function current_user():array{return ['id'=>$GLOBALS['uid']];}
function is_post():bool{return ($_SERVER['REQUEST_METHOD']??'GET')==='POST';}
function verify_csrf():void{if(($_POST['csrf']??'')!=='fixture')throw new LogicException('CSRF denied');}
function journeys_rate_limit(string $key,string $actor,int $limit,int $window):bool{return true;}
function render_write(string $view,array $data):void{$GLOBALS['rendered']=$data;}
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $path=''):string{return 'https://example.invalid'.$path;}
function asset(string $path):string{return '/assets/'.$path;}
function csrf_field():string{return '<input type="hidden" name="csrf" value="fixture">';}
$now=1793566800;$input=['enabled'=>'1','consent'=>'1','days'=>'28','cadence'=>'weekly','version'=>'0','connection'=>srs_binding($connection)];
$reader=static function(int $days,int $uid):array{
    $GLOBALS['reads']++;sc(srs_config()['state']==='reading'&&srs_config()['attempted_at']>0,'Claim persisted before network.');
    return ['days'=>$days,'current'=>['start'=>'2026-10-01','end'=>'2026-10-28'],'previous'=>['start'=>'2026-09-03','end'=>'2026-09-30'],'observed_at'=>'2026-11-01T00:00:00Z','current_sample'=>2,'previous_sample'=>1,'rows'=>[],'signals_in_sample'=>0,'note'=>'Sample only.'];
};
sc(!srs_work($now,$reader)['enabled']&&$reads===0,'Disabled defaults cause no reads.');
refuse(fn()=>srs_save(8,$input,$now),'Another Writer cannot enable owner reads.');
refuse(fn()=>srs_save(7,array_replace($input,['consent'=>'']),$now),'Explicit consent required.');
refuse(fn()=>srs_save(7,array_replace($input,['cadence'=>'hourly']),$now),'Unsupported frequency rejected.');
refuse(fn()=>srs_save(7,array_replace($input,['days'=>'7']),$now),'Unsupported window rejected.');
srs_save(7,$input,$now);$cfg=srs_config();
sc($cfg['enabled']&&$cfg['next_at']===$now+604800&&$reads===0,'Save schedules later without I/O.');
sc(!str_contains(json_encode($store),'NEVER_RENDER_SECRET'),'No plaintext credentials in schedule.');
refuse(fn()=>srs_save(7,$input,$now),'Stale save rejected.');
sc(srs_work($now+1,$reader)['checked']===0&&$reads===0,'Future interval makes no read.');
sc(srs_work($now+2,$reader,true)['checked']===1&&count(srs_config()['reports'])===1,'Owner can request a private report.');
sc(srs_work($now+3,$reader,true)['checked']===0&&$reads===1,'Immediate repeat denied.');
$fail=static function():array{throw new RuntimeException('NEVER_RENDER_SECRET');};
sc(srs_work($now+3603,$fail,true)['failed']===1,'Provider failure recorded as failure.');
sc(count(srs_config()['reports'])===1&&srs_config()['state']==='review_failed'&&!str_contains(json_encode($store),'NEVER_RENDER_SECRET'),'Failure preserves prior report and hides provider error.');
for($i=1;$i<=8;$i++)srs_work($now+3603+$i*604800,$reader);
sc(count(srs_config()['reports'])===6,'History bounded to six reports.');
$last=srs_config();$connection['selected_resource']='sc-domain:changed.invalid';
refuse(fn()=>srs_save(7,array_replace($input,['version'=>(string)$last['version']]),$now),'Property change after rendering cannot silently authorize a different target.');
sc(!srs_matches($last,7),'Changed property invalidates saved history.');
sc(srs_work($now+9*604800,$reader)['checked']===0&&!srs_config()['enabled']&&srs_config()['reports']===[],'Worker pauses changed grant and clears snapshots.');
$input['version']=(string)srs_config()['version'];$input['connection']=srs_binding($connection);srs_save(7,$input,$now);
$during=static function(int $days,int $uid)use($reader,$now):array{
    $in=['enabled'=>'','days'=>'28','cadence'=>'weekly','version'=>(string)srs_config()['version']];
    $report=$reader($days,$uid);srs_save(7,$in,$now+5);return $report;
};
sc(srs_work($now+4,$during,true)['checked']===0&&srs_config()['reports']===[]&&!srs_config()['enabled'],'Disable cancels in-flight commit.');
$input['version']=(string)srs_config()['version'];$input['connection']=srs_binding($connection);srs_save(7,$input,$now);
$changed=static function(int $days,int $uid)use($reader):array{$GLOBALS['connection']['external_id']='changed-subject';return $reader($days,$uid);};
sc(srs_work($now+6,$changed,true)['checked']===0&&srs_config()['reports']===[],'Changed identity during read discards response.');
// Disabling needs no live connection, and a former owner cannot run the new owner grant.
$connection=null;srs_save(7,['days'=>'90','cadence'=>'daily','version'=>(string)srs_config()['version']],$now);
sc(!srs_config()['enabled'],'Disconnect never prevents disabling.');
$owner=8;refuse(fn()=>srs_connection(7),'Former owner rejected.');$owner=7;
$_SERVER['REQUEST_METHOD']='GET';$before=$reads;srs_dispatch('/write/google/search-reviews');
sc($reads===$before&&!empty($GLOBALS['private'])&&!empty($GLOBALS['auth']),'GET renders only saved state with private headers.');
$uid=8;ob_start();srs_dispatch('/write/google/search-reviews');$body=ob_get_clean();sc(http_response_code()===403&&$body==='Owner access required.','Another Writer cannot view schedules.');http_response_code(200);$uid=7;
sc(!srs_dispatch('/write/unrelated'),'Unrelated route untouched.');
$_SERVER['REQUEST_METHOD']='POST';$_POST=['action'=>'save','version'=>(string)srs_config()['version'],'days'=>'28','cadence'=>'weekly'];
refuse(fn()=>srs_dispatch('/write/google/search-reviews'),'Missing CSRF rejected before save.');
$schedule=srs_config();$reports=[];$connectionMatches=false;$error='';$notice='<script>literal</script>';
ob_start();include dirname(__DIR__).'/views/write/search_reviews.php';$html=ob_get_clean();
sc(substr_count($html,'<main')===1&&substr_count($html,'<h1')===1&&!str_contains($html,'<script>literal</script>')&&str_contains($html,'&lt;script&gt;literal'),'One landmark and escaped notice.');
sc(!str_contains($html,'NEVER_RENDER_SECRET'),'No credentials rendered.');
echo "PASS: $checks scheduled search review checks.\n";
