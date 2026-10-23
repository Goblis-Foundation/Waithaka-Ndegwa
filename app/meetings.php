<?php
declare(strict_types=1);

/** User-delegated meeting access only. No organization-wide grants or meeting mutations. */
function meetings_providers(): array {
    return [
        'zoom'=>['name'=>'Zoom','scopes'=>['user:read:user','meeting:read:list_meetings'],'authorize'=>'https://zoom.us/oauth/authorize','token'=>'https://zoom.us/oauth/token','home'=>'https://zoom.us/meeting','developer'=>'https://marketplace.zoom.us/','note'=>'User-managed OAuth app. Read your scheduled meetings; scheduling and hosting stay in Zoom.'],
        'microsoft'=>['name'=>'Microsoft Teams & Outlook','scopes'=>['User.Read','Calendars.Read','offline_access'],'authorize'=>'https://login.microsoftonline.com/common/oauth2/v2.0/authorize','token'=>'https://login.microsoftonline.com/common/oauth2/v2.0/token','home'=>'https://teams.microsoft.com/','developer'=>'https://entra.microsoft.com/','note'=>'Register a Web app supporting work, school and personal accounts. Reads your Outlook calendar and its Teams links. Personal Teams meeting creation is handled in Teams; the Graph onlineMeetings API requires a work or school account.'],
        'webex'=>['name'=>'Cisco Webex','scopes'=>['spark:people_read','meeting:schedules_read'],'authorize'=>'https://webexapis.com/v1/authorize','token'=>'https://webexapis.com/v1/access_token','home'=>'https://web.webex.com/','developer'=>'https://developer.webex.com/my-apps','note'=>'Register a Webex OAuth integration. Reads meetings available to your signed-in host account; licensing can limit availability.'],
    ];
}
function meetings_service(string $provider): string {return 'meetings_'.$provider;}
function meetings_definition(string $service): ?array {
    foreach(meetings_providers() as$p=>$d)if(meetings_service($p)===$service)return $d+['provider'=>$p];return null;
}
function meetings_validate_client(string $provider,array $client): void {
    if($provider!=='microsoft')return;
    if(!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i',(string)($client['client_id']??'')))throw new RuntimeException('Microsoft requires the Application (client) ID from this app registration.');
    if(preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i',(string)($client['client_secret']??'')))throw new RuntimeException('The saved Microsoft credential looks like a Secret ID. Save the client secret Value in private connection settings instead.');
}
function meetings_remote_error(string $provider,string $stage,int $status): string {
    $name=meetings_providers()[$provider]['name']??'Meeting provider';
    $step=$stage==='token'?'token exchange':'account identity check';
    $help=match(true){
        $status===401&&$stage==='token'=>'Check that the app client ID and secret Value belong to the same registration, and that the secret has not expired. Then start a new connection.',
        $status===401=>'The token was not accepted for the account profile API. Review the app permissions and reconnect.',
        $status===403=>'Review the delegated read permissions and account eligibility before reconnecting.',
        $status===429=>'Wait before trying again; the provider rate limit was reached.',
        $status===400&&$stage==='token'=>'Review the exact Web callback and app settings, then start a fresh connection. The authorization code may have expired.',
        default=>'The provider could not complete this step. Check its status before trying again.'
    };
    return $name.' '.$step.' failed'.($status?' (HTTP '.$status.')':'').'. '.$help;
}
function meetings_authorize(string $service,string $state,string $verifier): string {
    $d=meetings_definition($service);if(!$d)throw new InvalidArgumentException('Unknown meeting provider.');
    $client=publishing_client($d['provider']);meetings_validate_client($d['provider'],$client);
    $params=['response_type'=>'code','client_id'=>$client['client_id'],'redirect_uri'=>publishing_callback_url($service),'state'=>$state,'scope'=>implode(' ',$d['scopes'])];
    if($d['provider']==='microsoft')$params+=['prompt'=>'select_account','code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),'code_challenge_method'=>'S256'];
    return $d['authorize'].'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);
}
function meetings_token_request(string $provider,array $grant): array {
    $d=meetings_providers()[$provider]??throw new InvalidArgumentException('Unknown meeting provider.');
    $client=publishing_client($provider);meetings_validate_client($provider,$client);$headers=[];$body=$client+$grant;
    if($provider==='zoom'){$headers[]='Authorization: Basic '.base64_encode($client['client_id'].':'.$client['client_secret']);unset($body['client_id'],$body['client_secret']);}
    try{$r=publishing_http('POST',$d['token'],$headers,$body,true)['data'];}catch(PublishingRemoteException $e){throw new PublishingRemoteException(meetings_remote_error($provider,'token',$e->httpStatus),$e->httpStatus);}
    if(empty($r['access_token'])||!is_string($r['access_token']))throw new RuntimeException('No access token returned. Reconnect this meeting account.');
    $r['expires_at']=time()+max(1,(int)($r['expires_in']??3600));return $r;
}
function meetings_complete(string $service,string $code,array $state,int $uid): void {
    $d=meetings_definition($service);if(!$d)throw new InvalidArgumentException('Unknown meeting provider.');
    $grant=['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>publishing_callback_url($service)];
    if($d['provider']==='microsoft')$grant['code_verifier']=decrypt_secret($state['verifier_encrypted']);
    $token=meetings_token_request($d['provider'],$grant);
    $scopes=isset($token['scope'])?preg_split('/[ ,]+/',trim((string)$token['scope'])):$d['scopes'];
    foreach($d['scopes'] as$s)if($s!=='offline_access'&&!in_array(strtolower($s),array_map('strtolower',$scopes),true))throw new RuntimeException('A required read permission was declined.');
    $headers=publishing_bearer($token['access_token']);
    $endpoint=match($d['provider']){'zoom'=>'https://api.zoom.us/v2/users/me','microsoft'=>'https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName','webex'=>'https://webexapis.com/v1/people/me'};
    try{$who=publishing_http('GET',$endpoint,$headers)['data'];}catch(PublishingRemoteException $e){throw new PublishingRemoteException(meetings_remote_error($d['provider'],'identity',$e->httpStatus),$e->httpStatus);}$id=(string)($who['id']??'');
    if($id===''||strlen($id)>255)throw new RuntimeException('The provider did not return an account identity.');
    $name=(string)($who['displayName']??trim(($who['first_name']??'').' '.($who['last_name']??'')));
    $email=(string)($who['email']??$who['mail']??$who['userPrincipalName']??($who['emails'][0]??''));
    $name=mb_substr(trim($name.' · '.$email),0,255);
    publishing_save_observed_connection($service,$token,$scopes,['id'=>$id,'name'=>$name,'resources'=>[['id'=>$id,'name'=>$name,'type'=>'personal_meeting_account']]],$uid);
}
function meetings_access_token(string $provider,int $uid): string {
    $service=meetings_service($provider);$lock='meeting-token-'.$service;
    $q=db()->prepare('SELECT GET_LOCK(?,3)');$q->execute([$lock]);if(!(int)$q->fetchColumn())throw new RuntimeException('The account is refreshing. Try again shortly.');
    try{
        $c=publishing_connection($service);if(!$c||$c['status']!=='connected'||(int)$c['connected_by']!==$uid||$c['selected_resource']!==$c['external_id'])throw new RuntimeException('Connect and confirm your meeting account first.');
        $token=publishing_credentials($c);
        if((int)($token['expires_at']??0)<=time()+120){
            if(empty($token['refresh_token']))throw new RuntimeException('Reconnect this meeting account.');
            $token=array_merge($token,meetings_token_request($provider,['grant_type'=>'refresh_token','refresh_token'=>$token['refresh_token']]));
            $q=db()->prepare("UPDATE publishing_connections SET credentials_encrypted=? WHERE id=? AND credentials_encrypted=? AND status='connected'");$q->execute([encrypt_secret(publishing_json($token)),$c['id'],$c['credentials_encrypted']]);
            if($q->rowCount()!==1)throw new RuntimeException('The connection changed. Reload and review it.');
        }
        return (string)$token['access_token'];
    }finally{$q=db()->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}
/** Strict join/booking links; never follow or fetch a user-supplied URL server-side. */
function meetings_link(string $value,string $provider): string {
    if($value==='')return '';
    if(strlen($value)>3000||preg_match('/[\x00-\x20\x7f\\\\]/',$value))throw new InvalidArgumentException('Enter a valid HTTPS meeting or booking link.');
    $decoded=rawurldecode($value);if(preg_match('/[\x00-\x20\x7f\\\\]/',$decoded))throw new InvalidArgumentException('Encoded control characters are not allowed.');
    $p=parse_url($value);$host=strtolower($p['host']??'');
    $domains=match($provider){'google'=>['meet.google.com','calendar.google.com','calendar.app.google'],'zoom'=>['zoom.us','zoom.com'],'microsoft'=>['teams.microsoft.com','teams.live.com','teams.cloud.microsoft'],'webex'=>['webex.com'],'calendly'=>['calendly.com'],default=>[]};
    $ok=false;foreach($domains as$d)if($host===$d||(($provider==='zoom'||$provider==='webex')&&str_ends_with($host,'.'.$d)))$ok=true;
    if(!$ok||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['fragment']))throw new InvalidArgumentException('Use an HTTPS link from the selected meeting platform.');
    if($provider==='zoom'&&preg_match('~^/(?:s|start)(?:/|$)~i',rawurldecode($p['path']??'')))throw new InvalidArgumentException('Save a participant join link, never a host start link.');
    if(preg_match('/(?:^|&)(?:zak|access_token|token|code|client_secret)=/i',rawurldecode($p['query']??'')))throw new InvalidArgumentException('Do not save an authentication or host access link.');
    return $value;
}
function meetings_event_join(array $event): string {
    $candidates=[];if(is_string($event['hangoutLink']??null))$candidates[]=$event['hangoutLink'];
    foreach($event['conferenceData']['entryPoints']??[]as$entry)if(($entry['entryPointType']??'')==='video'&&is_string($entry['uri']??null))$candidates[]=$entry['uri'];
    foreach($candidates as$link)foreach(['google','zoom','microsoft','webex']as$provider){try{return meetings_link($link,$provider);}catch(InvalidArgumentException){}}
    return '';
}
function meetings_read(string $provider,int $uid,?callable $transport=null): array {
    if(!isset(meetings_providers()[$provider]))throw new InvalidArgumentException('Choose a supported meeting account.');
    $headers=$transport?[]:publishing_bearer(meetings_access_token($provider,$uid));$transport??='publishing_http';
    $start=gmdate('Y-m-d\T00:00:00\Z');$end=gmdate('Y-m-d\T00:00:00\Z',time()+31*86400);
    $endpoint=match($provider){'zoom'=>'https://api.zoom.us/v2/users/me/meetings?type=upcoming&page_size=100','microsoft'=>'https://graph.microsoft.com/v1.0/me/calendarView?'.http_build_query(['startDateTime'=>$start,'endDateTime'=>$end,'$top'=>100,'$select'=>'subject,start,end,onlineMeeting,webLink,isCancelled'],'','&',PHP_QUERY_RFC3986),'webex'=>'https://webexapis.com/v1/meetings?'.http_build_query(['from'=>$start,'to'=>$end,'max'=>100],'','&',PHP_QUERY_RFC3986)};
    $response=$transport('GET',$endpoint,$headers);$data=$response['data'];$rows=[];
    foreach(array_slice($data[match($provider){'zoom'=>'meetings','microsoft'=>'value','webex'=>'items'}]??[],0,100)as$m){
        if(!empty($m['isCancelled']))continue;
        $join=(string)($m['join_url']??$m['onlineMeeting']['joinUrl']??$m['webLink']??'');
        // Outlook webLink is an event page, not necessarily a meeting join URL.
        try{$join=meetings_link($join,$provider);}catch(InvalidArgumentException){$join='';}
        $rows[]=['title'=>mb_substr((string)($m['topic']??$m['subject']??$m['title']??'Meeting'),0,300),'start'=>(string)($provider==='microsoft'?($m['start']['dateTime']??''):($m['start_time']??$m['start']??'')),'timezone'=>(string)($provider==='microsoft'?($m['start']['timeZone']??'UTC'):($m['timezone']??'UTC')),'join'=>$join];
    }
    return ['provider'=>meetings_providers()[$provider]['name'],'rows'=>$rows,'limited'=>count($rows)>=100||!empty($data['next_page_token'])||!empty($data['@odata.nextLink'])||!empty($response['headers']['link']),'observed'=>gmdate('c')];
}
function meetings_dispatch(string $path): bool {
    if($path!=='/write/meetings')return false;require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);
    $uid=(int)current_user()['id'];$error='';$report=null;
    try{if(is_post()){
        verify_csrf();$action=$_POST['action']??'';$provider=$_POST['provider']??'';
        if(!is_string($provider)||!isset(meetings_providers()[$provider])&& !in_array($provider,['google','calendly'],true))throw new InvalidArgumentException('Choose a meeting platform.');
        if($action==='settings'){
            if(!isset(meetings_providers()[$provider]))throw new InvalidArgumentException('Choose a connection provider.');
            $values=[];foreach(['client_id','client_secret']as$key){$v=$_POST[$key]??'';if(!is_string($v)||strlen($v)>2000||preg_match('/[\x00-\x20\x7f]/',$v))throw new InvalidArgumentException('Enter valid app credentials.');$values[$key]=$v;}
            foreach($values as$key=>$v)if($v!=='')set_setting('publishing.'.$provider.'.'.$key,$v,true);
            flash('success','App credentials saved securely. Connect the account to authorize access.');redirect(url('/write/meetings#'.$provider));
        }elseif($action==='link'){
            $v=$_POST['meeting_url']??'';if(!is_string($v))throw new InvalidArgumentException('Enter a meeting link.');
            set_setting('meetings.'.$uid.'.'.$provider.'.link',meetings_link(trim($v),$provider),true);
            flash('success','Private meeting shortcut saved. It is not published on the website.');redirect(url('/write/meetings#'.$provider));
        }elseif($action==='read'){
            if(!journeys_rate_limit('meetings-read',(string)$uid,12,3600))throw new RuntimeException('Please wait before reading meetings again.');$report=meetings_read($provider,$uid);
        }else throw new InvalidArgumentException('Choose a supported meeting action.');
    }}catch(Throwable $e){$error=$e instanceof RuntimeException||$e instanceof InvalidArgumentException?$e->getMessage():'Unable to complete the meeting request. Please try again.';}
    render_write('meetings',['writeTitle'=>'Meetings & booking','error'=>$error,'report'=>$report,'uid'=>$uid]);return true;
}
