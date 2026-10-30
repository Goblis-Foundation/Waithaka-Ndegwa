<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==2||!in_array($argv[1],['--plan','--apply'],true))throw new RuntimeException('Use --plan or --apply.');
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/community_channel_catalogue.php';
community_catalogue_hash();
if($argv[1]==='--plan'){
    fwrite(STDOUT,'Community catalogue: '.count(community_catalogue_missing(db()))." missing channel(s). Existing owner routes are preserved.\n");exit;
}
community_catalogue_apply(db());
fwrite(STDOUT,"Community catalogue verified: Threads, Telegram, Discord and Signal are available. No account authorization, post or queue was created.\n");
