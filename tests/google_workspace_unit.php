<?php
declare(strict_types=1);
function base_url():string{return 'https://www.waithakandegwa.com';}
function url(string $p=''):string{return base_url().'/'.ltrim($p,'/');}
function publishing_connection(string $service):array{return ['status'=>'connected','selected_resource'=>$service==='google_analytics'?'properties/123':($service==='google_search_console'?'sc-domain:waithakandegwa.com':'channel-fixture')];}
function publishing_access_token(array $c):string{return 'fixture';}
function publishing_bearer(string $s):array{return ['Authorization: Bearer '.$s];}
require dirname(__DIR__).'/app/providers/http.php';require dirname(__DIR__).'/app/google_workspace.php';require dirname(__DIR__).'/app/google_mcp.php';
$checks=0;function gw_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function gw_reject(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException){gw_check(true,$label);return;}throw new RuntimeException($label);}
gw_check(google_workspace_date('2028-02-29','')==='2028-02-29','Leap dates valid');
foreach(['2026-02-29','2026-13-01','yesterday',[],"2026-01-01\n"]as$v)gw_reject(fn()=>google_workspace_date($v,''),'Reject invalid date');
$available=[['id'=>'mine'],['id'=>'shared']];gw_check(google_calendar_selection($available,['mine','mine'])===['mine'],'Deduplicate selection');
foreach([['other-account'],[[]],str_repeat('x',20)]as$v)gw_reject(fn()=>google_calendar_selection($available,$v),'Reject foreign calendar selection');
foreach(['https://other.invalid/','https://www.waithakandegwa.com/write','https://www.waithakandegwa.com/?token=secret','https://www.waithakandegwa.com/resources/%2e%2e/write','http://www.waithakandegwa.com/','https://evil@www.waithakandegwa.com/','https://www.waithakandegwa.com/start-here#secret']as$v)gw_reject(fn()=>google_workspace_public_url($v),'Private and foreign URLs reject before network');
gw_check(google_workspace_public_url(base_url().'/resources/test')===base_url().'/resources/test','Public content permitted');
gw_reject(fn()=>google_workspace_link('https://calendar.google.com.evil.invalid/x',['calendar.google.com']),'Booking host exact');
$calls=[];$transport=function($method,$url,$headers,$body=null)use(&$calls){$calls[]=[$method,$url,$body];return ['data'=>['rows'=>[['dimensionValues'=>[['value'=>'Kenya']],'metricValues'=>[['value'=>'0'],['value'=>'2'],['value'=>'3'],['value'=>'0.5']]]]]];};
$r=google_workspace_report('ga4-countries','2026-01-01','2026-01-28',$transport);gw_check($r['rows'][0]===['Kenya','0','2','3','0.5'],'Zero and metric ordering preserved');gw_check(str_contains($calls[0][1],'/properties/123:runReport')&&$calls[0][2]['dimensions'][0]['name']==='country','Read only approved property and fixed dimension');
gw_reject(fn()=>google_workspace_report('arbitrary-sql','2026-01-01','2026-01-28',$transport),'Unsupported reports reject');
gw_reject(fn()=>google_workspace_report('ga4-countries','2026-01-28','2026-01-01',$transport),'Reversed range rejects');
$clean=google_mcp_snapshot('ga4-countries',$r+['credentials'=>'secret','calendar'=>'private']);gw_check(!str_contains(json_encode($clean),'secret')&&!str_contains(json_encode($clean),'private')&&$clean['rows'][0][1]===0,'MCP strips metadata and preserves zero');
gw_reject(fn()=>google_mcp_snapshot('gsc-queries',[]),'MCP excludes search query text');
$initialized=false;$call=['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'];gw_check(google_mcp_request($call,$initialized)['error']['code']===-32002,'MCP initialize required');google_mcp_request(['jsonrpc'=>'2.0','id'=>2,'method'=>'initialize'],$initialized);gw_check(google_mcp_request($call,$initialized)['result']['tools'][0]['annotations']['readOnlyHint'],'MCP read-only tool');
$bad=['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'google_saved_analytics','arguments'=>['report'=>'ga4-countries','token'=>'secret']]];gw_check(google_mcp_request($bad,$initialized)['error']['code']===-32602,'MCP rejects extra arguments');
echo "Google workspace policy checks passed: $checks\n";

$priorZone=date_default_timezone_get();
foreach(['Africa/Nairobi','America/New_York','UTC']as$zone){
 date_default_timezone_set($zone);
 gw_check(google_workspace_previous_period('2026-09-23','2026-10-20')===['start'=>'2026-08-26','end'=>'2026-09-22'],'Comparison is adjacent and equal-length in every server timezone.');
 gw_check(google_workspace_shift_date('2026-10-30',1)==='2026-10-31','Agenda includes the whole selected end date.');
 gw_check(google_workspace_previous_period('2028-03-01','2028-03-01')===['start'=>'2028-02-29','end'=>'2028-02-29'],'Leap-day comparison remains one day.');
}
date_default_timezone_set($priorZone);

// Remote diagnostics must use fixed, allowlisted text, never provider messages or metadata.
foreach(['SERVICE_DISABLED'=>'API is disabled','ACCESS_TOKEN_SCOPE_INSUFFICIENT'=>'missing a required permission','ORG_RESTRICTION_VIOLATION'=>'organization policy','RATE_LIMIT_EXCEEDED'=>'quota or rate limit']as$reason=>$expected){
    $raw=json_encode(['error'=>['message'=>'fixture-private-token','details'=>[['reason'=>$reason,'metadata'=>['secret'=>'fixture-private-token']]]]]);
    $message=publishing_google_denial_message($raw);gw_check(str_contains($message,$expected),'Safe diagnostic for '.$reason);gw_check(!str_contains($message,'fixture-private-token'),'Never echo remote secrets');
}
gw_check(str_contains(publishing_google_denial_message('not json'),'provider denied'),'Malformed body safe fallback');
gw_check(str_contains(publishing_google_denial_message('{"error":{"errors":[{"reason":"insufficientPermissions"}]}}'),'missing a required permission'),'Legacy Google reason mapped');
gw_check(!str_contains(publishing_google_denial_message('{"error":{"message":"fixture-private-token","details":[{"reason":"fixture-private-token"}]}}'),'fixture-private-token'),'Unknown codes never echoed');
