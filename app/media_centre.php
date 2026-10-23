<?php
declare(strict_types=1);

/** Private account reporting. No provider calls on page load and no publication side effects. */
function media_centre_platforms(): array {
    return [
        'linkedin'=>['name'=>'LinkedIn','service'=>'linkedin_analytics','note'=>'Member post analytics needs the approved analytics product and a separate read grant.'],
        'facebook'=>['name'=>'Facebook','service'=>'facebook_pages','note'=>'The API returns confirmed Page data. Professional-profile insights can be recorded from the native dashboard.'],
        'instagram'=>['name'=>'Instagram','service'=>'instagram','note'=>'Connected professional-account totals are available where returned. Record additional Insights from the native dashboard.'],
        'x'=>['name'=>'X','service'=>'x','note'=>'Account totals use your authorised X API access and may incur API charges.'],
        'youtube'=>['name'=>'YouTube','service'=>'google_youtube_read','note'=>'Channel totals use the confirmed YouTube channel; period-specific Studio analytics can be recorded separately.'],
        'tiktok'=>['name'=>'TikTok','service'=>'tiktok_read','note'=>'Profile totals and up to 20 public videos use the separate read grant; native analytics may offer more detail.']
    ];
}
function media_centre_connect_services(): array {
    return ['linkedin'=>'linkedin','facebook'=>'facebook_pages','instagram'=>'instagram','x'=>'x','youtube'=>'google_youtube_upload','tiktok'=>'tiktok_read'];
}
function writer_workspace_summary(): array {
    $connected=0;foreach(media_centre_connect_services()as$slug=>$service){$services=[$service];if($slug==='linkedin')$services[]='linkedin_analytics';if($slug==='youtube')$services[]='google_youtube_read';if($slug==='tiktok')$services[]='tiktok_business';foreach($services as$granted){$c=publishing_connection($granted);if($c&&$c['status']==='connected'&&!empty($c['selected_resource'])){$connected++;break;}}}
    $statuses=db()->query('SELECT status,COUNT(*) n FROM publishing_jobs GROUP BY status')->fetchAll();$attention=0;$scheduled=0;
    foreach($statuses as$row){if($row['status']==='scheduled')$scheduled+=(int)$row['n'];elseif(in_array($row['status'],['assisted','reminder_queued','reminder_sent','failed','reconnect','ambiguous'],true))$attention+=(int)$row['n'];}
    return ['connected'=>$connected,'attention'=>$attention,'scheduled'=>$scheduled];
}
function media_centre_count(mixed $value): ?int {
    if(is_int($value)&&$value>=0&&$value<=1000000000000)return $value;
    if(is_string($value)&&preg_match('/\A(?:0|[1-9][0-9]{0,12})\z/',$value)&&strlen($value)<=13&&(float)$value<=1000000000000)return (int)$value;
    return null;
}
function media_centre_manual(array $input): array {
    $slug=$input['platform']??'';if(!is_string($slug)||!isset(media_centre_platforms()[$slug]))throw new InvalidArgumentException('Choose a social account.');
    $account=$input['account']??'';$basis=$input['basis']??'';
    if(!is_string($account)||trim($account)===''||mb_strlen($account)>160||!is_string($basis)||trim($basis)===''||mb_strlen($basis)>600)throw new InvalidArgumentException('Add the account identity and the native report or export used.');
    $start=$input['start']??'';$end=$input['end']??'';
    foreach([$start,$end]as$date){if(!is_string($date)||!preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$date)||!($d=DateTimeImmutable::createFromFormat('!Y-m-d',$date))||$d->format('Y-m-d')!==$date)throw new InvalidArgumentException('Choose valid report dates.');}
    if($end<$start||$end>date('Y-m-d'))throw new InvalidArgumentException('Report dates must be ordered and cannot be in the future.');
    $metrics=[];foreach(['followers'=>'Followers at period end','views'=>'Views','reach'=>'Accounts reached','impressions'=>'Impressions','engagements'=>'Engagements','clicks'=>'Link clicks']as$key=>$label){$raw=$input[$key]??'';if($raw==='')continue;$count=media_centre_count($raw);if($count===null)throw new InvalidArgumentException('Use nonnegative whole numbers; leave unavailable metrics blank.');$metrics[$label]=$count;}
    if(!$metrics)throw new InvalidArgumentException('Enter at least one reported metric.');
    return ['platform'=>$slug,'account'=>trim($account),'source'=>'Owner-recorded native report','basis'=>trim($basis),'period'=>$start.' to '.$end.' (inclusive)','observed_at'=>gmdate('c'),'metrics'=>$metrics];
}
function media_centre_history(string $slug): array {
    if(!isset(media_centre_platforms()[$slug]))throw new InvalidArgumentException('Unknown account.');
    $value=json_decode((string)setting('media_centre.reports.'.$slug,'[]'),true);return is_array($value)?array_slice($value,0,20):[];
}
function media_centre_save_report(array $report): void {
    $slug=$report['platform'];if(!isset(media_centre_platforms()[$slug]))throw new InvalidArgumentException('Unknown account.');
    $pdo=db();$lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$name='media-centre-report-'.$slug;$lock->execute([$name]);
    if((int)$lock->fetchColumn()!==1)throw new InvalidArgumentException('Another report is being saved. Please try again.');
    try{
        // Read under the lock directly, avoiding the request-scoped settings cache.
        $q=$pdo->prepare('SELECT setting_value,is_secret FROM settings WHERE setting_key=?');$q->execute(['media_centre.reports.'.$slug]);$row=$q->fetch();$old=$row?json_decode((int)$row['is_secret']===1?decrypt_secret($row['setting_value']):$row['setting_value'],true):[];
        $reports=array_slice(array_merge([$report],is_array($old)?$old:[]),0,20);
        set_setting('media_centre.reports.'.$slug,json_encode($reports,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true);
    }finally{$q=$pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
}
function media_centre_api_report(string $slug,array $connection,?callable $transport=null): array {
    if(!isset(media_centre_platforms()[$slug])||($connection['status']??'')!=='connected'||empty($connection['selected_resource']))throw new InvalidArgumentException('Connect and confirm this account before requesting its report.');
    $expected=media_centre_platforms()[$slug]['service'];
    if(($connection['service']??'')!==$expected && !($slug==='youtube'&&($connection['service']??'')==='google_youtube_upload'))throw new InvalidArgumentException('The reporting connection does not match this platform.');
    $metrics=[];$period='Current cumulative account totals';$basis='Provider API';$id=(string)$connection['selected_resource'];
    $add=static function(string $label,mixed $value)use(&$metrics):void{$count=media_centre_count($value);if($count!==null)$metrics[$label]=$count;};
    if($slug==='tiktok'){$r=publishing_tiktok_report($connection);foreach(['follower_count'=>'Followers','following_count'=>'Following','likes_count'=>'Profile likes','video_count'=>'Public videos']as$key=>$label)$add($label,$r['summary'][$key]??null);$basis=$r['note'];}
    elseif($slug==='youtube'){$r=publishing_google_report($connection);$channel=$r['items'][0]??[];if(($channel['id']??'')!==$id)throw new InvalidArgumentException('The returned channel does not match the confirmed account.');foreach(['viewCount'=>'Channel views','videoCount'=>'Videos']as$key=>$label)$add($label,$channel['statistics'][$key]??null);if(empty($channel['statistics']['hiddenSubscriberCount']))$add('Subscribers',$channel['statistics']['subscriberCount']??null);}
    else{
        $headers=publishing_bearer(publishing_access_token($connection));$get=$transport??static fn(string $url,array $h):array=>publishing_http('GET',$url,$h)['data'];
        if($slug==='x'){$r=$get('https://api.x.com/2/users/me?user.fields=public_metrics',$headers)['data']??[];if(($r['id']??'')!==$id)throw new InvalidArgumentException('X returned a different account.');foreach(['followers_count'=>'Followers','following_count'=>'Following','tweet_count'=>'Posts']as$key=>$label)$add($label,$r['public_metrics'][$key]??null);}
        elseif($slug==='instagram'){$r=$get('https://graph.instagram.com/'.publishing_meta_version().'/'.rawurlencode($id).'?fields=id,user_id,followers_count,media_count',$headers);if((string)($r['user_id']??$r['id']??'')!==$id)throw new InvalidArgumentException('Instagram returned a different account.');$add('Followers',$r['followers_count']??null);$add('Published media',$r['media_count']??null);$basis='Professional-account profile totals; these are not reach, impressions or period-specific Insights.';}
        elseif($slug==='facebook'){$r=$get('https://graph.facebook.com/'.publishing_meta_version().'/'.rawurlencode($id).'?fields=id,followers_count',$headers);if((string)($r['id']??'')!==$id)throw new InvalidArgumentException('Facebook returned a different Page.');$add('Page followers',$r['followers_count']??null);$basis='Confirmed Page follower snapshot; not professional-profile Insights or period-specific reach.';}
        elseif($slug==='linkedin'){
            if(!in_array('r_member_postAnalytics',publishing_decode($connection['scopes_json']??null),true))throw new InvalidArgumentException('LinkedIn analytics permission has not been granted.');
            $end=new DateTimeImmutable('today',new DateTimeZone('UTC'));$start=$end->modify('-28 days');$range='(start:(day:'.$start->format('j').',month:'.$start->format('n').',year:'.$start->format('Y').'),end:(day:'.$end->format('j').',month:'.$end->format('n').',year:'.$end->format('Y').'))';
            $headers[]='X-Restli-Protocol-Version: 2.0.0';$headers[]='Linkedin-Version: 202610';
            foreach(['IMPRESSION'=>'Post impressions','MEMBERS_REACHED'=>'Members reached']as$type=>$label){$r=$get('https://api.linkedin.com/rest/memberCreatorPostAnalytics?q=me&queryType='.$type.'&aggregation=TOTAL&dateRange='.$range,$headers);if(count($r['elements']??[])===1)$add($label,$r['elements'][0]['count']??null);}
            $period=$start->format('Y-m-d').' to '.$end->modify('-1 day')->format('Y-m-d').' (UTC)';$basis='LinkedIn member aggregate analytics, TOTAL aggregation, exclusive API end date; best-effort provider data.';
        }
    }
    if(!$metrics)throw new InvalidArgumentException('The provider returned no supported metrics. Missing data is unavailable, not zero.');
    return ['platform'=>$slug,'account'=>(string)$connection['display_name'].' · '.$id,'source'=>'Connected provider API','basis'=>$basis,'period'=>$period,'observed_at'=>gmdate('c'),'metrics'=>$metrics];
}
function media_centre_dispatch(string $path): bool {
    if(!in_array($path,['/write/media-centre','/write/media-centre/report'],true))return false;
    require_auth();private_response_headers();journeys_method($path==='/write/media-centre/report'?['POST']:['GET','HEAD']);
    if(is_post()){
        verify_csrf();
        try{
            if(!journeys_rate_limit('media-report',(string)current_user()['id'],12,3600))throw new InvalidArgumentException('Please wait before requesting or recording another report.');
            if(($_POST['action']??'')==='record')$report=media_centre_manual($_POST);
            elseif(($_POST['action']??'')==='refresh'){
                $slug=$_POST['platform']??'';if(!is_string($slug)||!isset(media_centre_platforms()[$slug]))throw new InvalidArgumentException('Choose a social account.');
                $service=media_centre_platforms()[$slug]['service'];$connection=publishing_connection($service);
                if($slug==='youtube'&&(!$connection||$connection['status']!=='connected'))$connection=publishing_connection('google_youtube_upload');
                $report=media_centre_api_report($slug,$connection??[]);
            }else throw new InvalidArgumentException('Choose a reporting action.');
            $report['recorded_by']=(int)current_user()['id'];media_centre_save_report($report);flash('success','Report saved privately with its account, period and source. No social post was sent.');
        }catch(InvalidArgumentException $e){flash('error',$e->getMessage());}
        catch(PublishingRemoteException $e){flash('error','Report unavailable. Check account permissions, approval, quota or reconnection in Connections. Your last saved report is retained.');}
        catch(Throwable $e){flash('error','The report could not be saved. Your last saved report is retained.');}
        redirect(url('/write/media-centre#analytics'));
    }
    $connections=[];foreach(db()->query('SELECT * FROM publishing_connections')->fetchAll()as$c)$connections[$c['service']]=$c;
    $reports=[];foreach(array_keys(media_centre_platforms())as$slug)$reports[$slug]=media_centre_history($slug);
    $jobs=db()->query('SELECT j.status,j.scheduled_at,j.external_url,r.package_id,p.title,c.name channel_name FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id JOIN publishing_channels c ON c.id=j.channel_id ORDER BY j.id DESC LIMIT 12')->fetchAll();
    render_write('media_centre',['writeTitle'=>'Media centre','connections'=>$connections,'reports'=>$reports,'jobs'=>$jobs,'channels'=>publishing_channels()]);return true;
}
