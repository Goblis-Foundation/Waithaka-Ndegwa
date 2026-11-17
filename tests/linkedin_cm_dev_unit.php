<?php
declare(strict_types=1);
require_once __DIR__.'/../app/providers/linkedin_cm_dev.php';
$count=0;
function check(bool $ok,string $label):void { global $count; if(!$ok)throw new RuntimeException('FAIL: '.$label);$count++; }
function rejects(callable $f,string $label):void { try{$f();}catch(RuntimeException){check(true,$label);return;}check(false,$label); }
$p=publishing_linkedin_cm_dev_profile();
check(!$p['enabled']&&!$p['production_allowed']&&!$p['automatic_delivery_allowed'],'disabled and never production/automation');
check($p['scopes']===['r_basicprofile','w_member_social'],'least privilege, no OIDC/organization/contact scopes');
check($p['credential_namespace']==='linkedin_cm_dev','isolated credentials');
publishing_linkedin_cm_dev_budget(499,99);
check(true,'last available request');
foreach([[500,99,1],[499,100,1],[0,0,501],[-1,0,1],[0,1,1],[1,1,0],[499,99,2]] as $args)
    rejects(fn()=>publishing_linkedin_cm_dev_budget(...$args),'budget boundary rejects');
$who=publishing_linkedin_cm_dev_identity(['id'=>'app_local-123','localizedFirstName'=>'Isaac','localizedLastName'=>'Ndegwa']);
check($who['id']==='app_local-123'&&$who['resources'][0]['type']==='member','self identity parsed');
foreach([['sub'=>'other-app'],['id'=>'urn:li:organization:123'],['id'=>['bad']],['id'=>'abc','localizedFirstName'=>"bad\n",'localizedLastName'=>'name']]as$bad)
    rejects(fn()=>publishing_linkedin_cm_dev_identity($bad),'malformed or foreign identity rejected');
check(publishing_linkedin_cm_dev_callback('https://dev.example.com/','development')==='https://dev.example.com/write/connections/callback/linkedin_cm_dev','fixed isolated HTTPS callback');
check(str_starts_with(publishing_linkedin_cm_dev_callback('http://localhost:8080','test'),'http://localhost:8080/'),'local HTTP test');
foreach([['https://dev.example.com','production'],['https://dev.example.com','staging'],['http://dev.example.com','test'],['https://evil.test@dev.example.com','test'],['https://dev.example.com?next=evil','test'],['https://dev.example.com/path','test'],['http://localhost','development']]as$args)
    rejects(fn()=>publishing_linkedin_cm_dev_callback(...$args),'unsafe callback rejected');
rejects(fn()=>publishing_linkedin_cm_dev_require_transport(),'transport cannot be activated');
$oauth=file_get_contents(__DIR__.'/../app/providers/oauth.php');
check(str_contains($oauth,"https://api.linkedin.com/v2/userinfo"),'existing OIDC retained');
check(!str_contains($oauth,'linkedin_cm_dev'),'draft is not wired into OAuth');
echo "LinkedIn CM dev: $count checks passed.\n";
