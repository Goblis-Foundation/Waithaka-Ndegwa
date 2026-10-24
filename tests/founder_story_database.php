<?php
declare(strict_types=1);

/** Prepared, fresh loopback fixture DB only. Email uses the encrypted local outbox. */
function rd_env(string $primary, string $fallback): string|false { $v = getenv($primary); return $v !== false ? $v : getenv($fallback); }
$host = rd_env('DB_HOST', 'WK_TEST_DB_HOST'); $port = rd_env('DB_PORT', 'WK_TEST_DB_PORT'); $name = rd_env('DB_DATABASE', 'WK_TEST_DB_NAME'); $user = rd_env('DB_USERNAME', 'WK_TEST_DB_USER'); $pass = rd_env('DB_PASSWORD', 'WK_TEST_DB_PASS');
if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('WK_TEST_ALLOW_WRITES') !== '1' || !is_string($name) || !preg_match('/^waithaka_test_[a-z0-9_]+_test$/D', $name) || !in_array($host, ['127.0.0.1', '::1'], true) || !is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535 || !is_string($user) || $user === '' || !is_string($pass)) { fwrite(STDERR, "Use explicit testing/write opt-in, numeric-loopback DB_* credentials and a fresh waithaka_test_*_test schema.\n"); exit(2); }
$CONFIG = ['app_env' => 'testing', 'app_key' => 'reader-database-fixture-key-not-production', 'base_url' => 'https://www.waithakandegwa.com', 'timezone' => 'Africa/Nairobi', 'worker_enabled' => false, 'db' => ['host' => $host, 'port' => (int)$port, 'name' => $name, 'user' => $user, 'pass' => $pass, 'charset' => 'utf8mb4'], 'mail' => ['transport' => 'disabled', 'from' => 'fixture@example.invalid', 'from_name' => 'Reader fixture', 'reply_to' => '', 'postal_address' => 'Synthetic fixture address']];
$APP_INSTALLED = true; date_default_timezone_set('Africa/Nairobi');
foreach (['functions', 'journeys', 'operations', 'mail_feedback', 'newsletter', 'newsletter_routes', 'reader','publishing','founder_stories'] as $module) require_once __DIR__.'/../app/'.$module.'.php';
$checks=0;
function fs_check(bool $ok,string $why): void {global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$why);$checks++;}
function fs_reject(callable $fn,string $why): void {try{$fn();}catch(JourneyValidationException|JourneyConflictException|WriterConflict|ReaderValidationException){fs_check(true,$why);return;}fs_check(false,$why);}
function fs_row(string $sql,array $params=[]): array {$q=db()->prepare($sql);$q->execute($params);return $q->fetch()?:[];}
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Story fixture','story-writer@example.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
db()->exec("INSERT INTO categories(name,slug) VALUES('Story fixture','story-fixture')");$topic=(int)db()->lastInsertId();
function fs_fixture(string $suffix): array {
 global $actor,$topic;
 db()->prepare("INSERT INTO posts(user_id,category_id,title,slug,standfirst,content_html,status,published_at) VALUES(?,?,?,?,?,?,'draft',?)")->execute([$actor,$topic,'Story '.$suffix,'story-'.$suffix,'Original introduction','<p>Original exact story.</p>',date('Y-m-d H:i:s',time()-120)]);$post=(int)db()->lastInsertId();
 db()->prepare("INSERT INTO founder_submissions(reference,submission_type,submitter_name,submitter_email,founder_name,venture_name,country,story_summary,metric_basis,privacy_version,privacy_accepted_at,ip_hash,associated_post_id,created_at,updated_at) VALUES(?,'founder','Synthetic founder',?,'Synthetic founder','Fixture venture','Kenya','Private original intake','not_applicable','fixture',NOW(),?,?,NOW(),NOW())")->execute(['FS-'.$suffix,$suffix.'@example.invalid',str_repeat('0',64),$post]);return [(int)db()->lastInsertId(),$post];
}
function fs_input(int $post,array $changes=[]): array {$p=writer_post_by_id($post);$v=array_intersect_key($p,array_flip(writer_editable_fields()));$v['id']=$post;$v['draft_version']=$p['_draft_version'];$v['published_at']=date('Y-m-d\TH:i',time()-120);return array_replace($v,$changes);}
function fs_request(int $id,array $patch=[]): int {global $actor;$f=fs_row('SELECT * FROM founder_submissions WHERE id=?',[$id]);return founder_story_request($id,array_replace(['founder_email'=>$f['submitter_email'],'founder_whatsapp'=>'+254700000001','contact_evidence'=>'Synthetic consent fixture','contact_confirmed'=>1,'whatsapp_optin'=>1,'send_review_ack'=>1,'response_days'=>14],$patch),$actor);}
function fs_token(int $review): string {$d=fs_row("SELECT payload_encrypted FROM founder_story_deliveries WHERE review_id=? AND channel='whatsapp'",[$review]);$payload=json_decode(decrypt_secret($d['payload_encrypted']),true,32,JSON_THROW_ON_ERROR);parse_str((string)parse_url($payload['url'],PHP_URL_QUERY),$query);return $query['token'];}
$captured=[];$transport=static function(string $channel,array $payload)use(&$captured):string{$captured[]=[$channel,$payload];return 'fixture-'.$channel.'-'.count($captured);};
[$founder,$post]=fs_fixture('approved');
fs_reject(fn()=>writer_save_post(fs_input($post),$actor,true),'A linked founder story cannot publish using a general editorial decision.');
fs_reject(fn()=>fs_request($founder,['whatsapp_optin'=>0]),'A review cannot be sent without WhatsApp notification permission.');
$review=fs_request($founder);$token=fs_token($review);
fs_check(founder_story_token($token)!==null&&founder_story_token('invalid')===null,'Only the random recipient-bound review capability can read the snapshot.');
fs_check(!str_contains(reader_json(fs_row('SELECT * FROM founder_story_reviews WHERE id=?',[$review])),$token),'The review record stores only a token hash.');
fs_check(founder_story_workflow($founder)['state']==='pending','Reading a link never approves the story.');
fs_reject(fn()=>fs_request($founder),'The same pending copy cannot consume another round.');
fs_reject(fn()=>founder_story_respond($token,['decision'=>'approve']),'Approval requires an explicit acknowledgement.');
$delivery=founder_story_work(5,$transport);fs_check($delivery['accepted']===2,'The dedicated worker sends exactly email and WhatsApp fixture notifications.');
$stored=fs_row('SELECT * FROM founder_story_reviews WHERE id=?',[$review]);$deadline=$stored['expires_at'];
fs_check($deadline!==null&&abs(strtotime($deadline.' UTC')-time()-14*86400)<5,'Response deadline starts after both provider acknowledgements.');
founder_story_work(5,$transport);fs_check(fs_row('SELECT expires_at FROM founder_story_reviews WHERE id=?',[$review])['expires_at']===$deadline,'Worker polling never extends the approval deadline.');
fs_check(str_contains($captured[0][1]['html'],'data-email-design="waithaka-v1"')&&str_contains($captured[0][1]['html'],'Hello Synthetic founder'),'Review email uses the branded personalized template.');
founder_story_respond($token,['decision'=>'approve','approval_ack'=>1]);
fs_reject(fn()=>founder_story_respond($token,['decision'=>'decline']),'An answered link cannot overwrite an approval.');
fs_reject(fn()=>writer_save_post(fs_input($post,['content_html'=>'<p>Unapproved later edit.</p>']),$actor,true),'Changing the approved copy blocks publication.');
writer_save_post(fs_input($post),$actor,true);fs_check(post_by_slug('story-approved')!==null,'Only the exact approved story becomes publicly readable.');
writer_save_post(fs_input($post,['standfirst'=>'Edited private introduction']),$actor,false);
fs_reject(fn()=>writer_save_post(fs_input($post),$actor,true),'A saved edited draft also needs fresh founder approval.');
$second=fs_request($founder);fs_check((int)founder_story_workflow($founder)['rounds_used']===2&&founder_story_token($token)===null,'A changed draft uses the next round and old capabilities stay invalid.');
$oldToken=fs_token($second);writer_save_post(fs_input($post,['title'=>'A third editorial revision']),$actor,false);$third=fs_request($founder);
fs_check(founder_story_token($oldToken)===null&&fs_row('SELECT state FROM founder_story_reviews WHERE id=?',[$second])['state']==='superseded','A superseded pending link cannot approve an older copy.');
fs_reject(fn()=>fs_request($founder),'No fourth review can be issued.');
founder_story_respond(fs_token($third),['decision'=>'changes','proposed_title'=>'Proposed title','proposed_standfirst'=>'Proposed introduction','proposed_copy'=>'Founder proposed copy.']);
fs_check(founder_story_workflow($founder)['state']==='discarded'&&fs_row('SELECT status FROM posts WHERE id=?',[$post])['status']==='archived'&&post_by_slug('story-approved')===null,'A third unapproved review automatically discards and unpublishes the story.');
fs_check((int)db()->query('SELECT COUNT(*) FROM founder_story_reviews WHERE founder_id='.$founder)->fetchColumn()===3,'Discard retains all three private audit snapshots.');
[$edited,$editedPost]=fs_fixture('proposed');$r=fs_request($edited);founder_story_respond(fs_token($r),['decision'=>'changes','proposed_title'=>'Founder edited title','proposed_standfirst'=>'Founder edited introduction','proposed_copy'=>'Text with <script>unsafe markup</script>.']);
fs_check(founder_story_workflow($edited)['state']==='changes_requested'&&fs_row('SELECT title FROM posts WHERE id=?',[$editedPost])['title']==='Story proposed','Founder proposals never change the public post directly.');
founder_story_apply_edits($edited,$actor);$draft=writer_post_by_id($editedPost);fs_check($draft['title']==='Founder edited title'&&!str_contains($draft['content_html'],'<script>'),'An editor can import escaped founder edits into a private draft.');
$r=fs_request($edited);founder_story_respond(fs_token($r),['decision'=>'approve','approval_ack'=>1]);writer_save_post(fs_input($editedPost),$actor,true);fs_check(post_by_slug('story-proposed')!==null,'The edited draft can publish only after its new review is approved.');
[$declined,$declinedPost]=fs_fixture('declined');$r=fs_request($declined);founder_story_respond(fs_token($r),['decision'=>'decline']);fs_check(founder_story_workflow($declined)['state']==='discarded','An explicit decline immediately discards the story.');
[$expired,$expiredPost]=fs_fixture('expired');$r=fs_request($expired);$expiredToken=fs_token($r);db()->prepare('UPDATE founder_story_reviews SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE id=?')->execute([$r]);founder_story_work(5,$transport);fs_check(founder_story_workflow($expired)['state']==='discarded'&&founder_story_token($expiredToken)===null,'Expired unanswered reviews are automatically discarded and links revoked.');
[$blocked,$blockedPost]=fs_fixture('blocked');$r=fs_request($blocked);$result=founder_story_work(5);fs_check($result['blocked']===2&&fs_row('SELECT expires_at FROM founder_story_reviews WHERE id=?',[$r])['expires_at']===null,'Unconfigured providers are blocked, not falsely marked delivered or assigned a response deadline.');
founder_story_retry($blocked,$actor);$result=founder_story_work(5,$transport);fs_check($result['accepted']===2,'Configured/reviewed blocked notifications can be retried without using another round.');
[$uncertain,$uncertainPost]=fs_fixture('uncertain');$r=fs_request($uncertain);$result=founder_story_work(5,static function(){throw new RuntimeException('Synthetic uncertain provider outcome');});fs_check($result['ambiguous']===2,'Uncertain delivery outcomes are retained as ambiguous.');
fs_check(founder_story_work(5,$transport)['accepted']===0,'Ambiguous messages are never automatically resent.');fs_reject(fn()=>founder_story_retry($uncertain,$actor),'Retry of an uncertain send requires explicit provider reconciliation.');
founder_story_retry($uncertain,$actor,true);fs_check(founder_story_work(5,$transport)['accepted']===2,'Explicit reconciliation permits the existing notification job to resume.');
fs_check(!publishing_public_url('https://www.waithakandegwa.com/founders/story-review?token='.str_repeat('a',64)),'Private founder review capabilities cannot enter social publishing packages.');
echo 'PASS: '.$checks.' final-copy, delivery, revision, proposal, replay, deadline and discard checks.'.PHP_EOL;

