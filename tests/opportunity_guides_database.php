<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('WK_TEST_ALLOW_WRITES')!=='1')throw new RuntimeException('Disposable test database permission required.');
require __DIR__.'/prepare.php';
$name='waithaka_test_guides_'.bin2hex(random_bytes(6)).'_test';$dbSettings=wk_test_database_settings();wk_test_prepare($name,false);
$CONFIG=['app_env'=>'testing','base_url'=>'https://guides.example.com','timezone'=>'Africa/Nairobi','app_key'=>'test-only-'.str_repeat('z',48),'db'=>$dbSettings+['name'=>$name,'charset'=>'utf8mb4']];$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
require dirname(__DIR__).'/vendor/autoload.php';
foreach(['impact','writer_account','studio','media_centre','analytics','operations','media_storage','media_library','mail_feedback','integrations','journeys','journey_routes','newsletter','newsletter_routes','media_library_routes','publishing','publishing_routes','readiness','readiness_routes','editorial','editorial_routes','reader','reader_routes','founder_stories','founder_story_routes','discovery','discovery_routes','campaigns','campaign_routes','google_workspace','meetings','communication','communication_notifications','app_security','communication_mail','trusted_devices','planner','publishing_automation','workspace_reports','contact_experience','enterprises','microsoft_workspace','microsoft_actions','opportunities','opportunity_routes']as$module)require_once dirname(__DIR__).'/app/'.$module.'.php';
require_once dirname(__DIR__).'/app/publishing_desk.php';require_once dirname(__DIR__).'/app/opportunity_guides_release.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$n=0;function ogd(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}
function ogd_render(array $record,bool $preview=false):string{$post=$preview?writer_post_by_id((int)$record['post_id']):$record['public']['article'];if(!$preview)$record['fields']=$record['public']['fields'];ob_start();try{include dirname(__DIR__).'/views/desk/article.php';return (string)ob_get_contents();}finally{ob_end_clean();}}
$pdo=db();$pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Guide fixture editor','guide-fixture@example.com',password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();$config=comm_config();$config['owner']=$uid;comm_put('config',$config);
$catalog=curated_release_catalog();$catalog['checked_on']=date('Y-m-d');$guides=opportunity_guides_catalog();foreach($catalog['entries']as&$e)if($e['deadline_iso']!==null)$e['deadline_iso']=(new DateTimeImmutable('+5 days'))->format('Y-m-d\TH:i:00P');unset($e);
$initial=curated_release_import($uid,$catalog);$dates=[];foreach($initial as$item){$r=desk_get($item['record']);$dates[$r['id']]=[$r['published_at'],writer_post_by_id((int)$r['post_id'])['published_at']];}
$counts=[];foreach(['posts','email_outbox','publishing_jobs']as$table)$counts[$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
$report=opportunity_guides_upgrade($uid,$catalog,$guides);ogd(count($report)===16,'Sixteen individual updates.');
foreach($report as$i=>$item){
    ogd($item['state']==='enriched','Each unchanged initial listing is enriched.');$r=desk_get($item['record']);$p=writer_post_by_id((int)$r['post_id']);
    ogd($r['public']['article']['content_html']===opportunity_guide_body($catalog['entries'][$i],$guides['entries'][$item['slug']],$guides['guidance_note']),'Saved public body matches reviewed guide.');
    ogd($r['slug']===$item['slug']&&[$r['published_at'],$p['published_at']]===$dates[$r['id']],'URL and original publication date preserved.');
    ogd($r['owner_approval']===$r['public']['digest'],'Native final-copy approval remains valid.');
    $html=ogd_render($r);ogd(substr_count($html,'data-opportunity-support')===1,'Exactly one support request.');ogd(str_contains($html,'href="https://guides.example.com/contribute"'),'Correct support route.');
    ogd(strpos($html,'desk-cta')<strpos($html,'data-opportunity-support'),'Official provider action comes first.');ogd(!str_contains(ogd_render($r,true),'data-opportunity-support'),'No support request in private preview.');
}
$again=opportunity_guides_upgrade($uid,$catalog,$guides);ogd(count(array_filter($again,static fn($x)=>$x['state']==='preserved'))===16,'Repeat release does not rewrite content.');
foreach($counts as$table=>$count)ogd((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$count,'No duplicate or outbound side effect: '.$table);
// Reset two synthetic records to baseline to exercise conflict guards before enrichment.
for($i=0;$i<2;$i++){$r=desk_get($initial[$i]['record']);$p=writer_post_by_id((int)$r['post_id']);$in=desk_post_input($p);$in['content_html']=curated_release_body($catalog['entries'][$i]);writer_save_post($in,$uid,false);$r=desk_update($r['id'],['version'=>$r['version'],'action'=>'approve','approval_ack'=>'1'],$uid);desk_update($r['id'],['version'=>$r['version'],'action'=>'publish'],$uid);comm_put('curated.guides.v1.'.$r['slug'],[]);}
$r=desk_get($initial[0]['record']);$p=writer_post_by_id((int)$r['post_id']);$in=desk_post_input($p);$in['content_html'].='<p>Private editor change must survive.</p>';writer_save_post($in,$uid,false);
$r=desk_get($initial[1]['record']);$p=writer_post_by_id((int)$r['post_id']);$in=desk_post_input($p);$in['content_html'].='<p>Public editor change must survive.</p>';writer_save_post($in,$uid,false);$r=desk_update($r['id'],['version'=>$r['version'],'action'=>'approve','approval_ack'=>'1'],$uid);desk_update($r['id'],['version'=>$r['version'],'action'=>'publish'],$uid);
$r=desk_get($initial[2]['record']);desk_update($r['id'],['version'=>$r['version'],'action'=>'withdraw','note'=>'Synthetic withdrawal'],$uid);
$guarded=opportunity_guides_upgrade($uid,$catalog,$guides);ogd($guarded[0]['state']==='private_draft_preserved','Private edits are not overwritten.');ogd($guarded[1]['state']==='editorial_changes_preserved','Public edits are not overwritten.');ogd($guarded[2]['state']==='closed_or_private_preserved','Withdrawn page is never resurrected.');
$r=desk_get($initial[3]['record']);$r['fields']['sponsored']=true;$r['public']['fields']['sponsored']=true;$r['fields']['sponsor_name']=$r['public']['fields']['sponsor_name']='Synthetic sponsor';ogd(!str_contains(ogd_render($r),'data-opportunity-support'),'No support appeal in paid sponsored content.');
restore_error_handler();echo 'PASS: '.$n." guide publication, integrity and support-boundary assertions.\n";
