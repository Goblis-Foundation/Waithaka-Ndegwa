<?php
declare(strict_types=1);
function planner_reminder_sequence(mixed $value): string {if(!is_string($value)||!in_array($value,['inherit','single','staggered'],true))throw new InvalidArgumentException('Choose a reminder sequence.');return $value;}
function planner_reminder_times(array $r,string $sequence): array {
 $lead=(int)($r['reminder']??0);if($lead<=0)return [];
 $minutes=$sequence==='staggered'?array_values(array_unique([$lead,min(5,$lead),0])):[$lead];$times=array_map(static fn($m)=>(int)$r['start']-$m*60,$minutes);
 if(!empty($r['snooze_until'])){$times=array_values(array_filter($times,static fn($t)=>$t>=(int)$r['snooze_until']));$times[]=(int)$r['snooze_until'];}sort($times,SORT_NUMERIC);return array_values(array_unique($times));
}
function planner_reminder_latest(array $times,int $now): ?int {$past=array_values(array_filter($times,static fn($t)=>$t<=$now));return $past?max($past):null;}
function planner_remote_times(array $current,array $in,string $zone): array {
 if(isset($current['start']['date'])){$start=comm_date($in['new_start_date']??null);$last=comm_date($in['new_last_date']??null);if($last<$start||(new DateTimeImmutable($start))->diff(new DateTimeImmutable($last))->days>30)throw new InvalidArgumentException('Choose an all-day date range of up to 31 days.');$end=(new DateTimeImmutable($last))->modify('+1 day')->format('Y-m-d');return ['start'=>['date'=>$start],'end'=>['date'=>$end]];}
 $start=planner_time($in['new_start']??null,$zone);$end=planner_time($in['new_end']??null,$zone);if($end<=$start||$end-$start>31*86400)throw new InvalidArgumentException('End must follow start, within 31 days.');return ['start'=>['dateTime'=>gmdate(DATE_ATOM,$start),'timeZone'=>$zone],'end'=>['dateTime'=>gmdate(DATE_ATOM,$end),'timeZone'=>$zone]];
}
function booking_reschedule_dispatch(string $path): bool {
 if($path!=='/write/booking-reschedule')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];$cfg=comm_config();if((int)$cfg['owner']!==$uid){http_response_code(403);return true;}$id=reader_text($_GET['id']??'','Booking',32,true);if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!($b=comm_get('booking.'.$id)))not_found();$error='';$slots=[];
 $date=is_string($_GET['date']??null)?$_GET['date']:(new DateTimeImmutable('@'.$b['start']))->setTimezone(comm_zone($cfg['timezone']))->format('Y-m-d');
 try{if(is_post()){verify_csrf();if(($_POST['confirm_change']??'')!=='1')throw new InvalidArgumentException('Confirm the changed date and time and service notifications.');$slot=filter_var($_POST['slot']??null,FILTER_VALIDATE_INT);if(!$slot)throw new InvalidArgumentException('Choose a verified available time.');comm_change_booking($id,'reschedule',$slot,reader_text($_POST['reason']??'','Change reason',1000,true),0,'owner',(int)($_POST['version']??0));flash('success','Booking date and time changed. Calendar and service updates are processed separately.');redirect(url('/write/booking-review?id='.$id));}
 comm_date($date);if(!in_array($b['status'],['requested','confirmed'],true))throw new RuntimeException('Only current requests or confirmed meetings can be rescheduled.');$slots=comm_slots($cfg,$b['type'],$date,$id);
 }catch(Throwable $e){$error=$e instanceof InvalidArgumentException||get_class($e)===RuntimeException::class?$e->getMessage():'Availability could not be verified. This booking is unchanged.';}
 render_write('booking_reschedule',['writeTitle'=>'Change booking date & time','b'=>$b,'cfg'=>$cfg,'date'=>$date,'slots'=>$slots,'error'=>$error]);return true;
}
