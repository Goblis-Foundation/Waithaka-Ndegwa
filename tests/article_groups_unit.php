<?php
declare(strict_types=1);
$CONFIG=['base_url'=>'https://www.example.test'];$APP_INSTALLED=false;
require __DIR__.'/../app/functions.php';require __DIR__.'/../app/impact.php';
function ag_check(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);$GLOBALS['checks']++;}
function ag_reject(callable $fn): void {try{$fn();}catch(InvalidArgumentException){$GLOBALS['checks']++;return;}throw new RuntimeException('Invalid group input accepted.');}
$checks=0;
ag_check(count(article_groups())===4&&count(article_editable_groups())===3,'Founder Stories retains its own approval workflow.');
ag_check(article_group_values(['ideas-perspectives','personal-experiences','personal-experiences'])===['personal-experiences','ideas-perspectives'],'Cross-listing is deduplicated in stable order.');
foreach(['personal-experiences',[['personal-experiences']],['founder-stories'],['<script>'],array_fill(0,4,'personal-experiences')]as$bad)ag_reject(fn()=>article_group_values($bad));
ag_check(article_group_fields([],['article_groups_json'=>'["personal-experiences"]'])==='["personal-experiences"]','Older editor clients preserve existing group metadata.');
ag_check(article_group_fields(['article_groups_present'=>'1'],[])==='[]','Unchecking all is explicit.');
ag_reject(fn()=>article_group_fields(['article_groups_present'=>[]],[]));
ag_check(article_group_memberships(['id'=>3])===['ideas-perspectives'],'Unclassified legacy writing remains discoverable.');
ag_check(article_group_memberships(['id'=>3],true)===['founder-stories'],'A reviewed founder story is not silently classified as a personal essay.');
ag_check(article_group_memberships(['id'=>3],false,[3])===['my-enterprise-journey'],'A reviewed public enterprise association groups the article.');
ag_check(article_group_memberships(['id'=>3,'article_groups_json'=>'["personal-experiences"]'],true,[3])===['personal-experiences','my-enterprise-journey','founder-stories'],'One article can appear in multiple groups without duplicate addresses.');
foreach([['q'=>[]],['topic'=>'../write'],['q'=>str_repeat('x',121)],['q'=>"text\nheader"]]as$bad)ag_reject(fn()=>article_group_filters($bad));
$missing=impact_display([],'startups');ag_check($missing['value']===null,'Missing startup reports are unknown, not zero.');
$zero=impact_display(['data'=>['approved_for_publication'=>true,'metrics'=>['startups'=>0],'as_of'=>gmdate('c')]],'startups');ag_check($zero['value']===0,'An approved actual zero remains distinguishable from missing data.');
$withdrawn=impact_display(['data'=>['approved_for_publication'=>false,'metrics'=>['startups'=>9],'as_of'=>gmdate('c')]],'startups');ag_check($withdrawn['value']===null,'Withdrawn counts are not shown.');
require __DIR__.'/../app/article_groups_migration.php';
$authority=['schema_version'=>1,'migration'=>'210_article_groups.sql','sha256'=>str_repeat('a',64),'state'=>'open','account_id'=>'466401734428','region'=>'af-south-1','admin_secret_arn'=>'arn:aws:secretsmanager:af-south-1:466401734428:secret:fixture-only','db_host'=>'fixture.invalid','db_name'=>'fixture_database','release'=>'r17-0123456789ab','expires_at'=>5500];
ag_check(article_groups_authority($authority,'fixture.invalid','fixture_database',str_repeat('a',64),5000)===$authority['admin_secret_arn'],'Only exact, unexpired deployment authority can resolve the administrator identifier.');
foreach([['state'=>'retired'],['expires_at'=>5000],['expires_at'=>10000],['db_host'=>'other.invalid'],['db_name'=>'other_database'],['sha256'=>str_repeat('b',64)],['account_id'=>'111111111111'],['region'=>'us-east-1'],['release'=>'unreviewed'],['admin_secret_arn'=>'arn:aws:secretsmanager:us-east-1:111111111111:secret:other'],['password'=>'PRIVATE_CANARY'],['expires_at'=>'5500']]as$change){
 try{article_groups_authority(array_replace($authority,$change),'fixture.invalid','fixture_database',str_repeat('a',64),5000);}catch(RuntimeException){$checks++;continue;}throw new RuntimeException('Invalid deployment authority accepted.');
}
echo "PASS: $checks article grouping and verified-progress policy checks.\n";
