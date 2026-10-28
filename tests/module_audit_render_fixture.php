<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||count($argv)!==2)exit(2);
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
$out=realpath($argv[1]);if(!$out||!str_starts_with($out,sys_get_temp_dir().'/')&&!str_starts_with($out,(string)getenv('RUNNER_TEMP').'/'))throw new RuntimeException('Use a disposable fixture directory.');
$CONFIG=['app_env'=>'testing','base_url'=>'http://127.0.0.1:8125','timezone'=>'Africa/Nairobi','app_key'=>'isolated-fixture-only'];$APP_INSTALLED=false;$_SESSION=[];$_GET=[];$_SERVER['REQUEST_URI']='/write/login';
foreach(['functions','reader','planner','workspace_reports','impact','reconciliation_register']as$m)require_once dirname(__DIR__).'/app/'.$m.'.php';
// Replace only persistence in this isolated renderer. All templates and CSS are production files.
function comm_get(string $key,array $default=[]):array{return $default;}
function comm_records(string $key,int $limit=2000):array{return [];}
function comm_config():array{return ['owner'=>0];}
function comm_zone(mixed $zone):DateTimeZone{return new DateTimeZone($zone);}
function comm_date(mixed $date):string{return $date;}
function ma_render(string $view,array $data=[]):string{extract($data,EXTR_SKIP);ob_start();include dirname(__DIR__).'/views/write/'.$view.'.php';return (string)ob_get_clean();}
function ma_page(string $view,array $data=[],bool $workspace=true):string{$content=ma_render($view,$data);$page=ma_render('layout',['content'=>$content,'writeTitle'=>'Isolated module fixture']);return $workspace?str_replace('class="workspace-guest"','class="workspace-auth"',$page):$page;}
file_put_contents($out.'/module-login.html',ma_page('login',[],false));
$_SESSION['flash']=[['type'=>'error','message'=>'Email or password is incorrect.']];
file_put_contents($out.'/module-login-error.html',ma_page('login',['loginEmail'=>'fixture@example.invalid','trustDevice'=>false],false));
$_SERVER['REQUEST_URI']='/write/program';
file_put_contents($out.'/module-program.html',ma_page('program',['uid'=>1,'prefs'=>['timezone'=>'Africa/Nairobi'],'date'=>'2026-10-28','calendar'=>[],'rows'=>[],'mail'=>[],'error'=>'','edit'=>null,'stage'=>null]));
$_SERVER['REQUEST_URI']='/write/desk';
foreach([false,true]as$billing)file_put_contents($out.'/module-desk-'.(int)$billing.'.html',ma_page('desk_index',['billing'=>$billing,'items'=>[],'orders'=>[]]));
$_SERVER['REQUEST_URI']='/write/impact';file_put_contents($out.'/module-impact.html',ma_page('impact'));
$_SERVER['REQUEST_URI']='/write/reconciliation';file_put_contents($out.'/module-register.html',ma_page('reconciliation',['items'=>reconciliation_items()]));
echo "Rendered seven isolated module fixtures with no database or provider access.\n";
