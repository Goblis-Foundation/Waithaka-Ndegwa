<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/functions.php';
require dirname(__DIR__).'/app/reader.php';
require dirname(__DIR__).'/app/communication.php';
require dirname(__DIR__).'/app/planner.php';
require dirname(__DIR__).'/app/trusted_devices.php';
$CONFIG=['base_url'=>'https://www.waithakandegwa.com','app_key'=>'fixture-only'];
function pc(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function pr(callable $fn):void{try{$fn();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected rejected input.');}
$input=['title'=>'Project visit','start'=>'2026-11-02T09:00','end'=>'2026-11-02T10:00','location'=>'Nairobi','kind'=>'project','latitude'=>'-1.2','longitude'=>'36.8'];
$r=planner_fields($input,'Africa/Nairobi');pc($r['end']-$r['start']===3600,'Timezone interval');pc(str_contains(planner_maps($r),'query=-1.2%2C36.8'),'Coordinate Maps target');pc(str_contains(planner_maps($r,true),'destination='),'Directions target');
pr(fn()=>planner_time('2026-02-30T09:00+03:00','Africa/Nairobi'));pr(fn()=>planner_time('2026-03-08T02:30','America/New_York'));pr(fn()=>planner_time('2026-11-01T01:30','America/New_York'));
pc(planner_time('2026-11-01T01:30-04:00','America/New_York')!==planner_time('2026-11-01T01:30-05:00','America/New_York'),'Explicit DST offsets respected');
pr(fn()=>planner_fields($input+['recipients'=>"bad\r\nBcc:someone@example.invalid"],'Africa/Nairobi'));
pr(fn()=>planner_fields(array_replace($input,['end'=>'2026-11-02T08:00']),'Africa/Nairobi'));
pr(fn()=>planner_fields(array_replace($input,['latitude'=>'91']),'Africa/Nairobi'));
$parsed=planner_parse("title,start,end,recipients\nReview,2026-11-02T09:00,2026-11-02T10:00,unreviewed@example.invalid\n",'csv','Africa/Nairobi');pc(count($parsed['rows'])===1&&$parsed['rows'][0]['recipients']===[],'Import cannot smuggle notice recipients');
$ics="BEGIN:VCALENDAR\nBEGIN:VEVENT\nDTSTART;VALUE=DATE:20261102\nDTEND;VALUE=DATE:20261103\nSUMMARY:Planning day\nEND:VEVENT\nEND:VCALENDAR";
pc(planner_parse($ics,'ics','Africa/Nairobi')['rows'][0]['end']-planner_parse($ics,'ics','Africa/Nairobi')['rows'][0]['start']===86400,'All-day exclusive end');
pr(fn()=>planner_parse(str_replace('SUMMARY:','RRULE:FREQ=DAILY'."\nSUMMARY:",$ics),'ics','UTC'));
pr(fn()=>planner_parse(str_replace('20261102','20261340',$ics),'ics','UTC'));
pc(!planner_calendar_write_allowed(['https://www.googleapis.com/auth/calendar.readonly']),'Read-only cannot mutate calendar');pc(planner_calendar_write_allowed(['https://www.googleapis.com/auth/calendar.events']),'Event-edit grant accepted');
$a=$r+['status'=>'planned'];$b=array_replace($a,['start'=>$r['end'],'end'=>$r['end']+3600]);pc(planner_conflicts([$a,$b])===[],'Adjacent events do not overlap');$b['start']--;pc(count(planner_conflicts([$a,$b]))===1,'Actual conflict exposed');
pc(app_trust_choice('1')&&!app_trust_choice('0')&&!app_trust_choice(null)&&!app_trust_choice([]),'Only explicit private-device choice enables trust');
$token=str_repeat('a',64);$trust=['user'=>1,'hash'=>hash('sha256',$token),'epoch'=>'epoch','expires'=>2000,'revoked'=>false];pc(app_trust_valid($trust,$token,'epoch',1000),'Valid remembered credential');pc(!app_trust_valid($trust,$token,'new-epoch',1000),'Password change invalidates trust');pc(!app_trust_valid($trust,'other','epoch',1000),'Wrong token rejected');pc(!app_trust_valid($trust,$token,'epoch',2000),'Expired device rejected');pc(!app_trust_valid(array_replace($trust,['revoked'=>true]),$token,'epoch',1000),'Revoked device rejected');
echo "PASS: program parsing, DST, maps, review boundaries, calendar scopes and trusted-device policy.\n";
