<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/media_centre.php';
require dirname(__DIR__).'/app/meta_mcp.php';
function mm_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$initialized=false;$request=['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list'];mm_check(meta_mcp_request($request,$initialized)['error']['code']===-32002,'Cannot use tools before initialization.');
$r=meta_mcp_request(['jsonrpc'=>'2.0','id'=>2,'method'=>'initialize','params'=>['protocolVersion'=>'2026-07-28']],$initialized);mm_check($r['result']['protocolVersion']==='2026-07-28','Version negotiated.');
$r=meta_mcp_request($request,$initialized);mm_check(count($r['result']['tools'])===1&&$r['result']['tools'][0]['annotations']['readOnlyHint'],'Only the aggregate read tool is advertised.');
$called=false;$read=static function($p)use(&$called){$called=true;return ['platform'=>$p,'available'=>false];};
$call=['jsonrpc'=>'2.0','id'=>3,'method'=>'tools/call','params'=>['name'=>'meta_saved_analytics','arguments'=>['platform'=>'instagram','sql'=>'secret']]];
mm_check(isset(meta_mcp_request($call,$initialized,$read)['error'])&&!$called,'Additional arguments never reach data access.');
unset($call['params']['arguments']['sql']);mm_check(meta_mcp_request($call,$initialized,$read)['result']['structuredContent']['platform']==='instagram','Approved aggregate tool is callable.');
$history=[['account'=>'private account','basis'=>'private reference','credentials'=>'secret','messages'=>['private'],'period'=>'Current cumulative account totals','source'=>'Connected provider API','observed_at'=>'2026-10-23T12:00:00+00:00','metrics'=>['Followers'=>0]]];
$clean=meta_mcp_snapshot('instagram',$history);$encoded=json_encode($clean);mm_check(!str_contains($encoded,'private')&&!str_contains($encoded,'secret')&&$clean['metrics']->Followers===0,'Identity, free text and secrets excluded; reported zero preserved.');
mm_check(meta_mcp_snapshot('facebook',[])['available']===false,'Missing report is unavailable.');
mm_check(meta_mcp_request(['jsonrpc'=>'2.0','method'=>'notifications/initialized'],$initialized)===null,'Notifications have no response.');
echo "PASS: local Meta MCP lifecycle, read-only tool allowlist and aggregate privacy.\n";
