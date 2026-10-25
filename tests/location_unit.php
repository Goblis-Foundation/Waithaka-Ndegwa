<?php
declare(strict_types=1);
require_once __DIR__.'/../app/location.php';
function lc_check(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function lc_reject(callable $work):void{try{$work();}catch(InvalidArgumentException){return;}throw new RuntimeException('Invalid location accepted.');}
lc_check(count(location_kenya_counties())===47,'All 47 counties available.');
lc_check(location_fields([])['country']==='Kenya','Kenya is the default.');
$ke=location_fields(['country'=>' kenya ','county'=>'Nyeri','sub_county'=>'Kieni West','city'=>'Endarasha','region'=>'ignored','district'=>['ignored']]);
lc_check($ke['country']==='Kenya'&&$ke['county']==='Nyeri'&&$ke['region']===''&&$ke['district']==='','Kenyan fields are kept without foreign fields.');
lc_check(location_label($ke)==='Endarasha, Kieni West, Nyeri, Kenya','Kenyan location review label.');
$gb=location_fields(['country'=>'United Kingdom','county'=>['ignored'],'sub_county'=>['ignored'],'region'=>'England','district'=>'Westminster','city'=>'London']);
lc_check($gb['county']===''&&$gb['sub_county']===''&&location_label($gb)==='London, Westminster, England, United Kingdom','International structure excludes stale Kenyan fields.');
lc_check(location_phone(['phone'=>'0712 345 678'])['phone']==='+254712345678','Kenyan local phone normalized.');
lc_check(location_phone(['phone'=>'+44 20 7946 0958'])['phone']==='+442079460958','Full foreign number overrides default prefix.');
lc_check(location_phone(['phone_code'=>'+1','phone'=>'2025550123'])['phone']==='+12025550123','Editable country code retained.');
lc_check(location_phone([])['phone']==='','Phone stays optional.');
foreach([['country'=>[]],['country'=>"Kenya\0"],['county'=>'Made up county'],['sub_county'=>[]],['city'=>str_repeat('x',121)]]as$bad)lc_reject(fn()=>location_fields($bad));
foreach([['phone_code'=>[]],['phone_code'=>'+0'],['phone'=>'abc'],['phone'=>str_repeat('9',30)]]as$bad)lc_reject(fn()=>location_phone($bad));
echo "PASS: Kenya defaults, county validation, international fields, phones and malformed input.\n";
