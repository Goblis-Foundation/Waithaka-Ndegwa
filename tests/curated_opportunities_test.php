<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/functions.php';
require_once dirname(__DIR__).'/app/publishing_desk_policy.php';
require_once dirname(__DIR__).'/app/curated_opportunities_release.php';
$n=0;
function coa(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);$GLOBALS['n']++;}
$catalog=curated_release_catalog();coa(count($catalog['entries'])===16,'Exactly sixteen distinct entries.');
$seen=[];$noticeCount=0;
foreach($catalog['entries']as$entry){
    coa(!isset($seen[$entry['slug']]),'No duplicate slug.');$seen[$entry['slug']]=true;
    $input=curated_release_input($entry,$catalog['checked_on']);$fields=desk_fields($input,'opportunity',true);
    coa($fields['sponsored']===false,'Independent editorial listing, not paid placement.');
    coa($fields['amount_min']===null&&$fields['amount_max']===null,'No shared pool, service budget or stipend is misrepresented as an individual grant.');
    coa($fields['deadline_mode']===($entry['deadline_iso']===null?'unknown':'fixed'),'Deadline precision remains honest.');
    if($entry['deadline_iso']!==null)coa($fields['deadline']===(new DateTimeImmutable($entry['deadline_iso']))->getTimestamp(),'Exact deadline timezone conversion.');
    else coa($fields['deadline']===null,'No invented exact time for date-only or undisclosed deadlines.');
    $body=curated_release_body($entry);coa(mb_strlen(plain_text($body))>600,'Substantive individual article.');
    coa(substr_count($body,'<h2>')===6,'Six navigable content sections.');
    coa(!preg_match('~<script|<iframe|Research cut-off|PRIVATE-EDITORIAL~i',$body),'No scripts or internal research footers.');
    $dom=new DOMDocument();$old=libxml_use_internal_errors(true);$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$body.'</body></html>',LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);
    foreach($dom->getElementsByTagName('a')as$link){coa(str_starts_with($link->getAttribute('href'),'https://'),'Public HTTPS destinations only.');coa($link->getAttribute('target')==='_blank'&&str_contains($link->getAttribute('rel'),'noopener'),'External links are explicit safe new-tab links.');}
    if($entry['notice']!==''){$noticeCount++;coa(str_contains(plain_text($body),$entry['notice']),'Verification limitations remain public.');}
    $post=['title'=>$entry['title'],'standfirst'=>$entry['summary'],'content_html'=>$body,'category_id'=>1];
    $r=['state'=>'draft','kind'=>'opportunity','fields'=>$fields,'creatives'=>[]];
    coa(in_array('Your approval must match the current article, facts and creatives.',desk_publish_errors($r,$post),true),'Native approval guard is not bypassed.');
}
coa($noticeCount===6,'Six entries carry explicit source/terms limitations.');
$all=json_encode($catalog,JSON_THROW_ON_ERROR);
foreach(['SBLC','not cash','paid','US$500','US$100,000','EUR15,000','US$250']as$term)coa(str_contains($all,$term),'Material financing distinction retained: '.$term);
$hook=(string)file_get_contents(dirname(__DIR__).'/.platform/hooks/postdeploy/37_curated_opportunities.sh');coa(str_contains($hook,'curated_release_import'),'Deployment calls only the reviewed native importer.');
$code=(string)file_get_contents(dirname(__DIR__).'/app/curated_opportunities_release.php');
foreach(['enqueue_email(','desk_send_client(','desk_schedule_campaign(','publishing_approve(','curl_exec(']as$term)coa(!str_contains($code,$term),'No outbound or unrelated action: '.$term);
coa(str_contains($code,"'action'=>'approve'")&&str_contains($code,"'action'=>'publish'"),'Separate native approval and publication are required.');
$home=(string)file_get_contents(dirname(__DIR__).'/views/home.php');coa(!str_contains($home,'featured-thought')&&!str_contains($home,'openingEssay'),'Homepage article-free policy preserved.');
echo 'PASS: '.$n." curated-opportunity content and safety checks.\n";
