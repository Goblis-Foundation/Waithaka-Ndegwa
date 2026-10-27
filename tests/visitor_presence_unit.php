<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/visitor_presence.php';
$n=0;function vp_check(bool $value,string $why):void{global $n;$n++;if(!$value)throw new RuntimeException($why);}
function vp_reject(callable $call):void{try{$call();}catch(InvalidArgumentException|RuntimeException){vp_check(true,'Rejected');return;}throw new RuntimeException('Invalid input accepted.');}
$now=1793100000;$key=str_repeat('k',64);
[$a,$t1]=visitor_presence_identity('fixture-browser-one',str_repeat('1',32),$key);[$same,$t2]=visitor_presence_identity('fixture-browser-one',str_repeat('2',32),$key);[$b,$t3]=visitor_presence_identity('fixture-browser-two',str_repeat('3',32),$key);
vp_check($same===$a&&$a!==$b&&$t1!==$t2,'Browser identity is shared across tabs; tabs are distinct.');
vp_check(!str_contains($a,'fixture-browser-one')&&strlen($a)===64,'Raw session identity is not stored.');
vp_check(visitor_presence_count([],$now)===0,'An empty measured presence store has zero active browsers.');
$s=visitor_presence_change([],$a,$t1,true,1,$now);vp_check(visitor_presence_count($s,$now)===1,'First consented heartbeat counts one.');
$s=visitor_presence_change($s,$a,$t1,true,1,$now+1);vp_check(visitor_presence_count($s,$now+1)===1,'Replay does not add a visitor.');
$s=visitor_presence_change($s,$a,$t2,true,1,$now+2);vp_check(visitor_presence_count($s,$now+2)===1,'Two visible tabs count as one browser.');
$s=visitor_presence_change($s,$b,$t3,true,1,$now+3);vp_check(visitor_presence_count($s,$now+3)===2,'A second browser is independent.');
$s=visitor_presence_change($s,$a,$t1,false,3,$now+4);vp_check(visitor_presence_count($s,$now+4)===2,'Leaving one tab does not remove an active sibling.');
$s=visitor_presence_change($s,$a,$t2,false,2,$now+5);vp_check(visitor_presence_count($s,$now+5)===1,'Last visible tab leaving removes the browser.');
$s=visitor_presence_change($s,$a,$t1,true,2,$now+6);vp_check(visitor_presence_count($s,$now+6)===1,'A delayed heartbeat cannot resurrect a closed tab.');
$s=visitor_presence_change($s,$a,$t1,true,4,$now+7);vp_check(visitor_presence_count($s,$now+7)===2,'A newly visible tab can return.');
vp_check(visitor_presence_count($s,$now+63)===1,'Lost second browser expires independently.');vp_check(visitor_presence_count($s,$now+67)===0,'Disconnected browsers expire after 60 seconds.');
$clean=visitor_presence_compact($s,$now+67);vp_check($clean['visitors']===[],'Expired identifiers and leave tombstones are removed.');
vp_check(visitor_presence_count(['version'=>1,'visitors'=>[],'overflow_until'=>$now+60],$now)===null,'Capacity uncertainty is withheld, never fabricated.');
vp_check(visitor_presence_count(['version'=>1,'visitors'=>[],'overflow_until'=>$now-1],$now)===0,'An expired capacity warning clears.');
vp_check(visitor_presence_same_origin('https://www.waithakandegwa.com','https://www.waithakandegwa.com','same-origin'),'Exact origin accepted.');
foreach(['https://evil.example','null','','https://www.waithakandegwa.com.evil.example']as$origin)vp_check(!visitor_presence_same_origin($origin,'https://www.waithakandegwa.com','same-origin'),'Foreign or missing origin refused.');
vp_check(!visitor_presence_same_origin('https://www.waithakandegwa.com','https://www.waithakandegwa.com','cross-site'),'Cross-site browser context refused.');
vp_reject(fn()=>visitor_presence_identity('',str_repeat('1',32),$key));vp_reject(fn()=>visitor_presence_identity('session','bad',$key));vp_reject(fn()=>visitor_presence_change([],$a,$t1,true,0,$now));vp_reject(fn()=>visitor_presence_count(['version'=>2,'visitors'=>[]],$now));
echo 'PASS: '.$n." direct-presence assertions including distinct browsers, tabs, consent-bound identity, replay, leave ordering, expiry and same-origin boundaries.\n";
