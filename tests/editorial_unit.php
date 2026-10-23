<?php
declare(strict_types=1);
/** Pure content/privacy boundary checks. No database or network use. */
$CONFIG=['app_env'=>'testing','base_url'=>'https://www.waithakandegwa.com','timezone'=>'Africa/Nairobi'];
date_default_timezone_set('Africa/Nairobi');
require dirname(__DIR__).'/app/functions.php';
require dirname(__DIR__).'/app/journeys.php';
require dirname(__DIR__).'/app/publishing.php';
require dirname(__DIR__).'/app/editorial.php';
$checks=0;
function eu_check(bool $condition,string $label): void {if(!$condition)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function eu_reject(callable $operation,string $label): void {try{$operation();}catch(EditorialValidationException|EditorialConflictException){eu_check(true,$label);return;}throw new RuntimeException('FAIL: '.$label);}

$toc=editorial_toc('<h2 id="attacker" onclick="bad()">Problem &amp; evidence</h2><p>Read this.</p><h3>Problem &amp; evidence</h3><h2>Problem evidence 2</h2><h2>問題</h2><h3>問題</h3><script>alert(1)</script><h2> </h2><img src="javascript:bad()" onerror="bad()">');
eu_check(count($toc['items'])===5,'Only meaningful H2/H3 headings enter the table of contents');
$ids=array_column($toc['items'],'id');eu_check(count($ids)===count(array_unique($ids)),'Duplicate and suffix-colliding heading labels receive distinct anchors');
eu_check(!str_contains($toc['html'],'attacker')&&!str_contains($toc['html'],'onclick')&&!str_contains($toc['html'],'script')&&!str_contains($toc['html'],'onerror'),'Untrusted markup and IDs are sanitized before anchors are generated');
eu_check($toc['items'][0]['label']==='Problem & evidence'&&$toc['items'][1]['level']===3,'TOC preserves readable text and heading levels');
eu_check($toc===editorial_toc('<h2 id="attacker" onclick="bad()">Problem &amp; evidence</h2><p>Read this.</p><h3>Problem &amp; evidence</h3><h2>Problem evidence 2</h2><h2>問題</h2><h3>問題</h3><script>alert(1)</script><h2> </h2><img src="javascript:bad()" onerror="bad()">'),'Generated anchors are stable for unchanged content');
eu_check(editorial_toc('<p>No headings.</p>')['items']===[],'Short content does not invent a contents list');
eu_check(count(editorial_toc(str_repeat('<h2>Section</h2>',105))['items'])===100,'Contents navigation remains bounded on unusually long articles');

$templates=editorial_templates();eu_check(count($templates)===6,'The collection has six genuine blank tools');
foreach($templates as $slug=>$template){
    eu_check($template['builtin']===true&&$template['published_at']===null&&$template['updated_at']===null,'Blank templates do not invent personal publication histories');
    $download=editorial_download_text($template);eu_check(str_contains($download,'Source: https://www.waithakandegwa.com/resources/'.$slug)&&!str_contains($download,'<h2>'),'The downloadable text contains real prompts and its public source URL');
    eu_check(mb_strlen(plain_text($template['content_html']))>500,'Each blank tool provides substantive prompts');
    eu_reject(fn()=>editorial_normalize_item('resource',['title'=>'Copy','slug'=>$slug]),'Built-in URLs cannot be overwritten through the author editor');
}
$resource=['title'=>'A test resource','summary'=>'A useful synthetic worksheet.','content_html'=>'<h2>Question</h2><p>Record what would change your mind before running the test.</p><script>secret</script>','source_notes'=>'NEVER_PUBLIC','writer_note'=>'NEVER_PUBLIC'];
$normalized=editorial_normalize_item('resource',$resource,true);
eu_check(!isset($normalized['source_notes'],$normalized['writer_note'])&&!str_contains(editorial_json($normalized),'NEVER_PUBLIC'),'Public resource normalization uses an explicit whitelist, excluding private article notes');
eu_check(!str_contains($normalized['content_html'],'script')&&!str_contains($normalized['content_html'],'secret'),'Resource content uses the shared sanitizer');
eu_reject(fn()=>editorial_normalize_item('resource',['title'=>['array']]),'Array-shaped inputs are rejected without coercion');
eu_reject(fn()=>editorial_normalize_item('resource',['title'=>'Short','summary'=>'summary','content_html'=>'<p>A</p>'],true),'Publishing requires useful content');
eu_reject(fn()=>editorial_datetime('2026-02-30T12:30'),'Invalid calendar dates cannot be scheduled');
eu_check(editorial_datetime('2026-10-21T09:30')==='2026-10-21 09:30:00','Local publication times normalize without silently changing timezone');
eu_reject(fn()=>editorial_row_version('1e2'),'A row version must be an explicit integer');
eu_reject(fn()=>editorial_id('1 OR 1=1'),'Record IDs reject query-shaped values');
eu_check(editorial_field(['title'=>['malformed']],'title')==='','Rejected array values render safely in form fields');

$card=['label'=>'Observed interview','basis'=>'interview','summary'=>'Only a synthetic observation for tests.','url'=>'https://example.com/public-evidence','observed_on'=>'2026-01-01','limitations'=>'One conversation; no population claim.'];
$draft=editorial_normalize_evidence(['evidence'=>[$card],'source_notes'=>'NEVER_PUBLIC','update_kind'=>'none','update_note'=>'']);
eu_check(count($draft['evidence'])===1&&!str_contains(editorial_json($draft),'NEVER_PUBLIC'),'Public cards never derive from private source notes');
eu_check(editorial_normalize_evidence(['evidence'=>[['basis'=>'interview']]])['evidence']===[],'Unused card slots remain empty');
foreach(['https://www.waithakandegwa.com/write/preview/1','https://www.waithakandegwa.com/newsletter/one-click?token=x','https://example.com/file?X-Amz-Signature=x','https://example.com/article?preview=1','http://example.com/insecure','https://127.0.0.1/private','javascript:alert(1)'] as $badUrl){$bad=$card;$bad['url']=$badUrl;eu_reject(fn()=>editorial_normalize_evidence(['evidence'=>[$bad]]),'Private or capability URL is not publishable evidence');}
eu_reject(fn()=>editorial_normalize_evidence(['evidence'=>array_fill(0,13,$card)]),'Evidence cards have a bounded count');
eu_reject(fn()=>editorial_normalize_evidence(['update_kind'=>'none','update_note'=>'A change']),'A note cannot silently become an undated ordinary save');
eu_reject(fn()=>editorial_normalize_evidence(['update_kind'=>'correction','update_note'=>'']),'A correction requires an actual explanation');
$bad=$card;$bad['observed_on']='2099-01-01';eu_reject(fn()=>editorial_normalize_evidence(['evidence'=>[$bad]]),'Future events cannot be presented as observed evidence');
$bad=$card;$bad['basis']='verified';eu_reject(fn()=>editorial_normalize_evidence(['evidence'=>[$bad]]),'The editor cannot invent an evidence certification label');
eu_check(editorial_normalize_evidence(['update_kind'=>'correction','update_note'=>'The description of the sample has been corrected.'])['update_kind']==='correction','An explicit correction remains distinct from ordinary draft work');

$watch=['title'=>'Synthetic video','summary'=>'Synthetic test only.','duration_seconds'=>'42','upload_date'=>'2026-01-01T10:00','transcript'=>'This transcript describes a synthetic test video and asserts no real venture results.'];
eu_reject(fn()=>editorial_normalize_item('watch',$watch,true),'Video publication requires owned assets and explicit permission');
$watch['duration_seconds']='-1';eu_reject(fn()=>editorial_normalize_item('watch',$watch),'Negative duration is invalid');
$watch['duration_seconds']='86401';eu_reject(fn()=>editorial_normalize_item('watch',$watch),'Excessive duration is invalid');
$watch['duration_seconds']='42';$watch['upload_date']='2099-01-01T10:00';eu_reject(fn()=>editorial_normalize_item('watch',$watch),'A future video upload date is rejected');
eu_check(editorial_watch_schema(['type'=>'watch','rights_confirmed'=>false])===[],'A placeholder or unapproved video receives no VideoObject');
eu_check(editorial_article_modified(['id'=>1,'status'=>'draft','published_at'=>'2026-01-01'])===null,'An unpublished article cannot expose a public update date');
echo 'Editorial pure checks passed: '.$checks."\n";
