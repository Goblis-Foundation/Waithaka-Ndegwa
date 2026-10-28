<?php
declare(strict_types=1);
require_once __DIR__.'/email_design.php';
require_once __DIR__.'/location.php';

/** Startup journeys, reviewed founder stories and a double-opt-in mailing list. */
final class JourneyValidationException extends InvalidArgumentException {}
final class JourneyConflictException extends RuntimeException {}

function journeys_now(): string { return date('Y-m-d H:i:s'); }
function journeys_phases(): array { return ['planning'=>'Planning','building'=>'Building','operating'=>'Operating','paused'=>'Paused','concluded'=>'Concluded']; }
function journeys_publication_states(): array { return ['draft'=>'Private draft','published'=>'Published','archived'=>'Archived']; }
function journeys_evidence_bases(): array {
    return ['author_experience'=>'Firsthand experience','records'=>'Documented records','interview'=>'Interview','reported'=>'Reported by the founder','mixed'=>'Mixed sources','not_assessed'=>'Basis not yet assessed'];
}
function founders_metric_bases(): array { return ['self_reported'=>'Founder estimate / self-reported','records'=>'Supported by records','third_party'=>'Reported by a third party','not_applicable'=>'No numerical claims']; }
function founders_review_states(): array { return ['new'=>'New','reviewing'=>'In review','needs_info'=>'More information needed','approved'=>'Approved','declined'=>'Declined','withdrawn'=>'Withdrawn']; }

function journeys_text(mixed $value, string $label, int $max, bool $required=false): string {
    if (!is_scalar($value) && $value !== null) throw new JourneyValidationException($label.' is not valid.');
    $value=trim(str_replace(["\r\n","\r"],"\n",(string)$value));
    if (!mb_check_encoding($value,'UTF-8') || str_contains($value,"\0")) throw new JourneyValidationException($label.' contains invalid characters.');
    if ($required && $value==='') throw new JourneyValidationException($label.' is required.');
    if (mb_strlen($value)>$max) throw new JourneyValidationException($label.' must be '.$max.' characters or fewer.');
    return $value;
}
function journeys_choice(mixed $value, array $choices, string $label): string {
    if (!is_string($value) || !array_key_exists($value,$choices)) throw new JourneyValidationException('Choose a valid '.$label.'.');
    return $value;
}
function journeys_id(mixed $value, string $label='record', bool $optional=false): ?int {
    if ($optional && ($value===null || $value==='')) return null;
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/',(string)$value) || (int)$value>2147483647) throw new JourneyValidationException('Choose a valid '.$label.'.');
    return (int)$value;
}
function journeys_date(mixed $value): ?string {
    $value=journeys_text($value,'Start date',10);
    if ($value==='') return null;
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    if (!$d || $d->format('Y-m-d')!==$value) throw new JourneyValidationException('Enter a valid start date.');
    return $value;
}
function journeys_email(mixed $value): string {
    $email=strtolower(journeys_text($value,'Email address',254,true));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/',$email)) throw new JourneyValidationException('Enter a valid email address.');
    return $email;
}
function journeys_checked(mixed $value): bool { return $value==='1' || $value===1; }
/** Safe form redisplay even when a rejected request contains array-shaped fields. */
function journeys_field(array $values,string $key,string $default=''): string {
    return array_key_exists($key,$values)&&is_scalar($values[$key])?(string)$values[$key]:$default;
}
function journeys_lock_suffix(): string { return db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':''; }
function journeys_private_headers(): void {
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow, noarchive');
    header('Referrer-Policy: no-referrer');
}
function journeys_verify_csrf(): void {
    $expected=$_SESSION['csrf']??null;
    $provided=$_POST['csrf']??null;
    if (!is_string($expected) || !is_string($provided) || !preg_match('/^[a-f0-9]{64}$/',$expected) || !hash_equals($expected,$provided)) {
        http_response_code(419); exit('Your form has expired. Refresh the page and try again.');
    }
}
function journeys_method(array $allowed): void {
    if (!in_array(strtoupper($_SERVER['REQUEST_METHOD']??'GET'),$allowed,true)) {
        header('Allow: '.implode(', ',$allowed)); http_response_code(405); exit('Method not allowed.');
    }
}

/** A database-backed fixed-window limiter; keys are opaque, never raw IP/email. */
function journeys_rate_limit(string $purpose, string $identifier, int $limit, int $seconds=3600): bool {
    $window=(int)floor(time()/$seconds);
    $key=hash_hmac('sha256',$purpose.'|'.$identifier.'|'.$window,(string)app_config('app_key'));
    try {
        $s=db()->prepare('INSERT INTO journey_rate_limits(bucket_key,purpose,hits,expires_at) VALUES(?,?,1,?)');
        $s->execute([$key,$purpose,date('Y-m-d H:i:s',($window+2)*$seconds)]);
        return true;
    } catch (PDOException $e) {
        if (!in_array((string)$e->getCode(),['23000','23505','19'],true)) throw $e;
    }
    $s=db()->prepare('UPDATE journey_rate_limits SET hits=hits+1 WHERE bucket_key=?');$s->execute([$key]);
    $s=db()->prepare('SELECT hits FROM journey_rate_limits WHERE bucket_key=?');$s->execute([$key]);
    return (int)$s->fetchColumn()<=$limit;
}
function journeys_audit(string $entity,int $id,string $event,?int $actor=null): void {
    $s=db()->prepare('INSERT INTO journey_audit_events(entity_type,entity_id,event_type,actor_id,created_at) VALUES(?,?,?,?,?)');
    $s->execute([$entity,$id,$event,$actor,journeys_now()]);
}

function journeys_post_is_public(array $post,?string $now=null): bool {
    return in_array($post['status']??'', ['published','scheduled'],true)
        && !empty($post['published_at']) && (string)$post['published_at']<=($now??journeys_now());
}
function journeys_public_seasons(int $limit=100,bool $activeFirst=false): array {
    $priority=$activeFirst?"CASE WHEN s.phase IN ('planning','building','operating') THEN 0 ELSE 1 END, ":'';
    $s=db()->prepare("SELECT s.id,s.slug,s.title,s.venture_name,s.summary,s.baseline,s.learning_question,s.outcomes,s.started_on,s.phase,s.published_at,s.updated_at,
        (SELECT COUNT(*) FROM journey_episodes e JOIN posts p ON p.id=e.post_id WHERE e.season_id=s.id AND e.is_published=1 AND p.status IN ('published','scheduled') AND p.published_at IS NOT NULL AND p.published_at<=?) AS episode_count
        FROM journey_seasons s WHERE s.publication_status='published' AND s.published_at IS NOT NULL AND s.published_at<=?
        ORDER BY ".$priority."s.started_on IS NULL,s.started_on DESC,s.id DESC LIMIT ".max(1,min(100,$limit)));
    $s->execute([journeys_now(),journeys_now()]);return $s->fetchAll();
}
function journeys_home_feature(): ?array { return journeys_public_seasons(1,true)[0]??null; }
/** Public URLs only. Drafts, private forms and review records are excluded. */
function journeys_sitemap_entries(): array {
    $entries=[['loc'=>url('/startup-journeys')],['loc'=>url('/founders')]];
    $s=db()->prepare("SELECT slug,updated_at FROM journey_seasons WHERE publication_status='published' AND published_at IS NOT NULL AND published_at<=? ORDER BY id");
    $s->execute([journeys_now()]);
    foreach($s->fetchAll() as $season)$entries[]=['loc'=>url('/startup-journeys/'.$season['slug']),'lastmod'=>date(DATE_ATOM,strtotime($season['updated_at']))];
    return $entries;
}
function journeys_public_season(string $slug): ?array {
    $s=db()->prepare("SELECT id,slug,title,venture_name,summary,baseline,learning_question,outcomes,started_on,phase,published_at,updated_at FROM journey_seasons WHERE slug=? AND publication_status='published' AND published_at IS NOT NULL AND published_at<=? LIMIT 1");
    $s->execute([$slug,journeys_now()]);return $s->fetch()?:null;
}
function journeys_public_episodes(int $seasonId): array {
    $s=db()->prepare("SELECT e.id,e.episode_order,e.public_summary,e.evidence_basis,e.evidence_summary,p.id post_id,p.slug,p.title,p.standfirst,p.featured_image,p.featured_image_alt,p.published_at
        FROM journey_episodes e JOIN journey_seasons s ON s.id=e.season_id JOIN posts p ON p.id=e.post_id
        WHERE e.season_id=? AND e.is_published=1 AND s.publication_status='published' AND s.published_at IS NOT NULL AND s.published_at<=?
        AND p.status IN ('published','scheduled') AND p.published_at IS NOT NULL AND p.published_at<=? ORDER BY e.episode_order,e.id");
    $s->execute([$seasonId,journeys_now(),journeys_now()]);return $s->fetchAll();
}
/** Optional article navigation integration. Returns only publicly visible links. */
function journeys_post_context(int $postId): array {
    $s=db()->prepare("SELECT s.id,s.slug,s.title FROM journey_seasons s JOIN journey_episodes e ON e.season_id=s.id JOIN posts p ON p.id=e.post_id
        WHERE e.post_id=? AND e.is_published=1 AND s.publication_status='published' AND s.published_at IS NOT NULL AND s.published_at<=?
        AND p.status IN ('published','scheduled') AND p.published_at IS NOT NULL AND p.published_at<=? ORDER BY s.id DESC LIMIT 5");
    $s->execute([$postId,journeys_now(),journeys_now()]);$contexts=[];
    foreach($s->fetchAll() as $season){$episodes=journeys_public_episodes((int)$season['id']);foreach($episodes as $i=>$episode){if((int)$episode['post_id']===$postId){$contexts[]=['season'=>$season,'current'=>$episode,'previous'=>$episodes[$i-1]??null,'next'=>$episodes[$i+1]??null];break;}}}
    return $contexts;
}
function journeys_writer_season(int $id): ?array { $s=db()->prepare('SELECT * FROM journey_seasons WHERE id=?');$s->execute([$id]);return $s->fetch()?:null; }
function journeys_writer_episodes(int $seasonId): array {
    $s=db()->prepare('SELECT e.*,p.title post_title,p.slug post_slug,p.status post_status,p.published_at post_published_at FROM journey_episodes e JOIN posts p ON p.id=e.post_id WHERE e.season_id=? ORDER BY e.episode_order,e.id');
    $s->execute([$seasonId]);return $s->fetchAll();
}
function journeys_recent_posts(): array { return db()->query('SELECT id,title,status,published_at FROM posts ORDER BY updated_at DESC LIMIT 300')->fetchAll(); }

function journeys_season_values(array $input,?array $existing=null): array {
    $title=journeys_text($input['title']??'','Title',180,true);
    $slug=journeys_text($input['slug']??'','Web address',160);
    if ($slug==='') $slug=substr(slugify($title),0,160);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/',$slug)) throw new JourneyValidationException('Use lowercase letters, numbers and hyphens for the web address.');
    if ($existing && !empty($existing['published_at']) && $slug!==$existing['slug']) throw new JourneyValidationException('The web address stays fixed after first publication so existing links keep working.');
    $status=journeys_choice($input['publication_status']??'draft',journeys_publication_states(),'publication status');
    $baseline=journeys_text($input['baseline']??'','Starting point',5000,$status==='published');
    $summary=journeys_text($input['summary']??'','Summary',1200,$status==='published');
    return ['slug'=>$slug,'title'=>$title,'venture_name'=>journeys_text($input['venture_name']??'','Venture name',180,true),'summary'=>$summary,'baseline'=>$baseline,
        'learning_question'=>journeys_text($input['learning_question']??'','Learning question',1500),'outcomes'=>journeys_text($input['outcomes']??'','Outcomes and lessons',5000),
        'private_notes'=>journeys_text($input['private_notes']??'','Private notes',20000),'started_on'=>journeys_date($input['started_on']??''),
        'phase'=>journeys_choice($input['phase']??'planning',journeys_phases(),'journey phase'),'publication_status'=>$status];
}
function journeys_save_season(array $input,?int $id,int $actor): int {
    $existing=$id?journeys_writer_season($id):null;
    if($id&&!$existing) throw new JourneyValidationException('The journey no longer exists.');
    $v=journeys_season_values($input,$existing);$now=journeys_now();
    $published=$existing['published_at']??null;if($v['publication_status']==='published'&&!$published)$published=$now;
    $pdo=db();$pdo->beginTransaction();
    try {
        // Lock a pre-existing guard rather than only matching rows: the first two
        // concurrent active-season inserts must also serialize.
        $guard=$pdo->query("SELECT lock_name FROM journey_editorial_locks WHERE lock_name='active-season'".journeys_lock_suffix());
        if(!$guard->fetchColumn())throw new RuntimeException('Journey editorial guard is missing.');
        if($v['publication_status']==='published'&&in_array($v['phase'],['planning','building','operating'],true)){
            $active=$pdo->prepare("SELECT id FROM journey_seasons WHERE publication_status='published' AND phase IN ('planning','building','operating') AND id<>? LIMIT 1".journeys_lock_suffix());
            $active->execute([$id??0]);
            if($active->fetchColumn())throw new JourneyValidationException('Only one active Startup Journey can be public at a time. Pause, conclude or archive the current journey before publishing another active venture.');
        }
        if($id){
            $version=journeys_id($input['row_version']??null,'version');
            $s=$pdo->prepare('UPDATE journey_seasons SET slug=?,title=?,venture_name=?,summary=?,baseline=?,learning_question=?,outcomes=?,private_notes=?,started_on=?,phase=?,publication_status=?,published_at=?,updated_at=?,row_version=row_version+1 WHERE id=? AND row_version=?');
            $s->execute([...array_values($v),$published,$now,$id,$version]);
            if($s->rowCount()!==1) throw new JourneyConflictException('This journey changed in another window. Reopen it before saving.');
        } else {
            $s=$pdo->prepare('INSERT INTO journey_seasons(slug,title,venture_name,summary,baseline,learning_question,outcomes,private_notes,started_on,phase,publication_status,published_at,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([...array_values($v),$published,$actor,$now,$now]);$id=(int)$pdo->lastInsertId();
        }
        journeys_audit('season',$id,$existing?'updated':'created',$actor);$pdo->commit();return $id;
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException && (string)$e->getCode()==='23000')throw new JourneyValidationException('That journey web address is already in use. Choose another.');throw $e;}
}
function journeys_episode_values(array $input): array {
    $order=journeys_id($input['episode_order']??null,'chapter number');
    if($order>9999) throw new JourneyValidationException('Chapter numbers must be between 1 and 9999.');
    return ['post_id'=>journeys_id($input['post_id']??null,'post'),'episode_order'=>$order,'public_summary'=>journeys_text($input['public_summary']??'','Chapter summary',600),
        'evidence_basis'=>journeys_choice($input['evidence_basis']??'author_experience',journeys_evidence_bases(),'evidence basis'),
        'evidence_summary'=>journeys_text($input['evidence_summary']??'','Public evidence note',2000),
        'private_evidence_notes'=>journeys_text($input['private_evidence_notes']??'','Private evidence notes',12000),'is_published'=>journeys_checked($input['is_published']??null)?1:0];
}
function journeys_save_episode(int $seasonId,array $input,int $actor): void {
    $v=journeys_episode_values($input);$id=journeys_id($input['episode_id']??null,'chapter',true);
    $s=db()->prepare('SELECT id FROM posts WHERE id=?');$s->execute([$v['post_id']]);if(!$s->fetchColumn())throw new JourneyValidationException('Choose an existing post ID.');
    $pdo=db();$pdo->beginTransaction();
    try {
        $s=$pdo->prepare('SELECT id FROM journey_seasons WHERE id=?'.journeys_lock_suffix());$s->execute([$seasonId]);if(!$s->fetchColumn())throw new JourneyValidationException('The journey no longer exists.');
        if($id){$s=$pdo->prepare('SELECT id FROM journey_episodes WHERE id=? AND season_id=?');$s->execute([$id,$seasonId]);if(!$s->fetchColumn())throw new JourneyValidationException('That chapter does not belong to this journey.');
            $s=$pdo->prepare('UPDATE journey_episodes SET post_id=?,episode_order=?,public_summary=?,evidence_basis=?,evidence_summary=?,private_evidence_notes=?,is_published=?,updated_at=? WHERE id=? AND season_id=?');
            $s->execute([...array_values($v),journeys_now(),$id,$seasonId]);
        }else{$s=$pdo->prepare('INSERT INTO journey_episodes(post_id,episode_order,public_summary,evidence_basis,evidence_summary,private_evidence_notes,is_published,season_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([...array_values($v),$seasonId,journeys_now(),journeys_now()]);$id=(int)$pdo->lastInsertId();}
        $s=$pdo->prepare('UPDATE journey_seasons SET updated_at=?,row_version=row_version+1 WHERE id=?');$s->execute([journeys_now(),$seasonId]);
        journeys_audit('episode',$id,'saved',$actor);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException && (string)$e->getCode()==='23000')throw new JourneyValidationException('This post or chapter number is already linked to the journey. Edit its existing chapter instead.');throw $e;}
}
function journeys_remove_episode(int $seasonId,int $episodeId,int $actor): void {
    $pdo=db();$pdo->beginTransaction();
    try{
        $s=$pdo->prepare('SELECT id FROM journey_seasons WHERE id=?'.journeys_lock_suffix());$s->execute([$seasonId]);
        if(!$s->fetchColumn())throw new JourneyValidationException('The journey no longer exists.');
        $s=$pdo->prepare('DELETE FROM journey_episodes WHERE id=? AND season_id=?');$s->execute([$episodeId,$seasonId]);
        if($s->rowCount()!==1)throw new JourneyValidationException('The chapter was not found in this journey.');
        $s=$pdo->prepare('UPDATE journey_seasons SET updated_at=?,row_version=row_version+1 WHERE id=?');$s->execute([journeys_now(),$seasonId]);
        journeys_audit('episode',$episodeId,'removed',$actor);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function journeys_delete_season(int $id,int $version,int $actor): void {
    $pdo=db();$pdo->beginTransaction();
    try{$s=$pdo->prepare('DELETE FROM journey_seasons WHERE id=? AND row_version=?');$s->execute([$id,$version]);if($s->rowCount()!==1)throw new JourneyConflictException('The journey changed or was already removed. Reopen the list.');journeys_audit('season',$id,'deleted',$actor);$pdo->commit();}
    catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function founders_submission_values(array $input): array {
    if(!journeys_checked($input['privacy_ack']??null))throw new JourneyValidationException('Please agree to the private handling and review of your submission.');
    $type=journeys_choice($input['submission_type']??'', ['founder'=>'Founder','nomination'=>'Nomination'],'submission type');
    $metrics=journeys_text($input['claimed_metrics']??'','Progress and metrics',3000);
    $basis=journeys_choice($input['metric_basis']??'',founders_metric_bases(),'basis for numerical claims');
    if($metrics!==''&&$basis==='not_applicable')throw new JourneyValidationException('Choose how your progress or numerical claims are supported, or leave that field empty.');
    $values=['submission_type'=>$type,'submitter_name'=>journeys_text($input['submitter_name']??'','Your name',120,true),'submitter_email'=>journeys_email($input['submitter_email']??''),
        'founder_name'=>journeys_text($input['founder_name']??'','Founder name',120,true),'venture_name'=>journeys_text($input['venture_name']??'','Venture name',180,true),
        'country'=>journeys_text($input['country']??'','Country',120,true),'story_summary'=>journeys_text($input['story_summary']??'','Story',6000,true),
        'claimed_metrics'=>$metrics,'metric_basis'=>$basis,'private_evidence_notes'=>journeys_text($input['private_evidence_notes']??'','Private evidence notes',5000),
        'founder_publication_permission'=>($type==='founder'&&journeys_checked($input['founder_publication_permission']??null))?1:0];
    if(array_intersect(['county','sub_county','ward','region','district','city','phone','phone_code'],array_keys($input))){try{$values['_location']=location_fields($input)+location_phone($input);}catch(InvalidArgumentException $e){throw new JourneyValidationException($e->getMessage());}}
    return $values;
}
function founders_submit(array $values): string {
    $profile=$values['_location']??null;unset($values['_location']);
    $ref='FOUNDER-'.strtoupper(bin2hex(random_bytes(8)));$now=journeys_now();$pdo=db();$own=!$pdo->inTransaction();if($own)$pdo->beginTransaction();
    try{
     $s=$pdo->prepare('INSERT INTO founder_submissions(reference,submission_type,submitter_name,submitter_email,founder_name,venture_name,country,story_summary,claimed_metrics,metric_basis,private_evidence_notes,founder_publication_permission,privacy_version,privacy_accepted_at,ip_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
     $s->execute([$ref,...array_values($values),'2026-10-21-v1',$now,ip_hash(),$now,$now]);$id=(int)$pdo->lastInsertId();
     if(is_array($profile))location_record_save('founder',$id,$profile);
     if($own)$pdo->commit();
    }catch(Throwable $e){if($own&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    if(function_exists('reader_founder_received'))reader_founder_received($ref);
    return $ref;
}
function founders_public_where(): string {
    return "f.review_status='approved' AND f.is_public=1 AND f.permission_confirmed=1 AND f.claims_reviewed=1 AND f.consent_reference IS NOT NULL AND f.consent_reference<>''
        AND p.status IN ('published','scheduled') AND p.published_at IS NOT NULL AND p.published_at<=?".(function_exists('founder_story_public_sql')?' AND '.founder_story_public_sql():'');
}
function founders_public_story_count(): int {
    $s=db()->prepare('SELECT COUNT(DISTINCT p.id) FROM founder_submissions f JOIN posts p ON p.id=f.associated_post_id WHERE '.founders_public_where());
    $s->execute([journeys_now()]);return (int)$s->fetchColumn();
}
function founders_public_stories(int $limit=60,int $offset=0): array {
    $s=db()->prepare("SELECT DISTINCT p.id,p.slug,p.title,p.standfirst,p.featured_image,p.featured_image_alt,p.published_at
        FROM founder_submissions f JOIN posts p ON p.id=f.associated_post_id
        WHERE ".founders_public_where()." ORDER BY p.published_at DESC,p.id DESC LIMIT ".max(1,min(100,$limit)).' OFFSET '.max(0,$offset));
    $s->execute([journeys_now()]);return $s->fetchAll();
}
function founders_review_values(array $input,?array $post=null): array {
    $status=journeys_choice($input['review_status']??'',founders_review_states(),'review status');
    $permission=journeys_checked($input['permission_confirmed']??null);$claims=journeys_checked($input['claims_reviewed']??null);$public=journeys_checked($input['is_public']??null);
    $consent=journeys_text($input['consent_reference']??'','Consent reference',500);
    if($permission&&$consent==='')throw new JourneyValidationException('Record where the founder’s publication permission is held.');
    if($public&&($status!=='approved'||!$permission||!$claims||$consent===''||!$post||!journeys_post_is_public($post)))throw new JourneyValidationException('To show a founder story, approve it, confirm permission and claims review, and link an already published post.');
    return ['review_status'=>$status,'review_notes'=>journeys_text($input['review_notes']??'','Private review notes',15000),'consent_reference'=>$consent,
        'permission_confirmed'=>$permission?1:0,'claims_reviewed'=>$claims?1:0,'associated_post_id'=>$post?(int)$post['id']:null,'is_public'=>$public?1:0];
}
function founders_save_review(int $id,array $input,int $actor): void {
    $postId=journeys_id($input['associated_post_id']??null,'associated post',true);$post=null;
    if($postId&&function_exists('founder_story_schema_ready')&&!founder_story_schema_ready())throw new JourneyValidationException('Final-copy approval setup is still being upgraded. Linked story moderation will resume after the upgrade.');
    if(function_exists('founder_story_workflow')){$binding=db()->prepare('SELECT associated_post_id FROM founder_submissions WHERE id=?');$binding->execute([$id]);$bound=(int)$binding->fetchColumn();if($bound&&$bound!==$postId)throw new JourneyValidationException('A linked Founder Story cannot be detached or moved to bypass its final-copy approval.');}
    if(function_exists('founder_story_workflow')){$workflow=founder_story_workflow($id);if($workflow&&($workflow['state']==='discarded'||(int)$workflow['post_id']!==$postId))throw new JourneyValidationException('A discarded story cannot be resubmitted, and its approval workflow cannot be moved to another post.');}
    if($postId){$s=db()->prepare('SELECT id,status,published_at FROM posts WHERE id=?');$s->execute([$postId]);$post=$s->fetch()?:null;if(!$post)throw new JourneyValidationException('The associated post does not exist.');}
    $v=founders_review_values($input,$post);$version=journeys_id($input['row_version']??null,'version');$pdo=db();$pdo->beginTransaction();
    try{
        if($v['is_public']&&function_exists('founder_story_workflow')){$story=founder_story_lock_post((int)$postId);$workflow=founder_story_workflow($id,true);if(!$workflow||$workflow['state']!=='approved'||!hash_equals((string)$workflow['approved_hash'],founder_story_hash($story)))throw new JourneyValidationException('The founder must approve the exact final story through their private review link first.');}
        if($postId&&function_exists('founder_story_workflow')){$check=$pdo->prepare('SELECT id FROM founder_submissions WHERE associated_post_id=? AND id<>? LIMIT 1');$check->execute([$postId,$id]);if($check->fetchColumn())throw new JourneyValidationException('This article is already linked to another founder.');}
        if($postId&&function_exists('founder_story_workflow')){$story=founder_story_lock_post((int)$postId);$workflow=founder_story_workflow($id,true);if(!$workflow||!is_string($workflow['approved_hash'])||!hash_equals($workflow['approved_hash'],founder_story_hash($story)))$pdo->prepare("UPDATE posts SET status='draft',founder_approval_hash=NULL WHERE id=?")->execute([$postId]);}
        $s=$pdo->prepare('UPDATE founder_submissions SET review_status=?,review_notes=?,consent_reference=?,permission_confirmed=?,claims_reviewed=?,associated_post_id=?,is_public=?,reviewed_by=?,reviewed_at=?,updated_at=?,row_version=row_version+1 WHERE id=? AND row_version=?');
        $s->execute([...array_values($v),$actor,journeys_now(),journeys_now(),$id,$version]);if($s->rowCount()!==1)throw new JourneyConflictException('This submission changed in another window. Reopen it before saving.');if(function_exists('founder_story_workflow')&&in_array($v['review_status'],['declined','withdrawn'],true)){$closedWorkflow=founder_story_workflow($id,true);if($closedWorkflow)founder_story_discard($closedWorkflow,'editorial_'.$v['review_status'],$actor);}journeys_audit('founder',$id,'review_saved',$actor);if(function_exists('reader_founder_reviewed'))reader_founder_reviewed($id,$actor);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function newsletter_token_hash(string $token): ?string { return preg_match('/^[a-f0-9]{64}$/',$token)?hash('sha256',$token):null; }
function newsletter_create_token(int $subscriberId,string $purpose,?string $expires): string {
    if(!in_array($purpose,['confirm','unsubscribe'],true))throw new InvalidArgumentException('Unknown newsletter token purpose.');
    $issuedEmailHash=null;
    if($purpose==='confirm'){
        $s=db()->prepare('SELECT email FROM newsletter_subscribers WHERE id=?');$s->execute([$subscriberId]);$email=$s->fetchColumn();
        if(!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Subscriber does not exist or has an invalid email address.');
        $issuedEmailHash=hash('sha256',strtolower(trim($email)));
    }
    $token=bin2hex(random_bytes(32));$s=db()->prepare('INSERT INTO newsletter_tokens(subscriber_id,purpose,token_hash,expires_at,created_at,issued_email_hash) VALUES(?,?,?,?,?,?)');
    $s->execute([$subscriberId,$purpose,hash('sha256',$token),$expires,journeys_now(),$issuedEmailHash]);return $token;
}
/** Generate a per-message unsubscribe URL. Store only its hash, never the token. */
function newsletter_unsubscribe_url(int $subscriberId): string {
    $s=db()->prepare('SELECT id FROM newsletter_subscribers WHERE id=?');$s->execute([$subscriberId]);if(!$s->fetchColumn())throw new InvalidArgumentException('Subscriber does not exist.');
    return url('/newsletter/unsubscribe?token='.newsletter_create_token($subscriberId,'unsubscribe',null));
}
function newsletter_is_confirmed(string $email): bool { $s=db()->prepare("SELECT id FROM newsletter_subscribers WHERE email=? AND status='confirmed'");$s->execute([strtolower(trim($email))]);return (bool)$s->fetchColumn(); }
/** Private issuance proof; never add an address or hash to the public token record. */
function newsletter_confirmation_address_matches(string $tokenHash,string $currentEmail,?string $queuedRecipient=null): bool {
    if(!preg_match('/^[a-f0-9]{64}$/D',$tokenHash)||!filter_var($currentEmail,FILTER_VALIDATE_EMAIL))return false;
    $s=db()->prepare("SELECT issued_email_hash FROM newsletter_tokens WHERE token_hash=? AND purpose='confirm'");$s->execute([$tokenHash]);$token=$s->fetch();if(!$token)return false;
    $issuedHash=$token['issued_email_hash'];
    if($issuedHash===null){
        // Restored tokens predate issuance hashes. The original immutable outbox
        // recipient is evidence; the subscriber's current address is not.
        if(!function_exists('decrypt_secret'))return false;
        $dedupeKey='newsletter-confirm:'.$tokenHash;
        $s=db()->prepare('SELECT payload FROM email_outbox WHERE dedupe_key=?');$s->execute([hash('sha256',$dedupeKey)]);$encrypted=$s->fetchColumn();
        if(!is_string($encrypted)||$encrypted==='')return false;
        try{$payload=json_decode(decrypt_secret($encrypted),true,32,JSON_THROW_ON_ERROR);}catch(JsonException $error){return false;}
        if(!is_array($payload)||($payload['dedupe_key']??null)!==$dedupeKey||!is_string($payload['to']??null)||!filter_var($payload['to'],FILTER_VALIDATE_EMAIL))return false;
        $issuedHash=hash('sha256',strtolower(trim($payload['to'])));
    }
    if(!is_string($issuedHash)||!preg_match('/^[a-f0-9]{64}$/D',$issuedHash)||!hash_equals($issuedHash,hash('sha256',strtolower(trim($currentEmail)))))return false;
    return $queuedRecipient===null || (filter_var($queuedRecipient,FILTER_VALIDATE_EMAIL)!==false&&hash_equals($issuedHash,hash('sha256',strtolower(trim($queuedRecipient)))));
}
/** Email workers should cancel stale confirmation jobs before any delivery call. */
function newsletter_confirmation_deliverable(string $dedupeKey,?string $queuedRecipient=null): bool {
    if(!preg_match('/^newsletter-confirm:([a-f0-9]{64})$/',$dedupeKey,$match))return false;
    $s=db()->prepare("SELECT s.email FROM newsletter_tokens t JOIN newsletter_subscribers s ON s.id=t.subscriber_id WHERE t.token_hash=? AND t.purpose='confirm' AND t.used_at IS NULL AND t.expires_at>? AND s.status='pending' AND s.suppressed_at IS NULL LIMIT 1");
    $s->execute([$match[1],journeys_now()]);$email=$s->fetchColumn();
    return $email && newsletter_confirmation_address_matches($match[1],(string)$email,$queuedRecipient)
        && (!function_exists('newsletter_address_suppressed') || !newsletter_address_suppressed((string)$email));
}
function newsletter_request_signup(string $email,string $name,?array $identity=null): void {
    $email=journeys_email($email);if(mb_strlen($email)>190)throw new JourneyValidationException('Email address is too long.');$name=journeys_text($name,'Name',120);
    $first=$identity===null?'':journeys_text($identity['first_name']??'','First name',60,true);
    $last=$identity===null?'':journeys_text($identity['last_name']??'','Last name',59,true);
    if($identity!==null)$name=$first.' '.$last;
    if(function_exists('newsletter_address_suppressed') && newsletter_address_suppressed($email))return;
    if(!function_exists('enqueue_email'))throw new RuntimeException('Email queue is not available.');
    $pdo=db();$lockedId=null;
    $existing=$pdo->prepare('SELECT id FROM newsletter_subscribers WHERE email=?');$existing->execute([$email]);$existingId=(int)$existing->fetchColumn();
    if($existingId && function_exists('newsletter_subscriber_lock')){newsletter_subscriber_lock($existingId,30);$lockedId=$existingId;}
    $pdo->beginTransaction();
    try{
        if(function_exists('newsletter_address_suppressed') && newsletter_address_suppressed($email)){$pdo->commit();return;}
        $s=$pdo->prepare('SELECT id,status FROM newsletter_subscribers WHERE email=?'.journeys_lock_suffix());$s->execute([$email]);$subscriber=$s->fetch();$now=journeys_now();
        if($subscriber&&$subscriber['status']==='confirmed'){$pdo->commit();return;}
        if($subscriber){$id=(int)$subscriber['id'];$s=$pdo->prepare("UPDATE newsletter_subscribers SET name=?,status='pending',consent_version=?,requested_at=?,confirmed_at=NULL,updated_at=?,consent_revision=consent_revision+1 WHERE id=?");$s->execute([$name,'2026-10-21-v1',$now,$now,$id]);}
        else{$s=$pdo->prepare("INSERT INTO newsletter_subscribers(email,name,status,consent_version,requested_at,created_at,updated_at) VALUES(?,?,'pending',?,?,?,?)");$s->execute([$email,$name,'2026-10-21-v1',$now,$now,$now]);$id=(int)$pdo->lastInsertId();}
        $pdo->prepare('UPDATE newsletter_subscribers SET first_name=?,last_name=? WHERE id=?')->execute([$first,$last,$id]);
        $s=$pdo->prepare("UPDATE newsletter_tokens SET used_at=? WHERE subscriber_id=? AND purpose='confirm' AND used_at IS NULL");$s->execute([$now,$id]);
        if(function_exists('reader_capture_signup_preferences'))reader_capture_signup_preferences($id);
        $token=newsletter_create_token($id,'confirm',date('Y-m-d H:i:s',time()+86400));$confirm=url('/newsletter/confirm?token='.$token);$unsubscribe=newsletter_unsubscribe_url($id);
        $html=email_design('One small step to stay connected','<p>Thank you for your interest in My Thoughts. Please confirm your email to receive new writing, Startup Journeys and reflections from the work along the way.</p>'.email_design_button('Confirm my subscription',$confirm).'<p>This link expires in 24 hours. You will receive newsletter editions only after you confirm.</p><p>If you did not ask to subscribe, ignore this email or <a href="'.e($unsubscribe).'">cancel the request</a>.</p>',['name'=>$first!==''?$first:$name,'preheader'=>'Confirm your email to join My Thoughts.','footer'=>'<p>This email confirms a subscription request. <a href="'.e($unsubscribe).'">Cancel this request</a> if it was not yours.</p>']);
        enqueue_email($email,'Confirm your subscription · Waithaka Ndegwa',$html,'newsletter-confirm:'.hash('sha256',$token));
        $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{if($lockedId)newsletter_subscriber_unlock($lockedId);}
}
function newsletter_token_record(string $token,string $purpose,bool $lock=false): ?array {
    $hash=newsletter_token_hash($token);if(!$hash||!in_array($purpose,['confirm','unsubscribe'],true))return null;
    $s=db()->prepare('SELECT t.id token_id,t.subscriber_id,t.purpose,t.expires_at,t.used_at,s.status FROM newsletter_tokens t JOIN newsletter_subscribers s ON s.id=t.subscriber_id WHERE t.token_hash=? AND t.purpose=? LIMIT 1'.($lock?journeys_lock_suffix():''));
    $s->execute([$hash,$purpose]);$r=$s->fetch();if(!$r)return null;
    if($purpose==='confirm'&&(!empty($r['used_at'])||(!empty($r['expires_at'])&&$r['expires_at']<=journeys_now())))return null;
    return $r;
}
function newsletter_apply_token(string $token,string $purpose,bool $receipt=false): bool {
    $initial=newsletter_token_record($token,$purpose);
    if(!$initial)return false;
    $id=(int)$initial['subscriber_id'];$locked=false;
    if(function_exists('newsletter_subscriber_lock')){newsletter_subscriber_lock($id,30);$locked=true;}
    $pdo=db();$pdo->beginTransaction();
    try{$record=newsletter_token_record($token,$purpose,true);if(!$record){$pdo->rollBack();return false;}$now=journeys_now();$id=(int)$record['subscriber_id'];
        if($purpose==='confirm'){
            $emailQuery=$pdo->prepare('SELECT email FROM newsletter_subscribers WHERE id=?');$emailQuery->execute([$id]);$subscriberEmail=(string)$emailQuery->fetchColumn();
            if(!newsletter_confirmation_address_matches((string)newsletter_token_hash($token),$subscriberEmail)){$pdo->rollBack();return false;}
            if(function_exists('newsletter_address_suppressed') && newsletter_address_suppressed($subscriberEmail)){$pdo->rollBack();return false;}
            $s=$pdo->prepare("UPDATE newsletter_subscribers SET status='confirmed',confirmed_at=?,unsubscribed_at=NULL,updated_at=?,consent_revision=consent_revision+1 WHERE id=? AND status='pending' AND suppressed_at IS NULL");$s->execute([$now,$now,$id]);if($s->rowCount()!==1){$pdo->rollBack();return false;}
        }else{$s=$pdo->prepare("UPDATE newsletter_subscribers SET status='unsubscribed',unsubscribed_at=?,updated_at=?,consent_revision=consent_revision+1 WHERE id=? AND status<>'unsubscribed'");$s->execute([$now,$now,$id]);}
        $changed=$s->rowCount()>0;
        $s=$pdo->prepare("UPDATE newsletter_tokens SET used_at=? WHERE subscriber_id=? AND purpose='confirm' AND used_at IS NULL");$s->execute([$now,$id]);
        if($purpose==='confirm'&&function_exists('newsletter_queue_lifecycle'))newsletter_queue_lifecycle($id,'welcome');
        elseif($purpose==='unsubscribe'&&$receipt&&$changed&&function_exists('newsletter_queue_lifecycle'))newsletter_queue_lifecycle($id,'goodbye');
        journeys_audit('newsletter',$id,$purpose==='confirm'?'confirmed':'unsubscribed');$pdo->commit();return true;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}finally{if($locked)newsletter_subscriber_unlock($id);}
}

function journeys_rss_xml(array $posts,?string $now=null): string {
    $esc=static function(string $value):string{
        $value=mb_convert_encoding($value,'UTF-8','UTF-8');
        $value=preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u','',$value)??'';
        return htmlspecialchars($value,ENT_QUOTES|ENT_XML1|ENT_SUBSTITUTE,'UTF-8');
    };
    $xml='<?xml version="1.0" encoding="UTF-8"?>'."\n".'<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>Waithaka Ndegwa — My Thoughts</title><link>'.$esc(url('/')).'</link><description>Personal writing and Startup Journeys from Waithaka Ndegwa.</description><language>en</language><atom:link href="'.$esc(url('/feed.xml')).'" rel="self" type="application/rss+xml"/>';
    foreach($posts as $p){if(!journeys_post_is_public($p,$now))continue;$link=url('/my-thoughts/'.$p['slug']);$summary=(string)($p['standfirst']?:excerpt((string)$p['content_html'],300));$xml.='<item><title>'.$esc((string)$p['title']).'</title><link>'.$esc($link).'</link><guid isPermaLink="true">'.$esc($link).'</guid><pubDate>'.$esc(date(DATE_RSS,strtotime((string)$p['published_at']))).'</pubDate><description>'.$esc($summary).'</description></item>';}
    return $xml.'</channel></rss>';
}

