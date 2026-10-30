<?php
declare(strict_types=1);
function base_url():string{return 'https://www.waithakandegwa.com';}
function url(string $p=''):string{return base_url().'/'.ltrim($p,'/');}
function publishing_connection(string $service):array{return ['status'=>'connected','connected_by'=>7,'selected_resource'=>$service==='google_analytics'?'properties/123':($service==='google_search_console'?'sc-domain:waithakandegwa.com':'channel-fixture')];}
function publishing_access_token(array $c):string{return 'fixture';}
require dirname(__DIR__).'/app/providers/http.php';require dirname(__DIR__).'/app/google_workspace.php';require dirname(__DIR__).'/app/google_mcp.php';
$checks=0;function gw_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function gw_reject(callable $f,string $label):void{try{$f();}catch(InvalidArgumentException){gw_check(true,$label);return;}throw new RuntimeException($label);}
gw_check(google_workspace_identity('google_meet',['sub'=>'account-7','email'=>'fixture@example.invalid'],[])===[['id'=>'account-7','name'=>'fixture@example.invalid','type'=>'meet_account']],'Meet confirmation uses the authenticated Google subject without listing meetings');
gw_check(google_workspace_date('2028-02-29','')==='2028-02-29','Leap dates valid');
gw_check(google_workspace_connection('google_analytics',7)['selected_resource']==='properties/123','Selected Google property is available to its connected Writer.');
gw_reject(fn()=>google_workspace_connection('google_analytics',8),'Another Writer cannot acquire a saved provider connection.');
gw_reject(fn()=>google_workspace_connection('google_drive',0),'Unknown user cannot acquire saved Drive access.');
foreach(['2026-02-29','2026-13-01','yesterday',[],"2026-01-01\n"]as$v)gw_reject(fn()=>google_workspace_date($v,''),'Reject invalid date');
$available=[['id'=>'mine'],['id'=>'shared']];gw_check(google_calendar_selection($available,['mine','mine'])===['mine'],'Deduplicate selection');
foreach([['other-account'],[[]],str_repeat('x',20)]as$v)gw_reject(fn()=>google_calendar_selection($available,$v),'Reject foreign calendar selection');
foreach(['https://other.invalid/','https://www.waithakandegwa.com/write','https://www.waithakandegwa.com/?token=secret','https://www.waithakandegwa.com/resources/%2e%2e/write','http://www.waithakandegwa.com/','https://evil@www.waithakandegwa.com/','https://www.waithakandegwa.com/start-here#secret']as$v)gw_reject(fn()=>google_workspace_public_url($v),'Private and foreign URLs reject before network');
gw_check(google_workspace_public_url(base_url().'/resources/test')===base_url().'/resources/test','Public content permitted');
gw_reject(fn()=>google_workspace_link('https://calendar.google.com.evil.invalid/x',['calendar.google.com']),'Booking host exact');
$calls=[];$transport=function($method,$url,$headers,$body=null)use(&$calls){$calls[]=[$method,$url,$body];return ['data'=>['rows'=>[['dimensionValues'=>[['value'=>'Kenya']],'metricValues'=>[['value'=>'0'],['value'=>'2'],['value'=>'3'],['value'=>'0.5']]]]]];};
$r=google_workspace_report('ga4-countries','2026-01-01','2026-01-28',$transport,7);
gw_reject(fn()=>google_workspace_report('ga4-countries','2026-01-01','2026-01-28',$transport,8),'Another Writer cannot run reports using the owner connection.');gw_check($r['rows'][0]===['Kenya','0','2','3','0.5'],'Zero and metric ordering preserved');gw_check(str_contains($calls[0][1],'/properties/123:runReport')&&$calls[0][2]['dimensions'][0]['name']==='country','Read only approved property and fixed dimension');
// Exact 28-day Search Console read-only review, paired by public canonical URL.
$public=base_url();
$searchRows=function($kind)use($public):array {
    $row=static fn(string $path,int $clicks,int $impressions,float $ctr,float $position):array=>
        ['keys'=>[$path],'clicks'=>$clicks,'impressions'=>$impressions,'ctr'=>$ctr,'position'=>$position];
    if($kind==='now')return [
        $row($public.'/my-thoughts/learning',20,300,.0667,8.2),
        $row($public.'/resources/problem-interview-worksheet',1,200,.005,8.0),
        $row($public.'/startup-journeys/venture-a',4,85,.047,9.0),
        $row($public.'/my-thoughts/progress',23,120,.192,6.0),
        $row($public.'/write/notes',60,300,.20,5.0),
        $row('https://other.example.org/resources/example',50,200,.25,5.0)
    ];
    return [
        $row($public.'/my-thoughts/learning',50,320,.156,7.8),
        $row($public.'/resources/problem-interview-worksheet',1,210,.005,8.2),
        $row($public.'/my-thoughts/progress',8,80,.10,6.8),
        $row($public.'/my-thoughts/older-page',150,1000,.15,4.0)
    ];
};
$reviewCalls=[];
$reviewTransport=function($method,$endpoint,$headers,$body=null)use(&$reviewCalls,$searchRows):array{
    $reviewCalls[]=[$method,$endpoint,$body];
    if($method!=='POST'||!str_contains($endpoint,'/searchAnalytics/query'))throw new RuntimeException('Unexpected provider endpoint.');
    return ['data'=>['rows'=>$searchRows(($body['startDate']??'')==='2026-09-28'?'now':'before')]];
};
$searchReview=google_search_review(28,$reviewTransport,'2026-10-28');
gw_check(count($reviewCalls)===2,'Review requires exactly two read-only Search Analytics requests.');
gw_check($searchReview['current']===['start'=>'2026-09-28','end'=>'2026-10-25']
    &&$searchReview['previous']===['start'=>'2026-08-31','end'=>'2026-09-27'],'Equal length, non-overlapping finalized reporting periods.');
foreach($reviewCalls as $call)gw_check(($call[2]['dimensions']??null)===['page']
    &&($call[2]['dataState']??'')==='final'&&($call[2]['rowLimit']??null)===1000
    &&str_contains($call[1],'sc-domain%3Awaithakandegwa.com'),'Review reads fixed site, dimensions and finalized data.');
gw_check($searchReview['current_sample']===4&&$searchReview['previous_sample']===4,'Reject external and private page URLs without counting as valid results.');
gw_check(count($searchReview['rows'])===4,'Four classification signals for comparable and absent data.');
$labels=array_column($searchReview['rows'],'signal');
gw_check($labels===['Clicks declined','CTR worth reviewing','No comparable prior row','Clicks increased'],'Signals are deterministic and prioritized.');
gw_check($searchReview['rows'][2]['prior_clicks']===null,'Missing prior top-row observation is null, not fabricated zero.');
gw_check(!str_contains(json_encode($searchReview),'/write/notes')&&!str_contains(json_encode($searchReview),'other.example.org')
    &&!str_contains(json_encode($searchReview),'older-page'),'No private, foreign or prior-only rows exposed.');
gw_reject(fn()=>google_search_review(7,$reviewTransport,'2026-10-28'),'Unsupported cadence fails before API access.');
gw_check(google_search_review_compare(['rows'=>[]],['rows'=>[['https://www.waithakandegwa.com/my-thoughts/older',12,100,.12,8]]])['rows']===[],
    'Absent current top-row records never become a zero-click decline.');
$reviewView=file_get_contents(dirname(__DIR__).'/views/write/google_workspace.php');
gw_check(str_contains($reviewView,'id="search-review"')&&str_contains($reviewView,'name="review_days"')
    &&str_contains($reviewView,'No review signals were found'),'Private read-only control, bounded cadence and empty-state message exist.');

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

gw_check(google_gemini_generation_config('gemini-3.8-flash')['thinkingConfig']['thinkingLevel']==='low','Interactive 3.8 Flash uses supported low-latency thinking level');
gw_check(!isset(google_gemini_generation_config('gemini-2.5-flash')['thinkingConfig']),'Do not send unsupported thinkingLevel to older models');
gw_check(google_gemini_generation_config('gemini-3.8-flash')['maxOutputTokens']===2400,'Bounded Gemini generation output');

require dirname(__DIR__).'/app/wise_api.php';
$wiseProfile=['profile'=>'123','probe'=>['profile'=>'123']];
$wiseSub=['id'=>'72195556-e5cb-495e-a010-b37a4f2a3043','trigger_on'=>'balances#credit','delivery'=>['version'=>'4.0.0','url'=>url('/webhooks/wise')],'scope'=>['domain'=>'profile','id'=>123]];
$wiseCalls=[];$wiseSend=function($method,$path,$body=null)use(&$wiseCalls,$wiseSub){$wiseCalls[]=[$method,$path,$body];return $method==='GET'&&str_ends_with($path,'/subscriptions')?[$wiseSub]:$wiseSub;};
$connected=wise_api_connect_notifications($wiseProfile,$wiseSend);gw_check($connected['webhook_enabled']&&count($wiseCalls)===2&&$wiseCalls[0][0]==='GET'&&$wiseCalls[1][0]==='GET','Existing subscription reused and independently verified without POST.');
$wiseCalls=[];$wiseCreate=function($method,$path,$body=null)use(&$wiseCalls,$wiseSub){$wiseCalls[]=[$method,$path,$body];return $method==='GET'&&str_ends_with($path,'/subscriptions')?[]:$wiseSub;};
gw_check(wise_api_connect_notifications($wiseProfile,$wiseCreate)['subscription']===$wiseSub['id']&&count($wiseCalls)===3&&$wiseCalls[1][2]['trigger_on']==='balances#credit','Create only the exact incoming-credit subscription and read it back.');
foreach(['profile','callback','event','schema']as$bad){$row=$wiseSub;if($bad==='profile')$row['scope']['id']=456;if($bad==='callback')$row['delivery']['url']='https://other.invalid/';if($bad==='event')$row['trigger_on']='transfers#state-change';if($bad==='schema')$row['delivery']['version']='5.0.0';gw_check(!wise_api_subscription_match($row,'123',url('/webhooks/wise')),'Reject mismatched subscription '.$bad);}

$identityAccount=['google_sub'=>'stable-google-subject'];$identityWho=['sub'=>'stable-google-subject','email'=>'isaac@ubunifuinstitute.com','email_verified'=>true,'name'=>'Waithaka Ndegwa'];
gw_check(google_calendar_identity_label($identityAccount,$identityWho,'isaac@ubunifuinstitute.com')==='Waithaka Ndegwa · isaac@ubunifuinstitute.com','Verified renamed email retains the same immutable Google subject.');
foreach([array_replace($identityWho,['sub'=>'another-account']),array_replace($identityWho,['email'=>'isaac@ubunifuinstitute.co.ke']),array_replace($identityWho,['email_verified'=>false]),array_replace($identityWho,['email_verified'=>'true']),array_replace($identityWho,['name'=>"Bad\nname"])]as$wrong){$blocked=false;try{google_calendar_identity_label($identityAccount,$wrong,'isaac@ubunifuinstitute.com');}catch(RuntimeException){$blocked=true;}gw_check($blocked,'Different subject, old address, unverified email or malformed name cannot relabel access.');}
gw_reject(fn()=>google_calendar_identity_label($identityAccount,$identityWho,"isaac@ubunifuinstitute.com\r\nBcc: other@example.invalid"),'Reject header-bearing identity input.');
gw_check(mb_strlen(google_calendar_identity_label($identityAccount,array_replace($identityWho,['name'=>str_repeat('名',300)]),'isaac@ubunifuinstitute.com'))<=255,'Renamed labels fit the existing database field without splitting Unicode.');
