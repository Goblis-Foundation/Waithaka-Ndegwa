<?php
declare(strict_types=1);

/** Fixed editorial illustrations. This module does not fetch images or change records. */
function eb_catalog(): array {
    return [
        'octopus-african-energy-entrepreneurship-awards-2026'=>'energy',
        'kac-afrika-growth-stage-sme-debt-financing'=>'agriculture',
        'brahmam-global-innovation-grand-challenge-2026'=>'innovation',
        'afdb-hei-enterprise-support-capacity-building-phase-ii-2026'=>'education',
        'africa-business-accelerator-2027-cohort-1'=>'enterprise',
        'icoyaca-fifth-anniversary-microgrants-2026'=>'community',
        'africa-impact-academy-cohort-2-2026'=>'learning',
        'unido-sustainable-industrial-ai-global-call-2026'=>'industry',
        'bridge-in-agriculture-pitch-competition-2026'=>'agriculture',
        'sustech4africa-uganda-boost-camp-2027'=>'regenerative',
        'hbs-africa-business-club-new-venture-competition-2027'=>'enterprise',
        'nimasa-blue-economy-accelerator-hackathon-2026'=>'ocean',
        'sais-livestock-investment-readiness-programme-2027'=>'livestock',
        'mena-regenerative-agriculture-venture-programme-2026-2027'=>'regenerative',
        'easteco-coywa-entrepreneurship-training-2026'=>'learning',
        'mo-ibrahim-leadership-fellowship-uneca-2027'=>'leadership',
        'drk-foundation-social-enterprise-funding'=>'community',
        'rufford-small-grants-nature-conservation'=>'conservation',
        'undp-timbuktoo-greentech-launchpad'=>'energy',
        'undp-timbuktoo-edtech-launchpad'=>'learning',
        'undp-timbuktoo-minetech-launchpad'=>'industry',
        'undp-timbuktoo-creativetech-launchpad'=>'innovation',
        'undp-timbuktoo-agritech-launchpad'=>'agriculture',
        'undp-timbuktoo-healthtech-launchpad'=>'innovation',
        'gef-small-grants-northern-cape-conservation-2026'=>'conservation',
        'widu-original-grant-africa-europe'=>'enterprise',
        'vliruos-icp-connect-scholarships-2027-2028'=>'learning',
        'irex-community-solutions-program-2027'=>'leadership',
        'mbzuai-graduate-scholarships-2027'=>'innovation',
        'eu-arcx-africa-regional-centres-excellence-2026'=>'conservation',
        'jj-wbgsp-2027-developing-country-scholarships'=>'learning',
        'commonwealth-shared-scholarships-2027-2028'=>'learning',
        'erasmus-mundus-joint-masters-2027'=>'education',
        'mastercard-foundation-scholars-cmu-africa-open'=>'innovation',
        'mastercard-foundation-scholars-alu-open'=>'learning',
        'aerc-industrial-policy-growth-africa-research-2026'=>'innovation',
        'twas-research-continuity-grants-africa-2026'=>'innovation',
        'impact-driven-inspiration'=>'perspective',
        'electric-vehicles-biofuels-africa'=>'mobility',
        'the-gold-that-is-nairobis-sewage'=>'water',
        'opportunities-directory'=>'community',
        'topic-energy'=>'energy','topic-agriculture'=>'agriculture','topic-innovation'=>'innovation',
        'topic-learning'=>'learning','topic-enterprise'=>'enterprise','topic-conservation'=>'conservation',
        'topic-infrastructure'=>'water','topic-travel'=>'perspective','topic-perspective'=>'perspective',
    ];
}
function eb_descriptions(): array {
    return ['energy'=>'solar panels and wind turbines','agriculture'=>'cultivated fields and a farm',
        'innovation'=>'a light bulb linked to a technology network','education'=>'a university and an open book',
        'enterprise'=>'business growth and city buildings','community'=>'people collaborating around a growing enterprise',
        'learning'=>'an open book and people learning together','industry'=>'industrial machinery and digital connections',
        'regenerative'=>'plants, roots, soil and water conservation','ocean'=>'a vessel, waves and marine life',
        'livestock'=>'dairy farming and aquaculture','leadership'=>'an institutional building and a globe',
        'conservation'=>'trees, a wetland and mountains','perspective'=>'mountains and a winding path',
        'mobility'=>'a vehicle, charging point and plant-based energy','water'=>'water-treatment tanks and resource recovery'];
}
function eb_key(string $slug,string $topic=''): string {
    if(isset(eb_catalog()[$slug]))return $slug;
    $topic=strtolower($topic);
    foreach(['agri'=>'agriculture','farm'=>'agriculture','energy'=>'energy','climate'=>'conservation','conservation'=>'conservation','environment'=>'conservation','educat'=>'learning','training'=>'learning','fellow'=>'learning','innovat'=>'innovation','technolog'=>'innovation','infrastructure'=>'infrastructure','travel'=>'travel','entrepreneur'=>'enterprise','business'=>'enterprise','enterprise'=>'enterprise']as$word=>$key)if(str_contains($topic,$word))return 'topic-'.$key;
    return 'topic-perspective';
}
/** CloudFront varies static assets on v; include both artwork and subject in v. */
function eb_version(string $key): string {
    if(!isset(eb_catalog()[$key]))throw new InvalidArgumentException('Unknown editorial illustration.');
    static $revision;
    $revision??=hash_file('sha256',__FILE__);
    return substr(hash('sha256',$revision.':'.$key),0,24);
}
/** The origin must derive its content from the same parameter as the edge cache. */
function eb_key_from_version(mixed $version): ?string {
    if(!is_string($version)||!preg_match('/^[a-f0-9]{24}$/D',$version))return null;
    foreach(array_keys(eb_catalog())as$key)if(hash_equals(eb_version($key),$version))return $key;
    return null;
}
function eb_asset(string $key): array {
    if(!isset(eb_catalog()[$key]))throw new InvalidArgumentException('Unknown editorial illustration.');
    $version=eb_version($key);
    return ['url'=>url('/assets/editorial-banner.php?key='.rawurlencode($key).'&v='.$version),
        'alt'=>'Editorial illustration of '.eb_descriptions()[eb_catalog()[$key]].'.',
        'caption'=>'Editorial illustration.','width'=>1200,'height'=>630,'illustration'=>true];
}
function eb_is_logo(?string $source): bool {
    if(trim((string)$source)==='')return true;
    $path=strtolower((string)parse_url((string)$source,PHP_URL_PATH));
    return preg_match('~logo|monogram|lockup|favicon|social-default|(?:^|/)icon(?:[-.])~',$path)===1;
}
function eb_post_image(array $post): array {
    $source=(string)($post['featured_image']??'');
    if(!eb_is_logo($source))return ['url'=>featured_image_url($source),'alt'=>(string)(($post['featured_image_alt']??'')?:($post['title']??'Article image')),'caption'=>(string)($post['featured_image_caption']??''),'illustration'=>false];
    return eb_asset(eb_key((string)($post['slug']??''),(string)($post['category_slug']??$post['category_name']??'')));
}
function eb_record_image(array $record,?array $post=null,bool $preview=false): ?array {
    $image=$preview?($record['creatives']['landscape']??null):($record['public']['creatives']['landscape']??null);
    $f=$preview?($record['fields']??[]):($record['public']['fields']??$record['fields']??[]);
    // Commercial creatives retain their own approval and disclosure workflow.
    if(($record['kind']??'')!=='opportunity'||!empty($f['sponsored']))return $image;
    $slug=(string)($record['slug']??'');
    if(isset(eb_catalog()[$slug]))return eb_asset($slug);
    if($image&&!eb_is_logo((string)($image['url']??'')))return $image+['illustration'=>false,'caption'=>''];
    $post??=$record['public']['article']??[];
    if(!eb_is_logo((string)($post['featured_image']??'')))return eb_post_image($post);
    return eb_asset(eb_key($slug,($f['sectors']??'').' '.($f['type']??'')));
}
function eb_image_attrs(array $image,string $sizes='100vw'): string {
    if(empty($image['illustration']))return responsive_image_attrs((string)$image['url'],$sizes);
    return 'src="'.e((string)$image['url']).'" width="1200" height="630"';
}

/** Drawing coordinates and colours below are fixed; request text is never drawn. */
function eb_scene(string $key): array {
    $catalog=eb_catalog();if(!isset($catalog[$key]))throw new InvalidArgumentException('Unknown illustration.');
    $theme=$catalog[$key];$v=hexdec(substr(hash('sha256',$key),0,2))%3;
    $palettes=[['#F1F5EE','#DCE9DC','#164F49','#3D8A70','#EBC478'],['#F2F4F5','#DCE9EE','#173D58','#3A8392','#E4B96C'],['#F8F3E9','#ECE5CC','#375D49','#75A46B','#DFB166']];
    [$bg,$pale,$ink,$green,$gold]=$palettes[$v];$white='#FFFFFF';$water='#6DAFB4';$mid='#BCD7C6';$s=[];
    $rect=static function($x,$y,$w,$h,$c)use(&$s){$s[]=['rect',$x,$y,$w,$h,$c];};
    $ellipse=static function($x,$y,$w,$h,$c)use(&$s){$s[]=['ellipse',$x,$y,$w,$h,$c];};
    $line=static function($x,$y,$a,$b,$c,$w=6)use(&$s){$s[]=['line',$x,$y,$a,$b,$c,$w];};
    $poly=static function(array $p,$c)use(&$s){$s[]=['polygon',$p,$c];};
    $plant=static function($x,$y,$z)use($line,$ellipse,$green,$ink){$line($x,$y,$x,$y-$z,$ink,7);$ellipse($x-$z*.25,$y-$z*.55,$z*.7,$z*.26,$green);$ellipse($x+$z*.25,$y-$z*.83,$z*.7,$z*.27,$green);};
    $person=static function($x,$y)use($ellipse,$rect,$ink){$ellipse($x,$y,48,48,$ink);$rect($x-30,$y+34,60,77,$ink);};
    $book=static function($x,$y)use($poly,$line,$white,$ink,$green){$poly([$x-180,$y-100,$x-35,$y-73,$x,$y-45,$x,$y+133,$x-180,$y+94],$white);$poly([$x,$y-45,$x+35,$y-73,$x+180,$y-100,$x+180,$y+94,$x,$y+133],$white);$line($x,$y-43,$x,$y+133,$ink);for($i=0;$i<4;$i++){$line($x-145,$y-47+$i*31,$x-38,$y-26+$i*31,$green);$line($x+38,$y-26+$i*31,$x+145,$y-47+$i*31,$green);}};
    $building=static function($x,$y,$w)use($rect,$poly,$ink,$white,$green){$poly([$x-20,$y,$x+$w/2,$y-65,$x+$w+20,$y],$ink);$rect($x,$y,$w,170,$white);for($i=0;$i<4;$i++)$rect($x+25+$i*($w-60)/4,$y+16,21,136,$green);$rect($x-20,$y+168,$w+40,18,$ink);};
    $fish=static function($x,$y,$z)use($poly,$ellipse,$ink,$white){$ellipse($x,$y,$z,$z*.46,$ink);$poly([$x+$z*.35,$y,$x+$z*.82,$y-$z*.36,$x+$z*.82,$y+$z*.36],$ink);$ellipse($x-$z*.28,$y-3,8,8,$white);};
    $rect(0,0,1200,630,$bg);$ellipse(1000,88,540,540,$pale);$ellipse(135,470,430,430,$pale);$ellipse(930-$v*25,140,112,112,$gold);$poly([0,495,260,451,510,475,760,440,1200,485,1200,630,0,630],$mid);$line(80,555,1120,555,$white,3);
    if(in_array($theme,['energy','mobility'],true)){
        $poly([110,390,555,340,640,494,190,541],$ink);for($i=1;$i<5;$i++)$line(110+$i*89,390-$i*10,190+$i*90,541-$i*10,$water,3);for($i=1;$i<3;$i++)$line(110+$i*27,390+$i*50,555+$i*28,340+$i*51,$water,3);$line(265,529,265,571,$ink,10);$line(538,502,538,552,$ink,10);
        if($theme==='energy'){foreach([[800,260,120],[1015,330,90]]as[$x,$y,$r]){$line($x,$y,$x,$y+220,$ink,10);for($i=0;$i<3;$i++){$a=($i*120-90)*M_PI/180;$line($x,$y,$x+cos($a)*$r,$y+sin($a)*$r,$white,13);}$ellipse($x,$y,25,25,$ink);}}
        else{$rect(718,324,310,115,$green);$poly([767,260,933,260,995,324,725,324],$green);$poly([784,277,915,277,953,322,751,322],$white);foreach([778,970]as$x){$ellipse($x,444,73,73,$ink);$ellipse($x,444,31,31,$white);}$rect(1050,330,52,145,$ink);$rect(1058,341,36,55,$water);$line(1050,418,1010,418,$ink,8);$plant(668,296,85);}
    }elseif(in_array($theme,['agriculture','regenerative','livestock'],true)){
        $poly([0,420,1200,420,1200,630,0,630],$green);for($i=0;$i<8;$i++)$poly([80+$i*150,420,120+$i*150,420,45+$i*175,630,-15+$i*175,630],$gold);foreach([[240,380,120],[430,355,160],[620,380,115]]as[$x,$y,$z])$plant($x,$y,$z);
        if($theme==='regenerative'){$rect(745,300,290,162,$pale);$line(890,300,890,455,$ink,8);foreach([[-100,60],[100,85],[-70,125],[85,145]]as[$dx,$dy])$line(890,310,890+$dx,310+$dy,$ink,5);$plant(890,300,120);$ellipse(1080,386,42,70,$water);}
        elseif($theme==='livestock'){$rect(775,277,195,100,$white);$rect(927,254,70,76,$white);foreach([790,830,907,942]as$x)$rect($x,365,17,80,$ink);$ellipse(823,303,54,49,$ink);$ellipse(886,339,51,48,$ink);$rect(977,277,40,35,$ink);$ellipse(962,275,9,9,$ink);$line(930,253,917,233,$ink);$line(981,253,991,234,$ink);$ellipse(1030,500,220,58,$water);$fish(1015,500,86);}
        else{$rect(780,310,265,145,$white);$poly([755,310,912,205,1070,310],$ink);$rect(878,363,73,92,$green);$rect(800,338,46,47,$water);$rect(979,338,46,47,$water);}
    }elseif(in_array($theme,['education','learning'],true)){
        $book(470,355);if($theme==='education')$building(770,285,250);else{foreach([[830,235],[1005,340],[845,440]]as[$x,$y])$person($x,$y);$line(830,270,1005,340,$green,5);$line(1005,390,845,440,$green,5);}$plant(175,425,145);
    }elseif(in_array($theme,['innovation','industry'],true)){
        foreach([[200,225,370,225],[160,425,345,425],[820,230,1010,230],[820,410,1030,410]]as[$x,$y,$a,$b]){$line($x,$y,$a,$b,$green,7);$ellipse($x,$y,30,30,$gold);$line($a,$b,$a,$b+($y<300?70:-60),$green,7);}
        if($theme==='innovation'){$ellipse(590,270,270,270,$gold);$poly([490,328,690,328,655,418,525,418],$gold);$rect(527,410,128,27,$ink);$rect(540,450,103,24,$ink);$line(562,407,551,275,$white,8);$line(622,407,634,275,$white,8);$line(552,275,634,275,$white,8);}
        else{foreach([[555,300,139],[728,407,94]]as[$x,$y,$r]){$p=[];for($i=0;$i<48;$i++){$a=$i*M_PI/24;$rr=$i%4<2?$r:$r*.82;$p[]=$x+cos($a)*$rr;$p[]=$y+sin($a)*$rr;}$poly($p,$ink);$ellipse($x,$y,$r*.8,$r*.8,$white);}$rect(392,465,414,43,$green);}
    }elseif(in_array($theme,['enterprise','community','leadership'],true)){
        if($theme==='enterprise'){foreach([[240,360,95,170],[370,298,95,232],[500,220,95,310]]as[$x,$y,$w,$h]){$rect($x,$y,$w,$h,$ink);for($j=0;$j<3;$j++)$rect($x+22,$y+30+$j*45,50,18,$water);}$line(674,450,950,230,$green,17);$poly([950,230,877,234,943,308],$green);$person(975,425);}
        elseif($theme==='community'){$ellipse(595,340,275,275,$white);$plant(595,405,170);foreach([[235,232],[305,423],[950,250],[914,438]]as[$x,$y]){$person($x,$y);$line($x+($x<600?45:-45),$y+52,$x<600?438:752,335,$green);}}
        else{$building(220,320,330);$ellipse(815,300,270,270,$white);$ellipse(815,300,190,270,$water);$ellipse(815,300,170,250,$white);$line(680,300,950,300,$green,5);$line(815,168,815,432,$green,5);$line(700,235,930,235,$green,4);$line(700,365,930,365,$green,4);$person(1038,408);}
    }elseif($theme==='ocean'){
        foreach([[350,$water],[425,$green],[500,$ink]]as[$y,$c])$poly([0,$y+35,130,$y,300,$y+40,470,$y,650,$y+37,820,$y,1000,$y+40,1200,$y+5,1200,630,0,630],$c);$poly([345,325,863,325,790,420,430,420],$white);$rect(505,213,215,112,$ink);$rect(530,238,47,43,$water);$rect(602,238,47,43,$water);$line(620,213,620,135,$ink,8);$poly([620,140,720,180,620,180],$green);$fish(220,500,125);$fish(950,545,100);
    }elseif($theme==='water'){
        $rect(185,240,240,220,$white);$rect(185,218,240,32,$ink);for($i=0;$i<3;$i++)$rect(220+$i*62,275,36,42,$water);foreach([[595,395,260],[938,445,260]]as[$x,$y,$w]){$rect($x-$w/2,$y,$w,94,$pale);$ellipse($x,$y+94,$w,72,$pale);$ellipse($x,$y,$w,106,$ink);$ellipse($x,$y-3,$w-28,75,$water);$line($x-$w/2+15,$y,$x+$w/2-15,$y,$white,7);$ellipse($x,$y,25,25,$white);}$line(425,350,465,350,$green,13);$line(465,350,465,410,$green,13);$line(725,450,808,450,$green,13);$plant(570,245,110);
    }else{
        $poly([0,480,325,147,535,395,760,210,1060,470,1200,380,1200,630,0,630],$ink);$poly([245,230,325,147,395,230,349,212,319,238,290,213],$white);$poly([626,345,760,210,877,331,795,290,752,325,710,294],$pale);$poly([0,530,330,425,600,480,885,430,1200,485,1200,630,0,630],$green);$poly([620,470,654,470,527,570,675,630,520,630,445,568],$gold);foreach(($theme==='conservation'?[[187,445,145],[1030,435,172],[898,450,105]]:[[965,440,135]])as[$x,$y,$z]){$rect($x-7,$y,14,$z*.58,$ink);$poly([$x,$y-$z,$x-$z*.46,$y+$z*.13,$x+$z*.46,$y+$z*.13],$green);}if($theme==='conservation')$ellipse(250,570,320,65,$water);
    }
    return ['theme'=>$theme,'description'=>eb_descriptions()[$theme],'shapes'=>$s];
}
function eb_png(string $key): string {
    if(!extension_loaded('gd'))throw new RuntimeException('Image renderer unavailable.');
    $scene=eb_scene($key);$im=imagecreatetruecolor(2400,1260);$colors=[];
    $color=static function($hex)use($im,&$colors){return $colors[$hex]??=imagecolorallocate($im,hexdec(substr($hex,1,2)),hexdec(substr($hex,3,2)),hexdec(substr($hex,5,2)));};$n=static fn($v)=>(int)round($v*2);
    foreach($scene['shapes']as$s){if($s[0]==='rect')imagefilledrectangle($im,$n($s[1]),$n($s[2]),$n($s[1]+$s[3]),$n($s[2]+$s[4]),$color($s[5]));elseif($s[0]==='ellipse')imagefilledellipse($im,$n($s[1]),$n($s[2]),$n($s[3]),$n($s[4]),$color($s[5]));elseif($s[0]==='line'){imagesetthickness($im,$n($s[6]));imageline($im,$n($s[1]),$n($s[2]),$n($s[3]),$n($s[4]),$color($s[5]));}else imagefilledpolygon($im,array_map($n,$s[1]),$color($s[2]));}
    $out=imagecreatetruecolor(1200,630);imagecopyresampled($out,$im,0,0,0,0,1200,630,2400,1260);ob_start();try{if(!imagepng($out,null,6))throw new RuntimeException('Image output failed.');return (string)ob_get_contents();}finally{ob_end_clean();imagedestroy($im);imagedestroy($out);}
}
