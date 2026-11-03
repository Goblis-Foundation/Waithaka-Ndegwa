<?php
declare(strict_types=1);
function comm_zone(mixed $z): DateTimeZone {if(!is_string($z)||!in_array($z,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Invalid timezone');return new DateTimeZone($z);}
require dirname(__DIR__).'/app/publishing_automation.php';
function pa_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
$slot=['days'=>[2,4],'time'=>'09:00'];$after=(new DateTimeImmutable('2026-10-26 10:00',new DateTimeZone('Africa/Nairobi')))->getTimestamp();
$next=publishing_weekly_next($slot,'Africa/Nairobi',$after);pa_check(gmdate('c',$next)==='2026-10-27T06:00:00+00:00','Next article slot respects Nairobi wall time');
$following=publishing_weekly_next($slot,'Africa/Nairobi',$after,[$next]);pa_check($following===$next+2*86400,'Occupied slot is skipped');
$dst=publishing_weekly_next(['days'=>[7],'time'=>'02:30'],'Europe/Berlin',strtotime('2027-03-27T00:00:00Z'));pa_check(gmdate('Y-m-d',$dst)==='2027-04-04','Nonexistent daylight-saving wall time is skipped');
$input=['timezone'=>'Africa/Nairobi','run'=>'1','slots'=>[]];foreach(publishing_weekly_defaults()as$k=>$v)if(is_array($v))$input['slots'][$k]=$v;
$cfg=publishing_weekly_input($input);pa_check($cfg['article']['days']===[2,4]&&$cfg['run'],'Validated weekly settings');
$input['slots']['x']['days']=[9];try{publishing_weekly_input($input);throw new LogicException('Invalid weekday accepted');}catch(InvalidArgumentException){}
$link='https://www.waithakandegwa.com/my-thoughts/example';$copies=[];foreach(publishing_copy_limits()as$slug=>$limit){$copies[$slug]=publishing_channel_copy($slug,'Climate enterprise',str_repeat('A supplied observation. ',100),$link);pa_check(mb_strlen($copies[$slug])<=$limit&&str_ends_with($copies[$slug],$link),'Each caption fits and retains the exact source link');}
pa_check(count(array_unique($copies))>=4,'Platform copy is not simply cloned');
$assets=[['id'=>1,'title'=>'Climate enterprise portrait','status'=>'ready','visibility'=>'private','mime'=>'image/jpeg','public_url'=>''],['id'=>2,'title'=>'Unrelated banquet','status'=>'ready','visibility'=>'public','mime'=>'image/jpeg','public_url'=>'https://example.invalid/a.jpg'],['id'=>3,'title'=>'Climate enterprise visit','status'=>'ready','visibility'=>'public','mime'=>'image/jpeg','public_url'=>'https://example.invalid/b.jpg']];
pa_check(publishing_pick_media('instagram','Climate enterprise','',$assets)['id']===3,'Only relevant public ready media is suggested');
pa_check(publishing_pick_media('youtube','Climate enterprise','',$assets)===null,'An image is never invented into a video');
pa_check(publishing_pick_media('instagram','Completely different subject','',$assets)===null,'No unrelated fallback asset');
pa_check(publishing_pick_media('linkedin','Climate enterprise','',$assets,['delivery_method'=>'direct'])===null,'Text-only route does not receive unsupported media');
pa_check(publishing_weekly_can_send(['run'=>true],true),'Weekly worker may process only when both switches allow it');
pa_check(!publishing_weekly_can_send(['run'=>false],true)&&!publishing_weekly_can_send(['run'=>true],false),'Both pause switches prevent weekly sends');
pa_check(publishing_weekly_paused_scope([8,3,8,-2,0],false)===' AND j.id NOT IN (8,3)','Paused weekly jobs excluded from unscoped worker without SQL injection');
pa_check(publishing_weekly_paused_scope([8],true)==='','Enabled weekly jobs remain in unscoped queue');
$preview=publishing_weekly_route_preview(['slug'=>'facebook','name'=>'Facebook profile','delivery_method'=>'manual'],['usable'=>true,'reason'=>'Finish in Facebook'],publishing_weekly_defaults(),$after);
pa_check($preview['assisted']&&str_contains($preview['status'],'Assisted')&&$preview['next_preference']!==null,'Professional profile is not claimed as an automatic post');
$disabled=publishing_weekly_route_preview(['slug'=>'tiktok','delivery_method'=>'direct'],['usable'=>false,'reason'=>'Provider audit required'],publishing_weekly_defaults(),$after);
pa_check(!$disabled['usable']&&str_contains($disabled['status'],'Unavailable'),'Unapproved TikTok route remains unavailable');
$ci=publishing_weekly_route_preview(['slug'=>'linkedin','delivery_method'=>'direct'],['usable'=>true,'reason'=>'Saved connection only'],publishing_weekly_defaults(),$after);
pa_check($ci['usable']&&str_contains($ci['status'],'approval and provider check'),'Saved direct route is not published nor unconstrained');
$source=file_get_contents(dirname(__DIR__).'/app/publishing.php');
pa_check(str_contains($source,'publishing_weekly_paused_scope(publishing_automation_ids()'),'Global unscoped worker respects weekly pause before selecting new scheduled posts');
$policy=['enabled'=>true,'start_date'=>'2026-11-05','owner'=>42,'after_job'=>10,'after_revision'=>20];
$start=publishing_scoped_start('2026-11-05');
pa_check(gmdate('c',$start)==='2026-11-04T21:00:00+00:00','Independent policy starts at Nairobi midnight.');
pa_check(!publishing_scoped_ready($policy,true,42,$start-1)&&publishing_scoped_ready($policy,true,42,$start),'Start date cannot release posts early.');
pa_check(!publishing_scoped_ready($policy,false,42,$start)&&!publishing_scoped_ready($policy,true,43,$start),'Weekly pause and exact owner remain mandatory.');
$off=$policy;$off['enabled']=false;pa_check(!publishing_scoped_ready($off,true,42,$start),'Independent policy can be paused.');
$invalid=$policy;$invalid['start_date']='2026-02-30';pa_check(!publishing_scoped_ready($invalid,true,42,$start),'Invalid dates fail closed.');
$j=['id'=>11,'revision_id'=>21,'method'=>'direct','approved_by'=>42,'created_by'=>42,'status'=>'scheduled','scheduled_at'=>gmdate('Y-m-d H:i:s',$start+60)];
pa_check(publishing_scoped_job_allowed($j,$policy,$start),'Only new directly approved social work can qualify.');
foreach(['id'=>10,'revision_id'=>20,'method'=>'manual','approved_by'=>9,'created_by'=>9,'status'=>'ambiguous','scheduled_at'=>'bad']as$key=>$value){$bad=$j;$bad[$key]=$value;pa_check(!publishing_scoped_job_allowed($bad,$policy,$start),'Reject changed or historical job boundary: '.$key);}
$old=$j;$old['scheduled_at']=gmdate('Y-m-d H:i:s',$start-1);pa_check(!publishing_scoped_job_allowed($old,$policy,$start),'Pre-start scheduled backlog cannot qualify.');
pa_check(!publishing_scoped_job_allowed($j,$policy,$start+90000),'A missed release older than 24 hours needs renewed scheduling.');
$accepted=$j;$accepted['status']='delivered';pa_check(publishing_scoped_job_allowed($accepted,$policy,$start+90000),'Provider-accepted selected work can still reconcile without republishing.');
$code=file_get_contents(dirname(__DIR__).'/app/publishing_automation.php');$scoped=substr($code,strpos($code,'function publishing_scoped_work'));
pa_check(!str_contains($scoped,'email_work')&&!str_contains($scoped,'publishing_weekly_reminders')&&str_contains($scoped,'publishing_work(10,$allowed)'),'The independent path cannot drain mail or call assisted email reminders.');
// Operational evidence must not become a store of provider messages or private content.
$hb=publishing_scoped_heartbeat($policy,'completed',$start,['processed'=>2,'published'=>1,'checked'=>0,'attention'=>1,'body'=>'private','error'=>'token','recipient'=>'private@example.invalid']);
pa_check(array_keys($hb['counts'])===['processed','published','checked','attention']&&!str_contains(json_encode($hb),'private'),'Heartbeat only retains allowlisted numeric metrics.');
pa_check(publishing_scoped_health($policy,$hb,true,42,$start-1)['state']==='scheduled','Before launch is waiting, not failed.');
pa_check(publishing_scoped_health($policy,$hb,true,42,$start)['state']==='completed','Fresh current-policy cycle is visible.');
pa_check(publishing_scoped_health($policy,$hb,true,42,$start+901)['state']==='stale','A missing timer becomes stale.');
pa_check(publishing_scoped_health($policy,$hb,false,42,$start)['state']==='paused','Pause takes precedence over old successful evidence.');
pa_check(publishing_scoped_health($policy,$hb,true,43,$start)['state']==='blocked','Owner changes invalidate readiness.');
$changed=$policy;$changed['after_job']=11;pa_check(publishing_scoped_health($changed,$hb,true,42,$start)['state']==='unverified','A new policy cannot inherit an old successful heartbeat.');
$future=$hb;$future['at']=$start+61;pa_check(publishing_scoped_health($policy,$future,true,42,$start)['at']===0,'Future evidence cannot establish freshness.');
$failed=publishing_scoped_heartbeat($policy,'failed',$start);pa_check(publishing_scoped_health($policy,$failed,true,42,$start)['state']==='failed','Failed cycle remains distinct from completion.');
$running=publishing_scoped_heartbeat($policy,'running',$start);pa_check(publishing_scoped_health($policy,$running,true,42,$start+901)['state']==='stale','Interrupted cycle is visible.');
pa_check(publishing_scoped_health($policy,$hb,true,42,$start,true)['state']==='global','Global worker is identified as the active route.');
pa_check(publishing_scoped_heartbeat($policy,'completed',$start,['published'=>'9','processed'=>-1])['counts']===[],'Invalid counter types are rejected.');
// Exercise the actual empty-queue worker path without SQL or external delivery.
$records=['publishing.direct.policy'=>array_replace($policy,['start_date'=>'2000-01-01'])];$throwIds=false;
function app_config(string $key,mixed $default=null):mixed{return $default;}
function comm_config():array{return ['owner'=>42];}
function comm_lock(string $key,callable $work):mixed{return $work();}
function comm_get(string $key):array{global $records,$throwIds;if($throwIds&&$key==='publishing.weekly.jobs')throw new RuntimeException('sensitive provider context');return $records[$key]??[];}
function comm_put(string $key,array $value):void{global $records;$records[$key]=$value;}
publishing_scoped_work();pa_check($records['publishing.direct.heartbeat']['state']==='completed'&&$records['publishing.direct.heartbeat']['counts']['published']===0,'An empty checked queue records a successful cycle, not a missing heartbeat.');
$throwIds=true;try{publishing_scoped_work();throw new LogicException('Failure swallowed');}catch(RuntimeException $e){pa_check($e->getMessage()==='sensitive provider context','The worker still propagates its failure.');}
pa_check($records['publishing.direct.heartbeat']['state']==='failed'&&!str_contains(json_encode($records['publishing.direct.heartbeat']),'sensitive'),'Failed worker persists no provider details.');
echo "PASS: weekly slots, collision avoidance, DST, bounded platform copy, global worker pause and account readiness.\n";
