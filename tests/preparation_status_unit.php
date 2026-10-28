<?php
declare(strict_types=1);
require_once __DIR__.'/../app/preparation_status.php';
$store=[];function comm_get(string $k,array $default=[]):array{return $GLOBALS['store'][$k]??$default;}function comm_put(string $k,array $v):void{$GLOBALS['store'][$k]=$v;}
$result=preparation_stage('editorial',static fn()=>['enabled'=>true,'prepared'=>true,'private_content'=>'never record','token'=>'never record']);
$saved=$store['preparation.status.editorial'];if($saved['state']!=='completed'||isset($saved['private_content'],$saved['token'])||isset($saved['counts']['token'])||$result['token']!=='never record')throw new RuntimeException('Monitoring must sanitize storage without changing return values.');
preparation_stage('calendars',static fn()=>['enabled'=>false,'checked'=>0]);if($store['preparation.status.calendars']['state']!=='disabled')throw new RuntimeException('Disabled is distinct from failure.');
preparation_stage('impact',static fn()=>['failed'=>2,'checked'=>1]);if($store['preparation.status.impact']['state']!=='attention')throw new RuntimeException('Incomplete stage needs attention.');
try{preparation_stage('audience',static function(){throw new RuntimeException('secret provider body');});throw new LogicException('Expected failure');}catch(RuntimeException $e){if($e->getMessage()!=='secret provider body'||$store['preparation.status.audience']['state']!=='attention'||str_contains(json_encode($store),'secret provider body'))throw new LogicException('Failure monitoring preserves behavior without logging bodies.');}
if(count(preparation_status())!==4)throw new RuntimeException('Every monitored stage retained.');
echo "PASS: private preparation observability, disabled states and payload privacy.\n";
