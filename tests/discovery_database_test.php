<?php
declare(strict_types=1);
/** Fresh disposable schema only; synthetic API responses, never a live provider. */
if(PHP_SAPI!=='cli' || getenv('APP_ENV')!=='testing')exit(2);
require __DIR__.'/prepare.php';
$settings=wk_test_database_settings();$database=(string)(getenv('DB_DATABASE')?:getenv('WK_TEST_DB_NAME'));wk_test_assert_database_name($database);
$CONFIG=['app_env'=>'testing','base_url'=>'https://www.waithakandegwa.com','app_key'=>'local-test-key-not-for-production-use',
    'worker_enabled'=>false,'indexnow_enabled'=>false,'db'=>$settings+['name'=>$database]];$APP_INSTALLED=true;date_default_timezone_set('Africa/Nairobi');
require dirname(__DIR__).'/app/functions.php';require dirname(__DIR__).'/app/journeys.php';require dirname(__DIR__).'/app/providers/http.php';
require dirname(__DIR__).'/app/providers/oauth.php';require dirname(__DIR__).'/app/editorial.php';require dirname(__DIR__).'/app/discovery.php';
foreach(['posts','journey_seasons','editorial_items','publishing_connections','discovery_public_urls','discovery_indexnow_queue','discovery_gsc_reports']as$table)
    if((int)db()->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new RuntimeException('Discovery tests require a fresh disposable schema.');
$checks=0;$prefix='discovery-'.bin2hex(random_bytes(5));$private='PRIVATE_DISCOVERY_'.bin2hex(random_bytes(6));$postIds=[];
function discovery_db_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL: '.$label);$GLOBALS['checks']++;}
function discovery_db_reject(callable $action,string $label):void {try{$action();}catch(RuntimeException){discovery_db_check(true,$label);return;}throw new RuntimeException('FAIL: '.$label);}
function discovery_db_queue(string $url):array {$q=db()->prepare('SELECT * FROM discovery_indexnow_queue WHERE canonical_url=? ORDER BY id DESC LIMIT 1');$q->execute([$url]);return $q->fetch()?:[];}
function discovery_db_only_due(int $id):void {db()->exec("UPDATE discovery_indexnow_queue SET available_at='2099-01-01 00:00:00' WHERE status IN ('pending','retry')");db()->prepare('UPDATE discovery_indexnow_queue SET available_at=UTC_TIMESTAMP() WHERE id=?')->execute([$id]);}
db()->prepare('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)')->execute(['Discovery fixture',$prefix.'@example.invalid',password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT)]);$actor=(int)db()->lastInsertId();
db()->prepare('INSERT INTO categories(name,slug) VALUES(?,?)')->execute(['Categorical search phrase',$prefix]);$category=(int)db()->lastInsertId();
$insert=db()->prepare('INSERT INTO posts(user_id,category_id,title,slug,standfirst,content_html,writer_note,source_notes,status,published_at) VALUES(?,?,?,?,?,?,?,?,?,?)');
for($i=0;$i<65;$i++){$insert->execute([$actor,$category,$prefix.' public article '.$i,$prefix.'-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),'Public summary.','<p>A literal 100% lesson.</p>',$private,$private,$i===0?'scheduled':'published','2000-01-02 00:00:00']);$postIds[]=(int)db()->lastInsertId();}
foreach([['draft','2000-01-02 00:00:00'],['scheduled','2099-01-01 00:00:00'],['archived','2000-01-02 00:00:00'],['published',null]]as$i=>[$status,$date]){$insert->execute([$actor,$category,$private.' '.$prefix,$prefix.'-hidden-'.$i,$private,$private,$private,$private,$status,$date]);$hidden[]=(int)db()->lastInsertId();}
db()->prepare('INSERT INTO post_drafts(post_id,payload,version,updated_by) VALUES(?,?,1,?)')->execute([$postIds[0],json_encode(['title'=>$private,'content_html'=>$private],JSON_THROW_ON_ERROR),$actor]);
db()->prepare('INSERT INTO tags(name,slug) VALUES(?,?)')->execute(['Extra tag needle',$prefix]);$tag=(int)db()->lastInsertId();db()->prepare('INSERT INTO post_tags(post_id,tag_id) VALUES(?,?)')->execute([$postIds[0],$tag]);
$founder=db()->prepare('INSERT INTO founder_submissions(reference,submission_type,submitter_name,submitter_email,founder_name,venture_name,country,story_summary,metric_basis,private_evidence_notes,privacy_version,privacy_accepted_at,ip_hash,review_status,consent_reference,permission_confirmed,claims_reviewed,associated_post_id,is_public,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
for($i=0;$i<7;$i++){$founder->execute([$prefix.'-'.$i,'founder',$private,$prefix.'@example.invalid',$private,$private,'Kenya',$private,'not_applicable',$private,'test','2000-01-01 00:00:00',hash('sha256',$prefix),$i===6?'new':'approved',$i===4?'':'Synthetic consent',$i===2?0:1,$i===3?0:1,$i<2?$postIds[0]:$postIds[$i],$i===5?0:1,'2000-01-01 00:00:00','2000-01-01 00:00:00']);}
$season=db()->prepare('INSERT INTO journey_seasons(slug,title,venture_name,summary,baseline,learning_question,private_notes,phase,publication_status,published_at,created_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
$seasonIds=[];foreach([['published','2000-01-03 00:00:00'],['draft','2000-01-03 00:00:00'],['published','2099-01-01 00:00:00']]as$i=>[$status,$date]){$season->execute([$prefix.'-season-'.$i,$prefix.' journey','Synthetic journey','Public journey summary','Starting with one interview','What changes?',$private,'concluded',$status,$date,$actor,'2000-01-01 00:00:00','2000-01-03 00:00:00']);$seasonIds[]=(int)db()->lastInsertId();}
$search=discovery_search($prefix);
discovery_db_check($search['pagination']['total']===66,'Exact count includes 65 due posts and one public journey beyond the old60 cutoff.');
discovery_db_check($search['counts']['founder']===1 && $search['counts']['post']===64,'Approved duplicate submissions produce one founder card; failed consent gates are ordinary public posts.');
discovery_db_check(count(discovery_search($prefix,3)['results'])===18 && discovery_search($prefix,4)===null,'Deep search pages and out-of-range checks use the real total.');
$all=[];for($page=1;$page<=3;$page++)$all=array_merge($all,array_column(discovery_search($prefix,$page)['results'],'url'));
discovery_db_check(count(array_unique($all))===66 && $all===array_merge(array_column($search['results'],'url'),array_column(discovery_search($prefix,2)['results'],'url'),array_column(discovery_search($prefix,3)['results'],'url')),'Stable tied dates have no skipped or duplicate cards.');
discovery_db_check(discovery_search($private)['pagination']['total']===0,'Private article drafts, notes, founder identity/evidence and future pages are not searchable.');
discovery_db_check(discovery_search('Extra tag needle')['pagination']['total']===1,'Public tags are searchable.');
discovery_db_check(discovery_search('Categorical search phrase')['pagination']['total']===65,'Public topic names are searchable.');
discovery_db_check(discovery_search('100%')['pagination']['total']===65 && discovery_search('_')['pagination']['total']===0,'Search treats SQL wildcard characters literally.');
discovery_db_check(discovery_search($prefix,1,'founder')['pagination']['total']===1,'Content type filters preserve exact founder eligibility.');
discovery_db_check(discovery_search('',1)['pagination']['total']===0 && discovery_search('',2)===null,'The empty search has one empty page.');
discovery_db_check(!str_contains(json_encode($search,JSON_THROW_ON_ERROR),$private),'Returned cards contain no private content.');

// Real editorial public snapshots, including more than the old helper default1000.
$resourceInsert=db()->prepare('INSERT INTO editorial_items(item_type,slug,draft_json,public_json,publication_status,published_at,public_modified_at,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
db()->beginTransaction();for($i=0;$i<1005;$i++){$snapshot=['title'=>$prefix.' resource '.$i,'summary'=>'A public resource summary.','content_html'=>'<p>A practical checklist.</p>','format'=>'Worksheet'];$resourceInsert->execute(['resource',$prefix.'-resource-'.$i,json_encode(['title'=>$private],JSON_THROW_ON_ERROR),json_encode($snapshot,JSON_THROW_ON_ERROR),'published','2000-01-04 00:00:00','2000-01-04 00:00:00',$actor,$actor,'2000-01-01 00:00:00','2000-01-01 00:00:00']);}db()->commit();
foreach([['draft','2000-01-04 00:00:00'],['published','2099-01-01 00:00:00'],['published',null]]as$i=>[$status,$date])$resourceInsert->execute(['resource',$prefix.'-resource-hidden-'.$i,json_encode(['title'=>$private]),json_encode(['title'=>$private.' '.$prefix,'summary'=>$private,'content_html'=>$private]),$status,$date,$date,$actor,$actor,'2000-01-01 00:00:00','2000-01-01 00:00:00']);
discovery_db_check(discovery_search($prefix,1,'resource')['pagination']['total']===1005,'Resource search uses uncapped actual public editorial snapshots.');
discovery_db_check(count(discovery_search($prefix,11,'resource',100)['results'])===5,'The final resource page remains reachable after1000 items.');
discovery_db_check(discovery_search($private)['pagination']['total']===0,'Resource working drafts, future dates and null dates remain private.');
$templateSearch=discovery_search('Problem interview worksheet',1,'resource');
discovery_db_check($templateSearch['pagination']['total']===1 && $templateSearch['results'][0]['published_at']==='','A genuine blank built-in tool is searchable without a fabricated publication date.');

$sync=discovery_sync_public_urls();$queueCount=(int)db()->query('SELECT COUNT(*) FROM discovery_indexnow_queue')->fetchColumn();
discovery_db_check($sync['added']>1000 && $queueCount===$sync['added'],'Every actually public URL gets one initial durable revision.');
$second=discovery_sync_public_urls();discovery_db_check($second['added']===0 && $second['updated']===0 && $second['deleted']===0 && (int)db()->query('SELECT COUNT(*) FROM discovery_indexnow_queue')->fetchColumn()===$queueCount,'Unchanged inventories do not create duplicate notifications.');
discovery_db_check((int)db()->query('SELECT SUM(attempt_count) FROM discovery_indexnow_queue')->fetchColumn()===0,'Queue creation is not misreported as a network attempt.');
discovery_db_check(!str_contains(json_encode(db()->query('SELECT * FROM discovery_public_urls')->fetchAll(),JSON_THROW_ON_ERROR),$private),'Public inventory cannot retain private content/evidence.');
// Public child records can change without touching the parent timestamp.
$evidenceUrl=url('/my-thoughts/'.$prefix.'-003');$beforeEvidence=discovery_db_queue($evidenceUrl);
db()->prepare('INSERT INTO editorial_post_meta(post_id,draft_json,public_json,updated_by,updated_at) VALUES(?,?,?,?,?)')->execute([$postIds[3],json_encode(['evidence'=>[['summary'=>$private]]]),null,$actor,'2000-01-01 00:00:00']);
discovery_sync_public_urls();discovery_db_check(discovery_db_queue($evidenceUrl)['revision']===$beforeEvidence['revision'],'Private evidence drafts do not create public revisions.');
$card=['basis'=>'records','label'=>'Observed evidence','summary'=>'Public evidence needle','observed_on'=>'2000-01-02','limitations'=>'A small sample.','url'=>''];
db()->prepare('UPDATE editorial_post_meta SET public_json=? WHERE post_id=?')->execute([json_encode(['evidence'=>[$card]]),$postIds[3]]);discovery_sync_public_urls();$afterEvidence=discovery_db_queue($evidenceUrl);
discovery_db_check((int)$afterEvidence['revision']===(int)$beforeEvidence['revision']+1 && discovery_search('Public evidence needle')['pagination']['total']===1,'Published source cards update the article fingerprint and search text without a parent save.');
db()->prepare('INSERT INTO editorial_post_updates(post_id,update_kind,note,published_at,created_by) VALUES(?,?,?,?,?)')->execute([$postIds[3],'correction',$private,'2099-01-01 00:00:00',$actor]);discovery_sync_public_urls();
discovery_db_check(discovery_db_queue($evidenceUrl)['revision']===$afterEvidence['revision'] && discovery_search($private)['pagination']['total']===0,'Future corrections remain outside search and notifications.');
db()->prepare('INSERT INTO editorial_post_updates(post_id,update_kind,note,published_at,created_by) VALUES(?,?,?,?,?)')->execute([$postIds[3],'correction','Visible correction needle','2000-01-04 00:00:00',$actor]);discovery_sync_public_urls();
discovery_db_check((int)discovery_db_queue($evidenceUrl)['revision']===(int)$afterEvidence['revision']+1 && discovery_search('Visible correction needle')['pagination']['total']===1,'Due meaningful corrections affect public discovery immediately.');
$journeyUrl=url('/startup-journeys/'.$prefix.'-season-0');$beforeExperiment=discovery_db_queue($journeyUrl);
$experiment=['title'=>'Observed experiment','summary'=>'A public experiment.','question'=>'Experiment question needle','method'=>'Ask and record.','experiment_state'=>'planned'];
db()->prepare('INSERT INTO editorial_items(item_type,slug,draft_json,public_json,publication_status,published_at,public_modified_at,public_journey_id,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['experiment',$prefix.'-experiment',json_encode(['question'=>$private]),json_encode($experiment),'published','2000-01-04 00:00:00','2000-01-04 00:00:00',$seasonIds[0],$actor,$actor,'2000-01-01 00:00:00','2000-01-01 00:00:00']);$experimentId=(int)db()->lastInsertId();discovery_sync_public_urls();
discovery_db_check((int)discovery_db_queue($journeyUrl)['revision']===(int)$beforeExperiment['revision']+1 && discovery_search('Experiment question needle')['counts']['journey']===1,'Published experiments update their public journey fingerprint and search text.');
db()->prepare("UPDATE editorial_items SET publication_status='archived' WHERE id=?")->execute([$experimentId]);discovery_sync_public_urls();discovery_db_check(discovery_search('Experiment question needle')['pagination']['total']===0,'Archived experiments are removed from public search.');
$resourceUrl=url('/resources/'.$prefix.'-resource-0');$beforeResource=discovery_db_queue($resourceUrl);
db()->prepare('UPDATE editorial_items SET public_json=? WHERE item_type=\'resource\' AND slug=?')->execute([json_encode(['title'=>$prefix.' resource 0','summary'=>'A public resource summary.','content_html'=>'<p>A practical <strong>checklist.</strong></p>','format'=>'Worksheet']),$prefix.'-resource-0']);discovery_sync_public_urls();
discovery_db_check((int)discovery_db_queue($resourceUrl)['revision']===(int)$beforeResource['revision']+1,'The whole public resource hash detects same-second HTML changes that leave plain text unchanged.');
discovery_db_check(discovery_notify_public_url(url('/my-thoughts/'.$prefix.'-never-public'),'deleted')===null,'An arbitrary never-public deletion cannot enter the notification queue.');
discovery_db_reject(fn()=>discovery_notify_public_url(url('/newsletter/confirm?token=secret')),'Private capability URLs are rejected at enqueue.');
db()->beginTransaction();discovery_db_reject(fn()=>discovery_sync_public_urls(),'Inventory reconciliation cannot nest inside a publication transaction.');db()->rollBack();
$target=url('/my-thoughts/'.$prefix.'-000');$old=discovery_db_queue($target);
db()->prepare('UPDATE posts SET standfirst=? WHERE id=?')->execute(['Changed public summary.',$postIds[0]]);discovery_sync_public_urls();$new=discovery_db_queue($target);
discovery_db_check((int)$new['revision']===(int)$old['revision']+1 && $new['event_type']==='updated','A public content change gets its own immutable URL revision.');
$query=db()->prepare('SELECT status FROM discovery_indexnow_queue WHERE id=?');$query->execute([$old['id']]);discovery_db_check($query->fetchColumn()==='cancelled','An unsent superseded revision is cancelled.');
$calls=0;$ok=static function(array $payload)use(&$calls):array{$calls++;discovery_db_check($payload['host']==='www.waithakandegwa.com' && count($payload['urlList'])===1 && str_starts_with($payload['keyLocation'],'https://www.waithakandegwa.com/'),'The protocol envelope binds to the canonical host and one public URL.');return ['status'=>200];};
discovery_db_check(discovery_work(1,$ok)['enabled']===false && $calls===0,'Default settings keep the notification transport offline.');
discovery_indexnow_save(true,false,$actor);$CONFIG['worker_enabled']=true;
discovery_db_check(discovery_work(1,$ok)['enabled']===false && $calls===0,'Writer opt-in cannot override a paused environment gate.');
$CONFIG['indexnow_enabled']=true;
discovery_db_check(discovery_work(1)['enabled']===false && $calls===0,'Testing without a fixture can never contact IndexNow.');
discovery_db_only_due((int)$new['id']);$worked=discovery_work(1,$ok);$receipt=discovery_db_queue($target);
discovery_db_check($worked['accepted']===1 && $calls===1 && $receipt['status']==='accepted' && $receipt['accepted_at']!==null && (int)$receipt['last_http_status']===200,'HTTP200 becomes a real accepted receipt with completion time.');
discovery_work(1,$ok);discovery_db_check($calls===1,'An accepted unchanged revision is never sent again.');

$secondTarget=url('/my-thoughts/'.$prefix.'-001');$job=discovery_db_queue($secondTarget);discovery_db_only_due((int)$job['id']);
discovery_work(1,fn()=>['status'=>202]);$job=discovery_db_queue($secondTarget);discovery_db_check($job['status']==='verification_pending' && (int)$job['last_http_status']===202,'HTTP202 is pending key verification, not indexed or published.');
discovery_retry_notification((int)$job['id'],$actor);discovery_db_only_due((int)$job['id']);discovery_work(1,fn()=>['status'=>429,'retry_after'=>'600']);$job=discovery_db_queue($secondTarget);
discovery_db_check($job['status']==='retry' && strtotime($job['available_at'].' UTC')>=time()+595,'Rate limits persist a delayed retry.');
$before=(int)$job['attempt_count'];discovery_work(1,fn()=>throw new RuntimeException('Must not call'));discovery_db_check((int)discovery_db_queue($secondTarget)['attempt_count']===$before,'A delayed notification cannot run early.');
discovery_db_only_due((int)$job['id']);discovery_work(1,fn()=>['status'=>403]);$job=discovery_db_queue($secondTarget);
discovery_db_check($job['status']==='rejected' && $job['error_code']==='invalid_key','An invalid key stops automatic retries for review.');
discovery_retry_notification((int)$job['id'],$actor);discovery_db_only_due((int)$job['id']);discovery_work(1,fn()=>['status'=>200]);$job=discovery_db_queue($secondTarget);
discovery_db_check((int)$job['attempt_count']===4 && $job['status']==='accepted','An explicit recheck retains all prior attempt numbers.');
$receipts=db()->prepare('SELECT result,http_status FROM discovery_indexnow_attempts WHERE queue_id=? ORDER BY attempt_number');$receipts->execute([$job['id']]);
discovery_db_check(array_column($receipts->fetchAll(),'result')===['verification_pending','retry','rejected','accepted'],'Attempt history distinguishes received, delayed, rejected and accepted outcomes.');

db()->prepare("UPDATE posts SET status='archived' WHERE id=?")->execute([$postIds[0]]);discovery_sync_public_urls();$deleted=discovery_db_queue($target);
discovery_db_check($deleted['event_type']==='deleted' && (int)$deleted['revision']>(int)$new['revision'],'A formerly public archived URL becomes a deletion tombstone.');
discovery_db_only_due((int)$deleted['id']);$deletePayload=null;discovery_work(1,static function($payload)use(&$deletePayload){$deletePayload=$payload;return ['status'=>200];});
discovery_db_check($deletePayload['urlList']===[$target] && discovery_db_queue($target)['status']==='accepted','The worker can notify removal of a genuinely formerly public URL.');
db()->prepare("UPDATE posts SET published_at='2000-01-02 00:00:00' WHERE id=?")->execute([$hidden[1]]);discovery_sync_public_urls();
discovery_db_check(discovery_db_queue(url('/my-thoughts/'.$prefix.'-hidden-1'))['event_type']==='added','A due scheduled release is found without an editor save hook.');
$unknownTarget=url('/my-thoughts/'.$prefix.'-002');$unknown=discovery_db_queue($unknownTarget);discovery_db_only_due((int)$unknown['id']);discovery_work(1,fn()=>throw new RuntimeException('SYNTHETIC_SENSITIVE_PROVIDER_BODY'));
$unknown=discovery_db_queue($unknownTarget);discovery_db_check($unknown['status']==='retry' && $unknown['last_http_status']===null && $unknown['error_code']==='incomplete_response','Response loss retains an unknown receipt and safe retry without storing provider bodies.');
$CONFIG['worker_enabled']=false;$called=false;discovery_work(1,static function()use(&$called){$called=true;return ['status'=>200];});discovery_db_check(!$called,'Pausing the worker stops queued transport work.');

$resource='sc-domain:waithakandegwa.com';$resources=[['id'=>$resource,'type'=>'site','name'=>$resource],['id'=>'https://www.waithakandegwa.com/','type'=>'site','name'=>'URL prefix']];
$scopes=['openid','https://www.googleapis.com/auth/webmasters.readonly'];
db()->prepare("INSERT INTO publishing_connections(service,provider,status,external_id,display_name,credentials_encrypted,scopes_json,resources_json,selected_resource,verified_at,connected_by) VALUES('google_search_console','google','connected','synthetic-google-user','Synthetic Google',?,?,?,?,UTC_TIMESTAMP(),?)")
    ->execute([encrypt_secret(json_encode(['access_token'=>'SYNTHETIC_PRIVATE_TOKEN'])),json_encode($scopes),json_encode($resources),$resource,$actor]);
$requestCount=0;$fixture=static function(string $endpoint,array $body)use(&$requestCount):array {
    $requestCount++;discovery_db_check($endpoint==='https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Awaithakandegwa.com/searchAnalytics/query','Google request targets the confirmed property in a fixed documented endpoint.');
    discovery_db_check($body['dataState']==='final' && $body['type']==='web' && $body['aggregationType']==='byProperty','Only finalized property-level web-search data is requested.');
    $current=$requestCount%2===1;$keys=$body['dimensions']===[]?[]:[$body['dimensions'][0].($current?'-current':'-previous')];
    return ['rows'=>[['keys'=>$keys,'clicks'=>$keys===[]?($current?500:400):5,'impressions'=>$keys===[]?5000:50,'ctr'=>0.1,'position'=>4.0]]];
};
$reportId=discovery_gsc_refresh(28,$actor,$fixture);$saved=discovery_gsc_latest(28);
discovery_db_check($requestCount===8 && (int)$saved['success']['id']===$reportId,'One complete report saves all eight documented period/dimension reads.');
discovery_db_check($saved['success']['data']['totals']['current']['clicks']==500 && $saved['success']['data']['totals']['previous']['clicks']==400,'Property totals come from their own requests, not a sum of truncated query rows.');
discovery_db_check($saved['success']['data']['dimensions']['query'][0]['previous']===null,'A current-only query is not invented as zero in the previous period.');
discovery_db_check(!str_contains(json_encode($saved,JSON_THROW_ON_ERROR),'SYNTHETIC_PRIVATE_TOKEN') && !isset($saved['connection']['credentials_encrypted']),'Report projections never include provider credentials.');
discovery_db_reject(fn()=>discovery_gsc_refresh(28,$actor,fn()=>throw new RuntimeException('SYNTHETIC_SENSITIVE_PROVIDER_BODY')),'Provider failures become explicit failed report attempts.');
$failed=discovery_gsc_latest(28);discovery_db_check($failed['latest']['status']==='failed' && (int)$failed['success']['id']===$reportId && $failed['latest']['payload_json']===null,'A failed refresh preserves the last successful snapshot instead of fabricating zero data.');
discovery_db_check(!str_contains(json_encode(db()->query('SELECT * FROM discovery_gsc_reports')->fetchAll(),JSON_THROW_ON_ERROR),'SYNTHETIC_SENSITIVE_PROVIDER_BODY'),'Report failures never retain raw provider error bodies.');
$requestCount=0;$changed=false;
discovery_db_reject(fn()=>discovery_gsc_refresh(28,$actor,static function($endpoint,$body)use(&$changed,$fixture){if(!$changed){db()->exec("UPDATE publishing_connections SET selected_resource='https://www.waithakandegwa.com/' WHERE service='google_search_console'");$changed=true;}return $fixture($endpoint,$body);}), 'A changed confirmed property invalidates a report in flight.');
discovery_db_check(discovery_gsc_latest(28)['success']===null,'Old property reports cannot be shown as the newly selected property.');
db()->exec("UPDATE publishing_connections SET status='pending_confirmation' WHERE service='google_search_console'");$callsBefore=$requestCount;
discovery_db_reject(fn()=>discovery_gsc_refresh(28,$actor,$fixture),'An unconfirmed Google connection cannot read reports.');
discovery_db_check($requestCount===$callsBefore,'Connection rejection happens before any request.');
$summary=discovery_operations_summary();discovery_db_check(!str_contains(json_encode($summary,JSON_THROW_ON_ERROR),'SYNTHETIC_PRIVATE_TOKEN') && !str_contains(json_encode($summary,JSON_THROW_ON_ERROR),$prefix.'@example.invalid'),'Operations summaries contain counts and internal review links only.');
discovery_db_check(count(array_filter($summary,static fn($item)=>str_contains($item['label'],'Connections') && $item['count']===1))===1,'The summary exposes observed connection exceptions.');
echo 'Discovery database checks passed: '.$checks.' assertions on '.db()->query('SELECT VERSION()')->fetchColumn().".\n";
