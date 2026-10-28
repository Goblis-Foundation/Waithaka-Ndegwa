<?php
declare(strict_types=1);
require_once __DIR__.'/../app/google_actions.php';
final class PublishingRemoteException extends RuntimeException {public function __construct(string $m,public readonly int $httpStatus=0,public readonly bool $ambiguous=false){parent::__construct($m);}}
$store=[];$connections=[];$held=[];$writes=0;$mode='ok';$remote=[];$checks=0;
function check(bool $v,string $m):void{$GLOBALS['checks']++;if(!$v)throw new RuntimeException($m);}
function denied(callable $f,string $m):void{try{$f();}catch(GoogleActionException){check(true,$m);return;}throw new RuntimeException($m);}
function publishing_json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR);}
function publishing_decode(string $v):array{return json_decode($v,true,32,JSON_THROW_ON_ERROR);}
function publishing_bearer(string $s):array{return ['Authorization: Bearer '.$s];}
function publishing_connection(string $s):?array{return $GLOBALS['connections'][$s]??null;}
function comm_get(string $k,array $default=[]):array{return $GLOBALS['store'][$k]??$default;}
function comm_put(string $k,array $v):void{$GLOBALS['store'][$k]=$v;}
function comm_date(string $d):string{if(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$d)||date('Y-m-d',strtotime($d))!==$d)throw new RuntimeException();return $d;}
function comm_lock(string $k,callable $f):mixed{check(empty($GLOBALS['held'][$k]),'No recursive lock');$GLOBALS['held'][$k]=true;try{return $f();}finally{unset($GLOBALS['held'][$k]);}}
function setup():void{foreach(['google_drive'=>'drive.file','google_tasks'=>'tasks']as$s=>$scope)$GLOBALS['connections'][$s]=['id'=>$s,'status'=>'connected','connected_by'=>7,'external_id'=>'account-7','display_name'=>'fixture@example.invalid','selected_resource'=>$s==='google_tasks'?'list-7':'account-7','verified_at'=>'2026-10-28 10:00:00','scopes_json'=>json_encode(['https://www.googleapis.com/auth/'.$scope])];}
function transport(string $method,string $url,array $headers=[],?array $body=null,string $kind='json'):array{
    global $writes,$remote,$mode;
    check(in_array('Authorization: Bearer fixture-token',$headers,true),'Expected mock token only');
    if($method==='POST'){
        $writes++;
        if($mode==='timeout')throw new PublishingRemoteException('timeout',0,true);
        if($mode==='denied')throw new PublishingRemoteException('forbidden',403,false);
        $id='result-'.$writes;$remote[$id]=$body;
        return ['data'=>[str_contains($url,'sheets.googleapis.com')?'spreadsheetId':'id'=>$id],'status'=>200];
    }
    check($method==='GET','Verification uses only GET');
    if(str_contains($url,'/users/@me/lists/'))return ['data'=>['id'=>$mode==='list-changed'?'other':'list-7']];
    preg_match('~/result-(\d+)~',$url,$m);$id='result-'.($m[1]??'');$p=$remote[$id]??[];
    if($mode==='read-fails')throw new PublishingRemoteException('unavailable',503,false);
    if(str_contains($url,'tasks.googleapis.com'))return ['data'=>array_merge($p,['id'=>$id,'status'=>$mode==='completed'?'completed':'needsAction'])];
    if(str_contains($url,'/export?'))return ['data'=>['text'=>$mode==='mismatch'?'Different content':$p['text']."\n"]];
    if(str_contains($url,'sheets.googleapis.com')){
        $data=['spreadsheetId'=>$id]+$p;
        if($mode==='extra-column')$data['sheets'][0]['data'][0]['rowData'][0]['values'][]=['userEnteredValue'=>['stringValue'=>'unapproved']];
        if($mode==='extra-block')$data['sheets'][0]['data'][]=$data['sheets'][0]['data'][0];
        if($mode==='formula')$data['sheets'][0]['data'][0]['rowData'][0]['values'][0]['userEnteredValue']=['formulaValue'=>'=1+1'];
        return ['data'=>$data];
    }
    return ['data'=>['id'=>$id,'name'=>$p['metadata']['name']??$p['properties']['title'],'mimeType'=>isset($p['metadata'])?'application/vnd.google-apps.document':'application/vnd.google-apps.spreadsheet','ownedByMe'=>true,'shared'=>$mode==='shared','trashed'=>false,'appProperties'=>$p['metadata']['appProperties']??[]]];
}
$token=static fn()=> 'fixture-token';setup();
$base=['kind'=>'document','title'=>'Private report','text'=>"Reviewed selected text.\nSecond line."];
$id=gwa_prepare($base,7);$r=gwa_owned($id,7);check($writes===0&&$r['state']==='draft','Preparing never writes remotely');
denied(fn()=>gwa_owned($id,8),'Owner isolation');denied(fn()=>gwa_execute($id,7,1,'transport',$token),'Review required');
gwa_review($id,7,1);$r=gwa_owned($id,7);$done=gwa_execute($id,7,$r['version'],'transport',$token);check($done['state']==='done'&&str_contains($done['url'],'docs.google.com/document'),'Document independently read back');
$before=$writes;denied(fn()=>gwa_execute($id,7,$done['version'],'transport',$token),'Completed action cannot replay');check($writes===$before,'No duplicate request');
$check=gwa_recheck($id,7,$done['version'],'transport',$token);check($check['state']==='done'&&$writes===$before,'Recheck is read only');
foreach(['timeout'=>'unknown','denied'=>'failed','read-fails'=>'unknown','mismatch'=>'unknown','shared'=>'unknown']as$testMode=>$expected){
    $mode=$testMode;$id=gwa_prepare($base,7);gwa_review($id,7,1);$r=gwa_owned($id,7);$r=gwa_execute($id,7,$r['version'],'transport',$token);check($r['state']===$expected,$testMode.' preserves truthful outcome');$before=$writes;denied(fn()=>gwa_execute($id,7,$r['version'],'transport',$token),'No automatic retry after '.$testMode);check($writes===$before,'No extra mutation');
    if($testMode==='read-fails'){$mode='ok';$r=gwa_recheck($id,7,$r['version'],'transport',$token);check($r['state']==='done'&&$writes===$before,'Unknown result recoverable with independent read');}
}
$mode='ok';$id=gwa_prepare($base,7);gwa_review($id,7,1);$r=gwa_owned($id,7);$r['text']='tampered';comm_put('google.action.'.$id,$r);denied(fn()=>gwa_execute($id,7,$r['version'],'transport',$token),'Immutable approval digest');
$id=gwa_prepare($base,7);gwa_review($id,7,1);$r=gwa_owned($id,7);$r['reviewed_at']=time()-1801;comm_put('google.action.'.$id,$r);denied(fn()=>gwa_execute($id,7,$r['version'],'transport',$token),'Expired review blocked');gwa_review($id,7,$r['version']);check(gwa_owned($id,7)['reviewed_at']>=time()-1,'Review can be explicitly renewed');
$id=gwa_prepare($base,7);gwa_review($id,7,1);$r=gwa_owned($id,7);$connections['google_drive']['external_id']='other-account';denied(fn()=>gwa_execute($id,7,$r['version'],'transport',$token),'Identity change blocks write');setup();
$connections['google_drive']['connected_by']=8;denied(fn()=>gwa_prepare($base,7),'Cannot use another owner grant');setup();$connections['google_drive']['scopes_json']='[]';denied(fn()=>gwa_prepare($base,7),'Actual Drive scope required');setup();
$sheet=['kind'=>'sheet','title'=>'Aggregate report','text'=>"Metric\tValue\n=IMPORTXML(\"https://example.invalid\")\t@literal\n\t0"];
$id=gwa_prepare($sheet,7);$r=gwa_owned($id,7);$p=gwa_payload($r);check(isset($p['sheets'][0]['data'][0]['rowData'][1]['values'][0]['userEnteredValue']['stringValue']),'Formula-looking content stored as a string');gwa_review($id,7,1);$r=gwa_owned($id,7);$r=gwa_execute($id,7,$r['version'],'transport',$token);check($r['state']==='done','Sheet read-back verifies cells');
$mode='formula';$id=gwa_prepare($sheet,7);gwa_review($id,7,1);$r=gwa_owned($id,7);$r=gwa_execute($id,7,$r['version'],'transport',$token);check($r['state']==='unknown','Unexpected provider formula cannot be certified');$mode='ok';
$task=['kind'=>'task','title'=>'Review programme','text'=>'Private task notes','due'=>'2026-11-04'];$id=gwa_prepare($task,7);gwa_review($id,7,1);$r=gwa_owned($id,7);$r=gwa_execute($id,7,$r['version'],'transport',$token);check($r['state']==='done'&&$r['remote_status']==='needsAction','Task created in the exact selected list');$before=$writes;$mode='completed';$r=gwa_recheck($id,7,$r['version'],'transport',$token);check($r['remote_status']==='completed'&&$writes===$before,'External task completion read back without local overwrite');$mode='ok';
$id=gwa_prepare($task,7);gwa_review($id,7,1);$r=gwa_owned($id,7);$mode='list-changed';$before=$writes;denied(fn()=>gwa_execute($id,7,$r['version'],'transport',$token),'Missing list blocks writes');check($writes===$before,'No write after list mismatch');$mode='ok';
denied(fn()=>gwa_prepare(array_replace($base,['kind'=>'arbitrary']),7),'Unsupported operation rejected');denied(fn()=>gwa_prepare(array_replace($base,['title'=>"bad\nheader"]),7),'Single line title');denied(fn()=>gwa_prepare(array_replace($task,['due'=>'2026-02-30']),7),'Invalid task date');denied(fn()=>gwa_prepare(array_replace($sheet,['text'=>str_repeat("row\n",201)]),7),'Row limit');denied(fn()=>gwa_prepare(array_replace($sheet,['text'=>str_repeat("cell\t",20).'last']),7),'Column limit');denied(fn()=>gwa_prepare(array_replace($base,['text'=>str_repeat('x',60001)]),7),'Content limit');
foreach(['extra-column','extra-block']as$mode){$id=gwa_prepare($sheet,7);gwa_review($id,7,1);$r=gwa_execute($id,7,2,'transport',$token);check($r['state']==='unknown','Unexpected extra sheet content is never certified');}$mode='ok';
check(gwa_remote_id('opaque_Task_id==',true)==='opaque_Task_id==','Opaque padded Task identifiers supported');denied(fn()=>gwa_remote_id('../unsafe',true),'Unsafe task paths rejected');denied(fn()=>gwa_remote_id('drive=='),'Drive IDs retain strict path validation');
echo 'PASS: '.$checks." Google action ownership, exact approval, literal cells, read-back and no-retry checks. No provider called.\n";
