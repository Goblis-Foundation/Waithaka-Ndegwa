<?php
declare(strict_types=1);
require_once __DIR__.'/../app/microsoft_workspace.php';
function publishing_json(mixed $v): string{return json_encode($v,JSON_THROW_ON_ERROR);}
function check(bool $ok,string $label): void {if(!$ok)throw new RuntimeException($label);}
function rejected(callable $fn,string $label):void {try{$fn();}catch(MicrosoftWorkspaceException){return;}throw new RuntimeException($label);}
$store=[];$reads=[];$refreshes=0;
function comm_get(string $key,array $default=[]):array{return $GLOBALS['store'][$key]??$default;}
function comm_put(string $key,array $value):void{$GLOBALS['store'][$key]=$value;}
function comm_lock(string $key,callable $work):mixed{return $work();}
function publishing_bearer(string $token):array{return ['Authorization: Bearer '.$token];}
function meetings_token_request(string $provider,array $grant):array{$GLOBALS['refreshes']++;check($provider==='microsoft'&&$grant['grant_type']==='refresh_token','Only delegated refresh.');return ['access_token'=>'refreshed-fixture','scope'=>'User.Read Calendars.Read','expires_at'=>time()+3600];}
function publishing_http(string $method,string $url,array $headers):array{
 check($method==='GET'&&str_starts_with($url,'https://graph.microsoft.com/v1.0/me/'),'Only fixed own-account Graph reads.');$GLOBALS['reads'][]=$url;
 return ['data'=>$GLOBALS['response']??['value'=>[]]];
}
$accounts=ms_accounts();check(count($accounts)===3&&count(array_unique($accounts))===3,'Distinct target identities.');
foreach($accounts as$slot=>$email){$who=ms_identity(['id'=>'fixture-'.$slot,'mail'=>strtoupper($email)],$slot);check($who['email']===$email,'Exact target matched case-insensitively.');rejected(fn()=>ms_identity(['id'=>'other','mail'=>'other@example.com'],$slot),'Wrong account rejected.');rejected(fn()=>ms_identity(['mail'=>$email],$slot),'Missing identity rejected.');}
rejected(fn()=>ms_identity(['id'=>'x','mail'=>[],'userPrincipalName'=>[]],'personal'),'Malformed identities rejected.');rejected(fn()=>ms_key('../personal'),'Invalid account slot rejected.');
check(ms_has_scope(['https://graph.microsoft.com/Calendars.Read'],'calendars.read'),'Qualified grants recognized.');check(!ms_has_scope(['Mail.ReadWrite'],'Mail.ReadBasic'),'Unreturned grant not inferred.');rejected(fn()=>ms_granted([]),'Missing actual grants rejected.');
check(!in_array('Mail.Send',ms_scopes(),true)&&!in_array('Files.ReadWrite.All',ms_scopes(),true),'No sending or tenant-wide write grant.');
foreach(ms_features()as$key=>$d){$q=ms_query($key);$p=parse_url($q['url']);parse_str($p['query'],$params);check($p['host']==='graph.microsoft.com'&&(int)$params['$top']===20,'Bounded fixed endpoint.');check(!preg_match('/body|content|attachment/i',$params['$select']),'No mail bodies, attachments or file content.');if($key==='calendar')check(strtotime($params['endDateTime'])-strtotime($params['startDateTime'])===604800,'Seven-day agenda.');}
rejected(fn()=>ms_query('https://evil.example'),'Arbitrary endpoint rejected.');check(ms_value(['emailAddress'=>['address'=>'test@example.com']])==='test@example.com','Sender address rendered.');check(ms_value(['dateTime'=>'2026-10-25T09:00:00','timeZone'=>'UTC'])==='Sun 25 Oct 2026, 12:00 EAT','Nairobi dates.');
$state=str_repeat('a',64);$_SESSION=['ms_workspace_oauth'=>['state_hash'=>hash('sha256',$state),'uid'=>7,'expires'=>time()+600,'slot'=>'personal']];check(ms_consume($state,7)['slot']==='personal','Owner-bound state consumed.');rejected(fn()=>ms_consume($state,7),'No state replay.');
foreach([['uid'=>8,'expires'=>time()+600],['uid'=>7,'expires'=>time()-1]]as$bad){$_SESSION=['ms_workspace_oauth'=>['state_hash'=>hash('sha256',$state)]+$bad];rejected(fn()=>ms_consume($state,7),'Other owner or expired state rejected.');}
$_SESSION=['ms_workspace_oauth'=>['state_hash'=>hash('sha256',$state),'uid'=>7,'expires'=>time()+600]];rejected(fn()=>ms_consume(str_repeat('b',64),7),'Wrong state rejected.');
$record=['owner'=>7,'status'=>'connected','identity'=>['id'=>'fixture','email'=>$accounts['personal']],'scopes'=>ms_scopes(),'token'=>['access_token'=>'fixture','expires_at'=>time()+3600]];comm_put(ms_key('personal'),$record);
check(ms_record('personal',8)===[],'Other owner cannot read grant.');rejected(fn()=>ms_token('group',7),'Other account does not inherit access.');
$response=['value'=>[['subject'=>'Fixture meeting','start'=>['dateTime'=>'2026-10-25T09:00:00','timeZone'=>'UTC'],'end'=>['dateTime'=>'2026-10-25T10:00:00','timeZone'=>'UTC'],'private-extra'=>'not exposed']]];
$report=ms_read('personal','calendar',7);check(count($report['rows'])===1&&!isset($report['rows'][0]['private-extra']),'Only approved projection returned.');
$record['scopes']=['User.Read'];comm_put(ms_key('personal'),$record);$count=count($reads);rejected(fn()=>ms_read('personal','mail',7),'Missing grant rejected.');check(count($reads)===$count,'Missing grant makes no data request.');
$record['token']['expires_at']=time()-1;$record['token']['refresh_token']='fixture-refresh';comm_put(ms_key('personal'),$record);check(ms_token('personal',7)==='refreshed-fixture'&&$refreshes===1,'Expired token refreshed.');check(ms_record('personal',7)['token']['refresh_token']==='fixture-refresh','Omitted refresh token retained.');
$record['status']='pending_confirmation';comm_put(ms_key('personal'),$record);rejected(fn()=>ms_token('personal',7),'Unconfirmed identity cannot read.');
$interval=['start'=>['dateTime'=>'2026-10-25T09:00:00'],'end'=>['dateTime'=>'2026-10-25T10:00:00'],'showAs'=>'busy'];
check(ms_busy_rows(['value'=>[$interval]])===[[1792918800,1792922400]],'Busy interval normalized to UTC.');check(ms_busy_rows(['value'=>[array_replace($interval,['showAs'=>'free']),array_replace($interval,['isCancelled'=>true])]])===[],'Free/cancelled events excluded.');
rejected(fn()=>ms_busy_rows(['value'=>[$interval],'@odata.nextLink'=>'https://example.invalid']),'Incomplete availability fails closed.');rejected(fn()=>ms_busy_rows(['value'=>[['start'=>[],'end'=>[]]]]),'Malformed availability fails closed.');
check(ms_revision(['token'=>'a'])!==ms_revision(['token'=>'b']),'Grant revisions invalidate stale confirmation.');
echo "Microsoft identity, OAuth state, refresh, projection and booking conflict checks passed.\n";
