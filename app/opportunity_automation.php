<?php
declare(strict_types=1);

function opportunity_schedule_time(array $in,array $r): int {
 $zone=reader_text($in['schedule_timezone']??'Africa/Nairobi','Publication timezone',80,true);if(!in_array($zone,DateTimeZone::listIdentifiers(),true))throw new OpportunityException('Choose a valid publication timezone.');
 $local=reader_text($in['schedule_local']??'','Publication date and time',16,true);$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$local,new DateTimeZone($zone));
 if(!$d||$d->format('Y-m-d\TH:i')!==$local||$d->getTimestamp()<time()+60||$d->getTimestamp()>=($r['fields']['closes_at']??0))throw new OpportunityException('Choose a publication time at least one minute ahead and before applications close.');return $d->getTimestamp();
}
function opportunity_publish_record(array &$r): void {
 if(!in_array($r['state'],['approved','scheduled'],true)||!hash_equals((string)($r['approved_hash']??''),opportunity_digest($r['fields']))||($r['fields']['closes_at']??0)<=time())throw new OpportunityException('A current, unchanged founder approval and future deadline are required.');
 $r['state']='published';$r['published_at']=time();$r['public']=$r['fields']+['id'=>$r['id'],'published_at'=>$r['published_at'],'reviewed_at'=>time(),'enterprise_id'=>$r['enterprise_id']??''];unset($r['schedule']);
}
function opportunity_publish_due(string $id,?int $now=null): bool {
 $now??=time();return opportunity_atomic(static function()use($id,$now){$r=opportunity_record($id);if($r['state']!=='scheduled'||($r['schedule']['at']??PHP_INT_MAX)>$now)return false;
  $valid=($r['schedule']['owner']??0)===(int)(comm_config()['owner']??0)&&hash_equals((string)($r['schedule']['hash']??''),opportunity_digest($r['fields']))&&hash_equals((string)($r['approved_hash']??''),opportunity_digest($r['fields']));
  if(!$valid||($r['fields']['closes_at']??0)<=$now){$r['state']=($r['fields']['closes_at']??0)<=$now?'expired':'submitted';$r['public']=null;$r['approved_hash']=null;unset($r['schedule']);opportunity_audit($r,'schedule_blocked','worker','Approval, owner or deadline no longer valid.');$r['link']=null;opportunity_persist($r);return false;}
  opportunity_publish_record($r);opportunity_audit($r,'scheduled_publication','worker');opportunity_link($r,'followup','Your founder-approved opportunity has been published at the scheduled time. This private link lets you view its status or withdraw it.');opportunity_persist($r);return true;
 });
}
function opportunity_evidence_metadata(string $name,string $mime,string $bytes): array {
 if(!isset(['application/pdf'=>1,'image/jpeg'=>1,'image/png'=>1][$mime])||strlen($bytes)<1||strlen($bytes)>4*1024*1024)throw new OpportunityException('Use one PDF, JPEG or PNG file up to 4 MB.');
 $name=basename(str_replace('\\','/',$name));$name=preg_replace('/[\x00-\x1f\x7f]/u','',$name)??'';if(!mb_check_encoding($name,'UTF-8'))$name='Evidence document';
 return ['id'=>bin2hex(random_bytes(16)),'name'=>mb_substr($name,0,180),'mime'=>$mime,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'uploaded_at'=>time()];
}
/** Files never enter the public media library or an AI provider. */
function opportunity_evidence_upload(?array $file,bool $consent): ?array {
 if(!$file||($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE)return null;
 if(!$consent)throw new OpportunityException('Confirm permission to store this evidence privately.');
 if(($file['error']??null)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>4*1024*1024)throw new OpportunityException('Use one PDF, JPEG or PNG file up to 4 MB.');
 $bytes=file_get_contents($file['tmp_name']);if(!is_string($bytes))throw new OpportunityException('The evidence file could not be read.');$mime=(string)(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$doc=opportunity_evidence_metadata((string)($file['name']??'Evidence'),$mime,$bytes);$ext=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$mime];
 $doc['path']=media_store_private_upload($file['tmp_name'],'opportunity-'.$doc['id'].'.'.$ext,$mime);return $doc;
}
function opportunity_evidence_cleanup(?array $doc): void {
 if(!$doc)return;$path=media_private_path((string)($doc['path']??''),(string)app_config('media.driver','local'));if(!$path||!preg_match('~/opportunity-[a-f0-9]{32}\.(?:pdf|jpg|png)$~D',$path))return;
 try{if(str_starts_with($path,'s3-private://'))media_s3_client()->deleteObject(['Bucket'=>app_config('media.private_bucket'),'Key'=>substr($path,13)]);else{ $full=dirname(__DIR__).'/'.$path;if(is_file($full))unlink($full);}}catch(Throwable){error_log('Uncommitted opportunity evidence needs private cleanup.');}
}
function opportunity_evidence_append(array &$r,?array $doc,string $actor): void {
 if(!$doc)return;if(count($r['evidence']??[])>=5)throw new OpportunityException('Up to five private evidence files are supported per opportunity.');$doc['actor']=$actor;$r['evidence'][]=$doc;
}
function opportunity_evidence_get(array $r,string $fileId): ?array {foreach($r['evidence']??[]as$doc)if(hash_equals($doc['id'],$fileId))return $doc;return null;}
function opportunity_evidence_serve(array $doc): void {
 $bytes=booking_document_bytes($doc);private_response_headers();header('X-Content-Type-Options: nosniff');header("Content-Security-Policy: sandbox; default-src 'none'");header('Content-Type: '.$doc['mime']);$ext=['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png'][$doc['mime']];header('Content-Disposition: attachment; filename="opportunity-evidence.'.$ext.'"');header('Content-Length: '.strlen($bytes));if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD')echo $bytes;
}

function opportunity_delivery_config(): array {return comm_get('opportunity.delivery_config',['enabled'=>false,'sender_id'=>'','template'=>'','language'=>'en','version'=>'v23.0','access_token'=>'','app_secret'=>'','verify_token'=>'']);}
function opportunity_delivery_ready(?array $cfg=null): bool {$c=$cfg??opportunity_delivery_config();return !empty($c['enabled'])&&preg_match('/^[0-9]{5,30}$/D',(string)$c['sender_id'])&&preg_match('/^[a-z0-9_]{1,100}$/D',(string)$c['template'])&&preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',(string)$c['language'])&&preg_match('/^v[0-9]{1,3}\.[0-9]{1,2}$/D',(string)$c['version'])&&strlen((string)$c['access_token'])>=10&&strlen((string)$c['app_secret'])>=16&&strlen((string)$c['verify_token'])>=24;}
function opportunity_delivery_save(int $uid,array $in): void {
 opportunity_owner($uid);comm_lock('opportunity.delivery_config',static function()use($in){$old=opportunity_delivery_config();$c=$old;
  foreach(['sender_id'=>30,'template'=>100,'language'=>10,'version'=>10]as$key=>$max)$c[$key]=reader_text($in[$key]??'',$key,$max,true);
  if(!preg_match('/^[0-9]{5,30}$/D',$c['sender_id'])||!preg_match('/^[a-z0-9_]{1,100}$/D',$c['template'])||!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/D',$c['language'])||!preg_match('/^v[0-9]{1,3}\.[0-9]{1,2}$/D',$c['version']))throw new OpportunityException('Check the sender ID, approved template, language and Graph API version.');
  foreach(['access_token'=>10000,'app_secret'=>500,'verify_token'=>500]as$key=>$max){$v=reader_text($in[$key]??'',$key,$max);if($v!==''){if(preg_match('/\s/',$v))throw new OpportunityException('Secrets must not contain whitespace.');$c[$key]=$v;}}
  if(($in['use_story_sender']??'')==='1'){if(!founder_story_whatsapp_ready())throw new OpportunityException('The existing founder-story Cloud API sender is not configured.');$c['sender_id']=(string)setting('founder_story.whatsapp_phone_id');$c['access_token']=(string)setting('founder_story.whatsapp_token');}
  $c['enabled']=($in['enabled']??'')==='1';if($c['enabled']&&(($in['template_ack']??'')!=='1'||!opportunity_delivery_ready($c)))throw new OpportunityException('Complete all credentials and confirm that this opportunity-specific template is approved by Meta.');
  unset($c['verified_at']);$c['revision']=($old['revision']??0)+1;$c['updated']=time();comm_put('opportunity.delivery_config',$c);
 });
}
function opportunity_delivery_check(int $uid): void {
 opportunity_owner($uid);$c=opportunity_delivery_config();if(!opportunity_delivery_ready($c))throw new OpportunityException('Complete and enable the saved sender configuration first.');
 $res=publishing_http('GET','https://graph.facebook.com/'.$c['version'].'/'.$c['sender_id'].'?fields=id',publishing_bearer($c['access_token']),null,false,true);
 if((string)($res['data']['id']??'')!==$c['sender_id'])throw new OpportunityException('The saved sender could not be verified. Review its account permissions.');
 comm_lock('opportunity.delivery_config',static function()use($c){$latest=opportunity_delivery_config();if(($latest['revision']??0)!==($c['revision']??0))throw new OpportunityException('Configuration changed during verification. Check the new settings.');$latest['verified_at']=time();comm_put('opportunity.delivery_config',$latest);});
}
function opportunity_email_status(array $r): array {
 if(empty($r['notification']['outbox_id']))return [];$q=db()->prepare('SELECT status,sent_at,error_code FROM email_outbox WHERE id=?');$q->execute([$r['notification']['outbox_id']]);return $q->fetch()?:[];
}
function opportunity_queue_whatsapp(array &$r,string $purpose): void {
 if(!empty($r['whatsapp'])&&($r['whatsapp']['version']??0)!==$r['version']){$prior=$r['whatsapp'];if(in_array($prior['state'],['pending','blocked'],true))$prior['state']='cancelled';$r['whatsapp_history']=array_slice([...($r['whatsapp_history']??[]),$prior],-20);unset($r['whatsapp']);}
 if(!in_array($purpose,['approval','clarification'],true)||empty($r['auto_whatsapp'])||empty($r['contact']['whatsapp_optin'])||!preg_match('/^\+[1-9][0-9]{7,14}$/D',(string)($r['contact']['phone']??'')))return;
 $id=$r['id'].'.'.$r['version'];$r['whatsapp']=['id'=>$id,'state'=>opportunity_delivery_ready()?'pending':'blocked','error'=>opportunity_delivery_ready()?'':'provider_not_configured','version'=>$r['version'],'created'=>time(),'events'=>[]];
}
function opportunity_whatsapp_payload(array $r,array $c): array {
 $url=url('/opportunities/review?id='.$r['id'].'&token='.$r['link']['token']);$values=[$r['contact']['name'],$r['fields']['title'],$r['link']['purpose']==='approval'?'Approval review '.$r['rounds'].' of 3':'Information requested',$url];$params=array_map(static fn($text)=>['type'=>'text','text'=>$text],$values);
 return ['messaging_product'=>'whatsapp','to'=>ltrim($r['contact']['phone'],'+'),'type'=>'template','template'=>['name'=>$c['template'],'language'=>['code'=>$c['language']],'components'=>[['type'=>'body','parameters'=>$params]]]];
}
function opportunity_whatsapp_send(array $r,array $c): string {
 $res=publishing_http('POST','https://graph.facebook.com/'.$c['version'].'/'.$c['sender_id'].'/messages',publishing_bearer($c['access_token']),opportunity_whatsapp_payload($r,$c),false,true);$id=$res['data']['messages'][0]['id']??'';if(!is_string($id)||$id===''||strlen($id)>255)throw new RuntimeException('Provider acceptance unconfirmed.');return $id;
}
function opportunity_whatsapp_work(string $id,?callable $transport=null): string {
 if($transport&&app_config('app_env')!=='testing')throw new RuntimeException('Test transport unavailable.');
 return comm_lock('opportunity.whatsapp.'.$id,static function()use($id,$transport){
  $r=opportunity_record($id);$job=$r['whatsapp']??[];if(!$job)return 'none';
  if(($job['state']??'')==='sending'&&($job['started']??0)<time()-900){opportunity_atomic(static function()use($id){$x=opportunity_record($id);if(($x['whatsapp']['state']??'')==='sending'){$x['whatsapp']['state']='unknown';$x['whatsapp']['error']='worker_interrupted';opportunity_persist($x);}});return 'unknown';}
  if(($job['state']??'')!=='pending')return $job['state'];$c=opportunity_delivery_config();
  $claim=opportunity_atomic(static function()use($id,$c,$transport){$x=opportunity_record($id);if(($x['whatsapp']['state']??'')!=='pending')return null;
   if(!opportunity_token($id,(string)($x['link']['token']??''))||!in_array($x['link']['purpose'],['approval','clarification'],true)||empty($x['contact']['whatsapp_optin'])||empty($x['auto_whatsapp'])){$x['whatsapp']['state']='cancelled';opportunity_persist($x);return null;}
   if(!$transport&&!opportunity_delivery_ready($c)){$x['whatsapp']['state']='blocked';$x['whatsapp']['error']='provider_not_configured';opportunity_persist($x);return null;}
   $x['whatsapp']['state']='sending';$x['whatsapp']['started']=time();$x['whatsapp']['sender_id']=$c['sender_id'];$x['whatsapp']['config_revision']=$c['revision']??0;opportunity_persist($x);return $x;
  });if(!$claim)return 'skipped';$provider='';$error='';$state='unknown';
  try{$provider=$transport?$transport($claim,$c):opportunity_whatsapp_send($claim,$c);if(!is_string($provider)||$provider==='')throw new RuntimeException('Unconfirmed provider result.');$state='accepted';}
  catch(Throwable $e){$definite=$e instanceof PublishingRemoteException&&!$e->ambiguous&&$e->httpStatus>=400&&$e->httpStatus<500;$state=$definite?'blocked':'unknown';$error=$definite?'provider_rejected':'acceptance_unconfirmed';}
  opportunity_atomic(static function()use($id,$claim,$provider,$state,$error){$x=opportunity_record($id);$key=$claim['whatsapp']['id'];$job=$claim['whatsapp'];$job['state']=$state;$job['error']=$error;if($provider!==''){$job['provider_id']=$provider;$job['accepted_at']=time();comm_put('opportunity.receipt.'.hash('sha256',$provider),['opportunity'=>$id,'job'=>$key,'sender_id'=>$job['sender_id'],'recipient'=>ltrim($claim['contact']['phone'],'+')]);}
   if(($x['whatsapp']['id']??'')===$key)$x['whatsapp']=$job;else{$x['whatsapp_history'][]=$job;$x['whatsapp_history']=array_slice($x['whatsapp_history'],-20);}opportunity_persist($x);
  });return $state;
 });
}
function opportunity_email_gate(string $key,array $payload,int $outbox): bool {
 if(!preg_match('/^opportunity:([a-f0-9]{32}):([1-9][0-9]*)$/D',$key,$m))return false;try{$r=opportunity_record($m[1]);}catch(OpportunityException){return false;}
 return $r['version']===(int)$m[2]&&($r['notification']['outbox_id']??0)===$outbox&&hash_equals($r['contact']['email'],(string)($payload['to']??''))&&opportunity_token($r['id'],(string)($r['link']['token']??''))!==null;
}
function opportunity_automation_work(): array {
 $stats=['published'=>0,'whatsapp'=>0];$ids=[];$due=0;foreach(opportunity_index()as$i){if($i['state']==='scheduled'&&($i['schedule']['at']??PHP_INT_MAX)<=time()&&$due++<20)if(opportunity_publish_due($i['id']))$stats['published']++;$r=opportunity_record($i['id']);if(!empty($r['notification']['outbox_id']))$ids[]=$r['notification']['outbox_id'];if($stats['whatsapp']<10&&in_array($r['whatsapp']['state']??'',['pending','sending'],true)){opportunity_whatsapp_work($i['id']);$stats['whatsapp']++;}}
 opportunity_receipt_reconcile();
 if($ids)$stats['email']=email_work(20,null,$ids);comm_put('opportunity.worker',['at'=>time(),'stats'=>$stats]);return $stats;
}

function opportunity_webhook_signature(string $body,string $signature,string $secret): bool {return strlen($secret)>=16&&preg_match('/^sha256=[a-f0-9]{64}$/D',$signature)===1&&hash_equals('sha256='.hash_hmac('sha256',$body,$secret),$signature);}
/** Only known outgoing status events are retained; inbound messages and contacts are ignored. */
function opportunity_webhook_apply(array $data): int {
 if(($data['object']??'')!=='whatsapp_business_account'||!is_array($data['entry']??null))return 0;$count=0;
 foreach(array_slice($data['entry'],0,20)as$entry)foreach(array_slice(is_array($entry['changes']??null)?$entry['changes']:[],0,20)as$change){$v=is_array($change['value']??null)?$change['value']:[];foreach(array_slice(is_array($v['statuses']??null)?$v['statuses']:[],0,100)as$s){if(!is_array($s)||!is_string($s['id']??null)||strlen($s['id'])>255||!in_array($s['status']??'', ['sent','delivered','read','failed'],true)||!is_scalar($s['timestamp']??null)||!ctype_digit((string)$s['timestamp'])||!is_string($s['recipient_id']??null)||!is_string($v['metadata']['phone_number_id']??null))continue;
   $map=comm_get('opportunity.receipt.'.hash('sha256',$s['id']));if(!$map){opportunity_receipt_buffer($s,$v['metadata']['phone_number_id']);continue;}if(($map['sender_id']??'')!==$v['metadata']['phone_number_id']||($map['recipient']??'')!==$s['recipient_id'])continue;
   opportunity_atomic(static function()use($map,$s,&$count){$r=opportunity_record($map['opportunity']);$current=($r['whatsapp']['id']??'')===$map['job'];$job=null;$where=null;if($current)$job=$r['whatsapp'];else foreach($r['whatsapp_history']??[]as$k=>$old)if($old['id']===$map['job']){$job=$old;$where=$k;break;}if(!$job||($job['provider_id']??'')!==$s['id'])return;
    $at=(int)$s['timestamp'];if($at<($job['created']??0)-300||$at>time()+300)return;$event=$s['status'].':'.$at;if(in_array($event,$job['events']??[],true))return;$job['events']=array_slice([...($job['events']??[]),$event],-30);$rank=['accepted'=>0,'sent'=>1,'delivered'=>2,'read'=>3];$prior=$job['state'];
    if($s['status']==='failed'){if(!in_array($prior,['delivered','read'],true)){$job['state']='failed';$job['error']='provider_reported_failure';}}elseif(!($prior==='failed'&&$s['status']==='sent')&&($rank[$s['status']]??0)>($rank[$prior]??0)){$job['state']=$s['status'];$job[$s['status'].'_at']=$at;$job['error']='';}
    if($current)$r['whatsapp']=$job;elseif($where!==null)$r['whatsapp_history'][$where]=$job;opportunity_persist($r);$count++;
   });
  }}return $count;
}
/** A receipt can arrive before the send request returns. Buffer only a known in-flight recipient. */
function opportunity_receipt_buffer(array $s,string $sender): void {
 $known=false;foreach(opportunity_index()as$i){$r=opportunity_record($i['id']);foreach(array_merge(isset($r['whatsapp'])?[$r['whatsapp']]:[],$r['whatsapp_history']??[])as$j)if(in_array($j['state']??'',['sending','unknown'],true)&&($j['sender_id']??'')===$sender&&ltrim($r['contact']['phone']??'','+')===$s['recipient_id']){$known=true;break 2;}}if(!$known)return;
 opportunity_atomic(static function()use($s,$sender){$pending=comm_get('opportunity.receipt_pending')['items']??[];$pending=array_filter($pending,static fn($r)=>$r['at']>time()-3600);$key=hash('sha256',$s['id'].'|'.$s['status'].'|'.$s['timestamp']);if(count($pending)>=100&&!isset($pending[$key]))return;$pending[$key]=['at'=>time(),'sender'=>$sender,'status'=>array_intersect_key($s,array_flip(['id','status','timestamp','recipient_id']))];comm_put('opportunity.receipt_pending',['items'=>$pending]);});
}
function opportunity_receipt_reconcile(): void {
 $pending=comm_get('opportunity.receipt_pending')['items']??[];foreach($pending as$key=>$event){$known=comm_get('opportunity.receipt.'.hash('sha256',$event['status']['id']));if($known)opportunity_webhook_apply(['object'=>'whatsapp_business_account','entry'=>[['changes'=>[['value'=>['metadata'=>['phone_number_id'=>$event['sender']],'statuses'=>[$event['status']]]]]]]]);if($known||$event['at']<=time()-3600)opportunity_atomic(static function()use($key){$latest=comm_get('opportunity.receipt_pending')['items']??[];unset($latest[$key]);comm_put('opportunity.receipt_pending',['items'=>$latest]);});}
}
function opportunity_webhook_dispatch(string $path): bool {
 if($path!=='/webhooks/whatsapp/opportunities')return false;private_response_headers();$c=opportunity_delivery_config();$method=$_SERVER['REQUEST_METHOD']??'GET';
 if($method==='GET'){$mode=$_GET['hub_mode']??'';$token=$_GET['hub_verify_token']??'';$challenge=$_GET['hub_challenge']??'';if($mode==='subscribe'&&is_string($token)&&strlen((string)$c['verify_token'])>=24&&hash_equals($c['verify_token'],$token)&&is_string($challenge)&&preg_match('/^[0-9]{1,100}$/D',$challenge)){header('Content-Type: text/plain');echo $challenge;}else http_response_code(403);return true;}
 if($method!=='POST'){http_response_code(405);header('Allow: GET, POST');return true;}$body=file_get_contents('php://input',false,null,0,262145);if(!is_string($body)||strlen($body)>262144){http_response_code(413);return true;}
 if(!opportunity_webhook_signature($body,(string)($_SERVER['HTTP_X_HUB_SIGNATURE_256']??''),(string)$c['app_secret'])){http_response_code(403);return true;}
 try{$data=json_decode($body,true,32,JSON_THROW_ON_ERROR);if(!is_array($data))throw new InvalidArgumentException();opportunity_webhook_apply($data);header('Content-Type: application/json');echo '{"received":true}';}catch(JsonException|InvalidArgumentException){http_response_code(400);}return true;
}
