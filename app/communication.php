<?php
declare(strict_types=1);

/** Single-host communication records use the existing encrypted settings store.
 * Each record has its own key. Advisory locks serialize reservation and provider mutations.
 * No private record is cached by the PWA or sent to AI automatically. */
function comm_get(string $key,array $default=[]): array {
    $q=db()->prepare('SELECT setting_value,is_secret FROM settings WHERE setting_key=?');$q->execute(['communication.'.$key]);$r=$q->fetch();
    if(!$r)return $default;$s=(string)$r['setting_value'];if((int)$r['is_secret'])$s=decrypt_secret($s);
    return json_decode($s,true,32,JSON_THROW_ON_ERROR);
}
function comm_put(string $key,array $value): void {set_setting('communication.'.$key,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),true);}
function comm_records(string $prefix,int $limit=2000): array {
    $q=db()->prepare('SELECT setting_key,setting_value FROM settings WHERE setting_key LIKE ? ORDER BY setting_key DESC LIMIT '.($limit+1));$q->execute(['communication.'.$prefix.'.%']);$rows=$q->fetchAll();
    if(count($rows)>$limit)throw new RuntimeException('This workspace needs archival before accepting more records.');
    return array_map(static fn($r)=>json_decode(decrypt_secret($r['setting_value']),true,32,JSON_THROW_ON_ERROR),$rows);
}
function comm_lock(string $key,callable $work): mixed {
    $name='comm-'.substr(hash('sha256',$key),0,50);$q=db()->prepare('SELECT GET_LOCK(?,3)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Another update is in progress. Please try shortly.');
    try{return $work();}finally{$q=db()->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}
}
function comm_zone(mixed $zone): DateTimeZone {if(!is_string($zone)||!in_array($zone,DateTimeZone::listIdentifiers(),true))throw new InvalidArgumentException('Choose a valid timezone.');return new DateTimeZone($zone);}
function comm_types(): array {return ['intro'=>['name'=>'Introductory conversation','minutes'=>15,'approval'=>false],'innovation'=>['name'=>'Entrepreneurship & innovation','minutes'=>30,'approval'=>false],'partnership'=>['name'=>'Partnerships & investment','minutes'=>45,'approval'=>true],'media'=>['name'=>'Media & speaking','minutes'=>30,'approval'=>true],'collaboration'=>['name'=>'Existing collaboration','minutes'=>30,'approval'=>true]];}
function comm_config(): array {return comm_get('config',['enabled'=>false,'owner'=>0,'timezone'=>'Africa/Nairobi','days'=>[1,2,3,4,5],'start'=>'09:00','end'=>'17:00','notice'=>24,'horizon'=>30,'buffer'=>15,'daily_limit'=>4,'excluded_dates'=>[],'required_accounts'=>[],'microsoft_required'=>false,'worker'=>false,'types'=>comm_types()]);}
function comm_date(mixed $date): string {if(!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)||!($parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date))||$parsed->format('Y-m-d')!==$date)throw new InvalidArgumentException('Choose a valid date.');return $date;}
function comm_overlap(int $a,int $b,int $c,int $d): bool {return $a<$d&&$c<$b;}
function comm_slot_candidates(array $cfg,array $type,string $date,array $busy,array $bookings,int $now): array {
    comm_date($date);$zone=comm_zone($cfg['timezone']);$day=new DateTimeImmutable($date.' 00:00:00',$zone);$today=(new DateTimeImmutable('@'.$now))->setTimezone($zone)->setTime(0,0);
    if($day<$today||$day>$today->modify('+'.(int)$cfg['horizon'].' days')||!in_array((int)$day->format('N'),$cfg['days'],true)||in_array($date,$cfg['excluded_dates'],true))return [];
    $count=0;$buffer=(int)$cfg['buffer']*60;
    foreach($bookings as$b)if(in_array($b['status'],['requested','creating','confirmed','rescheduling','cancelling','review'],true)){
        $s=(int)$b['start'];$e=(int)$b['end'];$busy[]=[$s-$buffer,$e+$buffer];if((new DateTimeImmutable('@'.$s))->setTimezone($zone)->format('Y-m-d')===$date)$count++;
    }
    if($count>=(int)$cfg['daily_limit'])return [];
    $start=new DateTimeImmutable($date.' '.$cfg['start'],$zone);$end=new DateTimeImmutable($date.' '.$cfg['end'],$zone);$duration=(int)$type['minutes']*60;$slots=[];
    for($s=$start->getTimestamp();$s+$duration<=$end->getTimestamp();$s+=900){if($s<$now+(int)$cfg['notice']*3600)continue;$ok=true;foreach($busy as[$a,$b])if(comm_overlap($s-$buffer,$s+$duration+$buffer,$a,$b)){$ok=false;break;}if($ok)$slots[]=$s;}
    return $slots;
}
function comm_writer_connection(array $cfg): array {
    $c=publishing_connection('google_booking');if(!$c||$c['status']!=='connected'||(int)$c['connected_by']!==(int)$cfg['owner']||empty($c['selected_resource']))throw new RuntimeException('Booking calendar authorization is required.');return $c;
}
function comm_busy(array $cfg,string $date): array {
    $z=comm_zone($cfg['timezone']);$day=new DateTimeImmutable(comm_date($date).' 00:00:00',$z);$min=$day->modify('-1 day')->format(DATE_ATOM);$max=$day->modify('+2 days')->format(DATE_ATOM);$busy=[];
    $required=$cfg['required_accounts'];if(!$required)throw new RuntimeException('Select calendars to check before enabling bookings.');
    foreach($required as$id){$a=google_calendar_owned((int)$id,(int)$cfg['owner']);$selected=publishing_decode($a['selected_json']);if($a['status']!=='connected'||!$selected)throw new RuntimeException('A required calendar needs reconnection.');$token=google_calendar_token($a);
        foreach($selected as$cal){$page='';for($n=0;$n<10;$n++){$r=publishing_http('GET','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($cal).'/events?'.http_build_query(['timeMin'=>$min,'timeMax'=>$max,'singleEvents'=>'true','maxResults'=>2500,'timeZone'=>'UTC','pageToken'=>$page]),['Authorization: Bearer '.$token])['data'];
            foreach($r['items']??[]as$e){if(($e['status']??'')==='cancelled'||($e['transparency']??'')==='transparent')continue;$selfDeclined=false;foreach($e['attendees']??[]as$at)if(!empty($at['self'])&&($at['responseStatus']??'')==='declined')$selfDeclined=true;if($selfDeclined)continue;
                $s=$e['start']['dateTime']??null;$t=$e['end']['dateTime']??null;$ez=new DateTimeZone($e['start']['timeZone']??'UTC');if(!$s){$s=($e['start']['date']??'').'T00:00:00';$t=($e['end']['date']??'').'T00:00:00';foreach(publishing_decode($a['calendars_json'])as$meta)if($meta['id']===$cal)$ez=new DateTimeZone($meta['timezone']??'UTC');}
                if(!$s||!$t)throw new RuntimeException('A calendar interval could not be checked.');$busy[]=[(new DateTimeImmutable($s,$ez))->getTimestamp(),(new DateTimeImmutable($t,$ez))->getTimestamp()];}
            $page=(string)($r['nextPageToken']??'');if($page==='')break;if($n===9)throw new RuntimeException('Calendar is too busy to verify safely.');}}
    }
    // Include destination calendar, even when the owner selected different conflict accounts.
    $c=comm_writer_connection($cfg);$r=publishing_http('POST','https://www.googleapis.com/calendar/v3/freeBusy',['Authorization: Bearer '.publishing_access_token($c)],['timeMin'=>$min,'timeMax'=>$max,'items'=>[['id'=>$c['selected_resource']]]])['data'];
    $result=$r['calendars'][$c['selected_resource']]??null;if(!$result||!empty($result['errors']))throw new RuntimeException('Destination calendar is unavailable.');foreach($result['busy']??[]as$b)$busy[]=[strtotime($b['start']),strtotime($b['end'])];
    if(!empty($cfg['microsoft_required'])){$r=publishing_http('GET','https://graph.microsoft.com/v1.0/me/calendarView?'.http_build_query(['startDateTime'=>$min,'endDateTime'=>$max,'$top'=>1000,'$select'=>'start,end,isCancelled,showAs']),['Authorization: Bearer '.meetings_access_token('microsoft',(int)$cfg['owner']),'Prefer: outlook.timezone="UTC"'])['data'];if(!empty($r['@odata.nextLink']))throw new RuntimeException('Outlook availability is incomplete.');foreach($r['value']??[]as$e)if(empty($e['isCancelled'])&&($e['showAs']??'busy')!=='free')$busy[]=[(new DateTimeImmutable($e['start']['dateTime'],new DateTimeZone('UTC')))->getTimestamp(),(new DateTimeImmutable($e['end']['dateTime'],new DateTimeZone('UTC')))->getTimestamp()];}
    return $busy;
}
function comm_slots(array $cfg,string $type,string $date,?string $exclude=null): array {
    if(empty($cfg['enabled'])||empty($cfg['types'][$type]['enabled']))throw new RuntimeException('This meeting is not accepting bookings.');
    $bookings=array_filter(comm_records('booking'),static fn($b)=>$b['id']!==$exclude);return comm_slot_candidates($cfg,$cfg['types'][$type],$date,comm_busy($cfg,$date),$bookings,time());
}
function comm_capability(array $b): string {return hash_hmac('sha256','booking|'.$b['id'].'|'.$b['nonce'],(string)app_config('app_key'));}
function comm_manage_url(array $b): string {return url('/book/manage/'.$b['id'].'/'.comm_capability($b));}
function comm_booking_by_cap(string $id,string $cap): array {if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!preg_match('/^[a-f0-9]{64}$/D',$cap))throw new InvalidArgumentException('This booking link is invalid.');$b=comm_get('booking.'.$id);if(!$b||!hash_equals(comm_capability($b),$cap))throw new InvalidArgumentException('This booking link is invalid.');if((int)$b['end']<time()-30*86400)throw new InvalidArgumentException('This management link has expired.');return $b;}
function comm_queue_mail(array $b,string $event): void {
    $key=$b['id'].'.'.$b['version'].'.'.$event;comm_put('mail.'.$key,['id'=>$key,'booking'=>$b['id'],'version'=>$b['version'],'event'=>$event,'status'=>'pending','created'=>time()]);
}
function comm_event(string $category,string $record): void {comm_put('signal',['category'=>$category,'record'=>$record,'at'=>time(),'id'=>bin2hex(random_bytes(8))]);}
function comm_provider_event(array $b,array $cfg): array {
    $c=comm_writer_connection($cfg);$token=publishing_access_token($c);$eventId='wn'.$b['id'];$base='https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($c['selected_resource']).'/events';
    $body=['id'=>$eventId,'summary'=>$cfg['types'][$b['type']]['name'].' · Waithaka Ndegwa','description'=>'Booked through the website. Agenda: '.$b['agenda'],'start'=>['dateTime'=>gmdate(DATE_ATOM,$b['start'])],'end'=>['dateTime'=>gmdate(DATE_ATOM,$b['end'])],'attendees'=>[['email'=>$b['email'],'displayName'=>$b['first'].' '.$b['last']]],'extendedProperties'=>['private'=>['websiteBooking'=>$b['id']]],'conferenceData'=>['createRequest'=>['requestId'=>$b['id'],'conferenceSolutionKey'=>['type'=>'hangoutsMeet']]]];
    try{return publishing_http('POST',$base.'?conferenceDataVersion=1&sendUpdates=all',['Authorization: Bearer '.$token],$body,false,true)['data'];}
    catch(PublishingRemoteException $e){if($e->httpStatus===409){$r=publishing_http('GET',$base.'/'.$eventId,['Authorization: Bearer '.$token])['data'];if(($r['extendedProperties']['private']['websiteBooking']??'')===$b['id'])return $r;}throw $e;}
}
function comm_confirm(array $b,array $cfg): array {
    $b['conference_status']='pending';$b['conference_attempts']=0;$b['conference_checked']=0;$b['status']='creating';comm_put('booking.'.$b['id'],$b);
    try{$r=comm_provider_event($b,$cfg);$b['event_id']=$r['id'];$b['calendar']=comm_writer_connection($cfg)['selected_resource'];$b['join']=meetings_event_join($r);$b['conference_status']=$b['join']!==''?'ready':'pending';$b['status']='confirmed';$b['version']++;comm_put('booking.'.$b['id'],$b);comm_queue_mail($b,'confirmed');comm_event('bookings',$b['id']);return $b;}
    catch(Throwable $e){$b['status']='review';comm_put('booking.'.$b['id'],$b);comm_event('connections',$b['id']);throw new RuntimeException('Your request was saved, but calendar confirmation needs review. Please do not submit it again.');}
}
function comm_conference_result(array $b,array $event): array {
    if(($event['extendedProperties']['private']['websiteBooking']??'')!==$b['id'])throw new RuntimeException('The meeting identity could not be verified.');
    $b['join']=meetings_event_join($event);$state=$event['conferenceData']['createRequest']['status']['statusCode']??'pending';
    $b['conference_status']=$b['join']!==''?'ready':($state==='failure'?'unavailable':'pending');return $b;
}
function comm_refresh_conference(array $b,array $cfg): void {
    if($b['status']!=='confirmed'||$b['join']!==''||$b['end']<time()||($b['conference_attempts']??0)>=6||($b['conference_checked']??0)>time()-60)return;
    comm_lock('reservations',function()use($b,$cfg){$b=comm_get('booking.'.$b['id']);if($b['status']!=='confirmed'||$b['join']!=='')return;$b['conference_attempts']=($b['conference_attempts']??0)+1;$b['conference_checked']=time();
        try{$c=comm_writer_connection($cfg);$r=publishing_http('GET','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($b['calendar']).'/events/'.rawurlencode($b['event_id']),['Authorization: Bearer '.publishing_access_token($c)])['data'];$b=comm_conference_result($b,$r);}catch(Throwable){$b['conference_status']='pending';}
        if($b['conference_attempts']>=6&&$b['join']===''){$b['conference_status']='unavailable';comm_event('connections',$b['id']);}comm_put('booking.'.$b['id'],$b);
    });
}
function comm_make_booking(array $cfg,array $input): array {
    $type=reader_choice($input['type']??'', $cfg['types'],'meeting');$date=comm_date($input['date']??null);$zone=comm_zone($input['timezone']??'UTC');
    $first=reader_text($input['first']??'','First name',80,true);$last=reader_text($input['last']??'','Last name',80,true);$email=reader_text($input['email']??'','Email',254,true);if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email.');$agenda=reader_text($input['agenda']??'','Agenda',1500,true);$start=filter_var($input['slot']??null,FILTER_VALIDATE_INT);if(!$start)throw new InvalidArgumentException('Choose an available time.');
    if(empty($input['consent']))throw new InvalidArgumentException('Confirm the use of your details for this booking.');
    $nonce=$input['request_id']??'';if(!is_string($nonce)||!preg_match('/^[a-f0-9]{32}$/D',$nonce))throw new InvalidArgumentException('Reload the booking form.');
    return comm_lock('reservations',function()use($cfg,$input,$type,$date,$zone,$first,$last,$email,$agenda,$start,$nonce){$id=substr(hash_hmac('sha256','request|'.$nonce,(string)app_config('app_key')),0,32);$prior=comm_get('booking.'.$id);if($prior)return $prior;
        if(!in_array($start,comm_slots($cfg,$type,$date),true))throw new RuntimeException('This time is no longer available. Choose another time.');
        $b=['id'=>$id,'nonce'=>bin2hex(random_bytes(24)),'type'=>$type,'first'=>$first,'last'=>$last,'email'=>$email,'agenda'=>$agenda,'timezone'=>$zone->getName(),'start'=>$start,'end'=>$start+(int)$cfg['types'][$type]['minutes']*60,'status'=>'requested','created'=>time(),'version'=>1,'join'=>'','event_id'=>''];comm_put('booking.'.$id,$b);comm_event('bookings',$id);
        if(empty($cfg['types'][$type]['approval'])){try{return comm_confirm($b,$cfg);}catch(RuntimeException){return comm_get('booking.'.$id);}}comm_queue_mail($b,'requested');return $b;
    });
}
function comm_change_booking(string $id,string $action,?int $newStart=null,string $reason='',int $late=0,string $actor='owner'): array {
    return comm_lock('reservations',function()use($id,$action,$newStart,$reason,$late,$actor){$b=comm_get('booking.'.$id);$cfg=comm_config();if(!$b)throw new InvalidArgumentException('Booking not found.');
        if(in_array($action,['cancel','decline','late'],true)){
            $reason=reader_text($reason,'Reason',1000,true);$b['change_reason']=$reason;$b['change_actor']=$actor;
        }
        if($action==='late'){
            if((int)($b['late_notices'][$actor]??0)>time()-300)throw new RuntimeException('A late notice was just recorded. Wait five minutes before another update.');$b['late_notices'][$actor]=time();
            if(!in_array($b['status'],['requested','confirmed'],true)||$b['end']<time()||$late<1||$late>240)throw new InvalidArgumentException('Choose a current booking and delay of 1–240 minutes.');
            $b['late_minutes']=$late;$b['version']++;comm_put('booking.'.$id,$b);comm_queue_mail($b,'late');comm_event('bookings',$id);return $b;
        }
        if(in_array($action,['cancel','decline'],true)){
            if(in_array($b['status'],['cancelled','declined'],true))return $b;
            if(!in_array($b['status'],['requested','confirmed'],true))throw new RuntimeException('This booking needs manual calendar review before cancellation.');
            if($b['event_id']!==''){$b['status']='cancelling';comm_put('booking.'.$id,$b);$c=comm_writer_connection($cfg);try{publishing_http('DELETE','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($b['calendar']).'/events/'.rawurlencode($b['event_id']).'?sendUpdates=all',['Authorization: Bearer '.publishing_access_token($c)],null,false,true);}catch(Throwable $e){$b['status']='review';comm_put('booking.'.$id,$b);throw new RuntimeException('Calendar cancellation needs review. The time remains reserved.');}}
            $b['status']=$action==='decline'?'declined':'cancelled';$b['version']++;comm_put('booking.'.$id,$b);comm_queue_mail($b,$b['status']);comm_event('bookings',$id);return $b;
        }
        if($action==='approve'){if($b['status']!=='requested')throw new RuntimeException('Only requests can be approved.');$date=(new DateTimeImmutable('@'.$b['start']))->setTimezone(comm_zone($cfg['timezone']))->format('Y-m-d');if(!in_array($b['start'],comm_slots($cfg,$b['type'],$date,$id),true))throw new RuntimeException('This requested time now conflicts with your calendar.');return comm_confirm($b,$cfg);}
        if($action==='reschedule'){
            if(!in_array($b['status'],['requested','confirmed'],true)||$b['start']<=time())throw new RuntimeException('This booking cannot be rescheduled online.');$date=(new DateTimeImmutable('@'.(int)$newStart))->setTimezone(comm_zone($cfg['timezone']))->format('Y-m-d');if(!in_array($newStart,comm_slots($cfg,$b['type'],$date,$id),true))throw new RuntimeException('Choose an available time.');
            $b['start']=$newStart;$b['end']=$newStart+(int)$cfg['types'][$b['type']]['minutes']*60;
            if($b['event_id']!==''){$b['status']='rescheduling';comm_put('booking.'.$id,$b);$c=comm_writer_connection($cfg);try{publishing_http('PATCH','https://www.googleapis.com/calendar/v3/calendars/'.rawurlencode($b['calendar']).'/events/'.rawurlencode($b['event_id']).'?sendUpdates=all',['Authorization: Bearer '.publishing_access_token($c)],['start'=>['dateTime'=>gmdate(DATE_ATOM,$b['start'])],'end'=>['dateTime'=>gmdate(DATE_ATOM,$b['end'])]],false,true);$b['status']='confirmed';}catch(Throwable $e){$b['status']='review';comm_put('booking.'.$id,$b);throw new RuntimeException('Calendar rescheduling needs review. Please contact Waithaka.');}}
            $b['version']++;comm_put('booking.'.$id,$b);comm_queue_mail($b,'rescheduled');comm_event('bookings',$id);return $b;
        }
        throw new InvalidArgumentException('Choose a supported booking action.');
    });
}
function comm_config_input(array $input,int $uid): array {
    $c=comm_config();$c['owner']=$uid;$c['timezone']=comm_zone($input['timezone']??'Africa/Nairobi')->getName();
    foreach(['start','end']as$k){$v=$input[$k]??'';if(!is_string($v)||!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$v))throw new InvalidArgumentException('Choose valid working hours.');$c[$k]=$v;}if($c['start']>=$c['end'])throw new InvalidArgumentException('End time must be after start time.');
    foreach(['notice'=>[1,168],'horizon'=>[1,90],'buffer'=>[0,120],'daily_limit'=>[1,20]]as$k=>[$min,$max]){$n=filter_var($input[$k]??null,FILTER_VALIDATE_INT);if($n===false||$n<$min||$n>$max)throw new InvalidArgumentException('Review scheduling limits.');$c[$k]=$n;}
    $c['days']=reader_ids($input['days']??[],7);if(array_diff($c['days'],range(1,7)))throw new InvalidArgumentException('Choose valid weekdays.');
    $dates=trim(reader_text($input['excluded_dates']??'','Excluded dates',3000));$c['excluded_dates']=$dates===''?[]:array_map('comm_date',preg_split('/[\s,]+/',$dates));
    $c['required_accounts']=reader_ids($input['required_accounts']??[],20);foreach($c['required_accounts']as$id)google_calendar_owned($id,$uid);
    foreach(['enabled','worker','microsoft_required']as$k)$c[$k]=!empty($input[$k]);
    foreach(comm_types()as$k=>$t){$m=filter_var($input['minutes'][$k]??$t['minutes'],FILTER_VALIDATE_INT);if($m===false||$m<15||$m>120||$m%15)throw new InvalidArgumentException('Meeting duration must be 15–120 minutes in 15 minute steps.');$c['types'][$k]=$t+['enabled'=>false];$c['types'][$k]['minutes']=$m;$c['types'][$k]['enabled']=in_array($k,(array)($input['enabled_types']??[]),true);$c['types'][$k]['approval']=in_array($k,(array)($input['approval_types']??[]),true);}
    if($c['enabled']){comm_writer_connection($c);if(!$c['required_accounts']||!$c['days'])throw new RuntimeException('Choose conflict calendars and working days first.');if(!$c['worker']||!email_transport_ready())throw new RuntimeException('Enable booking notifications and configure email first.');}
    return $c;
}
function comm_json(array $value): never {header('Content-Type: application/json');echo json_encode($value,JSON_THROW_ON_ERROR);exit;}
function communication_dispatch(string $path): bool {
    if(!in_array($path,['/book','/write/communication','/write/communication/poll','/write/communication/notification','/write/app'],true)&&!preg_match('~^/book/manage/[a-f0-9]{32}/[a-f0-9]{64}$~D',$path))return false;
    private_response_headers();journeys_method(['GET','HEAD','POST']);$error='';$cfg=comm_config();
    if(str_starts_with($path,'/write/')){
        require_auth();$uid=(int)current_user()['id'];if(!empty($cfg['owner'])&&(int)$cfg['owner']!==$uid){http_response_code(403);echo 'This communication workspace belongs to another Writer.';return true;}
        if($path==='/write/communication/notification'){$signal=comm_get('signal');if(($signal['category']??'')==='planner')comm_json(['url'=>'/write/communication?tab=program','category'=>'planner']);$target=($signal['category']??'')==='bookings'?'/write/communication?tab=bookings':'/write/communication?tab=inbox';comm_json(['url'=>$target]);}
        if($path==='/write/communication/poll'){$signal=comm_get('signal');$summary=reader_operations_summary();comm_json(['signal'=>$signal['id']??'','category'=>$signal['category']??'bookings','open'=>(int)($summary['enquiries_open']??0),'overdue'=>(int)($summary['enquiries_overdue']??0),'at'=>time()]);}
        if(is_post()){verify_csrf();try{$action=$_POST['action']??'';
            if($action==='config'){comm_lock('reservations',function()use($uid){comm_put('config',comm_config_input($_POST,$uid));});flash('success','Booking preferences saved.');redirect(url('/write/communication?tab=availability'));}
            if(in_array($action,['approve','decline','cancel','late'],true)){comm_change_booking(reader_text($_POST['id']??'','Booking',32,true),$action,null,reader_text($_POST['reason']??'','Reason',1000),(int)($_POST['late_minutes']??0),'owner');flash('success','Booking updated.');redirect(url('/write/communication?tab=bookings'));}
            if($action==='preferences'){comm_put('preferences.'.$uid,comm_preferences($_POST));flash('success','Notification preferences saved.');redirect(url('/write/app'));}
            if($action==='subscribe'){comm_save_subscription($uid,$_POST['subscription']??'');comm_json(['ok'=>true]);}
            if($action==='unsubscribe'){comm_remove_subscription($uid,(string)($_POST['device']??''));comm_json(['ok'=>true]);}
            if($action==='push_test'){comm_push_user($uid,'test');flash('success','A private test alert was requested for your subscribed devices.');redirect(url('/write/app'));}
        }catch(Throwable $e){$error=$e instanceof InvalidArgumentException||get_class($e)===RuntimeException::class?$e->getMessage():'The update could not be completed.';}}
        if($path==='/write/app'){comm_vapid();render_write('app',['writeTitle'=>'App & notifications','error'=>$error,'prefs'=>comm_get('preferences.'.$uid,comm_preferences([])),'devices'=>comm_user_devices($uid),'publicKey'=>comm_get('vapid')['public']??'']);return true;}
        if(($_GET['tab']??'')==='program')redirect(url('/write/program'));
        $tab=is_string($_GET['tab']??null)?$_GET['tab']:'today';if(!in_array($tab,['today','inbox','bookings','people','availability','insights'],true))$tab='today';
        $bookings=comm_records('booking');usort($bookings,static fn($a,$b)=>$a['start']<=>$b['start']);$summary=reader_operations_summary();
        $search=reader_text($_GET['q']??'','Search',100);$enquiries=[];if(in_array($tab,['today','inbox','people'],true)){$q=db()->prepare('SELECT e.*,c.first_name,c.last_name,c.email,c.message,c.reference FROM reader_enquiries e JOIN contact_messages c ON c.id=e.contact_id WHERE CONCAT(c.first_name," ",c.last_name," ",c.email," ",c.message) LIKE ? ORDER BY e.updated_at DESC LIMIT 60');$q->execute(['%'.$search.'%']);$enquiries=$q->fetchAll();}
        render_write('communication',['writeTitle'=>'Communication Centre','tab'=>$tab,'cfg'=>$cfg,'error'=>$error,'bookings'=>$bookings,'summary'=>$summary,'enquiries'=>$enquiries,'search'=>$search,'accounts'=>google_calendar_accounts($uid)]);return true;
    }
    $manage=null;$slots=[];$type=is_string($_GET['type']??null)?$_GET['type']:'intro';$date=is_string($_GET['date']??null)?$_GET['date']:(new DateTimeImmutable('tomorrow',comm_zone($cfg['timezone'])))->format('Y-m-d');$zone=is_string($_GET['timezone']??null)?$_GET['timezone']:$cfg['timezone'];
    try{
        if(preg_match('~^/book/manage/([a-f0-9]{32})/([a-f0-9]{64})$~D',$path,$m)){$manage=comm_booking_by_cap($m[1],$m[2]);$type=$manage['type'];if(is_post()){verify_csrf();$action=$_POST['action']??'';if(!in_array($action,['cancel','reschedule','late'],true))throw new InvalidArgumentException('Choose a supported action.');$manage=comm_change_booking($manage['id'],$action,isset($_POST['slot'])?(int)$_POST['slot']:null,reader_text($_POST['reason']??'','Reason',1000),(int)($_POST['late_minutes']??0),'guest');flash('success','Booking updated.');redirect(comm_manage_url($manage));}}
        elseif($path!=='/book'){http_response_code(404);return true;}
        elseif(is_post()){verify_csrf();if(!empty($_POST['website']))throw new InvalidArgumentException('Unable to accept this request.');if(!journeys_rate_limit('booking',ip_hash(),5,3600))throw new RuntimeException('Please wait before submitting another booking.');$b=comm_make_booking($cfg,$_POST);redirect(comm_manage_url($b));}
        comm_zone($zone);comm_date($date);if(!empty($cfg['enabled'])&&!journeys_rate_limit('booking-slots',ip_hash(),30,60))throw new RuntimeException('Please wait before checking more dates.');if(!empty($cfg['enabled'])&&isset($cfg['types'][$type])&&(! $manage||in_array($manage['status'],['requested','confirmed'],true)))$slots=comm_slots($cfg,$type,$date,$manage['id']??null);
    }catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'Scheduling is temporarily unavailable. Your existing booking, if any, is unchanged. Please use Contact for assistance.';if($manage&&($e instanceof InvalidArgumentException||get_class($e)===RuntimeException::class))$error=$e->getMessage();}
    if(!isset($cfg['types'][$type]))$type='intro';try{comm_zone($zone);}catch(Throwable){$zone=$cfg['timezone'];}
    render('booking',['page'=>'booking','cfg'=>$cfg,'type'=>$type,'date'=>$date,'zone'=>$zone,'slots'=>$slots,'manage'=>$manage,'error'=>$error,'seo'=>['title'=>'Book time with Waithaka · Waithaka Ndegwa','description'=>'Schedule a conversation about entrepreneurship, innovation, collaboration or media.','canonical'=>url('/book'),'robots'=>'noindex,nofollow']]);return true;
}
