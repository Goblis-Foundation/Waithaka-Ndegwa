<?php
declare(strict_types=1);

/**
 * Native social-operations layer.
 *
 * This intentionally composes the existing publishing, media-centre and
 * connection primitives. It never owns provider credentials and never
 * publishes by itself.
 */
function social_ops_platforms(): array {
    $out=[];
    foreach(publishing_networks() as $slug=>$name)$out[$slug]=$name;
    return $out;
}

function social_ops_observations(): array {
    $raw=json_decode((string)setting('social_ops.observations','[]'),true);
    if(!is_array($raw))return [];
    usort($raw,static fn($a,$b)=>strcmp((string)($b['published_at']??''),(string)($a['published_at']??'')));
    return array_slice($raw,0,500);
}
function social_ops_metric(mixed $value,string $label): ?int {
    if($value===''||$value===null)return null;
    if(!is_string($value)&&!is_int($value))throw new InvalidArgumentException($label.' must be a whole number.');
    if(!preg_match('/\A(?:0|[1-9][0-9]{0,12})\z/',(string)$value))throw new InvalidArgumentException($label.' must be a nonnegative whole number.');
    return (int)$value;
}
function social_ops_observation_input(array $in,int $uid): array {
    $platform=$in['platform']??'';if(!is_string($platform)||!isset(social_ops_platforms()[$platform]))throw new InvalidArgumentException('Choose a supported platform.');
    $published=$in['published_at']??'';if(!is_string($published)||!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/',$published))throw new InvalidArgumentException('Choose the actual publication date and time.');
    $zone=new DateTimeZone((string)app_config('timezone','Africa/Nairobi'));$dt=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$published,$zone);
    if(!$dt||$dt->getTimestamp()>time()+300)throw new InvalidArgumentException('Publication time cannot be in the future.');
    $metrics=[];foreach(['impressions'=>'Impressions','reach'=>'Reach','engagements'=>'Engagements','clicks'=>'Link clicks']as$key=>$label){$value=social_ops_metric($in[$key]??'',$label);if($value!==null)$metrics[$key]=$value;}
    if(!$metrics)throw new InvalidArgumentException('Enter at least one observed result.');
    $den=$metrics['reach']??$metrics['impressions']??0;if($den===0&&(($metrics['engagements']??0)>0||($metrics['clicks']??0)>0))throw new InvalidArgumentException('Add reach or impressions when engagement or clicks are above zero.');
    $source=trim((string)($in['source']??''));if($source===''||mb_strlen($source)>500)throw new InvalidArgumentException('Add the native analytics/export source used.');
    $url=trim((string)($in['post_url']??''));if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!str_starts_with(strtolower($url),'https://')))throw new InvalidArgumentException('Use an HTTPS post URL or leave it blank.');
    return ['id'=>bin2hex(random_bytes(10)),'platform'=>$platform,'published_at'=>$dt->format(DATE_ATOM),'metrics'=>$metrics,'source'=>$source,'post_url'=>$url,'recorded_at'=>gmdate('c'),'recorded_by'=>$uid];
}
function social_ops_save_observation(array $record): void {
    $pdo=db();$name='social-ops-observations';$q=$pdo->prepare('SELECT GET_LOCK(?,3)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Another analytics update is in progress.');
    try{$rows=social_ops_observations();array_unshift($rows,$record);set_setting('social_ops.observations',json_encode(array_slice($rows,0,500),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),true);}
    finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}
}
function social_ops_rate(array $r): ?float {
    $m=$r['metrics']??[];$den=(int)($m['reach']??$m['impressions']??0);if($den<1)return null;
    return (((int)($m['engagements']??0))+2*((int)($m['clicks']??0)))/$den;
}
/** Evidence-first best-time model. No platform-wide benchmark is presented as personal data. */
function social_ops_best_times(string $platform,array $rows=null): array {
    if(!isset(social_ops_platforms()[$platform]))return [];
    $rows=$rows??social_ops_observations();$buckets=[];
    $zone=new DateTimeZone((string)app_config('timezone','Africa/Nairobi'));
    foreach($rows as$r){if(($r['platform']??'')!==$platform)continue;$rate=social_ops_rate($r);if($rate===null)continue;
        try{$d=(new DateTimeImmutable((string)$r['published_at']))->setTimezone($zone);}catch(Throwable){continue;}
        $day=(int)$d->format('N');$hour=(int)$d->format('G');$key=$day.'-'.$hour;
        $buckets[$key]??=['day'=>$day,'hour'=>$hour,'n'=>0,'sum'=>0.0];$buckets[$key]['n']++;$buckets[$key]['sum']+=$rate;
    }
    if(!$buckets)return [];
    $globalN=0;$globalSum=0.0;foreach($buckets as$b){$globalN+=$b['n'];$globalSum+=$b['sum'];}$prior=$globalN?$globalSum/$globalN:0.0;
    $max=0.0;foreach($buckets as&$b){$b['average']=$b['sum']/$b['n'];$b['adjusted']=($b['sum']+$prior*3)/($b['n']+3);$max=max($max,$b['adjusted']);}unset($b);
    foreach($buckets as&$b){$b['score']=$max>0?(int)round(100*$b['adjusted']/$max):0;$b['confidence']=$b['n']>=5?'strong':($b['n']>=3?'developing':'early');}unset($b);
    usort($buckets,static fn($a,$b)=>$b['score']<=>$a['score'] ?: $b['n']<=>$a['n']);
    return array_slice($buckets,0,12);
}
function social_ops_day(int $n): string {return [1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun'][$n]??'';}

function social_ops_calendar(int $days=35): array {
    $days=max(7,min(90,$days));$from=date('Y-m-d H:i:s',time()-86400);$to=date('Y-m-d H:i:s',time()+$days*86400);
    $q=db()->prepare('SELECT j.id,j.status,j.scheduled_at,j.external_url,c.slug,c.name channel,p.id package_id,p.title FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id JOIN publishing_channels c ON c.id=j.channel_id WHERE j.scheduled_at BETWEEN ? AND ? ORDER BY j.scheduled_at,j.id LIMIT 500');
    $q->execute([$from,$to]);return $q->fetchAll();
}
function social_ops_campaigns(): array {
    return db()->query("SELECT p.id,p.title,p.updated_at,p.approved_revision_id,COUNT(j.id) jobs,SUM(j.status='published') published,SUM(j.status='scheduled') scheduled,SUM(j.status IN ('failed','ambiguous','reconnect')) attention FROM publishing_packages p LEFT JOIN publishing_revisions r ON r.package_id=p.id LEFT JOIN publishing_jobs j ON j.revision_id=r.id GROUP BY p.id ORDER BY p.updated_at DESC LIMIT 30")->fetchAll();
}
function social_ops_latest_reports(): array {
    $out=[];foreach(array_keys(media_centre_platforms())as$slug){$h=media_centre_history($slug);if($h)$out[$slug]=$h[0];}return $out;
}
function social_ops_connection_summary(): array {
    $out=[];foreach(media_centre_connect_services()as$slug=>$service){$c=$service!==''?publishing_connection($service):null;$out[$slug]=['service'=>$service,'state'=>$c['status']??'not_connected','name'=>$c['display_name']??'', 'selected'=>$c['selected_resource']??''];}return $out;
}
function social_ops_inbox_links(): array {
    return [
      ['name'=>'Instagram comments','href'=>url('/write/connections#instagram_comments'),'note'=>'Read through the approved connection; moderation remains provider-bound.'],
      ['name'=>'Instagram messages','href'=>url('/write/connections#instagram_messages'),'note'=>'Private conversations are not copied into analytics or AI automatically.'],
      ['name'=>'Facebook','href'=>'https://www.facebook.com/','external'=>true,'note'=>'Open the native profile/Page inbox until provider messaging access is explicitly granted.'],
      ['name'=>'LinkedIn','href'=>'https://www.linkedin.com/messaging/','external'=>true,'note'=>'Use the native member inbox; no message permission is inferred from publishing access.'],
      ['name'=>'X','href'=>'https://x.com/messages','external'=>true,'note'=>'Use the native inbox unless a separately approved messaging product is connected.'],
      ['name'=>'TikTok','href'=>'https://www.tiktok.com/messages','external'=>true,'note'=>'Use the native inbox; publishing/read access does not imply DM access.'],
    ];
}

function social_ops_link_id(string $label,string $url): string {return substr(hash('sha256',mb_strtolower(trim($label)).'|'.trim($url)),0,12);}
function social_ops_smartlinks_default(): array {
    $rows=[
      ['label'=>'Start Here','url'=>url('/start-here'),'enabled'=>true],
      ['label'=>'My Thoughts','url'=>url('/my-thoughts'),'enabled'=>true],
      ['label'=>'Opportunities','url'=>url('/opportunities'),'enabled'=>true],
      ['label'=>'Contact','url'=>url('/contact'),'enabled'=>true],
    ];
    foreach($rows as&$row)$row['id']=social_ops_link_id($row['label'],$row['url']);unset($row);return $rows;
}
function social_ops_smartlinks(): array {
    $v=json_decode((string)setting('social_ops.smartlinks',''),true);$rows=is_array($v)?array_slice($v,0,12):social_ops_smartlinks_default();
    foreach($rows as&$row)if(empty($row['id']))$row['id']=social_ops_link_id((string)($row['label']??''),(string)($row['url']??''));unset($row);return $rows;
}
function social_ops_smartlink_clicks(): array {
    $raw=json_decode((string)setting('social_ops.smartlink_clicks','{}'),true);return is_array($raw)?$raw:[];
}
function social_ops_smartlink_resolve(string $id): ?array {
    if(!preg_match('/^[a-f0-9]{12}$/D',$id))return null;foreach(social_ops_smartlinks()as$row)if(!empty($row['enabled'])&&hash_equals((string)$row['id'],$id)&&publishing_public_url((string)$row['url']))return $row;return null;
}
function social_ops_smartlink_record(string $id): void {
    $pdo=db();$name='social-ops-smartlink-clicks';$q=$pdo->prepare('SELECT GET_LOCK(?,2)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)return;
    try{$clicks=social_ops_smartlink_clicks();$clicks[$id]=['count'=>(int)($clicks[$id]['count']??0)+1,'last_at'=>gmdate('c')];set_setting('social_ops.smartlink_clicks',json_encode($clicks,JSON_THROW_ON_ERROR),false);}finally{$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);}
}
function social_ops_smartlinks_input(array $in): array {
    $rows=$in['links']??[];if(!is_array($rows)||count($rows)>12)throw new InvalidArgumentException('Use up to 12 SmartLinks.');
    $out=[];foreach($rows as$row){if(!is_array($row))continue;$label=trim((string)($row['label']??''));$url=trim((string)($row['url']??''));if($label===''&&$url==='')continue;
      if($label===''||mb_strlen($label)>80)throw new InvalidArgumentException('Each SmartLink needs a short label.');
      if(!filter_var($url,FILTER_VALIDATE_URL)||!preg_match('~^https://~i',$url))throw new InvalidArgumentException('Each SmartLink must use HTTPS.');
      $out[]=['id'=>social_ops_link_id($label,$url),'label'=>$label,'url'=>$url,'enabled'=>!empty($row['enabled'])];
    }
    if(!$out)throw new InvalidArgumentException('Keep at least one SmartLink.');return $out;
}
function social_ops_bulk_rows(array $file): array {
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||empty($file['tmp_name'])||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Choose a CSV file to import.');
    if((int)($file['size']??0)<1||(int)$file['size']>1048576)throw new InvalidArgumentException('Use a CSV file smaller than 1 MB.');
    $h=fopen($file['tmp_name'],'rb');if(!$h)throw new InvalidArgumentException('The CSV could not be read.');
    try{
        $header=fgetcsv($h);if(!$header)throw new InvalidArgumentException('The CSV is empty.');
        $header=array_map(static fn($v)=>strtolower(trim((string)$v)),$header);
        $required=['title','platform','text'];foreach($required as$key)if(!in_array($key,$header,true))throw new InvalidArgumentException('CSV columns must include title, platform and text.');
        $map=array_flip($header);$rows=[];
        while(($row=fgetcsv($h))!==false){
            if(count($rows)>=100)throw new InvalidArgumentException('Import up to 100 draft rows at a time.');
            $get=static fn(string $key):string=>trim((string)($row[$map[$key]??-1]??''));
            $title=$get('title');$platform=$get('platform');$text=$get('text');$source=$get('source_url');$planned=$get('planned_at');
            if($title===''&&$platform===''&&$text==='')continue;
            if($title===''||mb_strlen($title)>240)throw new InvalidArgumentException('Every CSV row needs a title of 240 characters or fewer.');
            if(!isset(social_ops_platforms()[$platform]))throw new InvalidArgumentException('Unsupported platform in CSV: '.$platform);
            if($text===''||mb_strlen($text)>20000)throw new InvalidArgumentException('Every CSV row needs post text within the platform drafting limit.');
            if($source!==''&&!publishing_public_url($source))throw new InvalidArgumentException('CSV source URLs must be safe public HTTPS URLs.');
            if($planned!==''&&!preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}\z/',$planned))throw new InvalidArgumentException('planned_at must use YYYY-MM-DDTHH:MM when provided.');
            $rows[]=['title'=>$title,'platform'=>$platform,'text'=>$text,'source_url'=>$source,'planned_at'=>$planned];
        }
        if(!$rows)throw new InvalidArgumentException('The CSV contains no draft rows.');
        return $rows;
    } finally {fclose($h);}
}
function social_ops_bulk_import(array $file,int $uid): array {
    $rows=social_ops_bulk_rows($file);$ids=[];
    foreach($rows as$row){
        $draft=publishing_default_draft();$draft['title']=$row['title'];$draft['source_url']=$row['source_url'];
        $draft['notes']='Bulk planning import'.($row['planned_at']!==''?' · intended slot '.$row['planned_at'].' '.(string)app_config('timezone','Africa/Nairobi'):'').' · Review before approval and scheduling.';
        $draft['variants'][$row['platform']]['enabled']=true;$draft['variants'][$row['platform']]['text']=$row['text'];
        $ids[]=publishing_save_package($draft,$uid);
    }
    return $ids;
}

function social_ops_watchlist_records(string $kind): array {
    if(!in_array($kind,['competitors','hashtags'],true))throw new InvalidArgumentException('Unknown social watchlist.');
    $raw=json_decode((string)setting('social_ops.'.$kind,'[]'),true);if(!is_array($raw))return [];
    usort($raw,static fn($a,$b)=>strcmp((string)($b['observed_at']??''),(string)($a['observed_at']??'')));return array_slice($raw,0,300);
}
function social_ops_watchlist_save(string $kind,array $record): void {
    $rows=social_ops_watchlist_records($kind);array_unshift($rows,$record);set_setting('social_ops.'.$kind,json_encode(array_slice($rows,0,300),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),true);
}
function social_ops_competitor_input(array $in,int $uid): array {
    $platform=$in['platform']??'';if(!is_string($platform)||!isset(social_ops_platforms()[$platform]))throw new InvalidArgumentException('Choose a supported platform.');
    $name=trim((string)($in['name']??''));if($name===''||mb_strlen($name)>160)throw new InvalidArgumentException('Add the public account name.');
    $url=trim((string)($in['url']??''));if(!publishing_public_url($url))throw new InvalidArgumentException('Use a safe public HTTPS profile URL.');
    $metrics=[];foreach(['followers'=>'Followers','posts'=>'Posts in period','engagements'=>'Engagements in period','views'=>'Views in period']as$key=>$label){$v=social_ops_metric($in[$key]??'',$label);if($v!==null)$metrics[$key]=$v;}
    if(!$metrics)throw new InvalidArgumentException('Enter at least one public metric.');
    $source=trim((string)($in['source']??''));if($source===''||mb_strlen($source)>500)throw new InvalidArgumentException('Record the public/native source and period.');
    return ['id'=>bin2hex(random_bytes(10)),'platform'=>$platform,'name'=>$name,'url'=>$url,'metrics'=>$metrics,'source'=>$source,'observed_at'=>gmdate('c'),'recorded_by'=>$uid];
}
function social_ops_hashtag_input(array $in,int $uid): array {
    $platform=$in['platform']??'';if(!is_string($platform)||!isset(social_ops_platforms()[$platform]))throw new InvalidArgumentException('Choose a supported platform.');
    $tag=trim((string)($in['hashtag']??''));$tag=ltrim($tag,'#');if($tag===''||mb_strlen($tag)>100||!preg_match('/^[\p{L}\p{N}_-]+$/u',$tag))throw new InvalidArgumentException('Enter one hashtag or topic token.');
    $metrics=[];foreach(['posts'=>'Observed posts','views'=>'Observed views','reach'=>'Observed reach','engagements'=>'Observed engagements']as$key=>$label){$v=social_ops_metric($in[$key]??'',$label);if($v!==null)$metrics[$key]=$v;}
    if(!$metrics)throw new InvalidArgumentException('Enter at least one observed metric.');
    $source=trim((string)($in['source']??''));if($source===''||mb_strlen($source)>500)throw new InvalidArgumentException('Record the public/native source and period.');
    return ['id'=>bin2hex(random_bytes(10)),'platform'=>$platform,'hashtag'=>'#'.$tag,'metrics'=>$metrics,'source'=>$source,'observed_at'=>gmdate('c'),'recorded_by'=>$uid];
}
function social_ops_latest_by_key(array $rows,string $key): array {
    $out=[];foreach($rows as$row){$id=(string)($row['platform']??'').'|'.mb_strtolower((string)($row[$key]??''));if($id==='|')continue;if(!isset($out[$id]))$out[$id]=$row;}return array_values($out);
}

function social_ops_report_csv(): string {
    $f=fopen('php://temp','w+');fputcsv($f,['type','platform_or_campaign','period_or_status','metric','value','source']);
    foreach(social_ops_latest_reports()as$platform=>$r)foreach(($r['metrics']??[])as$metric=>$value)fputcsv($f,['analytics',$platform,$r['period']??'',$metric,$value,$r['source']??'']);
    foreach(social_ops_campaigns()as$c)foreach(['jobs','published','scheduled','attention']as$metric)fputcsv($f,['campaign',$c['title'],'current',$metric,$c[$metric]??0,'Writer publishing records']);
    rewind($f);$csv=stream_get_contents($f);fclose($f);return (string)$csv;
}

function social_ops_sitemap_entries(): array {return [['loc'=>url('/links')]];}\n\nfunction social_ops_dispatch(string $path): bool {
    if(preg_match('~^/links/go/([a-f0-9]{12})$~D',$path,$m)){$row=social_ops_smartlink_resolve($m[1]);if(!$row){http_response_code(404);return true;}social_ops_smartlink_record($m[1]);redirect((string)$row['url'],302);return true;}
    if($path==='/links'){
        journeys_method(['GET','HEAD']);render('smartlinks',['page'=>'links','links'=>array_values(array_filter(social_ops_smartlinks(),static fn($r)=>!empty($r['enabled']))),'seo'=>['title'=>'Links · Waithaka Ndegwa','description'=>'Selected links from Waithaka Ndegwa.','canonical'=>url('/links')]]);return true;
    }
    if(!in_array($path,['/write/social-operations','/write/social-operations/observation','/write/social-operations/smartlinks','/write/social-operations/import','/write/social-operations/competitor','/write/social-operations/hashtag','/write/social-operations/report.csv'],true))return false;
    require_auth();private_response_headers();$uid=(int)current_user()['id'];
    if($path==='/write/social-operations/report.csv'){journeys_method(['GET']);header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="waithaka-social-report-'.date('Y-m-d').'.csv"');echo social_ops_report_csv();return true;}
    if($path==='/write/social-operations/competitor'){journeys_method(['POST']);verify_csrf();try{social_ops_watchlist_save('competitors',social_ops_competitor_input($_POST,$uid));flash('success','Competitor snapshot saved from the stated public source.');}catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'The competitor snapshot could not be saved.');}redirect(url('/write/social-operations#competitors'));}\n    if($path==='/write/social-operations/hashtag'){journeys_method(['POST']);verify_csrf();try{social_ops_watchlist_save('hashtags',social_ops_hashtag_input($_POST,$uid));flash('success','Hashtag/topic snapshot saved from the stated source.');}catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'The hashtag snapshot could not be saved.');}redirect(url('/write/social-operations#hashtags'));}\n    if($path==='/write/social-operations/import'){journeys_method(['POST']);verify_csrf();try{$ids=social_ops_bulk_import($_FILES['csv']??[],$uid);flash('success',count($ids).' draft packages imported. Review and approve them in Posting & calendar.');}catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException||$e instanceof RuntimeException?$e->getMessage():'The CSV could not be imported.');}redirect(url('/write/social-operations#bulk'));}\n    if($path==='/write/social-operations/observation'){journeys_method(['POST']);verify_csrf();try{social_ops_save_observation(social_ops_observation_input($_POST,$uid));flash('success','Performance observation saved. Best-time recommendations were recalculated from your own evidence.');}catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'The observation could not be saved.');}redirect(url('/write/social-operations#best-times'));}
    if($path==='/write/social-operations/smartlinks'){journeys_method(['POST']);verify_csrf();try{$rows=social_ops_smartlinks_input($_POST);set_setting('social_ops.smartlinks',json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),true);flash('success','SmartLinks saved.');}catch(Throwable $e){flash('error',$e instanceof InvalidArgumentException?$e->getMessage():'SmartLinks could not be saved.');}redirect(url('/write/social-operations#smartlinks'));}
    journeys_method(['GET','HEAD']);$observations=social_ops_observations();$best=[];foreach(array_keys(social_ops_platforms())as$p)$best[$p]=social_ops_best_times($p,$observations);
    render_write('social_operations',['writeTitle'=>'Social Operations','platforms'=>social_ops_platforms(),'calendar'=>social_ops_calendar(),'campaigns'=>social_ops_campaigns(),'reports'=>social_ops_latest_reports(),'observations'=>$observations,'bestTimes'=>$best,'connections'=>social_ops_connection_summary(),'inboxes'=>social_ops_inbox_links(),'smartlinks'=>social_ops_smartlinks(),'smartlinkClicks'=>social_ops_smartlink_clicks(),'competitors'=>social_ops_latest_by_key(social_ops_watchlist_records('competitors'),'name'),'hashtags'=>social_ops_latest_by_key(social_ops_watchlist_records('hashtags'),'hashtag')]);return true;
}
