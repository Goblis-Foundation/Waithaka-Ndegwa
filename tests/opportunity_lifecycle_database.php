<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('WK_TEST_ALLOW_WRITES')!=='1')throw new RuntimeException('Disposable test database permission required.');
require __DIR__.'/prepare.php';
$name='waithaka_test_lifecycle_'.bin2hex(random_bytes(6)).'_test';$dbSettings=wk_test_database_settings();wk_test_prepare($name,false);
$CONFIG=['app_env'=>'testing','base_url'=>'https://guides.example.com','timezone'=>'Africa/Nairobi','app_key'=>'test-only-'.str_repeat('z',48),'db'=>$dbSettings+['name'=>$name,'charset'=>'utf8mb4']];$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
require dirname(__DIR__).'/vendor/autoload.php';
foreach(['impact','writer_account','studio','media_centre','analytics','operations','media_storage','media_library','mail_feedback','integrations','journeys','journey_routes','newsletter','newsletter_routes','media_library_routes','publishing','publishing_routes','readiness','readiness_routes','editorial','editorial_routes','reader','reader_routes','founder_stories','founder_story_routes','discovery','discovery_routes','campaigns','campaign_routes','google_workspace','meetings','communication','communication_notifications','app_security','communication_mail','trusted_devices','planner','publishing_automation','workspace_reports','contact_experience','enterprises','microsoft_workspace','microsoft_actions','opportunities','opportunity_routes']as$module)require_once dirname(__DIR__).'/app/'.$module.'.php';
require_once dirname(__DIR__).'/app/publishing_desk.php';require_once dirname(__DIR__).'/app/opportunity_guides_release.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$n=0;function ogd(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}
$pdo=db();$pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Lifecycle fixture editor','lifecycle-fixture@example.com',password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();$config=comm_config();$config['owner']=$uid;comm_put('config',$config);
$catalog=curated_release_catalog();$catalog['checked_on']=date('Y-m-d');$guides=opportunity_guides_catalog();foreach($catalog['entries']as&$e)$e['deadline_iso']=(new DateTimeImmutable('+5 days'))->format('Y-m-d\TH:i:00P');unset($e);
$initial=curated_release_import($uid,$catalog);opportunity_guides_upgrade($uid,$catalog,$guides);ol_install();
$mail=(int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();$jobs=(int)$pdo->query('SELECT COUNT(*) FROM publishing_jobs')->fetchColumn();
$now=time();$r=desk_get($initial[0]['record']);$id=$r['id'];$oldDate=$r['published_at'];$oldSlug=$r['slug'];
$newDate=(new DateTimeImmutable('+35 days'))->format('Y-m-d');$excerpt='Applications are extended to '.$newDate.'.';$source="Official fixture award for this same round. ".$excerpt." All original eligibility and award terms remain unchanged.";
$fetch=static fn($url)=>$source;$ai=static fn($record,$texts)=>ol_ai_parse(json_encode(['changed'=>true,'kind'=>'deadline','summary'=>'Official same-round deadline extension.','source_index'=>0,'quote'=>$excerpt,'deadline_date'=>$newDate],JSON_THROW_ON_ERROR),$texts);
$m=ol_scan($r,[],ol_config(),$fetch,$ai,$now);ogd($m['fetch_state']==='checked'&&$m['proposal']['kind']==='deadline','AI suggests evidence-backed change.');
ogd(desk_get($id)['public']['fields']['deadline']===$r['public']['fields']['deadline'],'AI cannot mutate deadline.');
comm_put('ol.monitor.'.$id,$m);
try{ol_ai_parse('{"changed":true,"kind":"deadline","source_index":0,"quote":"invented unsupported deadline","deadline_date":"2026-12-20"}',[$source]);throw new RuntimeException('Invented evidence accepted.');}catch(RuntimeException $e){ogd($e->getMessage()==='analysis_evidence_missing','Model quote must exist in source.');}
$failed=ol_scan($r,$m,ol_config(),static function($url){throw new RuntimeException('offline');},$ai,$now+10);ogd($failed['fetch_state']==='needs_review'&&$failed['last_success']===$m['last_success']&&$failed['sources']===$m['sources'],'Fetch failure does not erase evidence or fabricate closure.');
$input=['version'=>$r['version'],'public_digest'=>$r['public']['digest'],'source_hash'=>$m['source_hash'],'source_index'=>0,'evidence_quote'=>$excerpt,'deadline_date'=>$newDate,'deadline_local'=>'','timezone'=>'','source_verified'=>'1','same_round'=>'1','note'=>'Fixture: verified same-round extension.'];
ol_apply_deadline($id,$input,$uid);$updated=desk_get($id);
ogd($updated['state']==='published'&&$updated['public']['fields']['deadline_date']===$input['deadline_date'],'Owner-reviewed date-only extension publishes.');
ogd($updated['published_at']===$oldDate&&$updated['slug']===$oldSlug,'Original date and URL preserved.');
ogd($updated['owner_approval']===$updated['public']['digest'],'Final-copy native approval intact.');ogd(count($updated['lifecycle']['revisions'])===1,'Public revision evidence appears only after publication.');
ogd(str_contains($updated['public']['article']['content_html'],$updated['public']['fields']['deadline_label']),'Body and structured deadline agree.');
$close=ol_boundary(ol_public_record($updated))['at'];$pub=ol_public_record($updated);
ogd(ol_closed($pub,$close)&&ol_archived($pub,$close+90*86400),'Updated deadline resets retention.');
$body=ol_public_body($updated['public']['article']['content_html'],$pub,$close);ogd(!str_contains($body,'Check the official details and application route</a>'),'Closed body no longer invites an application.');
$p=writer_post_by_id((int)$updated['post_id']);$in=desk_post_input($p);$in['seo_title']='Private SEO work';writer_save_post($in,$uid,false);
$m=ol_scan($updated,[],ol_config(),$fetch,$ai,$now);comm_put('ol.monitor.'.$id,$m);$input['version']=$updated['version'];$input['public_digest']=$updated['public']['digest'];$input['source_hash']=$m['source_hash'];
try{ol_apply_deadline($id,$input,$uid);throw new RuntimeException('Private draft replaced.');}catch(InvalidArgumentException $e){ogd(str_contains($e->getMessage(),'editor draft'),'Private work protected.');}
$x=desk_get($initial[1]['record']);$x=desk_update($x['id'],['version'=>$x['version'],'action'=>'close','note'=>'Owner deliberately closed it.'],$uid);
ogd(ol_seed($x)['close_reason']==='manual'&&ol_interval(ol_public_record($x))===null,'Manual closure is retained.');
$before=desk_get($initial[2]['record']);$before=desk_update($before['id'],['version'=>$before['version'],'action'=>'withdraw','note'=>'Fixture withdrawal'],$uid);
$stats=ol_work($fetch,$ai,$now);ogd($stats['checked']<=2,'Bounded checks per pass.');ogd(desk_get($before['id'])['state']==='withdrawn','Worker never resurrects a withdrawal.');
ogd((int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$mail&&(int)$pdo->query('SELECT COUNT(*) FROM publishing_jobs')->fetchColumn()===$jobs,'No mail, applications or social sends.');
restore_error_handler();echo 'PASS: '.$n." source-review, native revision and side-effect boundary assertions.\n";
