<?php
declare(strict_types=1);
// Pure regression checks: no bootstrap, database, AWS SDK, or provider calls.
require dirname(__DIR__).'/app/environment.php';
require dirname(__DIR__).'/app/media_storage.php';

$checks=0;
function runtime_check(bool $ok,string $message): void {
    $GLOBALS['checks']++;
    if(!$ok)throw new RuntimeException($message);
}
function runtime_key_rejected(mixed $key): bool {
    try { wk_validate_app_key($key); return false; }
    catch(RuntimeException $e) { return true; }
}

runtime_check(wk_ip_in_cidr('10.2.3.4','10.2.0.0/16'),'A valid IPv4 proxy range must match.');
runtime_check(!wk_ip_in_cidr('203.0.113.9','10.2.0.0/16'),'An outside IPv4 client must not match.');
runtime_check(wk_ip_in_cidr('10.2.3.4','10.2.3.4'),'An exact IPv4 proxy address must match.');
runtime_check(!wk_ip_in_cidr('10.2.3.5','10.2.3.4'),'An exact proxy address must not become a network.');
runtime_check(wk_ip_in_cidr('10.2.3.255','10.2.3.128/25'),'The final address inside a partial-byte prefix must match.');
runtime_check(!wk_ip_in_cidr('10.2.3.127','10.2.3.128/25'),'An adjacent partial-byte prefix must not match.');
runtime_check(wk_ip_in_cidr('2001:db8::2','2001:db8::/64'),'A valid IPv6 proxy range must match.');
runtime_check(!wk_ip_in_cidr('2001:db9::1','2001:db8::/64'),'An outside IPv6 client must not match.');
runtime_check(wk_ip_in_cidr('2001:db8::2','2001:db8::2/128'),'An exact IPv6 prefix must match.');
runtime_check(!wk_ip_in_cidr('203.0.113.9','::/0'),'IPv4 and IPv6 families must not mix.');
runtime_check(wk_ip_in_cidr('203.0.113.9','0.0.0.0/0'),'An explicit numeric zero prefix must retain its defined meaning.');
foreach(['','not-a-number','0evil','-1','+16','1e1','16.0',' 16','16 ','16/2','33','999','0000',"16\n"]as$prefix) {
    runtime_check(!wk_ip_in_cidr('203.0.113.9','10.0.0.0/'.$prefix),'Malformed IPv4 prefix must fail closed.');
}
foreach(['','not-a-number','129','128/0',"64\n"]as$prefix) {
    runtime_check(!wk_ip_in_cidr('2001:db8::2','2001:db8::/'.$prefix),'Malformed IPv6 prefix must fail closed.');
}
runtime_check(!wk_ip_in_cidr('not-an-ip','10.0.0.0/8'),'An invalid client address must not match.');
runtime_check(!wk_ip_in_cidr('10.0.0.1','not-a-network/8'),'An invalid network must not match.');
$CONFIG=['trusted_proxies'=>['10.0.0.0/not-a-number']];
$_SERVER=['REMOTE_ADDR'=>'203.0.113.9','HTTP_X_FORWARDED_PROTO'=>'https','HTTP_X_FORWARDED_FOR'=>'198.51.100.8'];
runtime_check(!wk_request_is_https(),'Malformed proxy configuration must not trust a spoofed HTTPS header.');
runtime_check(wk_client_ip()==='203.0.113.9','Malformed proxy configuration must not trust a spoofed client address.');

foreach([null,false,123,[], '', 'GENERATED_BY_INSTALLER', str_repeat('a',31), str_repeat(' ',32), str_repeat("\n",32)]as$key) {
    runtime_check(runtime_key_rejected($key),'Missing, malformed, or blank application key must be rejected.');
}
foreach([str_repeat('a',32),str_repeat('0123456789abcdef',4),' legacy-key-value-retained-exactly-2026 ']as$key) {
    wk_validate_app_key($key);
    runtime_check(!runtime_key_rejected($key),'A sufficiently long recovered key must retain its exact format.');
}
$shortSecret='sensitive-fixture-value';
try { wk_validate_app_key($shortSecret); throw new LogicException('Short fixture unexpectedly accepted.'); }
catch(RuntimeException $e) { runtime_check(!str_contains($e->getMessage(),$shortSecret),'Validation errors must not disclose the rejected key.'); }

$legacy='storage/release-proofs/2026/10/0123456789abcdef0123456789abcdef.pdf';
$stored='s3-private://release-proofs/2026/10/0123456789abcdef0123456789abcdef.pdf';
runtime_check(media_private_path($legacy,'local')===$legacy,'Local mode must preserve valid legacy proof paths.');
runtime_check(media_private_path($legacy,'s3')===$stored,'S3 mode must translate legacy paths without changing the object key.');
runtime_check(media_private_path($stored,'s3')===$stored,'Existing explicit S3 references must remain stable.');
runtime_check(media_private_path($stored,'local')===$stored,'An explicit S3 reference must not be rewritten into a local path.');
foreach(['jpg','jpeg','png','pdf']as$extension) {
    runtime_check(media_private_path('storage/release-proofs/2025/01/record_123-a.'.$extension,'s3')==='s3-private://release-proofs/2025/01/record_123-a.'.$extension,'Supported proof formats must retain their extension.');
}
foreach([
    '', '/'.$legacy, $legacy."\n", $legacy."\0", $legacy.'?download=1',
    'storage/release-proofs/2026/00/a.pdf','storage/release-proofs/2026/13/a.pdf',
    'storage/release-proofs/2026/1/a.pdf','storage/release-proofs/2026/10/../a.pdf',
    'storage/release-proofs/2026/10/%2e%2e%2fa.pdf','storage/release-proofs/2026/10/a.php',
    'storage/release-proofs/2026/10/a.pdf.php','storage/release-proofs/2026/10/a/b.pdf',
    'storage/release-proofs/2026/10/a\\b.pdf','storage/release-proofs/2026/10/a%2fb.pdf',
    'storage/other/2026/10/a.pdf','s3-private://other/2026/10/a.pdf',
    's3-private://release-proofs/2026/10/a.pdf#fragment',
    'https://example.invalid/release-proofs/2026/10/a.pdf',
    'release-proofs/2026/10/a.pdf',
]as$invalid) {
    runtime_check(media_private_path($invalid,'s3')===null&&media_private_path($invalid,'local')===null,'Unrecognized proof paths must be rejected before filesystem or S3 access.');
}

$oldWorker=getenv('WORKER_ENABLED');
putenv('WORKER_ENABLED');runtime_check(wk_environment_config([])['worker_enabled']===false,'A restored staging environment must not start external workers by default.');
putenv('WORKER_ENABLED=0');runtime_check(wk_environment_config(['worker_enabled'=>true])['worker_enabled']===false,'An explicit environment stop overrides legacy worker configuration.');
putenv('WORKER_ENABLED=1');runtime_check(wk_environment_config([])['worker_enabled']===true,'Production worker activation requires an explicit enabled setting.');
$oldWorker===false?putenv('WORKER_ENABLED'):putenv('WORKER_ENABLED='.$oldWorker);
echo "Runtime configuration checks passed: $checks\n";
