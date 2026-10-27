<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('WK_TEST_ALLOW_WRITES')!=='1')throw new RuntimeException('Explicit disposable database required.');
// The existing suite creates a fresh loopback database and synthetic clients only.
require __DIR__.'/publishing_desk_database.php';
$n=0;function cr(bool $ok,string $why):void{global $n;$n++;if(!$ok)throw new RuntimeException($why);}
function crreject(callable $f,string $why):void{try{$f();}catch(InvalidArgumentException){cr(true,$why);return;}throw new RuntimeException('Expected rejection: '.$why);}
$client=desk_client(['client_name'=>'Automatic fixture client','client_email'=>'automatic-fixture@example.com','organisation'=>'Automatic fixture business','country'=>'Kenya','county'=>'Nyeri','sub_county'=>'Kieni','city'=>'Endarasha','phone_code'=>'+254','phone'=>'0718751696']);
$r=desk_new(array_replace($base,['title'=>'Reviewed automatic campaign fixture','organisation'=>$client['business'],'sponsor_name'=>$client['business'],'purchase_url'=>'https://example.org/buy','summary'=>'An accurately described fixture service.','offer'=>'Verified fixture terms.','suitability_ack'=>'1','advertising_category'=>'enterprise']),'sponsored',$uid,$client);
desk_test_post((int)$r['post_id'],$uid);$r=desk_get($r['id']);$r['creatives']['landscape']=$sponsored['creatives']['landscape'];$r=desk_store($r,'fixture_creative',(string)$uid);
cr($r['client']['contact_profile']['country']==='Kenya'&&!str_contains(json_encode(desk_ai_material($r)),'254718751696'),'Intake contact is encrypted with the client and absent from AI material.');
$inputs=['version'=>$r['version'],'package'=>'weekly','channel_one'=>'linkedin','channel_two'=>'x','campaign_start'=>(new DateTimeImmutable('+90 days'))->setTime(9,0)->format('Y-m-d\TH:i'),'visibility_days'=>'365'];
crreject(fn()=>desk_quote($r['id'],$inputs,$uid),'Pending suitability cannot get a chargeable quote.');
commercial_review_save($r['id'],['version'=>$r['version'],'material_hash'=>commercial_material_hash($r,writer_post_by_id((int)$r['post_id'])),'advertising_category'=>'enterprise','decision'=>'approved','review_note'=>'Synthetic evidence and rights checked.','editorial_ack'=>'1'],$uid);
$r=desk_get($r['id']);$inputs['version']=$r['version'];$o=desk_quote($r['id'],$inputs,$uid);$r=desk_get($r['id']);
desk_client_action($r['id'],['version'=>$r['version'],'action'=>'accept_quote','order'=>$o['id'],'terms_ack'=>'1','quote_hash'=>$o['quote']['hash']],$client['email']);
crreject(fn()=>commercial_plan(desk_get($r['id']),desk_order($o['id'])),'Accepted quotation without payment and content approval cannot enable automatic repeats.');
desk_checkout($o['id'],'bank',$client['email']);desk_bank_verify($o['id'],['bank_ack'=>'1','transaction'=>'AUTOMATIC-FIXTURE-TX','currency'=>'KES','amount'=>'18000.00'],$uid);
$r=desk_action($r['id'],'invite',[],$uid);$p=writer_post_by_id((int)$r['post_id']);desk_client_action($r['id'],['version'=>$r['version'],'action'=>'approve','approval_ack'=>'1','digest'=>desk_digest($r,$p)],$client['email']);$r=desk_action($r['id'],'approve',['approval_ack'=>'1'],$uid);$r=desk_action($r['id'],'publish',[],$uid);
desk_prepare_campaign($r['id'],$o['id'],$uid);$r=desk_get($r['id']);$o=desk_order($o['id']);$plan=commercial_plan($r,$o);
cr(count($plan['rounds'])===4,'One exact review contains all four contractual rounds.');
crreject(fn()=>commercial_enable($o['id'],['automation_ack'=>'1','plan_hash'=>'stale'],$uid),'Stale complete-campaign approval is rejected.');
commercial_enable($o['id'],['automation_ack'=>'1','plan_hash'=>$plan['hash']],$uid);$o=desk_order($o['id']);cr($o['automatic_repeats']['state']==='armed'&&count($o['automatic_repeats']['pins'])===4,'Campaign approval freezes each actual revision.');
$paidAt=$o['paid_at'];$paymentCount=count($o['payments']);$firstPackage=$o['automatic_repeats']['pins'][0]['package'];
cr(count(desk_round_jobs($firstPackage))===0,'Enabling repeats does not secretly launch the first round.');
cr(commercial_launch_first($o['id'],$uid)===2&&commercial_launch_first($o['id'],$uid)===0,'First-round launch is explicit and idempotent.');
commercial_repeat_work(true);$o=desk_order($o['id']);cr(count(desk_round_jobs($o['automatic_repeats']['pins'][1]['package']))===0,'Later rounds wait for first-round publication evidence.');
$first=desk_round_jobs($firstPackage);$firstDest=publishing_decode($first[0]['destination_json']);$urls=['linkedin'=>'https://www.linkedin.com/posts/fixture-editor_confirmed-first-activity-123','x'=>'https://x.com/fixture-editor/status/1234567890123456789'];
publishing_job_update((int)$first[0]['id'],['status'=>'published','external_url'=>$urls[$firstDest['slug']],'completed_at'=>gmdate('Y-m-d H:i:s')]);
commercial_repeat_work(true);cr(count(desk_round_jobs($o['automatic_repeats']['pins'][1]['package']))===0,'Partial first-round delivery cannot release repeats.');
$secondDest=publishing_decode($first[1]['destination_json']);publishing_job_update((int)$first[1]['id'],['status'=>'ambiguous','external_url'=>null]);commercial_repeat_work(true);cr(!commercial_first_complete($o,desk_round_jobs($firstPackage)),'An uncertain first placement does not count as confirmed.');
publishing_job_update((int)$first[1]['id'],['status'=>'published','external_url'=>$urls[$secondDest['slug']],'completed_at'=>gmdate('Y-m-d H:i:s')]);
cr(commercial_first_complete($o,desk_round_jobs($firstPackage)),'Both exact first-round destinations now have valid recorded post links.');
commercial_repeat_work(true);$o=desk_order($o['id']);$count=0;foreach($o['automatic_repeats']['pins']as$pin)$count+=count(desk_round_jobs($pin['package']));cr($count===8&&$o['automatic_repeats']['state']==='scheduled','Confirmed first round releases only the six agreed later placements.');
commercial_repeat_work(true);$again=desk_order($o['id']);$count2=0;foreach($again['automatic_repeats']['pins']as$pin)$count2+=count(desk_round_jobs($pin['package']));cr($count2===$count&&$again['paid_at']===$paidAt&&count($again['payments'])===$paymentCount,'Worker replays neither duplicate jobs nor create charges.');
foreach($o['automatic_repeats']['pins']as$pin)foreach(desk_round_jobs($pin['package'])as$j)cr($j['method']==='manual','Assisted fixture destinations are not silently converted into native automatic publishing.');
$changed=$r;$changed['fields']['offer']='A material claim changed';cr(!commercial_auto_valid($changed,$o),'Changed claims invalidate a frozen campaign.');
$paused=$o;$paused['paused']=true;cr(!commercial_auto_valid($r,$paused),'Paused payment/order blocks delivery.');
$cancelled=$o;$cancelled['cancellation_requested']=time();cr(!commercial_auto_valid($r,$cancelled),'Client cancellation blocks delivery.');
$unpaid=$o;$unpaid['state']='refunded';cr(!commercial_auto_valid($r,$unpaid),'Refunded campaigns cannot release paid repeats.');
$later=desk_round_jobs($o['automatic_repeats']['pins'][1]['package']);publishing_job_update((int)$later[0]['id'],['status'=>'ambiguous','remote_id'=>'provider-may-have-published']);commercial_repeat_work(true);$o=desk_order($o['id']);cr($o['automatic_repeats']['state']==='review','An uncertain later outcome pauses the remainder for review.');
$later=desk_round_jobs($o['automatic_repeats']['pins'][1]['package']);cr($later[0]['status']==='ambiguous'&&$later[0]['remote_id']==='provider-may-have-published','Uncertain remote evidence is retained, never replayed or erased.');
cr(commercial_first_complete($o,desk_round_jobs($firstPackage)),'Stopping future work preserves first-round publication evidence.');
commercial_stop($o['id'],$uid);cr(!desk_order($o['id'])['automatic_repeats']['enabled'],'Explicit owner pause is durable.');
$rows=ux_seo_audit();cr(is_array($rows),'SEO content checks execute against the actual schema.');
echo 'PASS: '.$n." commercial suitability, private intake, exact campaign approval, first-round gates, automatic scheduling, idempotence and safe stopping assertions. No provider called, no actual customer charged.\n";
