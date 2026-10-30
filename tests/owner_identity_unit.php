<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/owner_identity_aliases.php';
function oi(bool $ok,string $m):void{if(!$ok)throw new RuntimeException($m);}
oi(owner_identity_email(' ISAAC@AFRITRACELINK.COM ')==='isaac@goblisfoundation.org','Exact alias resolves to canonical Foundation');
oi(owner_identity_email('other@afritracelink.com')==='other@afritracelink.com','Other people are never mapped');
foreach(['isaac@afritracelink.com','Isaac Waithaka · isaac@afritracelink.com','Isaac <ISAAC@AFRITRACELINK.COM>']as$s)oi(owner_identity_label_is($s,'isaac@afritracelink.com'),'Exact returned alias email is recognised');
foreach(['notisaac@afritracelink.com','isaac@afritracelink.com.evil','isaac@goblisfoundation.org','isaac@afritracelink.com was once used']as$s)oi(!owner_identity_label_is($s,'isaac@afritracelink.com'),'Unrelated or historical prose is not mistaken for the identity');
try{owner_identity_require_primary('isaac@afritracelink.com');throw new LogicException('Independent grant accepted');}catch(RuntimeException $e){oi(str_contains($e->getMessage(),'isaac@goblisfoundation.org'),'Primary account guidance');}
owner_identity_require_primary('isaac@goblisfoundation.org');
echo "PASS: exact owner alias, canonical identity and standalone-grant rejection.\n";
