<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/migrations.php';
require dirname(__DIR__).'/app/message_trash_migration.php';
try{message_trash_apply_migration(db(),dirname(__DIR__).'/app/migrations/190_message_trash.sql');fwrite(STDOUT,"Message Trash table and migration ledger verified.\n");}
catch(Throwable $e){fwrite(STDERR,"Message Trash upgrade failed: ".get_class($e)."\n");exit(1);}
