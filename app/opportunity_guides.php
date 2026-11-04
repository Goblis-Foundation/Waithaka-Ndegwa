<?php
declare(strict_types=1);
/** Editorial guidance is deliberately separate from the organiser's eligibility rules. */
function opportunity_guides_catalog(): array {
    $data=json_decode((string)file_get_contents(__DIR__.'/content/opportunity-guides-20261104.json'),true,32,JSON_THROW_ON_ERROR);
    if(($data['revision']??'')!=='opportunity-guides-v1-20261104'||count($data['entries']??[])!==16||!is_string($data['guidance_note']??null))throw new RuntimeException('Invalid opportunity guide revision.');
    foreach($data['entries']as$slug=>$guide){
        if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/D',$slug))throw new RuntimeException('Invalid guide slug.');
        foreach(['context','fit','strategy','commitment']as$key)if(!is_string($guide[$key]??null)||mb_strlen($guide[$key])<100)throw new RuntimeException('Incomplete guide: '.$slug);
        if(!is_array($guide['prepare']??null)||count($guide['prepare'])<3)throw new RuntimeException('A practical preparation checklist is required.');
        foreach($guide['prepare']as$text)if(!is_string($text)||trim($text)==='')throw new RuntimeException('Invalid preparation advice.');
        foreach($guide['sources']??[]as$source){if(count($source)!==2||!is_string($source[0]))throw new RuntimeException('Invalid guide evidence.');desk_url($source[1],'guide source',true);}
    }
    return $data;
}
function opportunity_guide_sources(array $entry,array $guide): array {
    $out=[];foreach(array_merge($entry['sources'],$guide['sources'])as$source)$out[$source[1]]=$source;return array_values($out);
}
function opportunity_guide_body(array $entry,array $guide,string $guidanceNote): string {
    $p=static fn(string $text):string=>'<p>'.e($text).'</p>';
    $list=static function(array $items,string $tag='ul'):string{return '<'.$tag.'>'.implode('',array_map(static fn($text)=>'<li>'.e($text).'</li>',$items)).'</'.$tag.'>';};
    $body='<p><strong>Deadline:</strong> '.e($entry['deadline_label']).'</p><p><strong>Funding or support:</strong> '.e($entry['support']).'</p>';
    if($entry['notice']!=='')$body.='<blockquote><p><strong>Check before applying:</strong> '.e($entry['notice']).'</p></blockquote>';
    $body.='<h2>About this opportunity</h2>'.$p($guide['context']);
    if($entry['slug']==='nimasa-blue-economy-accelerator-hackathon-2026')$body.='<p><strong>Current age rule:</strong> NIMASA\'s updated notice opens the hackathon to Nigerians aged 18 and above; it removes the previous upper-age limit, not the minimum age. <a href="https://nimasa.gov.ng/nimasa-beap-portal-opens-to-all-nigerians-as-nimasa-removes-age-barrier/" target="_blank" rel="noopener noreferrer">Read the official eligibility update.</a></p>';
    $body.='<h2>What it offers</h2>'.$list($entry['benefits']);
    $body.='<h2>Who can apply</h2>'.$p($entry['countries']).$list($entry['eligibility']);
    $body.='<h2>Is this a good fit for you?</h2>'.$p($guide['fit']);
    $body.='<h2>Important conditions and costs</h2>'.$list($entry['conditions']).$p($guide['commitment']);
    $body.='<h2>Prepare before you apply</h2><p><em>'.e($guidanceNote).'</em></p>'.$list($guide['prepare']);
    $body.='<h2>How to build a clearer application</h2>'.$p($guide['strategy']);
    $body.='<h2>How to apply</h2>'.$list($entry['steps'],'ol');
    $body.='<p><a href="'.e($entry['apply_url']).'" target="_blank" rel="noopener noreferrer">Check the official details and application route</a></p>';
    $body.='<h2>Sources and official information</h2><ul>';
    foreach(opportunity_guide_sources($entry,$guide)as[$label,$link])$body.='<li><a href="'.e($link).'" target="_blank" rel="noopener noreferrer">'.e($label).'</a></li>';
    return sanitize_html($body.'</ul>');
}
