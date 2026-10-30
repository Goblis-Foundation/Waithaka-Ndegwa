<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||count($argv)!==2)exit(2);
$out=realpath($argv[1]);if(!$out||!str_starts_with($out,sys_get_temp_dir().'/')&&!str_starts_with($out,(string)getenv('RUNNER_TEMP').'/'))throw new RuntimeException('Use a disposable fixture directory.');
$CONFIG=['app_env'=>'testing','base_url'=>'http://127.0.0.1:8125','timezone'=>'Africa/Nairobi','app_key'=>'local-only-fixture-'.str_repeat('x',40)];$APP_INSTALLED=false;$_SESSION=[];$_GET=[];
foreach(['functions','location','reader','contact_experience','publishing_desk_policy','commercial_control','experience_workflows','promotion_experience','analytics','discovery','journeys','enterprises']as$m)require_once dirname(__DIR__).'/app/'.$m.'.php';
function ef_render(string $view,array $data=[]):string{extract($data,EXTR_SKIP);ob_start();include dirname(__DIR__).'/views/'.$view.'.php';return (string)ob_get_clean();}
function ef_page(string $body,bool $workspace=false):string{
 $styles=$workspace?['write','workspace','workspace-polish','unified-experience']:['site','experience','personal-refresh','brand-refresh','unified-experience'];$head='';foreach($styles as$s)$head.='<link rel="stylesheet" href="/assets/css/'.$s.'.css">';
 $nav='';if($workspace){$nav='<div class="workspace-top-actions"></div><nav class="workspace-nav">';foreach(ux_workspace_groups()as$group=>$links){$nav.='<details open><summary>'.e($group).'</summary>';foreach($links as$href=>$label)$nav.='<a data-workspace-link href="'.e($href).'">'.e($label).'</a>';$nav.='</details>';}$nav.='</nav>';}
 return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'.$head.'</head><body class="'.($workspace?'workspace-auth':'page-experience').'">'.$nav.'<main id="'.($workspace?'writer-content':'main').'">'.$body.'</main><script src="/assets/js/unified-experience.js" defer></script></body></html>';
}
$nonce=str_repeat('a',32);$promote=ef_render('desk/promote',['values'=>[],'nonce'=>$nonce,'received'=>false,'error'=>'']);file_put_contents($out.'/promote.html',ef_page($promote));
file_put_contents($out.'/explore.html',ef_page(ef_render('experience/hub')));file_put_contents($out.'/purpose.html',ef_page(ef_render('personal_purpose')));
$body=ef_render('write/control_centre',['counts'=>['Fixture drafts'=>2,'Fixture messages'=>3,'Fixture review'=>null,'Fixture posts'=>1]]);
$body.='<form method="post" action="/fixture-only"><h2>Fixture contact</h2>';foreach(['Name','Email','Company','Country','County','Town','Message']as$i=>$name)$body.='<label>'.$name.'<input name="field'.$i.'" '.($i<2?'required':'').'></label>';$body.='<input name="csrf" type="hidden" value="DO-NOT-PREVIEW"><button type="submit">Explicit fixture save</button></form><h2>Fixture records</h2><table><tbody>';foreach(['Alpha','Beta','Gamma','Delta','Epsilon']as$name)$body.='<tr><td>'.$name.'</td></tr>';$body.='</tbody></table>';
file_put_contents($out.'/workspace.html',ef_page($body,true));
/* An isolated render of the actual public layout, including its header,
 * secondary navigation, footer and production stylesheet ordering.
 * No live site, external account, production database or submission is used.
 */
$_SERVER['REQUEST_URI']='/';
$publicHome='<section class="home-hero"><div class="container home-hero-grid">'
    .'<div class="home-hero-copy"><h1>Waithaka Ndegwa</h1><p>Public layout fixture</p></div>'
    .'<div class="home-hero-media" aria-hidden="true"></div></div></section>'
    .'<section class="section"><div class="container"><h2>Experience</h2><p>Sample section</p>'
    .'<h2>Lessons</h2><p>Sample section</p><h2>Next steps</h2><p>Sample section</p>'
    .'</div></section>';
file_put_contents($out.'/public-home.html',ef_render('layout',['page'=>'home','content'=>$publicHome,'suppressAnalytics'=>true]));
function comm_config():array{return ['enabled'=>false,'owner'=>0];}
$_SERVER['REQUEST_URI']='/contact';
file_put_contents($out.'/public-contact.html',ef_render('layout',['page'=>'contact','content'=>ef_render('contact',['contactValues'=>['country'=>'Kenya','phone_code'=>'+254'],'contactReceipt'=>null]),'suppressAnalytics'=>true]));
$_SERVER['REQUEST_URI']='/explore';
$publicPage='<article class="ux-page"><h1>Public page layout</h1><p>Equivalent public page gutters and content width.</p></article>';
file_put_contents($out.'/public-explore-layout.html',ef_render('layout',['page'=>'explore','content'=>$publicPage,'suppressAnalytics'=>true]));
$_SERVER['REQUEST_URI']='/search';
$searchEmpty=ef_render('search',[
  'q'=>'','type'=>'all','categories'=>[
    ['slug'=>'social-entrepreneurship','name'=>'Social entrepreneurship'],
    ['slug'=>'travel','name'=>'Travel']
  ],'results'=>[],'counts'=>array_fill_keys(array_keys(discovery_types()),0),
  'pagination'=>['total'=>0,'start'=>0,'end'=>0,'page'=>1,'pages'=>0,'previous'=>'','next'=>'']
]);
file_put_contents($out.'/public-search.html',ef_render('layout',['page'=>'search','content'=>$searchEmpty,'suppressAnalytics'=>true]));
$searchResults=ef_render('search',[
  'q'=>'enterprise','type'=>'post','categories'=>[],
  'results'=>[[
    'type'=>'post','title'=>'Synthetic enterprise search result',
    'summary'=>'Local fixture only; no production article or client record.',
    'url'=>url('/my-thoughts/fixture-only'),'image'=>'',
    'published_at'=>'2026-10-01 09:00:00'
  ]],
  'counts'=>array_fill_keys(array_keys(discovery_types()),1),
  'pagination'=>['total'=>1,'start'=>1,'end'=>1,'page'=>1,'pages'=>1,'previous'=>'','next'=>'']
]);
file_put_contents($out.'/public-search-results.html',ef_render('layout',['page'=>'search','content'=>$searchResults,'suppressAnalytics'=>true]));
/* Actual shared field partials, rendered as used in each eligible journey.
 * No private customer data, backend writes or genuine submitted forms. */
foreach(['contact','booking','founder','consent','promote','opportunity','client-contact'] as $prefix){
  $form='<main class="container"><h1>Contact location fixture</h1><form action="/fixture-no-submit" method="post">'
   .ef_render('partials_location_fields',[
     'locationValues'=>['country'=>'Kenya','phone_code'=>'+254'],
     'locationPrefix'=>$prefix,
     'locationCountryRequired'=>in_array($prefix,['founder','booking','opportunity'],true),
     'locationPhone'=>$prefix!=='opportunity',
     'locationLegend'=>'Contact and location'
   ]).'<button type="button">No submission</button></form></main>';
  file_put_contents($out.'/location-'.$prefix.'.html',ef_page($form));
}
/* Broader flow acceptance: render real static public templates with empty or
 * synthetic data. No production connection, real client or external service.
 * The page layout and its body-loaded CSS are retained, so alignment checks
 * can compare actual public surfaces against the same header gutters. */
$emptyPage=['total'=>0,'start'=>0,'end'=>0,'page'=>1,'pages'=>0,'previous'=>'','next'=>''];
$fixtures=[
  'start-here'=>['/start-here','editorial','editorial/start',['seasons'=>[]]],
  'startup-journeys'=>['/startup-journeys','journeys','journeys/index',['seasons'=>[]]],
  'founder-stories'=>['/founders','founders','founders/index',['stories'=>[],'pagination'=>$emptyPage]],
  'resources'=>['/resources','editorial','editorial/collection',['type'=>'resource','items'=>[],'pagination'=>$emptyPage]],
  'watch'=>['/watch','editorial','editorial/collection',['type'=>'watch','items'=>[],'pagination'=>$emptyPage]],
  'enterprises'=>['/enterprises','enterprises','enterprises/index',[
    'filters'=>['q'=>'','country'=>'','sector'=>'','stage'=>''],'countries'=>[],'sectors'=>[],'profiles'=>[]
  ]],
  'employment'=>['/opportunities/jobs','founders','opportunities/index',['q'=>'','items'=>[]]],
  'opportunities'=>['/opportunities','experience','desk/index',[
    'kind'=>'opportunity','items'=>[],'filters'=>['q'=>'','type'=>'','country'=>'','sector'=>'','status'=>''],
    'pageNumber'=>1,'total'=>0,'jobs'=>[]
  ]],
  'partner-stories'=>['/partner-stories','experience','desk/index',[
    'kind'=>'sponsored','items'=>[],'filters'=>['q'=>'','type'=>'','country'=>'','sector'=>'','status'=>''],
    'pageNumber'=>1,'total'=>0,'jobs'=>[]
  ]],
  'monthly-roundup'=>['/monthly-roundup','newsletter','roundup/index',['editions'=>[]]],
  'newsletter'=>['/newsletter','newsletter','journeys/newsletter',['values'=>[]]],
  'explore'=>['/explore','experience','experience/hub',[]],
  'advertise'=>['/advertise','experience','experience/advertise',[]],
  'advertising-policy'=>['/advertising-policy','experience','experience/advertising_policy',[]],
  'promotion-intake'=>['/promote','experience','desk/promote',[
    'values'=>[],'nonce'=>$nonce,'received'=>false,'error'=>''
  ]],
];
foreach($fixtures as$key=>[$path,$page,$view,$values]){
    $_SERVER['REQUEST_URI']=$path;
    $body=ef_render($view,$values);
    file_put_contents($out.'/sitewide-'.$key.'.html',
        ef_render('layout',['page'=>$page,'content'=>$body,'suppressAnalytics'=>true,
            'privatePage'=>in_array($key,['newsletter','promotion-intake'],true)]));
}
$_SERVER['REQUEST_URI']='/';

echo "Rendered isolated actual-view fixtures. No database, provider or production content accessed.\n";
