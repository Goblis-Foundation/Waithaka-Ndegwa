<?php
declare(strict_types=1);
/** Privacy-safe monitoring of preparation that runs independently from delivery. */
function preparation_names(): array {return ['editorial'=>'Editorial plan and private newsletter drafts','audience'=>'Aggregate audience refresh','impact'=>'Approved impact-source refresh','calendars'=>'Selected-calendar refresh'];}
function preparation_stage(string $stage,callable $work): mixed {
    if(!isset(preparation_names()[$stage]))throw new InvalidArgumentException('Unknown preparation stage.');
    $started=time();$save=static function(array $r)use($stage):void{try{comm_put('preparation.status.'.$stage,$r);}catch(Throwable){error_log('Preparation monitoring unavailable.');}};
    $save(['started'=>$started,'state'=>'running']);
    try{$result=$work();$state=is_array($result)&&isset($result['enabled'])&&!$result['enabled']?'disabled':'completed';if(is_array($result)&&!empty($result['failed']))$state='attention';$safe=[];if(is_array($result))foreach(['checked','failed','prepared']as$key)if(isset($result[$key])&&(is_bool($result[$key])||is_int($result[$key])))$safe[$key]=$result[$key];$save(['started'=>$started,'finished'=>time(),'state'=>$state,'counts'=>$safe]);return $result;}
    catch(Throwable $e){$save(['started'=>$started,'finished'=>time(),'state'=>'attention']);throw $e;}
}
function preparation_status(): array {
    $rows=[];foreach(preparation_names()as$key=>$name){try{$r=comm_get('preparation.status.'.$key);}catch(Throwable){$r=[];}$rows[]=['name'=>$name,'state'=>$r['state']??'not_recorded','at'=>(int)($r['finished']??$r['started']??0)];}return $rows;
}
