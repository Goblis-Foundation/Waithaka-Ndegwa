<?php
declare(strict_types=1);

/** Owner-reviewed, one-use Microsoft actions. No worker or automatic mutation retry. */
function mwa_bundles(): array {return ['drafts'=>['name'=>'Outlook drafts','scope'=>'Mail.ReadWrite'],'calendar'=>['name'=>'Calendar changes','scope'=>'Calendars.ReadWrite']];}
function mwa_types(): array {return ['email_draft'=>'Create an Outlook draft','calendar_create'=>'Create a single meeting','calendar_reschedule'=>'Reschedule a single meeting','calendar_cancel'=>'Cancel a single meeting'];}
function mwa_purpose(string $kind): string {if(!isset(mwa_types()[$kind]))throw new MicrosoftWorkspaceException('Choose a supported Microsoft action.');return $kind==='email_draft'?'drafts':'calendar';}
function mwa_grant_key(string $slot,string $purpose): string {ms_key($slot);if(!isset(mwa_bundles()[$purpose]))throw new MicrosoftWorkspaceException('Choose draft or calendar access.');return 'microsoft.actions.grant.'.$slot.'.'.$purpose;}
function mwa_grant(string $slot,string $purpose,int $uid): array {$r=comm_get(mwa_grant_key($slot,$purpose));return (int)($r['owner']??0)===$uid?$r:[];}
function mwa_grant_fingerprint(array $r): string {$scopes=array_map(static fn($s)=>strtolower(preg_replace('~^https://graph\.microsoft\.com/~i','',(string)$s)),$r['scopes']??[]);sort($scopes);return hash('sha256',publishing_json([$r['identity']['id']??'', $r['verified_at']??0,$scopes]));}
function mwa_authorize(string $slot,string $purpose,int $uid): string {
 $r=ms_record($slot,$uid);if(($r['status']??'')!=='connected')throw new MicrosoftWorkspaceException('Confirm read access for this account first.');$d=mwa_bundles()[$purpose]??throw new MicrosoftWorkspaceException('Choose a supported permission.');
 $client=publishing_client('microsoft');meetings_validate_client('microsoft',$client);if($client['client_id']===''||$client['client_secret']==='')throw new MicrosoftWorkspaceException('Save Microsoft app setup first.');
 $state=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(64)),'+/','-_'),'=');
 $_SESSION['ms_workspace_oauth']=['state_hash'=>hash('sha256',$state),'verifier'=>$verifier,'slot'=>$slot,'purpose'=>$purpose,'uid'=>$uid,'expires'=>time()+600,'revision'=>ms_revision(mwa_grant($slot,$purpose,$uid)),'identity_id'=>$r['identity']['id']];
 return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query(['client_id'=>$client['client_id'],'response_type'=>'code','redirect_uri'=>ms_callback(),'response_mode'=>'query','state'=>$state,'scope'=>implode(' ',['User.Read','offline_access',$d['scope']]),'prompt'=>'select_account','login_hint'=>ms_accounts()[$slot],'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'],'','&',PHP_QUERY_RFC3986);
}
function mwa_complete(array $flow,string $code,int $uid): void {
 $purpose=$flow['purpose'];$d=mwa_bundles()[$purpose]??throw new MicrosoftWorkspaceException('Unknown Microsoft permission.');
 $token=meetings_token_request('microsoft',['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>ms_callback(),'code_verifier'=>$flow['verifier']]);$scopes=ms_granted($token);
 if(!ms_has_scope($scopes,'User.Read')||!ms_has_scope($scopes,$d['scope']))throw new MicrosoftWorkspaceException('The required Microsoft permission was not granted.');
 $who=publishing_http('GET','https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName',publishing_bearer($token['access_token']))['data'];$identity=ms_identity($who,$flow['slot']);
 if(!hash_equals((string)$flow['identity_id'],$identity['id']))throw new MicrosoftWorkspaceException('Microsoft returned a different underlying account. Existing read access was retained.');
 comm_lock('mwa-grant-'.$uid.'-'.$flow['slot'].'-'.$purpose,static function()use($flow,$uid,$purpose,$identity,$token,$scopes){$old=mwa_grant($flow['slot'],$purpose,$uid);$read=ms_record($flow['slot'],$uid);
  if(($read['status']??'')!=='connected'||!hash_equals((string)($read['identity']['id']??''),$identity['id'])||!hash_equals((string)$flow['revision'],ms_revision($old)))throw new MicrosoftWorkspaceException('This account changed during authorization. Start again.');
  comm_put(mwa_grant_key($flow['slot'],$purpose),['owner'=>$uid,'identity'=>$identity,'token'=>$token,'scopes'=>$scopes,'status'=>'pending_confirmation','verified_at'=>time()]);
 });
}
function mwa_ready(string $slot,string $purpose,int $uid): array {
 $r=mwa_grant($slot,$purpose,$uid);$read=ms_record($slot,$uid);$d=mwa_bundles()[$purpose];
 if(($r['status']??'')!=='connected'||($read['status']??'')!=='connected'||!hash_equals((string)($read['identity']['id']??''),(string)($r['identity']['id']??''))||!ms_has_scope($r['scopes']??[],$d['scope']))throw new MicrosoftWorkspaceException('Connect and confirm '.$d['name'].' access for this account first.');return $r;
}
function mwa_token(string $slot,string $purpose,int $uid): string {
 return comm_lock('mwa-grant-'.$uid.'-'.$slot.'-'.$purpose,static function()use($slot,$purpose,$uid){$r=mwa_ready($slot,$purpose,$uid);$t=$r['token'];
  if((int)($t['expires_at']??0)<=time()+120){if(empty($t['refresh_token']))throw new MicrosoftWorkspaceException('Reconnect this Microsoft action permission.');$u=meetings_token_request('microsoft',['grant_type'=>'refresh_token','refresh_token'=>$t['refresh_token']]);$r['scopes']=ms_granted($u);if(!ms_has_scope($r['scopes'],mwa_bundles()[$purpose]['scope']))throw new MicrosoftWorkspaceException('The action permission is no longer granted.');$t=array_merge($t,$u);$r['token']=$t;comm_put(mwa_grant_key($slot,$purpose),$r);}
  if(!is_string($t['access_token']??null)||$t['access_token']==='')throw new MicrosoftWorkspaceException('Reconnect this Microsoft action permission.');return $t['access_token'];
 });
}
function mwa_text(mixed $v,int $max,bool $required=false): string {if(!is_string($v)||mb_strlen($v)>$max||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$v))throw new MicrosoftWorkspaceException('Use valid text within the field limit.');$v=trim($v);if($required&&$v==='')throw new MicrosoftWorkspaceException('Complete the required fields.');return $v;}
function mwa_emails(mixed $v): array {
 $v=mwa_text($v,3000);$items=preg_split('/[,;\n]+/',$v,-1,PREG_SPLIT_NO_EMPTY)?:[];$out=[];if(count($items)>20)throw new MicrosoftWorkspaceException('Use at most 20 email addresses.');
 foreach($items as$email){$email=trim($email);if(strlen($email)>254||preg_match('/[\r\n]/',$email)||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new MicrosoftWorkspaceException('Enter valid email addresses separated by commas.');$out[strtolower($email)]=$email;}return array_values($out);
}
function mwa_time(mixed $v,int $now): int {
 if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$v))throw new MicrosoftWorkspaceException('Choose a valid Nairobi date and time.');$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$v,new DateTimeZone('Africa/Nairobi'));
 if(!$d||$d->format('Y-m-d\TH:i')!==$v||$d->getTimestamp()<=$now||$d->getTimestamp()>$now+366*86400)throw new MicrosoftWorkspaceException('Choose a future time within the next year.');return $d->getTimestamp();
}
function mwa_fields(array $in,int $now): array {
 $kind=mwa_text($in['kind']??'',40,true);mwa_purpose($kind);$slot=mwa_text($in['account']??'',30,true);ms_key($slot);$f=['kind'=>$kind,'account'=>$slot];
 if(in_array($kind,['email_draft','calendar_create'],true)){$f['subject']=mwa_text($in['subject']??'',200,true);if(preg_match('/[\r\n]/',$f['subject']))throw new MicrosoftWorkspaceException('Use a single-line subject.');$f['body']=mwa_text($in['body']??'',12000,true);$f['recipients']=mwa_emails($in['recipients']??'');}
 if($kind==='email_draft'){$f['recipient_name']=mwa_text($in['recipient_name']??'',120);}
 if(in_array($kind,['calendar_create','calendar_reschedule'],true)){$f['start']=mwa_time($in['start']??null,$now);$f['end']=mwa_time($in['end']??null,$now);if($f['end']-$f['start']<300||$f['end']-$f['start']>86400)throw new MicrosoftWorkspaceException('Meetings must last between 5 minutes and 24 hours.');}
 if($kind==='calendar_create'){$f['location']=mwa_text($in['location']??'',300);$f['teams']=($in['teams']??'')==='1';if($f['teams']&&$slot!=='group')throw new MicrosoftWorkspaceException('Teams meeting creation requires the organisation account.');$minutes=filter_var($in['reminder']??15,FILTER_VALIDATE_INT);if(!in_array($minutes,[5,10,15,30,60],true))throw new MicrosoftWorkspaceException('Choose an available reminder.');$f['reminder']=$minutes;}
 if(in_array($kind,['calendar_reschedule','calendar_cancel'],true)){$f['event_id']=mwa_text($in['event_id']??'',2000,true);if(preg_match('/[\x00-\x20\x7f]/',$f['event_id']))throw new MicrosoftWorkspaceException('Choose an existing meeting.');$f['reason']=mwa_text($in['reason']??'',2000,true);}
 return $f;
}
function mwa_event(array $e): array {
 if(empty($e['id'])||!is_string($e['id'])||($e['isOrganizer']??false)!==true||!empty($e['isCancelled'])||($e['type']??'')!=='singleInstance')throw new MicrosoftWorkspaceException('Choose an active single meeting you organise. Recurring meetings are managed in Outlook.');
 $etag=$e['@odata.etag']??'';$change=$e['changeKey']??'';if(!is_string($etag)||$etag===''||strlen($etag)>1000||preg_match('/[\r\n]/',$etag)||!is_string($change)||$change==='')throw new MicrosoftWorkspaceException('Microsoft did not return the meeting revision.');
 $times=ms_busy_rows(['value'=>[['start'=>$e['start']??[],'end'=>$e['end']??[]]]]);$body=$e['body']??[];
 if(!is_string($body['content']??null)||strlen($body['content'])>160000||!in_array(strtolower((string)($body['contentType']??'')),['text','html'],true))throw new MicrosoftWorkspaceException('This meeting body cannot be preserved safely. Change it in Outlook.');
 $attendees=[];foreach($e['attendees']??[]as$a){$email=$a['emailAddress']['address']??null;if(!is_string($email)||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new MicrosoftWorkspaceException('A meeting attendee could not be verified.');$attendees[]=strtolower($email);}sort($attendees);
 $join='';if(is_string($e['onlineMeeting']['joinUrl']??null)){try{$join=meetings_link($e['onlineMeeting']['joinUrl'],'microsoft');}catch(InvalidArgumentException){}}
 return ['id'=>$e['id'],'subject'=>mwa_text($e['subject']??'',500),'start'=>$times[0][0],'end'=>$times[0][1],'etag'=>$etag,'change_key'=>$change,'body'=>$body,'attendees'=>$attendees,'is_online'=>($e['isOnlineMeeting']??false)===true,'online_provider'=>(string)($e['onlineMeetingProvider']??''),'join'=>$join];
}
function mwa_remote_event(string $slot,string $id,int $uid,?string $token=null,?callable $http=null): array {
 $http??='publishing_http';$token??=ms_token($slot,$uid);$url='https://graph.microsoft.com/v1.0/me/events/'.rawurlencode($id).'?'.http_build_query(['$select'=>'id,subject,start,end,body,attendees,isOrganizer,isCancelled,type,changeKey,isOnlineMeeting,onlineMeetingProvider,onlineMeeting'],'','&',PHP_QUERY_RFC3986);
 return mwa_event($http('GET',$url,array_merge(publishing_bearer($token),['Prefer: outlook.timezone="UTC"']))['data']);
}
function mwa_choices(string $slot,int $uid): array {
 ms_key($slot);$q=ms_query('calendar');$url='https://graph.microsoft.com/v1.0/me/calendarView?'.http_build_query(['startDateTime'=>gmdate('Y-m-d\TH:i:s\Z'),'endDateTime'=>gmdate('Y-m-d\TH:i:s\Z',time()+31*86400),'$top'=>50,'$select'=>'id,subject,start,isOrganizer,type,isCancelled'],'','&',PHP_QUERY_RFC3986);
 $data=publishing_http('GET',$url,array_merge(publishing_bearer(ms_token($slot,$uid)),['Prefer: outlook.timezone="UTC"']))['data'];if(!is_array($data['value']??null))throw new MicrosoftWorkspaceException('Microsoft did not return a meeting list.');$out=[];
 foreach(array_slice($data['value'],0,50)as$e)if(($e['isOrganizer']??false)===true&&($e['type']??'')==='singleInstance'&&empty($e['isCancelled'])&&is_string($e['id']??null))$out[]=['id'=>$e['id'],'label'=>mb_substr((string)($e['subject']??'Meeting'),0,200).' · '.ms_value($e['start']??null)];return $out;
}
function mwa_id(string $id): string {if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new MicrosoftWorkspaceException('This action was not found.');return $id;}
function mwa_owned(string $id,int $uid): array {$r=comm_get('microsoft.action.'.mwa_id($id));if(!$r||(int)($r['owner']??0)!==$uid)throw new MicrosoftWorkspaceException('This action was not found.');return $r;}
function mwa_summary(array $r): array {return ['id'=>$r['id'],'owner'=>$r['owner'],'created_at'=>$r['created_at'],'state'=>$r['state'],'version'=>$r['version'],'fields'=>['kind'=>$r['fields']['kind'],'account'=>$r['fields']['account'],'subject'=>$r['fields']['subject']??$r['snapshot']['subject']??'Meeting']];}
function mwa_records(int $uid): array {return array_values(array_filter(comm_get('microsoft.action_index.'.$uid),static fn($r)=>is_array($r)&&(int)($r['owner']??0)===$uid));}
function mwa_index(array $r): void {comm_lock('mwa-catalog-'.$r['owner'],static function()use($r){$index=comm_get('microsoft.action_index.'.$r['owner']);$index[$r['id']]=mwa_summary($r);comm_put('microsoft.action_index.'.$r['owner'],$index);});}
function mwa_digest(array $r): string {return hash('sha256',publishing_json([$r['fields'],$r['snapshot']??null,$r['identity_id'],$r['payload']??null]));}
function mwa_prepare(array $in,int $uid,?callable $eventLoader=null): string {
 $f=mwa_fields($in,time());$read=ms_record($f['account'],$uid);if(($read['status']??'')!=='connected')throw new MicrosoftWorkspaceException('Confirm the selected Microsoft account first.');$snapshot=null;
 if(isset($f['event_id'])){$snapshot=$eventLoader?$eventLoader($f['account'],$f['event_id'],$uid):mwa_remote_event($f['account'],$f['event_id'],$uid);if($snapshot['start']<=time())throw new MicrosoftWorkspaceException('Choose a future meeting.');}
 $id=bin2hex(random_bytes(16));comm_lock('mwa-catalog-'.$uid,static function()use($id,$f,$uid,$read,$snapshot){$index=comm_get('microsoft.action_index.'.$uid);if(count($index)>=500)throw new MicrosoftWorkspaceException('This workspace has reached its retained-action capacity.');$r=['id'=>$id,'owner'=>$uid,'fields'=>$f,'snapshot'=>$snapshot,'identity_id'=>$read['identity']['id'],'state'=>'draft','version'=>1,'created_at'=>time(),'audit'=>[['event'=>'prepared','actor'=>$uid,'at'=>time()]]];$r['payload']=mwa_payload($r);comm_put('microsoft.action.'.$id,$r);$index[$id]=mwa_summary($r);comm_put('microsoft.action_index.'.$uid,$index);});return $id;
}
function mwa_version(array $r,int $version): void {if((int)$r['version']!==$version)throw new MicrosoftWorkspaceException('This action changed. Reload and review its latest state.');}
function mwa_update(array $r,string $state): array {$r['state']=$state;$r['version']++;$r['audit'][]=['event'=>$state,'actor'=>(int)$r['owner'],'at'=>time()];$r['updated_at']=time();comm_put('microsoft.action.'.$r['id'],$r);mwa_index($r);return $r;}
function mwa_review(string $id,int $uid,int $version): void {comm_lock('mwa-'.$id,static function()use($id,$uid,$version){$r=mwa_owned($id,$uid);mwa_version($r,$version);if(!in_array($r['state'],['draft','reviewed'],true))throw new MicrosoftWorkspaceException('Only a prepared action can be reviewed.');$f=$r['fields'];$g=mwa_ready($f['account'],mwa_purpose($f['kind']),$uid);if(!hash_equals($r['identity_id'],$g['identity']['id']))throw new MicrosoftWorkspaceException('The connected identity changed. Prepare this action again.');$r['approval_hash']=mwa_digest($r);$r['grant_fingerprint']=mwa_grant_fingerprint($g);$r['reviewed_at']=time();mwa_update($r,'reviewed');});}
function mwa_normal_text(string $html): string {
 $html=preg_replace('~<(?:br|/p|/h[1-6]|/div|/tr|/td|/li)\b[^>]*>~i','$0 ',$html)??$html;
 $dom=new DOMDocument();if(!$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))return '';
 $xpath=new DOMXPath($dom);$remove=$xpath->query('//head|//style|//script|//*[contains(translate(@style,"ABCDEFGHIJKLMNOPQRSTUVWXYZ ","abcdefghijklmnopqrstuvwxyz"),"display:none")]');
 if($remove)foreach(iterator_to_array($remove)as$node)$node->parentNode?->removeChild($node);
 return trim(preg_replace('/\s+/u',' ',str_replace("\xc2\xa0",' ',$dom->textContent))??'');
}
function mwa_body_text(array $body): string {return strtolower((string)($body['contentType']??''))==='html'?mwa_normal_text((string)($body['content']??'')):trim(preg_replace('/\s+/u',' ',(string)($body['content']??''))??'');}
function mwa_payload(array $r): array {
 $f=$r['fields'];$kind=$f['kind'];
 if($kind==='email_draft')return ['subject'=>$f['subject'],'body'=>['contentType'=>'HTML','content'=>email_design($f['subject'],'<p>'.nl2br(e($f['body']),false).'</p>',['name'=>$f['recipient_name']])],'toRecipients'=>array_map(static fn($email)=>['emailAddress'=>['address'=>$email]],$f['recipients']),'internetMessageHeaders'=>[['name'=>'x-waithaka-action','value'=>$r['id']]]];
 if($kind==='calendar_cancel')return ['comment'=>$f['reason']];
 $times=['start'=>['dateTime'=>gmdate('Y-m-d\TH:i:s',$f['start']),'timeZone'=>'UTC'],'end'=>['dateTime'=>gmdate('Y-m-d\TH:i:s',$f['end']),'timeZone'=>'UTC']];
 if($kind==='calendar_reschedule'){$body=$r['snapshot']['body'];$body['content'].=strtolower($body['contentType'])==='html'?'<p>Schedule update: '.e($f['reason']).'</p>':"\n\nSchedule update: ".$f['reason'];return $times+['body'=>$body];}
 return $times+['subject'=>$f['subject'],'body'=>['contentType'=>'Text','content'=>$f['body']],'attendees'=>array_map(static fn($email)=>['emailAddress'=>['address'=>$email],'type'=>'required'],$f['recipients']),'location'=>['displayName'=>$f['location']],'isReminderOn'=>true,'reminderMinutesBeforeStart'=>$f['reminder'],'isOnlineMeeting'=>$f['teams'],'transactionId'=>$r['id']]+($f['teams']?['onlineMeetingProvider'=>'teamsForBusiness']:[]);
}
function mwa_verify_draft(array $data,array $payload,string $id): void {
 $actual=[];foreach($data['toRecipients']??[]as$to)$actual[]=strtolower((string)($to['emailAddress']['address']??''));$expected=array_map(static fn($to)=>strtolower($to['emailAddress']['address']),$payload['toRecipients']);sort($actual);sort($expected);
 if(($data['id']??'')!==$id||($data['isDraft']??false)!==true||($data['subject']??'')!==$payload['subject']||$actual!==$expected||mwa_body_text($data['body']??[])!==mwa_body_text($payload['body']))throw new MicrosoftWorkspaceException('The native draft could not be independently verified. Check Outlook before doing anything further.');
}
function mwa_execute(string $id,int $uid,int $version,?callable $http=null,?callable $tokenProvider=null): array {
 return comm_lock('mwa-'.$id,static function()use($id,$uid,$version,$http,$tokenProvider){
  $r=mwa_owned($id,$uid);mwa_version($r,$version);if($r['state']!=='reviewed'||time()-(int)($r['reviewed_at']??0)>1800||!hash_equals((string)($r['approval_hash']??''),mwa_digest($r)))throw new MicrosoftWorkspaceException('Review this exact action again before applying it.');
  $f=$r['fields'];$purpose=mwa_purpose($f['kind']);$g=mwa_ready($f['account'],$purpose,$uid);if(!hash_equals($r['identity_id'],$g['identity']['id'])||!hash_equals((string)$r['grant_fingerprint'],mwa_grant_fingerprint($g)))throw new MicrosoftWorkspaceException('The action permission changed. Prepare and review again.');
  $http??='publishing_http';$token=$tokenProvider?$tokenProvider($f['account'],$purpose,$uid):mwa_token($f['account'],$purpose,$uid);$headers=array_merge(publishing_bearer($token),['Prefer: outlook.timezone="UTC"']);$payload=$r['payload'];
  if(isset($f['start'])&&$f['start']<=time())throw new MicrosoftWorkspaceException('This meeting time is no longer in the future. Prepare it again.');
  if(isset($f['event_id'])){$current=mwa_remote_event($f['account'],$f['event_id'],$uid,$token,$http);if(!hash_equals($r['snapshot']['change_key'],$current['change_key'])||$current['attendees']!==$r['snapshot']['attendees']||$current['start']!==$r['snapshot']['start']||$current['end']!==$r['snapshot']['end']){return mwa_update($r,'stale');}$headers[]='If-Match: '.$r['snapshot']['etag'];}
  if(!empty($f['teams'])){$cal=$http('GET','https://graph.microsoft.com/v1.0/me/calendar?$select=allowedOnlineMeetingProviders',$headers)['data'];if(!in_array('teamsForBusiness',$cal['allowedOnlineMeetingProviders']??[],true))throw new MicrosoftWorkspaceException('This calendar does not offer Teams meeting creation. Prepare a calendar-only meeting instead.');}
  return comm_lock('ms365-'.$uid.'-'.$f['account'],static function()use($r,$f,$uid,$purpose,$http,$token,$headers,$payload,$id){return comm_lock('mwa-grant-'.$uid.'-'.$f['account'].'-'.$purpose,static function()use($r,$f,$uid,$purpose,$http,$token,$headers,$payload,$id){
  $currentGrant=mwa_ready($f['account'],$purpose,$uid);if(!hash_equals($r['grant_fingerprint'],mwa_grant_fingerprint($currentGrant)))throw new MicrosoftWorkspaceException('The permission changed. Review this action again.');
  $r=mwa_update($r,'executing');$mutationReturned=false;
  try{
   $root='https://graph.microsoft.com/v1.0/me/';$kind=$f['kind'];$method=$kind==='calendar_reschedule'?'PATCH':'POST';$endpoint=$kind==='email_draft'?$root.'messages':($kind==='calendar_create'?$root.'events':$root.'events/'.rawurlencode($f['event_id']).($kind==='calendar_cancel'?'/cancel':''));
   $response=$http($method,$endpoint,$headers,$payload,false,true);$mutationReturned=true;$r['provider_http']=(int)($response['status']??0);
   if($kind==='calendar_cancel'){if(!in_array($r['provider_http'],[200,202,204],true))throw new MicrosoftWorkspaceException('Microsoft did not return a cancellation acceptance.');$r['remote_id']=$f['event_id'];$r['outcome']='Microsoft accepted the cancellation request. Delivery to attendees is not independently verified.';return mwa_update($r,'accepted');}
   $remote=$response['data']['id']??'';if(!is_string($remote)||$remote==='')throw new MicrosoftWorkspaceException('Microsoft did not return the created record identity.');$r['remote_id']=$remote;comm_put('microsoft.action.'.$id,$r);
   if($kind==='email_draft'){$data=$http('GET',$root.'messages/'.rawurlencode($remote).'?'.http_build_query(['$select'=>'id,isDraft,subject,body,toRecipients'],'','&',PHP_QUERY_RFC3986),$headers)['data'];mwa_verify_draft($data,$payload,$remote);$r['outcome']='Native Outlook draft created and independently verified. Finish and send it in Outlook.';}
   else{$data=mwa_remote_event($f['account'],$remote,$uid,$token,$http);$expectedSubject=$kind==='calendar_create'?$f['subject']:$r['snapshot']['subject'];$attendees=$kind==='calendar_create'?array_map('strtolower',$f['recipients']):$r['snapshot']['attendees'];sort($attendees);if($data['start']!==$f['start']||$data['end']!==$f['end']||$data['subject']!==$expectedSubject||$data['attendees']!==$attendees||mwa_body_text($data['body'])!==mwa_body_text($payload['body'])||(!empty($f['teams'])&&(!$data['is_online']||$data['online_provider']!=='teamsForBusiness')))throw new MicrosoftWorkspaceException('The calendar change could not be independently verified. Check Outlook.');$r['join']=$data['join'];$r['outcome']='Calendar change independently verified. Invitation or update delivery to attendees is not independently verified.'.(!empty($f['teams'])&&$data['join']===''?' The Teams link is still pending; check Outlook.':'');}
   return mwa_update($r,'done');
  }catch(Throwable $e){$uncertain=$mutationReturned||!($e instanceof PublishingRemoteException)||$e->ambiguous||$e->httpStatus===0||$e->httpStatus>=500;$r['outcome']=$uncertain?'Outcome unknown. Check Outlook before preparing any replacement; this action will not retry.':'Microsoft rejected this action (HTTP '.$e->httpStatus.'). Correct the issue and prepare a new action.';return mwa_update($r,$uncertain?'unknown':'failed');}
  });});
 });
}
function mwa_dispatch(string $path): bool {
 if(!preg_match('~^/write/microsoft/actions(?:/(new|[a-f0-9]{32}))?$~D',$path,$m))return false;require_auth();private_response_headers();$uid=(int)current_user()['id'];if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}journeys_method(['GET','HEAD','POST']);$part=$m[1]??'';$error='';$record=null;$events=[];$values=['kind'=>'email_draft','account'=>'personal'];
 try{
  if($part!==''&&$part!=='new')$record=mwa_owned($part,$uid);
  if(is_post()){
   verify_csrf();if(!journeys_rate_limit('microsoft-actions',(string)$uid,30,300))throw new MicrosoftWorkspaceException('Please wait before another Microsoft action.');$a=is_string($_POST['action']??null)?$_POST['action']:'';$slot=is_string($_POST['account']??null)?$_POST['account']:'personal';$purpose=is_string($_POST['purpose']??null)?$_POST['purpose']:'drafts';
   if($a==='connect')redirect(mwa_authorize($slot,$purpose,$uid));
   elseif(in_array($a,['confirm_grant','disconnect_grant'],true)){comm_lock('mwa-grant-'.$uid.'-'.$slot.'-'.$purpose,static function()use($slot,$purpose,$uid,$a){$g=mwa_grant($slot,$purpose,$uid);if(!is_string($_POST['revision']??null)||!hash_equals(ms_revision($g),$_POST['revision']))throw new MicrosoftWorkspaceException('This permission changed. Reload and review it.');if($a==='confirm_grant'){if(($g['status']??'')!=='pending_confirmation'||($_POST['confirm_identity']??'')!=='1')throw new MicrosoftWorkspaceException('Confirm the returned Microsoft identity.');$read=ms_record($slot,$uid);if(($read['status']??'')!=='connected'||!hash_equals((string)($read['identity']['id']??''),(string)($g['identity']['id']??'')))throw new MicrosoftWorkspaceException('The read identity changed. Reconnect this permission.');$g['status']='connected';}else $g=['owner'=>$uid,'status'=>'disconnected'];comm_put(mwa_grant_key($slot,$purpose),$g);});flash('success','Microsoft action permission updated. Read connections are unchanged.');redirect(url('/write/microsoft/actions'));}
   elseif($a==='events'){$values=array_filter($_POST,'is_string');$events=mwa_choices($slot,$uid);}
   elseif($a==='prepare'){$id=mwa_prepare($_POST,$uid);redirect(url('/write/microsoft/actions/'.$id));}
   elseif(in_array($a,['review','apply','discard'],true)&&$record){$version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT);if($version===false)throw new MicrosoftWorkspaceException('Reload this action.');if($a==='review'){if(($_POST['acknowledge']??'')!=='1')throw new MicrosoftWorkspaceException('Confirm the account, recipients and exact change.');mwa_review($part,$uid,$version);}elseif($a==='apply'){if(($_POST['apply_confirm']??'')!=='1')throw new MicrosoftWorkspaceException('Confirm applying this reviewed action to Microsoft.');mwa_execute($part,$uid,$version);}else comm_lock('mwa-'.$part,static function()use($part,$uid,$version){$r=mwa_owned($part,$uid);mwa_version($r,$version);if(!in_array($r['state'],['draft','reviewed'],true))throw new MicrosoftWorkspaceException('Applied actions are retained as an audit record.');mwa_update($r,'discarded');});redirect(url('/write/microsoft/actions/'.$part));}
   else throw new MicrosoftWorkspaceException('Choose a supported action.');
  }
 }catch(MicrosoftWorkspaceException $e){$error=$e->getMessage();if($part==='new')$values=array_filter($_POST,'is_string')?:$values;if(!$record&&$part!==''&&$part!=='new')http_response_code(404);}
 catch(Throwable $e){$error='This request could not be completed. No automatic retry was scheduled. Check the saved action state before continuing.';error_log('Microsoft action failure: '.get_class($e));}
 if($record)$record=mwa_owned($part,$uid);$records=mwa_records($uid);usort($records,static fn($a,$b)=>$b['created_at']<=>$a['created_at']);render_write('microsoft_actions',compact('uid','part','error','record','records','events','values')+['writeTitle'=>'Microsoft action studio']);return true;
}
