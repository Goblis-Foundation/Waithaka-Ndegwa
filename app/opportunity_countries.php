<?php
declare(strict_types=1);

/**
 * Africa opportunity discovery: structured country metadata, eligibility matching,
 * deadline-first ordering and country-page SEO helpers.
 */
function opportunity_africa_countries(): array {
    return [
        'algeria'=>['name'=>'Algeria','demonym'=>'Algerian','region'=>'North Africa','ssa'=>false,'aliases'=>['Algeria']],
        'angola'=>['name'=>'Angola','demonym'=>'Angolan','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Angola']],
        'benin'=>['name'=>'Benin','demonym'=>'Beninese','region'=>'West Africa','ssa'=>true,'aliases'=>['Benin']],
        'botswana'=>['name'=>'Botswana','demonym'=>'Botswanan','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Botswana']],
        'burkina-faso'=>['name'=>'Burkina Faso','demonym'=>'Burkinabè','region'=>'West Africa','ssa'=>true,'aliases'=>['Burkina Faso']],
        'burundi'=>['name'=>'Burundi','demonym'=>'Burundian','region'=>'East Africa','ssa'=>true,'aliases'=>['Burundi']],
        'cabo-verde'=>['name'=>'Cabo Verde','demonym'=>'Cabo Verdean','region'=>'West Africa','ssa'=>true,'aliases'=>['Cabo Verde','Cape Verde']],
        'cameroon'=>['name'=>'Cameroon','demonym'=>'Cameroonian','region'=>'Central Africa','ssa'=>true,'aliases'=>['Cameroon']],
        'central-african-republic'=>['name'=>'Central African Republic','demonym'=>'Central African','region'=>'Central Africa','ssa'=>true,'aliases'=>['Central African Republic','CAR']],
        'chad'=>['name'=>'Chad','demonym'=>'Chadian','region'=>'Central Africa','ssa'=>true,'aliases'=>['Chad']],
        'comoros'=>['name'=>'Comoros','demonym'=>'Comorian','region'=>'East Africa','ssa'=>true,'aliases'=>['Comoros']],
        'congo'=>['name'=>'Republic of the Congo','demonym'=>'Congolese','region'=>'Central Africa','ssa'=>true,'aliases'=>['Republic of the Congo','Congo-Brazzaville']],
        'cote-divoire'=>['name'=>"Côte d’Ivoire",'demonym'=>'Ivorian','region'=>'West Africa','ssa'=>true,'aliases'=>["Côte d’Ivoire","Cote d'Ivoire",'Ivory Coast']],
        'democratic-republic-of-the-congo'=>['name'=>'Democratic Republic of the Congo','demonym'=>'Congolese','region'=>'Central Africa','ssa'=>true,'aliases'=>['Democratic Republic of the Congo','DRC','Congo-Kinshasa']],
        'djibouti'=>['name'=>'Djibouti','demonym'=>'Djiboutian','region'=>'East Africa','ssa'=>true,'aliases'=>['Djibouti']],
        'egypt'=>['name'=>'Egypt','demonym'=>'Egyptian','region'=>'North Africa','ssa'=>false,'aliases'=>['Egypt']],
        'equatorial-guinea'=>['name'=>'Equatorial Guinea','demonym'=>'Equatoguinean','region'=>'Central Africa','ssa'=>true,'aliases'=>['Equatorial Guinea']],
        'eritrea'=>['name'=>'Eritrea','demonym'=>'Eritrean','region'=>'East Africa','ssa'=>true,'aliases'=>['Eritrea']],
        'eswatini'=>['name'=>'Eswatini','demonym'=>'Swazi','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Eswatini','Swaziland']],
        'ethiopia'=>['name'=>'Ethiopia','demonym'=>'Ethiopian','region'=>'East Africa','ssa'=>true,'aliases'=>['Ethiopia']],
        'gabon'=>['name'=>'Gabon','demonym'=>'Gabonese','region'=>'Central Africa','ssa'=>true,'aliases'=>['Gabon']],
        'gambia'=>['name'=>'The Gambia','demonym'=>'Gambian','region'=>'West Africa','ssa'=>true,'aliases'=>['The Gambia','Gambia']],
        'ghana'=>['name'=>'Ghana','demonym'=>'Ghanaian','region'=>'West Africa','ssa'=>true,'aliases'=>['Ghana']],
        'guinea'=>['name'=>'Guinea','demonym'=>'Guinean','region'=>'West Africa','ssa'=>true,'aliases'=>['Guinea']],
        'guinea-bissau'=>['name'=>'Guinea-Bissau','demonym'=>'Bissau-Guinean','region'=>'West Africa','ssa'=>true,'aliases'=>['Guinea-Bissau']],
        'kenya'=>['name'=>'Kenya','demonym'=>'Kenyan','region'=>'East Africa','ssa'=>true,'aliases'=>['Kenya']],
        'lesotho'=>['name'=>'Lesotho','demonym'=>'Basotho','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Lesotho']],
        'liberia'=>['name'=>'Liberia','demonym'=>'Liberian','region'=>'West Africa','ssa'=>true,'aliases'=>['Liberia']],
        'libya'=>['name'=>'Libya','demonym'=>'Libyan','region'=>'North Africa','ssa'=>false,'aliases'=>['Libya']],
        'madagascar'=>['name'=>'Madagascar','demonym'=>'Malagasy','region'=>'East Africa','ssa'=>true,'aliases'=>['Madagascar']],
        'malawi'=>['name'=>'Malawi','demonym'=>'Malawian','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Malawi']],
        'mali'=>['name'=>'Mali','demonym'=>'Malian','region'=>'West Africa','ssa'=>true,'aliases'=>['Mali']],
        'mauritania'=>['name'=>'Mauritania','demonym'=>'Mauritanian','region'=>'West Africa','ssa'=>true,'aliases'=>['Mauritania']],
        'mauritius'=>['name'=>'Mauritius','demonym'=>'Mauritian','region'=>'East Africa','ssa'=>true,'aliases'=>['Mauritius']],
        'morocco'=>['name'=>'Morocco','demonym'=>'Moroccan','region'=>'North Africa','ssa'=>false,'aliases'=>['Morocco']],
        'mozambique'=>['name'=>'Mozambique','demonym'=>'Mozambican','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Mozambique']],
        'namibia'=>['name'=>'Namibia','demonym'=>'Namibian','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Namibia']],
        'niger'=>['name'=>'Niger','demonym'=>'Nigerien','region'=>'West Africa','ssa'=>true,'aliases'=>['Niger']],
        'nigeria'=>['name'=>'Nigeria','demonym'=>'Nigerian','region'=>'West Africa','ssa'=>true,'aliases'=>['Nigeria']],
        'rwanda'=>['name'=>'Rwanda','demonym'=>'Rwandan','region'=>'East Africa','ssa'=>true,'aliases'=>['Rwanda']],
        'sao-tome-and-principe'=>['name'=>'São Tomé and Príncipe','demonym'=>'São Toméan','region'=>'Central Africa','ssa'=>true,'aliases'=>['São Tomé and Príncipe','Sao Tome and Principe']],
        'senegal'=>['name'=>'Senegal','demonym'=>'Senegalese','region'=>'West Africa','ssa'=>true,'aliases'=>['Senegal']],
        'seychelles'=>['name'=>'Seychelles','demonym'=>'Seychellois','region'=>'East Africa','ssa'=>true,'aliases'=>['Seychelles']],
        'sierra-leone'=>['name'=>'Sierra Leone','demonym'=>'Sierra Leonean','region'=>'West Africa','ssa'=>true,'aliases'=>['Sierra Leone']],
        'somalia'=>['name'=>'Somalia','demonym'=>'Somali','region'=>'East Africa','ssa'=>true,'aliases'=>['Somalia']],
        'south-africa'=>['name'=>'South Africa','demonym'=>'South African','region'=>'Southern Africa','ssa'=>true,'aliases'=>['South Africa']],
        'south-sudan'=>['name'=>'South Sudan','demonym'=>'South Sudanese','region'=>'East Africa','ssa'=>true,'aliases'=>['South Sudan']],
        'sudan'=>['name'=>'Sudan','demonym'=>'Sudanese','region'=>'North Africa','ssa'=>false,'aliases'=>['Sudan']],
        'tanzania'=>['name'=>'Tanzania','demonym'=>'Tanzanian','region'=>'East Africa','ssa'=>true,'aliases'=>['Tanzania','United Republic of Tanzania']],
        'togo'=>['name'=>'Togo','demonym'=>'Togolese','region'=>'West Africa','ssa'=>true,'aliases'=>['Togo']],
        'tunisia'=>['name'=>'Tunisia','demonym'=>'Tunisian','region'=>'North Africa','ssa'=>false,'aliases'=>['Tunisia']],
        'uganda'=>['name'=>'Uganda','demonym'=>'Ugandan','region'=>'East Africa','ssa'=>true,'aliases'=>['Uganda']],
        'zambia'=>['name'=>'Zambia','demonym'=>'Zambian','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Zambia']],
        'zimbabwe'=>['name'=>'Zimbabwe','demonym'=>'Zimbabwean','region'=>'Southern Africa','ssa'=>true,'aliases'=>['Zimbabwe']],
    ];
}
function opportunity_country(string $slug): ?array {
    $all=opportunity_africa_countries();
    return isset($all[$slug])?['slug'=>$slug]+$all[$slug]:null;
}
function opportunity_eac_slugs(): array {
    return ['burundi','democratic-republic-of-the-congo','kenya','rwanda','somalia','south-sudan','tanzania','uganda'];
}
function opportunity_country_excluded(string $text,array $country): bool {
    $hay=mb_strtolower($text);
    foreach($country['aliases'] as $alias){
        $a=preg_quote(mb_strtolower($alias),'~');
        if(preg_match('~(?:except|excluding|exclude|not open to|ineligible(?:\s+in|\s+for)?)[^.;]{0,90}\b'.$a.'\b~u',$hay))return true;
    }
    return false;
}
function opportunity_country_direct_match(array $record,array $country): bool {
    $f=$record['public']['fields']??$record['fields']??[];$text=trim(implode(' ',array_filter([(string)($f['countries']??''),(string)($f['eligibility']??''),(string)($f['conditions']??''),(string)($f['summary']??''),(string)($f['description']??'')]))) ;
    if($text===''||opportunity_country_excluded($text,$country))return false;
    foreach($country['aliases']as$alias){$a=preg_quote($alias,'~');if(preg_match('~(?<![\\p{L}\\p{N}])'.$a.'(?![\\p{L}\\p{N}])~iu',$text))return true;}return false;
}
function opportunity_country_match(array $record,array $country): bool {
    $f=$record['public']['fields']??$record['fields']??[];
    $text=trim(implode(' ',array_filter([
        (string)($f['countries']??''),(string)($f['eligibility']??''),(string)($f['conditions']??''),
        (string)($f['summary']??''),(string)($f['description']??'')
    ])));
    if($text==='')return false;
    if(opportunity_country_excluded($text,$country))return false;
    if(opportunity_country_direct_match($record,$country))return true;
    $hay=mb_strtolower($text);
    if(preg_match('~\b(all\s+)?african\s+(countries|nationals|citizens|states|startups|entrepreneurs|applicants|businesses|enterprises)\b|\bacross\s+africa\b|\bpan[- ]african\b|\bafrica-wide\b~u',$hay))return true;
    if($country['ssa']&&preg_match('~\bsub[- ]saharan\s+africa\b|\bsub[- ]saharan\s+african\b~u',$hay))return true;
    if(in_array($country['slug'],opportunity_eac_slugs(),true)&&preg_match('~\beast\s+african\s+community\b|\beac\s+(countries|member states|region)\b~u',$hay))return true;
    return false;
}
function opportunity_deadline_key(array $record,?int $now=null): array {
    $now??=time();$f=$record['public']['fields']??$record['fields']??[];
    $closed=desk_closed(ol_public_record($record),$now);
    if($closed)return [3,PHP_INT_MAX,0-(int)($record['published_at']??0)];
    $ts=(int)($f['deadline']??0);
    if($ts>0)return [0,$ts,0-(int)($record['published_at']??0)];
    $date=(string)($f['deadline_date']??'');
    if($date!==''&&ol_date_valid($date)){
        $d=new DateTimeImmutable($date.' 23:59:59',new DateTimeZone('Africa/Nairobi'));
        return [0,$d->getTimestamp(),0-(int)($record['published_at']??0)];
    }
    if(($f['deadline_mode']??'')==='rolling')return [1,PHP_INT_MAX-10,0-(int)($record['published_at']??0)];
    return [2,PHP_INT_MAX-5,0-(int)($record['published_at']??0)];
}
function opportunity_sort_deadline(array &$records,?int $now=null): void {
    usort($records,static function($a,$b)use($now){return opportunity_deadline_key($a,$now)<=>opportunity_deadline_key($b,$now);});
}
function opportunity_country_records(array $country,bool $includeArchived=false): array {
    $records=desk_public_list('opportunity',$includeArchived);
    $records=array_values(array_filter($records,static fn($r)=>opportunity_country_match($r,$country)));
    opportunity_sort_deadline($records);
    return $records;
}
function opportunity_country_quality(array $country,array $records): bool {
    $current=array_values(array_filter($records,static fn($r)=>ol_directory_match(ol_public_record($r),'')));
    if(count($current)<2)return false;
    foreach($current as$r)if(opportunity_country_direct_match($r,$country))return true;
    return false;
}

function opportunity_country_coverage_rows(?int $now=null): array {
    $now??=time();$rows=[];
    foreach(opportunity_africa_countries()as$slug=>$meta){
        $country=['slug'=>$slug]+$meta;
        $all=opportunity_country_records($country,true);
        $current=array_values(array_filter($all,static fn($r)=>ol_directory_match(ol_public_record($r),'')));
        $direct=array_values(array_filter($current,static fn($r)=>opportunity_country_direct_match($r,$country)));
        $status=$direct?'direct_current':($current?'regional_current':($all?'historical_only':'no_verified_current'));
        $next=null;
        foreach($current as$r){
            $f=$r['public']['fields']??[];
            $ts=(int)($f['deadline']??0);
            if($ts<=0&&($f['deadline_date']??'')!==''&&ol_date_valid((string)$f['deadline_date']))$ts=(new DateTimeImmutable($f['deadline_date'].' 23:59:59',new DateTimeZone('Africa/Nairobi')))->getTimestamp();
            if($ts>$now&&($next===null||$ts<$next))$next=$ts;
        }
        $rows[]=[
            'slug'=>$slug,'name'=>$country['name'],'region'=>$country['region'],
            'status'=>$status,'current'=>count($current),'direct'=>count($direct),
            'historical'=>max(0,count($all)-count($current)),'next_deadline'=>$next
        ];
    }
    usort($rows,static function($a,$b){
        $rank=['no_verified_current'=>0,'historical_only'=>1,'regional_current'=>2,'direct_current'=>3];
        $r=($rank[$a['status']]??9)<=>($rank[$b['status']]??9);
        return $r!==0?$r:strcmp($a['name'],$b['name']);
    });
    return $rows;
}
function opportunity_country_coverage_summary(array $rows): array {
    $out=['countries'=>count($rows),'direct_current'=>0,'regional_current'=>0,'historical_only'=>0,'no_verified_current'=>0];
    foreach($rows as$row)if(isset($out[$row['status']]))$out[$row['status']]++;
    return $out;
}
function opportunity_country_seo(array $country,int $count,bool $indexable=false): array {
    $name=$country['name'];$demonym=$country['demonym'];
    $title='Funding, Grants & Opportunities for '.$demonym.' Applicants';
    $description='Explore current grants, fellowships, accelerators, training and business opportunities open to applicants from '.$name.'. Compare eligibility, support, costs and upcoming deadlines from verified official sources.';
    return ['title'=>$title.' · Waithaka Ndegwa','description'=>$description,'canonical'=>url('/opportunities/country/'.$country['slug']),'robots'=>$count>0&&$indexable?'index,follow':'noindex,follow'];
}
function opportunity_country_schema(array $country,array $records): array {
    $items=[];foreach(array_slice($records,0,50)as$i=>$r)$items[]=['@type'=>'ListItem','position'=>$i+1,'url'=>url(desk_path($r)),'name'=>$r['public']['fields']['title']??$r['slug']];
    return [
        ['@context'=>'https://schema.org','@type'=>'CollectionPage','name'=>'Opportunities for '.$country['demonym'].' applicants','url'=>url('/opportunities/country/'.$country['slug']),'about'=>['@type'=>'Country','name'=>$country['name']],'mainEntity'=>['@type'=>'ItemList','numberOfItems'=>count($records),'itemListElement'=>$items]],
        ['@context'=>'https://schema.org','@type'=>'BreadcrumbList','itemListElement'=>[
            ['@type'=>'ListItem','position'=>1,'name'=>'Home','item'=>url('/')],
            ['@type'=>'ListItem','position'=>2,'name'=>'Opportunities','item'=>url('/opportunities')],
            ['@type'=>'ListItem','position'=>3,'name'=>$country['name'],'item'=>url('/opportunities/country/'.$country['slug'])],
        ]],
    ];
}
function opportunity_country_indexable(): array {
    $out=[];foreach(opportunity_africa_countries()as$slug=>$country){$c=['slug'=>$slug]+$country;$records=opportunity_country_records($c);if(opportunity_country_quality($c,$records))$out[$slug]=$c;}return $out;
}
