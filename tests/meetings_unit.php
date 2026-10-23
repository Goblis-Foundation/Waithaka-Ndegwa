<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/meetings.php';
$checks=0;function mt_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++;}
function mt_reject(string $link,string $p):void{try{meetings_link($link,$p);}catch(InvalidArgumentException){$GLOBALS['checks']++;return;}throw new RuntimeException('Unsafe meeting link accepted');}
mt_check(meetings_link('https://us02web.zoom.us/j/123?pwd=participant','zoom')==='https://us02web.zoom.us/j/123?pwd=participant','Participant links work');
mt_check(meetings_link('https://teams.live.com/meet/123','microsoft')!=='','Personal Teams link supported');
foreach(['https://zoom.us.evil.test/j/1','https://evil@zoom.us/j/1','http://zoom.us/j/1','https://zoom.us:443/j/1','https://zoom.us/s/1','https://zoom.us/j/1?zak=secret','https://zoom.us/j/1#secret',"https://zoom.us/j/1\n"]as$v)mt_reject($v,'zoom');
mt_reject('https://notcalendly.com/x','calendly');mt_reject('https://sub.calendly.com/x','calendly');
$requests=[];$fixture=function($method,$url,$headers)use(&$requests){$requests[]=[$method,$url];return ['data'=>['value'=>[['subject'=>'Private test','start'=>['dateTime'=>'2026-10-23T12:00:00','timeZone'=>'UTC'],'onlineMeeting'=>['joinUrl'=>'https://teams.microsoft.com/l/meetup-join/test']],['subject'=>'Cancelled','isCancelled'=>true]],'@odata.nextLink'=>'https://evil.test/never-followed']];};
$r=meetings_read('microsoft',1,$fixture);mt_check(count($r['rows'])===1&&$r['limited'],'Cancelled events excluded; pagination disclosed');mt_check(count($requests)===1&&$requests[0][0]==='GET'&&str_starts_with($requests[0][1],'https://graph.microsoft.com/v1.0/me/calendarView?'),'Fixed read-only personal endpoint');
$fixture=function(){return ['data'=>['meetings'=>[['topic'=>'Zoom test','start_url'=>'https://zoom.us/s/secret','join_url'=>'https://evil.test']]]];};
$r=meetings_read('zoom',1,$fixture);mt_check($r['rows'][0]['join']===''&&!str_contains(json_encode($r),'secret'),'Host links and foreign links excluded');
foreach(meetings_providers()as$d)foreach($d['scopes']as$s)mt_check(!preg_match('/write|admin|recording|transcript/i',$s),'Only delegated read scopes');
mt_check(meetings_event_join(['conferenceData'=>['entryPoints'=>[['entryPointType'=>'video','uri'=>'https://meet.google.com/abc-defg-hij']]]])==='https://meet.google.com/abc-defg-hij','Google conference links available in agenda');
mt_reject('https://zoom.us/%73/1','zoom');mt_reject('https://zoom.us/j/1?%7Aak=secret','zoom');
echo "Meeting policy checks passed: $checks\n";

meetings_validate_client('microsoft',['client_id'=>'12345678-1234-1234-1234-123456789abc','client_secret'=>'fixture-secret-value']);
$rejected=false;try{meetings_validate_client('microsoft',['client_id'=>'12345678-1234-1234-1234-123456789abc','client_secret'=>'abcdefab-1234-1234-1234-123456789abc']);}catch(RuntimeException $e){$rejected=str_contains($e->getMessage(),'Secret ID');}mt_check($rejected,'Secret identifier rejected before any network request');
mt_check(str_contains(meetings_remote_error('microsoft','token',401),'token exchange')&&str_contains(meetings_remote_error('microsoft','identity',401),'account identity check'),'Credential and identity failures distinguished');
mt_check(!str_contains(meetings_remote_error('microsoft','token',401),'fixture-secret-value'),'Diagnostic excludes credential values');
