<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
try{db()->exec(file_get_contents(dirname(__DIR__).'/app/migrations/180_culture_topic.sql'));fwrite(STDOUT,"Culture topic is available; any existing category is preserved.\n");}catch(Throwable $e){fwrite(STDERR,"Culture topic could not be verified: ".get_class($e)."\n");exit(1);}
