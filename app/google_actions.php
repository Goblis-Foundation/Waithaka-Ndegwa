<?php
declare(strict_types=1);

/** Reviewed Google writes. Prepared content is immutable; uncertain writes never retry. */
final class GoogleActionException extends RuntimeException {}
function gwa_types(): array {return ['document'=>'Create a Google Doc','sheet'=>'Create a Google Sheet','task'=>'Create a Google Task'];}
function gwa_service(string $kind): string {
    if(!isset(gwa_types()[$kind]))throw new GoogleActionException('Choose a document, spreadsheet or task.');
    return $kind==='task'?'google_tasks':'google_drive';
}
function gwa_text(mixed $value,int $limit,bool $required=true): string {
    if(!is_string($value)||!mb_check_encoding($value,'UTF-8')||strlen($value)>$limit||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new GoogleActionException('Use valid text within the displayed limit.');
    $value=str_replace(["\r\n","\r"],"\n",$value);
    if($required&&trim($value)==='')throw new GoogleActionException('Complete the required content.');
    return $value;
}
function gwa_rows(string $text): array {
    $rows=array_map(static fn($line)=>explode("\t",$line),explode("\n",rtrim($text,"\n")));
    if(count($rows)>200)throw new GoogleActionException('Use at most 200 rows.');
    $width=1;foreach($rows as$row){$width=max($width,count($row));foreach($row as$cell)if(strlen($cell)>2000)throw new GoogleActionException('Use at most 2,000 bytes in each cell.');}
    if($width>20)throw new GoogleActionException('Use at most 20 columns.');
    return array_map(static fn($row)=>array_pad($row,$width,''),$rows);
}
function gwa_ready(string $kind,int $uid): array {
    $service=gwa_service($kind);$c=publishing_connection($service);
    $required=$kind==='task'?'https://www.googleapis.com/auth/tasks':'https://www.googleapis.com/auth/drive.file';
    if(!$c||$c['status']!=='connected'||(int)$c['connected_by']!==$uid||empty($c['external_id'])||empty($c['selected_resource'])||!in_array($required,publishing_decode($c['scopes_json']),true))throw new GoogleActionException('Connect and confirm '.($kind==='task'?'Google Tasks and its selected list':'Google Drive selected files').' for your own account first.');
    return $c;
}
function gwa_fingerprint(array $c): string {
    return hash('sha256',publishing_json([$c['id'],$c['external_id'],$c['selected_resource'],$c['verified_at'],$c['scopes_json'],(int)$c['connected_by']]));
}
function gwa_owned(string $id,int $uid): array {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new GoogleActionException('This action was not found.');
    $r=comm_get('google.action.'.$id);
    if(!$r||(int)$r['owner']!==$uid)throw new GoogleActionException('This action was not found.');
    return $r;
}
function gwa_records(int $uid): array {return array_values(comm_get('google.action_index.'.$uid));}
function gwa_save(array $r,string $state): array {
    $r['state']=$state;$r['version']++;$r['updated_at']=time();$r['audit'][]=['state'=>$state,'at'=>time()];
    comm_put('google.action.'.$r['id'],$r);
    comm_lock('gwa-index-'.$r['owner'],static function()use($r){$index=comm_get('google.action_index.'.$r['owner']);$index[$r['id']]=array_intersect_key($r,array_flip(['id','owner','kind','title','state','created_at','updated_at']));comm_put('google.action_index.'.$r['owner'],$index);});
    return $r;
}
function gwa_digest(array $r): string {return hash('sha256',publishing_json([$r['id'],$r['owner'],$r['kind'],$r['title'],$r['text'],$r['due'],$r['destination'],$r['grant_fingerprint']]));}
function gwa_version(array $r,int $version): void {if($r['version']!==$version)throw new GoogleActionException('This action changed. Reload and review it.');}
function gwa_prepare(array $input,int $uid): string {
    $kind=gwa_text($input['kind']??'',20);$c=gwa_ready($kind,$uid);$title=trim(gwa_text($input['title']??'',200));
    if(str_contains($title,"\n")||str_contains($title,"\t"))throw new GoogleActionException('Use a single-line title.');
    $text=gwa_text($input['text']??'',$kind==='task'?6000:60000,$kind!=='task');
    if($kind==='sheet')gwa_rows($text);
    $due=$kind==='task'?trim(gwa_text($input['due']??'',10,false)):'';
    if($due!==''){try{comm_date($due);}catch(Throwable){throw new GoogleActionException('Choose a valid due date.');}}
    $id=bin2hex(random_bytes(16));
    comm_lock('gwa-capacity-'.$uid,static function()use($uid,$id,$kind,$title,$text,$due,$c){
        if(count(gwa_records($uid))>=200)throw new GoogleActionException('The retained action limit is 200. Keep existing evidence and contact your administrator for archival.');
        $destination=$kind==='task'?$c['selected_resource']:'My Drive';
        gwa_save(['id'=>$id,'owner'=>$uid,'kind'=>$kind,'title'=>$title,'text'=>$text,'due'=>$due,'destination'=>$destination,'account_name'=>$c['display_name'],'grant_fingerprint'=>gwa_fingerprint($c),'version'=>0,'created_at'=>time(),'audit'=>[]],'draft');
    });
    return $id;
}
function gwa_review(string $id,int $uid,int $version): void {
    comm_lock('gwa-'.$id,static function()use($id,$uid,$version){$r=gwa_owned($id,$uid);gwa_version($r,$version);
        if(!in_array($r['state'],['draft','reviewed'],true))throw new GoogleActionException('Only an unused action can be reviewed.');
        if(!hash_equals($r['grant_fingerprint'],gwa_fingerprint(gwa_ready($r['kind'],$uid))))throw new GoogleActionException('The account or destination changed. Prepare a new action.');
        $r['approval_hash']=gwa_digest($r);$r['reviewed_at']=time();gwa_save($r,'reviewed');
    });
}
function gwa_payload(array $r): array {
    if($r['kind']==='task')return ['title'=>$r['title'],'notes'=>$r['text'],'status'=>'needsAction']+($r['due']!==''?['due'=>$r['due'].'T00:00:00.000Z']:[]);
    if($r['kind']==='document')return ['metadata'=>['name'=>$r['title'],'mimeType'=>'application/vnd.google-apps.document','appProperties'=>['waithakaAction'=>$r['id']]],'text'=>$r['text']];
    // Every pasted value is explicitly a string, never an executable spreadsheet formula.
    return ['properties'=>['title'=>$r['title']],'sheets'=>[['properties'=>['title'=>'Report','gridProperties'=>['rowCount'=>max(1,count(gwa_rows($r['text']))),'columnCount'=>count(gwa_rows($r['text'])[0])]],'data'=>[['rowData'=>array_map(static fn($row)=>['values'=>array_map(static fn($value)=>['userEnteredValue'=>['stringValue'=>$value]],$row)],gwa_rows($r['text']))]]]]];
}
/** Only two fixed Drive binary endpoints; no caller-selected host, redirects or credential logs. */
function gwa_drive_bytes(string $method,string $url,array $headers,?array $body=null): array {
    $upload=$method==='POST'&&$url==='https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id';
    $export=$method==='GET'&&preg_match('~^https://www\.googleapis\.com/drive/v3/files/[A-Za-z0-9_-]{1,200}/export\?mimeType=text%2Fplain$~D',$url);
    if(!$upload&&!$export)throw new GoogleActionException('Unsupported document operation.');
    $raw='';$ch=curl_init($url);$options=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45,CURLOPT_WRITEFUNCTION=>static function($ch,string $part)use(&$raw):int{if(strlen($raw)+strlen($part)>100000)return 0;$raw.=$part;return strlen($part);}];
    if($upload){$boundary='wn_'.bin2hex(random_bytes(24));$headers[]='Content-Type: multipart/related; boundary='.$boundary;$options[CURLOPT_POSTFIELDS]='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($body['metadata'],JSON_THROW_ON_ERROR)."\r\n--".$boundary."\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n".$body['text']."\r\n--".$boundary."--\r\n";}
    $options[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$options);$ok=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    if($ok===false||$code<200||$code>=300)throw new PublishingRemoteException('Google document request did not complete. Check the saved action before any replacement.',$code,$upload&&($ok===false||$code>=500));
    if(!$upload){if(!mb_check_encoding($raw,'UTF-8'))throw new GoogleActionException('Document read-back was not valid text.');return ['data'=>['text'=>$raw],'status'=>$code];}
    $data=json_decode($raw,true);if(!is_array($data))throw new PublishingRemoteException('Google returned an unreadable creation result.',$code,true);return ['data'=>$data,'status'=>$code];
}
function gwa_http(string $method,string $url,array $headers=[],?array $body=null,string $mode='json'): array {
    return $mode==='document'?gwa_drive_bytes($method,$url,$headers,$body):publishing_http($method,$url,$headers,$body,false,$method!=='GET');
}
function gwa_remote_id(mixed $id,bool $task=false): string {if(!is_string($id)||!preg_match($task?'/^[A-Za-z0-9_=\-]{1,1024}$/D':'/^[A-Za-z0-9_-]{1,200}$/D',$id))throw new GoogleActionException('Google did not return a valid record identifier.');return $id;}
function gwa_verify(array $r,array $h,callable $http): array {
    $id=gwa_remote_id($r['remote_id'],$r['kind']==='task');
    if($r['kind']==='task'){
        $d=$http('GET','https://tasks.googleapis.com/tasks/v1/lists/'.rawurlencode($r['destination']).'/tasks/'.rawurlencode($id),$h)['data'];
        if(($d['id']??'')!==$id||($d['title']??'')!==$r['title']||($d['notes']??'')!==$r['text']||!empty($d['deleted'])||substr((string)($d['due']??''),0,10)!==$r['due']||!in_array($d['status']??'',['needsAction','completed'],true))throw new GoogleActionException('The task no longer matches this action. Review it in Google Tasks.');
        return ['remote_status'=>$d['status'],'url'=>'https://tasks.google.com/'];
    }
    if($r['kind']==='document'){
        $d=$http('GET','https://www.googleapis.com/drive/v3/files/'.$id.'?fields=id,name,mimeType,trashed,ownedByMe,shared,appProperties',$h)['data'];
        if(($d['id']??'')!==$id||($d['name']??'')!==$r['title']||($d['mimeType']??'')!=='application/vnd.google-apps.document'||empty($d['ownedByMe'])||!empty($d['shared'])||!empty($d['trashed'])||($d['appProperties']['waithakaAction']??'')!==$r['id'])throw new GoogleActionException('The private document identity could not be verified.');
        $text=$http('GET','https://www.googleapis.com/drive/v3/files/'.$id.'/export?mimeType=text%2Fplain',$h,null,'document')['data']['text']??null;
        if(!is_string($text)||rtrim(str_replace("\r\n","\n",$text),"\n")!==rtrim($r['text'],"\n"))throw new GoogleActionException('The document content could not be verified.');
        return ['url'=>'https://docs.google.com/document/d/'.$id.'/edit'];
    }
    $meta=$http('GET','https://www.googleapis.com/drive/v3/files/'.$id.'?fields=id,name,mimeType,trashed,ownedByMe,shared',$h)['data'];
    if(($meta['id']??'')!==$id||($meta['name']??'')!==$r['title']||($meta['mimeType']??'')!=='application/vnd.google-apps.spreadsheet'||empty($meta['ownedByMe'])||!empty($meta['shared'])||!empty($meta['trashed']))throw new GoogleActionException('The private spreadsheet identity could not be verified.');
    $d=$http('GET','https://sheets.googleapis.com/v4/spreadsheets/'.$id.'?includeGridData=true&fields=spreadsheetId,properties(title),sheets(properties(title),data(rowData(values(userEnteredValue))))',$h)['data'];
    if(($d['spreadsheetId']??'')!==$id||($d['properties']['title']??'')!==$r['title']||count($d['sheets']??[])!==1||($d['sheets'][0]['properties']['title']??'')!=='Report')throw new GoogleActionException('The spreadsheet structure changed.');
    $blocks=$d['sheets'][0]['data']??[];if(count($blocks)>1)throw new GoogleActionException('The spreadsheet contains unexpected data blocks.');$actual=$blocks[0]['rowData']??[];$expected=gwa_rows($r['text']);foreach($actual as$row)if(count($row['values']??[])>count($expected[0]))throw new GoogleActionException('The spreadsheet contains unexpected columns.');
    foreach($expected as$i=>$row)foreach($row as$j=>$cell){$v=$actual[$i]['values'][$j]['userEnteredValue']??[];if(array_diff(array_keys($v),['stringValue'])||($v['stringValue']??'')!==$cell)throw new GoogleActionException('The spreadsheet cells could not be verified as literal text.');}
    if(count($actual)>count($expected))throw new GoogleActionException('The spreadsheet contains unexpected rows.');
    return ['url'=>'https://docs.google.com/spreadsheets/d/'.$id.'/edit'];
}
function gwa_execute(string $id,int $uid,int $version,?callable $http=null,?callable $tokenProvider=null): array {
    return comm_lock('gwa-'.$id,static function()use($id,$uid,$version,$http,$tokenProvider){
        $r=gwa_owned($id,$uid);gwa_version($r,$version);
        if($r['state']!=='reviewed'||time()-($r['reviewed_at']??0)>1800||!hash_equals($r['approval_hash']??'',gwa_digest($r)))throw new GoogleActionException('Review the exact action again before creating anything.');
        $c=gwa_ready($r['kind'],$uid);if(!hash_equals($r['grant_fingerprint'],gwa_fingerprint($c)))throw new GoogleActionException('The account or destination changed. Prepare again.');
        $h=publishing_bearer($tokenProvider?$tokenProvider($c):publishing_access_token($c));$http??='gwa_http';
        // Verify the selected task list still exists before creating a task.
        if($r['kind']==='task'){$list=$http('GET','https://tasks.googleapis.com/tasks/v1/users/@me/lists/'.rawurlencode($r['destination']),$h)['data'];if(($list['id']??'')!==$r['destination'])throw new GoogleActionException('The selected task list changed.');}
        $fresh=gwa_ready($r['kind'],$uid);if(!hash_equals($r['grant_fingerprint'],gwa_fingerprint($fresh)))throw new GoogleActionException('The connection changed during preparation.');
        $r=gwa_save($r,'executing');$returned=false;
        try{
            $endpoint=match($r['kind']){'document'=>'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id','sheet'=>'https://sheets.googleapis.com/v4/spreadsheets',default=>'https://tasks.googleapis.com/tasks/v1/lists/'.rawurlencode($r['destination']).'/tasks'};
            $response=$http('POST',$endpoint,$h,gwa_payload($r),$r['kind']==='document'?'document':'json');$returned=true;
            $r['remote_id']=gwa_remote_id($response['data'][$r['kind']==='sheet'?'spreadsheetId':'id']??null,$r['kind']==='task');$r=gwa_save($r,'verification_pending');
            $verified=gwa_verify($r,$h,$http);$r=array_merge($r,$verified);$r['outcome']='Created in the confirmed Google account and independently read back.';return gwa_save($r,'done');
        }catch(Throwable $e){$uncertain=$returned||!($e instanceof PublishingRemoteException)||$e->ambiguous||$e->httpStatus===0||$e->httpStatus>=500;$r['outcome']=$uncertain?'Outcome needs review. No creation will be retried. Check Google before preparing a replacement.':'Google rejected creation. Review the connection and prepare a new action.';return gwa_save($r,$uncertain?'unknown':'failed');}
    });
}
function gwa_recheck(string $id,int $uid,int $version,?callable $http=null,?callable $tokenProvider=null): array {
    return comm_lock('gwa-'.$id,static function()use($id,$uid,$version,$http,$tokenProvider){$r=gwa_owned($id,$uid);gwa_version($r,$version);
        if(!in_array($r['state'],['unknown','verification_pending','done'],true)||empty($r['remote_id']))throw new GoogleActionException('There is no known remote record to check. Inspect Google directly.');
        $c=gwa_ready($r['kind'],$uid);if(!hash_equals($r['grant_fingerprint'],gwa_fingerprint($c)))throw new GoogleActionException('Reconnect changes require a separate manual review.');
        $h=publishing_bearer($tokenProvider?$tokenProvider($c):publishing_access_token($c));$verified=gwa_verify($r,$h,$http??'gwa_http');$r=array_merge($r,$verified);$r['outcome']='Existing result independently checked. No new record was created.';return gwa_save($r,'done');
    });
}
function gwa_dispatch(string $path): bool {
    if(!preg_match('~^/write/google/actions(?:/(new|[a-f0-9]{32}))?$~D',$path,$m))return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD','POST']);$uid=(int)current_user()['id'];
    if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}
    $part=$m[1]??'';$record=null;$error='';$values=['kind'=>'document'];
    try{
        if($part!==''&&$part!=='new')$record=gwa_owned($part,$uid);
        if(is_post()){
            verify_csrf();if(!journeys_rate_limit('google-actions',(string)$uid,20,300))throw new GoogleActionException('Please wait before preparing another action.');
            $a=$_POST['action']??'';
            if($a==='prepare'&&$part==='new'){if(($_POST['content_consent']??'')!=='1')throw new GoogleActionException('Confirm the selected content is intended for your Google account.');redirect(url('/write/google/actions/'.gwa_prepare($_POST,$uid)));}
            if(!$record)throw new GoogleActionException('Choose a saved action.');
            $version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT);if($version===false)throw new GoogleActionException('Reload the current action.');
            if($a==='review'){if(($_POST['confirm']??'')!=='1')throw new GoogleActionException('Confirm the account, destination and exact content.');gwa_review($part,$uid,$version);}
            elseif($a==='apply'){if(($_POST['confirm']??'')!=='1')throw new GoogleActionException('Confirm creating this exact record in Google.');gwa_execute($part,$uid,$version);}
            elseif($a==='recheck')gwa_recheck($part,$uid,$version);
            elseif($a==='discard')comm_lock('gwa-'.$part,static function()use($part,$uid,$version){$r=gwa_owned($part,$uid);gwa_version($r,$version);if(!in_array($r['state'],['draft','reviewed'],true))throw new GoogleActionException('Keep attempted actions as evidence.');gwa_save($r,'discarded');});
            else throw new GoogleActionException('Choose an available action.');
            redirect(url('/write/google/actions/'.$part));
        }
    }catch(GoogleActionException $e){$error=$e->getMessage();$values=array_filter($_POST,'is_string')?:$values;}
    catch(Throwable $e){$error='The operation could not be completed. Check the saved action state before continuing.';error_log('Google action failure: '.get_class($e));}
    if($record)$record=gwa_owned($part,$uid);$records=gwa_records($uid);usort($records,static fn($a,$b)=>$b['created_at']<=>$a['created_at']);
    render_write('google_actions',compact('uid','part','record','records','values','error')+['writeTitle'=>'Google action studio']);return true;
}
