<?php
declare(strict_types=1);
/** Consented GA4 aggregates only. No public request makes a provider call. */
function visitor_statistics_number(array $report,int $metric=0): ?int {
 $v=$report['rows'][0]['metricValues'][$metric]['value']??null;
 if(!is_string($v)||!preg_match('/^[0-9]{1,15}$/D',$v)||!empty($report['metadata']['subjectToThresholding']))return null;
 return (int)$v;
}
function visitor_statistics_binding(array $streams,string $tag,string $host): array {
 $matches=[];foreach($streams as$s){$web=$s['webStreamData']??[];if(($web['measurementId']??'')===$tag&&strtolower((string)parse_url($web['defaultUri']??'',PHP_URL_HOST))===$host&&preg_match('~/dataStreams/([0-9]+)$~D',$s['name']??'',$m))$matches[]=['id'=>$m[1],'created'=>$s['createTime']??''];}
 if(count($matches)!==1)throw new RuntimeException('The deployed tag must match exactly one web stream for this website.');return $matches[0];
}
function visitor_statistics_month(string $zone,?DateTimeImmutable $now=null): array {
 $now=($now??new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($zone));
 return ['month'=>$now->format('Y-m'),'start'=>$now->format('Y-m-01'),'end'=>$now->format('Y-m-d'),'label'=>$now->format('F Y')];
}
function visitor_statistics_display(array $s,?DateTimeImmutable $now=null): array {
 if(empty($s['timezone']))return [];
 try{$period=visitor_statistics_month($s['timezone'],$now);}catch(Throwable){return [];}
 $stamp=($now??new DateTimeImmutable('now'))->getTimestamp();
 $year=substr($period['month'],0,4);$out=['month_label'=>substr($period['label'],0,-5),'year_label'=>$year];
 if(isset($s['active'])&&is_int($s['active'])&&$s['active']>=0&&($s['active_at']??0)>=$stamp-180&&($s['active_window']??0)===5)$out['now']=$s['active'];
 if(($s['month']??'')===$period['month']&&($s['month_at']??0)>=$stamp-86400&&isset($s['monthly_visitors']))$out['month']=$s['monthly_visitors'];
 if(($s['year']??'')===$year&&($s['year_at']??0)>=$stamp-86400&&isset($s['yearly_visitors']))$out['year']=$s['yearly_visitors'];
 return isset($out['now'])||isset($out['month'])||isset($out['year'])?$out:[];
}
function visitor_statistics_realtime_number(array $report): ?int {
 $value=visitor_statistics_number($report);if($value!==null)return $value;
 if(empty($report['metadata']['subjectToThresholding'])&&empty($report['rows'])&&($report['metricHeaders'][0]['name']??'')==='activeUsers'&&($report['kind']??'')==='analyticsData#runRealtimeReport')return 0;
 return null;
}
function visitor_statistics_refresh(bool $force=false): void {
 if(setting('google.analytics_enabled','1')!=='1')return;
 comm_lock('visitor.statistics',function()use($force){$s=comm_get('visitor.statistics');if(!$force&&($s['attempted']??0)>time()-60)return;$s['attempted']=time();comm_put('visitor.statistics',$s);
 try{
 $c=google_workspace_connection('google_analytics');$property=$c['selected_resource'];if(!preg_match('~^properties/[0-9]+$~D',$property))throw new RuntimeException('Invalid Analytics property.');$tag=(string)setting('google.ga4','G-RN0YXC2WCH');$h=publishing_bearer(publishing_access_token($c));$host=strtolower((string)parse_url(base_url(),PHP_URL_HOST));
 $binding=hash('sha256',$property.'|'.$tag.'|'.$host);
 if(($s['binding']??'')!==$binding||($s['binding_at']??0)<time()-3600||empty($s['timezone'])){
  $streams=publishing_http('GET','https://analyticsadmin.googleapis.com/v1beta/'.$property.'/dataStreams?pageSize=200',$h)['data'];if(!empty($streams['nextPageToken']))throw new RuntimeException('Stream selection needs review.');
  $stream=visitor_statistics_binding($streams['dataStreams']??[],$tag,$host);$p=publishing_http('GET','https://analyticsadmin.googleapis.com/v1beta/'.$property,$h)['data'];$zone=new DateTimeZone($p['timeZone']??'UTC');$createdAt=$stream['created']?:($p['createTime']??'');if(!is_string($createdAt)||!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T/',$createdAt))throw new RuntimeException('Analytics history date unavailable.');$created=new DateTimeImmutable($createdAt);$since=$created->setTimezone($zone)->format('Y-m-d');if($since<'2005-01-01'||$since>date('Y-m-d'))throw new RuntimeException('Analytics history date unavailable.');
  if(($s['binding']??'')!==$binding)$s=['attempted'=>time()];$s+=['stream'=>$stream['id'],'since'=>$since];$s['stream']=$stream['id'];$s['since']=$since;$s['binding']=$binding;$s['binding_at']=time();$s['tag']=$tag;$s['timezone']=$zone->getName();
 }
 $filter=['filter'=>['fieldName'=>'streamId','stringFilter'=>['matchType'=>'EXACT','value'=>$s['stream']]]];
 try{
  $rt=publishing_http('POST','https://analyticsdata.googleapis.com/v1beta/'.$property.':runRealtimeReport',$h,['metrics'=>[['name'=>'activeUsers']],'dimensionFilter'=>$filter,'minuteRanges'=>[['startMinutesAgo'=>4,'endMinutesAgo'=>0]]])['data'];
  $s['active']=visitor_statistics_realtime_number($rt);$s['active_at']=time();$s['active_window']=5;
 }catch(Throwable){unset($s['active'],$s['active_at']);}
 $period=visitor_statistics_month($s['timezone']);
 if($force||($s['month']??'')!==$period['month']||($s['month_at']??0)<time()-3600){
  $monthly=publishing_http('POST','https://analyticsdata.googleapis.com/v1beta/'.$property.':runReport',$h,['dateRanges'=>[['startDate'=>$period['start'],'endDate'=>$period['end']]],'metrics'=>[['name'=>'activeUsers'],['name'=>'sessions']],'dimensionFilter'=>$filter])['data'];
  $s['monthly_visitors']=visitor_statistics_number($monthly,0);$s['monthly_visits']=visitor_statistics_number($monthly,1);$s['month']=$period['month'];$s['month_at']=time();
 }
 $year=substr($period['month'],0,4);
 if($force||($s['year']??'')!==$year||($s['year_at']??0)<time()-3600){
  $yearly=publishing_http('POST','https://analyticsdata.googleapis.com/v1beta/'.$property.':runReport',$h,['dateRanges'=>[['startDate'=>$year.'-01-01','endDate'=>$period['end']]],'metrics'=>[['name'=>'activeUsers']],'dimensionFilter'=>$filter])['data'];
  $s['yearly_visitors']=visitor_statistics_number($yearly);$s['year']=$year;$s['year_at']=time();
 }
 $s['status']='updated';comm_put('visitor.statistics',$s);
 }catch(Throwable $e){$s['status']='unavailable';comm_put('visitor.statistics',$s);if($force)throw new RuntimeException('Visitor statistics could not be verified. Check the GA4 connection, deployed tag and Admin/Data API access. No estimated numbers were published.');}
 });
}
function visitor_statistics_public(): array {
 if(setting('google.analytics_enabled','1')!=='1')return [];
 $s=comm_get('visitor.statistics');if(($s['tag']??'')!==(string)setting('google.ga4','G-RN0YXC2WCH')||($s['binding_at']??0)<time()-7200)return [];
 return visitor_statistics_display($s);
}
function visitor_statistics_dispatch(string $path): bool {
 if($path!=='/write/visitor-statistics')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);if((int)current_user()['id']!==(int)comm_config()['owner']){http_response_code(403);echo 'Owner access required.';return true;}$error='';
 if(is_post()){verify_csrf();try{visitor_statistics_refresh(true);flash('success','Verified aggregate visitor statistics refreshed.');redirect(url('/write/visitor-statistics'));}catch(RuntimeException $e){$error=$e->getMessage();}}
 render_write('visitor_statistics',['writeTitle'=>'Footer visitor statistics','statistics'=>comm_get('visitor.statistics'),'error'=>$error]);return true;
}
