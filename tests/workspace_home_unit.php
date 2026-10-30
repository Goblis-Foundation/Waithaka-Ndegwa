<?php
declare(strict_types=1);
function comm_config():array{return ['owner'=>7];}
function reader_operations_summary():array{$GLOBALS['reads']++;if(!empty($GLOBALS['fail']))throw new RuntimeException('Unavailable');return ['enquiries_open'=>0,'enquiries_overdue'=>0];}
function comm_user_devices(int $id):array{if(!empty($GLOBALS['fail']))throw new RuntimeException('Unavailable');return [['enabled'=>true],['enabled'=>false]];}
function comm_get(string $key,array $default=[]):array{return $default;}
function comm_zone(string $zone):DateTimeZone{return new DateTimeZone($zone);}
function planner_day(int $id,string $date,string $zone):array{if(!empty($GLOBALS['fail']))throw new RuntimeException('Unavailable');return [['title'=>'past','start'=>time()-200,'end'=>time()-100,'status'=>'planned'],['title'=>'later','start'=>time()+100,'end'=>time()+200,'status'=>'planned'],['title'=>'cancelled','start'=>time(),'end'=>time()+200,'status'=>'cancelled']];}
require dirname(__DIR__).'/app/workspace_home.php';
function home_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$GLOBALS['reads']=0;$a=workspace_home_snapshot(7);home_check($a['is_owner']&&$a['open']===0&&$a['devices']===1,'Known empty inbox and enabled-device count');home_check(count($a['activities'])===1&&$a['activities'][0]['title']==='later'&&$a['active_total']===2,'Past and cancelled work stays out of next steps');
$b=workspace_home_snapshot(8);home_check(!$b['is_owner']&&!$b['can_communicate']&&$b['open']===null&&$GLOBALS['reads']===1,'Collaborators do not read owner inbox');
$GLOBALS['fail']=true;$c=workspace_home_snapshot(7);home_check($c['open']===null&&$c['devices']===null&&$c['activities']===null,'Unavailable inventories stay unknown');
$paths=[];foreach(workspace_home_journeys()as[$name,$public,$private,$flow]){home_check(str_starts_with($public,'/')&&!str_starts_with($public,'/write')&&str_starts_with($private,'/write'),'Public/private routes are separate');$paths[]=$public;}home_check(count(array_unique($paths))===9,'Nine distinct visitor journeys');
echo "PASS: dashboard unknown-state, owner privacy, daily priorities and public/private journey boundaries.\n";
