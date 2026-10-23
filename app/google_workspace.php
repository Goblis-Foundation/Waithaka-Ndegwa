<?php
declare(strict_types=1);

/** Personal Google workspace. All remote reads are explicit; no background calendar replication. */
function google_workspace_identity(string $service,array $who,array $headers): array {
    if($service==='google_drive')return [['id'=>(string)$who['sub'],'name'=>(string)($who['email']??'Selected files'),'type'=>'drive_account']];
    if($service==='google_postmaster'){
        $r=publishing_http('GET','https://gmailpostmastertools.googleapis.com/v1/domains?pageSize=100',$headers)['data'];$out=[];
        foreach($r['domains']??[]as$d)if(($d['permission']??'')!=='PERMISSION_UNSPECIFIED')$out[]=['id'=>$d['name'],'name'=>substr($d['name'],8),'type'=>'sender_domain'];
        return $out;
    }
    return google_calendar_discover($headers);
}
function google_calendar_discover(array $headers): array {
    $items=[];$page='';
    for($n=0;$n<10;$n++){
        $r=publishing_http('GET','https://www.googleapis.com/calendar/v3/users/me/calendarList?maxResults=250'.($page!==''?'&pageToken='.rawurlencode($page):''),$headers)['data'];
        foreach($r['items']??[]as$c)if(empty($c['deleted']))$items[]=['id'=>(string)$c['id'],'name'=>(string)($c['summaryOverride']??$c['summary']??$c['id']),'timezone'=>(string)($c['timeZone']??'UTC'),'access'=>(string)($c['accessRole']??'')];
        $page=(string)($r['nextPageToken']??'');if($page==='')return $items;
    }
    throw new RuntimeException('More calendars were returned than can be safely reviewed. Use an account with a smaller subscribed calendar list.');
}
function google_calendar_observed(array $credentials,array $scopes,array $observed,int $userId): void {
    // Separate rows preserve every previously connected Google account.
    $q=db()->prepare("SELECT COUNT(*) FROM google_calendar_accounts WHERE user_id=? AND status<>'disconnected' AND google_sub<>?");$q->execute([$userId,$observed['id']]);if((int)$q->fetchColumn()>=20)throw new RuntimeException('Up to 20 Google calendar accounts can be connected. Remove an unused account before adding another.');
    db()->prepare("INSERT INTO google_calendar_accounts(user_id,google_sub,display_name,status,credentials_encrypted,scopes_json,calendars_json,selected_json,verified_at) VALUES(?,?,?,'pending_confirmation',?,?,?,'[]',UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),status='pending_confirmation',credentials_encrypted=VALUES(credentials_encrypted),scopes_json=VALUES(scopes_json),calendars_json=VALUES(calendars_json),selected_json='[]',verified_at=UTC_TIMESTAMP()")
        ->execute([$userId,$observed['id'],$observed['name'],encrypt_secret(publishing_json($credentials)),publishing_json($scopes),publishing_json($observed['resources'])]);
}
function google_calendar_accounts(int $userId): array {
    $q=db()->prepare("SELECT * FROM google_calendar_accounts WHERE user_id=? AND status<>'disconnected' ORDER BY display_name,id");$q->execute([$userId]);return $q->fetchAll();
}
function google_calendar_owned(int $id,int $userId): array {
    $q=db()->prepare('SELECT * FROM google_calendar_accounts WHERE id=? AND user_id=?');$q->execute([$id,$userId]);$r=$q->fetch();
    if(!$r||$r['status']==='disconnected')throw new InvalidArgumentException('Choose one of your connected calendar accounts.');return $r;
}
function google_calendar_selection(array $available,mixed $input): array {
    if(!is_array($input)||count($input)>30)throw new InvalidArgumentException('Choose up to 30 calendars per account.');
    $valid=array_column($available,'id');$out=[];
    foreach($input as$id){if(!is_string($id)||!in_array($id,$valid,true))throw new InvalidArgumentException('Select only calendars returned for this account.');$out[]=$id;}
    return array_values(array_unique($out));
}
function google_calendar_token(array $account): string {
    if($account['status']!=='connected')throw new RuntimeException('Confirm calendars for this account first.');
    $lock='google-calendar-token-'.$account['id'];$q=db()->prepare('SELECT GET_LOCK(?,3)');$q->execute([$lock]);
    if(!(int)$q->fetchColumn())throw new RuntimeException('This connection is being refreshed. Try again shortly.');
    try{
        $account=google_calendar_owned((int)$account['id'],(int)$account['user_id']);
        if($account['status']!=='connected')throw new RuntimeException('Review this account again.');
        $c=publishing_decode(decrypt_secret((string)$account['credentials_encrypted']));
        if(empty($c['access_token']))throw new RuntimeException('Reconnect this calendar account.');
        if((int)($c['expires_at']??0)<=time()+120){
            if(empty($c['refresh_token']))throw new RuntimeException('Reconnect this calendar account.');
            $r=publishing_http('POST','https://oauth2.googleapis.com/token',[],publishing_client('google')+['grant_type'=>'refresh_token','refresh_token'=>$c['refresh_token']],true)['data'];
            if(empty($r['access_token']))throw new RuntimeException('Google did not refresh access. Reconnect this account.');
            $c=array_merge($c,$r);$c['expires_at']=time()+(int)($r['expires_in']??3600);
            $u=db()->prepare('UPDATE google_calendar_accounts SET credentials_encrypted=? WHERE id=? AND user_id=? AND status=\'connected\' AND credentials_encrypted=?');
            $u->execute([encrypt_secret(publishing_json($c)),$account['id'],$account['user_id'],$account['credentials_encrypted']]);
            if($u->rowCount()!==1)throw new RuntimeException('The connection changed during refresh. Reload and review it.');
        }
        return (string)$c['access_token'];
    }finally{$q=db()->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}
function google_workspace_date(mixed $value,string $default): string {
    if($value===null||$value==='')return $default;
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value))throw new InvalidArgumentException('Choose a valid date.');
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
    if(!$d||$d->format('Y-m-d')!==$value)throw new InvalidArgumentException('Choose a valid calendar date.');return $value;
}
function google_calendar_agenda(int $userId,string $start,string $end): array {
    if($end<$start||strtotime($end)-strtotime($start)>31*86400)throw new InvalidArgumentException('Choose a calendar range of up to 31 days.');
    $out=[];$errors=[];$seen=[];$truncated=false;
    foreach(google_calendar_accounts($userId)as$a){
        if($a['status']!=='connected')continue;$selected=publishing_decode($a['selected_json']);if(!$selected)continue;
        try{$headers=publishing_bearer(google_calendar_token($a));}catch(Throwable){$errors[]=$a['display_name'].': reconnect or review access.';continue;}
        $names=array_column(publishing_decode($a['calendars_json']),'name','id');
        foreach($selected as$id){
            // The same subscribed calendar can be present in several accounts. Read once.
            if(isset($seen[$id]))continue;
            try{
                $page='';$calendarItems=[];
                for($n=0;$n<4;$n++){
                    $params=['timeMin'=>$start.'T00:00:00Z','timeMax'=>gmdate('Y-m-d',strtotime($end.' +1 day')).'T00:00:00Z','singleEvents'=>'true','orderBy'=>'startTime','maxResults'=>250];if($page!=='')$params['pageToken']=$page;
                    $r=publishing_http('GET','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($id).'/events?'.http_build_query($params,'','&',PHP_QUERY_RFC3986),$headers)['data'];
                    foreach($r['items']??[]as$e){if(($e['status']??'')==='cancelled')continue;$calendarItems[]=['calendar'=>$names[$id]??'Selected calendar','account'=>$a['display_name'],'title'=>$e['summary']??'Busy','start'=>$e['start']['dateTime']??$e['start']['date']??'','end'=>$e['end']['dateTime']??$e['end']['date']??'','all_day'=>isset($e['start']['date']),'url'=>google_workspace_link($e['htmlLink']??'',['calendar.google.com','www.google.com'])];}
                    $page=(string)($r['nextPageToken']??'');if($page==='')break;
                }
                if($page!=='')$truncated=true;$seen[$id]=true;$out=array_merge($out,$calendarItems);
            }catch(Throwable){$errors[]=($names[$id]??'Selected calendar').': unavailable; no events inferred.';}
        }
        db()->prepare('UPDATE google_calendar_accounts SET last_read_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([$a['id'],$userId]);
    }
    usort($out,static fn($a,$b)=>(strtotime($a['start'])?:0)<=>(strtotime($b['start'])?:0));
    return ['events'=>$out,'errors'=>$errors,'truncated'=>$truncated,'range'=>$start.' – '.$end,'timezone'=>'UTC; timed events retain their supplied offsets. All-day end dates are exclusive.'];
}
function google_workspace_link(string $value,array $hosts): string {
    if($value==='')return ''; $p=parse_url($value);
    if(($p['scheme']??'')!=='https'||!in_array(strtolower($p['host']??''),$hosts,true)||isset($p['user'])||isset($p['pass'])||isset($p['port'])||preg_match('/[\x00-\x20\x7f]/',$value))throw new InvalidArgumentException('Use a supported HTTPS Google link.');return $value;
}
function google_workspace_reports(): array {
    return [
        'ga4-countries'=>['Google Analytics','google_analytics','country','Global readership','activeUsers','sessions','screenPageViews','engagementRate'],
        'ga4-devices'=>['Google Analytics','google_analytics','deviceCategory','Devices','activeUsers','sessions','screenPageViews','engagementRate'],
        'ga4-channels'=>['Google Analytics','google_analytics','sessionDefaultChannelGroup','Acquisition channels','activeUsers','sessions','screenPageViews','engagementRate'],
        'ga4-pages'=>['Google Analytics','google_analytics','landingPage','Landing pages','activeUsers','sessions','screenPageViews','engagementRate'],
        'ga4-trend'=>['Google Analytics','google_analytics','date','Readership trend','activeUsers','sessions','screenPageViews','engagementRate'],
        'ga4-campaigns'=>['Google Analytics','google_analytics','sessionCampaignName','Campaign attribution','activeUsers','sessions','screenPageViews','engagementRate'],
        'gsc-countries'=>['Search Console','google_search_console','country','Search countries'],
        'gsc-devices'=>['Search Console','google_search_console','device','Search devices'],
        'gsc-pages'=>['Search Console','google_search_console','page','Search pages'],
        'gsc-queries'=>['Search Console','google_search_console','query','Search queries'],
        'gsc-trend'=>['Search Console','google_search_console','date','Search trend'],
        'youtube-trend'=>['YouTube Analytics','google_youtube_analytics','day','Video performance over time'],
        'youtube-countries'=>['YouTube Analytics','google_youtube_analytics','country','Video audience countries'],
        'youtube-videos'=>['YouTube Analytics','google_youtube_analytics','video','Individual video performance'],
    ];
}
function google_workspace_connection(string $service): array {
    $c=publishing_connection($service);
    if(!$c||$c['status']!=='connected'||empty($c['selected_resource']))throw new RuntimeException('Connect and confirm '.(publishing_services()[$service]['name']??$service).' first.');return $c;
}
function google_workspace_report(string $key,string $start,string $end,?callable $transport=null): array {
    $spec=google_workspace_reports()[$key]??null;if(!$spec)throw new InvalidArgumentException('Choose an available report.');
    if($end<$start||strtotime($end)-strtotime($start)>366*86400||$end>gmdate('Y-m-d'))throw new InvalidArgumentException('Choose a past reporting range of up to one year.');
    $c=google_workspace_connection($spec[1]);$h=publishing_bearer(publishing_access_token($c));$id=$c['selected_resource'];$send=$transport??'publishing_http';
    $rows=[];$columns=[];
    if(str_starts_with($key,'ga4-')){
        if(!preg_match('~^properties/\d+$~D',$id))throw new RuntimeException('Invalid GA4 property.');
        $metrics=array_slice($spec,4);$r=$send('POST','https://analyticsdata.googleapis.com/v1beta/'.$id.':runReport',$h,['dateRanges'=>[['startDate'=>$start,'endDate'=>$end]],'dimensions'=>[['name'=>$spec[2]]],'metrics'=>array_map(static fn($m)=>['name'=>$m],$metrics),'orderBys'=>[['dimension'=>['dimensionName'=>$spec[2]],'desc'=>false]],'limit'=>100])['data'];
        $columns=array_merge([$spec[2]],$metrics);foreach($r['rows']??[]as$row)$rows[]=array_merge([$row['dimensionValues'][0]['value']??''],array_column($row['metricValues']??[],'value'));
    }elseif(str_starts_with($key,'gsc-')){
        if($end>gmdate('Y-m-d',strtotime('-3 days')))throw new InvalidArgumentException('Search Console reports end at least three days ago to avoid incomplete data.');
        $r=$send('POST','https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($id).'/searchAnalytics/query',$h,['startDate'=>$start,'endDate'=>$end,'dimensions'=>[$spec[2]],'rowLimit'=>100,'dataState'=>'final'])['data'];
        $columns=[$spec[2],'Clicks','Impressions','CTR (fraction)','Average position'];foreach($r['rows']??[]as$row)$rows[]=[$row['keys'][0]??'',$row['clicks']??null,$row['impressions']??null,$row['ctr']??null,$row['position']??null];
    }else{
        $params=['ids'=>'channel=='.$id,'startDate'=>$start,'endDate'=>$end,'dimensions'=>$spec[2],'metrics'=>'views,estimatedMinutesWatched,averageViewDuration,subscribersGained,subscribersLost','sort'=>$spec[2]==='day'?'day':'-views','maxResults'=>100];
        $r=$send('GET','https://youtubeanalytics.googleapis.com/v2/reports?'.http_build_query($params,'','&',PHP_QUERY_RFC3986),$h)['data'];$columns=array_column($r['columnHeaders']??[],'name');$rows=$r['rows']??[];
    }
    return ['key'=>$key,'title'=>$spec[3],'columns'=>$columns,'rows'=>$rows,'start'=>$start,'end'=>$end,'observed_at'=>gmdate('c'),'destination_hash'=>hash('sha256',$id),'note'=>'Up to 100 rows. Empty results mean no data returned, not measured zero. Country and device reports may be privacy-limited.'];
}
function google_workspace_save_report(array $r,int $uid): void {
    db()->prepare('INSERT INTO google_report_snapshots(user_id,report_key,destination_hash,payload_json,observed_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$uid,$r['key'],$r['destination_hash'],publishing_json($r)]);
    // Keep a small rolling history; private account content is never included.
    db()->prepare('DELETE FROM google_report_snapshots WHERE user_id=? AND observed_at < UTC_TIMESTAMP() - INTERVAL 30 DAY')->execute([$uid]);
}
function google_workspace_diagnostics(string $action,string $input=''): array {
    if($action==='ga4-diagnostics'){
        $c=google_workspace_connection('google_analytics');$id=$c['selected_resource'];if(!preg_match('~^properties/\d+$~D',$id))throw new RuntimeException('Invalid property.');$h=publishing_bearer(publishing_access_token($c));
        $streams=publishing_http('GET','https://analyticsadmin.googleapis.com/v1beta/'.$id.'/dataStreams?pageSize=200',$h)['data'];$rows=[];$match=false;$tag=(string)setting('google.ga4','G-RN0YXC2WCH');
        foreach($streams['dataStreams']??[]as$s){$web=$s['webStreamData']??null;if(!$web)continue;$same=hash_equals($tag,(string)($web['measurementId']??''));$match=$match||$same;$rows[]=[$s['displayName']??'', $web['defaultUri']??'', $web['measurementId']??'',$same?'Matches deployed tag':'Different tag'];}
        $r=publishing_http('POST','https://analyticsdata.googleapis.com/v1beta/'.$id.':runRealtimeReport',$h,['metrics'=>[['name'=>'activeUsers']]])['data'];
        return ['title'=>'GA4 collection diagnostics','columns'=>['Web stream','Website','Measurement ID','Match'],'rows'=>$rows,'note'=>($match?'The deployed tag matches a stream in this property.':'The deployed tag was not found in this property. Check property selection and tag settings.').' Realtime active users returned: '.($r['rows'][0]['metricValues'][0]['value']??'No rows').'. Reader consent, blockers and processing affect collection. No consent or settings were changed.'];
    }
    $c=google_workspace_connection('google_search_console');$h=publishing_bearer(publishing_access_token($c));
    if($action==='sitemaps'){
        $r=publishing_http('GET','https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($c['selected_resource']).'/sitemaps',$h)['data'];$rows=[];
        foreach($r['sitemap']??[]as$s)$rows[]=[$s['path']??'',$s['lastDownloaded']??'Not returned',$s['warnings']??'Not returned',$s['errors']??'Not returned'];
        return ['title'=>'Search Console sitemaps','columns'=>['Sitemap','Last downloaded','Warnings','Errors'],'rows'=>$rows,'note'=>'Read-only status. Submission and indexing are separate actions.'];
    }
    $url=google_workspace_public_url($input);
    $r=publishing_http('POST','https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',$h,['inspectionUrl'=>$url,'siteUrl'=>$c['selected_resource'],'languageCode'=>'en-US'])['data']['inspectionResult']['indexStatusResult']??[];
    $rows=[];foreach(['verdict','coverageState','robotsTxtState','indexingState','lastCrawlTime','pageFetchState','googleCanonical','userCanonical']as$k)$rows[]=[$k,$r[$k]??'Not returned'];
    return ['title'=>'Google URL indexing inspection','columns'=>['Check','Result'],'rows'=>$rows,'note'=>'Google’s indexed copy, not a live crawl or request to index this URL.'];
}
function google_workspace_public_url(string $input): string {
    $input=trim($input);if($input==='')return url('/');$p=parse_url($input);$host=strtolower((string)parse_url(base_url(),PHP_URL_HOST));$path=$p['path']??'/';
    if(($p['scheme']??'')!=='https'||strtolower($p['host']??'')!==$host||isset($p['port'])||isset($p['user'])||isset($p['query'])||isset($p['fragment'])||str_contains($path,'%')||str_contains($path,'..')||!preg_match('~^/(?:$|about$|contact$|start-here$|professional-information$|my-thoughts(?:/[a-z0-9-]+)?$|resources(?:/[a-z0-9-]+)?$|startup-journeys(?:/[a-z0-9-]+)?$|watch(?:/[a-z0-9-]+)?$)~D',$path))throw new InvalidArgumentException('Choose a canonical public content URL on this website, without query parameters.');
    return rtrim(base_url(),'/').$path;
}
function google_workspace_performance(string $action,string $input,string $strategy): array {
    $url=google_workspace_public_url($input);
    if($action==='crux'){
        $key=trim((string)setting('google.crux_key',''));if($key==='')throw new RuntimeException('Save a Chrome UX Report API key first.');
        $r=publishing_http('POST','https://chromeuxreport.googleapis.com/v1/records:queryRecord',['x-goog-api-key: '.$key],['url'=>$url,'formFactor'=>$strategy==='desktop'?'DESKTOP':'PHONE'])['data'];$rows=[];
        foreach($r['record']['metrics']??[]as$name=>$m)$rows[]=[$name,$m['percentiles']['p75']??'Not returned'];
        return ['title'=>'Real-user Core Web Vitals','columns'=>['Metric','75th percentile'],'rows'=>$rows,'note'=>'CrUX aggregated field data for the returned collection period: '.json_encode($r['record']['collectionPeriod']??new stdClass()).'. A low-traffic page may have no eligible data.'];
    }
    $key=trim((string)setting('google.pagespeed_key',''));$params=['url'=>$url,'strategy'=>$strategy==='desktop'?'desktop':'mobile'];if($key!=='')$params['key']=$key;
    $r=publishing_http('GET','https://pagespeedonline.googleapis.com/pagespeedonline/v5/runPagespeed?'.http_build_query($params,'','&',PHP_QUERY_RFC3986).'&category=performance&category=accessibility&category=best-practices&category=seo')['data'];$rows=[];
    foreach($r['lighthouseResult']['categories']??[]as$cat)$rows[]=[$cat['title']??'',isset($cat['score'])?round((float)$cat['score']*100):'Not returned'];
    foreach(['largest-contentful-paint','total-blocking-time','cumulative-layout-shift','speed-index']as$id){$a=$r['lighthouseResult']['audits'][$id]??[];$rows[]=[$a['title']??$id,$a['displayValue']??'Not returned'];}
    return ['title'=>'PageSpeed · '.$params['strategy'],'columns'=>['Check','Result'],'rows'=>$rows,'note'=>'Lighthouse laboratory test, not real-user field performance. Test time: '.($r['analysisUTCTimestamp']??'Not returned').'.'];
}
function google_workspace_postmaster(): array {
    $c=google_workspace_connection('google_postmaster');$id=$c['selected_resource'];
    if(!preg_match('~^domains/[a-z0-9.-]+$~iD',$id))throw new RuntimeException('Invalid verified domain.');
    $r=publishing_http('GET','https://gmailpostmastertools.googleapis.com/v1/'.$id.'/trafficStats?startDate.year='.gmdate('Y',strtotime('-28 days')).'&startDate.month='.gmdate('n',strtotime('-28 days')).'&startDate.day='.gmdate('j',strtotime('-28 days')).'&pageSize=100',publishing_bearer(publishing_access_token($c)))['data'];$rows=[];
    foreach($r['trafficStats']??[]as$d)$rows[]=[basename($d['name']??''),$d['domainReputation']??'Not returned',$d['userReportedSpamRatio']??'Not returned',$d['spfSuccessRatio']??'Not returned',$d['dkimSuccessRatio']??'Not returned',$d['dmarcSuccessRatio']??'Not returned'];
    return ['title'=>'Gmail sender reputation','columns'=>['Date','Domain reputation','Spam ratio','SPF ratio','DKIM ratio','DMARC ratio'],'rows'=>$rows,'note'=>'Postmaster may suppress low-volume data. This is aggregate Gmail reputation, not confirmation of delivery to an individual inbox. Use Workspace Email Log Search for individual delivery investigations.'];
}
function google_workspace_gemini(string $input,string $mode,bool $grounded): array {
    $input=studio_input($input);$key=trim((string)setting('google.gemini_key',''));if($key==='')throw new RuntimeException('Save a Gemini API key and choose its billing/data tier first.');
    $model=(string)setting('google.gemini_model','gemini-2.5-flash');if(!preg_match('/^gemini-[a-z0-9.-]{1,80}$/D',$model))throw new RuntimeException('Choose a valid Gemini model identifier.');
    $body=['systemInstruction'=>['parts'=>[['text'=>studio_instructions($mode).($grounded?' Use search sources to investigate claims and distinguish verified sources from suggestions.':'')]]],'contents'=>[['role'=>'user','parts'=>[['text'=>$input]]]],'generationConfig'=>['maxOutputTokens'=>2400]];
    if($grounded)$body['tools']=[['google_search'=>new stdClass()]];
    $r=publishing_http('POST','https://generativelanguage.googleapis.com/v1beta/models/'.$model.':generateContent',['x-goog-api-key: '.$key],$body)['data'];$candidate=$r['candidates'][0]??[];$text='';
    foreach($candidate['content']['parts']??[]as$part)if(empty($part['thought']))$text.=(string)($part['text']??'');
    if($text==='')throw new RuntimeException('No draft was returned. Your input remains available.');$sources=[];
    foreach($candidate['groundingMetadata']['groundingChunks']??[]as$chunk){$web=$chunk['web']??[];$link=(string)($web['uri']??'');$p=parse_url($link);if(($p['scheme']??'')==='https'&&!isset($p['user']))$sources[]=['title'=>(string)($web['title']??'Source'),'url'=>$link];}
    return ['text'=>$text,'sources'=>$sources,'grounded'=>$grounded,'note'=>'Unverified editorial suggestion. Check claims and source support before publishing. No article or social post was saved or released.'];
}
/** Plain-text export only; fixed Drive API host, no redirects, strict byte limit. */
function google_drive_text(string $id,array $connection): array {
    if(!preg_match('/^[a-zA-Z0-9_-]{10,200}$/D',$id))throw new InvalidArgumentException('Choose a Google Doc returned by Picker.');
    $headers=publishing_bearer(publishing_access_token($connection));$m=publishing_http('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?fields=id,name,mimeType,trashed,capabilities(canDownload)',$headers)['data'];
    if(($m['mimeType']??'')!=='application/vnd.google-apps.document'||!empty($m['trashed'])||empty($m['capabilities']['canDownload']))throw new RuntimeException('Choose a downloadable Google Doc.');
    $raw='';$ch=curl_init('https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'/export?mimeType=text%2Fplain');
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$raw):int{if(strlen($raw)+strlen($part)>60000)return 0;$raw.=$part;return strlen($part);}]);
    $ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($ok===false||$code!==200||!mb_check_encoding($raw,'UTF-8'))throw new RuntimeException('The document could not be exported within the 60 KB limit. No draft was saved.');
    return ['title'=>mb_substr((string)($m['name']??'Imported Google Doc'),0,240),'text'=>$raw];
}
function google_workspace_health(): array {
    $rows=[];foreach(['google_search_console','google_analytics','google_youtube_read','google_youtube_upload','google_youtube_analytics','google_drive','google_postmaster']as$s){$c=publishing_connection($s);$credentials=$c?publishing_credentials($c):[];$expiry=(int)($credentials['expires_at']??0);$refresh=(int)($credentials['refresh_token_expires_in']??0);
        $rows[]=['name'=>publishing_services()[$s]['name'],'status'=>$c['status']??'not_connected','verified'=>$c['verified_at']??'Not connected','refresh'=>!empty($credentials['refresh_token'])?'Refresh credential saved':'Reconnect if token expires','expiry'=>$expiry?gmdate('Y-m-d H:i',$expiry).' UTC':'Not returned','duration'=>$refresh>0?round($refresh/86400,1).' days reported at grant':'Not returned'];}
    return $rows;
}
function google_workspace_dispatch(string $path): bool {
    if(!preg_match('~^/write/google(?:/|$)~',$path))return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];$result=null;$error='';$draft=null;$agenda=null;$input='';$mode='outline';
    try{
        if($path==='/write/google/picker-token'){
            journeys_method(['POST']);verify_csrf();$c=google_workspace_connection('google_drive');
            header('Content-Type: application/json');echo json_encode(['token'=>publishing_access_token($c),'key'=>(string)setting('google.picker_key',''),'appId'=>explode('-',publishing_client('google')['client_id'])[0]],JSON_THROW_ON_ERROR|JSON_HEX_TAG);return true;
        }
        if(is_post()){
            verify_csrf();$action=$_POST['action']??'';if(!is_string($action))throw new InvalidArgumentException('Choose an action.');
            if($action==='settings'){
                foreach(['pagespeed_key','crux_key','picker_key','gemini_key']as$key){$v=$_POST[$key]??'';if(!is_string($v)||strlen($v)>1000||preg_match('/[\x00-\x20\x7f]/',$v))throw new InvalidArgumentException('A key has invalid characters.');if($v!=='')set_setting('google.'.$key,$v,true);}
                $model=$_POST['gemini_model']??'';if(!is_string($model)||!preg_match('/^gemini-[a-z0-9.-]{1,80}$/D',$model))throw new InvalidArgumentException('Enter a Gemini model identifier.');set_setting('google.gemini_model',$model);
                $booking=$_POST['booking_url']??'';if(!is_string($booking))throw new InvalidArgumentException('Enter a Google booking link.');set_setting('google.booking_url',google_workspace_link(trim($booking),['calendar.google.com','calendar.app.google']));
                flash('success','Google preferences saved. No account permission, paid service or background job was activated.');redirect(url('/write/google#settings'));
            }elseif($action==='calendar-select'){
                $a=google_calendar_owned((int)($_POST['account_id']??0),$uid);if(($_POST['confirm_identity']??'')!=='1')throw new InvalidArgumentException('Confirm this account and calendar selection.');
                if(!is_string($_POST['revision']??null)||!hash_equals(hash('sha256',$a['credentials_encrypted'].$a['calendars_json']),$_POST['revision']))throw new InvalidArgumentException('The account changed. Reload and review the returned calendars.');
                $selected=google_calendar_selection(publishing_decode($a['calendars_json']),$_POST['calendars']??[]);
                $q=db()->prepare("UPDATE google_calendar_accounts SET status='connected',selected_json=? WHERE id=? AND user_id=? AND verified_at=?");$q->execute([publishing_json($selected),$a['id'],$uid,$a['verified_at']]);
                flash('success','Calendar selection saved privately. Other connected accounts were retained.');redirect(url('/write/google#calendars'));
            }elseif($action==='calendar-remove'){
                $a=google_calendar_owned((int)($_POST['account_id']??0),$uid);db()->prepare("UPDATE google_calendar_accounts SET status='disconnected',credentials_encrypted=NULL,selected_json='[]',calendars_json='[]' WHERE id=? AND user_id=?")->execute([$a['id'],$uid]);flash('success','Saved calendar access removed. You can also revoke the app in your Google account.');redirect(url('/write/google#calendars'));
            }elseif($action==='calendar-refresh'){
                $a=google_calendar_owned((int)($_POST['account_id']??0),$uid);$available=google_calendar_discover(publishing_bearer(google_calendar_token($a)));$old=publishing_decode($a['selected_json']);$selected=array_values(array_intersect($old,array_column($available,'id')));db()->prepare('UPDATE google_calendar_accounts SET calendars_json=?,selected_json=?,verified_at=UTC_TIMESTAMP() WHERE id=? AND user_id=?')->execute([publishing_json($available),publishing_json($selected),$a['id'],$uid]);flash('success','Calendar list refreshed. Newly discovered calendars remain unselected.');redirect(url('/write/google#calendars'));
            }else{
                if(!journeys_rate_limit('google-workspace-'.$action,(string)$uid,$action==='gemini'?8:20,3600))throw new RuntimeException('Please wait before requesting another report.');
                if($action==='agenda')$agenda=google_calendar_agenda($uid,google_workspace_date($_POST['start']??null,gmdate('Y-m-d')),google_workspace_date($_POST['end']??null,gmdate('Y-m-d',strtotime('+7 days'))));
                elseif($action==='report'){$key=is_string($_POST['report']??null)?$_POST['report']:'';$result=google_workspace_report($key,google_workspace_date($_POST['start']??null,gmdate('Y-m-d',strtotime('-30 days'))),google_workspace_date($_POST['end']??null,gmdate('Y-m-d',strtotime('-3 days'))));google_workspace_save_report($result,$uid);if(($_POST['compare']??'')==='1'){$days=(int)((strtotime($result['end'])-strtotime($result['start']))/86400)+1;$previousEnd=gmdate('Y-m-d',strtotime($result['start'].' -1 day'));$previousStart=gmdate('Y-m-d',strtotime($previousEnd.' -'.($days-1).' days'));$result['comparison']=google_workspace_report($key,$previousStart,$previousEnd);}}
                elseif(in_array($action,['ga4-diagnostics','sitemaps','inspect'],true))$result=google_workspace_diagnostics($action,is_string($_POST['url']??null)?$_POST['url']:'');
                elseif(in_array($action,['pagespeed','crux'],true))$result=google_workspace_performance($action,is_string($_POST['url']??null)?$_POST['url']:'',($_POST['strategy']??'')==='desktop'?'desktop':'mobile');
                elseif($action==='postmaster')$result=google_workspace_postmaster();
                elseif($action==='gemini'){$input=studio_input($_POST['selected_text']??null);$mode=is_string($_POST['mode']??null)?$_POST['mode']:'';if(($_POST['ai_consent']??'')!=='1')throw new InvalidArgumentException('Confirm that this selected text may be sent to Google Gemini.');$draft=google_workspace_gemini($input,$mode,($_POST['grounded']??'')==='1');}
                elseif($action==='drive-import'){if(($_POST['confirm_import']??'')!=='1')throw new InvalidArgumentException('Confirm importing the chosen document as a private draft.');$c=google_workspace_connection('google_drive');$d=google_drive_text(is_string($_POST['file_id']??null)?$_POST['file_id']:'',$c);$saved=writer_save_post(['id'=>0,'title'=>$d['title'],'content_html'=>'<p>'.nl2br(e($d['text']),false).'</p>','writer_note'=>'Imported from a selected Google Doc. Review content and permissions before publication.'],$uid,false);flash('success','Google Doc imported as a private draft.');redirect(url('/write/edit/'.$saved['id']));}
                else throw new InvalidArgumentException('Choose a supported Google action.');
            }
        }
    }catch(Throwable $e){$error=!($e instanceof PDOException)&&($e instanceof InvalidArgumentException||$e instanceof RuntimeException)?$e->getMessage():'This request could not be completed. Your saved content is unchanged.';if($path==='/write/google/picker-token'){http_response_code(400);header('Content-Type: application/json');echo '{"error":"Reconnect Drive or check Picker settings."}';return true;}}
    render_write('google_workspace',['writeTitle'=>'Google workspace','accounts'=>google_calendar_accounts($uid),'health'=>google_workspace_health(),'result'=>$result,'agenda'=>$agenda,'draft'=>$draft,'error'=>$error,'input'=>$input,'mode'=>$mode]);return true;
}
