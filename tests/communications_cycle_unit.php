<?php
declare(strict_types=1);
/** Pure, offline policy. No DB, sender, API or live document is accessed. */
function url(string $path=''):string{return 'https://www.waithakandegwa.com/'.ltrim($path,'/');}
function comm_get(string $key,array $fallback=[]):array{return $fallback;}
function impact_snapshot(string $source):array{return [];}
require dirname(__DIR__).'/app/communications_cycle.php';
$checks=0;
function cycle_ok(bool $pass,string $why):void{global $checks;$checks++;if(!$pass)throw new RuntimeException($why);}
function cycle_reject(callable $run,string $why):void{try{$run();}catch(InvalidArgumentException){cycle_ok(true,$why);return;}throw new RuntimeException($why);}
$cfg=communications_cycle_defaults();
cycle_ok($cfg['enabled']&&$cfg['weekly_draft_enabled']&&$cfg['timezone']==='Africa/Nairobi','Private preparation enabled independently of delivery worker.');
cycle_ok(count(communications_cycle_sources())===4,'Four independent source-governed organization identities.');
$inputs=['enabled'=>'1','weekly_draft_enabled'=>'1','daily_time'=>'12:00','article_day'=>'2','article_time'=>'09:00','newsletter_day'=>'5','newsletter_time'=>'09:00'];
$valid=communications_cycle_input($inputs);
cycle_ok($valid['article_day']===2&&$valid['newsletter_day']===5&&$valid['enabled']&&$valid['weekly_draft_enabled'],'Validated days and times.');
foreach([['daily_time'=>'33:00'],['article_day'=>'9'],['newsletter_time'=>"08:00\n"]]as$bad)
 cycle_reject(fn()=>communications_cycle_input(array_replace($inputs,$bad)),'Invalid timing input fails closed.');
cycle_ok(!communications_cycle_input(array_replace($inputs,['enabled'=>'','weekly_draft_enabled'=>'']))['enabled'],'Pausing the preparatory cycle is explicit.');
$when=(new DateTimeImmutable('2026-10-28 15:00:00',new DateTimeZone('Africa/Nairobi')))->getTimestamp();
$missing=communications_cycle_source_preview('ubunifu',[]);
cycle_ok($missing['ready']===false&&$missing['copy']===''&&str_contains($missing['note'],'approved'),'Missing source results never produce posts.');
$approved=['status'=>'available','data'=>[
 'schema_version'=>2,'source'=>'goblis_group','approved_for_publication'=>true,
 'revision'=>4,'metrics'=>['startups'=>6],'measurement_period_start'=>'2026-09-01',
 'measurement_period_end'=>'2026-09-30','published_at'=>'2026-10-20T09:00:00+03:00',
 'next_report_due_at'=>'2028-12-31T23:59:59+03:00'
]];
$actual=communications_cycle_source_preview('goblis_group',$approved);
cycle_ok($actual['ready']&&str_contains($actual['copy'],'6 successful startups')&&str_contains($actual['copy'],'2026-09-01 – 2026-09-30'),'Only source-approved metrics and exact measurement period enter a draft.');
$withdrawn=$approved;$withdrawn['status']='withheld';
cycle_ok(!communications_cycle_source_preview('goblis_group',$withdrawn)['ready'],'Withdrawal removes a ready draft.');
$foreign=$approved;$foreign['data']['source']='ubunifu';
cycle_ok(!communications_cycle_source_preview('goblis_group',$foreign)['ready'],'Cross-organization metric substitution rejected.');
$future=$approved;$future['data']['next_report_due_at']='2020-01-01T00:00:00Z';
cycle_ok(!communications_cycle_source_preview('goblis_group',$future)['ready'],'Overdue data cannot seed a fresh daily claim.');
$planned=communications_cycle_schedule($when,$valid,['goblis_group'=>$approved]);
cycle_ok(count(array_filter($planned,fn($x)=>$x['kind']==='daily'))===14,'One daily organization briefing for each of 14 days.');
cycle_ok(count(array_filter($planned,fn($x)=>$x['kind']==='article'))===2,'Two visible weekly original-article preparations in two weeks.');
cycle_ok(count(array_filter($planned,fn($x)=>$x['kind']==='newsletter'))===2,'Two visible weekly newsletter preparations in two weeks.');
cycle_ok(count($planned)===count(array_unique(array_column($planned,'id'))),'Recurring slots have distinct immutable date-kind identifiers.');
cycle_ok(count(array_filter($planned,fn($x)=>$x['kind']==='daily'&&$x['source']==='goblis_group'))>=3,'Daily source rotation is deterministic and balanced.');
cycle_ok(count(array_filter($planned,fn($x)=>$x['automatic_delivery']!==false))===0,'Automatic preparation never implies provider sending or publishing.');
$readyBriefs=array_filter($planned,fn($x)=>$x['kind']==='daily'&&$x['copy']!=='');
cycle_ok(count($readyBriefs)>0&&count($readyBriefs)<14,'Only approved source-specific dates can provide ready copy.');
$items=[['slug'=>'verified-story','title'=>'Real <lesson> & progress'],['slug'=>'../../private','title'=>'Unsafe'],['slug'=>'another-story','title'=>'Another approved piece']];
$email=communications_cycle_email_values('2026-10-30',$items);
cycle_ok($email!==null&&str_contains($email['body_html'],'Real &lt;lesson&gt; &amp; progress')&&!str_contains($email['body_html'],'/private')&&str_contains($email['body_html'],'/my-thoughts/verified-story'),'Weekly email private draft uses only safe public slugs and escaped headlines.');
cycle_ok(communications_cycle_email_values('2026-10-30',[])===null,'No published articles means no automated newsletter draft.');
cycle_reject(fn()=>communications_cycle_email_values('20261030',[]),'Invalid date rejected.');
cycle_ok(!str_contains(file_get_contents(dirname(__DIR__).'/app/communications_cycle.php'),'enqueue_email(')
   &&!str_contains(file_get_contents(dirname(__DIR__).'/app/communications_cycle.php'),'publishing_work('),
   'Editorial preparation cannot dispatch mail or direct social providers.');
echo "PASS: $checks editorial cycle, source-proof, cadence, safe email and no-send policy assertions.\n";
