<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
// Staging can serve restored content without draining live external delivery work.
if (!$APP_INSTALLED || !app_config('worker_enabled',false)) { echo "{\"enabled\":false}\n"; exit; }
$limit=max(1,min(50,(int)($argv[1]??10)));$stats=[];
if(function_exists('publishing_work'))$stats['publishing']=publishing_work($limit);
if(function_exists('payment_reconcile_due'))$stats['payments']=payment_reconcile_due($limit);
$stats['email']=email_work($limit);
echo json_encode($stats,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
