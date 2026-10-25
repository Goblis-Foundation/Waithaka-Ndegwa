<?php
declare(strict_types=1);

/** Per-account delegated grants, never a tenant-wide application permission. */
final class MicrosoftWorkspaceException extends RuntimeException {}
function ms_accounts(): array {return ['personal'=>'iwaithakaw@gmail.com','group'=>'isaac@goblisfoundation.org'];}
/** Alias relationship explicitly confirmed by the owner; not a separate mailbox. */
function ms_aliases(): array {return ['personal'=>[],'group'=>['isaac@goblisgroup.com','isaac@goblisfoundation.com','isaac@waithakandegwa.com']];}
function ms_features(): array {
 return [
 'calendar'=>['name'=>'Outlook calendar','scope'=>'Calendars.Read','path'=>'/me/calendarView','select'=>'id,subject,start,end,isCancelled','columns'=>['subject'=>'Meeting','start'=>'Starts','end'=>'Ends','isCancelled'=>'Cancelled']],
 'mail'=>['name'=>'Outlook mail headers','scope'=>'Mail.ReadBasic','path'=>'/me/messages','select'=>'id,subject,from,receivedDateTime,isRead','columns'=>['subject'=>'Subject','from'=>'From','receivedDateTime'=>'Received','isRead'=>'Read']],
 'contacts'=>['name'=>'Outlook contacts','scope'=>'Contacts.Read','path'=>'/me/contacts','select'=>'id,displayName,emailAddresses','columns'=>['displayName'=>'Contact','emailAddresses'=>'Email']],
 'files'=>['name'=>'OneDrive & Office files','scope'=>'Files.Read','path'=>'/me/drive/root/children','select'=>'id,name,size,lastModifiedDateTime','columns'=>['name'=>'Name','size'=>'Bytes','lastModifiedDateTime'=>'Modified']],
 'tasks'=>['name'=>'Microsoft To Do lists','scope'=>'Tasks.Read','path'=>'/me/todo/lists','select'=>'id,displayName','columns'=>['displayName'=>'List']],
 'notes'=>['name'=>'OneNote notebooks','scope'=>'Notes.Read','path'=>'/me/onenote/notebooks','select'=>'id,displayName,lastModifiedDateTime','columns'=>['displayName'=>'Notebook','lastModifiedDateTime'=>'Modified']]
 ];
}
function ms_scopes(): array {return array_merge(['User.Read','offline_access'],array_column(ms_features(),'scope'));}
function ms_has_scope(array $scopes,string $required): bool {
 foreach($scopes as$scope)if(is_string($scope)&&strtolower(preg_replace('~^https://graph\.microsoft\.com/~i','',$scope))===strtolower($required))return true;
 return false;
}
function ms_granted(array $token): array {
 if(!is_string($token['scope']??null))throw new MicrosoftWorkspaceException('Microsoft did not return the granted permissions. Start again.');
 return array_values(array_filter(preg_split('/\s+/',trim($token['scope']))?:[]));
}
function ms_identity(array $who,string $slot): array {
 $expected=ms_accounts()[$slot]??throw new MicrosoftWorkspaceException('Choose one of the requested accounts.');
 $id=$who['id']??'';if(!is_string($id)||$id===''||strlen($id)>255)throw new MicrosoftWorkspaceException('Microsoft did not return an account identity.');
 $matched='';foreach(array_merge([$expected],ms_aliases()[$slot]??[])as$allowed){foreach([$who['mail']??null,$who['userPrincipalName']??null]as$email)if(is_string($email)&&strcasecmp(trim($email),$allowed)===0){$matched=$allowed;break 2;}}
 if($matched==='')throw new MicrosoftWorkspaceException('Microsoft returned a different identity. Select '.$expected.'. If this address is an alias, verify its Microsoft identity before connecting it here.');
 return ['id'=>$id,'email'=>$matched,'canonical_email'=>$expected,'name'=>mb_substr(is_string($who['displayName']??null)?$who['displayName']:$expected,0,200)];
}
function ms_key(string $slot): string {if(!isset(ms_accounts()[$slot]))throw new MicrosoftWorkspaceException('Choose an account shown here.');return 'microsoft.workspace.'.$slot;}
function ms_record(string $slot,int $uid): array {$r=comm_get(ms_key($slot));return (int)($r['owner']??0)===$uid?$r:[];}
function ms_revision(array $r): string {return hash('sha256',publishing_json($r));}
function ms_callback(): string {return publishing_callback_url('meetings_microsoft');}
function ms_authorize(string $slot,int $uid): string {
 $email=ms_accounts()[$slot]??throw new MicrosoftWorkspaceException('Choose an account shown here.');$client=publishing_client('microsoft');meetings_validate_client('microsoft',$client);
 if($client['client_id']===''||$client['client_secret']==='')throw new MicrosoftWorkspaceException('Save the Microsoft app setup in Integrations first.');
 $state=bin2hex(random_bytes(32));$verifier=rtrim(strtr(base64_encode(random_bytes(64)),'+/','-_'),'=');
 $_SESSION['ms_workspace_oauth']=['state_hash'=>hash('sha256',$state),'verifier'=>$verifier,'slot'=>$slot,'uid'=>$uid,'expires'=>time()+600,'revision'=>ms_revision(ms_record($slot,$uid))];
 return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?'.http_build_query(['client_id'=>$client['client_id'],'response_type'=>'code','redirect_uri'=>ms_callback(),'response_mode'=>'query','state'=>$state,'scope'=>implode(' ',ms_scopes()),'prompt'=>'select_account','login_hint'=>$email,'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'],'','&',PHP_QUERY_RFC3986);
}
function ms_consume(string $state,int $uid): array {
 $r=$_SESSION['ms_workspace_oauth']??[];unset($_SESSION['ms_workspace_oauth']);
 if(!is_array($r)||!preg_match('/^[a-f0-9]{64}$/D',$state)||!hash_equals((string)($r['state_hash']??''),hash('sha256',$state))||(int)($r['uid']??0)!==$uid||(int)($r['expires']??0)<time())throw new MicrosoftWorkspaceException('This Microsoft connection expired or belongs to another session. Start again.');
 $_SESSION['ms_workspace_consumed']=['state_hash'=>hash('sha256',$state),'uid'=>$uid,'expires'=>time()+600,'purpose'=>$r['purpose']??null];return $r;
}
function ms_callback_replay(string $state,int $uid): bool {$r=$_SESSION['ms_workspace_consumed']??[];return preg_match('/^[a-f0-9]{64}$/D',$state)===1&&(int)($r['uid']??0)===$uid&&(int)($r['expires']??0)>=time()&&hash_equals((string)($r['state_hash']??''),hash('sha256',$state));}
function ms_complete(array $flow,string $code,int $uid): void {
 if(isset($flow['purpose'])){mwa_complete($flow,$code,$uid);return;}
 $token=meetings_token_request('microsoft',['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>ms_callback(),'code_verifier'=>$flow['verifier']]);$scopes=ms_granted($token);
 if(!ms_has_scope($scopes,'User.Read'))throw new MicrosoftWorkspaceException('Account identity permission was declined.');
 $who=publishing_http('GET','https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName',publishing_bearer($token['access_token']))['data'];$identity=ms_identity($who,$flow['slot']);
 comm_lock('ms365-'.$uid.'-'.$flow['slot'],static function()use($flow,$uid,$identity,$token,$scopes){
  if(!hash_equals((string)$flow['revision'],ms_revision(ms_record($flow['slot'],$uid))))throw new MicrosoftWorkspaceException('This account changed during authorization. Start a fresh connection.');
  $old=ms_record($flow['slot'],$uid);comm_put(ms_key($flow['slot']),['owner'=>$uid,'identity'=>$identity,'token'=>$token,'scopes'=>$scopes,'status'=>'pending_confirmation','verified_at'=>time(),'booking_conflicts'=>!empty($old['booking_conflicts'])]);
 });
}
function ms_token(string $slot,int $uid): string {
 return comm_lock('ms365-'.$uid.'-'.$slot,static function()use($slot,$uid){
  $r=ms_record($slot,$uid);if(($r['status']??'')!=='connected')throw new MicrosoftWorkspaceException('Connect and confirm this account first.');$token=$r['token']??[];
  if((int)($token['expires_at']??0)<=time()+120){if(!is_string($token['refresh_token']??null)||$token['refresh_token']==='')throw new MicrosoftWorkspaceException('Reconnect this Microsoft account.');$updated=meetings_token_request('microsoft',['grant_type'=>'refresh_token','refresh_token'=>$token['refresh_token']]);$r['scopes']=ms_granted($updated);$token=array_merge($token,$updated);$r['token']=$token;comm_put(ms_key($slot),$r);}
  if(!is_string($token['access_token']??null)||$token['access_token']==='')throw new MicrosoftWorkspaceException('Reconnect this Microsoft account.');return $token['access_token'];
 });
}
function ms_query(string $feature): array {
 $d=ms_features()[$feature]??throw new MicrosoftWorkspaceException('Choose a supported read check.');$params=['$top'=>20];
 // To Do supports only a subset of OData options; retain local field projection.
 if($feature!=='tasks')$params['$select']=$d['select'];
 if($feature==='calendar'){$params['startDateTime']=gmdate('Y-m-d\TH:i:s\Z');$params['endDateTime']=gmdate('Y-m-d\TH:i:s\Z',time()+604800);$params['$orderby']='start/dateTime';}if($feature==='mail')$params['$orderby']='receivedDateTime desc';
 return ['url'=>'https://graph.microsoft.com/v1.0'.$d['path'].'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986),'columns'=>$d['columns']];
}
function ms_value(mixed $value): string {
 if(is_bool($value))return $value?'Yes':'No';if(is_scalar($value))return mb_substr((string)$value,0,500);
 if(is_array($value)){if(isset($value['emailAddress']))return ms_value($value['emailAddress']);if(isset($value['address']))return ms_value($value['address']);if(isset($value['dateTime'])){try{$z=new DateTimeZone((string)($value['timeZone']??'UTC'));return(new DateTimeImmutable((string)$value['dateTime'],$z))->setTimezone(new DateTimeZone('Africa/Nairobi'))->format('D j M Y, H:i T');}catch(Throwable){return 'Date unavailable';}}return mb_substr(implode('; ',array_map('ms_value',array_slice($value,0,10))),0,500);}return 'Unavailable';
}
function ms_read(string $slot,string $feature,int $uid): array {
 $d=ms_features()[$feature]??throw new MicrosoftWorkspaceException('Choose a supported read check.');$token=ms_token($slot,$uid);$r=ms_record($slot,$uid);
 if(!ms_has_scope($r['scopes']??[],$d['scope']))throw new MicrosoftWorkspaceException('This permission was not granted. Reconnect and review Microsoft consent.');$q=ms_query($feature);
 $data=publishing_http('GET',$q['url'],array_merge(publishing_bearer($token),['Prefer: outlook.timezone="UTC"']))['data'];if(!is_array($data['value']??null))throw new MicrosoftWorkspaceException('Microsoft did not return a supported list.');
 $rows=[];foreach(array_slice($data['value'],0,20)as$row){if(!is_array($row))continue;$safe=[];foreach($q['columns']as$key=>$label)$safe[$key]=ms_value($row[$key]??null);$rows[]=$safe;}
 return ['account'=>$r['identity']['email'],'feature'=>$d['name'],'columns'=>$q['columns'],'rows'=>$rows,'at'=>time(),'more'=>!empty($data['@odata.nextLink'])];
}
/** Busy intervals only; never expose Outlook subjects to public booking visitors. */
function ms_busy_rows(array $data): array {
 if(!is_array($data['value']??null)||!empty($data['@odata.nextLink']))throw new MicrosoftWorkspaceException('Outlook availability could not be checked completely.');$busy=[];
 foreach($data['value']as$event){if(!is_array($event))throw new MicrosoftWorkspaceException('An Outlook interval was invalid.');if(!empty($event['isCancelled'])||($event['showAs']??'busy')==='free')continue;
  foreach(['start','end']as$key)if(!is_string($event[$key]['dateTime']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})?$/D',$event[$key]['dateTime']))throw new MicrosoftWorkspaceException('An Outlook interval was invalid.');
  try{$s=new DateTimeImmutable($event['start']['dateTime'],new DateTimeZone('UTC'));$e=new DateTimeImmutable($event['end']['dateTime'],new DateTimeZone('UTC'));}catch(Throwable){throw new MicrosoftWorkspaceException('An Outlook interval was invalid.');}
  if(empty($event['start']['dateTime'])||empty($event['end']['dateTime'])||$e<=$s)throw new MicrosoftWorkspaceException('An Outlook interval was invalid.');$busy[]=[$s->getTimestamp(),$e->getTimestamp()];
 }return $busy;
}
function ms_booking_busy(int $uid,string $min,string $max): array {
 $busy=[];foreach(ms_accounts()as$slot=>$email){$r=ms_record($slot,$uid);if(empty($r['booking_conflicts']))continue;$token=ms_token($slot,$uid);$r=ms_record($slot,$uid);if(!ms_has_scope($r['scopes']??[],'Calendars.Read'))throw new MicrosoftWorkspaceException('A required Microsoft calendar needs reconnection.');
  $data=publishing_http('GET','https://graph.microsoft.com/v1.0/me/calendarView?'.http_build_query(['startDateTime'=>$min,'endDateTime'=>$max,'$top'=>1000,'$select'=>'start,end,isCancelled,showAs'],'','&',PHP_QUERY_RFC3986),array_merge(publishing_bearer($token),['Prefer: outlook.timezone="UTC"']))['data'];array_push($busy,...ms_busy_rows($data));
 }return $busy;
}
function ms_dispatch(string $path): bool {
 $callback=$path==='/write/connections/callback/meetings_microsoft';
 if($callback){$state=is_string($_GET['state']??null)?$_GET['state']:'';$pending=$_SESSION['ms_workspace_oauth']??[];if((!$pending||!hash_equals((string)($pending['state_hash']??''),hash('sha256',$state)))&&!ms_callback_replay($state,(int)($_SESSION['user_id']??0)))return false;}
 elseif($path!=='/write/microsoft')return false;
 require_auth();private_response_headers();$uid=(int)current_user()['id'];if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}journeys_method($callback?['GET']:['GET','HEAD','POST']);$error='';$report=null;$agenda=[];
 try{
  if($callback){if(ms_callback_replay($state,$uid))redirect(url(in_array($_SESSION['ms_workspace_consumed']['purpose']??'', ['drafts','calendar'],true)?'/write/microsoft/actions':'/write/microsoft'));$flow=ms_consume($state,$uid);if(!empty($_GET['error']))throw new MicrosoftWorkspaceException('Microsoft authorization was not completed. Your existing meeting connection was retained.');$code=is_string($_GET['code']??null)?$_GET['code']:'';if($code===''||strlen($code)>12000)throw new MicrosoftWorkspaceException('Microsoft did not return a valid authorization. Start again.');ms_complete($flow,$code,$uid);flash('success','Microsoft returned the requested identity. Confirm it before using this permission.');redirect(url(isset($flow['purpose'])?'/write/microsoft/actions':'/write/microsoft#'.$flow['slot']));}
  if(is_post()){
   verify_csrf();if(!journeys_rate_limit('ms365-owner',(string)$uid,30,300))throw new MicrosoftWorkspaceException('Please wait before another Microsoft request.');$action=is_string($_POST['action']??null)?$_POST['action']:'';
   if($action==='agenda'){foreach(ms_accounts()as$slot=>$email){if((ms_record($slot,$uid)['status']??'')!=='connected')continue;try{$agenda[]=['slot'=>$slot,'report'=>ms_read($slot,'calendar',$uid)];}catch(Throwable){$agenda[]=['slot'=>$slot,'error'=>'This account could not be read. Its availability remains unknown.'];}}}
   else{$slot=is_string($_POST['account']??null)?$_POST['account']:'';ms_key($slot);
    if($action==='connect')redirect(ms_authorize($slot,$uid));
    elseif($action==='read')$report=ms_read($slot,is_string($_POST['feature']??null)?$_POST['feature']:'',$uid);
    elseif(in_array($action,['confirm','disconnect','conflicts'],true)){
     comm_lock('ms365-'.$uid.'-'.$slot,static function()use($slot,$uid,$action){$r=ms_record($slot,$uid);$revision=is_string($_POST['revision']??null)?$_POST['revision']:'';if(!hash_equals(ms_revision($r),$revision))throw new MicrosoftWorkspaceException('The account changed. Reload and review it.');
      if($action==='confirm'){if(($r['status']??'')!=='pending_confirmation'||($_POST['confirm_identity']??'')!=='1')throw new MicrosoftWorkspaceException('Confirm the returned account first.');$r['status']='connected';}
      elseif($action==='disconnect'){if(!empty($r['booking_conflicts']))throw new MicrosoftWorkspaceException('First remove this calendar from booking conflict checks.');$r=['owner'=>$uid,'status'=>'disconnected','removed_at'=>time()];}
      else{$enabled=($_POST['booking_conflicts']??'')==='1';if($enabled&&(($r['status']??'')!=='connected'||!ms_has_scope($r['scopes']??[],'Calendars.Read')))throw new MicrosoftWorkspaceException('Connect calendar read access first.');$r['booking_conflicts']=$enabled;}
      comm_put(ms_key($slot),$r);
     });flash('success',$action==='confirm'?'Microsoft account confirmed.':($action==='disconnect'?'Saved Microsoft access removed.':'Booking conflict calendar choice saved.'));redirect(url('/write/microsoft#'.$slot));
    }else throw new MicrosoftWorkspaceException('Choose a supported Microsoft action.');
   }
  }
 }catch(MicrosoftWorkspaceException $e){$error=$e->getMessage();}
 catch(PublishingRemoteException $e){$error='Microsoft could not complete this check'.($e->httpStatus?' (HTTP '.$e->httpStatus.')':'').'. Check account eligibility, permissions and licence. No Microsoft content was changed.';}
 catch(Throwable $e){$error='This Microsoft request could not be completed. The existing meeting connection is retained.';error_log('Microsoft workspace failure: '.get_class($e));}
 if($callback&&$error!==''){flash('error',$error);redirect(url('/write/microsoft'));}
 $accounts=[];foreach(ms_accounts()as$slot=>$email)$accounts[$slot]=['email'=>$email,'record'=>ms_record($slot,$uid)];render_write('microsoft_workspace',compact('accounts','error','report','agenda')+['writeTitle'=>'Microsoft 365 workspace']);return true;
}
