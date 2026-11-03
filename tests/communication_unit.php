<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/meetings.php';require dirname(__DIR__).'/app/communication.php';require dirname(__DIR__).'/app/communication_notifications.php';
function cc(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function reject(callable $f):void{try{$f();}catch(InvalidArgumentException){return;}throw new RuntimeException('Expected validation rejection.');}
foreach(['2026-02-29','2026-13-01','yesterday',[],"2026-01-01\n"]as$d)reject(fn()=>comm_date($d));cc(comm_date('2028-02-29')==='2028-02-29','Leap date');reject(fn()=>comm_zone('Bogus/Zone'));
$cfg=['timezone'=>'Africa/Nairobi','days'=>[1,2,3,4,5],'start'=>'09:00','end'=>'12:00','notice'=>1,'horizon'=>30,'buffer'=>15,'daily_limit'=>3,'excluded_dates'=>[]];$type=['minutes'=>30];$now=strtotime('2026-10-25T00:00:00Z');$date='2026-10-26';$base=strtotime('2026-10-26T06:00:00Z');
$s=comm_slot_candidates($cfg,$type,$date,[],[],$now);cc($s[0]===$base&&end($s)===$base+9000,'Host working hours and duration');
$s=comm_slot_candidates($cfg,$type,$date,[[$base+3600,$base+5400]],[],$now);foreach($s as$t)cc(!comm_overlap($t-900,$t+2700,$base+3600,$base+5400),'Preparation buffers block conflicts');
foreach(['requested','creating','confirmed','review','rescheduling','cancelling']as$status){$b=['id'=>'one','start'=>$base,'end'=>$base+1800,'status'=>$status];$s=comm_slot_candidates($cfg,$type,$date,[],[$b],$now);cc(!in_array($base,$s,true),'All unresolved reservations block');}
$s=comm_slot_candidates($cfg,$type,$date,[],[['start'=>$base,'end'=>$base+1800,'status'=>'cancelled']],$now);cc(in_array($base,$s,true),'Cancelled releases slot');
$cfg['excluded_dates']=[$date];cc(comm_slot_candidates($cfg,$type,$date,[],[],$now)===[],'Travel/holiday override');$cfg['excluded_dates']=[];$cfg['daily_limit']=1;cc(comm_slot_candidates($cfg,$type,$date,[],[['start'=>$base,'end'=>$base+1800,'status'=>'requested']],$now)===[],'Daily meeting limit');cc(comm_slot_candidates($cfg,$type,'2026-10-24',[],[],$now)===[],'Past dates');
$cfg['timezone']='America/New_York';$cfg['days']=[7];$cfg['start']='00:00';$cfg['end']='04:00';$cfg['daily_limit']=4;$s=comm_slot_candidates($cfg,['minutes'=>30],'2026-11-01',[],[],strtotime('2026-10-31T00:00:00Z'));cc(count($s)===19,'DST fall-back preserves distinct UTC slots');
$p=comm_preferences(['timezone'=>'Africa/Nairobi','enabled'=>1]);cc(comm_quiet($p,strtotime('2026-10-26T19:00:00Z')),'Nairobi quiet hours');cc(!comm_quiet($p,strtotime('2026-10-26T09:00:00Z')),'Daytime alert');
foreach(['http://fcm.googleapis.com/x','https://fcm.googleapis.com.evil.test/x','https://user@fcm.googleapis.com/x','https://127.0.0.1/x','https://fcm.googleapis.com:443/x','https://fcm.googleapis.com/x#token']as$u)cc(!comm_push_endpoint($u),'Reject unsafe push endpoint');foreach(['https://fcm.googleapis.com/fcm/send/a','https://updates.push.services.mozilla.com/wpush/v2/a','https://web.push.apple.com/a']as$u)cc(comm_push_endpoint($u),'Allow official push services');
$key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);openssl_sign('fixture',$der,$key,OPENSSL_ALGO_SHA256);cc(strlen(comm_es256_raw($der))===64,'VAPID signature width');
$sw=file_get_contents(dirname(__DIR__).'/sw.js');cc(!str_contains($sw,"caches.put")&&!str_contains($sw,'e.data.json'),'No private response cache or push payload');
echo "PASS: availability, DST, reservation states, buffers, quiet hours and notification boundaries.\n";

$b=['id'=>'fixture','join'=>''];$e=['extendedProperties'=>['private'=>['websiteBooking'=>'fixture']],'conferenceData'=>['createRequest'=>['status'=>['statusCode'=>'pending']]]];cc(comm_conference_result($b,$e)['conference_status']==='pending','Async conference remains pending');$e['hangoutLink']='https://meet.google.com/abc-defg-hij';cc(comm_conference_result($b,$e)['conference_status']==='ready','Async conference becomes joinable');$e['extendedProperties']['private']['websiteBooking']='different';$failed=false;try{comm_conference_result($b,$e);}catch(RuntimeException){$failed=true;}cc($failed,'Reject a different meeting identity');

// Private travel/time-off intervals use exact instants and the same public slot gate.
$blocks=booking_blocks_parse([['start'=>'2026-10-26T10:00','end'=>'2026-10-26T11:00','timezone'=>'Africa/Nairobi']]);
cc($blocks[0]['start']===strtotime('2026-10-26T07:00:00Z'),'Nairobi travel time converted to UTC');
$travel=['timezone'=>'Africa/Nairobi','days'=>[1,2,3,4,5],'start'=>'09:00','end'=>'12:00','notice'=>1,'horizon'=>30,'buffer'=>15,'daily_limit'=>3,'excluded_dates'=>[],'travel_blocks'=>$blocks];
$slots=comm_slot_candidates($travel,['minutes'=>30],'2026-10-26',[],[],strtotime('2026-10-25T00:00:00Z'));
cc($slots===[strtotime('2026-10-26T06:00:00Z'),strtotime('2026-10-26T06:15:00Z'),strtotime('2026-10-26T08:15:00Z'),strtotime('2026-10-26T08:30:00Z')],'Travel blocks remove overlap plus buffer but preserve exact boundary slots');
cc(booking_blocks_parse([['start'=>'','end'=>'','timezone'=>'']])===[],'Blank rows remove blocks');
cc(count(booking_blocks_parse([['start'=>'2026-10-26T10:00','end'=>'2026-10-26T11:00','timezone'=>'Africa/Nairobi'],['start'=>'2026-10-26T07:00','end'=>'2026-10-26T08:00','timezone'=>'UTC']]))===1,'Equal instants deduplicate across timezones');
foreach([
 ['start'=>'2026-02-30T10:00','end'=>'2026-03-01T11:00','timezone'=>'Africa/Nairobi'],
 ['start'=>'2026-10-26T10:00','end'=>'2026-10-26T10:00','timezone'=>'Africa/Nairobi'],
 ['start'=>'2026-10-26T10:00','end'=>'2027-10-26T11:00','timezone'=>'Africa/Nairobi'],
 ['start'=>'2026-10-26T10:00','end'=>'','timezone'=>'Africa/Nairobi'],
 ['start'=>[],'end'=>'2026-10-26T11:00','timezone'=>'Africa/Nairobi'],
 ['start'=>'2026-03-08T02:30','end'=>'2026-03-08T04:00','timezone'=>'America/New_York'],
 ['start'=>'2026-11-01T01:30','end'=>'2026-11-01T04:00','timezone'=>'America/New_York'],
]as$r)reject(fn()=>booking_blocks_parse([$r]));
reject(fn()=>booking_blocks_parse(array_fill(0,21,[])));
$cross=booking_blocks_parse([['start'=>'2026-10-25T22:00','end'=>'2026-10-26T04:00','timezone'=>'America/New_York']]);
cc($cross[0]['start']===strtotime('2026-10-26T02:00Z')&&$cross[0]['end']===strtotime('2026-10-26T08:00Z'),'Overnight foreign-zone travel remains exact');
$wide=booking_blocks_parse([['start'=>'2026-11-01T00:00','end'=>'2026-11-01T04:00','timezone'=>'America/New_York']]);cc($wide[0]['end']-$wide[0]['start']===5*3600,'Unambiguous boundaries cover DST transition');
cc(booking_blocks_update($travel,[])===$travel,'Old form retains blocks');
$changed=booking_blocks_update($travel,['travel_blocks_present'=>'1','travel_blocks_revision'=>booking_blocks_revision($travel),'travel_blocks'=>[]]);cc($changed['travel_blocks']===[],'Current version can explicitly clear blocks');
try{booking_blocks_update($travel,['travel_blocks_present'=>'1','travel_blocks_revision'=>booking_blocks_revision([]),'travel_blocks'=>[]]);throw new LogicException('Stale form erased blocks');}catch(RuntimeException){}
try{booking_blocks_busy(['travel_blocks'=>[['start'=>'invalid','end'=>1]]]);throw new LogicException('Malformed stored block opened availability');}catch(RuntimeException){}
echo "PASS: private travel blocks, buffers, timezone/DST validation, stale updates and fail-closed availability.\n";
