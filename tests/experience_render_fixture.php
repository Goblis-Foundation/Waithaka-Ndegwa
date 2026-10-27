<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'||count($argv)!==2)exit(2);
$out=realpath($argv[1]);if(!$out||!str_starts_with($out,sys_get_temp_dir().'/')&&!str_starts_with($out,(string)getenv('RUNNER_TEMP').'/'))throw new RuntimeException('Use a disposable fixture directory.');
$CONFIG=['app_env'=>'testing','base_url'=>'http://127.0.0.1:8125','timezone'=>'Africa/Nairobi','app_key'=>'local-only-fixture-'.str_repeat('x',40)];$APP_INSTALLED=false;$_SESSION=[];$_GET=[];
foreach(['functions','location','publishing_desk_policy','commercial_control','experience_workflows','promotion_experience']as$m)require_once dirname(__DIR__).'/app/'.$m.'.php';
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
echo "Rendered isolated actual-view fixtures. No database, provider or production content accessed.\n";
