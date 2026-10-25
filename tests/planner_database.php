<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
require __DIR__.'/prepare.php';$settings=wk_test_database_settings();$database=(string)(getenv('DB_DATABASE')?:getenv('WK_TEST_DB_NAME'));wk_test_assert_database_name($database);
$CONFIG=['app_env'=>'testing','base_url'=>'https://www.waithakandegwa.com','app_key'=>'local-planner-fixture-not-production','db'=>$settings+['name'=>$database]];
$APP_INSTALLED=true;
foreach(['functions','reader','operations','publishing','communication','communication_notifications','app_security','trusted_devices','planner','workspace_reports']as$m)require dirname(__DIR__).'/app/'.$m.'.php';
function pd(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function px(callable $fn):void{try{$fn();}catch(InvalidArgumentException|RuntimeException){return;}throw new LogicException('Expected rejected operation.');}
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Program fixture','program@example.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);$uid=(int)db()->lastInsertId();
set_setting('publishing.google.client_id','fixture-client');set_setting('publishing.google.client_secret','fixture-secret',true);
parse_str((string)parse_url(publishing_oauth_start('google_calendar',$uid),PHP_URL_QUERY),$readGrant);
parse_str((string)parse_url(publishing_oauth_start('google_calendar',$uid,true),PHP_URL_QUERY),$editGrant);
pd(!str_contains($readGrant['scope'],'calendar.events.owned')&&str_contains($editGrant['scope'],'calendar.events.owned'),'Calendar edit permission is requested only by explicit opt-in');
pd($readGrant['redirect_uri']===$editGrant['redirect_uri'],'Calendar editing reuses registered callback');
$_SESSION=['user_id'=>$uid];app_trust_issue($uid);$cookie=$_COOKIE[app_trust_cookie_name()];$trusted=$_SESSION['trusted_id'];$raw=db()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');$raw->execute(['communication.trusted.'.$trusted]);$encoded=$raw->fetchColumn();pd(!str_contains($encoded,explode('.',$cookie)[1]),'Bearer token not stored in clear text');
$_SESSION=[];pd(app_trust_restore()&&$_SESSION['user_id']===$uid,'Expired ordinary session restored from valid remembered device');
$_SESSION=[];comm_put('session.'.$trusted,['revoked'=>true]);pd(!app_trust_restore(),'Device session revocation prevents restoration');
$_COOKIE[app_trust_cookie_name()]=$cookie;comm_put('session.'.$trusted,['revoked'=>false]);set_setting('auth.password_epoch.'.$uid,'changed');pd(!app_trust_restore(),'Password epoch prevents restoration');
$_SESSION=['user_id'=>$uid];app_trust_issue($uid);app_trust_forget();pd(!app_trust_restore(),'Explicit sign-out does not automatically sign in');
$in=['title'=>'Private program fixture','start'=>'2026-11-02T09:00','end'=>'2026-11-02T10:00','request_id'=>str_repeat('a',32),'notes'=>'Private detail','recipients'=>'fixture@example.invalid'];
$r=planner_save($uid,$in);pd(planner_save($uid,$in)['id']===$r['id'],'Double submit idempotent');pd(count(planner_rows($uid))===1,'No duplicate item');
px(fn()=>planner_owned($uid+1,$r['id']));px(fn()=>planner_save($uid,array_replace($in,['id'=>$r['id'],'version'=>0])));
planner_change($uid,['action'=>'late','id'=>$r['id'],'version'=>1,'reason'=>'Traffic delay','late_minutes'=>10,'notify'=>'1']);
$r=planner_owned($uid,$r['id']);pd($r['late_reason']==='Traffic delay','Late reason retained');pd(count(comm_records('planner.mail',5000))===1,'Only explicit notice queued');
px(fn()=>planner_change($uid,['action'=>'cancel','id'=>$r['id'],'version'=>$r['version'],'reason'=>'']));
planner_change($uid,['action'=>'cancel','id'=>$r['id'],'version'=>$r['version'],'reason'=>'Rescheduled project visit']);$r=planner_owned($uid,$r['id']);pd($r['status']==='cancelled','Cancelled item retained with audit');
$parsed=planner_parse("2026-11-03 | 09:00 | 09:30 | Imported program | Nairobi",'text','Africa/Nairobi');$stage=str_repeat('b',32);comm_put('planner.import.'.$uid.'.'.$stage,$parsed+['digest'=>str_repeat('c',64),'expires'=>time()+3600]);
pd(planner_import_commit($uid,$stage,[1],[1=>['title'=>'Reviewed title']])===1,'Selected reviewed import committed');px(fn()=>planner_import_commit($uid,$stage,[1]));
pd(count(planner_rows($uid))===2&&planner_rows($uid+1)===[],'Owner namespace isolation');
$raw=db()->query("SELECT setting_value FROM settings WHERE setting_key LIKE 'communication.planner.item.%'")->fetchAll(PDO::FETCH_COLUMN);pd(!str_contains(implode('',$raw),'Private detail'),'Programs encrypted at rest');
planner_work();pd((int)db()->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn()===0,'No legacy mail queue activity or delivery without worker opt-in');
$at=(new DateTimeImmutable('2026-11-02T09:50:00+03:00'))->getTimestamp();
comm_put('config',array_replace(comm_config(),['owner'=>$uid,'worker'=>true]));
comm_put('preferences.'.$uid,['timezone'=>'Africa/Nairobi','enabled'=>true,'categories'=>['planner'],'quiet_start'=>'00:00','quiet_end'=>'00:00']);
comm_save_subscription($uid,json_encode(['endpoint'=>'https://fcm.googleapis.com/fcm/send/planner-fixture']));
planner_save($uid,['title'=>'Due reminder','start'=>'2026-11-02T10:05','end'=>'2026-11-02T10:30','request_id'=>str_repeat('d',32),'reminder'=>15]);
$pushes=0;$fake=function(array $device)use(&$pushes):int{$pushes++;return 201;};planner_work($at,$fake);planner_work($at+30,$fake);pd($pushes===1,'Due reminder sent once across worker passes');
comm_put('preferences.'.$uid,['timezone'=>'Africa/Nairobi','enabled'=>true,'categories'=>['planner'],'quiet_start'=>'00:00','quiet_end'=>'23:59']);
planner_save($uid,['title'=>'Quiet reminder','start'=>'2026-11-02T10:05','end'=>'2026-11-02T10:30','request_id'=>str_repeat('e',32),'reminder'=>15]);
planner_work($at,$fake);pd($pushes===1,'Quiet hours prevent device push');
planner_save($uid,['title'=>'Missed reminder','start'=>'2026-11-02T09:51','end'=>'2026-11-02T10:30','request_id'=>str_repeat('f',32),'reminder'=>15]);
planner_work($at,$fake);pd($pushes===1,'Late worker does not flood missed reminders');
echo "PASS: persistent trust, revocation, password changes, encrypted planning, ownership, optimistic updates, review/import and explicit scoped notices.\n";


$note=workspace_note_save($uid,['request_id'=>str_repeat('8',32),'title'=>'Private notes fixture','date'=>'2026-11-02','notes'=>'Synthetic meeting only','report'=>'Reviewed fixture report','approve_report'=>'1']);
pd($note['report_state']==='reviewed','Explicit report review retained');px(fn()=>workspace_note_owned($uid+1,$note['id']));
$changed=workspace_note_save($uid,['id'=>$note['id'],'request_id'=>str_repeat('9',32),'version'=>1,'title'=>$note['title'],'date'=>$note['date'],'notes'=>$note['notes'],'report'=>'Changed report']);pd($changed['report_state']==='draft','Any saved edit needs fresh approval');
px(fn()=>workspace_note_email($uid,['id'=>$note['id'],'version'=>1]));px(fn()=>workspace_note_email($uid,['id'=>$note['id'],'version'=>2]));
planner_routine_save($uid,['routine'=>'1,2,3,4,5,6,7 | 22:00 | 06:00 | Sleep | personal','timezone'=>'Africa/Nairobi','version'=>0]);px(fn()=>planner_routine_save($uid,['routine'=>'1 | 09:00 | 10:00 | Work | focus','version'=>0]));
pd(planner_routine_apply($uid,['week'=>'2026-11-04'])===7,'Routine applied atomically to week');px(fn()=>planner_routine_apply($uid,['week'=>'2026-11-02']));pd(count(array_filter(planner_rows($uid),static fn($r)=>$r['project']==='Weekly routine'))===7,'Repeated apply adds no duplicates');
$booking=['id'=>str_repeat('7',32),'status'=>'requested','version'=>1,'brief_status'=>'pending','brief_report'=>''];comm_put('booking.'.$booking['id'],$booking);px(fn()=>comm_change_booking($booking['id'],'approve'));pd(comm_get('booking.'.$booking['id'])['status']==='requested','Missing briefing prevents confirmation before provider access');
echo "PASS: private note ownership, report invalidation, routine duplicate prevention and fail-closed booking acceptance.\n";
