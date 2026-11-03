<?php
declare(strict_types=1);
require_once __DIR__.'/../app/preparation_status.php';
$store=[];function comm_lock(string $k,callable $fn):mixed{return $fn();}function comm_get(string $k,array $default=[]):array{return $GLOBALS['store'][$k]??$default;}function comm_put(string $k,array $v):void{$GLOBALS['store'][$k]=$v;}
$result=preparation_stage('editorial',static fn()=>['enabled'=>true,'prepared'=>true,'private_content'=>'never record','token'=>'never record']);
$saved=$store['preparation.status.editorial'];if($saved['state']!=='completed'||isset($saved['private_content'],$saved['token'])||isset($saved['counts']['token'])||$result['token']!=='never record')throw new RuntimeException('Monitoring must sanitize storage without changing return values.');
preparation_stage('calendars',static fn()=>['enabled'=>false,'checked'=>0]);if($store['preparation.status.calendars']['state']!=='disabled')throw new RuntimeException('Disabled is distinct from failure.');
preparation_stage('impact',static fn()=>['failed'=>2,'checked'=>1]);if($store['preparation.status.impact']['state']!=='attention')throw new RuntimeException('Incomplete stage needs attention.');
try{preparation_stage('audience',static function(){throw new RuntimeException('secret provider body');});throw new LogicException('Expected failure');}catch(RuntimeException $e){if($e->getMessage()!=='secret provider body'||$store['preparation.status.audience']['state']!=='attention'||str_contains(json_encode($store),'secret provider body'))throw new LogicException('Failure monitoring preserves behavior without logging bodies.');}
if(count(preparation_status())!==5)throw new RuntimeException('Every monitored stage retained.');
echo "PASS: private preparation observability, disabled states and payload privacy.\n";

background_stage('contact',static fn()=>['processed'=>2,'sent'=>1,'recipient'=>'private@example.invalid']);
if(isset($store['preparation.status.contact']['counts']['recipient']))throw new RuntimeException('No private recipient in monitoring.');
$store['preparation.status.cleanup']=['state'=>'running','started'=>100];$rows=array_column(background_status(2000),null,'key');if($rows['cleanup']['state']!=='interrupted')throw new RuntimeException('Stalled invocation hidden.');
$store['preparation.status.cleanup']=['state'=>'secret raw state','started'=>3000];$rows=array_column(background_status(2000),null,'key');if($rows['cleanup']['state']!=='not_recorded'||$rows['cleanup']['at']!==0)throw new RuntimeException('Invalid or future evidence trusted.');
background_stage('planner',static function(){background_stage('planner',static fn()=>['checked'=>7]);return ['checked'=>1];});if($store['preparation.status.planner']['counts']['checked']!==7)throw new RuntimeException('Older overlapping completion overwrote new evidence.');
try{background_stage('unknown',static fn()=>throw new LogicException('must not run'));throw new LogicException('Unknown stage accepted');}catch(InvalidArgumentException){}
if(count(background_names())!==22)throw new RuntimeException('All timer and delivery stages covered.');
