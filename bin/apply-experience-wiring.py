#!/usr/bin/env python3
"""One-time, exact-match source integration on the dedicated feature branch only."""
from pathlib import Path
import json
import subprocess

ROOT=Path(__file__).resolve().parents[1]
BRANCH='feat/unified-journeys-seo-20261027'
branch=subprocess.check_output(['git','branch','--show-current'],cwd=ROOT,text=True).strip()
if branch!=BRANCH:
    raise RuntimeError('This one-time integration only runs on its dedicated feature branch.')
files={}
def text(path):
    if path not in files: files[path]=(ROOT/path).read_text()
    return files[path]
def change(path,old,new,count=1):
    current=text(path)
    if current.count(old)!=count:
        raise RuntimeError(f'Expected {count} exact integration anchor(s) in {path}, got {current.count(old)}: {old[:140]}')
    files[path]=current.replace(old,new)

p='app/publishing_desk.php'
anchor="require_once __DIR__.'/publishing_desk_media.php';"
change(p,anchor,anchor+"\nrequire_once __DIR__.'/commercial_control.php';\nrequire_once __DIR__.'/experience_workflows.php';")
change(p,"    $pdo=db();$pdo->beginTransaction();try{comm_put('desk.post.'", "    $r=commercial_new_review($r,$in);\n    $pdo=db();$pdo->beginTransaction();try{comm_put('desk.post.'")
change(p,"$order??=desk_current_order($r);", "$order??=desk_current_order($r);commercial_assert_suitable($r,$post);")
old="    return ['name'=>desk_text($in['client_name']??'','Client name',120,true),'email'=>strtolower($email),'business'=>desk_text($in['organisation']??'','Business',180,true)];"
new="    $client=['name'=>desk_text($in['client_name']??'','Client name',120,true),'email'=>strtolower($email),'business'=>desk_text($in['organisation']??'','Business',180,true)];\n    $profile=commercial_client_profile($in);if($profile!==null)$client['contact_profile']=$profile;return $client;"
change(p,old,new)
change(p,"$r['client']=$newClient;", "$r['client']=commercial_preserve_client($r['client']??[],$newClient);")
change(p,"            $r['fields']=$fields;$r['owner_approval']", "            $r['fields']=$fields;if($fields['sponsored']&&!isset($r['suitability']))$r=commercial_new_review($r,$in);$r['owner_approval']")
change(p,"        $cfg=desk_config();if($cfg['seller']===''", "        $reviewPost=writer_post_by_id((int)$r['post_id']);if(!$reviewPost)throw new InvalidArgumentException('Source article unavailable.');commercial_assert_suitable($r,$reviewPost);\n        $cfg=desk_config();if($cfg['seller']===''")
change(p,"function desk_schedule_campaign(string $oid,int $uid): int {\n", "function desk_schedule_campaign(string $oid,int $uid): int {\n    $mode=desk_order($oid);if(!empty($mode['automatic_repeats']['enabled']))return commercial_launch_first($oid,$uid);\n")
change(p,"    foreach($q->fetchAll()as$job){$r=desk_post_record", "    foreach($q->fetchAll()as$job){$o=null;$r=desk_post_record")
change(p,"        if(!$valid){$u=db()->prepare(\"UPDATE publishing_jobs", "        if($valid&&!commercial_delivery_allowed($r,$o,$map,$job))$valid=false;\n        if(!$valid){$u=db()->prepare(\"UPDATE publishing_jobs")
change(p,"    desk_reconcile_due();desk_mail_work();", "    desk_reconcile_due();commercial_repeat_work();desk_mail_work();")

p='app/publishing_desk_routes.php'
change(p,"function desk_dispatch(string $path): bool {\n", "function desk_dispatch(string $path): bool {\n    if(ux_dispatch($path)||commercial_dispatch($path))return true;\n")
change(p,"                    $client=desk_client($_POST);if(($_POST['privacy_ack']", "                    commercial_intake_values($_POST);$client=desk_client($_POST);if(($_POST['privacy_ack']")

p='views/desk/promote.php'
old="<?php if($step===3):?><div class=\"promote-file-box\">"
new="<?php if($step===1)include __DIR__.'/suitability_fields.php';?>\n<?php if($step===3):?><?php $locationValues=$values;$locationPrefix='promote';$locationPhone=true;$locationCountryRequired=false;$locationLegend='Telephone and location';include __DIR__.'/../partials_location_fields.php';?><div class=\"promote-file-box\">"
change(p,old,new)
change(p,"Optional telephone and location details can be completed later in your private workspace. This brief does not subscribe you to newsletters.", "Telephone and location details are private and can be changed later in your workspace. The phone calling code is independent of your country of residence. This brief does not subscribe you to newsletters.")

for p in ['views/partials_location_fields.php','assets/js/location-fields.js']:
    change(p,'Town or Location','Town / Area')

p='assets/js/promotion-experience.js'
change(p,"panel.querySelectorAll('.promote-field input:not([type=\"file\"]),.promote-field textarea').forEach(input => {\n          const label = input.closest('label').querySelector('span').textContent.replace(/\\s*(?:optional|\\*)\\s*$/, '').trim();\n          summaryRow(list, label, input.value.trim());\n        });", "panel.querySelectorAll('input,textarea,select').forEach(input => {\n          if (input.disabled || input.closest('[hidden]') || ['hidden','file','checkbox','radio','submit','button'].includes(input.type)) return;\n          const source = input.labels?.[0];\n          const clone = source?.cloneNode(true);\n          clone?.querySelectorAll('input,textarea,select,small,button').forEach(node => node.remove());\n          const label = (clone?.textContent || input.name).replace(/\\s*(?:optional|\\*)\\s*$/, '').trim();\n          const value = input.tagName === 'SELECT' ? (input.selectedOptions?.[0]?.textContent || '') : input.value.trim();\n          summaryRow(list, label, value);\n        });")

p='views/layout.php'
change(p,"$seo = array_merge(seo_defaults(), $seo ?? []);", "require_once __DIR__.'/../app/experience_workflows.php';\n$seo = ux_seo(array_merge(seo_defaults(), $seo ?? []),path_only(),!empty($privatePage),$_GET);\n$uxCrumbs=ux_breadcrumbs($seo,path_only(),!empty($privatePage));")
change(p,"$schema = $schema ?? [];", "$schema = $schema ?? [];if($uxCrumbs)$schema[]=ux_breadcrumb_schema($uxCrumbs);")
anchor="<link rel=\"stylesheet\" href=\"<?=asset('css/brand-refresh.css')?>\">"
change(p,anchor,anchor+"<link rel=\"stylesheet\" href=\"<?=asset('css/unified-experience.css')?>\">")
change(p,"<main id=\"main\"><?php include __DIR__.'/partials_public_explore.php';?><?=$content?></main>", "<main id=\"main\"><?php include __DIR__.'/partials_public_explore.php';include __DIR__.'/partials_experience_navigation.php';?><?=$content?></main>")
change(p,"<div class=\"footer-nav\"><span>Discover</span>", "<div class=\"footer-nav\"><span>Discover</span><a href=\"<?=url('/explore')?>\">Explore all journeys</a><a href=\"<?=url('/advertise')?>\">Sponsored publishing</a>")
change(p,"</body>", "<script src=\"<?=asset('js/unified-experience.js')?>\" defer></script></body>")
p='views/write/layout.php'
change(p,anchor,anchor+"<link rel=\"stylesheet\" href=\"<?=asset('css/unified-experience.css')?>\">")
change(p,"'Daily work'=>['/write'=>'Today',", "'Daily work'=>['/write'=>'Today','/write/control-centre'=>'Control centre',")
change(p,"'/write/discovery'=>'Search performance'", "'/write/seo-checks'=>'SEO publishing checks','/write/discovery'=>'Search performance'")
change(p,"</body>", "<script src=\"<?=asset('js/unified-experience.js')?>\" defer></script></body>")
p='views/partials_public_explore.php'
change(p,"start-here|my-thoughts|", "explore|advertise|advertising-policy|start-here|my-thoughts|")
change(p,"foreach(['/my-thoughts'=>'My Thoughts'", "foreach(['/explore'=>'Explore journeys','/my-thoughts'=>'My Thoughts'")

p='views/write/desk_edit.php'
anchor="<?php if($error):?><p class=\"desk-alert\" role=\"alert\"><?=e($error)?></p><?php endif;?>"
change(p,anchor,anchor+"\n<?php if($r&&!empty($r['fields']['sponsored'])):?><div class=\"ux-owner-workflow-link\"><strong>Advertising review and paid repeat posting</strong><p>Assess suitability, review the complete campaign and control later automatic rounds after the first publication is confirmed.</p><a class=\"desk-button primary\" href=\"<?=url('/write/desk/'.$r['id'].'/workflow')?>\">Open suitability & repeat controls →</a></div><?php endif;?>")
p='views/desk/order.php'
change(p,"<?php else:?>\n<div class=\"desk-actions\"><form method=\"post\">", "<?php else:?>\n<p><a class=\"desk-button\" href=\"<?=url('/write/desk/'.$r['id'].'/workflow')?>\">Suitability & paid repeat-posting controls</a></p>\n<div class=\"desk-actions\"><form method=\"post\">")

# The immutable pins are independently read back at every eligibility check.
p='app/commercial_control.php'
anchor="foreach($a['pins']as$i=>$pin){$package=publishing_package((int)$pin['package']);"
insert="foreach($a['pins']as$i=>$pin){$q=db()->prepare('SELECT content_hash,snapshot_json FROM publishing_revisions WHERE id=? AND package_id=?');$q->execute([(int)$pin['revision'],(int)$pin['package']]);$rev=$q->fetch();if(!$rev||!hash_equals((string)$pin['hash'],(string)$rev['content_hash'])||!hash_equals((string)$rev['content_hash'],hash('sha256',(string)$rev['snapshot_json'])))return false;$snap=publishing_decode($rev['snapshot_json']);foreach($o['quote']['channels']as$c){if(!isset($snap['destinations'][$c['slug']])||desk_destination_key($snap['destinations'][$c['slug']])!==$c['route_hash'])return false;desk_contract_variant($r,$snap['variants'][$c['slug']]??[],$c);}$package=publishing_package((int)$pin['package']);"
change(p,anchor,insert)
change(p,"function commercial_repeat_work(): void {\n if(app_config('app_env')!=='production')return;", "function commercial_repeat_work(bool $fixture=false): void {\n if($fixture&&app_config('app_env')!=='testing')throw new RuntimeException('Fixture override is disabled.');\n if(app_config('app_env')!=='production'&&!$fixture)return;")

# Extend existing regressions with a real, explicit fixture suitability review.
p='tests/publishing_desk_database.php'
anchor="$first=(new DateTimeImmutable('+5 days'))->setTime(9,0)->format('Y-m-d\\TH:i');"
review="desk_reject(fn()=>commercial_assert_suitable($sponsored,writer_post_by_id((int)$sponsored['post_id'])),'New commercial content needs an explicit suitability decision.');\ncommercial_review_save($sponsored['id'],['version'=>$sponsored['version'],'material_hash'=>commercial_material_hash($sponsored,writer_post_by_id((int)$sponsored['post_id'])),'advertising_category'=>'enterprise','decision'=>'approved','review_note'=>'Synthetic fixture evidence and rights reviewed.','editorial_ack'=>'1'],$uid);$sponsored=desk_get($sponsored['id']);\n"
change(p,anchor,review+anchor)
p='tests/promotion_experience_unit.php'
anchor="require dirname(__DIR__).'/app/promotion_experience.php';"
change(p,anchor,anchor+"\nrequire dirname(__DIR__).'/app/commercial_control.php';require dirname(__DIR__).'/app/location.php';\nfunction country_options():array{return ['Kenya','Uganda','United Kingdom'];}")

p='composer.json';manifest=json.loads(text(p));manifest['scripts']['test'] += ['php tests/experience_workflows_unit.php','php tests/commercial_repeat_database.php'];files[p]=json.dumps(manifest,ensure_ascii=False,indent=2)+'\n'
p='deploy/aws/verify_reconciliation_release.py'
change(p,"from verify_promotion_release import verify as verify_promotion", "from verify_promotion_release import verify as verify_promotion\nfrom verify_experience_release import verify as verify_experience")
change(p,"    verify_promotion()", "    verify_promotion()\n    verify_experience()")

for path,content in files.items():
    (ROOT/path).write_text(content)
print('Integrated',len(files),'existing source files with exact-match checks:')
for path in sorted(files): print(path)
