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
// A previously confirmed Google subject retains its reviewed selections as prechecked
// choices, but pending reauthorization must not silently restore calendar API access.
$dbSet=db()->prepare("UPDATE google_calendar_accounts SET status='connected',selected_json=? WHERE user_id=? AND google_sub='google-one'");
$dbSet->execute([publishing_json(['calendar-google-one']),$ids[0]]);
google_calendar_observed($token,$scopes,['id'=>'google-one','name'=>'Reconnected','resources'=>[['id'=>'calendar-google-one','name'=>'Still available'],['id'=>'replacement','name'=>'New calendar']]],$ids[0]);
gwd_check(count(google_calendar_accounts($ids[0]))===2,'Reconnect same Google subject updates one row without dropping another account.');
$q=db()->prepare("SELECT * FROM google_calendar_accounts WHERE user_id=? AND google_sub='google-one'");$q->execute([$ids[0]]);$updated=$q->fetch();
gwd_check($updated['status']==='pending_confirmation','Reconnection requires explicit destination reconfirmation.');
gwd_check(publishing_decode($updated['selected_json'])===['calendar-google-one'],'Same-subject reauthorization must preserve the selected calendar as review prefill.');
gwd_check(google_calendar_selection(publishing_decode($updated['calendars_json']),publishing_decode($updated['selected_json']))===['calendar-google-one'],'Retained selection must be present in the fresh returned inventory.');
try{google_calendar_token($updated);throw new LogicException('Unconfirmed reauthorized token accepted');}catch(RuntimeException $e){gwd_check(str_contains($e->getMessage(),'Confirm'),'Reauthorization does not silently enable access');}
google_calendar_observed($token,$scopes,['id'=>'google-one','name'=>'Reconnected again','resources'=>[['id'=>'replacement','name'=>'New calendar']]],$ids[0]);
$q->execute([$ids[0]]);$updated=$q->fetch();
gwd_check(publishing_decode($updated['selected_json'])===['calendar-google-one'],'Keep previous selection for audit until explicit reconfirmation.');
try{google_calendar_selection(publishing_decode($updated['calendars_json']),publishing_decode($updated['selected_json']));throw new LogicException('Stale selection was accepted');}
catch(InvalidArgumentException){}
gwd_check(google_calendar_selection(publishing_decode($updated['calendars_json']),['replacement'])===['replacement'],'Only freshly returned calendars may be confirmed.');
gwd_check(count(google_calendar_accounts($ids[0]))===2&&google_calendar_accounts($ids[1])===[],'Reconnect must preserve other accounts and cross-user isolation.');
$r=['key'=>'ga4-countries','destination_hash'=>hash('sha256','properties/123'),'rows'=>[['Kenya','1']]];google_workspace_save_report($r,$ids[0]);gwd_check((int)db()->query('SELECT COUNT(*) FROM google_report_snapshots')->fetchColumn()===1,'Private report snapshot saved.');
echo "PASS: multi-account retention, saved selection prefill, stale exclusion, reconnection confirmation, encrypted access and report storage.\n";

// The already-applied migration must verify using runtime SELECT only, without an admin ARN.
$env=getenv();unset($env['DB_ADMIN_SECRET_ARN']);$pipes=[];
$process=proc_open([PHP_BINARY,dirname(__DIR__).'/bin/google-workspace-migrate.php','--apply'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
if(!is_resource($process))throw new RuntimeException('Cannot launch migration fixture.');fclose($pipes[0]);$output=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);
gwd_check($status===0&&str_contains($output,'already verified with runtime read access'),'An applied Google migration must not need administrator access on routine releases.');
