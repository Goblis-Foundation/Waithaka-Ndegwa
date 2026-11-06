<?php
declare(strict_types=1);
function url(string $path): string {return 'https://example.test'.$path;}
function desk_path(array $r): string {return '/opportunities/'.$r['slug'];}
function ol_public_record(array $r): array {return $r;}
function desk_closed(array $r,?int $now=null): bool {
    $now??=time();$f=$r['public']['fields']??[];
    if(($f['deadline']??0)>0)return (int)$f['deadline']<=$now;
    if(!empty($f['deadline_date']))return $f['deadline_date']<date('Y-m-d',$now);
    return ($r['state']??'')==='closed';
}
function ol_date_valid(string $date): bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);return $d!==false&&$d->format('Y-m-d')===$date;
}
require dirname(__DIR__).'/app/opportunity_countries.php';
$fail=[];
$ok=function(bool $condition,string $label)use(&$fail){if(!$condition)$fail[]=$label;};
$record=function(string $countries,string $eligibility='',array $extra=[]):array{
    return ['slug'=>'fixture','state'=>'published','published_at'=>100,'public'=>['fields'=>array_merge(['countries'=>$countries,'eligibility'=>$eligibility,'conditions'=>'','summary'=>'','description'=>'','deadline'=>null,'deadline_date'=>'','deadline_mode'=>'unknown','title'=>'Fixture'],$extra)]];
};
$kenya=opportunity_country('kenya');$egypt=opportunity_country('egypt');$southSudan=opportunity_country('south-sudan');
$ok($kenya!==null&&$kenya['ssa']===true,'Kenya registry metadata');
$ok(opportunity_country_match($record('Kenya'),$kenya),'Direct Kenya eligibility');
$ok(opportunity_country_match($record('African countries','Open to African startups'),$kenya),'Africa-wide eligibility includes Kenya');
$ok(opportunity_country_match($record('Sub-Saharan Africa'),$kenya),'Sub-Saharan Africa includes Kenya');
$ok(!opportunity_country_match($record('Sub-Saharan Africa'),$egypt),'Sub-Saharan Africa does not automatically include Egypt');
$ok(!opportunity_country_match($record('Africa','Open across Africa except South Sudan'),$southSudan),'Explicit country exclusion wins');
$records=[
    $record('Kenya','',['deadline'=>strtotime('2026-12-15T12:00:00+03:00'),'deadline_mode'=>'fixed','title'=>'Later']),
    $record('Kenya','',['deadline'=>null,'deadline_mode'=>'rolling','title'=>'Rolling']),
    $record('Kenya','',['deadline'=>strtotime('2026-11-20T12:00:00+03:00'),'deadline_mode'=>'fixed','title'=>'Sooner']),
];
opportunity_sort_deadline($records,strtotime('2026-11-05T12:00:00+03:00'));
$ok(array_column(array_column($records,'public'),'fields')[0]['title']==='Sooner','Nearest deadline sorts first');
$ok(array_column(array_column($records,'public'),'fields')[1]['title']==='Later','Later dated opportunity follows');
$ok(array_column(array_column($records,'public'),'fields')[2]['title']==='Rolling','Rolling opportunity follows dated opportunities');
$ok(opportunity_country_seo($kenya,3,true)['robots']==='index,follow','Substantive country page with current listings is indexable');
$ok(opportunity_country_seo($kenya,3,false)['robots']==='noindex,follow','Broad-only country page is not indexed');
$ok(opportunity_country_seo($kenya,0,false)['robots']==='noindex,follow','Empty country page is not indexed');
$niger=opportunity_country('niger');$ok(!opportunity_country_direct_match($record('Nigeria'),$niger),'Niger does not falsely match Nigeria');

$coverageFixtureRows=[
 ['status'=>'direct_current'],['status'=>'regional_current'],['status'=>'historical_only'],['status'=>'no_verified_current']
];
$summary=opportunity_country_coverage_summary($coverageFixtureRows);
$ok(($summary['countries']??0)===4,'Coverage summary counts countries');
$ok(($summary['direct_current']??0)===1&&($summary['regional_current']??0)===1&&($summary['historical_only']??0)===1&&($summary['no_verified_current']??0)===1,'Coverage summary separates direct, regional, historical and research-gap states');
$allCountries=opportunity_africa_countries();
$ok(count($allCountries)>=54,'African country registry retains continental coverage');
$view=(string)file_get_contents(dirname(__DIR__).'/views/write/opportunity_reviews.php');
$ok(str_contains($view,'African country research coverage')&&str_contains($view,'No verified current listing'),'Private review workspace exposes honest country research gaps');
$ok(str_contains($view,"/opportunities/country/"),'Coverage ledger links to public country pages without exposing a new public data endpoint');

if($fail){fwrite(STDERR,"FAIL: ".implode('; ',$fail)."\n");exit(1);}echo "PASS: African country eligibility, exclusions, SEO and deadline ordering.\n";
