<?php
declare(strict_types=1);

/**
 * Integration assertions for a dedicated, disposable MySQL/MariaDB database.
 * Apply app/schema.sql and numbered migrations before running this script.
 * Required: JOURNEYS_TEST_DSN, JOURNEYS_TEST_USER, JOURNEYS_TEST_PASSWORD,
 *           JOURNEYS_TEST_ALLOW_WRITES=1.
 * The database name must contain a separate "test" segment. No application
 * bootstrap is loaded and the email transport is a local in-memory test double.
 */
if(PHP_SAPI!=='cli')exit(1);
$testDsn=getenv('JOURNEYS_TEST_DSN')?:'';
if(getenv('JOURNEYS_TEST_ALLOW_WRITES')!=='1'||!str_starts_with($testDsn,'mysql:')){
    fwrite(STDERR,"Refusing to run without an explicitly authorized disposable MySQL test database.\n");exit(2);
}
$journeysTestDb=new PDO($testDsn,getenv('JOURNEYS_TEST_USER')?:'',getenv('JOURNEYS_TEST_PASSWORD')?:'',[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
]);
$testDatabase=(string)$journeysTestDb->query('SELECT DATABASE()')->fetchColumn();
if(!preg_match('/(^|_)test($|_)/',$testDatabase)){
    fwrite(STDERR,"Refusing to write to a database without a test name.\n");exit(2);
}
date_default_timezone_set('Africa/Nairobi');
$journeysTestEmails=[];$checks=0;$prefix='journey-test-'.bin2hex(random_bytes(5));

function db(): PDO { return $GLOBALS['journeysTestDb']; }
function app_config(?string $key=null,mixed $default=null): mixed { return $key==='app_key'?'isolated-journeys-test-key':$default; }
function ip_hash(): string { return hash('sha256','journeys-test-connection'); }
function url(string $path=''): string { return 'https://www.waithakandegwa.com'.($path===''?'/':$path); }
function e(?string $text): string { return htmlspecialchars((string)$text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function slugify(string $text): string { return trim((string)preg_replace('/[^a-z0-9]+/','-',strtolower($text)),'-'); }
function excerpt(string $text,int $length=160): string { return mb_substr(trim(strip_tags($text)),0,$length); }
function enqueue_email(string $to,string $subject,string $html,string $dedupeKey): int {
    $GLOBALS['journeysTestEmails'][]=compact('to','subject','html','dedupeKey');return count($GLOBALS['journeysTestEmails']);
}
function check(bool $condition,string $label): void {
    if(!$condition)throw new RuntimeException('FAILED: '.$label);$GLOBALS['checks']++;
}
function rejects(callable $action,string $label,string $exception=JourneyValidationException::class): void {
    try{$action();}catch(Throwable $error){check($error instanceof $exception,$label.' (expected exception class)');return;}
    throw new RuntimeException('FAILED: '.$label.' (accepted invalid action)');
}
function fixture_post(string $suffix,string $status,?string $published): int {
    $s=db()->prepare('INSERT INTO posts(user_id,title,slug,standfirst,content_html,status,published_at) VALUES(?,?,?,?,?,?,?)');
    $s->execute([$GLOBALS['actor'],'Test & <story> '.$suffix,$GLOBALS['prefix'].'-'.$suffix,'A safe summary & context.','<p>Article body.</p>',$status,$published]);return (int)db()->lastInsertId();
}
function fixture_season(string $suffix,array $changes=[]): array {
    return array_merge(['slug'=>$GLOBALS['prefix'].'-'.$suffix,'title'=>'Test journey '.$suffix,'venture_name'=>'Integration fixture','summary'=>'A public summary.','baseline'=>'A measured starting point.','learning_question'=>'What changes?','private_notes'=>'PRIVATE_SEASON_MARKER','phase'=>'building','publication_status'=>'draft','started_on'=>'2026-01-01'],$changes);
}
function saved_season_values(int $id,array $changes=[]): array { return array_merge(journeys_writer_season($id)??[],$changes); }
function find_newsletter_token(string $purpose): string {
    $last=end($GLOBALS['journeysTestEmails']);
    if(!$last||!preg_match('~/newsletter/'.$purpose.'\?token=([a-f0-9]{64})~',$last['html'],$match))throw new RuntimeException('Expected queued newsletter token is missing.');
    return $match[1];
}

require __DIR__.'/../app/journeys.php';

$testExitCode=0;
try{
    check((int)db()->query("SELECT COUNT(*) FROM journey_seasons WHERE publication_status='published' AND phase IN ('planning','building','operating')")->fetchColumn()===0,'fixture database has no active public journey');
    $s=db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');$s->execute(['Integration test',$prefix.'@example.invalid',password_hash(bin2hex(random_bytes(18)),PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();

    rejects(fn()=>journeys_email("reader@example.com\r\nBcc: extra@example.com"),'email header injection is rejected');
    rejects(fn()=>journeys_text(['nested'],'Title',20),'array-shaped fields are rejected');
    rejects(fn()=>journeys_text(str_repeat('x',181),'Title',180),'field size limit is enforced');
    rejects(fn()=>journeys_date('2026-02-30'),'invalid calendar dates are rejected');
    check(journeys_field(['name'=>['nested']],'name')==='','invalid form fields redisplay safely');

    $season=journeys_save_season(fixture_season('first'),null,$actor);
    check(journeys_public_season($prefix.'-first')===null,'private journey is not publicly retrievable');
    rejects(fn()=>journeys_save_season(saved_season_values($season,['publication_status'=>'published','baseline'=>'']),$season,$actor),'publication requires a baseline');
    journeys_save_season(saved_season_values($season,['publication_status'=>'published']),$season,$actor);
    $public=journeys_public_season($prefix.'-first');
    check($public!==null,'a published journey is retrievable');
    check(!array_key_exists('private_notes',$public),'public journey projection omits private notes');
    check(journeys_home_feature()['id']===$public['id'],'homepage feature is a real published season');
    rejects(fn()=>journeys_save_season(saved_season_values($season,['slug'=>$prefix.'-changed']),$season,$actor),'published web addresses stay stable');
    rejects(fn()=>journeys_save_season(fixture_season('second',['publication_status'=>'published']),null,$actor),'a second active public venture is rejected');
    $privateSecond=journeys_save_season(fixture_season('second'),null,$actor);
    check(journeys_writer_season($privateSecond)!==null,'a second private draft is allowed');
    $paused=journeys_save_season(fixture_season('paused',['publication_status'=>'published','phase'=>'paused']),null,$actor);
    check(journeys_public_season($prefix.'-paused')!==null,'paused journey history stays accessible');
    check((int)journeys_home_feature()['id']===$season,'homepage prefers the active venture over historical journeys');
    $stale=saved_season_values($season);journeys_save_season($stale,$season,$actor);
    rejects(fn()=>journeys_save_season($stale,$season,$actor),'stale edits are rejected',JourneyConflictException::class);

    $past=date('Y-m-d H:i:s',time()-86400);$future=date('Y-m-d H:i:s',time()+86400);
    $published=fixture_post('published','published',$past);$draft=fixture_post('draft','draft',$past);$scheduled=fixture_post('due','scheduled',$past);$scheduledFuture=fixture_post('future','scheduled',$future);$archived=fixture_post('archived','archived',$past);$hidden=fixture_post('hidden-link','published',$past);
    foreach([[$draft,1],[$published,2],[$scheduledFuture,3],[$scheduled,4],[$archived,5],[$hidden,6]] as [$postId,$order]){
        journeys_save_episode($season,['post_id'=>(string)$postId,'episode_order'=>(string)$order,'evidence_basis'=>'records','evidence_summary'=>'Public context.','private_evidence_notes'=>'PRIVATE_EVIDENCE_MARKER','is_published'=>$postId===$hidden?'0':'1'],$actor);
    }
    $episodes=journeys_public_episodes($season);
    check(array_map('intval',array_column($episodes,'post_id'))===[$published,$scheduled],'only public due posts with enabled links appear in sequence');
    check(!str_contains(json_encode($episodes),'PRIVATE_'),'chapter data excludes private evidence');
    check(!array_key_exists('private_evidence_notes',$episodes[0]),'chapter projection has no private evidence key');
    $contexts=journeys_post_context($scheduled);
    check(count($contexts)===1&&(int)$contexts[0]['previous']['post_id']===$published&&$contexts[0]['next']===null,'article navigation uses adjacent public chapters');
    check(journeys_post_context($draft)===[],'draft post has no public journey context');
    rejects(fn()=>journeys_save_episode($season,['post_id'=>(string)$published,'episode_order'=>'9'],$actor),'a post cannot be linked twice to one journey');
    rejects(fn()=>journeys_save_episode($season,['post_id'=>'2147483647','episode_order'=>'9'],$actor),'chapter links require an existing post');
    $s=db()->prepare('SELECT id FROM journey_episodes WHERE season_id=? AND post_id=?');$s->execute([$season,$hidden]);$hiddenEpisode=(int)$s->fetchColumn();
    rejects(fn()=>journeys_remove_episode($privateSecond,$hiddenEpisode,$actor),'chapter removal is scoped to its journey');
    journeys_remove_episode($season,$hiddenEpisode,$actor);
    $s=db()->prepare('SELECT id FROM posts WHERE id=?');$s->execute([$hidden]);check((int)$s->fetchColumn()===$hidden,'removing a chapter preserves its post');
    $entries=journeys_sitemap_entries();$locations=array_column($entries,'loc');
    check(in_array(url('/startup-journeys/'.$prefix.'-first'),$locations,true),'sitemap includes public journeys');
    check(!in_array(url('/startup-journeys/'.$prefix.'-second'),$locations,true),'sitemap excludes private journeys');
    check(!str_contains(json_encode($entries),'newsletter/confirm'),'sitemap contains no private token routes');
    $s=db()->prepare('UPDATE journey_seasons SET published_at=? WHERE id=?');$s->execute([$future,$season]);
    check(journeys_public_season($prefix.'-first')===null&&journeys_public_episodes($season)===[],'future journey publication suppresses detail and chapters');
    $s->execute([$past,$season]);

    $founderInput=['privacy_ack'=>'1','submission_type'=>'nomination','submitter_name'=>'Test nominator','submitter_email'=>$prefix.'-nominator@example.invalid','founder_name'=>'Test founder','venture_name'=>'Test venture','country'=>'Kenya','story_summary'=>'PRIVATE_STORY_MARKER','claimed_metrics'=>'10 customers, as of a specified date.','metric_basis'=>'self_reported','private_evidence_notes'=>'PRIVATE_FOUNDER_EVIDENCE','founder_publication_permission'=>'1'];
    $founderValues=founders_submission_values($founderInput);
    check($founderValues['founder_publication_permission']===0,'a nominator cannot grant founder publication permission');
    rejects(fn()=>founders_submission_values(array_merge($founderInput,['privacy_ack'=>'0'])),'private handling consent is required');
    rejects(fn()=>founders_submission_values(array_merge($founderInput,['metric_basis'=>'not_applicable'])),'numerical claims require a declared basis');
    $reference=founders_submit($founderValues);$s=db()->prepare('SELECT * FROM founder_submissions WHERE reference=?');$s->execute([$reference]);$submission=$s->fetch();$founderId=(int)$submission['id'];
    check($submission['review_status']==='new'&&!(bool)$submission['is_public'],'new founder submissions remain private');
    check(founders_public_stories()===[],'unreviewed submission does not create a public story');
    $approval=['review_status'=>'approved','row_version'=>'1','permission_confirmed'=>'1','claims_reviewed'=>'1','consent_reference'=>'Test permission record','associated_post_id'=>(string)$published,'is_public'=>'1'];
    rejects(fn()=>founders_save_review($founderId,array_merge($approval,['permission_confirmed'=>'0']),$actor),'public founder stories require recorded permission');
    rejects(fn()=>founders_save_review($founderId,array_merge($approval,['claims_reviewed'=>'0']),$actor),'public founder stories require a claims review');
    rejects(fn()=>founders_save_review($founderId,array_merge($approval,['consent_reference'=>'']),$actor),'public founder stories require a permission reference');
    rejects(fn()=>founders_save_review($founderId,array_merge($approval,['associated_post_id'=>(string)$draft]),$actor),'public founder stories cannot point to a draft');
    founders_save_review($founderId,$approval,$actor);$stories=founders_public_stories();
    check(count($stories)===1&&(int)$stories[0]['id']===$published,'explicit approval exposes only the associated public article');
    check(!str_contains(json_encode($stories),'PRIVATE_')&&!array_key_exists('submitter_email',$stories[0]),'public founder projection contains no submission/contact/evidence data');
    $s=db()->prepare("UPDATE posts SET status='draft' WHERE id=?");$s->execute([$published]);
    check(founders_public_stories()===[],'unpublishing the associated article removes the founder story');
    $s=db()->prepare("UPDATE posts SET status='published' WHERE id=?");$s->execute([$published]);
    rejects(fn()=>founders_save_review($founderId,$approval,$actor),'stale founder reviews are rejected',JourneyConflictException::class);
    founders_save_review($founderId,array_merge($approval,['row_version'=>'2','review_status'=>'withdrawn','is_public'=>'0']),$actor);
    check(founders_public_stories()===[],'withdrawn founder story leaves the public collection');

    $subscriberEmail=$prefix.'-reader@example.invalid';newsletter_request_signup($subscriberEmail,'Test reader');
    check(count($journeysTestEmails)===1,'signup queues one confirmation email');
    check(!newsletter_is_confirmed($subscriberEmail),'a pending signup is not subscribed');
    $confirmation=find_newsletter_token('confirm');$unsubscribe=find_newsletter_token('unsubscribe');
    $confirmationKey='newsletter-confirm:'.hash('sha256',$confirmation);
    check(newsletter_confirmation_deliverable($confirmationKey),'a current pending confirmation is eligible for delivery');
    $tokenRows=db()->query('SELECT token_hash FROM newsletter_tokens')->fetchAll(PDO::FETCH_COLUMN);
    check(in_array(hash('sha256',$confirmation),$tokenRows,true)&&!in_array($confirmation,$tokenRows,true),'confirmation token is stored only as a hash in its token table');
    $record=newsletter_token_record($confirmation,'confirm');
    check($record!==null&&!newsletter_is_confirmed($subscriberEmail),'reading a confirmation token does not activate the subscription');
    check(!array_key_exists('email',$record),'token lookup does not disclose the email address');
    check(newsletter_apply_token($confirmation,'confirm')&&newsletter_is_confirmed($subscriberEmail),'confirmation activates a pending subscription');
    check(!newsletter_confirmation_deliverable($confirmationKey),'confirmation jobs become obsolete after activation');
    check(!newsletter_apply_token($confirmation,'confirm'),'confirmation token is single use');
    newsletter_request_signup($subscriberEmail,'Different requester');
    check(count($journeysTestEmails)===1,'confirmed address does not trigger repeated signup messages');
    check(newsletter_token_record($unsubscribe,'unsubscribe')!==null&&newsletter_is_confirmed($subscriberEmail),'reading an unsubscribe token does not alter state');
    check(newsletter_apply_token($unsubscribe,'unsubscribe')&&!newsletter_is_confirmed($subscriberEmail),'unsubscribe stops the subscription');
    check(newsletter_apply_token($unsubscribe,'unsubscribe'),'unsubscribe remains idempotent');
    newsletter_request_signup($subscriberEmail,'Test reader');$oldPending=find_newsletter_token('confirm');
    check(!newsletter_is_confirmed($subscriberEmail),'re-subscription requires fresh confirmation');
    newsletter_request_signup($subscriberEmail,'Test reader');$newPending=find_newsletter_token('confirm');
    check($oldPending!==$newPending&&!newsletter_apply_token($oldPending,'confirm'),'a new request invalidates an older pending confirmation');
    $s=db()->prepare('UPDATE newsletter_tokens SET expires_at=? WHERE token_hash=?');$s->execute([$past,hash('sha256',$newPending)]);
    check(!newsletter_apply_token($newPending,'confirm'),'expired confirmation tokens are rejected');
    check(!newsletter_confirmation_deliverable('newsletter-confirm:'.hash('sha256',$newPending)),'expired confirmations are excluded from email delivery');
    newsletter_request_signup($subscriberEmail,'Test reader');$cancelled=find_newsletter_token('confirm');
    check(newsletter_apply_token($unsubscribe,'unsubscribe')&&!newsletter_apply_token($cancelled,'confirm'),'unsubscribe cancels outstanding confirmation tokens');
    check(newsletter_token_record(str_repeat('0',64),'confirm')===null&&newsletter_token_record('../invalid','confirm')===null,'unknown and malformed tokens are rejected');

    check(journeys_rate_limit('journey-test',$prefix,2),'rate limiter accepts the first request');
    check(journeys_rate_limit('journey-test',$prefix,2),'rate limiter accepts the final allowed request');
    check(!journeys_rate_limit('journey-test',$prefix,2),'rate limiter rejects the next request');
    $s=db()->prepare('SELECT * FROM journey_rate_limits WHERE purpose=?');$s->execute(['journey-test']);
    check(!str_contains(json_encode($s->fetchAll()),$prefix),'rate limit keys contain no raw identifier');

    $s=db()->prepare('SELECT * FROM posts WHERE user_id=? ORDER BY id');$s->execute([$actor]);$rss=journeys_rss_xml($s->fetchAll());$xml=new DOMDocument();
    check($xml->loadXML($rss,LIBXML_NONET),'RSS is valid XML');
    $rssLocations=[];foreach($xml->getElementsByTagName('item') as $item)$rssLocations[]=$item->getElementsByTagName('link')->item(0)->textContent;
    check(count($rssLocations)===3,'RSS includes published and due posts independent of journey links');
    check(!in_array(url('/my-thoughts/'.$prefix.'-draft'),$rssLocations,true)&&!in_array(url('/my-thoughts/'.$prefix.'-future'),$rssLocations,true),'RSS excludes drafts and future posts');
    check(str_contains($rss,'&amp;')&&str_contains($rss,'&lt;story&gt;'),'RSS safely escapes special characters');
    $hostileRss=journeys_rss_xml([['title'=>"A title\x01 with control characters",'slug'=>'test','standfirst'=>'A valid summary','content_html'=>'','status'=>'published','published_at'=>$past]]);
    check($xml->loadXML($hostileRss,LIBXML_NONET),'RSS excludes XML-invalid control characters');

    journeys_save_season(saved_season_values($season,['phase'=>'concluded']),$season,$actor);
    journeys_save_season(saved_season_values($privateSecond,['publication_status'=>'published']),$privateSecond,$actor);
    check(journeys_public_season($prefix.'-second')!==null,'a new active venture can publish after the previous venture concludes');
    $deletion=saved_season_values($season);journeys_delete_season($season,(int)$deletion['row_version'],$actor);
    check(journeys_writer_season($season)===null&&journeys_public_episodes($season)===[],'journey deletion removes its chapter links');
    $s=db()->prepare('SELECT id FROM posts WHERE id=?');$s->execute([$published]);check((int)$s->fetchColumn()===$published,'deleting a journey preserves the associated posts');

    fwrite(STDOUT,'PASS: '.$checks." journey, founder, newsletter and RSS workflow assertions.\n");
}catch(Throwable $error){
    fwrite(STDERR,$error->getMessage()."\n");$testExitCode=1;
}finally{
    if(db()->inTransaction())db()->rollBack();
    if(isset($actor)){
        $s=db()->prepare('DELETE FROM founder_submissions WHERE submitter_email=?');$s->execute([$prefix.'-nominator@example.invalid']);
        $s=db()->prepare('DELETE FROM journey_seasons WHERE created_by=?');$s->execute([$actor]);
        $s=db()->prepare('DELETE FROM posts WHERE user_id=?');$s->execute([$actor]);
        $s=db()->prepare('DELETE FROM journey_audit_events WHERE actor_id=?');$s->execute([$actor]);
        $s=db()->prepare('DELETE FROM users WHERE id=?');$s->execute([$actor]);
    }
    if(isset($subscriberEmail)){$s=db()->prepare('SELECT id FROM newsletter_subscribers WHERE email=?');$s->execute([$subscriberEmail]);$subscriberId=$s->fetchColumn();if($subscriberId){$s=db()->prepare("DELETE FROM journey_audit_events WHERE entity_type='newsletter' AND entity_id=?");$s->execute([$subscriberId]);}$s=db()->prepare('DELETE FROM newsletter_subscribers WHERE email=?');$s->execute([$subscriberEmail]);}
    $s=db()->prepare('DELETE FROM journey_rate_limits WHERE purpose=?');$s->execute(['journey-test']);
}
exit($testExitCode);
