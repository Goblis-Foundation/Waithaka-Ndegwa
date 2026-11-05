<?php
declare(strict_types=1);
$path=dirname(__DIR__).'/app/content/international-africa-opportunities-20261105.json';
$data=json_decode((string)file_get_contents($path),true,96,JSON_THROW_ON_ERROR);
$fail=[];$ok=function(bool $v,string $label)use(&$fail){if(!$v)$fail[]=$label;};
$ok(($data['release']??'')==='international-africa-opportunities-20261105','Release identity');
$entries=$data['entries']??[];$ok(count($entries)===6,'Six verified international entries');
$expectedModes=['rolling'=>1,'fixed'=>2,'unknown'=>3];$seen=['rolling'=>0,'fixed'=>0,'unknown'=>0];$slugs=[];
$allowed=['widu.africa','www.vliruos.be','www.irex.org','www.linkedin.com','mbzuai.ac.ae','international-partnerships.ec.europa.eu','www.worldbank.org'];
foreach($entries as$entry){
    $slug=$entry['slug']??'';$ok(is_string($slug)&&preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/D',$slug)===1,'Valid slug '.$slug);$ok(!isset($slugs[$slug]),'Unique slug '.$slug);$slugs[$slug]=true;
    foreach(['title','organisation','summary','support','deadline_label','countries','apply_url','deadline_mode']as$key)$ok(is_string($entry[$key]??null)&&trim($entry[$key])!=='',$slug.' has '.$key);
    $mode=$entry['deadline_mode']??'';if(isset($seen[$mode]))$seen[$mode]++;else$fail[]=$slug.' has valid deadline mode';
    if($mode==='fixed')$ok(is_string($entry['deadline_iso']??null)&&$entry['deadline_iso']!=='',$slug.' fixed timestamp');
    if($mode==='rolling')$ok(($entry['deadline_iso']??null)===null&&($entry['deadline_date']??'')==='',$slug.' rolling deadline has no invented date');
    if($mode==='unknown')$ok(($entry['deadline_iso']??null)===null&&preg_match('/^202[67]-\d{2}-\d{2}$/D',(string)($entry['deadline_date']??''))===1,$slug.' date-only deadline is explicit');
    foreach(['benefits','eligibility','conditions','steps']as$key)$ok(count($entry[$key]??[])>=2,$slug.' detailed '.$key);
    $ok(mb_strlen((string)($entry['support']??''))>100,$slug.' support remains substantive and is not constrained by the legacy instrument field');
    foreach(['context','fit','strategy','commitment']as$key)$ok(mb_strlen((string)($entry['guide'][$key]??''))>=180,$slug.' substantive '.$key);
    $ok(count($entry['guide']['prepare']??[])>=3,$slug.' preparation checklist');
    foreach(array_merge([['apply',$entry['apply_url']]],$entry['sources']??[])as$source){$host=parse_url($source[1]??'',PHP_URL_HOST);$ok(is_string($host)&&in_array($host,$allowed,true),$slug.' approved source host '.(string)$host);}
}
require_once dirname(__DIR__).'/app/bootstrap.php';
require_once dirname(__DIR__).'/app/international_africa_opportunities_release.php';
foreach($entries as$entry){$input=international_africa_opportunity_input($entry,$data['checked_on']);$ok(($input['instrument']??null)==='',$entry['slug'].' does not overflow the 100-character Publishing Desk instrument field');}
foreach($expectedModes as$mode=>$count)$ok($seen[$mode]===$count,'Expected '.$count.' '.$mode.' entries');
if($fail){fwrite(STDERR,"FAIL: ".implode('; ',$fail)."\n");exit(1);}echo "PASS: international opportunities are source-bounded, substantive and deadline-precise.\n";
