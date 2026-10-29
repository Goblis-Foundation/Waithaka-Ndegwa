<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||count($argv)!==2)exit(2);
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$out=realpath($argv[1]);if(!$out||!str_starts_with($out,sys_get_temp_dir().'/')&&!str_starts_with($out,(string)getenv('RUNNER_TEMP').'/'))throw new RuntimeException('Use a disposable fixture directory.');
$CONFIG=['app_env'=>'testing','base_url'=>'http://127.0.0.1:8125','timezone'=>'Africa/Nairobi','app_key'=>'isolated-fixture-only'];$APP_INSTALLED=false;$_SESSION=[];$_GET=[];$_SERVER['REQUEST_URI']='/write/login';
foreach(['functions','reader','planner','workspace_reports','impact','reconciliation_register','google_actions','writer_auth','writer_auth_providers','experience_workflows']as$m)require_once dirname(__DIR__).'/app/'.$m.'.php';
// Replace only persistence in this isolated renderer. All templates and CSS are production files.
function comm_get(string $key,array $default=[]):array{return $default;}
function comm_records(string $key,int $limit=2000):array{return [];}
function publishing_callback_url(string $service):string{return 'https://example.invalid/write/connections/callback/'.$service;}
function publishing_client(string $provider):array{return ['client_id'=>'synthetic-client','client_secret'=>'synthetic-secret'];}
function publishing_connection(string $service):?array{return null;}
function comm_config():array{return ['owner'=>7];}
function comm_zone(mixed $zone):DateTimeZone{return new DateTimeZone($zone);}
function comm_date(mixed $date):string{return $date;}
function ma_render(string $view,array $data=[]):string{extract($data,EXTR_SKIP);ob_start();include dirname(__DIR__).'/views/write/'.$view.'.php';return (string)ob_get_clean();}
function ma_page(string $view,array $data=[],bool $workspace=true):string{$content=ma_render($view,$data);$page=ma_render('layout',['content'=>$content,'writeTitle'=>'Isolated module fixture','writerShellUser'=>$workspace?['id'=>7,'name'=>'Synthetic owner','email'=>'fixture@example.invalid']:null]);return $workspace?str_replace('class="workspace-guest"','class="workspace-auth"',$page):$page;}
file_put_contents($out.'/module-login.html',ma_page('login',[],false));
$_SESSION['flash']=[['type'=>'error','message'=>'Email or password is incorrect.']];
file_put_contents($out.'/module-login-error.html',ma_page('login',['loginEmail'=>'fixture@example.invalid','trustDevice'=>false],false));
$_SERVER['REQUEST_URI']='/write/program';
file_put_contents($out.'/module-program.html',ma_page('program',['uid'=>1,'prefs'=>['timezone'=>'Africa/Nairobi'],'date'=>'2026-10-28','calendar'=>[],'rows'=>[],'mail'=>[],'error'=>'','edit'=>null,'stage'=>null]));
$_SERVER['REQUEST_URI']='/write/desk';
foreach([false,true]as$billing)file_put_contents($out.'/module-desk-'.(int)$billing.'.html',ma_page('desk_index',['billing'=>$billing,'items'=>[],'orders'=>[]]));
$_SERVER['REQUEST_URI']='/write/impact';file_put_contents($out.'/module-impact.html',ma_page('impact'));
$_SERVER['REQUEST_URI']='/write/reconciliation';file_put_contents($out.'/module-register.html',ma_page('reconciliation',['items'=>reconciliation_items()]));
$_SERVER['REQUEST_URI']='/write/google/actions';
$base=['part'=>'','record'=>null,'records'=>[],'values'=>[],'uid'=>7,'error'=>''];
file_put_contents($out.'/module-google-actions.html',ma_page('google_actions',$base));
file_put_contents($out.'/module-google-new.html',ma_page('google_actions',array_replace($base,['part'=>'new','values'=>['kind'=>'sheet']])));
$record=['id'=>str_repeat('a',32),'state'=>'reviewed','kind'=>'sheet','title'=>'Selected table','text'=>"Name\tValue\nReviewed source\t=literal text",'account_name'=>'Synthetic fixture account','destination'=>'My Drive','due'=>'','version'=>2,'audit'=>[['state'=>'draft','at'=>1793193600]]];
file_put_contents($out.'/module-google-review.html',ma_page('google_actions',array_replace($base,['part'=>$record['id'],'record'=>$record])));
file_put_contents($out.'/module-google-sync.html',ma_page('google_calendar_sync',['config'=>['enabled'=>true,'push'=>false],'rows'=>[['account'=>'Verified owner · owner@example.invalid','calendar'=>'Owner calendar','observed'=>1793188800,'attempted'=>1793188800,'retry_at'=>0,'mode'=>'full','count'=>2,'error'=>'','push_error'=>'','expires'=>0],['account'=>'Second account · second@example.invalid','calendar'=>'Calendar awaiting review','observed'=>0,'attempted'=>1793188800,'retry_at'=>1793190600,'mode'=>'Not checked','count'=>0,'error'=>'Google rejected access renewal (HTTP 400). Review this account’s Google authorization and application settings. Any previous snapshot remains stale.','push_error'=>'','expires'=>0]],'error'=>'']));
$_SERVER['REQUEST_URI']='/write/google/meet';
$meetBase=['record'=>null,'discovery'=>null,'selection'=>null,'error'=>'','connected'=>null,'records'=>[]];
file_put_contents($out.'/module-google-meet.html',ma_page('google_meet',$meetBase));
$meetArtifact=['name'=>'conferenceRecords/synthetic-meeting/transcripts/synthetic-transcript','kind'=>'transcript','state'=>'FILE_GENERATED','start'=>'2026-10-29T10:00:00Z','end'=>'2026-10-29T11:00:00Z','url'=>'https://docs.google.com/document/d/synthetic-doc/edit'];
$meetSelection=['account'=>'Synthetic account · owner@example.invalid','conference'=>'conferenceRecords/synthetic-meeting','fingerprint'=>str_repeat('f',64),'artifacts'=>[$meetArtifact]];
file_put_contents($out.'/module-google-meet-select.html',ma_page('google_meet',array_replace($meetBase,['selection'=>$meetSelection])));
$meetRecord=['id'=>str_repeat('c',64),'owner'=>7,'account'=>'Synthetic account · owner@example.invalid','imported_at'=>1793268000,'artifact'=>$meetArtifact,'entries'=>[['speaker'=>1,'text'=>'<script>Literal untrusted transcript content</script> '.str_repeat('longunbrokenword',30),'start'=>'2026-10-29T10:00:00Z','end'=>'2026-10-29T10:00:10Z']]];
file_put_contents($out.'/module-google-meet-saved.html',ma_page('google_meet',array_replace($meetBase,['record'=>$meetRecord,'records'=>[$meetRecord]])));
$_SERVER['REQUEST_URI']='/write/verify';
file_put_contents($out.'/module-verify.html',ma_page('verify_login',['challenge'=>['uid'=>7,'email'=>'fixture@example.invalid','method'=>'email','delivery'=>'accepted','link_identity'=>[]],'error'=>''],false));
$_SERVER['REQUEST_URI']='/write/security/sign-in';
file_put_contents($out.'/module-auth-settings.html',ma_page('sign_in_security',['user'=>['id'=>7,'email'=>'fixture@example.invalid'],'uid'=>7,'error'=>'','factors'=>[],'setup'=>[],'phoneSetup'=>[],'codes'=>[],'identities'=>[]]));
$_SERVER['REQUEST_URI']='/write/control-centre';file_put_contents($out.'/module-directory.html',ma_page('control_centre',['counts'=>['Draft articles'=>3,'Open conversations'=>2,'Awaiting review'=>null,'Published articles'=>12]]));
echo "Rendered seventeen isolated module fixtures with no database or provider access.\n";
