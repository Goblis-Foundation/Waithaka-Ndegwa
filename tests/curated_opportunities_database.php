<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('WK_TEST_ALLOW_WRITES')!=='1')throw new RuntimeException('Explicit disposable database permission required.');
require __DIR__.'/prepare.php';
$name='waithaka_test_curated_'.bin2hex(random_bytes(6)).'_test';$dbSettings=wk_test_database_settings();wk_test_prepare($name,false);
$CONFIG=['app_env'=>'testing','base_url'=>'https://curated.example.com','timezone'=>'Africa/Nairobi','app_key'=>'test-only-'.str_repeat('z',48),'db'=>$dbSettings+['name'=>$name,'charset'=>'utf8mb4']];$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
require dirname(__DIR__).'/vendor/autoload.php';
foreach(['impact','writer_account','studio','media_centre','analytics','operations','media_storage','media_library','mail_feedback','integrations','journeys','journey_routes','newsletter','newsletter_routes','media_library_routes','publishing','publishing_routes','readiness','readiness_routes','editorial','editorial_routes','reader','reader_routes','founder_stories','founder_story_routes','discovery','discovery_routes','campaigns','campaign_routes','google_workspace','meetings','communication','communication_notifications','app_security','communication_mail','trusted_devices','planner','publishing_automation','workspace_reports','contact_experience','enterprises','microsoft_workspace','microsoft_actions','opportunities','opportunity_routes']as$module)require_once dirname(__DIR__).'/app/'.$module.'.php';
require_once dirname(__DIR__).'/app/publishing_desk.php';require_once dirname(__DIR__).'/app/curated_opportunities_release.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$n=0;function cda(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['n']++;}
$pdo=db();$pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Curated fixture editor','curated-fixture@example.com',password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();$config=comm_config();$config['owner']=$uid;comm_put('config',$config);
$catalog=curated_release_catalog();$catalog['checked_on']=date('Y-m-d');
// Future fixture dates keep database tests reusable. Production uses the unchanged source catalog.
foreach($catalog['entries']as&$entry)$entry['deadline_iso']=(new DateTimeImmutable('+5 days'))->format('Y-m-d\TH:i:00P');unset($entry);
$mailBefore=(int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();$jobsBefore=(int)$pdo->query('SELECT COUNT(*) FROM publishing_jobs')->fetchColumn();
$first=curated_release_import($uid,$catalog);cda(count($first)===16,'All entries handled.');
foreach($first as$i=>$item){
    cda($item['state']==='published','First import publishes each approved listing.');$record=desk_get($item['record']);
    cda($record['slug']===$catalog['entries'][$i]['slug'],'Stable individual canonical slug.');
    cda($record['public_visible']&&$record['state']==='published','Native public snapshot present.');
    cda($record['owner_approval']===$record['public']['digest'],'Approval matches final published material.');
    cda($record['public']['fields']['support_label']===$catalog['entries'][$i]['support'],'Support type is displayed without numeric ambiguity.');
    cda(isset($record['public']['fields']['deadline_label']),'Date precision label survives snapshot.');
    $post=writer_post_by_id((int)$record['post_id']);$preview=false;$record['fields']=$record['public']['fields'];
    ob_start();include dirname(__DIR__).'/views/desk/article.php';$html=(string)ob_get_clean();
    cda(str_contains($html,'curated-opportunity')&&!str_contains($html,'Private preview'),'Public article renders without warnings.');
    cda(str_contains($html,e($catalog['entries'][$i]['support'])),'Header has the correct funding/support description.');
}
$posts=(int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn();$second=curated_release_import($uid,$catalog);
cda(count(array_filter($second,static fn($r)=>$r['state']==='preserved'))===16,'Second import preserves all sixteen.');
cda((int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn()===$posts,'No duplicate posts.');
cda((int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===$mailBefore,'No emails queued.');
cda((int)$pdo->query('SELECT COUNT(*) FROM publishing_jobs')->fetchColumn()===$jobsBefore,'No social jobs queued.');
cda(count(desk_public_list('opportunity'))===16,'All sixteen discoverable in native archive.');
$r=desk_get($first[0]['record']);desk_update($r['id'],['version'=>$r['version'],'action'=>'withdraw','note'=>'Fixture withdrawal'],$uid);curated_release_import($uid,$catalog);cda(desk_get($r['id'])['state']==='withdrawn','Later deploy does not resurrect withdrawn material.');
restore_error_handler();echo 'PASS: '.$n." native publishing database checks in disposable schema.\n";
