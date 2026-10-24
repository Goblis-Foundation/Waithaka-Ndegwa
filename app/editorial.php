<?php
declare(strict_types=1);

/** Public learning material is always a reviewed snapshot, never a Writer working copy. */
final class EditorialValidationException extends InvalidArgumentException {}
final class EditorialConflictException extends RuntimeException {}

function editorial_types(): array { return ['resource'=>'Resource','experiment'=>'Experiment / decision','watch'=>'Watch page']; }
function editorial_experiment_states(): array { return ['planned'=>'Planned','running'=>'In progress','concluded'=>'Concluded','inconclusive'=>'Inconclusive','stopped'=>'Stopped']; }
function editorial_evidence_bases(): array { return ['firsthand'=>'Firsthand observation','records'=>'Documented records','interview'=>'Interview','self_reported'=>'Self-reported','third_party'=>'Third-party source']; }
function editorial_now(): string { return date('Y-m-d H:i:s'); }
function editorial_json(array $value): string { return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
function editorial_decode(?string $value): array {
    if(!$value)return [];
    try{$result=json_decode($value,true,64,JSON_THROW_ON_ERROR);return is_array($result)?$result:[];}catch(JsonException){return [];}
}
function editorial_text(mixed $value,string $label,int $max,bool $required=false): string {
    if(!is_string($value)&&!is_int($value)&&$value!==null)throw new EditorialValidationException($label.' is not valid.');
    $value=trim(str_replace(["\r\n","\r"],"\n",(string)$value));
    if(!mb_check_encoding($value,'UTF-8')||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new EditorialValidationException($label.' contains invalid characters.');
    if($required&&$value==='')throw new EditorialValidationException($label.' is required.');
    if(mb_strlen($value)>$max)throw new EditorialValidationException($label.' must be '.$max.' characters or fewer.');
    return $value;
}
function editorial_field(array $values,string $key,string $default=''): string { return isset($values[$key])&&is_scalar($values[$key])?(string)$values[$key]:$default; }
function editorial_checked(mixed $value): bool { return $value===true||$value===1||$value==='1'; }
function editorial_id(mixed $value,bool $optional=false): ?int {
    if($optional&&($value===null||$value===''||$value===0||$value==='0'))return null;
    if((!is_int($value)&&!is_string($value))||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value)||(int)$value>2147483647)throw new EditorialValidationException('Choose a valid record.');
    return (int)$value;
}
function editorial_datetime(mixed $value,string $label='Publication time'): string {
    $value=editorial_text($value,$label,19,true);$value=str_replace('T',' ',$value);
    if(strlen($value)===16)$value.=':00';
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value);
    if(!$date||$date->format('Y-m-d H:i:s')!==$value||$date->format('Y')<'1970'||$date->format('Y')>'2100')throw new EditorialValidationException('Enter a valid '.$label.'.');
    return $value;
}
function editorial_public_url(string $value): bool {
    // Shared publishing validation rejects credential, preview, unsubscribe and signed URLs.
    return function_exists('publishing_public_url')&&publishing_public_url($value);
}
function editorial_slug(mixed $value,string $title): string {
    $value=editorial_text($value,'URL slug',160);if($value==='')$value=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($title))??'','-');
    if(!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',$value)||strlen($value)>160)throw new EditorialValidationException('Use a URL slug containing lowercase letters, numbers and single hyphens.');
    return $value;
}

/** Original blank tools; these are prompts, not accounts of an actual venture or its results. */
function editorial_templates(): array {
    $definitions=[
        'problem-interview-worksheet'=>[
            'title'=>'Problem interview worksheet','summary'=>'Prepare a useful conversation, record what happened and separate observations from assumptions.',
            'sections'=>[
                'Before the conversation'=>"Who experiences the problem?\nWhat do I need to learn?\nHow will I ask permission to take notes?\nWhat will I avoid collecting or sharing?",
                'Ask about a real occasion'=>"Tell me about the last time this happened.\nWhat were you trying to achieve?\nWhat did you do first, and then?\nWhat did the current approach cost in time, money or effort?\nWhat alternatives have you already tried?",
                'Keep the record honest'=>"Observation or exact quote (with permission):\nMy interpretation:\nWhat contradicts my assumption?\nWhat remains unknown?\nPermission and anonymity requirements:",
                'Choose the next question'=>"Pattern to investigate across more conversations:\nSmallest next step:\nWho will do it, and by when?"
            ]
        ],
        'experiment-decision-sheet'=>[
            'title'=>'Experiment and decision sheet','summary'=>'State the question, choose the evidence in advance and make the next decision visible.',
            'sections'=>[
                'Before testing'=>"Question:\nHypothesis:\nCurrent baseline and how it was measured:\nWho is included or excluded?\nWhat would change my mind?",
                'Design the test'=>"Smallest test:\nMeasure and evidence source:\nDecision threshold chosen before testing:\nTime and spending limits:\nPermission, privacy or safety considerations:\nStart date and review date:",
                'After testing'=>"What actually happened?\nObserved result and sample size:\nMissing data or limits:\nAlternative explanations:\nDid the test follow the original plan?",
                'Record the decision'=>"Continue, change, stop or investigate:\nReason linked to the evidence:\nNext action, owner and review date:\nWhat can be shared publicly, and with whose permission?"
            ]
        ],
        'first-customer-checklist'=>[
            'title'=>'First customer checklist','summary'=>'Work through a small, clear offer and a responsible pilot without pretending demand has already been proven.',
            'sections'=>[
                'Understand the buyer'=>"[ ] A specific buyer and problem are described.\n[ ] Recent examples of the problem are recorded with permission.\n[ ] The current alternative and its cost are understood.\n[ ] Assumptions and evidence are recorded separately.",
                'Make a bounded offer'=>"[ ] The promised outcome and exclusions are clear.\n[ ] Price, payment terms and delivery dates are written down.\n[ ] Costs and delivery capacity have been checked.\n[ ] Refunds, cancellations and customer questions have a clear route.",
                'Run and review the pilot'=>"[ ] Customer permission and necessary data handling are agreed.\n[ ] A baseline and success measure are recorded before delivery.\n[ ] Feedback includes failures and unmet expectations.\n[ ] The next decision follows the results, not the original hope.",
                'Working notes'=>"Unresolved question:\nEvidence needed:\nNext action:\nOwner:\nReview date:"
            ]
        ],
        'pilot-cost-worksheet'=>[
            'title'=>'Pilot cost worksheet','summary'=>'List the cash, time and assumptions behind a small test before treating revenue as profit.',
            'sections'=>[
                'Define the unit'=>"What is one unit of the offer?\nCurrency and date:\nExpected selling price per unit:\nSource of the price assumption:",
                'List the costs'=>"Direct materials or inputs per unit:\nDelivery and transaction costs per unit:\nLabour time per unit and the rate used:\nFixed costs for the pilot period:\nSetup costs and contingencies:\nTaxes or regulatory costs to verify locally:",
                'Make assumptions visible'=>"Expected units sold:\nCash collected and payment timing:\nRefunds, waste or non-payment allowance:\nContribution per unit = price less variable cost.\nPilot contribution = units sold × contribution per unit less fixed pilot costs.\nCosts excluded from this estimate:",
                'Review after the pilot'=>"Actual units sold and cash collected:\nActual costs and unpaid commitments:\nDifference from the estimate and reason:\nWhat changes before another pilot?"
            ]
        ]
    ];
    $definitions['impact-definition-sheet']=[
        'title'=>'Impact definition sheet','summary'=>'Define a result before counting it: who qualifies, which records support it and how overlap will be handled.',
        'sections'=>[
            'Define the claim'=>"Programme and reporting period:\nOutput, outcome or longer-term impact?\nMetric and unit (people, enterprises or transactions):\nEligibility and exclusions:\nTarget versus observed result:",
            'Record the evidence'=>"Source owner and reporting date:\nCollection method and consent:\nUnique record or deduplication approach:\nMissing records and sampling limits:\nSelf-reported, checked or independently verified?",
            'Prevent double counting'=>"Overlap with other programmes:\nDirect participants versus indirect beneficiaries:\nEvidence of the pathway of benefit:\nUncertainty and alternative explanations:\nWho reviews the report before publication?",
            'Illustrative example — not a programme result'=>"A hypothetical workshop has 20 attendance records and 18 distinct participants. Report 18 participants, not 20 enterprises created. Attendance alone is not evidence of trading or job creation."
        ]];
    $definitions['market-access-readiness-checklist']=[
        'title'=>'Market access readiness checklist','summary'=>'Check whether an enterprise can respond to a buyer, deliver its offer and document what a market connection achieves.',
        'sections'=>[
            'Describe the offer'=>"[ ] A named buyer segment and use case are clear.\n[ ] Product specification, quality and available volume are documented.\n[ ] Price, currency, payment terms and delivery limits are clear.\n[ ] Required local standards or approvals have been checked.",
            'Prepare to deliver'=>"[ ] Production capacity and lead time are realistic.\n[ ] Packaging, logistics, traceability and returns are understood.\n[ ] Working capital needs and payment risks are recorded.\n[ ] A responsible contact can respond to buyer questions.",
            'Follow the connection'=>"Introduction date and permission:\nBuyer meeting or accepted opportunity:\nOrder or transaction evidence, if any:\nDelivery and customer feedback:\nA listing or introduction must not be reported as a completed sale.",
            'Illustrative example — not a real transaction'=>"A hypothetical producer receives a buyer introduction but no order. Record one introduction, zero verified sales, and the next follow-up date. Do not infer revenue or employment."
        ]];
    $result=[];
    foreach($definitions as $slug=>$definition){
        $html='<p>Version 23 October 2026. Allow 20–30 minutes for an initial draft; evidence collection takes longer.</p><p>This is a blank working tool. Replace the prompts with your own observations; do not treat an unanswered field as evidence.</p>';
        foreach($definition['sections'] as $heading=>$prompts){$html.='<h2>'.htmlspecialchars($heading,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</h2><ul>';foreach(explode("\n",$prompts) as $prompt)$html.='<li>'.htmlspecialchars($prompt,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</li>';$html.='</ul>';}
        $result[$slug]=['id'=>'template-'.$slug,'type'=>'resource','slug'=>$slug,'title'=>$definition['title'],'summary'=>$definition['summary'],'content_html'=>$html,'format'=>'Blank worksheet / checklist','builtin'=>true,'version'=>'2026-10-23','effort'=>'Allow 20–30 minutes for an initial draft; evidence collection takes longer.','published_at'=>null,'updated_at'=>null];
    }
    return $result;
}
function editorial_default_item(string $type): array {
    return ['title'=>'','slug'=>'','summary'=>'','content_html'=>'','format'=>'Worksheet','season_id'=>'','experiment_state'=>'planned','question'=>'','hypothesis'=>'','method'=>'','findings'=>'','decision'=>'','limitations'=>'','next_step'=>'','evidence_url'=>'','video_asset_id'=>'','thumbnail_asset_id'=>'','transcript'=>'','duration_seconds'=>'','upload_date'=>'','rights_confirmed'=>false,'published_at'=>editorial_now()];
}
function editorial_normalize_item(string $type,array $input,bool $publishing=false): array {
    if(!isset(editorial_types()[$type]))throw new EditorialValidationException('Choose a valid editorial type.');
    $v=['title'=>editorial_text($input['title']??'','Title',180,true),'summary'=>editorial_text($input['summary']??'','Summary',600,$publishing)];
    $v['slug']=editorial_slug($input['slug']??'',$v['title']);
    if($type==='resource'&&isset(editorial_templates()[$v['slug']]))throw new EditorialValidationException('That URL belongs to a built-in blank tool. Choose another slug for your own version.');
    if($type==='resource'){
        $v['format']=editorial_text($input['format']??'Worksheet','Resource type',80,true);
        $v['content_html']=sanitize_html(editorial_text($input['content_html']??'','Resource contents',100000));
        if($publishing&&mb_strlen(plain_text($v['content_html']))<40)throw new EditorialValidationException('Write the useful resource contents before publishing.');
    }elseif($type==='experiment'){
        $v['season_id']=editorial_id($input['season_id']??null,true);
        if($v['season_id']&&!journeys_writer_season($v['season_id']))throw new EditorialValidationException('Choose an existing Startup Journey.');
        if($publishing&&!$v['season_id'])throw new EditorialValidationException('Link the experiment to a Startup Journey before publishing.');
        $state=$input['experiment_state']??'planned';if(!is_string($state)||!isset(editorial_experiment_states()[$state]))throw new EditorialValidationException('Choose a valid experiment state.');$v['experiment_state']=$state;
        foreach(['question','hypothesis','method','findings','decision','limitations','next_step'] as $key)$v[$key]=editorial_text($input[$key]??'',ucfirst(str_replace('_',' ',$key)),5000,$publishing&&in_array($key,['question','method'],true));
        if($publishing&&in_array($state,['concluded','inconclusive','stopped'],true)&&($v['findings']===''||$v['decision']===''))throw new EditorialValidationException('A finished experiment needs its actual findings and decision, including uncertainty or a decision to stop.');
        $v['evidence_url']=editorial_text($input['evidence_url']??'','Public evidence URL',2000);
        if($v['evidence_url']!==''&&!editorial_public_url($v['evidence_url']))throw new EditorialValidationException('Use a public HTTPS evidence URL without private or signed-access tokens.');
    }else{
        $v['video_asset_id']=editorial_id($input['video_asset_id']??null,true);$v['thumbnail_asset_id']=editorial_id($input['thumbnail_asset_id']??null,true);
        $v['transcript']=editorial_text($input['transcript']??'','Transcript',200000,$publishing);
        $duration=editorial_text($input['duration_seconds']??'','Duration',6);
        if($duration!==''&&(!ctype_digit($duration)||(int)$duration<1||(int)$duration>86400))throw new EditorialValidationException('Enter the actual duration in whole seconds, from 1 to 86400.');
        $v['duration_seconds']=$duration===''?null:(int)$duration;
        $upload=editorial_text($input['upload_date']??'','Video upload date',19);$v['upload_date']=$upload===''?'':editorial_datetime($upload,'video upload date');
        if($v['upload_date']!==''&&$v['upload_date']>editorial_now())throw new EditorialValidationException('Use the actual video upload date, not a future date.');
        $v['rights_confirmed']=editorial_checked($input['rights_confirmed']??false);
        if($publishing){
            if(!$v['rights_confirmed']||!$v['video_asset_id']||!$v['thumbnail_asset_id']||!$v['duration_seconds']||!$v['upload_date']||mb_strlen($v['transcript'])<40)throw new EditorialValidationException('Complete the owned video, thumbnail, transcript, actual duration/date and publication permission before publishing.');
            $v['video_asset']=media_library_approved_asset($v['video_asset_id']);$v['thumbnail_asset']=media_library_approved_asset($v['thumbnail_asset_id']);
            if(!in_array($v['video_asset']['mime'],['video/mp4','video/webm'],true)||!str_starts_with($v['thumbnail_asset']['mime'],'image/'))throw new EditorialValidationException('Choose an MP4 or WebM video and an image thumbnail from the media library.');
            foreach(['video_asset','thumbnail_asset'] as $key){if(($v[$key]['visibility']??'')!=='public'||!editorial_public_url((string)$v[$key]['public_url']))throw new EditorialValidationException('A watch page needs explicitly public media with a stable HTTPS URL. Private uploads stay private.');media_library_validate_public_asset($v[$key],$key==='video_asset'?media_library_max_bytes():media_library_image_max_bytes());}
        }
    }
    return $v;
}
function editorial_writer_item(int $id): ?array {$q=db()->prepare('SELECT * FROM editorial_items WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
function editorial_row_version(mixed $value): int {
    if((!is_string($value)&&!is_int($value))||!preg_match('/^(?:0|[1-9][0-9]{0,9})$/D',(string)$value))throw new EditorialConflictException('Reload the saved version before making another change.');return (int)$value;
}
function editorial_sync_discovery(): void {
    if(!function_exists('discovery_sync_public_urls'))return;
    try{discovery_sync_public_urls();}catch(Throwable $e){error_log('Editorial discovery reconciliation deferred: '.get_class($e));}
}
function editorial_save_item(string $type,array $input,?int $id,int $actor,string $action='draft'): int {
    if(!in_array($action,['draft','publish','archive'],true))throw new EditorialValidationException('Choose a valid editorial action.');
    $expected=editorial_row_version($input['row_version']??0);
    if($action==='publish'&&!editorial_checked($input['publish_ack']??false))throw new EditorialValidationException('Confirm that this exact content and its public evidence may be published.');
    if($action==='archive'&&!$id)throw new EditorialValidationException('Save the item before archiving it.');
    $v=$action==='archive'?null:editorial_normalize_item($type,$input,$action==='publish');
    $now=editorial_now();$pdo=db();$pdo->beginTransaction();
    try{
        $old=null;if($id){$q=$pdo->prepare('SELECT * FROM editorial_items WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();if(!$old||$old['item_type']!==$type)throw new EditorialValidationException('The editorial item is not available.');if((int)$old['row_version']!==$expected)throw new EditorialConflictException('This item changed in another window. Reload it before saving or publishing.');}
        elseif($expected!==0)throw new EditorialConflictException('Reload the new-item form.');
        if($action==='archive'){$pdo->prepare("UPDATE editorial_items SET publication_status='archived',row_version=row_version+1,updated_by=?,updated_at=? WHERE id=?")->execute([$actor,$now,$id]);}
        else{
            if($old&&!empty($old['public_json'])&&$old['slug']!==$v['slug'])throw new EditorialValidationException('A previously published URL is permanent. Keep its existing slug.');
            $status=$old['publication_status']??'draft';$published=$old['published_at']??null;$modified=$old['public_modified_at']??null;$public=$old['public_json']??null;$journey=$old['public_journey_id']??null;
            if($action==='publish'){
                $published=($old&&$old['published_at']&&$old['published_at']<=$now)?$old['published_at']:editorial_datetime($input['published_at']??$now);
                $next=editorial_json($v);if($public!==$next)$modified=max($now,$published);$public=$next;$status='published';$journey=$type==='experiment'?$v['season_id']:null;
            }
            $draft=$v;unset($draft['video_asset'],$draft['thumbnail_asset']);$draft['published_at']=$published??editorial_datetime($input['published_at']??$now);
            if($old)$pdo->prepare('UPDATE editorial_items SET slug=?,draft_json=?,public_json=?,publication_status=?,published_at=?,public_modified_at=?,public_journey_id=?,row_version=row_version+1,updated_by=?,updated_at=? WHERE id=?')->execute([$v['slug'],editorial_json($draft),$public,$status,$published,$modified,$journey,$actor,$now,$id]);
            else{$pdo->prepare('INSERT INTO editorial_items(item_type,slug,draft_json,public_json,publication_status,published_at,public_modified_at,public_journey_id,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$type,$v['slug'],editorial_json($draft),$public,$status,$published,$modified,$journey,$actor,$actor,$now,$now]);$id=(int)$pdo->lastInsertId();}
        }
        journeys_audit('editorial_item',(int)$id,$action,$actor);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException&&$e->getCode()==='23000')throw new EditorialValidationException('That URL is already in use, or a linked record changed. Reload and choose an available record.');throw $e;}
    if($action!=='draft')editorial_sync_discovery();return (int)$id;
}

/** Metadata checks are local/DB-only on public requests; publishing has already verified owned bytes. */
function editorial_asset_current(array $snapshot,string $kind): bool {
    if(!is_int($snapshot['id']??null)||($snapshot['visibility']??'')!=='public'||!editorial_public_url((string)($snapshot['public_url']??'')))return false;
    $asset=media_library_asset($snapshot['id']);if(!$asset||!in_array($asset['status'],['ready','archived'],true)||$asset['kind']!==$kind)return false;
    if(media_library_snapshot($asset)!==$snapshot)return false;
    if($asset['driver']!==(string)app_config('media.driver','local'))return false;
    if($asset['driver']==='s3'&&$asset['bucket']!==(string)app_config('media.bucket',''))return false;
    try{if(media_library_public_url($snapshot['key'])!==$snapshot['public_url'])return false;if($asset['driver']==='local')media_library_local_path($asset);}catch(Throwable){return false;}
    return true;
}
function editorial_watch_valid(array $item): bool {
    return ($item['rights_confirmed']??false)===true&&is_string($item['transcript']??null)&&mb_strlen($item['transcript'])>=40
        &&is_int($item['duration_seconds']??null)&&$item['duration_seconds']>0&&$item['duration_seconds']<=86400
        &&is_string($item['upload_date']??null)&&$item['upload_date']!==''&&$item['upload_date']<=editorial_now()
        &&is_array($item['video_asset']??null)&&in_array($item['video_asset']['mime']??'', ['video/mp4','video/webm'],true)&&editorial_asset_current($item['video_asset'],'video')
        &&is_array($item['thumbnail_asset']??null)&&editorial_asset_current($item['thumbnail_asset'],'image');
}
function editorial_public_projection(array $row): ?array {
    $snapshot=editorial_decode($row['public_json']??null);if(empty($snapshot['title'])||empty($snapshot['summary']))return null;
    if($row['item_type']==='watch'&&!editorial_watch_valid($snapshot))return null;
    return array_merge($snapshot,['id'=>(int)$row['id'],'type'=>$row['item_type'],'slug'=>$row['slug'],'published_at'=>$row['published_at'],'updated_at'=>$row['public_modified_at'],'builtin'=>false]);
}
function editorial_public_items(string $type,int $limit=1000): array {
    if(!in_array($type,['resource','watch'],true))throw new EditorialValidationException('Choose a public editorial collection.');
    $q=db()->prepare("SELECT id,item_type,slug,public_json,published_at,public_modified_at FROM editorial_items WHERE item_type=? AND publication_status='published' AND published_at IS NOT NULL AND published_at<=? ORDER BY published_at DESC,id DESC".($limit>0?' LIMIT '.min(10000,$limit):''));$q->execute([$type,editorial_now()]);
    $result=[];while($row=$q->fetch()){if($item=editorial_public_projection($row))$result[]=$item;}return $result;
}
function editorial_public_item(string $type,string $slug): ?array {
    if($type==='resource'&&isset(editorial_templates()[$slug]))return editorial_templates()[$slug];
    if(!in_array($type,['resource','watch'],true))return null;
    $q=db()->prepare("SELECT id,item_type,slug,public_json,published_at,public_modified_at FROM editorial_items WHERE item_type=? AND slug=? AND publication_status='published' AND published_at IS NOT NULL AND published_at<=? LIMIT 1");$q->execute([$type,$slug,editorial_now()]);$row=$q->fetch();return $row?editorial_public_projection($row):null;
}
function editorial_public_experiments(int $seasonId): array {
    $q=db()->prepare("SELECT i.id,i.item_type,i.slug,i.public_json,i.published_at,i.public_modified_at FROM editorial_items i JOIN journey_seasons s ON s.id=i.public_journey_id WHERE i.item_type='experiment' AND i.public_journey_id=? AND i.publication_status='published' AND i.published_at IS NOT NULL AND i.published_at<=? AND s.publication_status='published' AND s.published_at IS NOT NULL AND s.published_at<=? ORDER BY i.published_at DESC,i.id DESC");$q->execute([$seasonId,editorial_now(),editorial_now()]);$result=[];
    while($row=$q->fetch())if($item=editorial_public_projection($row))$result[]=$item;return $result;
}
function editorial_download_text(array $item): string {
    $html=sanitize_html((string)($item['content_html']??''));$html=preg_replace('~<(?:br|hr)\s*/?>|</(?:p|h2|h3|h4|li|blockquote|pre|figure)>~i',"\n\n",$html)??$html;
    $body=trim(html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    return $item['title']."\n".str_repeat('=',min(80,mb_strlen($item['title'])))."\n\n".$item['summary']."\n\n".$body."\n\nSource: ".url('/resources/'.$item['slug'])."\n";
}
/** Uncapped full public inventory for accurate unified search counts and pagination. */
function editorial_search_documents(): array {
    $documents=[];$resources=array_merge(array_values(editorial_templates()),editorial_public_items('resource',0));
    foreach(array_merge($resources,editorial_public_items('watch',0)) as $item){$video=$item['type']==='watch';$documents[]=['id'=>($video?'watch:':'resource:').$item['id'],'type'=>$video?'video':'resource','title'=>$item['title'],'summary'=>$item['summary'],'body'=>$video?$item['transcript']:plain_text($item['content_html']),'url'=>url(($video?'/watch/':'/resources/').$item['slug']),'published_at'=>$item['published_at'],'updated_at'=>$item['updated_at'],'builtin'=>(bool)($item['builtin']??false),'public_hash'=>hash('sha256',editorial_json($item))];}
    return $documents;
}
function editorial_sitemap_entries(): array {
    $entries=[['loc'=>url('/professional-information')],['loc'=>url('/start-here')],['loc'=>url('/resources')],['loc'=>url('/watch')]];
    foreach(editorial_search_documents() as $document){$entry=['loc'=>$document['url']];if($document['updated_at'])$entry['lastmod']=date(DATE_ATOM,strtotime($document['updated_at']));$entries[]=$entry;}return $entries;
}
function editorial_watch_schema(array $item): array {
    if(($item['type']??'')!=='watch'||!editorial_watch_valid($item))return [];
    return ['@context'=>'https://schema.org','@type'=>'VideoObject','@id'=>url('/watch/'.$item['slug']).'#video','name'=>$item['title'],'description'=>$item['summary'],'thumbnailUrl'=>[$item['thumbnail_asset']['public_url']],'uploadDate'=>date(DATE_ATOM,strtotime($item['upload_date'])),'duration'=>'PT'.$item['duration_seconds'].'S','contentUrl'=>$item['video_asset']['public_url'],'transcript'=>$item['transcript'],'mainEntityOfPage'=>url('/watch/'.$item['slug'])];
}

/** Add only generated, unique heading anchors after the site's existing sanitizer. */
function editorial_toc(string $html): array {
    $safe=sanitize_html($html);$document=new DOMDocument('1.0','UTF-8');$before=libxml_use_internal_errors(true);
    try{$document->loadHTML('<?xml encoding="UTF-8"><div id="editorial-toc-root">'.$safe.'</div>',LIBXML_NONET|LIBXML_HTML_NOIMPLIED|LIBXML_HTML_NODEFDTD);$root=$document->getElementById('editorial-toc-root');if(!$root)return ['html'=>$safe,'items'=>[]];$xpath=new DOMXPath($document);$items=[];$used=[];
        foreach($xpath->query('.//h2|.//h3',$root) as $heading){$label=trim(preg_replace('/\s+/u',' ',$heading->textContent)??'');if($label==='')continue;$slug=trim(preg_replace('/[^a-z0-9]+/','-',strtolower($label))??'','-');$base='article-section-'.($slug!==''?substr($slug,0,80):'heading');$n=1;$id=$base;while(isset($used[$id]))$id=$base.'-'.(++$n);$used[$id]=true;$heading->setAttribute('id',$id);if(count($items)<100)$items[]=['id'=>$id,'label'=>mb_substr($label,0,200),'level'=>$heading->tagName==='h2'?2:3];}
        $output='';foreach($root->childNodes as $node)$output.=$document->saveHTML($node);return ['html'=>$output,'items'=>$items];
    }finally{libxml_clear_errors();libxml_use_internal_errors($before);}
}

function editorial_post_draft(int $postId): array {
    $q=db()->prepare('SELECT draft_json,row_version FROM editorial_post_meta WHERE post_id=?');$q->execute([$postId]);$row=$q->fetch();return ['draft'=>$row?editorial_decode($row['draft_json']):['evidence'=>[],'update_kind'=>'none','update_note'=>''],'row_version'=>$row?(int)$row['row_version']:0];
}
function editorial_normalize_evidence(array $input): array {
    $raw=$input['evidence']??[];if(!is_array($raw)||count($raw)>12)throw new EditorialValidationException('Use at most 12 public evidence cards.');$cards=[];
    foreach($raw as $card){if(!is_array($card))throw new EditorialValidationException('An evidence card is not valid.');$label=editorial_text($card['label']??'','Evidence label',180);$summary=editorial_text($card['summary']??'','Evidence summary',3000);$link=editorial_text($card['url']??'','Public evidence URL',2000);$limits=editorial_text($card['limitations']??'','Evidence limitations',2000);$date=editorial_text($card['observed_on']??'','Evidence date',10);
        if($label===''&&$summary===''&&$link===''&&$limits===''&&$date==='')continue;
        if($label===''||$summary==='')throw new EditorialValidationException('Each public evidence card needs a label and summary.');
        $basis=$card['basis']??'';if(!is_string($basis)||!isset(editorial_evidence_bases()[$basis]))throw new EditorialValidationException('Choose the basis of each evidence card.');
        if($link!==''&&!editorial_public_url($link))throw new EditorialValidationException('Use a public HTTPS evidence URL without private, preview or signed-access tokens.');
        if($date!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date||$date>date('Y-m-d'))throw new EditorialValidationException('Use an actual, valid observation date.');}
        $cards[]=['label'=>$label,'basis'=>$basis,'summary'=>$summary,'url'=>$link,'limitations'=>$limits,'observed_on'=>$date];
    }
    $kind=$input['update_kind']??'none';if(!is_string($kind)||!in_array($kind,['none','update','correction'],true))throw new EditorialValidationException('Choose a valid update type.');$note=editorial_text($input['update_note']??'','Public update note',2000);
    if(($kind==='none')!==($note===''))throw new EditorialValidationException('Choose Update or Correction and explain the meaningful change, or leave both fields empty.');
    return ['evidence'=>$cards,'update_kind'=>$kind,'update_note'=>$note];
}
function editorial_save_post_meta(int $postId,array $input,int $actor,string $action='draft'): void {
    if(!in_array($action,['draft','publish'],true))throw new EditorialValidationException('Choose Save draft or Publish reviewed evidence.');
    if($action==='publish'&&!editorial_checked($input['publish_ack']??false))throw new EditorialValidationException('Confirm that these exact evidence cards and the update note are suitable for public release.');
    $draft=editorial_normalize_evidence($input);$expected=editorial_row_version($input['row_version']??0);$pdo=db();$pdo->beginTransaction();
    try{
        $q=$pdo->prepare('SELECT id,status,published_at FROM posts WHERE id=? FOR UPDATE');$q->execute([$postId]);$post=$q->fetch();if(!$post)throw new EditorialValidationException('The article is not available.');
        $q=$pdo->prepare('SELECT row_version,public_json FROM editorial_post_meta WHERE post_id=? FOR UPDATE');$q->execute([$postId]);$old=$q->fetch();if((int)($old['row_version']??0)!==$expected)throw new EditorialConflictException('These notes changed in another window. Reload the saved version.');
        $public=$old['public_json']??null;
        if($action==='publish'){
            if(function_exists('founder_story_workflow')){$founderCheck=$pdo->prepare('SELECT id FROM founder_submissions WHERE associated_post_id=? LIMIT 1');$founderCheck->execute([$postId]);if($founderCheck->fetchColumn())throw new EditorialValidationException('Founder Story evidence and correction changes must be included in the article draft and sent through final-copy founder approval. Separate evidence-card publishing is locked for these stories.');}
            if(!journeys_post_is_public($post))throw new EditorialValidationException('Publish the article first. Public evidence and correction notes can be released once the article is publicly available.');
            $next=editorial_json(['evidence'=>$draft['evidence']]);
            if($public!==null&&$public!==$next&&$draft['update_note']==='')throw new EditorialValidationException('Explain the meaningful change when replacing already-public evidence cards.');
            $public=$next;
            if($draft['update_kind']!=='none'){$pdo->prepare('INSERT INTO editorial_post_updates(post_id,update_kind,note,published_at,created_by) VALUES(?,?,?,?,?)')->execute([$postId,$draft['update_kind'],$draft['update_note'],editorial_now(),$actor]);$draft['update_kind']='none';$draft['update_note']='';}
        }
        if($old)$pdo->prepare('UPDATE editorial_post_meta SET draft_json=?,public_json=?,row_version=row_version+1,updated_by=?,updated_at=? WHERE post_id=?')->execute([editorial_json($draft),$public,$actor,editorial_now(),$postId]);
        else $pdo->prepare('INSERT INTO editorial_post_meta(post_id,draft_json,public_json,updated_by,updated_at) VALUES(?,?,?,?,?)')->execute([$postId,editorial_json($draft),$public,$actor,editorial_now()]);
        journeys_audit('article_evidence',$postId,$action,$actor);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($action==='publish')editorial_sync_discovery();
}
function editorial_public_post(int $postId): array {
    $q=db()->prepare("SELECT m.public_json FROM posts p LEFT JOIN editorial_post_meta m ON m.post_id=p.id WHERE p.id=? AND p.status IN ('published','scheduled') AND p.published_at IS NOT NULL AND p.published_at<=? LIMIT 1");$q->execute([$postId,editorial_now()]);$row=$q->fetch();if(!$row)return ['evidence'=>[],'updates'=>[]];
    $public=editorial_decode($row['public_json']);$q=db()->prepare('SELECT update_kind,note,published_at FROM editorial_post_updates WHERE post_id=? AND published_at<=? ORDER BY published_at DESC,id DESC');$q->execute([$postId,editorial_now()]);return ['evidence'=>is_array($public['evidence']??null)?$public['evidence']:[],'updates'=>$q->fetchAll()];
}
/** An editorial correction/update is meaningful; an autosave or private draft timestamp is not. */
function editorial_article_modified(array $post): ?string {
    if(empty($post['id'])||!journeys_post_is_public($post))return null;
    $q=db()->prepare("SELECT MAX(u.published_at) FROM editorial_post_updates u JOIN posts p ON p.id=u.post_id WHERE p.id=? AND p.status IN ('published','scheduled') AND p.published_at<=? AND u.published_at<=?");$q->execute([(int)$post['id'],editorial_now(),editorial_now()]);$time=$q->fetchColumn();return is_string($time)&&$time!==''?$time:null;
}

