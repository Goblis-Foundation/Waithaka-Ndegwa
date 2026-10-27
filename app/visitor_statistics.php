<?php
declare(strict_types=1);
require_once __DIR__.'/visitor_presence.php';
/** Verified GA4 period aggregates, with independent, first-party live presence. */
function visitor_statistics_number(array $report,int $metric=0): ?int {
 $v=$report['rows'][0]['metricValues'][$metric]['value']??null;
 if(!empty($report['metadata']['subjectToThresholding'])||!empty($report['error']))return null;
 if(is_int($v)&&$v>=0&&$v<=999999999999999)return $v;
 if(!is_string($v)||!preg_match('/^[0-9]{1,15}$/D',$v))return null;return (int)$v;
}
function visitor_statistics_binding(array $streams,string $tag,string $host): array {
 $matches=[];foreach($streams as$s){$web=$s['webStreamData']??[];if(($web['measurementId']??'')===$tag&&strtolower((string)parse_url($web['defaultUri']??'',PHP_URL_HOST))===$host&&preg_match('~/dataStreams/([0-9]+)$~D',$s['name']??'',$m))$matches[]=['id'=>$m[1],'created'=>$s['createTime']??''];}
 if(count($matches)!==1)throw new RuntimeException('The deployed tag must match exactly one web stream for this website.');return $matches[0];
}
function visitor_statistics_month(string $zone,?DateTimeImmutable $now=null): array {
 $now=($now??new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($zone));
 return ['month'=>$now->format('Y-m'),'start'=>$now->format('Y-m-01'),'end'=>$now->format('Y-m-d'),'label'=>$now->format('F Y')];
}
function visitor_statistics_fresh(mixed $value,mixed $at,int $stamp,int $ttl): bool {
 return is_int($value)&&$value>=0&&$value<=999999999999999&&is_int($at)&&$at<=$stamp+5&&$at>=$stamp-$ttl;
}
function visitor_statistics_display(array $s,?DateTimeImmutable $now=null): array {
 if(empty($s['timezone']))return [];
 try{$period=visitor_statistics_month($s['timezone'],$now);}catch(Throwable){return [];}
 $stamp=($now??new DateTimeImmutable('now'))->getTimestamp();$year=substr($period['month'],0,4);
 $out=['month_label'=>substr($period['label'],0,-5),'year_label'=>$year,'month_key'=>$period['month'],'year_key'=>$year,'timezone'=>$s['timezone'],'server_time'=>$stamp,'refresh_after'=>30,'as_of'=>[]];
 // Historical GA4 live snapshots are retained for private diagnostics only.
 // The public payload always replaces Now with authoritative website presence.
 if(($s['active_window']??0)===2&&($s['active_mode']??'')==='presence'&&visitor_statistics_fresh($s['active']??null,$s['active_at']??null,$stamp,150)){$out['now']=$s['active'];$out['as_of']['now']=$s['active_at'];}
 if(($s['month']??'')===$period['month']&&($s['monthly_metric']??'')==='totalUsers'&&visitor_statistics_fresh($s['monthly_visitors']??null,$s['month_at']??null,$stamp,900)){$out['month']=$s['monthly_visitors'];$out['as_of']['month']=$s['month_at'];}
 if(($s['year']??'')===$year&&($s['yearly_metric']??'')==='totalUsers'&&visitor_statistics_fresh($s['yearly_visitors']??null,$s['year_at']??null,$stamp,900)){$out['year']=$s['yearly_visitors'];$out['as_of']['year']=$s['year_at'];}
 return $out;
}
function visitor_statistics_realtime_request(string $stream,bool $singleStream,bool $presence=false): array {
 $request=['metrics'=>[['name'=>'activeUsers']]];
 $filters=[];if(!$singleStream)$filters[]=['filter'=>['fieldName'=>'streamId','stringFilter'=>['matchType'=>'EXACT','value'=>$stream]]];
 if($presence){$request['minuteRanges']=[['name'=>'live','startMinutesAgo'=>1,'endMinutesAgo'=>0]];$filters[]=['filter'=>['fieldName'=>'eventName','stringFilter'=>['matchType'=>'EXACT','value'=>'wn_live_presence']]];}
 if(count($filters)===1)$request['dimensionFilter']=$filters[0];elseif($filters)$request['dimensionFilter']=['andGroup'=>['expressions'=>$filters]];
 return $request;
}
function visitor_statistics_realtime_number(array $report): ?int {
 $value=visitor_statistics_number($report);if($value!==null)return $value;
 if(isset($report['totals'][0])){$total=$report;$total['rows']=[$report['totals'][0]];$value=visitor_statistics_number($total);if($value!==null)return $value;}
 if(empty($report['metadata']['subjectToThresholding'])&&empty($report['rows'])&&($report['metricHeaders'][0]['name']??'')==='activeUsers'&&empty($report['error']))return 0;return null;
}
function visitor_statistics_period_number(array $report,int $index=0): ?int {
 if(($report['metricHeaders'][$index]['name']??'')!=='totalUsers'||!empty($report['error'])||!empty($report['metadata']['subjectToThresholding']))return null;
 $n=visitor_statistics_number($report,$index);return $n!==null?$n:(empty($report['rows'])?0:null);
}
function visitor_statistics_refresh(bool $force=false): void {
 // Short-lived pseudonyms are removed independently of Analytics availability.
 try{visitor_presence_cleanup();}catch(Throwable){error_log('Live presence cleanup needs review.');}
 if(setting('google.analytics_enabled','1')!=='1')return;
 comm_lock('visitor.statistics',function()use($force){$s=comm_get('visitor.statistics');if(!$force&&($s['attempted']??0)>time()-55)return;$s['attempted']=time();comm_put('visitor.statistics',$s);
 try{
  $c=google_workspace_connection('google_analytics');$property=$c['selected_resource'];if(!preg_match('~^properties/[0-9]+$~D',$property))throw new RuntimeException('Invalid Analytics property.');
  $tag=(string)setting('google.ga4','G-RN0YXC2WCH');$h=publishing_bearer(publishing_access_token($c));$host=strtolower((string)parse_url(base_url(),PHP_URL_HOST));$binding=hash('sha256',$property.'|'.$tag.'|'.$host);
  if(($s['binding']??'')!==$binding||($s['binding_at']??0)<time()-3600||empty($s['timezone'])||!array_key_exists('single_stream',$s)){
   $streams=publishing_http('GET','https://analyticsadmin.googleapis.com/v1beta/'.$property.'/dataStreams?pageSize=200',$h)['data'];if(!empty($streams['nextPageToken']))throw new RuntimeException('Stream selection needs review.');
   $stream=visitor_statistics_binding($streams['dataStreams']??[],$tag,$host);$p=publishing_http('GET','https://analyticsadmin.googleapis.com/v1beta/'.$property,$h)['data'];$zone=new DateTimeZone($p['timeZone']??'UTC');
   if(($s['binding']??'')!==$binding)$s=['attempted'=>time()];
   $s['stream']=$stream['id'];$s['property']=$property;$s['binding']=$binding;$s['binding_at']=time();$s['tag']=$tag;$s['timezone']=$zone->getName();$s['single_stream']=count($streams['dataStreams']??[])===1;
   $created=$stream['created']?:($p['createTime']??'');if(is_string($created)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T/',$created))$s['since']=(new DateTimeImmutable($created))->setTimezone($zone)->format('Y-m-d');
  }
  // Now is read directly from the ephemeral website-presence store. Do not
  // substitute Google's 30-minute window or a missing provider report for it.
  $s['realtime_status']='Website presence is authoritative';
  $period=visitor_statistics_month($s['timezone']);$year=substr($period['month'],0,4);
  $filter=['filter'=>['fieldName'=>'streamId','stringFilter'=>['matchType'=>'EXACT','value'=>$s['stream']]]];
  foreach(['month'=>[$period['month'],$period['start'],'monthly_visitors','monthly_metric'],'year'=>[$year,$year.'-01-01','yearly_visitors','yearly_metric']]as$key=>[$periodKey,$start,$valueKey,$metricKey]){
   if(!$force&&($s[$key]??'')===$periodKey&&($s[$key.'_at']??0)>=time()-300&&($s[$metricKey]??'')==='totalUsers')continue;
   try{$report=publishing_http('POST','https://analyticsdata.googleapis.com/v1beta/'.$property.':runReport',$h,['dateRanges'=>[['startDate'=>$start,'endDate'=>$period['end']]],'metrics'=>[['name'=>'totalUsers']],'dimensionFilter'=>$filter])['data'];
    $s[$valueKey]=visitor_statistics_period_number($report);$s[$metricKey]='totalUsers';$s[$key]=$periodKey;$s[$key.'_at']=time();$s[$key.'_status']='updated';
   }catch(Throwable){$s[$key.'_status']='Request not confirmed';}
  }
  $s['status']='updated';$s['implementation']='direct-presence-v3';comm_put('visitor.statistics',$s);
 }catch(Throwable $e){$s['status']='unavailable';comm_put('visitor.statistics',$s);if($force)throw new RuntimeException('Period statistics could not be verified. Check the GA4 connection and Admin/Data API access. No estimated numbers were published.');}
 });
}
function visitor_statistics_public(): array {
 if(setting('google.analytics_enabled','1')!=='1')return [];
 try{
  $s=comm_get('visitor.statistics');$tag=(string)setting('google.ga4','G-RN0YXC2WCH');
  $q=db()->prepare('SELECT selected_resource,status FROM publishing_connections WHERE service=?');$q->execute(['google_analytics']);$c=$q->fetch();
  $binding=$c?hash('sha256',$c['selected_resource'].'|'.$tag.'|'.strtolower((string)parse_url(base_url(),PHP_URL_HOST))):'';
  if(!$c||$c['status']!=='connected'||($s['tag']??'')!==$tag||!hash_equals($binding,(string)($s['binding']??''))||($s['binding_at']??0)<time()-7200)return [];
  return visitor_statistics_display($s);
 }catch(Throwable){return [];}
}
function visitor_statistics_payload(): array {
 $s=visitor_statistics_public();$zone=$s['timezone']??(string)app_config('timezone','Africa/Nairobi');
 try{$period=visitor_statistics_month($zone);}catch(Throwable){$zone='Africa/Nairobi';$period=visitor_statistics_month($zone);}
 $live=visitor_presence_public();$asOf=$s['as_of']??[];unset($asOf['now']);if($live['at']!==null)$asOf['now']=$live['at'];
 return ['enabled'=>setting('google.analytics_enabled','1')==='1','server_time'=>time(),'timezone'=>$zone,'month_key'=>$period['month'],'year_key'=>substr($period['month'],0,4),'month_label'=>substr($period['label'],0,-5),'year_label'=>substr($period['month'],0,4),'refresh_after'=>20,'now'=>$live['now'],'month'=>$s['month']??null,'year'=>$s['year']??null,'as_of'=>$asOf];
}
function visitor_statistics_dispatch(string $path): bool {
 if(visitor_presence_dispatch($path))return true;
 if($path==='/api/visitor-statistics'){
  journeys_method(['GET','HEAD']);private_response_headers();header('Cache-Control: no-store, max-age=0');header('Content-Type: application/json; charset=utf-8');
  if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo json_encode(visitor_statistics_payload(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);return true;
 }
 if($path!=='/write/visitor-statistics')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);if((int)current_user()['id']!==(int)comm_config()['owner']){http_response_code(403);echo 'Owner access required.';return true;}$error='';
 if(is_post()){verify_csrf();try{visitor_statistics_refresh(true);flash('success','Aggregate visitor statistics queried. See individual source timestamps and status below.');redirect(url('/write/visitor-statistics'));}catch(RuntimeException $e){$error=$e->getMessage();}}
 render_write('visitor_statistics',['writeTitle'=>'Footer visitor statistics','statistics'=>comm_get('visitor.statistics'),'error'=>$error]);return true;
}
