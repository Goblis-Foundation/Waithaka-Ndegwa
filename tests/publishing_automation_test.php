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
echo "PASS: weekly slots, collision avoidance, DST, bounded platform copy, global worker pause and account readiness.\n";
