<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
// Staging can serve restored content without draining live external delivery work.
$enabled=$APP_INSTALLED && (bool)app_config('worker_enabled',false);
$run=$APP_INSTALLED && function_exists('readiness_worker_begin')?readiness_worker_begin($enabled):null;
if (!$enabled) { echo "{\"enabled\":false}\n"; exit; }
$limit=max(1,min(50,(int)($argv[1]??10)));$stats=[];
$success=false;
try {
    foreach(['publishing'=>'publishing_work','payments'=>'payment_reconcile_due','newsletter'=>'newsletter_work','email'=>'email_work','discovery'=>'discovery_work']as$stage=>$handler){
        if(!function_exists($handler))continue;
        if(function_exists('readiness_worker_touch'))readiness_worker_touch($run,$stage,$stats);
        $stats[$stage]=$handler($limit);
    }
    $success=true;echo json_encode($stats,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    if(function_exists('readiness_worker_finish'))readiness_worker_finish($run,$success,$stats,$success?null:'stage_failed');
}
