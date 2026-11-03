<?php
declare(strict_types=1);
/** Streaming cap: a rejected chunk is never appended or echoed. */
function response_append_bounded(string &$buffer,string $chunk,int $limit): int {
    $bytes=strlen($chunk);
    if($limit<0||strlen($buffer)>$limit||$bytes>$limit-strlen($buffer))return 0;
    $buffer.=$chunk;return $bytes;
}
