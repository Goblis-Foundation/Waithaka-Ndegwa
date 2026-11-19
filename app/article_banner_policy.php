<?php
declare(strict_types=1);

require_once __DIR__.'/editorial_banners.php';

/**
 * Article banner policy
 *
 * - The visual must express the article's specific subject, geography, period and argument.
 * - A real photograph must be relevant, publishable and described with meaningful alt text.
 * - A generated visual is an editorial illustration, never documentary evidence.
 * - The current approved Waithaka Ndegwa identity may appear as a restrained brand mark.
 * - Obsolete marks and logo-only article images are never used as public article art.
 * - Article headers, archive cards and social previews resolve through the same image decision.
 */

function abp_approved_logo_paths(): array {
    return [
        '/assets/images/brand-lockup.webp',
        '/assets/images/brand-lockup.png',
        '/assets/images/wn-approved-monogram.svg',
    ];
}

function abp_source_path(?string $source): string {
    $path=(string)parse_url(trim((string)$source),PHP_URL_PATH);
    if($path==='')return '';
    return '/'.ltrim(strtolower(rawurldecode($path)),'/');
}

function abp_is_approved_logo(?string $source): bool {
    return in_array(abp_source_path($source),abp_approved_logo_paths(),true);
}

function abp_is_legacy_logo(?string $source): bool {
    if(abp_is_approved_logo($source))return false;
    $path=abp_source_path($source);
    if($path==='')return false;
    return eb_is_logo($path)||preg_match('~(?:^|/)(?:old[-_]?logo|legacy[-_]?lockup|waithaka[-_]?wn[-_]?logo[-_]?proposal)(?:$|[?._-])~',$path)===1;
}

/** An approved logo may brand a composed banner, but it cannot be the whole article image. */
function abp_is_logo_only(?string $source): bool {
    if(trim((string)$source)==='')return true;
    return abp_is_approved_logo($source)||abp_is_legacy_logo($source)||eb_is_logo((string)$source);
}

function abp_briefs(): array {
    static $briefs;
    if($briefs!==null)return $briefs;
    $briefs=[
        'impact-driven-inspiration'=>[
            'scene'=>'impact-path','base_key'=>'impact-driven-inspiration',
            'subject'=>'a personal journey from the foothills of the Aberdare and Mount Kenya landscape into enterprise and social impact',
            'motifs'=>['central Kenyan mountain landscape','a path linking community, learning and enterprise','people moving from ideas to practical impact'],
            'research_basis'=>['the published personal narrative','the article’s named Nyeri and Endarasha context'],
            'alt'=>'Research-led editorial illustration of a path from the central Kenyan highlands towards community, enterprise and practical impact.',
            'caption'=>'Research-led editorial illustration based on the article’s Nyeri origins, enterprise journey and impact theme. Approved Waithaka Ndegwa branding.'
        ],
        'from-impact-evidence-to-economic-demand'=>[
            'scene'=>'impact-market','base_key'=>'topic-enterprise',
            'subject'=>'African social enterprises connecting credible impact evidence to customers, contracts, capital and economic demand',
            'motifs'=>['evidence and measurement','an enterprise serving a real market','capital and customer connections'],
            'research_basis'=>['the article’s cited impact-economy signals','the published analysis of evidence, demand and enterprise finance'],
            'alt'=>'Research-led editorial illustration connecting impact evidence, an African enterprise, customers and investment.',
            'caption'=>'Research-led editorial illustration of the article’s central argument: evidence creates value when it connects to economic demand. Approved Waithaka Ndegwa branding.'
        ],
        'electric-vehicles-biofuels-africa'=>[
            'scene'=>'ev-biofuel','base_key'=>'electric-vehicles-biofuels-africa',
            'subject'=>'Africa’s transport transition combining electric mobility, charging infrastructure and sustainable fuels',
            'motifs'=>['electric vehicle and charging point','biofuel feedstock and processing','African transport infrastructure'],
            'research_basis'=>['the article’s linked energy and transport sources','the published comparison of batteries, pipelines and sustainable fuels'],
            'alt'=>'Editorial visual of an electric vehicle, charging infrastructure and plant-based fuel production in Africa.',
            'caption'=>'Editorial visual based on the article’s researched comparison of electric mobility and sustainable fuels. Approved Waithaka Ndegwa branding.'
        ],
        'tanzania-railways-vision-2050'=>[
            'scene'=>'rail-port','base_key'=>'tanzania-railways-vision-2050',
            'subject'=>'Tanzania’s connected railway, port and regional trade ambitions under Vision 2050',
            'motifs'=>['standard-gauge railway and locomotive','port and freight corridor','lake and regional connections'],
            'research_basis'=>['the article’s linked railway financing and corridor sources','Tanzania Vision 2050 and the published transport analysis'],
            'alt'=>'Research-led editorial illustration of a Tanzanian train linking inland trade corridors, a port and regional markets.',
            'caption'=>'Research-led editorial illustration based on the article’s railway, port, TAZARA and Vision 2050 analysis. Approved Waithaka Ndegwa branding.'
        ],
        'the-gold-that-is-nairobis-sewage'=>[
            'scene'=>'water-recovery','base_key'=>'the-gold-that-is-nairobis-sewage',
            'subject'=>'wastewater treatment, resource recovery and circular urban infrastructure for Nairobi',
            'motifs'=>['treatment tanks','water reuse and recovered resources','Nairobi urban infrastructure'],
            'research_basis'=>['the article’s cited water and wastewater sources','the approved article image manifest'],
            'alt'=>'Editorial composite of wastewater treatment, resource recovery and Nairobi urban infrastructure.',
            'caption'=>'Editorial composite based on the article’s wastewater and circular-economy research; not documentary evidence of an existing Nairobi facility. Approved Waithaka Ndegwa branding.'
        ],
        'christmas-truce-humanity-leadership'=>[
            'scene'=>'truce','base_key'=>'christmas-truce-humanity-leadership',
            'subject'=>'the 1914 Christmas Truce as a human and leadership lesson without romanticising the wider war',
            'motifs'=>['two trench lines','soldiers meeting in no-man’s-land','winter, a football and a brief exchange'],
            'research_basis'=>['the article’s First World War sources','the published distinction between local truces and a centrally organised truce'],
            'alt'=>'Historical editorial illustration of soldiers cautiously meeting between First World War trenches during the Christmas Truce.',
            'caption'=>'Historical editorial illustration based on the article’s researched account of local Christmas truces in 1914. Approved Waithaka Ndegwa branding.'
        ],
        'tong-wenhong-long-term-value'=>[
            'scene'=>'long-term-value','base_key'=>'tong-wenhong-long-term-value',
            'subject'=>'Tong Wenhong’s long-term journey at Alibaba and the value of patience, ownership and compounding opportunity',
            'motifs'=>['a modest starting desk','parcel and commerce infrastructure','steps towards long-term enterprise value'],
            'research_basis'=>['the article’s linked Alibaba and biographical sources','the published correction of simplified wealth folklore'],
            'alt'=>'Editorial illustration of a modest starting desk leading through logistics and commerce to long-term enterprise value.',
            'caption'=>'Research-led editorial illustration based on the article’s account of Tong Wenhong, Alibaba and long-term value creation. Approved Waithaka Ndegwa branding.'
        ],
        'kenya-visa-free-eta-2026'=>[
            'scene'=>'eta','base_key'=>'kenya-visa-free-eta-2026',
            'subject'=>'Kenya’s visa-free promise, electronic travel authorisation and the practical traveller experience',
            'motifs'=>['passport and digital travel authorisation','aircraft route to Kenya','airport entry and mobile verification'],
            'research_basis'=>['the article’s linked Kenyan immigration and travel sources','the published distinction between visa-free messaging and eTA administration'],
            'alt'=>'Editorial illustration of a passport, digital travel authorisation and an aircraft arriving in Kenya.',
            'caption'=>'Research-led editorial illustration based on Kenya’s visa-free and electronic travel authorisation system. Approved Waithaka Ndegwa branding.'
        ],
        'dhl-friction-logistics-lesson'=>[
            'scene'=>'logistics','base_key'=>'dhl-friction-logistics-lesson',
            'subject'=>'the hidden operational friction in African and global logistics, from a parcel to the wider supply chain',
            'motifs'=>['parcel and barcode','truck, aircraft and warehouse','connected but imperfect logistics route'],
            'research_basis'=>['the article’s linked DHL and logistics sources','the published personal service experience and wider investment context'],
            'alt'=>'Editorial illustration of a parcel moving through a truck, aircraft, warehouse and connected logistics route.',
            'caption'=>'Research-led editorial illustration based on the article’s personal logistics experience and wider supply-chain analysis. Approved Waithaka Ndegwa branding.'
        ],
        'sugar-beet-kenya-complement-not-cure'=>[
            'scene'=>'sugar-crops','base_key'=>'sugar-beet-kenya-complement-not-cure',
            'subject'=>'sugar beet as a possible complement to Kenya’s sugar-cane industry rather than a simple cure',
            'motifs'=>['sugar beet roots and cane stalks','a processing mill','a balanced comparison of crops and policy'],
            'research_basis'=>['the article’s agronomic and COMESA sources','the published Kenyan policy and presidential remarks analysis'],
            'alt'=>'Editorial illustration comparing sugar beet roots and sugar cane beside a Kenyan processing mill.',
            'caption'=>'Research-led editorial illustration based on the article’s agronomic, COMESA and Kenyan sugar-policy analysis. Approved Waithaka Ndegwa branding.'
        ],
        'tomato-processing-cooperatives-kenya'=>[
            'scene'=>'tomato-processing','base_key'=>'tomato-processing-cooperatives-kenya',
            'subject'=>'Kenyan tomato farmers moving from seasonal gluts towards processing, coordination and stronger cooperatives',
            'motifs'=>['fresh tomatoes and harvest crates','a processing line','farmers coordinating supply through a cooperative'],
            'research_basis'=>['the article’s linked Kenyan agriculture and processing sources','the published analysis of perishability, timing and market coordination'],
            'alt'=>'Editorial illustration of Kenyan tomato farmers, harvest crates and a cooperative processing line.',
            'caption'=>'Research-led editorial illustration based on the article’s tomato-market, processing and cooperative analysis. Approved Waithaka Ndegwa branding.'
        ],
        'africas-fintechs-are-becoming-banks'=>[
            'scene'=>'fintech-banking','base_key'=>'topic-innovation',
            'subject'=>'African payment companies and digital banks moving deeper into deposits, credit and regulated banking',
            'motifs'=>['mobile financial application','regulated bank and deposits','merchant payments and credit connections'],
            'research_basis'=>['official and reported licensing information for Flutterwave, Paystack, Moniepoint and Tyme','the article’s cited analysis of deposits, lending and telco competition'],
            'alt'=>'Research-led editorial illustration of a mobile financial platform connected to a regulated bank, merchant payments, deposits and credit in Africa.',
            'caption'=>'Research-led editorial illustration based on the article’s verified shift from payments into licensed banking, deposits and credit. Approved Waithaka Ndegwa branding.'
        ],
    ];
    return $briefs;
}

function abp_scene_descriptions(): array {
    return [
        'impact-path'=>'a path joining community, learning and enterprise',
        'impact-market'=>'impact evidence connecting an enterprise to customers and capital',
        'ev-biofuel'=>'electric mobility, charging and sustainable fuel production',
        'rail-port'=>'a railway corridor joining freight, ports and regional markets',
        'water-recovery'=>'wastewater treatment and resource recovery',
        'truce'=>'two trench lines and a cautious meeting in no-man’s-land',
        'long-term-value'=>'a modest beginning developing into long-term enterprise value',
        'eta'=>'a passport, digital travel authorisation and aircraft route',
        'logistics'=>'a parcel moving through road, air and warehouse logistics',
        'sugar-crops'=>'sugar beet, sugar cane and processing infrastructure',
        'tomato-processing'=>'tomato production, coordination and processing',
        'fintech-banking'=>'mobile finance connected to a regulated bank, deposits, merchants and credit',
        'agriculture'=>'agriculture, processing and market connections',
        'enterprise'=>'enterprise, customers and growth',
        'innovation'=>'technology and practical innovation',
        'perspective'=>'a researched African perspective',
    ];
}

function abp_brief_for_post(array $post): array {
    $slug=(string)($post['slug']??'');
    if(isset(abp_briefs()[$slug]))return abp_briefs()[$slug];
    $text=mb_strtolower(strip_tags(implode(' ',[(string)($post['title']??''),(string)($post['standfirst']??''),(string)($post['content_html']??''),(string)($post['category_name']??''),(string)($post['category_slug']??'')])),'UTF-8');
    $rules=[
        'fintech-banking'=>['bank','fintech','payment','deposit','credit','mobile money'],
        'rail-port'=>['rail','train','railway','locomotive','port','corridor'],
        'water-recovery'=>['sewage','wastewater','water treatment','sanitation'],
        'eta'=>['visa','passport','travel authorisation','eta','airport'],
        'logistics'=>['logistics','parcel','freight','warehouse','supply chain'],
        'sugar-crops'=>['sugar beet','sugar cane','sugarcane'],
        'tomato-processing'=>['tomato','processing','cooperative'],
        'ev-biofuel'=>['electric vehicle','biofuel','charging','battery'],
        'agriculture'=>['farm','agriculture','crop','farmer'],
        'enterprise'=>['enterprise','business','entrepreneur','startup','investment'],
        'innovation'=>['technology','innovation','digital','artificial intelligence'],
    ];
    $scene='perspective';
    foreach($rules as$candidate=>$words){foreach($words as$word){if(str_contains($text,$word)){$scene=$candidate;break 2;}}}
    $description=abp_scene_descriptions()[$scene]??abp_scene_descriptions()['perspective'];
    return [
        'scene'=>$scene,
        'base_key'=>abp_base_key_for_scene($scene),
        'subject'=>(string)(($post['title']??'')?:'the article’s central subject'),
        'motifs'=>[$description],
        'research_basis'=>['the published article title, standfirst, body and cited sources'],
        'alt'=>'Research-led editorial illustration for '.trim((string)(($post['title']??'')?:'this article')).', showing '.$description.'.',
        'caption'=>'Research-led editorial illustration derived from the article’s published subject and sources. Approved Waithaka Ndegwa branding.',
        'derived'=>true,
    ];
}

function abp_base_key_for_scene(string $scene): string {
    return match($scene){
        'impact-path'=>'impact-driven-inspiration',
        'ev-biofuel'=>'electric-vehicles-biofuels-africa',
        'rail-port'=>'tanzania-railways-vision-2050',
        'water-recovery'=>'the-gold-that-is-nairobis-sewage',
        'truce'=>'christmas-truce-humanity-leadership',
        'long-term-value'=>'tong-wenhong-long-term-value',
        'eta'=>'kenya-visa-free-eta-2026',
        'logistics'=>'dhl-friction-logistics-lesson',
        'sugar-crops'=>'sugar-beet-kenya-complement-not-cure',
        'tomato-processing'=>'tomato-processing-cooperatives-kenya',
        'impact-market','enterprise'=>'topic-enterprise',
        'agriculture'=>'topic-agriculture',
        'innovation','fintech-banking'=>'topic-innovation',
        default=>'topic-perspective',
    };
}

function abp_token(string $slug,string $scene): string {
    if(!preg_match('/^[a-z0-9-]{1,180}$/D',$slug)||!isset(abp_scene_descriptions()[$scene]))throw new InvalidArgumentException('Invalid article banner identity.');
    $revision=hash('sha256',hash_file('sha256',__FILE__).':'.hash_file('sha256',__DIR__.'/editorial_banners.php'));
    $hash=substr(hash('sha256',$revision.':'.$slug.':'.$scene),0,24);
    return $slug.'.'.$scene.'.'.$hash;
}

function abp_identity_from_token(mixed $token): ?array {
    if(!is_string($token)||!preg_match('/^([a-z0-9-]{1,180})\.([a-z-]{2,40})\.([a-f0-9]{24})$/D',$token,$m))return null;
    try{$expected=abp_token($m[1],$m[2]);}catch(Throwable){return null;}
    return hash_equals($expected,$token)?['slug'=>$m[1],'scene'=>$m[2]]:null;
}

function abp_asset(array $post): array {
    $slug=(string)($post['slug']??'article');
    if(!preg_match('/^[a-z0-9-]{1,180}$/D',$slug))$slug='article';
    $brief=abp_brief_for_post($post);$scene=(string)$brief['scene'];$token=abp_token($slug,$scene);
    return [
        'url'=>url('/assets/article-banner.php?v='.rawurlencode($token)),
        'alt'=>(string)$brief['alt'],'caption'=>(string)$brief['caption'],
        'width'=>1200,'height'=>630,'illustration'=>true,'article_banner'=>true,
        'scene'=>$scene,'brief'=>$brief,'approved_brand'=>true,
    ];
}

function abp_post_image(array $post): array {
    $source=(string)($post['featured_image']??'');
    if($source!==''&&!abp_is_logo_only($source)){
        $caption=(string)($post['featured_image_caption']??'');
        $illustration=preg_match('~(?:ai[- ]generated|editorial (?:illustration|composite))~i',$caption)===1;
        return [
            'url'=>featured_image_url($source),
            'alt'=>(string)(($post['featured_image_alt']??'')?:($post['title']??'Article image')),
            'caption'=>$caption,'illustration'=>$illustration,'article_banner'=>true,
            'approved_brand'=>false,'policy'=>'approved-custom-image',
        ];
    }
    return abp_asset($post)+['policy'=>abp_is_legacy_logo($source)?'legacy-logo-replaced':($source!==''?'logo-only-replaced':'generated-research-banner')];
}

function abp_image_attrs(array $image,string $sizes='100vw'): string {
    if(!empty($image['article_banner'])&&!empty($image['illustration']))return 'src="'.e((string)$image['url']).'" width="1200" height="630"';
    return responsive_image_attrs((string)$image['url'],$sizes);
}

function abp_draw_scene(GdImage $im,string $scene): void {
    $blue=imagecolorallocate($im,7,59,106);$green=imagecolorallocate($im,47,111,62);$gold=imagecolorallocate($im,226,185,108);
    $white=imagecolorallocate($im,255,255,255);$ink=imagecolorallocate($im,24,55,72);$water=imagecolorallocate($im,92,164,177);
    $panel=imagecolorallocatealpha($im,255,255,255,24);imagefilledrectangle($im,70,72,1130,520,$panel);
    $line=static function($x1,$y1,$x2,$y2,$c,$w=8)use($im){imagesetthickness($im,$w);imageline($im,$x1,$y1,$x2,$y2,$c);};
    $person=static function($x,$y,$c)use($im){imagefilledellipse($im,$x,$y,42,42,$c);imagefilledrectangle($im,$x-20,$y+24,$x+20,$y+96,$c);};
    if($scene==='fintech-banking'){
        imagefilledpolygon($im,[180,280,390,165,600,280],$blue);imagefilledrectangle($im,205,280,575,450,$white);
        for($i=0;$i<4;$i++)imagefilledrectangle($im,235+$i*80,305,270+$i*80,425,$green);
        imagefilledrectangle($im,760,145,955,445,$ink);imagefilledrectangle($im,780,175,935,395,$white);imagefilledellipse($im,857,420,20,20,$gold);
        foreach([[705,255],[655,365],[1010,265],[1015,390]]as$p){imagefilledellipse($im,$p[0],$p[1],44,44,$gold);$line(857,285,$p[0],$p[1],$green,5);}
        for($i=0;$i<3;$i++)imagefilledellipse($im,630+$i*44,455-$i*16,62,22,$gold);
    }elseif($scene==='rail-port'){
        $line(420,545,560,285,$ink,11);$line(780,545,640,285,$ink,11);for($y=320;$y<555;$y+=38){$t=($y-285)/270;$half=(int)(40+$t*150);$line(600-$half,$y,600+$half,$y,$gold,7);}
        imagefilledrectangle($im,500,220,700,330,$blue);imagefilledpolygon($im,[500,220,560,165,660,165,700,220],$green);imagefilledellipse($im,540,340,62,62,$ink);imagefilledellipse($im,660,340,62,62,$ink);
        $line(205,190,205,430,$ink,13);$line(205,190,390,190,$ink,10);$line(390,190,350,350,$green,8);imagefilledpolygon($im,[145,420,380,420,430,480,110,480],$water);
    }elseif($scene==='truce'){
        imagefilledrectangle($im,80,405,430,510,$ink);imagefilledrectangle($im,770,405,1120,510,$ink);for($i=0;$i<38;$i++)imagefilledellipse($im,100+($i*83)%1000,95+($i*47)%280,6,6,$white);
        $person(470,290,$blue);$person(730,290,$green);$line(490,345,590,375,$blue,12);$line(710,345,610,375,$green,12);imagefilledellipse($im,600,455,42,42,$gold);
    }elseif($scene==='long-term-value'){
        imagefilledrectangle($im,135,385,355,475,$blue);imagefilledrectangle($im,170,325,320,385,$white);
        for($i=0;$i<5;$i++){imagefilledrectangle($im,420+$i*105,430-$i*58,505+$i*105,500-$i*58,$i%2?$green:$gold);}
        imagefilledrectangle($im,885,175,1080,440,$ink);for($y=210;$y<400;$y+=55)for($x=915;$x<1045;$x+=55)imagefilledrectangle($im,$x,$y,$x+28,$y+25,$water);
        $line(355,410,930,190,$green,10);
    }elseif($scene==='eta'){
        imagefilledrectangle($im,150,165,430,465,$blue);imagefilledrectangle($im,180,205,400,425,$white);imagefilledellipse($im,290,285,115,115,$water);$line(235,285,345,285,$blue,5);$line(290,230,290,340,$blue,5);
        imagefilledrectangle($im,690,150,900,455,$ink);imagefilledrectangle($im,715,180,875,405,$white);for($r=0;$r<5;$r++)for($c=0;$c<5;$c++)if(($r+$c)%3!==1)imagefilledrectangle($im,735+$c*24,215+$r*24,751+$c*24,231+$r*24,$blue);
        $line(430,240,700,210,$green,6);imagefilledpolygon($im,[545,190,615,225,555,242,570,300,535,285,520,245,465,260],$gold);
    }elseif($scene==='logistics'){
        imagefilledrectangle($im,140,335,455,455,$blue);imagefilledrectangle($im,455,370,570,455,$green);imagefilledpolygon($im,[455,370,500,320,555,370],$green);imagefilledellipse($im,235,465,66,66,$ink);imagefilledellipse($im,500,465,66,66,$ink);
        imagefilledrectangle($im,650,285,1015,445,$white);for($x=680;$x<990;$x+=75)$line($x,300,$x,430,$water,5);
        imagefilledpolygon($im,[660,165,790,210,1060,175,805,255,720,245],$gold);$line(570,300,690,230,$green,7);
    }elseif($scene==='sugar-crops'){
        imagefilledellipse($im,300,330,175,210,$gold);imagefilledpolygon($im,[260,415,300,535,340,415],$gold);$line(270,230,235,135,$green,12);$line(320,230,350,125,$green,12);
        for($x=520;$x<=660;$x+=45){$line($x,470,$x,150,$green,15);for($y=220;$y<430;$y+=75)$line($x,$y,$x+35,$y-30,$green,8);}
        imagefilledrectangle($im,790,260,1050,460,$white);imagefilledpolygon($im,[790,260,880,205,960,260],$ink);imagefilledrectangle($im,945,165,985,260,$blue);
    }elseif($scene==='tomato-processing'){
        for($i=0;$i<8;$i++)imagefilledellipse($im,190+($i%4)*70,315+intdiv($i,4)*75,58,58,$i%2?$gold:$green);
        imagefilledrectangle($im,145,430,470,500,$blue);$line(500,410,830,410,$ink,14);for($x=520;$x<820;$x+=60)imagefilledellipse($im,$x,410,34,34,$gold);
        imagefilledrectangle($im,835,235,1080,450,$white);imagefilledpolygon($im,[835,235,915,185,990,235],$ink);imagefilledrectangle($im,985,145,1025,235,$green);
        $person(610,245,$blue);$person(700,245,$green);
    }elseif($scene==='impact-market'){
        $line(180,440,180,190,$ink,8);$line(180,440,520,440,$ink,8);$line(215,390,305,330,$green,10);$line(305,330,395,350,$green,10);$line(395,350,490,235,$green,10);foreach([[215,390],[305,330],[395,350],[490,235]]as$p)imagefilledellipse($im,$p[0],$p[1],28,28,$gold);
        imagefilledrectangle($im,650,280,1030,455,$white);imagefilledpolygon($im,[620,280,840,175,1060,280],$blue);for($x=700;$x<990;$x+=80)imagefilledrectangle($im,$x,315,$x+42,420,$green);
        $line(520,340,650,340,$gold,12);
    }elseif($scene==='impact-path'){
        imagefilledpolygon($im,[70,420,300,140,470,350,650,205,900,420],$blue);imagefilledpolygon($im,[245,205,300,140,355,205,315,190,290,215],$white);
        imagefilledpolygon($im,[520,520,580,520,500,430,630,335,600,300,450,420],$gold);$person(690,315,$green);$person(805,370,$blue);$line(720,360,785,390,$green,8);
    }elseif($scene==='ev-biofuel'){
        imagefilledrectangle($im,185,325,570,435,$green);imagefilledpolygon($im,[245,325,330,245,475,245,540,325],$green);imagefilledellipse($im,270,445,70,70,$ink);imagefilledellipse($im,495,445,70,70,$ink);
        imagefilledrectangle($im,650,225,705,455,$blue);imagefilledrectangle($im,660,245,695,315,$water);$line(705,350,780,350,$ink,8);imagefilledellipse($im,880,350,150,210,$gold);$line(845,250,800,175,$green,12);$line(910,250,955,170,$green,12);
    }
}

function abp_stamp_approved_logo(GdImage $im): void {
    $file=dirname(__DIR__).'/assets/images/brand-lockup.webp';
    $expected='f674df8d4a9f30df3ce663b7f001af8fd1b18984d251ff5924c9ff01bac77c0b';
    if(!is_file($file)||!hash_equals($expected,(string)hash_file('sha256',$file)))throw new RuntimeException('Approved logo asset is missing or changed.');
    $logo=imagecreatefromwebp($file);if(!$logo)throw new RuntimeException('Approved logo cannot be rendered.');
    $white=imagecolorallocatealpha($im,255,255,255,12);imagefilledrectangle($im,900,536,1175,605,$white);
    imagecopyresampled($im,$logo,925,548,0,0,225,50,imagesx($logo),imagesy($logo));imagedestroy($logo);
}

function abp_png(string $slug,string $scene): string {
    $brief=abp_briefs()[$slug]??null;$baseKey=(string)($brief['base_key']??abp_base_key_for_scene($scene));
    if(!isset(eb_catalog()[$baseKey]))$baseKey='topic-perspective';
    $im=imagecreatefromstring(eb_png($baseKey));if(!$im||imagesx($im)!==1200||imagesy($im)!==630)throw new RuntimeException('Base editorial artwork is invalid.');
    imagealphablending($im,true);abp_draw_scene($im,$scene);abp_stamp_approved_logo($im);
    ob_start();try{if(!imagepng($im,null,6))throw new RuntimeException('Article banner output failed.');return (string)ob_get_contents();}finally{ob_end_clean();imagedestroy($im);}
}
