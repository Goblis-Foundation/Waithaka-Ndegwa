<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
// Aggregate reporting is independent of paused mail/social delivery. No feeds means no network calls.
if ($APP_INSTALLED && app_config('app_env')==='production') {
    try { impact_sync(); } catch (Throwable $e) { error_log('Impact reporting refresh unavailable.'); }
}
// Read-only consented Google aggregates for the public footer.
if($APP_INSTALLED && app_config('app_env')==='production'){
    try { visitor_statistics_refresh(); } catch(Throwable $e) { error_log('Footer visitor statistics unavailable.'); }
}
// Independently opted-in booking service. Does not drain newsletter/social/payment queues.
if($APP_INSTALLED && app_config('app_env')==='production'){
    try { communication_work(); } catch(Throwable $e) { error_log('Communication worker needs review.'); }
}
if($APP_INSTALLED && app_config('app_env')==='production'){
    try { planner_work(); } catch(Throwable $e) { error_log('Daily program worker needs review.'); }
}
// New contact submissions only; private scoped delivery never releases the old outbox.
if($APP_INSTALLED && app_config('app_env')==='production'){
    try { contact_delivery_work(); } catch(Throwable $e) { error_log('Contact delivery needs review.'); }
}
// Staging can serve restored content without draining live external delivery work.
if($APP_INSTALLED && app_config('app_env')==='production'){
    try { founder_story_work(); } catch(Throwable $e) { error_log('Founder story delivery or expiry needs review.'); }
}
// Only explicitly queued weekly revisions; legacy mail/payment/social queues keep their own gate.
if($APP_INSTALLED && app_config('app_env')==='production'){
    try { publishing_weekly_work(); } catch(Throwable $e) { error_log('Weekly publishing queue needs review.'); }
}
// Explicitly enabled read-only PayPal reconciliation; never captures money or drains other delivery queues.
if($APP_INSTALLED && app_config('app_env')==='production'){
    try { paypal_notifications_work(); } catch(Throwable $e) { error_log('PayPal reconciliation needs review.'); }
}
if($APP_INSTALLED && app_config('app_env')==='production'){
    try { monthly_work(); } catch(Throwable $e) { error_log('Monthly support status needs review.'); }
}
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


