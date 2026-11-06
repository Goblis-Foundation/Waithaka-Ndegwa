<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/country_opportunities_batch3_release.php';
$uid=(int)(comm_config()['owner']??0);
if($uid<1)throw new RuntimeException('Configured website owner required.');
echo json_encode(country_opportunities_batch3_import($uid),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
