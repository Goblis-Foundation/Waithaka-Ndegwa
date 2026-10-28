<?php
declare(strict_types=1);
/**
 * Waithaka editorial cadence: private planning and source-verified drafts.
 * This module NEVER sends mail, publishes an article or posts to a social API.
 * Exact approval, verified channel identity and provider receipts remain owned by
 * newsletter.php and publishing.php.
 */
function communications_cycle_sources(): array {
 return [
  'goblis_group'=>['name'=>'Goblis Group','metric'=>'startups','metric_label'=>'successful startups','url'=>'https://www.goblisgroup.com/'],
  'ubunifu'=>['name'=>'Ubunifu Institute','metric'=>'social_enterprises','metric_label'=>'incubated social enterprises','url'=>'https://www.ubunifuinstitute.com/'],
  'afritrace'=>['name'=>'AfriTrace Link','metric'=>'market_access','metric_label'=>'distinct enterprises connected to markets','url'=>'https://www.afritracelink.com/'],
  'goblis_foundation'=>['name'=>'Goblis Foundation','metric'=>'market_access','metric_label'=>'corroborating market-access enterprises','url'=>'https://www.goblisfoundation.org/']
 ];
}
function communications_cycle_defaults(): array {
 return ['enabled'=>true,'timezone'=>'Africa/Nairobi','days'=>14,
  'daily_time'=>'12:00','article_day'=>2,'article_time'=>'09:00',
  'newsletter_day'=>5,'newsletter_time'=>'09:00',
  'weekly_draft_enabled'=>true,'version'=>1];
}
function communications_cycle_config(): array {
 return array_replace(communications_cycle_defaults(),comm_get('editorial.cycle.config'));
}
function communications_cycle_input(array $input): array {
 $out=communications_cycle_defaults();
 $out['enabled']=($input['enabled']??null)==='1';
 $out['weekly_draft_enabled']=($input['weekly_draft_enabled']??null)==='1';
 foreach(['daily_time','article_time','newsletter_time'] as $key){
  $v=$input[$key]??null;
  if(!is_string($v)||!preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/D',$v))
   throw new InvalidArgumentException('Choose a 24-hour time for '.str_replace('_',' ',$key).'.');
  $out[$key]=$v;
 }
 foreach(['article_day','newsletter_day'] as $key){
  $v=$input[$key]??null;
  if(!is_scalar($v)||!preg_match('/^[1-7]$/D',(string)$v))
   throw new InvalidArgumentException('Choose a weekday for '.str_replace('_',' ',$key).'.');
  $out[$key]=(int)$v;
 }
 // Date-bound source reporting, email consent and site posting use the Nairobi
 // authority. The original weekly social scheduler may have its own timezone.
 return $out;
}
/** Validated source summary only. Missing, withdrawn, changed or stale data is not content. */
function communications_cycle_source_preview(string $source,array $snapshot,?int $asOf=null): array {
 $asOf??=time();
 $definition=communications_cycle_sources()[$source]??null;
 if(!$definition)throw new InvalidArgumentException('Unknown reviewed source.');
 $data=$snapshot['data']??null;
 if(($snapshot['status']??'')!=='available'||!is_array($data)
    ||($data['schema_version']??0)!==2||($data['approved_for_publication']??null)!==true
    ||($data['source']??'')!==$source
    ||!is_int($data['metrics'][$definition['metric']]??null)
    ||!is_string($data['measurement_period_start']??null)
    ||!is_string($data['measurement_period_end']??null)
    ||!is_string($data['published_at']??null))
  return ['ready'=>false,'note'=>'Awaiting a current, source-owned approved monthly report. Do not invent activity or figures.','copy'=>'','source_url'=>$definition['url'],'revision'=>null];
 $due=$data['next_report_due_at']??null;
 if(is_string($due)&&$due!==''&&(strtotime($due)?:0)<$asOf)
  return ['ready'=>false,'note'=>'The last source report is overdue. Obtain current source confirmation before preparing a new daily claim.','copy'=>'','source_url'=>$definition['url'],'revision'=>$data['revision']??null];
 $period=$data['measurement_period_start'].' – '.$data['measurement_period_end'];
 $number=number_format($data['metrics'][$definition['metric']]);
 $suffix=$source==='goblis_foundation'?' (corroborating AfriTrace Link, not an additional total)':'';
 $copy=$definition['name'].'’s source-approved report for '.$period.' records '.$number.' '.$definition['metric_label'].$suffix.'. '
      .'Measurement definitions, reporting dates and changes: '.url('/about#goals-2030');
 return ['ready'=>true,'note'=>'Source aggregate only; editorial and account approval are still required.','copy'=>$copy,
         'source_url'=>$definition['url'],'revision'=>$data['revision']??null,'period'=>$period];
}
/** Pure date-and-source schedule, no DB/network/provider I/O. */
function communications_cycle_schedule(int $now,array $cfg,array $snapshots): array {
 $tz=new DateTimeZone('Africa/Nairobi');
 $today=(new DateTimeImmutable('@'.$now))->setTimezone($tz)->setTime(0,0);
 $sources=array_keys(communications_cycle_sources());
 $rows=[];
 for($offset=0;$offset<14;$offset++){
  $day=$today->modify('+'.$offset.' days');$date=$day->format('Y-m-d');
  // UTC day number shifted to local calendar; stable over year boundaries.
  $index=((int)floor(($day->getTimestamp()+10800)/86400))%count($sources);
  $source=$sources[$index];$definition=communications_cycle_sources()[$source];
  $slotTime=(new DateTimeImmutable($date.' '.$cfg['daily_time'], $tz))->getTimestamp();
  $preview=communications_cycle_source_preview($source,$snapshots[$source]??[],$slotTime);
  $rows[]=['id'=>'daily-'.$date,'kind'=>'daily','date'=>$date,'when'=>$date.' '.$cfg['daily_time'],
    'label'=>'Daily organization update','source'=>$source,'title'=>$definition['name'].' · verified update',
    'status'=>$preview['ready']?'Evidence ready for editorial review':'Source evidence required',
    'copy'=>$preview['copy'],'note'=>$preview['note'],'source_url'=>$preview['source_url'],
    'action'=>'/write/publishing/new','automatic_delivery'=>false];
  if((int)$day->format('N')===$cfg['article_day'])
   $rows[]=['id'=>'article-'.$date,'kind'=>'article','date'=>$date,'when'=>$date.' '.$cfg['article_time'],
     'label'=>'Weekly original article','source'=>'personal','title'=>'Original experience, evidence and lesson',
     'status'=>'Writer research and approval required','copy'=>'','note'=>'Prepare an original article with evidence, metadata and an explicit public release. Never manufacture experiences.',
     'source_url'=>'','action'=>'/write/new','automatic_delivery'=>false];
  if((int)$day->format('N')===$cfg['newsletter_day'])
   $rows[]=['id'=>'newsletter-'.$date,'kind'=>'newsletter','date'=>$date,'when'=>$date.' '.$cfg['newsletter_time'],
     'label'=>'Weekly consented newsletter','source'=>'personal','title'=>'Week in My Thoughts',
     'status'=>'Approved edition and confirmed audience required','copy'=>'',
     'note'=>'The worker may prepare a private draft from published articles. Sending requires a checked sender postal address, approved revision, scheduled edition and confirmed readers.',
     'source_url'=>'','action'=>'/write/newsletter','automatic_delivery'=>false];
 }
 usort($rows,static fn($a,$b)=>strcmp($a['when'],$b['when'])?:strcmp($a['id'],$b['id']));
 return $rows;
}
function communications_cycle_snapshot(int $now): array {
 $cfg=communications_cycle_config();
 $snapshots=[];
 foreach(communications_cycle_sources()as$key=>$def)$snapshots[$key]=impact_snapshot($key);
 $rows=communications_cycle_schedule($now,$cfg,$snapshots);
 return ['timezone'=>'Africa/Nairobi','prepared_at'=>gmdate('c',$now),'enabled'=>$cfg['enabled'],
   'days'=>14,'rows'=>$rows,'note'=>'Private editorial briefs; no post, newsletter or channel is released by creating this calendar.'];
}
/** Only source-reviewed PUBLIC posts can appear in an automatic weekly email DRAFT. */
function communications_cycle_email_values(string $date,array $posts): ?array {
 if(!preg_match('/\A\d{4}-\d{2}-\d{2}\z/D',$date))throw new InvalidArgumentException('Invalid digest date.');
 $items=[];
 foreach(array_slice($posts,0,5)as$post){
  $slug=$post['slug']??null;$title=$post['title']??null;
  if(!is_string($slug)||!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D',$slug)
     ||!is_string($title)||trim($title)==='')continue;
  $items[]='<li><a href="'.htmlspecialchars(url('/my-thoughts/'.$slug),ENT_QUOTES,'UTF-8').'">'
    .htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</a></li>';
 }
 if(!$items)return null;
 $title='Weekly My Thoughts · '.$date;
 return ['title'=>$title,'subject'=>'This week in My Thoughts · '.$date,
  'preheader'=>'Reviewed writing and practical lessons from Waithaka Ndegwa.',
  'body_html'=>'<p>Here is this week’s published writing. Follow the links for the full context and original publication dates.</p>'
     .'<ul>'.implode('',$items).'</ul>'
     .'<p><a href="'.htmlspecialchars(url('/newsletter/preferences/request'),ENT_QUOTES,'UTF-8').'">Manage email preferences</a></p>',
  'source_post_id'=>null];
}
/**
 * Idempotent weekly newsletter DRAFT generation. No approval, scheduling,
 * recipient selection, automatic send or content publication occurs here.
 * Reuses the existing immutable newsletter approvals and consent engine.
 */
function communications_cycle_weekly_draft(int $now,array $cfg): array {
 if(!$cfg['enabled']||!$cfg['weekly_draft_enabled'])return ['state'=>'disabled'];
 $tz=new DateTimeZone('Africa/Nairobi');
 $local=(new DateTimeImmutable('@'.$now))->setTimezone($tz);
 if((int)$local->format('N')!==$cfg['newsletter_day']||$local->format('H:i')<$cfg['newsletter_time'])
  return ['state'=>'not_due'];
 $owner=(int)(comm_config()['owner']??0);
 if($owner<1)return ['state'=>'owner_not_configured'];
 $title='Weekly My Thoughts · '.$local->format('Y-m-d');
 return comm_lock('editorial.cycle.weekly.'.$local->format('Y-m-d'),static function()use($title,$local,$owner):array{
  $q=db()->prepare('SELECT id FROM newsletter_campaigns WHERE title=? ORDER BY id DESC LIMIT 1');
  $q->execute([$title]);$existing=(int)$q->fetchColumn();
  if($existing>0)return ['state'=>'existing_draft_or_review','id'=>$existing];
  $monday=$local->modify('-'.((int)$local->format('N')-1).' days')->setTime(0,0);
  $q=db()->prepare('SELECT p.id,p.title,p.slug,p.standfirst FROM posts p WHERE '.published_where().' AND p.published_at>=? AND p.published_at<=? ORDER BY p.published_at DESC,p.id DESC LIMIT 30');
  $q->execute([$monday->format('Y-m-d H:i:s'),$local->format('Y-m-d H:i:s')]);
  // Sponsored/customer posts have their own contracted campaign and permissions.
  // Never silently enroll them into the personal independent weekly digest.
  $deskPosts=[];if(function_exists('desk_all'))foreach(desk_all()as$record)
   if(!empty($record['post_id']))$deskPosts[(int)$record['post_id']]=true;
  $public=array_values(array_filter($q->fetchAll(),static fn($row)=>!isset($deskPosts[(int)$row['id']])));
  $values=communications_cycle_email_values($local->format('Y-m-d'),$public);
  if(!$values)return ['state'=>'no_published_articles'];
  $id=newsletter_save_campaign($values,$owner);
  return ['state'=>'draft_created_for_owner_review','id'=>$id];
 });
}
function communications_cycle_work(?int $when=null): array {
 $when??=time();$cfg=communications_cycle_config();
 if(!$cfg['enabled'])return ['enabled'=>false,'prepared'=>false,'newsletter'=>['state'=>'disabled']];
 $plan=communications_cycle_snapshot($when);
 $digest=hash('sha256',json_encode($plan['rows'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
 $updated=comm_lock('editorial.cycle.plan',static function()use($plan,$digest):bool{
  $old=comm_get('editorial.cycle.latest');
  if(($old['digest']??'')===$digest)return false;
  comm_put('editorial.cycle.latest',['digest'=>$digest,'prepared_at'=>$plan['prepared_at'],
       'timezone'=>$plan['timezone'],'rows'=>$plan['rows']]);return true;
 });
 return ['enabled'=>true,'prepared'=>$updated,
    'newsletter'=>communications_cycle_weekly_draft($when,$cfg)];
}
