<?php
declare(strict_types=1);
require_once __DIR__.'/monthly_roundup_policy.php';

function roundup_config(): array {
    return comm_get('roundup.config')+['enabled'=>true,'email_enabled'=>true,'first_release'=>'2026-11-01','version'=>0,'policy_hash'=>roundup_policy_hash(),'sender_hash'=>'','authority'=>'Owner request: 27 October 2026'];
}
function roundup_sender_hash(): string {return hash('sha256',newsletter_json(newsletter_sender()));}
function roundup_initialize(): array {
    return comm_lock('roundup.config',static function(){
        $cfg=roundup_config();if($cfg['version']===0){$cfg['version']=1;$cfg['sender_hash']=roundup_sender_hash();$cfg['created_at']=time();comm_put('roundup.config',$cfg);}return $cfg;
    });
}
function roundup_stamp(mixed $value): ?int {
    if(is_int($value))return $value;if(!is_string($value)||trim($value)==='')return null;
    try{return (new DateTimeImmutable($value,new DateTimeZone('UTC')))->getTimestamp();}catch(Throwable){return null;}
}
function roundup_collect(array $window): array {
    $candidates=[];$deskPosts=[];
    foreach(desk_all()as$r){$deskPosts[(int)$r['post_id']]=true;
        if(empty($r['public'])||empty($r['public_visible'])||$r['state']==='withdrawn')continue;
        $f=$r['public']['fields'];$p=$r['public']['article'];$at=(int)($r['public']['at']??$r['published_at']??0);
        $candidates[]=['type'=>!empty($f['sponsored'])?'partner':'opportunity','public'=>true,'published'=>$at,'title'=>$p['title'],'excerpt'=>$p['standfirst']??$f['summary'],'url'=>url(desk_path($r)),'closed'=>desk_closed(['kind'=>$r['kind'],'state'=>$r['state'],'fields'=>$f]),'sponsored'=>!empty($f['sponsored']),'newsletter_permission'=>!empty($f['newsletter_roundup_permission']),'sponsor'=>$f['sponsor_name']??''];
    }
    $q=db()->prepare('SELECT p.id,p.title,p.slug,p.standfirst,p.published_at FROM posts p WHERE '.published_where().' AND p.published_at>=? AND p.published_at<? ORDER BY p.published_at,p.id LIMIT 1001');
    $q->execute([gmdate('Y-m-d H:i:s',$window['start']),gmdate('Y-m-d H:i:s',$window['end'])]);$posts=$q->fetchAll();
    if(count($posts)>1000)throw new RuntimeException('Monthly source volume requires editorial review.');
    foreach($posts as$p)if(!isset($deskPosts[(int)$p['id']]))$candidates[]=['type'=>'article','public'=>true,'published'=>roundup_stamp($p['published_at']),'title'=>$p['title'],'excerpt'=>$p['standfirst'],'url'=>url('/my-thoughts/'.$p['slug'])];
    foreach(['resource','watch']as$type)foreach(editorial_public_items($type,0)as$item){
        $candidates[]=['type'=>$type==='watch'?'video':'resource','public'=>true,'published'=>roundup_stamp($item['public_modified_at']??$item['published_at']??null),'title'=>$item['title'],'excerpt'=>$item['summary']??'','url'=>url(($type==='watch'?'/watch/':'/resources/').$item['slug'])];
    }
    foreach(opportunity_public_list()as$item){
        $candidates[]=['type'=>'employment','public'=>true,'published'=>roundup_stamp($item['published_at']??null),'title'=>$item['title'],'excerpt'=>$item['purpose']??'','url'=>url('/opportunities/'.$item['id'])];
    }
    // Only a currently approved aggregate is eligible. Measurement and publication
    // dates stay distinct; no private source records or calculated totals enter mail.
    $impact=[];$impactAt=0;
    foreach(impact_sources()as$source=>$label){$snapshot=impact_snapshot($source);$data=$snapshot['data']??null;
        if(!is_array($data)||($data['approved_for_publication']??false)!==true||($data['schema_version']??0)!==2)continue;
        $at=roundup_stamp($data['published_at']??null);if($at===null||$at<$window['start']||$at>=$window['end'])continue;
        $impact[]=$label.': an approved public report was published for measurement period '.($data['measurement_period_start']??'').' to '.($data['measurement_period_end']??'').'.';$impactAt=max($impactAt,$at);
    }
    if($impact)$candidates[]=['type'=>'impact','public'=>true,'published'=>$impactAt,'title'=>'Approved public progress reports','excerpt'=>implode(' ',$impact),'url'=>url('/about')];
    $items=roundup_select($candidates,$window,base_url());if(count($items)>1000)throw new RuntimeException('Monthly source volume requires editorial review.');return $items;
}
function roundup_edition(string $key): array {return comm_get('roundup.edition.'.roundup_key($key));}
function roundup_store(array $edition): void {comm_put('roundup.edition.'.$edition['key'],$edition);}
function roundup_source_hash(array $items): string {return hash('sha256',newsletter_json($items));}
function roundup_sources_current(array $edition): bool {
    $current=[];foreach(roundup_collect($edition['window'])as$item)$current[$item['url']]=$item;
    foreach($edition['items']as$item){$new=$current[$item['url']]??null;if(!$new)return false;
        // Expiry is checked and labelled at release; later expiry alone must not
        // create a second edition. Changed public claims pause unsent delivery.
        unset($new['closed']);$old=$item;unset($old['closed']);if($new!==$old)return false;
    }return true;
}
function roundup_freeze(array $window,int $now,int $actor): array {
    $existing=roundup_edition($window['key']);if($existing)return $existing;
    $items=roundup_collect($window);$edition=['key'=>$window['key'],'window'=>$window,'title'=>roundup_title($window),'items'=>$items,'source_hash'=>roundup_source_hash($items),'policy_hash'=>roundup_policy_hash(),'published_at'=>$now,'scheduled_for'=>$window['release'],'public'=>true,'actor'=>$actor,'campaign_id'=>null,'email_state'=>'pending','created_at'=>$now];
    roundup_store($edition);return $edition;
}
function roundup_ensure_campaign(array $edition,int $actor): array {
    if(!empty($edition['campaign_id']))return $edition;
    $values=newsletter_values(['title'=>$edition['title'],'subject'=>$edition['title'],'preheader'=>'A welcome to '.$edition['window']['welcome_month'].' and the public updates from '.$edition['window']['month_year'].'.','body_html'=>roundup_body($edition['window'],$edition['items'],true,url(roundup_public_path($edition['key']))),'source_post_id'=>null]);
    db()->beginTransaction();try{
        db()->prepare('INSERT INTO newsletter_campaigns(title,subject,preheader,body_html,source_post_id,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([...array_values($values),$actor,$actor]);
        $edition['campaign_id']=(int)db()->lastInsertId();roundup_store($edition);
        newsletter_event($edition['campaign_id'],null,null,'monthly_policy_draft','Generated from the owner-authorised monthly public-content policy; not from private records.',$actor);db()->commit();return $edition;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
}
/** Queue only this edition. No unrelated newsletter or historical outbox is released. */
function roundup_queue_campaign(int $id,int $limit=50): array {
    $stats=['queued'=>0,'suppressed'=>0];$lock=newsletter_lock_name('campaign',$id);if(!newsletter_lock($lock,0))return $stats;
    try{db()->beginTransaction();$q=db()->prepare('SELECT * FROM newsletter_campaigns WHERE id=? FOR UPDATE');$q->execute([$id]);$campaign=$q->fetch();
        if(!$campaign||!in_array($campaign['status'],['scheduled','sending'],true)||$campaign['scheduled_at']>gmdate('Y-m-d H:i:s')){db()->commit();return $stats;}
        $revision=newsletter_revision((int)$campaign['scheduled_revision_id']);if(!$revision||!hash_equals($revision['content_hash'],hash('sha256',$revision['snapshot_json'])))throw new RuntimeException('Monthly revision integrity mismatch.');
        $snapshot=json_decode($revision['snapshot_json'],true,512,JSON_THROW_ON_ERROR);newsletter_assert_snapshot_current($snapshot);
        $q=db()->prepare("SELECT * FROM newsletter_deliveries WHERE campaign_id=? AND status='pending' ORDER BY id LIMIT ".max(1,min(100,$limit)).' FOR UPDATE');$q->execute([$id]);
        foreach($q->fetchAll()as$delivery){$s=$delivery['subscriber_id']?newsletter_subscriber((int)$delivery['subscriber_id']):null;
            if(!$s||!newsletter_recipient_eligible($s,$delivery)||!reader_newsletter_matches((int)$s['id'],$snapshot)){db()->prepare("UPDATE newsletter_deliveries SET status='suppressed',last_error='recipient_ineligible' WHERE id=?")->execute([$delivery['id']]);$stats['suppressed']++;continue;}
            $unsubscribe=newsletter_unsubscribe_url((int)$s['id']);$headers=['List-Unsubscribe'=>'<'.newsletter_one_click_url((int)$s['id']).'>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click'];
            $html=newsletter_html($snapshot,$unsubscribe,reader_preference_url((int)$s['id']),(string)(($s['first_name']??'')?:($s['name']??'')));
            $payload=['to'=>$s['email'],'subject'=>$snapshot['subject'],'html'=>$html,'headers'=>$headers];$outbox=enqueue_email($s['email'],$snapshot['subject'],$html,'newsletter-campaign:'.$delivery['delivery_key'],$headers);
            db()->prepare("UPDATE newsletter_deliveries SET status='queued',outbox_id=?,envelope_hash=?,queued_at=UTC_TIMESTAMP() WHERE id=?")->execute([$outbox,newsletter_envelope_hash($payload),$delivery['id']]);$stats['queued']++;
        }
        db()->prepare("UPDATE newsletter_campaigns SET status='sending',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$id]);db()->commit();return $stats;
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}finally{newsletter_unlock($lock);}
}
function roundup_work(?DateTimeImmutable $clock=null,?callable $testTransport=null): array {
    if(($clock!==null||$testTransport!==null)&&app_config('app_env')!=='testing')throw new RuntimeException('Fixture overrides are disabled.');
    if(!in_array(app_config('app_env'),['production','testing'],true))return ['enabled'=>false];
    return comm_lock('roundup.worker',function()use($clock,$testTransport){
        $cfg=roundup_initialize();$now=($clock??new DateTimeImmutable('now'))->getTimestamp();$window=roundup_window($clock);$owner=(int)(comm_config()['owner']??0);
        if(!$owner||empty($cfg['enabled'])||$cfg['policy_hash']!==roundup_policy_hash())return ['enabled'=>false,'reason'=>'owner_or_policy_not_ready'];
        if(!roundup_due($window,$now,$cfg['first_release']))return ['enabled'=>true,'due'=>false];
        $edition=roundup_freeze($window,$now,$owner);
        if(empty($cfg['email_enabled'])||in_array($edition['email_state'],['complete','no_subscribers','review'],true))return ['enabled'=>true,'published'=>true,'email_state'=>$edition['email_state']];
        if(!hash_equals($cfg['sender_hash'],roundup_sender_hash())||(!$testTransport&&!email_transport_ready())){$edition['email_state']='blocked_sender';roundup_store($edition);return ['enabled'=>true,'published'=>true,'email_state'=>'blocked_sender'];}
        if(!roundup_sources_current($edition)){$edition['email_state']='review';roundup_store($edition);return ['enabled'=>true,'published'=>true,'email_state'=>'review'];}
        try{
            $edition=roundup_ensure_campaign($edition,$owner);$campaign=newsletter_campaign($edition['campaign_id']);
            if($campaign['status']==='draft'){$snapshot=newsletter_snapshot($campaign);newsletter_approve((int)$campaign['id'],(int)$campaign['draft_version'],hash('sha256',newsletter_json($snapshot)),$owner);newsletter_event((int)$campaign['id'],null,null,'monthly_policy_approval','Exact snapshot authorised by the standing monthly policy '.$cfg['policy_hash'].'. This is automatic policy approval, not a fabricated manual preview.',$owner);$campaign=newsletter_campaign((int)$campaign['id']);}
            if($campaign['status']==='approved'){
                $q=db()->query("SELECT COUNT(*) FROM newsletter_subscribers WHERE status='confirmed' AND confirmed_at IS NOT NULL AND suppressed_at IS NULL");
                if(!(int)$q->fetchColumn()){$edition['email_state']='no_subscribers';roundup_store($edition);return ['enabled'=>true,'published'=>true,'email_state'=>'no_subscribers'];}
                // Midnight remains the planned release. A recovery run schedules
                // actual sending now; timestamps are never backdated.
                $actual=new DateTimeImmutable('now',new DateTimeZone((string)app_config('timezone','Africa/Nairobi')));
                newsletter_schedule((int)$campaign['id'],(int)$campaign['approved_revision_id'],$actual->format('Y-m-d\TH:i'),(int)$campaign['state_version'],$owner);$campaign=newsletter_campaign((int)$campaign['id']);
            }
            if(in_array($campaign['status'],['paused','cancelled'],true)){$edition['email_state']='review';roundup_store($edition);return ['enabled'=>true,'published'=>true,'email_state'=>'review'];}
            $queued=roundup_queue_campaign((int)$campaign['id']);
            $q=db()->prepare('SELECT outbox_id FROM newsletter_deliveries WHERE campaign_id=? AND outbox_id IS NOT NULL ORDER BY id');$q->execute([(int)$campaign['id']]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN));
            $sent=$ids?email_work(3,$testTransport,$ids):['sent'=>0];newsletter_email_reconcile();newsletter_finish_campaigns();$campaign=newsletter_campaign((int)$campaign['id']);
            $edition['email_state']=$campaign['status']==='completed'?'complete':'sending';$edition['last_run']=$now;roundup_store($edition);
            return ['enabled'=>true,'published'=>true,'email_state'=>$edition['email_state'],'queued'=>$queued['queued'],'accepted'=>(int)($sent['sent']??0)];
        }catch(NewsletterValidationException|NewsletterConflictException $e){$edition['email_state']='review';$edition['notice']='Review the monthly campaign sender, audience or content settings.';roundup_store($edition);return ['enabled'=>true,'published'=>true,'email_state'=>'review'];}
    });
}
function roundup_save_config(array $in,int $actor): void {
    comm_lock('roundup.worker',function()use($in,$actor){$cfg=roundup_config();if((string)($in['version']??'')!==(string)$cfg['version'])throw new InvalidArgumentException('Monthly settings changed. Reload this page.');
        $enabled=($in['enabled']??'')==='1';if($enabled&&($in['policy_ack']??'')!=='1')throw new InvalidArgumentException('Review and accept the exact monthly publication policy.');
        $cfg['enabled']=$enabled;$cfg['email_enabled']=($in['email_enabled']??'')==='1';$cfg['sender_hash']=roundup_sender_hash();$cfg['policy_hash']=roundup_policy_hash();$cfg['version']++;$cfg['reviewed_by']=$actor;$cfg['reviewed_at']=time();comm_put('roundup.config',$cfg);
        if(!$enabled||!$cfg['email_enabled'])foreach(comm_records('roundup.edition',240)as$edition)if(!empty($edition['campaign_id'])){$campaign=newsletter_campaign((int)$edition['campaign_id']);if(in_array($campaign['status']??'',['scheduled','sending'],true))newsletter_transition((int)$campaign['id'],'pause',(int)$campaign['state_version'],$actor);}
    });
}
function roundup_dispatch(string $path): bool {
    if($path==='/write/monthly-roundup'){
        require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);return true;}$error='';
        if(is_post()){verify_csrf();try{roundup_save_config($_POST,$uid);flash('success','Monthly policy saved. No edition was sent by saving this form.');redirect(url($path));}catch(InvalidArgumentException $e){$error=$e->getMessage();http_response_code(422);}}
        $cfg=roundup_config();$next=(new DateTimeImmutable('first day of next month',roundup_zone()))->setTime(0,0);$previewWindow=roundup_window($next);$preview=roundup_collect($previewWindow);$editions=comm_records('roundup.edition',240);
        render_write('monthly_roundup',['writeTitle'=>'Monthly newsletter','cfg'=>$cfg,'next'=>$next,'previewWindow'=>$previewWindow,'preview'=>$preview,'editions'=>$editions,'error'=>$error]);return true;
    }
    if($path==='/monthly-roundup'){
        journeys_method(['GET','HEAD']);$editions=array_values(array_filter(comm_records('roundup.edition',240),static fn($r)=>!empty($r['public'])&&$r['published_at']<=time()));usort($editions,static fn($a,$b)=>$b['key']<=>$a['key']);
        render('roundup/index',['page'=>'newsletter','editions'=>$editions,'seo'=>['title'=>'Monthly roundups · Waithaka Ndegwa','description'=>'Public writing, opportunities and updates from each previous month.','canonical'=>url($path)]]);return true;
    }
    if(preg_match('~^/monthly-roundup/([2-9][0-9]{3}-(?:0[1-9]|1[0-2]))$~D',$path,$m)){
        journeys_method(['GET','HEAD']);$edition=roundup_edition($m[1]);if(!$edition||empty($edition['public'])||$edition['published_at']>time()){http_response_code(404);return true;}
        $visible=[];$current=[];foreach(roundup_collect($edition['window'])as$item)$current[$item['url']]=$item;
        foreach($edition['items']as$item)if(isset($current[$item['url']]))$visible[]=$current[$item['url']];
        render('roundup/article',['page'=>'newsletter','edition'=>$edition,'body'=>roundup_body($edition['window'],$visible),'count'=>count($visible),'seo'=>['title'=>$edition['title'].' · Waithaka Ndegwa','description'=>'The public updates shared during '.$edition['window']['month_year'].'.','canonical'=>url($path)]]);return true;
    }
    return false;
}
