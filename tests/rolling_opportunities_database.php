<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(getenv('WK_TEST_ALLOW_WRITES')!=='1')throw new RuntimeException('Disposable test database permission required.');
require __DIR__.'/prepare.php';
$name='waithaka_test_rolling_'.bin2hex(random_bytes(6)).'_test';$dbSettings=wk_test_database_settings();wk_test_prepare($name,false);
$CONFIG=['app_env'=>'testing','base_url'=>'https://guides.example.com','timezone'=>'Africa/Nairobi','app_key'=>'test-only-'.str_repeat('r',48),'db'=>$dbSettings+['name'=>$name,'charset'=>'utf8mb4']];$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
require dirname(__DIR__).'/vendor/autoload.php';
foreach(['impact','writer_account','studio','media_centre','analytics','operations','media_storage','media_library','mail_feedback','integrations','journeys','journey_routes','newsletter','newsletter_routes','media_library_routes','publishing','publishing_routes','readiness','readiness_routes','editorial','editorial_routes','reader','reader_routes','founder_stories','founder_story_routes','discovery','discovery_routes','campaigns','campaign_routes','google_workspace','meetings','communication','communication_notifications','app_security','communication_mail','trusted_devices','planner','publishing_automation','workspace_reports','contact_experience','enterprises','microsoft_workspace','microsoft_actions','opportunities','opportunity_routes']as$module)require_once dirname(__DIR__).'/app/'.$module.'.php';
require_once dirname(__DIR__).'/app/publishing_desk.php';require_once dirname(__DIR__).'/app/opportunity_guides_release.php';require_once dirname(__DIR__).'/app/rolling_opportunities_release.php';require_once dirname(__DIR__).'/app/africa_country_opportunities_release.php';require_once dirname(__DIR__).'/app/international_africa_opportunities_release.php';require_once dirname(__DIR__).'/app/education_research_opportunities_release.php';
set_error_handler(static function(int $severity,string $message,string $file,int $line):bool{if(error_reporting()&$severity)throw new ErrorException($message,0,$severity,$file,$line);return false;});
$n=0;function rod(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}
function rod_render(array $record):string{$record['fields']=$record['public']['fields'];$post=$record['public']['article'];$preview=false;ob_start();try{include dirname(__DIR__).'/views/desk/article.php';return (string)ob_get_contents();}finally{ob_end_clean();}}
$pdo=db();$pdo->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Rolling fixture editor','rolling-fixture@example.com',password_hash(bin2hex(random_bytes(12)),PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();$config=comm_config();$config['owner']=$uid;comm_put('config',$config);
$base=curated_release_catalog();$base['checked_on']=date('Y-m-d');foreach($base['entries']as&$e)$e['deadline_iso']=(new DateTimeImmutable('+5 days'))->format('Y-m-d\TH:i:00P');unset($e);
$initial=curated_release_import($uid,$base);$snapshot=json_encode(array_map(static fn($item)=>desk_get($item['record']),$initial),JSON_THROW_ON_ERROR);
$catalog=rolling_opportunities_catalog();$before=[];foreach(['posts','email_outbox','publishing_jobs']as$table)$before[$table]=(int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
$report=rolling_opportunities_import($uid,$catalog);rod(count($report)===2,'Two individual opportunities are published.');
$hashes=[];
foreach($report as$i=>$item){
    rod($item['state']==='published','New record published through native flow.');$r=desk_get($item['record']);$p=writer_post_by_id((int)$r['post_id']);$e=$catalog['entries'][$i];
    rod($r['slug']===$e['slug']&&desk_path($r)==='/opportunities/'.$e['slug'],'Stable opportunity-only canonical URL.');
    rod($r['public']['fields']['deadline_mode']==='rolling'&&$r['public']['fields']['deadline']===null&&ol_boundary(ol_public_record($r))['at']===null,'No invented closing timestamp.');
    rod(!ol_closed(ol_public_record($r),time()+365*86400)&&!ol_archived(ol_public_record($r),time()+365*86400),'Rolling calls do not expire from an invented date.');
    rod($r['owner_approval']===$r['public']['digest']&&$r['public']['article']['content_html']===opportunity_guide_body($e,$e['guide'],$catalog['guidance_note']),'Exact published guide and approval agree.');
    rod($r['public']['fields']['currency']===$e['currency']&&$r['public']['fields']['amount_max']===desk_money($e['amount_max']),'Funding amount and currency remain accurate.');
    rod(count(ol_sources($r))===2&&ol_interval(ol_public_record($r))===86400,'Official-source monitoring is inherited.');
    rod(post_by_slug($r['slug'])===null&&!(bool)$p['is_selected'],'New listings cannot enter My Thoughts.');
    $html=rod_render($r);rod(str_contains($html,'curated-opportunity')&&substr_count($html,'data-opportunity-support')===1,'Existing header design and voluntary support are retained.');
    rod(str_contains($html,'data-editorial-banner')&&str_contains($html,'editorial-banner.php?key='.$r['slug']),'Relevant header artwork is rendered.');
    rod(substr_count($html,'<h2>')>=9&&str_contains($html,'Rolling applications'),'Detailed guide and rolling disclosure render.');
    $hashes[$r['id']]=json_encode($r,JSON_THROW_ON_ERROR);
}
rod(json_encode(array_map(static fn($item)=>desk_get($item['record']),$initial),JSON_THROW_ON_ERROR)===$snapshot,'Existing sixteen listings are untouched.');
rod((int)$pdo->query('SELECT COUNT(*) FROM posts')->fetchColumn()===$before['posts']+2,'Exactly two new backing posts.');
$again=rolling_opportunities_import($uid,$catalog);rod(count(array_filter($again,static fn($item)=>$item['state']==='preserved'))===2,'Repeated deployments do not duplicate content.');
foreach($hashes as$id=>$snapshot)rod(json_encode(desk_get($id),JSON_THROW_ON_ERROR)===$snapshot,'Repeat release preserves original publication date and audit.');
$r=desk_get($report[0]['record']);$p=writer_post_by_id((int)$r['post_id']);$input=desk_post_input($p);$input['seo_title']='Private owner SEO draft';writer_save_post($input,$uid,false);
$r=desk_get($report[1]['record']);$r=desk_update($r['id'],['version'=>$r['version'],'action'=>'close','note'=>'Fixture organiser closure'],$uid);$closed=rod_render($r);rod(str_contains($closed,'data-opportunity-status="closed"')&&!str_contains($closed,'Check the official details and application route</a>'),'Manual closure disables active application invitations.');
rolling_opportunities_import($uid,$catalog);
$r=desk_get($report[0]['record']);rod(writer_post_by_id((int)$r['post_id'])['seo_title']==='Private owner SEO draft','Private editorial work survives repeat deployment.');
rod(desk_get($report[1]['record'])['state']==='closed','Manual closure is not reopened by the content release.');
$r=desk_get($report[1]['record']);desk_update($r['id'],['version'=>$r['version'],'action'=>'withdraw','note'=>'Fixture withdrawal'],$uid);rolling_opportunities_import($uid,$catalog);rod(desk_get($r['id'])['state']==='withdrawn','Withdrawals are preserved.');
foreach(['email_outbox','publishing_jobs']as$table)rod((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$before[$table],'No outbound delivery is scheduled: '.$table);
$country=africa_country_opportunities_catalog();foreach($country['entries']as&$entry){if($entry['deadline_iso']!==null)$entry['deadline_iso']=(new DateTimeImmutable('+7 days'))->format('Y-m-d\TH:i:00P');}unset($entry);
$countryReport=africa_country_opportunities_import($uid,$country);rod(count($countryReport)===7&&count(array_filter($countryReport,static fn($item)=>$item['state']==='published'))===7,'Seven country opportunities publish after the existing eighteen.');
$countryAgain=africa_country_opportunities_import($uid,$country);rod(count(array_filter($countryAgain,static fn($item)=>$item['state']==='preserved'))===7,'Country release is idempotent.');
$international=international_africa_opportunities_catalog();$future=(new DateTimeImmutable('+8 days'))->format('Y-m-d');foreach($international['entries']as&$entry){if($entry['deadline_iso']!==null){$entry['deadline_iso']=(new DateTimeImmutable('+8 days'))->format('Y-m-d\TH:i:00P');$entry['deadline_date']=$future;}elseif($entry['deadline_date']!=='')$entry['deadline_date']=$future;}unset($entry);
$internationalReport=international_africa_opportunities_import($uid,$international);rod(count($internationalReport)===6&&count(array_filter($internationalReport,static fn($item)=>$item['state']==='published'))===6,'Six international opportunities publish after the country batch.');
$internationalAgain=international_africa_opportunities_import($uid,$international);rod(count(array_filter($internationalAgain,static fn($item)=>$item['state']==='preserved'))===6,'International release is idempotent.');
$education=education_research_opportunities_catalog();$futureResearch=(new DateTimeImmutable('+9 days'))->format('Y-m-d');foreach($education['entries']as&$entry){if($entry['deadline_iso']!==null){$entry['deadline_iso']=(new DateTimeImmutable('+9 days'))->format('Y-m-d\TH:i:00P');$entry['deadline_date']=$futureResearch;}elseif(($entry['deadline_date']??'')!=='')$entry['deadline_date']=$futureResearch;}unset($entry);
$educationReport=education_research_opportunities_import($uid,$education);rod(count($educationReport)===6&&count(array_filter($educationReport,static fn($item)=>$item['state']==='published'))===6,'Six education and research opportunities publish after the international batch.');
$educationAgain=education_research_opportunities_import($uid,$education);rod(count(array_filter($educationAgain,static fn($item)=>$item['state']==='preserved'))===6,'Education and research release is idempotent.');
rod(count(desk_public_list('opportunity'))===36,'Nineteen new records remain public alongside the fixture set after one deliberate rolling withdrawal.');
foreach(['email_outbox','publishing_jobs']as$table)rod((int)$pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()===$before[$table],'Country, international, education and research releases schedule no outbound delivery: '.$table);
$bad=$catalog;$bad['entries'][0]['apply_url']='https://unrelated.example.com/apply';try{rolling_opportunities_catalog($bad);throw new RuntimeException('Unexpected application origin accepted.');}catch(RuntimeException $e){rod($e->getMessage()==='Unexpected source origin.','Application origin is checked.');}
restore_error_handler();echo 'PASS: '.$n." rolling content, native publication and preservation assertions.\n";
