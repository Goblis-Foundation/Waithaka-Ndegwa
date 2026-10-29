<?php
declare(strict_types=1);
require_once __DIR__.'/../app/google_meet.php';
$store=[];$connection=[];$requests=[];$mode='ok';$checks=0;$held=[];$authenticated=true;$actor=7;$post=false;$csrf=true;$rendered=[];
function check(bool $ok,string $message):void{$GLOBALS['checks']++;if(!$ok)throw new RuntimeException($message);}
function denied(callable $fn,string $message):void{try{$fn();}catch(GoogleMeetException){check(true,$message);return;}throw new RuntimeException($message);}
function publishing_connection(string $s):?array{return $GLOBALS['connection']?:null;}
function publishing_decode(string $s):array{return json_decode($s,true,32,JSON_THROW_ON_ERROR);}
function publishing_bearer(string $s):array{return ['Authorization: Bearer '.$s];}
function publishing_access_token(array $c):string{return 'fixture-token';}
function comm_get(string $k,array $default=[]):array{return $GLOBALS['store'][$k]??$default;}
function comm_put(string $k,array $v):void{$GLOBALS['store'][$k]=$v;}
function comm_lock(string $k,callable $f):mixed{check(empty($GLOBALS['held'][$k]),'No recursive lock');$GLOBALS['held'][$k]=true;try{return $f();}finally{unset($GLOBALS['held'][$k]);}}
function setup():void{$GLOBALS['connection']=['id'=>18,'status'=>'connected','connected_by'=>7,'external_id'=>'account-7','display_name'=>'fixture@example.invalid','selected_resource'=>'account-7','verified_at'=>'2026-10-29 20:00:00','scopes_json'=>'["https://www.googleapis.com/auth/meetings.space.readonly"]'];$GLOBALS['store']=[];$GLOBALS['requests']=[];$GLOBALS['mode']='ok';}
function artifact(string $kind='transcript'):array{return ['name'=>'conferenceRecords/conf-7/'.($kind==='transcript'?'transcripts/transcript-7':'recordings/recording-7'),'state'=>'FILE_GENERATED','startTime'=>'2026-10-29T10:00:00Z','endTime'=>'2026-10-29T11:00:00Z','docsDestination'=>['document'=>'doc-7','exportUri'=>'https://untrusted.invalid/ignored'],'driveDestination'=>['file'=>'video-7','exportUri'=>'javascript:ignored']];}
function entry(string $id='1'):array{return ['name'=>'conferenceRecords/conf-7/transcripts/transcript-7/entries/entry-'.$id,'participant'=>'conferenceRecords/conf-7/participants/speaker-7','text'=>'<script>untrusted text</script>','startTime'=>'2026-10-29T10:00:00.001Z','endTime'=>'2026-10-29T10:00:01.001Z'];}
function publishing_http(string $method,string $url,array $headers=[]):array{
    global $mode,$requests,$connection;
    check($method==='GET','Every provider operation is read only');check(str_starts_with($url,'https://meet.googleapis.com/v2/conferenceRecords'),'Fixed Meet host and resource root');check($headers===['Authorization: Bearer fixture-token'],'Token stays on the Meet host');$requests[]=$url;
    if($mode==='permission')return ['status'=>403,'data'=>['error'=>['message'=>'denied']]];
    $path=parse_url($url,PHP_URL_PATH);parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    if($path==='/v2/conferenceRecords'){check(isset($query['filter'])&&str_contains($query['filter'],'end_time'),'Discovery bounded to ended meetings');return ['data'=>['conferenceRecords'=>[['name'=>'conferenceRecords/conf-7','startTime'=>'2026-10-29T10:00:00Z','endTime'=>'2026-10-29T11:00:00Z']]]];}
    if(str_ends_with($path,'/recordings'))return ['data'=>['recordings'=>[artifact('recording')]]];
    if(str_ends_with($path,'/transcripts'))return ['data'=>['transcripts'=>[artifact()]]];
    if(str_ends_with($path,'/transcript-7')){$r=artifact();if($mode==='not-ready')$r['state']='ENDED';if($mode==='wrong-artifact')$r['name']='conferenceRecords/conf-7/transcripts/other';if($mode==='other-meeting')$r['name']='conferenceRecords/other/transcripts/transcript-7';return ['data'=>$r];}
    check(str_ends_with($path,'/entries'),'Only selected transcript entries are fetched');$r=entry();
    if($mode==='identity-change')$connection['external_id']='changed';
    if($mode==='malformed')$r['name']='conferenceRecords/other/transcripts/other/entries/1';
    if($mode==='oversized')$r['text']=str_repeat('x',250001);
    if($mode==='control')$r['text']="bad\x00text";
    if($mode==='timestamp')$r['startTime']='not-a-timestamp';
    if($mode==='speaker')$r['participant']='https://outside.invalid';
    if($mode==='empty')return ['data'=>[]];
    if($mode==='duplicates')return ['data'=>['transcriptEntries'=>[$r,$r]]];
    if($mode==='repeat-page')return ['data'=>['transcriptEntries'=>[$r],'nextPageToken'=>'again']];
    if($mode==='too-many-pages')return ['data'=>['transcriptEntries'=>[],'nextPageToken'=>(string)count($requests)]];
    if($mode==='bad-token')return ['data'=>['transcriptEntries'=>[$r],'nextPageToken'=>['bad']]];
    if($mode==='bad-list')return ['data'=>['transcriptEntries'=>'not-a-list']];
    if($mode==='pages'){if(!isset($query['pageToken']))return ['data'=>['transcriptEntries'=>[$r],'nextPageToken'=>'page/2?&']];check($query['pageToken']==='page/2?&','Opaque tokens encoded safely');return ['data'=>['transcriptEntries'=>[entry('2')]]];}
    return ['data'=>['transcriptEntries'=>[$r]],'status'=>200];
}
setup();$fingerprint=gmeet_fingerprint($connection);$name=artifact()['name'];
$discovery=gmeet_discover(7);check(count($discovery['records'])===1,'Ended meeting discovered');check($store===[],'Discovery persists no meeting content');
$selection=gmeet_artifacts('conferenceRecords/conf-7',7,$fingerprint);check(count($selection['artifacts'])===2,'Both artifact types read');check($selection['artifacts'][0]['url']==='https://drive.google.com/file/d/video-7/view','Recording link built from strict ID');check($selection['artifacts'][1]['url']==='https://docs.google.com/document/d/doc-7/edit','Transcript link built from strict ID');check($store===[],'Artifact discovery stores nothing');
$mode='pages';$id=gmeet_import($name,7,$fingerprint);$copy=gmeet_owned($id,7);check(count($copy['entries'])===2,'All transcript pages imported');check($copy['entries'][0]['speaker']===1&&$copy['entries'][1]['speaker']===1,'Stable anonymous speaker labels');check(!str_contains(json_encode($copy),'participants/'),'Participant directory references not retained');$before=count($requests);check(gmeet_import($name,7,$fingerprint)===$id&&count($requests)===$before,'Duplicate submission reuses exact saved copy without remote access');denied(fn()=>gmeet_owned($id,8),'Saved copy owner isolation');denied(fn()=>gmeet_remove($id,8),'Cannot remove another owner copy');check(count(gmeet_records(7))===1&&gmeet_records(8)===[],'Indexes isolate owners');
$connection['status']='disconnected';check(gmeet_owned($id,7)['id']===$id,'Owner can read retained copy after disconnect');gmeet_remove($id,7);check(gmeet_records(7)===[]&&comm_get('meet.copy.'.$id)===[],'Removal clears local content and index');check(count($requests)===$before,'Removal never deletes the Google original');
foreach(['permission','not-ready','wrong-artifact','other-meeting','identity-change','malformed','oversized','control','timestamp','speaker','empty','duplicates','repeat-page','too-many-pages','bad-token','bad-list']as$scenario){setup();$mode=$scenario;denied(fn()=>gmeet_import($name,7,gmeet_fingerprint($connection)),$scenario.' rejected');check($store===[],$scenario.' persists no partial transcript');}
setup();foreach(['https://outside.invalid/','conferenceRecords/../x','conferenceRecords/conf-7?token=secret','conferenceRecords/conf-7/transcripts/t/entries/e']as$bad)denied(fn()=>gmeet_import($bad,7,$fingerprint),'Unsafe selected resource rejected');check($requests===[],'Invalid names do not trigger requests');
foreach(['connected_by'=>8,'selected_resource'=>'other-account','external_id'=>'other-account','scopes_json'=>'[]','status'=>'pending_confirmation','verified_at'=>'changed']as$field=>$value){setup();$connection[$field]=$value;denied(fn()=>gmeet_import($name,7,$fingerprint),'Changed '.$field.' rejected');check($requests===[],'Grant check precedes requests');}
setup();$bad=artifact();$bad['docsDestination']['document']='../../unsafe';check(gmeet_artifact($bad,'transcript','conferenceRecords/conf-7')['url']==='','Invalid destination has no link');
// Dispatch boundaries: authorization, owner restriction, CSRF, explicit consent and no GET reads.
function require_auth():void{if(!$GLOBALS['authenticated'])throw new LogicException('Login required');}
function private_response_headers():void{}
function journeys_method(array $methods):void{}
function current_user():array{return ['id'=>$GLOBALS['actor']];}
function comm_config():array{return ['owner'=>7];}
function is_post():bool{return $GLOBALS['post'];}
function verify_csrf():void{if(!$GLOBALS['csrf'])throw new GoogleMeetException('Invalid CSRF');}
function journeys_rate_limit(string $a,string $b,int $c,int $d):bool{return true;}
function render_write(string $view,array $values):void{$GLOBALS['rendered']=$values;}
setup();$_POST=[];check(gmeet_dispatch('/write/google/meet'),'Route handled');check($requests===[],'GET never fetches provider data');check(!gmeet_dispatch('/write/google/actions'),'Unrelated routes unchanged');
$authenticated=false;try{gmeet_dispatch('/write/google/meet');throw new RuntimeException('Anonymous request accepted');}catch(LogicException){check(true,'Authentication required');}$authenticated=true;
$actor=8;ob_start();gmeet_dispatch('/write/google/meet');ob_end_clean();check(http_response_code()===403&&$requests===[],'Non-owner denied before any provider call');$actor=7;http_response_code(200);
$post=true;$csrf=false;$_POST=['action'=>'discover'];gmeet_dispatch('/write/google/meet');check($requests===[]&&$rendered['error']==='Invalid CSRF','Invalid CSRF blocks discovery');$csrf=true;
$_POST=['action'=>'import','transcript'=>$name,'fingerprint'=>$fingerprint];gmeet_dispatch('/write/google/meet');check($requests===[]&&$store===[],'Import requires explicit retention consent');
// Render literal untrusted content and ensure links/forms retain their review boundaries.
function e(mixed $s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function asset(string $s):string{return '/assets/'.$s;}
function url(string $s):string{return $s;}
function csrf_field():string{return '<input type="hidden" name="csrf" value="fixture">';}
$record=$copy;$discovery=null;$selection=null;$error='';$connected=$connection;$records=[$copy];ob_start();include __DIR__.'/../views/write/google_meet.php';$html=ob_get_clean();check(!str_contains($html,'<script>untrusted'),'Provider text is escaped');check(str_contains($html,'&lt;script&gt;untrusted'),'Original text remains readable');check(str_contains($html,'name="confirm" value="1" required'),'Local removal requires confirmation');
echo 'PASS: '.$checks." selected Meet import, pagination, ownership, consent, encoding and privacy checks. No real provider called.\n";
