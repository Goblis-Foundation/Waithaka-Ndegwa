<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||getenv('APP_ENV')!=='testing')exit(2);
require __DIR__.'/prepare.php';$settings=wk_test_database_settings();$database=(string)(getenv('DB_DATABASE')?:getenv('WK_TEST_DB_NAME'));wk_test_assert_database_name($database);
$CONFIG=['app_env'=>'testing','base_url'=>'https://www.waithakandegwa.com','app_key'=>'local-test-key-not-for-production-use','db'=>$settings+['name'=>$database]];$APP_INSTALLED=true;
require dirname(__DIR__).'/app/functions.php';require dirname(__DIR__).'/app/publishing.php';require dirname(__DIR__).'/app/google_workspace.php';
function gwd_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$insert=db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)');$ids=[];foreach(['a','b']as$n){$insert->execute(['Fixture '.$n,$n.'@example.invalid',password_hash('fixture-only',PASSWORD_DEFAULT)]);$ids[]=(int)db()->lastInsertId();}
$token=['access_token'=>'fixture-secret','refresh_token'=>'fixture-refresh','expires_at'=>time()+3600];$scopes=['https://www.googleapis.com/auth/calendar.events.readonly'];
foreach(['google-one','google-two']as$id)google_calendar_observed($token,$scopes,['id'=>$id,'name'=>$id,'resources'=>[['id'=>'calendar-'.$id,'name'=>'Selected calendar']]],$ids[0]);
gwd_check(count(google_calendar_accounts($ids[0]))===2,'A second Google account must not overwrite the first.');gwd_check(google_calendar_accounts($ids[1])===[],'Accounts isolated by Writer user.');
$accounts=google_calendar_accounts($ids[0]);$a=$accounts[0];gwd_check(!str_contains($a['credentials_encrypted'],'fixture-secret'),'Calendar credentials encrypted.');
try{google_calendar_owned((int)$a['id'],$ids[1]);throw new LogicException('Foreign access accepted');}catch(InvalidArgumentException){}
try{google_calendar_token($a);throw new LogicException('Pending token accepted');}catch(RuntimeException $e){gwd_check(str_contains($e->getMessage(),'Confirm'),'Pending calendars do not expose token');}
google_calendar_observed($token,$scopes,['id'=>'google-one','name'=>'Reconnected','resources'=>[['id'=>'replacement','name'=>'New calendar']]],$ids[0]);gwd_check(count(google_calendar_accounts($ids[0]))===2,'Reconnect same identity updates one row.');
$q=db()->prepare("SELECT * FROM google_calendar_accounts WHERE user_id=? AND google_sub='google-one'");$q->execute([$ids[0]]);$updated=$q->fetch();gwd_check($updated['status']==='pending_confirmation'&&publishing_decode($updated['selected_json'])===[],'Reconnect requires destination reconfirmation.');
$r=['key'=>'ga4-countries','destination_hash'=>hash('sha256','properties/123'),'rows'=>[['Kenya','1']]];google_workspace_save_report($r,$ids[0]);gwd_check((int)db()->query('SELECT COUNT(*) FROM google_report_snapshots')->fetchColumn()===1,'Private report snapshot saved.');
echo "PASS: multi-account retention, ownership, encryption, reconnect confirmation and report storage.\n";

// The already-applied migration must verify using runtime SELECT only, without an admin ARN.
$env=getenv();unset($env['DB_ADMIN_SECRET_ARN']);$pipes=[];
$process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/google-workspace-migrate.php','--apply'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
if(!is_resource($process))throw new RuntimeException('Cannot launch migration fixture.');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
gwd_check($status===0&&str_contains($output,'already verified with runtime read access'),'An applied Google migration must not need administrator access on routine releases.');
