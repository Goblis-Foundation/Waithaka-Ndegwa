<?php
declare(strict_types=1);

/** Opt-in, selected-calendar cache. Never supplies public booking availability. */
function gcs_key(int $account,string $calendar): string {return 'google.calendar.cache.'.hash('sha256',$account.'|'.$calendar);}
function gcs_revision(array $a): string {return hash('sha256',publishing_json([$a['id'],$a['user_id'],$a['google_sub'],$a['verified_at'],$a['selected_json']]));}
function gcs_config(int $uid): array {return comm_get('google.calendar.sync.'.$uid,['enabled'=>false,'push'=>false]);}
function gcs_event(array $event): ?array {
    if(!is_string($event['id']??null)||$event['id']===''||strlen($event['id'])>1024)throw new RuntimeException('Calendar event identity is missing.');
    if(($event['status']??'')==='cancelled')return null;
    if(!is_array($event['start']??null)||!is_array($event['end']??null))throw new RuntimeException('Calendar event time is missing.');
    foreach(['start','end']as$key){
        $time=$event[$key];$value=$time['dateTime']??$time['date']??null;
        $pattern=isset($time['dateTime'])?'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?$/D':'/^\d{4}-\d{2}-\d{2}$/D';
        if(!is_string($value)||!preg_match($pattern,$value)||isset($time['dateTime'])&&!preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D',$value)&&empty($time['timeZone']))throw new RuntimeException('Calendar event time is invalid.');
        try{new DateTimeImmutable($value,new DateTimeZone($time['timeZone']??'UTC'));}catch(Throwable){throw new RuntimeException('Calendar event time is invalid.');}$errors=DateTimeImmutable::getLastErrors();if($errors&&($errors['warning_count']||$errors['error_count']))throw new RuntimeException('Calendar event date is invalid.');
        $event[$key]=array_intersect_key($time,array_flip(['dateTime','date','timeZone']));
    }
    $out=array_intersect_key($event,array_flip(['id','etag','status','summary','start','end','location','htmlLink']));
    foreach(['summary'=>500,'location'=>1000,'htmlLink'=>2000,'etag'=>1000]as$key=>$limit)if(isset($out[$key])){if(!is_string($out[$key])||strlen($out[$key])>$limit)throw new RuntimeException('Calendar event exceeded the private cache limits.');}
    $out['organizer']=['self'=>!empty($event['organizer']['self'])];
    // Keep join links only; never cache attendees, descriptions, attachments or transcripts.
    if(is_string($event['hangoutLink']??null)&&strlen($event['hangoutLink'])<=2000)$out['hangoutLink']=$event['hangoutLink'];
    $out['conferenceData']=['entryPoints'=>[]];foreach($event['conferenceData']['entryPoints']??[]as$p)if(($p['entryPointType']??'')==='video'&&is_string($p['uri']??null)&&strlen($p['uri'])<=2000)$out['conferenceData']['entryPoints'][]=array_intersect_key($p,array_flip(['entryPointType','uri']));
    return $out;
}
/** Atomically replace only after all pages and nextSyncToken are present. */
function gcs_fetch(string $calendar,array $old,int $now,array $headers,callable $http): array {
    $from=gmdate('Y-m-d',$now-86400);$until=gmdate('Y-m-d',$now+30*86400);
    $incremental=!empty($old['sync_token'])&&($old['from']??'')===$from&&($old['until']??'')===$until;
    for($attempt=0;$attempt<2;$attempt++){
        $events=$incremental?($old['events']??[]):[];$params=['singleEvents'=>'true','showDeleted'=>'true','maxResults'=>250];
        if($incremental)$params['syncToken']=$old['sync_token'];else{$params['timeMin']=$from.'T00:00:00Z';$params['timeMax']=$until.'T00:00:00Z';}
        $seen=[];$page='';$token='';
        try{
            for($n=0;$n<8;$n++){
                $query=$params;if($page!=='')$query['pageToken']=$page;
                $data=$http('GET','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendar).'/events?'.http_build_query($query,'','&',PHP_QUERY_RFC3986),$headers)['data'];
                if(!is_array($data['items']??null))throw new RuntimeException('Google returned an incomplete calendar page.');
                foreach($data['items']as$event){if(!is_array($event))throw new RuntimeException('Invalid calendar event.');$item=gcs_event($event);$id=$event['id'];
                    if($item===null){unset($events[$id]);continue;}
                    $start=new DateTimeImmutable($item['start']['dateTime']??$item['start']['date'],new DateTimeZone('UTC'));$end=new DateTimeImmutable($item['end']['dateTime']??$item['end']['date'],new DateTimeZone('UTC'));
                    if($end<=$start)throw new RuntimeException('Calendar event duration is invalid.');
                    if($end->getTimestamp()<=strtotime($from.'T00:00:00Z')||$start->getTimestamp()>=strtotime($until.'T00:00:00Z'))unset($events[$id]);else $events[$id]=$item;
                    if(count($events)>2000)throw new RuntimeException('This calendar exceeds the bounded private cache. Use live agenda reads.');
                }
                $page=$data['nextPageToken']??'';
                if(!is_string($page)||strlen($page)>4096)throw new RuntimeException('Invalid calendar page token.');
                if($page===''){$token=$data['nextSyncToken']??'';break;}
                if(isset($seen[$page]))throw new RuntimeException('Google repeated a calendar page.');$seen[$page]=true;
            }
            if($page!==''||!is_string($token)||$token===''||strlen($token)>4096)throw new RuntimeException('Calendar sync was incomplete. The previous snapshot is retained.');
            return ['events'=>$events,'sync_token'=>$token,'from'=>$from,'until'=>$until,'observed'=>$now,'attempted'=>$now,'mode'=>$incremental?'incremental':'full','dirty'=>false,'error'=>''];
        }catch(PublishingRemoteException $e){if($e->httpStatus===410&&$incremental){$incremental=false;continue;}throw $e;}
    }
    throw new RuntimeException('Calendar resynchronization did not complete.');
}
function gcs_notice_valid(array $channel,array $headers,int $now): bool {
    foreach(['id','token','resource','number','state']as$key)if(!is_string($headers[$key]??null))return false;
    if(($channel['status']??'')!=='active'||($channel['expires']??0)<=$now||!hash_equals((string)($channel['id']??''),$headers['id'])||!hash_equals((string)($channel['token_hash']??''),hash('sha256',$headers['token']))||!hash_equals((string)($channel['resource']??''),$headers['resource']))return false;
    $number=$headers['number'];if(!preg_match('/^[1-9][0-9]{0,19}$/D',$number)||!in_array($headers['state'],['exists','not_exists','sync'],true))return false;
    $last=(string)($channel['number']??'0');return strlen($number)>strlen($last)||(strlen($number)===strlen($last)&&strcmp($number,$last)>0);
}
function gcs_webhook(string $path): bool {
    if($path!=='/hooks/google-calendar')return false;
    private_response_headers();if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);header('Allow: POST');return true;}
    $id=$_SERVER['HTTP_X_GOOG_CHANNEL_ID']??'';
    if(is_string($id)&&preg_match('/^[a-f0-9]{32}$/D',$id))try{
        comm_lock('gcs-channel-'.$id,static function()use($id){$channel=comm_get('google.calendar.channel.'.$id);$headers=['id'=>$id,'token'=>$_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN']??'','resource'=>$_SERVER['HTTP_X_GOOG_RESOURCE_ID']??'','number'=>$_SERVER['HTTP_X_GOOG_MESSAGE_NUMBER']??'','state'=>$_SERVER['HTTP_X_GOOG_RESOURCE_STATE']??''];
            if(!gcs_notice_valid($channel,$headers,time()))return;
            $a=google_calendar_owned((int)$channel['account'],(int)$channel['owner']);$cfg=gcs_config((int)$channel['owner']);
            if(empty($cfg['enabled'])||empty($cfg['push'])||$a['status']!=='connected'||!in_array($channel['calendar'],publishing_decode($a['selected_json']),true))return;
            $channel['number']=$headers['number'];$channel['notified_at']=time();comm_put('google.calendar.channel.'.$id,$channel);
            // Separate marker avoids overwriting a cache updated by a concurrent sync.
            comm_put($channel['key'].'.notice',['at'=>time(),'channel'=>$id,'number'=>$headers['number']]);
        });
    }catch(Throwable){/* No event data or credentials are accepted from a notification. Polling recovers. */}
    http_response_code(204);return true;
}
function gcs_watch(array $a,string $calendar,string $key,array $old,array $headers,callable $http,int $now): array {
    $current=$old['channel']??[];
    if(($current['expires']??0)>$now+3600)return $current;
    $id=bin2hex(random_bytes(16));$secret=bin2hex(random_bytes(32));$expires=$now+7*86400;
    $channel=['id'=>$id,'token_hash'=>hash('sha256',$secret),'status'=>'pending','expires'=>$expires,'number'=>'0','owner'=>(int)$a['user_id'],'account'=>(int)$a['id'],'calendar'=>$calendar,'key'=>$key];
    comm_put('google.calendar.channel.'.$id,$channel);
    $data=$http('POST','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($calendar).'/events/watch',$headers,['id'=>$id,'type'=>'web_hook','address'=>url('/hooks/google-calendar'),'token'=>$secret,'expiration'=>(string)($expires*1000)],false,true)['data'];
    if(($data['id']??'')!==$id||!is_string($data['resourceId']??null)||$data['resourceId']===''||strlen($data['resourceId'])>2000||!is_string($data['expiration']??null)&&!is_int($data['expiration']??null)||!ctype_digit((string)$data['expiration']))throw new RuntimeException('Google did not verify the notification channel. Polling remains available.');
    $until=(int)floor((float)$data['expiration']/1000);if($until<=$now+60||$until>$expires+60)throw new RuntimeException('Unexpected notification expiry.');
    $channel['status']='active';$channel['resource']=$data['resourceId'];$channel['expires']=$until;comm_put('google.calendar.channel.'.$id,$channel);
    if(!empty($current['id'])&&!empty($current['resource']))try{$http('POST','https://www.googleapis.com/calendar/v3/channels/stop',$headers,['id'=>$current['id'],'resourceId'=>$current['resource']],false,true);comm_put('google.calendar.channel.'.$current['id'],['id'=>$current['id'],'status'=>'stopped','expires'=>$now]);}catch(Throwable){/* Overlap is permitted; old notifications expire independently. */}
    return $channel;
}
function gcs_purge(int $uid,array $active,int $now): void {
    foreach(comm_records('google.calendar.cache',1000)as$r)if((int)($r['owner']??0)===$uid&&is_string($r['key']??null)&&preg_match('/^google\.calendar\.cache\.[a-f0-9]{64}$/D',$r['key'])&&!isset($active[$r['key']])){
        foreach([$r['key'],$r['key'].'.notice']as$key)db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['communication.'.$key]);
        $id=$r['channel']['id']??'';if(preg_match('/^[a-f0-9]{32}$/D',$id))comm_put('google.calendar.channel.'.$id,['id'=>$id,'status'=>'stopped','expires'=>$now]);
    }
    foreach(comm_records('google.calendar.channel',2000)as$r)if(($r['expires']??PHP_INT_MAX)<$now-86400&&is_string($r['id']??null)&&preg_match('/^[a-f0-9]{32}$/D',$r['id']))db()->prepare('DELETE FROM settings WHERE setting_key=?')->execute(['communication.google.calendar.channel.'.$r['id']]);
}
/** Settings changes and snapshot commits share a lock; an in-flight response cannot restore disabled data. */
function gcs_context_current(array $a,string $calendar,array $cfg): bool {
    $current=gcs_config((int)$a['user_id']);if(empty($current['enabled'])||($current['generation']??'')!==($cfg['generation']??''))return false;
    try{$fresh=google_calendar_owned((int)$a['id'],(int)$a['user_id']);return $fresh['status']==='connected'&&gcs_revision($fresh)===gcs_revision($a)&&in_array($calendar,publishing_decode($fresh['selected_json']),true);}catch(Throwable){return false;}
}
function gcs_commit(array $a,string $calendar,array $cfg,string $key,array $snapshot): bool {
    return comm_lock('gcs-owner-'.$a['user_id'],static function()use($a,$calendar,$cfg,$key,$snapshot){if(!gcs_context_current($a,$calendar,$cfg))return false;comm_put($key,$snapshot);return true;});
}
function gcs_preferences(int $uid,bool $enabled,bool $push): void {
    comm_lock('gcs-owner-'.$uid,static function()use($uid,$enabled,$push){comm_put('google.calendar.sync.'.$uid,['enabled'=>$enabled,'push'=>$enabled&&$push,'generation'=>bin2hex(random_bytes(16))]);if(!$enabled)gcs_purge($uid,[],time());});
}
function gcs_work(?int $now=null,?callable $http=null): array {
    $now??=time();$http??='publishing_http';$uid=(int)(comm_config()['owner']??0);$cfg=gcs_config($uid);
    if(!$uid||empty($cfg['enabled']))return ['enabled'=>false,'checked'=>0];
    $queue=[];$active=[];foreach(google_calendar_accounts($uid)as$a)if($a['status']==='connected')foreach(publishing_decode($a['selected_json'])as$id){$key=gcs_key((int)$a['id'],$id);$active[$key]=true;$old=comm_get($key);$notice=comm_get($key.'.notice');$due=($old['observed']??0)<=$now-900||($notice&&hash('sha256',publishing_json($notice))!==($old['notice_seen']??''))||($old['revision']??'')!==gcs_revision($a)||(!empty($cfg['push'])&&($old['channel']['expires']??0)<=$now+3600);if($due&&($old['attempted']??0)<=$now-60)$queue[]=['a'=>$a,'id'=>$id,'key'=>$key,'attempted'=>$old['attempted']??0];}
    comm_lock('gcs-owner-'.$uid,static fn()=>gcs_purge($uid,$active,$now));if(count($active)>30)throw new RuntimeException('Select at most 30 calendars for background refresh.');
    usort($queue,static fn($a,$b)=>$a['attempted']<=>$b['attempted']);$result=['enabled'=>true,'checked'=>0,'failed'=>0];
    foreach(array_slice($queue,0,4)as$item)try{comm_lock($item['key'],static function()use($item,$cfg,$now,$http,&$result){
        $a=google_calendar_owned((int)$item['a']['id'],(int)$item['a']['user_id']);if(!gcs_context_current($a,$item['id'],$cfg))return;
        $old=comm_get($item['key']);$revision=gcs_revision($a);if(($old['revision']??'')!==$revision)$old=[];
        $old['attempted']=$now;$old['owner']=(int)$a['user_id'];$old['key']=$item['key'];if(!gcs_commit($a,$item['id'],$cfg,$item['key'],$old))return;$noticeBefore=comm_get($item['key'].'.notice');
        try{
            $headers=publishing_bearer(google_calendar_token($a));$new=gcs_fetch($item['id'],$old,$now,$headers,$http);
            if(!gcs_context_current($a,$item['id'],$cfg))return;
            $new['revision']=$revision;$new['owner']=(int)$a['user_id'];$new['key']=$item['key'];$new['calendar']=$item['id'];$new['channel']=$old['channel']??[];$new['notice_seen']=hash('sha256',publishing_json($noticeBefore));
            if(!empty($cfg['push']))try{$new['channel']=gcs_watch($a,$item['id'],$item['key'],$old,$headers,$http,$now);$new['push_error']='';}catch(Throwable){$new['push_error']='Channel registration needs review; polling continues.';}
            if(gcs_commit($a,$item['id'],$cfg,$item['key'],$new))$result['checked']++;
        }catch(Throwable){$old['error']='Refresh incomplete. The previous snapshot is retained as stale.';$old['attempted']=$now;if(gcs_commit($a,$item['id'],$cfg,$item['key'],$old))$result['failed']++;}
    });}catch(Throwable){$result['failed']++;}
    comm_put('google.calendar.sync.status.'.$uid,$result+['at'=>$now]);return $result;
}
function gcs_cached(array $a,string $calendar,string $start,string $end,?int $now=null): ?array {
    $now??=time();if($a['status']!=='connected'||!in_array($calendar,publishing_decode($a['selected_json']),true)||empty(gcs_config((int)$a['user_id'])['enabled']))return null;$r=comm_get(gcs_key((int)$a['id'],$calendar));
    if(!empty($r['error'])||($r['observed']??0)<$now-900||($r['revision']??'')!==gcs_revision($a)||$start<($r['from']??'9999')||google_workspace_shift_date($end,1)>($r['until']??''))return null;
    $min=strtotime($start.'T00:00:00Z');$max=strtotime(google_workspace_shift_date($end,1).'T00:00:00Z');$items=[];
    foreach($r['events']??[]as$e){$s=strtotime($e['start']['dateTime']??$e['start']['date'].'T00:00:00Z');$t=strtotime($e['end']['dateTime']??$e['end']['date'].'T00:00:00Z');if($s<$max&&$t>$min)$items[]=$e;}
    return ['items'=>$items];
}
function gcs_dispatch(string $path): bool {
    if($path!=='/write/google/sync')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];
    if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}
    $error='';try{if(is_post()){verify_csrf();if(!journeys_rate_limit('calendar-sync',(string)$uid,10,300))throw new RuntimeException('Please wait before another change.');$action=$_POST['action']??'';
        if($action==='settings'){$enabled=($_POST['enabled']??'')==='1';$push=($_POST['push']??'')==='1';$count=0;foreach(google_calendar_accounts($uid)as$a)if($a['status']==='connected')$count+=count(publishing_decode($a['selected_json']));if($enabled&&($count===0||$count>30))throw new RuntimeException('Select between 1 and 30 calendars across your confirmed accounts.');gcs_preferences($uid,$enabled,$push);flash('success','Private refresh preferences saved. Public booking checks still query live availability.');}
        elseif($action==='refresh'){gcs_work();flash('success','Up to four due calendars checked. Review the status below.');}else throw new RuntimeException('Choose a supported action.');redirect(url('/write/google/sync'));}
    }catch(Throwable $e){$error=$e instanceof PDOException?'The preference could not be saved.':$e->getMessage();}
    $rows=[];foreach(google_calendar_accounts($uid)as$a)if($a['status']==='connected'){$names=array_column(publishing_decode($a['calendars_json']),'name','id');foreach(publishing_decode($a['selected_json'])as$id){$cache=comm_get(gcs_key((int)$a['id'],$id));$rows[]=['account'=>$a['display_name'],'calendar'=>$names[$id]??'Selected calendar','observed'=>$cache['observed']??0,'mode'=>$cache['mode']??'Not checked','count'=>count($cache['events']??[]),'error'=>$cache['error']??'','push_error'=>$cache['push_error']??'','expires'=>$cache['channel']['expires']??0];}}
    render_write('google_calendar_sync',['writeTitle'=>'Private calendar refresh','config'=>gcs_config($uid),'rows'=>$rows,'error'=>$error]);return true;
}
