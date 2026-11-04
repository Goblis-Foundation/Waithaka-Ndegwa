<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/opportunity_lifecycle_policy.php';
$n=0;function olt(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}
$at=strtotime('2026-11-20T20:59:00Z');$r=['kind'=>'opportunity','state'=>'published','public_visible'=>true,'fields'=>['deadline'=>$at,'sponsored'=>false]];
olt(!ol_closed($r,$at-1)&&ol_closed($r,$at),'Exact deadline boundary.');
olt(ol_status($r,$at-3600)==='Closing soon','Seven-day closing-soon state.');
olt(!ol_archived($r,$at+90*86400-1)&&ol_archived($r,$at+90*86400),'Ninety-day archive boundary.');
olt(ol_directory_match($r,'closed',$at+1)&&!ol_directory_match($r,'',$at+1),'Closed removed from current browsing.');
olt(!ol_directory_match($r,'all',$at+90*86400)&&ol_directory_match($r,'archived',$at+90*86400),'Archive only in explicit archive filter.');
olt(ol_interval($r,$at-8*86400)===86400,'Daily when deadline distant.');
olt(ol_interval($r,$at-7*86400)===21600,'Six-hour checks in final week.');
olt(ol_interval($r,$at+14*86400)===86400,'Daily through day fourteen after closure.');
olt(ol_interval($r,$at+15*86400)===604800,'Weekly after day fourteen.');
olt(ol_interval($r,$at+90*86400)===null,'No unattended monitoring after archival.');
$r['fields']['deadline']=null;$r['fields']['deadline_date']='2026-11-20';$bound=ol_boundary($r);
olt($bound['at']===strtotime('2026-11-21T12:00:00Z')&&$bound['precision']==='date','Unknown timezone uses latest worldwide day end, not an official time.');
olt(!ol_closed($r,$bound['at']-1)&&ol_closed($r,$bound['at']),'Date-only expiry works.');
olt(ol_status($r,$bound['at'])==='Closed - published date passed','Date precision is disclosed.');
olt(ol_date_valid('2028-02-29')&&!ol_date_valid('2027-02-29')&&!ol_date_valid('2026-13-11'),'Strict calendar dates.');
$r['fields']['deadline_date']='';olt(ol_boundary($r)['at']===null&&!ol_closed($r,$at+100000),'No invented rolling/unknown deadline.');
$r['state']='closed';$r['lifecycle']=['closed_at'=>$at,'close_reason'=>'manual'];olt(ol_interval($r,$at+1)===null,'Manual close never auto-monitored for reopening.');
$r['state']='withdrawn';olt(ol_status($r,$at)==='Withdrawn'&&!ol_archived($r,$at+200*86400),'Withdrawn is not a reversible deadline closure.');
$r=['kind'=>'sponsored','state'=>'published','fields'=>['offer_expires'=>$at,'sponsored'=>true]];olt(ol_closed($r,$at)&&!ol_archived($r,$at+100*86400),'Commercial offer expiry unchanged, no automatic commercial archival.');
$r=['kind'=>'opportunity','slug'=>'brahmam-global-innovation-grand-challenge-2026','state'=>'published','fields'=>['deadline'=>null,'deadline_date'=>'']];olt(ol_boundary($r)['precision']==='date','Legacy date-only source seed applies.');
$r['fields']['deadline']=$at;olt(ol_boundary($r)['precision']==='exact','Reviewed exact deadline overrides date-only seed.');
echo 'PASS: '.$n." deterministic closure, precision, archive and cadence checks.\n";
