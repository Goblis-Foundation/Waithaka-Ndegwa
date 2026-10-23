<?php
declare(strict_types=1);
// Recovery and initialization use authenticated server access and bin/migrate.php.
http_response_code(404);
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/plain; charset=UTF-8');
echo 'Not found.';
