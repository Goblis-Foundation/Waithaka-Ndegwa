<?php
declare(strict_types=1);
$CONFIG=['app_env'=>'testing','app_key'=>'enterprise-unit-fixture','base_url'=>'https://www.waithakandegwa.com'];$APP_INSTALLED=false;
require_once __DIR__.'/../app/functions.php';require_once __DIR__.'/../app/journeys.php';require_once __DIR__.'/../app/reader.php';
$fixture=[];
function comm_config():array{return ['owner'=>7];}
function comm_lock(string $key,callable $fn):mixed{return $fn();}
function comm_get(string $key,array $default=[]):array{return $GLOBALS['fixture'][$key]??$default;}
function comm_put(string $key,array $value):void{$GLOBALS['fixture'][$key]=$value;}
function comm_records(string $prefix,int $limit=100):array{return array_values(array_filter($GLOBALS['fixture'],static fn($r)=>isset($r['id'])));}
require_once __DIR__.'/../app/enterprises.php';
function ec(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);}
function er(callable $fn):void{try{$fn();}catch(EnterpriseException|ReaderValidationException){return;}throw new RuntimeException('Invalid enterprise mutation accepted.');}
ec(enterprise_public_profiles()===[],'No fictional enterprise seeds.');
$input=['name'=>'Fixture venture','sector'=>'Social enterprise','summary'=>'Reviewed summary','problem'=>'Problem','solution'=>'Solution','involvement'=>'A documented role','private_notes'=>'PRIVATE EVIDENCE','consent_reference'=>'PRIVATE PERMISSION','country'=>'Kenya','county'=>'Nyeri','sub_county'=>'Kieni West','city'=>'Endarasha','targets_text'=>'Permanent jobs | 5 | jobs | 2030-06-30','action'=>'draft','version'=>0];
er(fn()=>enterprise_save(8,null,$input));
$id=enterprise_save(7,null,$input);$r=enterprise_owned(7,$id);
ec(enterprise_public_profiles()===[]&&$r['version']===1,'Working copy stays private.');
er(fn()=>enterprise_save(7,$id,$input+['reviewed'=>'1','consent_confirmed'=>'1']));
er(fn()=>enterprise_save(7,$id,array_replace($input,['version'=>1,'action'=>'publish'])));
enterprise_save(7,$id,array_replace($input,['version'=>1,'action'=>'publish','reviewed'=>'1','consent_confirmed'=>'1']));
$p=enterprise_public_profiles()[0];
ec(array_intersect(['private_notes','consent_reference','targets_text'],array_keys($p))===[],'Private notes and permission references are excluded.');
ec($p['summary']==='Reviewed summary'&&$p['targets'][0]['value']==='5'&&!isset($p['actual']),'Targets are retained without invented results.');
enterprise_save(7,$id,array_replace($input,['version'=>2,'summary'=>'Unsaved public replacement','action'=>'draft']));
ec(enterprise_public_profiles()[0]['summary']==='Reviewed summary','Private edits cannot replace the reviewed public profile.');
er(fn()=>enterprise_save(7,$id,array_replace($input,['version'=>2])));
enterprise_save(7,$id,array_replace($input,['version'=>3,'action'=>'unpublish']));
ec(enterprise_public_profiles()===[],'Unpublishing removes public visibility immediately.');
$unicode=enterprise_save(7,null,array_replace($input,['name'=>'🌱','version'=>0]));
ec(preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',enterprise_owned(7,$unicode)['slug'])===1,'Names with no ASCII slug get a stable safe fallback.');
foreach(['X | NaN | jobs | 2030-06-30','X | 5 | jobs | 2030-02-30','X | 5 | jobs','X | -1 | jobs | 2030-06-30']as$bad)er(fn()=>enterprise_targets($bad));
er(fn()=>enterprise_fields(array_replace($input,['website'=>'javascript:alert(1)'])));
er(fn()=>enterprise_fields(array_replace($input,['post_ids'=>[['1']]])));
ec(count(enterprise_owned(7,$id)['audit'])===4,'Each mutation retains actor/version audit metadata.');
echo "PASS: enterprise privacy, approval, working-copy separation, versions, targets, ownership and safe slugs.\n";
