<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/opportunity_migration_redirect.php';
$cases = [
 ['/opportunities/country/senegal/',['type'=>'training'],'?country=SN&type=training'],
 ['/opportunities/country/senegal',['type'=>'unsupported'],'?country=SN'],
 ['/opportunities/country/senegal',['type'=>'training'],'?country=SN&type=training'],
 ['/opportunities/country/kenya',['type'=>'training'],'?country=KE&type=training'],
 ['/opportunities/country/senegal',[], '?country=SN'],
 ['/opportunities',['type'=>'training'],'?type=training'],
 ['/opportunities/country/unknown',['type'=>'training'],''],
 ['/write/opportunities',['type'=>'training','token'=>'secret'],''],
 ['/opportunities/country/senegal',['type'=>['training'],'next'=>'https://evil.test'],'?country=SN'],
 ['/opportunities/country/senegal',['type'=>"training\r\nX-Test: bad"],'?country=SN'],
 ['/opportunities/country/senegal',['type'=>'training','country'=>'KE','token'=>'secret'],'?country=SN&type=training'],
 ['/opportunities/country/../kenya',['type'=>'training'],''],
];
foreach($cases as [$path,$q,$expected]) {
 if(wk_opportunity_migration_target($path,$q)!=='https://www.ubunifuinstitute.com/opportunities'.$expected)throw new RuntimeException('Redirect regression: '.$path);
}
if(wk_opportunity_migration_target('/about',[])!==null)throw new RuntimeException('Unrelated route changed');
echo "PASS opportunity migration redirect\n";
