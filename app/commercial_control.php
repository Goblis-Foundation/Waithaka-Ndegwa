<?php
declare(strict_types=1);
/** Additional editorial suitability and expressly enabled, exact-campaign repeat control. */
function commercial_categories(): array {
 return ['enterprise'=>'Products and business services','agriculture'=>'Agriculture and sustainable enterprise','education'=>'Education, training and events','technology'=>'Technology and innovation','professional'=>'Professional and creative services','regulated'=>'Health, finance or another regulated offer','other'=>'Other — needs editorial assessment','excluded'=>'Unlawful, deceptive, unsafe or rights-infringing offer'];
}
function commercial_policy(): string {
 return 'Not every product or service is accepted. We review relevance, truthful claims, customer safety, ownership and permissions before quoting or publishing. We do not accept unlawful products, scams, counterfeit goods, discriminatory or exploitative offers, deceptive guarantees, or material supplied without the necessary rights. Health, finance and other regulated offers need appropriate evidence and permissions and may be declined. Payment never overrides editorial suitability. Accepted advertising is labelled, and the advertiser remains responsible for its offer and customer orders.';
}
function commercial_policy_hash(): string {return hash('sha256','commercial-suitability-v1|'.commercial_policy());}
function commercial_intake_values(array $in): string {
 $category=desk_choice($in['advertising_category']??'enterprise',commercial_categories(),'advertising category');
 if($category==='excluded')throw new InvalidArgumentException('This type of offer is not accepted for advertising. Do not submit or pay for it.');
 if(($in['suitability_ack']??'')!=='1')throw new InvalidArgumentException('Acknowledge the advertising suitability policy before submitting.');
 return $category;
}
function commercial_new_review(array $r,array $in): array {
 if(empty($r['fields']['sponsored']))return $r;
 $category=desk_choice($in['advertising_category']??'enterprise',commercial_categories(),'advertising category');
 $r['suitability']=['policy'=>commercial_policy_hash(),'category'=>$category,'state'=>'pending','client_ack'=>($in['suitability_ack']??'')==='1','digest'=>null];return $r;
}
function commercial_material_hash(array $r,array $post,?string $category=null): string {
 return hash('sha256',commercial_policy_hash().'|'.($category??$r['suitability']['category']??'enterprise').'|'.desk_digest($r,$post));
}
function commercial_suitable(array $r,array $post): bool {
 if(empty($r['fields']['sponsored']))return true;
 // Previously approved commercial records retain their existing exact-copy gates.
 // New records, and records edited under this release, require the explicit review.
 if(!isset($r['suitability']))return true;
 $s=$r['suitability'];return ($s['state']??'')==='approved'&&($s['category']??'')!=='excluded'&&($s['policy']??'')===commercial_policy_hash()&&hash_equals(commercial_material_hash($r,$post),(string)($s['digest']??''));
}
function commercial_assert_suitable(array $r,array $post): void {
 if(!commercial_suitable($r,$post))throw new InvalidArgumentException('Complete the current advertising suitability review before quoting, publishing or distributing this feature.');
}
function commercial_review_save(string $id,array $in,int $uid): void {
 comm_lock('desk.catalog',function()use($id,$in,$uid){$r=desk_get($id);desk_expect($r,$in);$p=writer_post_by_id((int)$r['post_id']);
  if(!$p||empty($r['fields']['sponsored']))throw new InvalidArgumentException('Choose a sponsored feature.');
  if(!hash_equals(commercial_material_hash($r,$p),(string)($in['material_hash']??'')))throw new InvalidArgumentException('The brief, copy or creative changed. Review it again.');
  $category=desk_choice($in['advertising_category']??'enterprise',commercial_categories(),'advertising category');$decision=desk_choice($in['decision']??'',['approved'=>1,'changes'=>1,'declined'=>1],'editorial decision');
  $note=desk_text($in['review_note']??'','Review evidence and decision',5000,true);
  if(($in['editorial_ack']??'')!=='1')throw new InvalidArgumentException('Confirm the evidence, permissions and suitability review.');
  if($decision==='approved'&&$category==='excluded')throw new InvalidArgumentException('Excluded offers cannot be approved.');
  $r['suitability']=['policy'=>commercial_policy_hash(),'category'=>$category,'state'=>$decision,'digest'=>$decision==='approved'?commercial_material_hash($r,$p,$category):null,'note'=>$note,'reviewed_by'=>$uid,'reviewed_at'=>time(),'client_ack'=>!empty($r['suitability']['client_ack'])];
  desk_store($r,'suitability_'.$decision,(string)$uid);desk_delivery_guard();
 });
}
function commercial_client_profile(array $in): ?array {
 foreach(['country','phone_code','phone','county','sub_county','city','region','district']as$key)if(array_key_exists($key,$in))return location_fields($in)+location_phone($in);
 return null;
}
function commercial_preserve_client(array $old,array $new): array {
 if(!isset($new['contact_profile'])&&isset($old['contact_profile'])&&($old['email']??'')===($new['email']??''))$new['contact_profile']=$old['contact_profile'];return $new;
}
function commercial_plan(array $r,array $o): array {
 $post=desk_assert_publish($r,$o);$count=(int)($o['quote']['rounds']??0);
 if($o['record']!==$r['id']||($r['order']??'')!==$o['id']||$r['state']!=='published'||$count<2||count($o['campaign']??[])!==$count)throw new InvalidArgumentException('Use a paid, published multi-round campaign with all round drafts prepared.');
 if(!empty($o['cancellation_requested'])||!empty($o['settlement'])||!empty($o['schedule_proposal']))throw new InvalidArgumentException('Resolve cancellation, settlement or replacement dates first.');
 $rounds=[];$basis=['source'=>desk_digest($r,$post),'suitability'=>commercial_material_hash($r,$post),'order'=>$o['id'],'quote'=>$o['quote']['hash'],'times'=>$o['times'],'rounds'=>[]];
 foreach($o['times']as$i=>$at){$round=$o['campaign'][$i]??null;$p=$round?publishing_package((int)$round['package']):null;$map=$p?comm_get('desk.package.'.$p['id']):[];
  if(!$p||!empty($map['retired'])||($map['record']??'')!==$r['id']||($map['order']??'')!==$o['id']||($map['digest']??'')!==$basis['source']||($round['at']??0)!==$at)throw new InvalidArgumentException('A campaign draft or date changed. Prepare current rounds first.');
  $draft=publishing_decode($p['draft_json']);$destinations=[];$variants=[];
  foreach($o['quote']['channels']as$c){$slug=$c['slug'];$variant=publishing_variant_with_asset($draft['variants'][$slug]??[]);$channel=publishing_channel($slug);$dest=$channel?publishing_destination($channel,$variant):[];
   if(!$dest||empty($variant['enabled'])||desk_destination_key($dest)!==$c['route_hash'])throw new InvalidArgumentException('A contracted account or delivery method changed. Review the quotation again.');
   publishing_validate_variant($slug,$variant,$dest);desk_contract_variant($r,$variant,$c);$variants[$slug]=$variant;$destinations[$slug]=$dest;
  }
  $enabled=array_keys(array_filter($draft['variants']??[],static fn($v)=>!empty($v['enabled'])));$wanted=array_keys($destinations);sort($enabled);sort($wanted);if($enabled!==$wanted)throw new InvalidArgumentException('A round must contain exactly its contracted accounts.');
  $rounds[$i]=['package'=>(int)$p['id'],'draft_version'=>(int)$p['draft_version'],'at'=>$at,'variants'=>$variants,'destinations'=>$destinations,'approved_revision'=>(int)($p['approved_revision_id']??0)];
  $basis['rounds'][$i]=['package'=>(int)$p['id'],'version'=>(int)$p['draft_version'],'draft'=>hash('sha256',$p['draft_json']),'destinations'=>hash('sha256',publishing_json($destinations))];
 }
 return ['rounds'=>$rounds,'hash'=>hash('sha256',publishing_json($basis)),'source'=>$basis['source']];
}
function commercial_pinned_hash(array $r,array $p,array $o,array $pins): string {
 return hash('sha256',publishing_json(['source'=>desk_digest($r,$p),'suitability'=>commercial_material_hash($r,$p),'order'=>$o['id'],'quote'=>$o['quote']['hash']??'','accepted'=>$o['accepted_hash']??'','times'=>$o['times']??[],'pins'=>$pins]));
}
function commercial_enable(string $oid,array $in,int $uid): void {
 comm_lock('desk.catalog',function()use($oid,$in,$uid){$o=desk_order($oid);$r=desk_get($o['record']);$plan=commercial_plan($r,$o);
  if(($in['automation_ack']??'')!=='1'||!hash_equals($plan['hash'],(string)($in['plan_hash']??'')))throw new InvalidArgumentException('Review and approve this exact complete campaign before enabling repeat posting.');
  foreach($plan['rounds']as$i=>$round)if($i>0){if(desk_round_jobs($round['package']))throw new InvalidArgumentException('Later rounds already have jobs. Reconcile them before enabling a new automatic plan.');if($round['at']<time()+60||desk_closed($r,$round['at']))throw new InvalidArgumentException('A repeat slot was missed or exceeds offer expiry. Agree replacement dates first.');}
  $pins=[];foreach($plan['rounds']as$i=>$round){$revision=publishing_approve($round['package'],$round['draft_version'],$uid,hash('sha256',publishing_json($round['destinations'])));$q=db()->prepare('SELECT content_hash FROM publishing_revisions WHERE id=? AND package_id=?');$q->execute([$revision,$round['package']]);$hash=$q->fetchColumn();if(!is_string($hash)||$hash==='')throw new RuntimeException('Approved revision missing.');$pins[$i]=['package'=>$round['package'],'revision'=>$revision,'hash'=>$hash];}
  $o['automatic_repeats']=['enabled'=>true,'state'=>'armed','pins'=>$pins,'approved_by'=>$uid,'approved_at'=>time(),'notice'=>'Launch or confirm the first round. Later rounds wait for its actual publication links.'];$o['automatic_repeats']['hash']=commercial_pinned_hash($r,writer_post_by_id((int)$r['post_id']),$o,$pins);$o['audit'][]=['at'=>time(),'by'=>$uid,'action'=>'automatic_repeats_approved'];comm_put('desk.order.'.$oid,$o);
 });
}
function commercial_auto_valid(array $r,array $o): bool {
 $a=$o['automatic_repeats']??[];if(empty($a['enabled'])||!in_array($a['state']??'',['armed','scheduled'],true)||!empty($o['paused'])||!empty($r['cancellation_requested'])||!empty($o['cancellation_requested'])||!empty($o['settlement'])||!empty($o['schedule_proposal'])||($r['order']??'')!==$o['id'])return false;
 try{$p=desk_assert_publish($r,$o);if($r['state']!=='published'||empty($r['public_visible'])||($r['public']['digest']??'')!==desk_digest($r,$p))return false;
  if(!hash_equals(commercial_pinned_hash($r,$p,$o,$a['pins']??[]),(string)($a['hash']??'')))return false;
  foreach($a['pins']as$i=>$pin){$q=db()->prepare('SELECT content_hash,snapshot_json FROM publishing_revisions WHERE id=? AND package_id=?');$q->execute([(int)$pin['revision'],(int)$pin['package']]);$rev=$q->fetch();if(!$rev||!hash_equals((string)$pin['hash'],(string)$rev['content_hash'])||!hash_equals((string)$rev['content_hash'],hash('sha256',(string)$rev['snapshot_json'])))return false;$snap=publishing_decode($rev['snapshot_json']);foreach($o['quote']['channels']as$c){if(!isset($snap['destinations'][$c['slug']])||desk_destination_key($snap['destinations'][$c['slug']])!==$c['route_hash'])return false;desk_contract_variant($r,$snap['variants'][$c['slug']]??[],$c);}$package=publishing_package((int)$pin['package']);$map=comm_get('desk.package.'.$pin['package']);if(!$package||(int)$package['approved_revision_id']!==(int)$pin['revision']||!empty($map['retired'])||($map['order']??'')!==$o['id']||($o['campaign'][$i]['package']??null)!==$pin['package'])return false;}
  foreach($o['quote']['channels']as$c){$current=desk_channel_snapshot($c['slug'],$c['method']==='manual'?$c['account']:'');if($current['route_hash']!==$c['route_hash'])return false;}
  return true;
 }catch(Throwable){return false;}
}
function commercial_first_complete(array $o,array $jobs): bool {
 $pin=$o['automatic_repeats']['pins'][0]??null;if(!$pin)return false;$seen=[];
 foreach($jobs as$j){if((int)($j['revision_id']??0)!==(int)$pin['revision'])continue;$dest=publishing_decode($j['destination_json']??'');$slug=$dest['slug']??'';$contract=null;foreach($o['quote']['channels']as$c)if($c['slug']===$slug)$contract=$c;
  if(!$contract||$j['status']!=='published'||empty($j['external_url'])||!publishing_job_url_valid($j['external_url'],$slug)||desk_destination_key($dest)!==$contract['route_hash']||isset($seen[$slug]))return false;$seen[$slug]=true;
 }
 return count($seen)===count($o['quote']['channels'])&&count($seen)===2;
}
function commercial_launch_first(string $oid,int $uid): int {
 return comm_lock('desk.catalog',function()use($oid,$uid){$o=desk_order($oid);$r=desk_get($o['record']);if(!commercial_auto_valid($r,$o))throw new InvalidArgumentException('The approved automatic campaign is not currently eligible.');$pin=$o['automatic_repeats']['pins'][0];
  if(desk_round_jobs($pin['package']))return 0;$at=(int)$o['times'][0];if($at<time()+60||desk_closed($r,$at))throw new InvalidArgumentException('The first slot was missed or expired. Agree replacement dates; do not backdate a post.');
  $times=[];foreach($o['quote']['channels']as$c)$times[$c['slug']]=gmdate('Y-m-d H:i:s',$at);$n=publishing_schedule($pin['package'],$pin['revision'],date('Y-m-d\TH:i',$at),$uid,$times,true);$o['campaign'][0]['state']='scheduled';comm_put('desk.order.'.$oid,$o);return $n;
 });
}
function commercial_stop(string $oid,int $uid): void {
 comm_lock('desk.catalog',function()use($oid,$uid){$o=desk_order($oid);if(empty($o['automatic_repeats']))return;$o['automatic_repeats']['enabled']=false;$o['automatic_repeats']['state']='paused';$o['automatic_repeats']['notice']='Automatic repeats paused by the owner. Review future dates before enabling again.';$o['audit'][]=['at'=>time(),'by'=>$uid,'action'=>'automatic_repeats_paused'];comm_put('desk.order.'.$oid,$o);
  foreach($o['automatic_repeats']['pins']as$i=>$pin)if($i>0)db()->prepare("UPDATE publishing_jobs SET status='cancelled',last_error='Automatic repeat campaign paused by owner.' WHERE revision_id=? AND status IN ('scheduled','assisted','reminder_queued','reminder_sent','failed','reconnect') AND (remote_id IS NULL OR remote_id='')")->execute([$pin['revision']]);
 });
}
function commercial_delivery_allowed(array $r,?array $o,array $map,array $job): bool {
 $p=writer_post_by_id((int)$r['post_id']);if(!$p||!commercial_suitable($r,$p))return false;
 if(!$o||!isset($o['automatic_repeats'])||(int)($map['round']??0)===0)return true;
 return commercial_auto_valid($r,$o)&&commercial_first_complete($o,desk_round_jobs((int)$o['automatic_repeats']['pins'][0]['package']));
}
function commercial_repeat_work(bool $fixture=false): void {
 if($fixture&&app_config('app_env')!=='testing')throw new RuntimeException('Fixture override is disabled.');
 if(app_config('app_env')!=='production'&&!$fixture)return;
 comm_lock('desk.catalog',function(){foreach(comm_records('desk.order',3000)as$o){$a=$o['automatic_repeats']??[];if(empty($a['enabled'])||!in_array($a['state']??'',['armed','scheduled'],true))continue;
  $r=desk_get($o['record']);if(!commercial_auto_valid($r,$o)){$o['automatic_repeats']['state']='review';$o['automatic_repeats']['notice']='Payment, suitability, content, approval, dates or destination changed. Review before resuming.';comm_put('desk.order.'.$o['id'],$o);continue;}
  if(!commercial_first_complete($o,desk_round_jobs((int)$a['pins'][0]['package'])))continue;
  try{foreach($a['pins']as$i=>$pin){if($i===0||($o['campaign'][$i]['state']??'')==='scheduled')continue;$at=(int)$o['times'][$i];if($at<time()+60||desk_closed($r,$at))throw new InvalidArgumentException('A repeat slot was missed or expired. Agree a replacement; no catch-up burst.');
    $existing=desk_round_jobs($pin['package']);foreach($existing as$j)if((int)$j['revision_id']!==$pin['revision']||!in_array($j['status'],['scheduled','published','delivered','processing'],true))throw new InvalidArgumentException('An existing delivery outcome requires review; it will not be replayed.');
    $times=[];foreach($o['quote']['channels']as$c)$times[$c['slug']]=gmdate('Y-m-d H:i:s',$at);publishing_schedule($pin['package'],$pin['revision'],date('Y-m-d\TH:i',$at),(int)$a['approved_by'],$times,true);$o['campaign'][$i]['state']='scheduled';comm_put('desk.order.'.$o['id'],$o);
   }$o['automatic_repeats']['state']='scheduled';$o['automatic_repeats']['notice']='Later approved rounds are scheduled. Connected delivery is automatic; assisted accounts still need their recorded manual action.';comm_put('desk.order.'.$o['id'],$o);
  }catch(Throwable){$o['automatic_repeats']['state']='review';$o['automatic_repeats']['notice']='A repeat date or delivery outcome needs review. No automatic retry or replacement charge is made.';comm_put('desk.order.'.$o['id'],$o);}
 }desk_delivery_guard();});
}
function commercial_dispatch(string $path): bool {
 if(!preg_match('~^/write/desk/([a-f0-9]{32})/workflow$~D',$path,$m))return false;
 require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);try{$uid=desk_owner();$r=desk_get($m[1]);}catch(Throwable){http_response_code(403);return true;}$error='';
 if(is_post()){verify_csrf();try{$action=desk_text($_POST['action']??'','Action',40,true);if($action==='suitability')commercial_review_save($r['id'],$_POST,$uid);else{$o=desk_order(desk_text($_POST['order']??'','Order',32,true));if($o['record']!==$r['id'])throw new InvalidArgumentException('Order does not belong to this feature.');if($action==='enable')commercial_enable($o['id'],$_POST,$uid);elseif($action==='launch')commercial_launch_first($o['id'],$uid);elseif($action==='pause')commercial_stop($o['id'],$uid);else throw new InvalidArgumentException('Choose an available action.');}flash('success','Saved. Review the current campaign evidence below.');redirect(url($path));}catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'This action was not confirmed. Review saved state before trying again.';http_response_code(422);}}
 $r=desk_get($r['id']);$post=writer_post_by_id((int)$r['post_id']);$order=desk_current_order($r);$plan=null;$gate='';if($order)try{$plan=commercial_plan($r,$order);}catch(Throwable $e){$gate=$e instanceof InvalidArgumentException?$e->getMessage():'Prepare and review the campaign drafts first.';}
 render_write('commercial_workflow',['writeTitle'=>'Advertising review & repeat posting','r'=>$r,'post'=>$post,'order'=>$order,'plan'=>$plan,'gate'=>$gate,'error'=>$error]);return true;
}
