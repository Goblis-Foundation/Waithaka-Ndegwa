<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';require dirname(__DIR__).'/app/migrations.php';
try {
    $plan=in_array('--plan',$argv,true);$fresh=in_array('--fresh',$argv,true);
    foreach(wk_migrate($fresh,$plan)as$action)fwrite(STDOUT,($plan?'PLAN: ':'DONE: ').$action.PHP_EOL);
    fwrite(STDOUT,$plan?"Plan complete. No schema changes applied.\n":"Database is up to date.\n");
} catch(Throwable $e){fwrite(STDERR,'Migration stopped: '.$e->getMessage().PHP_EOL);exit(1);}
