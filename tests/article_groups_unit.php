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
$stack=['StackName'=>'waithaka-ndegwa-production-466401734428-data','StackId'=>'arn:aws:cloudformation:af-south-1:466401734428:stack/waithaka-ndegwa-production-466401734428-data/fixture-123','Outputs'=>[['OutputKey'=>'DatabaseHost','OutputValue'=>'fixture.invalid'],['OutputKey'=>'AdminSecretArn','OutputValue'=>'arn:aws:secretsmanager:af-south-1:466401734428:secret:fixture-only']],'Parameters'=>[['ParameterKey'=>'DatabaseName','ParameterValue'=>'fixture_database']]];
$response=['Stacks'=>[$stack]];
ag_check(article_groups_stack_authority($response,'fixture.invalid','fixture_database')===$stack['Outputs'][1]['OutputValue'],'Only exact owned-stack metadata resolves the administrator identifier.');
$variants=[];
$bad=$response;$bad['Stacks'][0]['StackName']='other-stack';$variants[]=$bad;
$bad=$response;$bad['Stacks'][0]['StackId']=str_replace('466401734428','111111111111',$stack['StackId']);$variants[]=$bad;
$bad=$response;$bad['Stacks'][0]['Outputs'][0]['OutputValue']='other.invalid';$variants[]=$bad;
$bad=$response;$bad['Stacks'][0]['Parameters'][0]['ParameterValue']='other_database';$variants[]=$bad;
$bad=$response;$bad['Stacks'][0]['Outputs'][1]['OutputValue']='arn:aws:secretsmanager:us-east-1:111111111111:secret:other';$variants[]=$bad;
$bad=$response;array_pop($bad['Stacks'][0]['Outputs']);$variants[]=$bad;
$bad=$response;$bad['Stacks'][0]['Outputs'][]=$stack['Outputs'][1];$variants[]=$bad;
$bad=$response;$bad['Stacks'][0]['Parameters'][]=$stack['Parameters'][0];$variants[]=$bad;
$variants[]=['Stacks'=>[]];$variants[]=['Stacks'=>[$stack,$stack]];
$variants[]=$response+['NextToken'=>'unfinished'];
$bad=$response;$bad['Stacks'][0]['Outputs']='invalid';$variants[]=$bad;
foreach($variants as$bad){try{article_groups_stack_authority($bad,'fixture.invalid','fixture_database');}catch(RuntimeException){$checks++;continue;}throw new RuntimeException('Foreign or incomplete deployment authority accepted.');}
echo "PASS: $checks article grouping and verified-progress policy checks.\n";
