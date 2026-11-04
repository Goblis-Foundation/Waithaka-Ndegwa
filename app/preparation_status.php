<?php
declare(strict_types=1);
/** Fixed-stage operational evidence; no content, addresses, tokens or remote errors. */
function preparation_names(): array {return ['editorial'=>'Editorial plan and private newsletter drafts','audience'=>'Aggregate audience refresh','impact'=>'Approved impact-source refresh','calendars'=>'Selected-calendar refresh','search'=>'Scheduled Search Console review'];}

function background_names(): array {return preparation_names()+[
 'roundup'=>'Monthly public roundup','cleanup'=>'Expired sign-in challenge cleanup','communication'=>'Booking communication and notification checks','planner'=>'Daily programme checks','contact'=>'Approved contact delivery','founder'=>'Founder story expiry and delivery','desk'=>'Commercial publishing desk','opportunity_review'=>'Opportunity source checks and lifecycle','weekly'=>'Weekly publishing scheduler','paypal'=>'PayPal notification reconciliation','monthly'=>'Monthly support status','opportunity'=>'Approved employment-opportunity automation','direct'=>'Independent approved social delivery','publishing'=>'Global social delivery','payments'=>'Payment reconciliation','newsletter'=>'Newsletter preparation and queueing','email'=>'Global email delivery','discovery'=>'Discovery preparation'];}
function background_save(string $stage,array $record,?string $expected=null): void {
 try{comm_lock('monitor.'.$stage,static function()use($stage,$record,$expected){if($expected!==null&&(comm_get('preparation.status.'.$stage)['run']??null)!==$expected)return;comm_put('preparation.status.'.$stage,$record);});}catch(Throwable){error_log('Background stage monitoring unavailable.');}
}
function background_stage(string $stage,callable $work): mixed {
 if(!isset(background_names()[$stage]))throw new InvalidArgumentException('Unknown background stage.');
 try{$run=bin2hex(random_bytes(12));}catch(Throwable){error_log('Background stage monitoring unavailable.');return $work();}$started=time();$clock=microtime(true);background_save($stage,['run'=>$run,'started'=>$started,'state'=>'running']);
 try{$result=$work();$state=is_array($result)&&isset($result['enabled'])&&!$result['enabled']?'disabled':'completed';
  if(is_array($result)&&(!empty($result['failed'])||!empty($result['attention'])||!empty($result['ambiguous'])))$state='attention';
  $safe=[];if(is_array($result))foreach(['checked','failed','prepared','processed','published','queued','sent','attention','ambiguous','suppressed','mail_processed']as$key)if(isset($result[$key])&&is_int($result[$key])&&$result[$key]>=0)$safe[$key]=min(1000000,$result[$key]);
  if(is_array($result)&&isset($result['prepared'])&&is_bool($result['prepared']))$safe['prepared']=$result['prepared'];
  background_save($stage,['run'=>$run,'started'=>$started,'finished'=>time(),'state'=>$state,'duration_ms'=>min(86400000,max(0,(int)round((microtime(true)-$clock)*1000))),'counts'=>$safe],$run);return $result;
 }catch(Throwable $e){background_save($stage,['run'=>$run,'started'=>$started,'finished'=>time(),'state'=>'attention'],$run);throw $e;}
}
function preparation_stage(string $stage,callable $work): mixed {if(!isset(preparation_names()[$stage]))throw new InvalidArgumentException('Unknown preparation stage.');return background_stage($stage,$work);}
function background_status(?int $now=null): array {
 $now??=time();$rows=[];
 foreach(background_names()as$key=>$name){try{$r=comm_get('preparation.status.'.$key);}catch(Throwable){$r=[];}
  $at=$r['finished']??$r['started']??0;$valid=is_int($at)&&$at>0&&$at<=$now+60;$state=$r['state']??'not_recorded';
  if(!$valid||!in_array($state,['running','completed','disabled','attention'],true)){$at=0;$state='not_recorded';}
  $freshness=$at===0?'Not verified':($now-$at>900?'Stale · no recent check':'Recent');
  if($state==='running'&&$at&&$now-$at>900)$state='interrupted';
  $rows[]=['key'=>$key,'name'=>$name,'state'=>$state,'at'=>$at,'freshness'=>$freshness,'duration_ms'=>isset($r['duration_ms'])&&is_int($r['duration_ms'])&&$r['duration_ms']>=0&&$r['duration_ms']<=86400000?$r['duration_ms']:null];
 }return $rows;
}
function preparation_status(): array {return array_values(array_filter(background_status(),static fn($r)=>isset(preparation_names()[$r['key']])));}
