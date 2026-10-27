<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('WK_TEST_ALLOW_WRITES')!=='1')throw new RuntimeException('Explicit disposable database permission is required.');
require __DIR__.'/prepare.php';
$name='waithaka_test_desk_'.bin2hex(random_bytes(6)).'_test';
$dbSettings=wk_test_database_settings();wk_test_prepare($dbSettings,$name);
$CONFIG=['app_env'=>'testing','base_url'=>'https://publishing-desk.example.com','timezone'=>'Africa/Nairobi','app_key'=>'test-only-'.str_repeat('z',48),'db'=>$dbSettings+['name'=>$name,'charset'=>'utf8mb4']];$APP_INSTALLED=true;
date_default_timezone_set('Africa/Nairobi');
require dirname(__DIR__).'/vendor/autoload.php';
foreach(['impact','writer_account','studio','media_centre','analytics','operations','media_storage','media_library','mail_feedback','integrations','journeys','journey_routes','newsletter','newsletter_routes','media_library_routes','publishing','publishing_routes','readiness','readiness_routes','editorial','editorial_routes','reader','reader_routes','founder_stories','founder_story_routes','discovery','discovery_routes','campaigns','campaign_routes','google_workspace','meetings','communication','communication_notifications','app_security','communication_mail','trusted_devices','planner','publishing_automation','workspace_reports','contact_experience','enterprises','microsoft_workspace','microsoft_actions','opportunities','opportunity_routes']as$module)require_once dirname(__DIR__).'/app/'.$module.'.php';
require_once dirname(__DIR__).'/app/publishing_desk.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$checks=0;
function desk_test(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function desk_reject(callable $call,string $message):void{try{$call();}catch(InvalidArgumentException){desk_test(true,$message);return;}throw new RuntimeException('Expected rejection: '.$message);}
function desk_test_post(int $post,int $uid):void{$p=writer_post_by_id($post);$in=desk_post_input($p);$in['category_id']=(int)db()->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();writer_save_post($in,$uid,false);}
function desk_action(string $id,string $action,array $extra,int $uid):array{$r=desk_get($id);return desk_update($id,['version'=>$r['version'],'action'=>$action]+$extra,$uid);}
$pdo=db();$pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Desk fixture editor','desk-fixture@example.com',password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();
$cfg=comm_config();$cfg['owner']=$uid;comm_put('config',$cfg);
$base=['title'=>'Fixture social enterprise grant','organisation'=>'Fixture provider','summary'=>'A source-verified fixture for tests only.','description'=>'Applicants should follow the official provider instructions.','eligibility'=>'Registered social enterprises','countries'=>'Kenya','source_url'=>'https://example.org/official','apply_url'=>'https://example.org/apply','verified_on'=>date('Y-m-d'),'timezone'=>'Africa/Nairobi','type'=>'grant','verification_notes'=>'PRIVATE-EDITORIAL-NOTE','claims_evidence'=>'PRIVATE-EVIDENCE'];
$organic=desk_new($base,'opportunity',$uid);desk_test($organic['public']===null,'Creating a record is private.');
desk_test_post((int)$organic['post_id'],$uid);
desk_reject(fn()=>desk_action($organic['id'],'publish',[],$uid),'No publication before approval.');
$organic=desk_action($organic['id'],'approve',['approval_ack'=>'1'],$uid);$organic=desk_action($organic['id'],'publish',[],$uid);
desk_test($organic['state']==='published'&&$organic['public_visible'],'Owner-approved organic opportunity publishes.');
desk_test(!str_contains(json_encode($organic['public']),'PRIVATE-'),'Private evidence is absent from public snapshot.');
desk_test(str_starts_with(desk_path($organic),'/opportunities/'),'Canonical opportunity URL.');
$publishedDigest=$organic['public']['digest'];$p=writer_post_by_id((int)$organic['post_id']);$in=desk_post_input($p);$in['content_html'].='<p>A private draft change.</p>';writer_save_post($in,$uid,false);
desk_test(desk_get($organic['id'])['public']['digest']===$publishedDigest,'Private article edits do not replace the public snapshot.');
desk_reject(fn()=>desk_assert_publish(desk_get($organic['id'])),'Private edit invalidates exact approval.');
$organic=desk_action($organic['id'],'approve',['approval_ack'=>'1'],$uid);$organic=desk_action($organic['id'],'publish',[],$uid);
$organic=desk_action($organic['id'],'close',['note'=>'Fixture closure'],$uid);desk_test(desk_closed($organic),'Closed archive has no active call to action.');
$organic=desk_action($organic['id'],'withdraw',['note'=>'Fixture withdrawal'],$uid);desk_test(!$organic['public_visible']&&$pdo->query('SELECT status FROM posts WHERE id='.(int)$organic['post_id'])->fetchColumn()==='draft','Withdrawal removes public/native publication.');
// Commercial provider settings are isolated fixture data. No external request is made.
$commercial=desk_config();$commercial=array_replace($commercial,['seller'=>'Fixture supplier','billing_email'=>'accounts@example.com','invoice_address'=>'Fixture address','bank_reference'=>'FIXTURE-ACCOUNT','bank_instructions'=>'Fixture instruction, not a real receiving account','billing_enabled'=>true,'providers'=>['bank'],'daily_cap'=>1]);comm_put('desk.config',$commercial);
foreach(['linkedin'=>'https://www.linkedin.com/in/fixture-editor','x'=>'https://x.com/fixture-editor']as$slug=>$profile)$pdo->prepare("UPDATE publishing_channels SET profile_url=?,delivery_method='manual',account_type='personal' WHERE slug=?")->execute([$profile,$slug]);
$client=['name'=>'Fixture client','email'=>'client@example.com','business'=>'Fixture business'];
$sponsored=desk_new(array_replace($base,['title'=>'Fixture paid service feature','organisation'=>'Fixture business','purchase_url'=>'https://example.org/buy','sponsor_name'=>'Fixture business','offer'=>'A clearly described service.']),'sponsored',$uid,$client);desk_test_post((int)$sponsored['post_id'],$uid);
$_SESSION=[];desk_reject(fn()=>desk_private($sponsored['id']),'Private workspace requires current mailbox-bound session.');$_SESSION['desk_client'][$sponsored['id']]=['email'=>'wrong@example.com','until'=>time()+100];desk_reject(fn()=>desk_private($sponsored['id']),'Wrong mailbox is rejected.');$_SESSION['desk_client'][$sponsored['id']]=['email'=>$client['email'],'until'=>time()+100];desk_test(desk_private($sponsored['id'])['id']===$sponsored['id'],'Correct client session opens only its record.');
// A small immutable public fixture image stands in for a reviewed deterministic banner.
$tmp=tempnam(sys_get_temp_dir(),'wn-desk-fixture-');$image=imagecreatetruecolor(1200,630);imagefill($image,0,0,imagecolorallocate($image,235,243,245));imagejpeg($image,$tmp,90);imagedestroy($image);
try{$asset=media_library_import($tmp,'fixture-banner.jpg',['title'=>'Fixture reviewed banner','visibility'=>'public','public_ack'=>1],$uid);}finally{unlink($tmp);}
$a=media_library_asset($asset);$sponsored=desk_get($sponsored['id']);$sponsored['creatives']['landscape']=['asset'=>$asset,'sha256'=>$a['sha256'],'url'=>$a['public_url'],'alt'=>'Synthetic editorial test illustration.','width'=>1200,'height'=>630,'ai_generated'=>false];$sponsored=desk_store($sponsored,'fixture_creative',(string)$uid);
$first=(new DateTimeImmutable('+5 days'))->setTime(9,0)->format('Y-m-d\TH:i');
$o=desk_quote($sponsored['id'],['version'=>$sponsored['version'],'package'=>'weekly','channel_one'=>'linkedin','channel_two'=>'x','campaign_start'=>$first,'visibility_days'=>'365'],$uid);desk_test($o['quote']['posts']===8&&count($o['times'])===4,'Quote reserves exactly four two-account rounds.');
$before=(int)$pdo->query('SELECT COUNT(*) FROM contributions')->fetchColumn();
desk_reject(fn()=>desk_checkout($o['id'],'bank',$client['email']),'Cannot pay before accepting terms.');
$sponsored=desk_get($sponsored['id']);desk_reject(fn()=>desk_client_action($sponsored['id'],['version'=>$sponsored['version'],'action'=>'accept_quote','order'=>$o['id'],'terms_ack'=>'1','quote_hash'=>$o['quote']['hash']],'other@example.com'),'Another client cannot accept the quote.');
desk_client_action($sponsored['id'],['version'=>$sponsored['version'],'action'=>'accept_quote','order'=>$o['id'],'terms_ack'=>'1','quote_hash'=>$o['quote']['hash']],$client['email']);
$url=desk_checkout($o['id'],'bank',$client['email']);desk_test(str_contains($url,'/client/workspace/'),'Bank checkout remains private.');
desk_reject(fn()=>desk_bank_verify($o['id'],['bank_ack'=>'1','transaction'=>'FIXTURE-TX','currency'=>'KES','amount'=>'1.00'],$uid),'Bank mismatch cannot become paid.');
desk_bank_verify($o['id'],['bank_ack'=>'1','transaction'=>'FIXTURE-TX','currency'=>'KES','amount'=>'18000.00'],$uid);$o=desk_order($o['id']);desk_test($o['state']==='paid','Exact bank statement proof confirms fixture order.');
$paidAt=$o['paid_at'];$receiptCount=(int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();desk_settle($o,$o['proof']);desk_test(desk_order($o['id'])['paid_at']===$paidAt&&(int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$receiptCount,'Repeated reconciliation does not issue duplicate receipts.');
$sponsored=desk_get($sponsored['id']);desk_reject(fn()=>desk_action($sponsored['id'],'approve',['approval_ack'=>'1'],$uid),'Owner approval cannot bypass client review.');
$sponsored=desk_action($sponsored['id'],'invite',[],$uid);$post=writer_post_by_id((int)$sponsored['post_id']);$digest=desk_digest($sponsored,$post);
desk_client_action($sponsored['id'],['version'=>$sponsored['version'],'action'=>'approve','approval_ack'=>'1','digest'=>$digest],$client['email']);
$sponsored=desk_action($sponsored['id'],'approve',['approval_ack'=>'1'],$uid);$sponsored=desk_action($sponsored['id'],'publish',[],$uid);
desk_test($sponsored['fields']['sponsored']&&$sponsored['state']==='published','Paid, client-approved, owner-approved feature publishes.');
desk_test((int)$pdo->query('SELECT COUNT(*) FROM contributions')->fetchColumn()===$before,'Commercial orders never create contributions.');
$record=$sponsored;$record['fields']=$record['public']['fields'];$post=$record['public']['article'];$preview=false;ob_start();include dirname(__DIR__).'/views/desk/article.php';$html=(string)ob_get_clean();desk_test(str_contains($html,'Sponsored feature')&&str_contains($html,'rel="sponsored noopener noreferrer"')&&!str_contains($html,'Support My Thoughts')&&!str_contains($html,'/contribute'),'Sponsored rendering discloses payment and omits contribution calls.');
desk_prepare_campaign($sponsored['id'],$o['id'],$uid);$o=desk_order($o['id']);desk_test(count($o['campaign'])===4,'Four distinct social round drafts, one website article.');
$packages=(int)$pdo->query('SELECT COUNT(*) FROM publishing_packages')->fetchColumn();desk_prepare_campaign($sponsored['id'],$o['id'],$uid);desk_test((int)$pdo->query('SELECT COUNT(*) FROM publishing_packages')->fetchColumn()===$packages,'Repeated preparation is idempotent.');
desk_reject(fn()=>desk_schedule_campaign($o['id'],$uid),'Every social revision requires explicit approval.');
foreach($o['campaign']as$round){$pkg=publishing_package((int)$round['package']);$draft=publishing_decode($pkg['draft_json']);foreach(['linkedin','x']as$slug)desk_test(str_starts_with($draft['variants'][$slug]['text'],'Sponsored feature · Fixture business'),'Each channel starts with sponsor disclosure.');}
desk_order_adjust($o['id'],['action'=>'pause','note'=>'Fixture pause'],$uid);desk_reject(fn()=>desk_assert_publish(desk_get($sponsored['id']),desk_order($o['id'])),'Paused paid orders block additional release.');desk_order_adjust($o['id'],['action'=>'resume'],$uid);
$next=(new DateTimeImmutable('+35 days'))->setTime(9,0)->format('Y-m-d\TH:i');desk_propose_schedule($o['id'],['new_start'=>$next,'note'=>'Client-approved fixture replacement'],$uid);$o=desk_order($o['id']);desk_test($o['paused']&&count($o['schedule_proposal']['indices'])===4,'Replacement dates pause delivery until client agreement.');
desk_reject(fn()=>desk_accept_schedule($o['id'],'wrong@example.com',$o['schedule_proposal']['hash']),'Only contracted client accepts replacement dates.');desk_accept_schedule($o['id'],$client['email'],$o['schedule_proposal']['hash']);$o=desk_order($o['id']);desk_test(!$o['paused']&&!$o['campaign']&&$o['quote']['posts']===8&&$o['paid_at']===$paidAt,'Reschedule does not change price, post count or collect another payment.');
$sponsored=desk_get($sponsored['id']);desk_client_action($sponsored['id'],['version'=>$sponsored['version'],'action'=>'cancel','note'=>'Fixture cancellation'],$client['email']);desk_test(desk_order($o['id'])['paused'],'Client cancellation pauses future delivery.');
$report=desk_campaign_report(desk_get($sponsored['id']));desk_test(is_array($report),'Delivery report query executes against real schema.');desk_test(count(desk_sitemap())>=4,'Public publishing sitemap contains live reviewed feature.');
restore_error_handler();echo 'Publishing desk database: '.$checks.' assertions passed in fresh disposable schema '.$name.".\n";
