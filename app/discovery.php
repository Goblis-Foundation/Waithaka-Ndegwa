<?php
declare(strict_types=1);

/** Public discovery, private search reports, and an explicitly enabled notification outbox. */
final class DiscoveryValidationException extends RuntimeException {}

function discovery_types(): array {
    return ['all'=>'Everything','post'=>'My Thoughts','journey'=>'Startup Journeys','founder'=>'Founder stories','resource'=>'Resources','video'=>'Watch'];
}

function discovery_query(mixed $value): string {
    if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || preg_match('/[\x00-\x1f\x7f]/',$value) || mb_strlen($value)>200)
        throw new DiscoveryValidationException('Use a search phrase of up to 200 characters.');
    return trim(preg_replace('/\s+/u',' ',$value) ?? '');
}

/** Each projection is public, including its plain text. Never read post_drafts or submission evidence here. */
function discovery_public_documents(): Generator {
    $now=date('Y-m-d H:i:s');
    $tags=[];$tagRows=db()->prepare("SELECT pt.post_id,t.name FROM post_tags pt JOIN tags t ON t.id=pt.tag_id JOIN posts p ON p.id=pt.post_id WHERE p.status IN ('published','scheduled') AND p.published_at IS NOT NULL AND p.published_at<=? ORDER BY pt.post_id,t.name");
    $tagRows->execute([$now]);while($tag=$tagRows->fetch())$tags[$tag['post_id']][]=$tag['name'];
    $sql="SELECT p.id,p.slug,p.title,p.standfirst,p.content_html,p.featured_image,p.featured_image_alt,p.featured_image_caption,p.video_url,p.travel_city,p.travel_country,p.seo_title,p.seo_description,p.published_at,p.updated_at,
        c.name category_name,
        EXISTS(SELECT 1 FROM founder_submissions f WHERE f.associated_post_id=p.id AND f.review_status='approved'
            AND f.is_public=1 AND f.permission_confirmed=1 AND f.claims_reviewed=1 AND f.consent_reference IS NOT NULL AND f.consent_reference<>'') founder_story
        FROM posts p LEFT JOIN categories c ON c.id=p.category_id
        WHERE p.status IN ('published','scheduled') AND p.published_at IS NOT NULL AND p.published_at<=?";
    $st=db()->prepare($sql);$st->execute([$now]);
    while($row=$st->fetch()) {
        $path='/my-thoughts/'.$row['slug'];
        if(!discovery_public_path($path))continue;
        $extras=function_exists('editorial_public_post')?editorial_public_post((int)$row['id']):['evidence'=>[],'updates'=>[]];
        $extraText=[];foreach($extras['evidence']??[]as$card)foreach(['label','summary','limitations']as$key)$extraText[]=(string)($card[$key]??'');
        foreach($extras['updates']??[]as$update)$extraText[]=(string)($update['note']??'');
        yield ['id'=>'post:'.$row['id'],'type'=>(int)$row['founder_story']===1?'founder':'post','title'=>(string)$row['title'],
            'summary'=>(string)$row['standfirst'],'body'=>plain_text((string)$row['content_html']).' '.implode(' ',$extraText),
            'keywords'=>trim((string)$row['category_name'].' '.implode(' ',$tags[$row['id']]??[])),
            'url'=>url($path),'published_at'=>$row['published_at'],'updated_at'=>$row['updated_at'],
            'image'=>(string)$row['featured_image'],'_public_hash'=>hash('sha256',json_encode([$row,$extras],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))];
    }
    $st=db()->prepare("SELECT id,slug,title,venture_name,summary,baseline,learning_question,outcomes,published_at,updated_at,phase
        FROM journey_seasons WHERE publication_status='published' AND published_at IS NOT NULL AND published_at<=?");
    $st->execute([$now]);
    while($row=$st->fetch()) {
        $path='/startup-journeys/'.$row['slug'];if(!discovery_public_path($path))continue;
        $experiments=function_exists('editorial_public_experiments')?editorial_public_experiments((int)$row['id']):[];
        $episodes=journeys_public_episodes((int)$row['id']);$extraText=[];
        foreach($experiments as$experiment)foreach(['title','summary','question','hypothesis','method','findings','decision','limitations','next_step']as$key)$extraText[]=(string)($experiment[$key]??'');
        foreach($episodes as$episode)foreach(['title','standfirst','public_summary','evidence_summary']as$key)$extraText[]=(string)($episode[$key]??'');
        yield ['id'=>'journey:'.$row['id'],'type'=>'journey','title'=>(string)$row['title'],'summary'=>(string)$row['summary'],
            'body'=>implode(' ',[(string)$row['venture_name'],(string)$row['baseline'],(string)$row['learning_question'],(string)$row['outcomes']]).' '.implode(' ',$extraText),
            'keywords'=>(string)$row['phase'],'url'=>url($path),'published_at'=>$row['published_at'],'updated_at'=>$row['updated_at'],'image'=>'',
            '_public_hash'=>hash('sha256',json_encode([$row,$experiments,$episodes],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))];
    }
    if(function_exists('editorial_search_documents')) {
        foreach(editorial_search_documents() as $row) {
            if(!is_array($row) || !in_array($row['type']??'', ['resource','video'],true))continue;
            $canonical=discovery_canonical_url((string)($row['url']??''));
            // The editorial hook is responsible for frozen-public projection. Recheck time and URL boundary here.
            $published=(string)($row['published_at']??'');
            $templateSlug=basename((string)parse_url((string)$canonical,PHP_URL_PATH));
            $builtin=($row['builtin']??false)===true && $row['type']==='resource' && function_exists('editorial_templates') && isset(editorial_templates()[$templateSlug]) && $canonical===url('/resources/'.$templateSlug);
            if($canonical===null || (!$builtin && (!discovery_public_timestamp($published) || $published>$now)))continue;
            if($builtin)$published='';
            yield ['id'=>(string)($row['id']??$canonical),'type'=>$row['type'],'title'=>(string)($row['title']??''),
                'summary'=>(string)($row['summary']??''),'body'=>(string)($row['body']??''),'keywords'=>'',
                'url'=>$canonical,'published_at'=>$published,'updated_at'=>(string)($row['updated_at']??$published),'image'=>'','_public_hash'=>(string)($row['public_hash']??'')];
        }
    }
}

function discovery_public_timestamp(string $value): bool {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new DateTimeZone('Africa/Nairobi'));
    return $date!==false && $date->format('Y-m-d H:i:s')===$value;
}

function discovery_document_matches(array $document,string $query): bool {
    $text=implode(' ',[(string)($document['title']??''),(string)($document['summary']??''),(string)($document['body']??''),(string)($document['keywords']??'')]);
    return $query!=='' && mb_stripos($text,$query,0,'UTF-8')!==false;
}

/** Exact counts without a first-60/first-1000 cut-off; only small matching card projections are retained. */
function discovery_search(string $query,int $page=1,string $type='all',int $perPage=24): ?array {
    $query=discovery_query($query);
    if(!array_key_exists($type,discovery_types()) || $page<1 || $perPage<1 || $perPage>100)return null;
    $counts=array_fill_keys(array_keys(discovery_types()),0);$results=[];$seen=[];
    if($query!=='')foreach(discovery_public_documents() as $document) {
        if(isset($seen[$document['url']]) || !discovery_document_matches($document,$query))continue;
        $seen[$document['url']]=true;$counts['all']++;$counts[$document['type']]++;
        if($type!=='all' && $document['type']!==$type)continue;
        unset($document['body'],$document['keywords'],$document['_public_hash']);$results[]=$document;
    }
    usort($results,static fn(array $a,array $b):int=>strcmp($b['published_at'],$a['published_at']) ?: strcmp($a['url'],$b['url']));
    $queryParams=$query===''?[]:['q'=>$query];if($type!=='all')$queryParams['type']=$type;
    $pagination=archive_pagination($page,count($results),$perPage,'/search',$queryParams);
    if($pagination===null)return null;
    return ['q'=>$query,'type'=>$type,'counts'=>$counts,'pagination'=>$pagination,'results'=>array_slice($results,$pagination['offset'],$perPage)];
}

function discovery_public_path(string $path): bool {
    return in_array($path,['/','/about','/my-thoughts','/startup-journeys','/founders','/start-here','/resources','/watch'],true)
        || preg_match('~\A/(?:my-thoughts|startup-journeys|resources|watch)/[a-z0-9][a-z0-9_-]{0,259}\z~',$path)===1;
}

/** Only the site's canonical origin and public content paths; never query/capability URLs or alternate domains. */
function discovery_canonical_url(string $value): ?string {
    if(strlen($value)>2048 || preg_match('/[\x00-\x20\x7f\\\\]/',$value))return null;
    $base=parse_url(base_url());$parts=parse_url($value);
    if(!$base || !$parts || !isset($base['host'],$parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']))return null;
    if(strtolower($parts['host'])!==strtolower($base['host']) || ($parts['scheme']??'')!==($base['scheme']??'') || ($parts['port']??null)!==($base['port']??null))return null;
    if(!in_array($parts['scheme']??'',['https','http'],true) || (($parts['scheme']??'')!=='https' && app_config('app_env')!=='testing'))return null;
    if(!empty($base['path']) && $base['path']!=='/')return null;
    $path=$parts['path']??'/';return discovery_public_path($path)?url($path):null;
}

function discovery_lock(int $timeout=5): bool {
    $st=db()->prepare('SELECT GET_LOCK(?,?)');$st->execute(['waithaka_discovery',$timeout]);return (int)$st->fetchColumn()===1;
}
function discovery_unlock(): void { db()->query("SELECT RELEASE_LOCK('waithaka_discovery')"); }

/** Called after content commits, and before every enabled worker batch. No network activity. */
function discovery_sync_public_urls(): array {
    if(db()->inTransaction())throw new RuntimeException('Reconcile discovery after the content transaction commits.');
    if(!discovery_lock())throw new RuntimeException('Discovery is busy. Retry shortly.');
    try{return discovery_sync_public_urls_locked();}finally{discovery_unlock();}
}

function discovery_sync_public_urls_locked(): array {
    $stats=['added'=>0,'updated'=>0,'deleted'=>0,'unchanged'=>0];$run=bin2hex(random_bytes(16));$seen=[];$collectionHashes=[];
    db()->beginTransaction();
    try {
        foreach(discovery_public_documents() as $document) {
            $canonical=discovery_canonical_url($document['url']);
            if($canonical===null || isset($seen[$canonical]))continue;
            $seen[$canonical]=true;
            $hash=hash('sha256',json_encode($document,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            discovery_reconcile_url($canonical,$document['id'],$hash,$run,$stats);
            $collection=match($document['type']){'journey'=>'/startup-journeys','resource'=>'/resources','video'=>'/watch',default=>'/my-thoughts'};
            $collectionHashes[$collection][$canonical]=$hash;
            $collectionHashes['/'][$canonical]=$hash;
            if($document['type']==='founder')$collectionHashes['/founders'][$canonical]=$hash;
        }
        $collections=['/','/about','/my-thoughts','/startup-journeys','/founders'];
        if(function_exists('editorial_search_documents'))$collections=array_merge($collections,['/start-here','/resources','/watch']);
        foreach($collections as $path) {
            $hashes=$collectionHashes[$path]??[];ksort($hashes,SORT_STRING);
            $files=match($path){'/'=>['home.php'],'/about'=>['about.php'],'/my-thoughts'=>['writing.php'],'/founders'=>['founders/index.php'],'/startup-journeys'=>['journeys/index.php'],'/start-here'=>['editorial/start.php'],'/resources','/watch'=>['editorial/collection.php'],default=>[]};
            foreach($files as $file){$full=dirname(__DIR__).'/views/'.$file;if(is_file($full))$hashes['view:'.$file]=hash_file('sha256',$full);}
            discovery_reconcile_url(url($path),'collection:'.$path,hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR)),$run,$stats);
        }
        $st=db()->prepare('SELECT * FROM discovery_public_urls WHERE is_public=1 AND seen_run<>? FOR UPDATE');$st->execute([$run]);
        foreach($st->fetchAll() as $row) {
            $revision=(int)$row['revision']+1;
            db()->prepare('UPDATE discovery_public_urls SET is_public=0,revision=?,changed_at=UTC_TIMESTAMP() WHERE id=?')->execute([$revision,$row['id']]);
            discovery_queue_revision((int)$row['id'],$revision,'deleted',$row['canonical_url']);$stats['deleted']++;
        }
        db()->commit();return $stats;
    }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();throw $exception;}
}

function discovery_reconcile_url(string $url,string $source,string $hash,string $run,array &$stats): void {
    $st=db()->prepare('SELECT * FROM discovery_public_urls WHERE url_hash=? FOR UPDATE');$st->execute([hash('sha256',$url)]);$row=$st->fetch();
    if(!$row) {
        db()->prepare('INSERT INTO discovery_public_urls(url_hash,canonical_url,source_key,content_hash,revision,is_public,seen_run,first_public_at,changed_at,seen_at) VALUES(?,?,?,?,1,1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())')
            ->execute([hash('sha256',$url),$url,mb_substr($source,0,200),$hash,$run]);
        discovery_queue_revision((int)db()->lastInsertId(),1,'added',$url);$stats['added']++;return;
    }
    if(!hash_equals((string)$row['canonical_url'],$url))throw new RuntimeException('Discovery URL identity conflict.');
    $changed=!(int)$row['is_public'] || !hash_equals((string)$row['content_hash'],$hash);
    $revision=(int)$row['revision']+($changed?1:0);
    db()->prepare('UPDATE discovery_public_urls SET source_key=?,content_hash=?,revision=?,is_public=1,seen_run=?,seen_at=UTC_TIMESTAMP(),changed_at=IF(?,UTC_TIMESTAMP(),changed_at) WHERE id=?')
        ->execute([mb_substr($source,0,200),$hash,$revision,$run,$changed?1:0,$row['id']]);
    if($changed){discovery_queue_revision((int)$row['id'],$revision,(int)$row['is_public']?'updated':'added',$url);$stats['updated']++;}
    else $stats['unchanged']++;
}

function discovery_queue_revision(int $publicId,int $revision,string $event,string $url): void {
    db()->prepare("UPDATE discovery_indexnow_queue SET status='cancelled',error_code='superseded_revision',updated_at=UTC_TIMESTAMP() WHERE public_url_id=? AND revision<? AND status IN ('pending','retry','rejected')")
        ->execute([$publicId,$revision]);
    db()->prepare("INSERT INTO discovery_indexnow_queue(public_url_id,revision,event_type,canonical_url,status,available_at,created_at,updated_at) VALUES(?,?,?,?,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())")
        ->execute([$publicId,$revision,$event,$url]);
}

/** A caller cannot use this helper to enqueue arbitrary or never-public URLs. */
function discovery_notify_public_url(string $url,string $event='updated'): ?int {
    if(!in_array($event,['added','updated','deleted'],true) || discovery_canonical_url($url)===null)throw new DiscoveryValidationException('Only a canonical public content URL can be reconciled.');
    discovery_sync_public_urls();
    $st=db()->prepare('SELECT q.id FROM discovery_indexnow_queue q JOIN discovery_public_urls u ON u.id=q.public_url_id WHERE u.url_hash=? ORDER BY q.id DESC LIMIT 1');$st->execute([hash('sha256',$url)]);
    return ($id=$st->fetchColumn())!==false?(int)$id:null;
}

/** Read fresh settings: settings() intentionally caches per request, which is wrong for long-lived workers. */
function discovery_indexnow_settings(): array {
    $values=[];$st=db()->query("SELECT setting_key,setting_value FROM settings WHERE setting_key IN ('discovery.indexnow.enabled','discovery.indexnow.key')");
    foreach($st->fetchAll() as $row)$values[$row['setting_key']]=$row['setting_value'];
    $key=(string)($values['discovery.indexnow.key']??'');
    if(!preg_match('/\A[a-zA-Z0-9-]{8,128}\z/',$key))$key='';
    return ['enabled'=>($values['discovery.indexnow.enabled']??'0')==='1','key'=>$key,'environment_enabled'=>(bool)app_config('indexnow_enabled',false)];
}

function discovery_indexnow_save(bool $enabled,bool $rotate,int $actor): void {
    if(!discovery_lock())throw new RuntimeException('Discovery is busy. Retry shortly.');
    try {
        $current=discovery_indexnow_settings();$key=$current['key'];if($key==='' || $rotate)$key=bin2hex(random_bytes(32));
        db()->beginTransaction();
        set_setting('discovery.indexnow.enabled',$enabled?'1':'0');set_setting('discovery.indexnow.key',$key);
        journeys_audit('discovery',0,$enabled?'indexnow_enabled':'indexnow_paused',$actor);db()->commit();
    }catch(Throwable $exception){if(db()->inTransaction())db()->rollBack();throw $exception;}finally{discovery_unlock();}
}

function discovery_indexnow_result(int $httpStatus,int $failures,?string $retryAfter=null,?int $now=null): array {
    $now??=time();
    if($httpStatus===200)return ['status'=>'accepted','error'=>null,'delay'=>0];
    if($httpStatus===202)return ['status'=>'verification_pending','error'=>'key_verification_pending','delay'=>0];
    $retryable=$httpStatus===0 || $httpStatus===408 || $httpStatus===429 || $httpStatus>=500;
    if(!$retryable || $failures>=8)return ['status'=>'rejected','error'=>$retryable?'retry_limit':match($httpStatus){400=>'invalid_request',403=>'invalid_key',422=>'invalid_host_or_urls',default=>'unexpected_response'},'delay'=>0];
    $delay=min(86400,60*(2**min(10,max(0,$failures-1))));
    if($retryAfter!==null) {
        $seconds=ctype_digit($retryAfter)?(int)$retryAfter:(($parsed=strtotime($retryAfter))!==false?max(0,$parsed-$now):0);
        $delay=max($delay,min(86400,$seconds));
    }
    return ['status'=>'retry','error'=>$httpStatus===0?'incomplete_response':($httpStatus===429?'rate_limit':'remote_unavailable'),'delay'=>$delay];
}

/** Fixed HTTPS endpoint; no redirects, cookies, bearer tokens, response bodies, or configurable remote hosts. */
function discovery_indexnow_transport(array $payload): array {
    if(app_config('app_env')!=='production')throw new RuntimeException('External IndexNow notifications are disabled outside production.');
    $responseHeaders=[];$ch=curl_init('https://api.indexnow.org/indexnow');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json; charset=utf-8','Accept: application/json'],CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,
        CURLOPT_WRITEFUNCTION=>static fn($handle,string $chunk):int=>strlen($chunk),
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$responseHeaders):int{$parts=explode(':',$line,2);if(count($parts)===2)$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);}]);
    $ok=curl_exec($ch)!==false && curl_errno($ch)===0;$status=$ok?(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE):0;curl_close($ch);
    return ['status'=>$status,'retry_after'=>$responseHeaders['retry-after']??null];
}

/** CLI worker only. A supplied fake transport is accepted only in explicit testing, never from a route. */
function discovery_work(int $limit=10,?callable $testTransport=null): array {
    $stats=['enabled'=>false,'added'=>0,'updated'=>0,'deleted'=>0,'attempted'=>0,'accepted'=>0,'verification_pending'=>0,'retry'=>0,'rejected'=>0,'cancelled'=>0];
    if(PHP_SAPI!=='cli')return $stats;
    $testing=app_config('app_env')==='testing';
    if($testTransport!==null && !$testing)throw new RuntimeException('A discovery fixture is restricted to testing.');
    $settings=discovery_indexnow_settings();
    if(!(bool)app_config('worker_enabled',false) || !$settings['enabled'] || !$settings['environment_enabled'] || $settings['key']==='')return $stats;
    if(!$testing && app_config('app_env')!=='production')return $stats;
    if($testing && $testTransport===null)return $stats;
    $parts=parse_url(base_url());
    if(($parts['scheme']??'')!=='https' || !isset($parts['host']) || isset($parts['port']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || (!empty($parts['path']) && $parts['path']!=='/'))throw new RuntimeException('IndexNow requires a canonical HTTPS origin.');
    if(!preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}\z/i',$parts['host']) || (!$testing && filter_var($parts['host'],FILTER_VALIDATE_IP)))throw new RuntimeException('IndexNow requires a public canonical hostname.');
    if(!discovery_lock(0))return $stats;
    try {
        $stats['enabled']=true;$sync=discovery_sync_public_urls_locked();foreach(['added','updated','deleted']as$key)$stats[$key]=$sync[$key];
        // An interrupted attempt is an unknown notification receipt. Retrying IndexNow is idempotent for indexing, not a publication replay.
        db()->prepare("UPDATE discovery_indexnow_attempts a JOIN discovery_indexnow_queue q ON q.id=a.queue_id SET a.result='interrupted',a.error_code='interrupted_attempt',a.finished_at=UTC_TIMESTAMP() WHERE q.status='attempting' AND q.attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE) AND a.finished_at IS NULL")->execute();
        db()->prepare("UPDATE discovery_indexnow_queue SET status='retry',error_code='interrupted_attempt',available_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE status='attempting' AND attempted_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)")->execute();
        $rows=db()->query("SELECT q.*,u.revision current_revision,u.is_public FROM discovery_indexnow_queue q JOIN discovery_public_urls u ON u.id=q.public_url_id WHERE q.status IN ('pending','retry') AND q.available_at<=UTC_TIMESTAMP() ORDER BY q.id LIMIT ".max(1,min(100,$limit)))->fetchAll();
        foreach($rows as $row) {
            if((int)$row['revision']!==(int)$row['current_revision'] || (($row['event_type']==='deleted')===(bool)$row['is_public']) || discovery_canonical_url($row['canonical_url'])===null) {
                db()->prepare("UPDATE discovery_indexnow_queue SET status='cancelled',error_code='stale_or_noncanonical',updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$row['id']]);$stats['cancelled']++;continue;
            }
            $attempt=(int)$row['attempt_count']+1;
            db()->beginTransaction();
            db()->prepare("UPDATE discovery_indexnow_queue SET status='attempting',attempt_count=?,attempted_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?")->execute([$attempt,$row['id']]);
            db()->prepare('INSERT INTO discovery_indexnow_attempts(queue_id,attempt_number,result,started_at) VALUES(?,?,\'started\',UTC_TIMESTAMP())')->execute([$row['id'],$attempt]);
            $attemptId=(int)db()->lastInsertId();db()->commit();$stats['attempted']++;
            $payload=['host'=>$parts['host'],'key'=>$settings['key'],'keyLocation'=>url('/'.$settings['key'].'.txt'),'urlList'=>[$row['canonical_url']]];
            try{$response=$testTransport!==null?$testTransport($payload):discovery_indexnow_transport($payload);$code=(int)($response['status']??0);$retryAfter=isset($response['retry_after'])?(string)$response['retry_after']:null;}
            catch(Throwable){$code=0;$retryAfter=null;}
            $code=($code>=100 && $code<=599)?$code:0;$failures=(int)$row['failure_count']+1;
            $result=discovery_indexnow_result($code,$failures,$retryAfter);$stats[$result['status']]++;
            db()->beginTransaction();
            db()->prepare('UPDATE discovery_indexnow_attempts SET result=?,http_status=?,error_code=?,finished_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$result['status'],$code?:null,$result['error'],$attemptId]);
            db()->prepare('UPDATE discovery_indexnow_queue SET status=?,failure_count=?,last_http_status=?,error_code=?,available_at=?,accepted_at=IF(?,UTC_TIMESTAMP(),NULL),updated_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$result['status'],in_array($code,[200,202],true)?0:$failures,$code?:null,$result['error'],gmdate('Y-m-d H:i:s',time()+$result['delay']),in_array($code,[200,202],true)?1:0,$row['id']]);
            db()->commit();
        }
        return $stats;
    }finally{if(db()->inTransaction())db()->rollBack();discovery_unlock();}
}

function discovery_retry_notification(int $id,int $actor): void {
    if(!discovery_lock())throw new RuntimeException('Discovery is busy. Retry shortly.');
    try {
        discovery_sync_public_urls_locked();
        $st=db()->prepare("UPDATE discovery_indexnow_queue q JOIN discovery_public_urls u ON u.id=q.public_url_id SET q.status='retry',q.failure_count=0,q.error_code=NULL,q.available_at=UTC_TIMESTAMP(),q.updated_at=UTC_TIMESTAMP() WHERE q.id=? AND q.status IN ('rejected','verification_pending') AND q.revision=u.revision AND ((q.event_type='deleted' AND u.is_public=0) OR (q.event_type<>'deleted' AND u.is_public=1))");
        $st->execute([$id]);if($st->rowCount()!==1)throw new DiscoveryValidationException('Only the latest rejected or verification-pending notification can be retried.');
        journeys_audit('discovery',$id,'indexnow_retry_requested',$actor);
    }finally{discovery_unlock();}
}

function discovery_periods(int $days=28,?DateTimeImmutable $now=null): array {
    if(!in_array($days,[28,90],true))throw new DiscoveryValidationException('Choose a 28-day or 90-day comparison.');
    $now=($now??new DateTimeImmutable('now',new DateTimeZone('America/Los_Angeles')))->setTimezone(new DateTimeZone('America/Los_Angeles'));
    $end=$now->setTime(0,0)->modify('-3 days');$start=$end->modify('-'.($days-1).' days');$previousEnd=$start->modify('-1 day');$previousStart=$previousEnd->modify('-'.($days-1).' days');
    return ['days'=>$days,'start'=>$start->format('Y-m-d'),'end'=>$end->format('Y-m-d'),'previous_start'=>$previousStart->format('Y-m-d'),'previous_end'=>$previousEnd->format('Y-m-d'),'timezone'=>'America/Los_Angeles'];
}

function discovery_gsc_connection(): array {
    $connection=publishing_connection('google_search_console');
    if(!$connection || $connection['provider']!=='google' || $connection['status']!=='connected' || empty($connection['verified_at']) || empty($connection['external_id']))throw new DiscoveryValidationException('Connect Google Search Console and confirm a returned property first.');
    $scopes=publishing_decode($connection['scopes_json']);
    if(!in_array('https://www.googleapis.com/auth/webmasters.readonly',$scopes,true) && !in_array('https://www.googleapis.com/auth/webmasters',$scopes,true))throw new DiscoveryValidationException('Reconnect Google Search Console with its read permission.');
    $resource=(string)$connection['selected_resource'];$found=false;
    foreach(publishing_decode($connection['resources_json']) as $item)if(($item['id']??null)===$resource && ($item['type']??'')==='site'){$found=true;break;}
    if(!$found || $resource==='' || strlen($resource)>500 || preg_match('/[\x00-\x20\x7f]/',$resource))throw new DiscoveryValidationException('Confirm a property returned by Google before requesting reports.');
    return $connection;
}

function discovery_gsc_fingerprint(array $connection): string {
    return hash('sha256',json_encode([(int)$connection['id'],$connection['service'],$connection['provider'],$connection['external_id'],$connection['selected_resource'],$connection['verified_at'],publishing_decode($connection['scopes_json'])],JSON_THROW_ON_ERROR));
}

/** Missing dimension rows are unknown, not fabricated zeroes. Totals use a separate undimensioned API request. */
function discovery_gsc_row_limit(?string $dimension): int { return $dimension===null?1:($dimension==='country'?300:100); }

function discovery_gsc_rows(array $response,?string $dimension,int $limit): array {
    if(isset($response['error']) || (isset($response['rows']) && (!is_array($response['rows']) || !array_is_list($response['rows']))))throw new RuntimeException('Search Console returned an invalid report.');
    $rows=$response['rows']??[];if(count($rows)>$limit)throw new RuntimeException('Search Console returned more rows than requested.');
    $clean=[];$seen=[];
    foreach($rows as $row) {
        if(!is_array($row))throw new RuntimeException('Search Console returned an invalid row.');
        $key='total';
        if($dimension!==null) {
            if(!isset($row['keys']) || !is_array($row['keys']) || count($row['keys'])!==1 || !is_string($row['keys'][0]) || !mb_check_encoding($row['keys'][0],'UTF-8') || mb_strlen($row['keys'][0])>2000)throw new RuntimeException('Search Console returned an invalid dimension.');
            $key=$row['keys'][0];
        }
        if(isset($seen[$key]))throw new RuntimeException('Search Console returned a duplicate dimension.');$seen[$key]=true;
        $metrics=[];foreach(['clicks','impressions','ctr','position']as$metric) {
            if(!isset($row[$metric]) || (!is_int($row[$metric])&&!is_float($row[$metric])) || !is_finite((float)$row[$metric]) || $row[$metric]<0 || ($metric==='ctr' && $row[$metric]>1))throw new RuntimeException('Search Console returned invalid metrics.');
            $metrics[$metric]=(float)$row[$metric];
        }
        $clean[]=['key'=>$key]+$metrics;
    }
    return $clean;
}

function discovery_gsc_comparison(array $current,array $previous): array {
    $rows=[];foreach($current as $row)$rows[$row['key']]=['key'=>$row['key'],'current'=>$row,'previous'=>null];
    foreach($previous as $row){if(!isset($rows[$row['key']]))$rows[$row['key']]=['key'=>$row['key'],'current'=>null,'previous'=>$row];else $rows[$row['key']]['previous']=$row;}
    $rows=array_values($rows);usort($rows,static fn($a,$b)=>(($b['current']['clicks']??-1)<=>($a['current']['clicks']??-1)) ?: strcmp((string)$a['key'],(string)$b['key']));return $rows;
}

/** Explicit Writer action only: eight documented Search Analytics reads, no notification or indexing mutation. */
function discovery_gsc_refresh(int $days,int $actor,?callable $testRequest=null): int {
    $testing=app_config('app_env')==='testing';
    if($testRequest!==null && !$testing)throw new RuntimeException('A reporting fixture is restricted to testing.');
    if($testing && $testRequest===null)throw new RuntimeException('Live reports are disabled in tests.');
    $periods=discovery_periods($days);$connection=discovery_gsc_connection();$fingerprint=discovery_gsc_fingerprint($connection);
    $lock=db()->query("SELECT GET_LOCK('waithaka_discovery_gsc',0)");if((int)$lock->fetchColumn()!==1)throw new DiscoveryValidationException('A Search Console report is already running.');
    $id=0;
    try {
        db()->prepare("INSERT INTO discovery_gsc_reports(connection_id,connection_fingerprint,property_url,days,period_start,period_end,previous_start,previous_end,status,requested_by,created_at) VALUES(?,?,?,?,?,?,?,?,'running',?,UTC_TIMESTAMP())")
            ->execute([$connection['id'],$fingerprint,$connection['selected_resource'],$days,$periods['start'],$periods['end'],$periods['previous_start'],$periods['previous_end'],$actor]);$id=(int)db()->lastInsertId();
        $endpoint='https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($connection['selected_resource']).'/searchAnalytics/query';
        $payload=['periods'=>$periods,'type'=>'web','data_state'=>'final','row_limit'=>100,'row_limits'=>['query'=>100,'country'=>300,'device'=>100],'totals'=>[],'dimensions'=>[]];
        $headers=$testing?[]:publishing_bearer(publishing_access_token($connection));
        foreach(['totals'=>null,'query'=>'query','country'=>'country','device'=>'device']as$name=>$dimension) {
            $pairs=[];
            foreach(['current','previous']as$period) {
                $body=['startDate'=>$period==='current'?$periods['start']:$periods['previous_start'],'endDate'=>$period==='current'?$periods['end']:$periods['previous_end'],
                    'dimensions'=>$dimension===null?[]:[$dimension],'type'=>'web','dataState'=>'final','aggregationType'=>'byProperty','rowLimit'=>discovery_gsc_row_limit($dimension),'startRow'=>0];
                $response=$testRequest!==null?$testRequest($endpoint,$body):publishing_http('POST',$endpoint,$headers,$body)['data'];
                if(!is_array($response))throw new RuntimeException('Search Console returned an invalid report.');
                $pairs[$period]=discovery_gsc_rows($response,$dimension,discovery_gsc_row_limit($dimension));
            }
            if($dimension===null)$payload['totals']=['current'=>$pairs['current'][0]??null,'previous'=>$pairs['previous'][0]??null];
            else $payload['dimensions'][$name]=discovery_gsc_comparison($pairs['current'],$pairs['previous']);
        }
        if(!hash_equals($fingerprint,discovery_gsc_fingerprint(discovery_gsc_connection())))throw new DiscoveryValidationException('The confirmed Google property changed during this report. Refresh it again.');
        db()->prepare("UPDATE discovery_gsc_reports SET status='success',payload_json=?,completed_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$id]);return $id;
    }catch(Throwable $exception) {
        if($id)db()->prepare("UPDATE discovery_gsc_reports SET status='failed',error_code=?,payload_json=NULL,completed_at=UTC_TIMESTAMP() WHERE id=?")
            ->execute([$exception instanceof PublishingRemoteException && $exception->httpStatus===401?'reconnect_required':'report_unavailable',$id]);
        throw new DiscoveryValidationException($exception instanceof PublishingRemoteException && $exception->httpStatus===401?'Reconnect Google Search Console before refreshing this report.':'The report could not be completed. The previous successful snapshot is unchanged.');
    }finally{db()->query("SELECT RELEASE_LOCK('waithaka_discovery_gsc')");}
}

function discovery_gsc_latest(?int $days=null): array {
    try{$connection=discovery_gsc_connection();}catch(DiscoveryValidationException $exception){return ['connection'=>null,'latest'=>null,'success'=>null,'notice'=>$exception->getMessage()];}
    $params=[$connection['id'],discovery_gsc_fingerprint($connection)];$where='connection_id=? AND connection_fingerprint=?';
    if($days!==null){$where.=' AND days=?';$params[]=$days;}
    $latest=db()->prepare('SELECT * FROM discovery_gsc_reports WHERE '.$where.' ORDER BY id DESC LIMIT 1');$latest->execute($params);
    $success=db()->prepare("SELECT * FROM discovery_gsc_reports WHERE ".$where." AND status='success' ORDER BY id DESC LIMIT 1");$success->execute($params);
    $report=$success->fetch()?:null;if($report)$report['data']=json_decode((string)$report['payload_json'],true,512,JSON_THROW_ON_ERROR);
    // Do not return credentials/resources to the reporting view.
    return ['connection'=>['property'=>$connection['selected_resource'],'status'=>$connection['status']],'latest'=>$latest->fetch()?:null,'success'=>$report,'notice'=>null];
}

/** Counts and fixed internal review links only; no recipient addresses, credentials, raw payloads or provider bodies. */
function discovery_operations_summary(): array {
    $items=[];
    $queries=[
        ['Publishing outcomes need review',"SELECT COUNT(*) FROM publishing_jobs WHERE status IN ('failed','ambiguous','reconnect')",'/write/publishing'],
        ['Connections need confirmation or reconnection',"SELECT COUNT(*) FROM publishing_connections WHERE status IN ('pending_confirmation','reconnect')",'/write/connections'],
        ['Email outcomes need review',"SELECT COUNT(*) FROM email_outbox WHERE status IN ('failed','ambiguous') OR error_code='bookkeeping_pending'",'/write/operations#email-attention'],
        ['IndexNow notifications need attention',"SELECT COUNT(*) FROM discovery_indexnow_queue WHERE status IN ('rejected','verification_pending')",'/write/discovery#notifications'],
        ['IndexNow notifications are waiting',"SELECT COUNT(*) FROM discovery_indexnow_queue WHERE status IN ('pending','retry','attempting')",'/write/discovery#notifications']
    ];
    foreach($queries as[$label,$sql,$path])$items[]=['label'=>$label,'count'=>(int)db()->query($sql)->fetchColumn(),'url'=>url($path)];
    $report=discovery_gsc_latest();$date=$report['success']['completed_at']??null;
    $stale=$date===null || strtotime($date.' UTC')<time()-14*86400;
    $items[]=['label'=>$date===null?'No report for the confirmed Search Console property':'Search Console snapshot is older than 14 days','count'=>$stale?1:0,'url'=>url('/write/discovery#search-console')];
    if(function_exists('reader_operations_summary'))foreach(reader_operations_summary() as $key=>$count) {
        if(in_array($key,['enquiries_open','enquiries_overdue','founder_waiting','founder_replies_pending'],true) && is_int($count))$items[]=['label'=>ucwords(str_replace('_',' ',$key)),'count'=>max(0,$count),'url'=>url('/write/readers')];
    }
    return $items;
}

/** Existing local variants only. No disk writes, synthetic URLs, arbitrary URL fetches, or S3 metadata calls. */
function discovery_image_attributes(?string $source,array $variants=[],string $sizes='(max-width: 720px) 100vw, 50vw'): array {
    $fallback=asset('images/social-default.png');$source=trim((string)$source);
    if($source==='' || preg_match('/[\x00-\x20\x7f]/',$source))$source=$fallback;
    $parts=parse_url($source);$base=parse_url(base_url());$local=!isset($parts['host']) || (strtolower((string)$parts['host'])===strtolower((string)($base['host']??'')) && ($parts['scheme']??'')===($base['scheme']??'') && ($parts['port']??null)===($base['port']??null));
    if(!$local) {
        return ['src'=>(($parts['scheme']??'')==='https' && !isset($parts['user']) && !isset($parts['pass']))?$source:$fallback];
    }
    $path='/'.ltrim((string)($parts['path']??''),'/');$root=realpath(dirname(__DIR__).'/assets');
    $inspect=static function(string $candidate)use($root):?array {
        if(!preg_match('~\A/assets/(?:images|uploads)/[a-zA-Z0-9_./-]+\.(?:webp|png|jpe?g|gif|avif)\z~',$candidate) || str_contains($candidate,'..'))return null;
        $file=realpath(dirname(__DIR__).$candidate);
        if(!$root || !$file || !str_starts_with($file,$root.DIRECTORY_SEPARATOR) || !is_file($file) || is_link(dirname(__DIR__).$candidate))return null;
        $size=@getimagesize($file);return $size && $size[0]>0 && $size[1]>0?['path'=>$candidate,'width'=>$size[0],'height'=>$size[1]]:null;
    };
    $original=$inspect($path);if(!$original)return ['src'=>$fallback];
    $attributes=['src'=>url($path),'width'=>$original['width'],'height'=>$original['height']];$candidates=[$original['width']=>$original];
    $extension=pathinfo($path,PATHINFO_EXTENSION);$stem=substr($path,0,-strlen($extension)-1);
    foreach([320,480,640,800,960,1200,1600]as$width)$variants[]=$stem.'-'.$width.'w.'.$extension;
    foreach(array_slice($variants,0,24)as$variant) {
        if(!is_string($variant))continue;$variantParts=parse_url($variant);if(isset($variantParts['host']) || isset($variantParts['query']) || isset($variantParts['fragment']))continue;
        $image=$inspect('/'.ltrim($variant,'/'));if(!$image || $image['width']>$original['width'])continue;
        if(abs($image['width']/$image['height']-$original['width']/$original['height'])>0.02)continue;
        $candidates[$image['width']]=$image;
    }
    if(count($candidates)>1) {
        ksort($candidates,SORT_NUMERIC);$attributes['srcset']=implode(', ',array_map(static fn($item)=>url($item['path']).' '.$item['width'].'w',array_values($candidates)));
        $attributes['sizes']=strlen($sizes)<=300 && !preg_match('/[<>\x00-\x1f]/',$sizes)?$sizes:'100vw';
    }
    return $attributes;
}
