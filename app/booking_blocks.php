<?php
declare(strict_types=1);

/** Owner-entered private availability intervals; never infer location or alter events. */
function booking_block_time(mixed $value,string $zone): int {
    if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value))throw new InvalidArgumentException('Choose a start and end date and time for each block.');
    $tz=comm_zone($zone);$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,$tz);
    if(!$date||$date->format('Y-m-d\TH:i')!==$value)throw new InvalidArgumentException('A block contains an invalid or skipped local time.');
    $nominal=(new DateTimeImmutable($value,new DateTimeZone('UTC')))->getTimestamp();$offsets=[];$matches=0;
    foreach($tz->getTransitions($date->getTimestamp()-86400,$date->getTimestamp()+86400)?:[]as$t)$offsets[$t['offset']]=true;
    foreach(array_keys($offsets)as$offset)if((new DateTimeImmutable('@'.($nominal-$offset)))->setTimezone($tz)->format('Y-m-d\TH:i')===$value)$matches++;
    if($matches>1)throw new InvalidArgumentException('A block boundary falls in a repeated daylight-saving hour. Choose a boundary outside that repeated hour.');
    return $date->getTimestamp();
}
function booking_blocks_parse(mixed $rows): array {
    if(!is_array($rows)||count($rows)>20)throw new InvalidArgumentException('Use at most 20 travel or time-off blocks.');
    $out=[];
    foreach($rows as$r){
        if(!is_array($r))throw new InvalidArgumentException('Review the travel or time-off blocks.');
        if(($r['start']??'')===''&&($r['end']??'')==='')continue;
        $zone=comm_zone($r['timezone']??'')->getName();$start=booking_block_time($r['start']??null,$zone);$end=booking_block_time($r['end']??null,$zone);
        if($end<=$start||$end-$start>90*86400)throw new InvalidArgumentException('Each block must end after it starts and last no more than 90 days.');
        $out[$start.':'.$end]=['start'=>$start,'end'=>$end,'timezone'=>$zone];
    }
    $out=array_values($out);usort($out,static fn($a,$b)=>[$a['start'],$a['end']]<=>[$b['start'],$b['end']]);return $out;
}
function booking_blocks_revision(array $cfg): string {return hash('sha256',json_encode($cfg['travel_blocks']??[],JSON_THROW_ON_ERROR));}
function booking_blocks_update(array $cfg,array $input): array {
    // Old forms do not silently remove blocks added after that form was opened.
    if(($input['travel_blocks_present']??'')!=='1')return $cfg;
    if(!is_string($input['travel_blocks_revision']??null)||!hash_equals(booking_blocks_revision($cfg),$input['travel_blocks_revision']))throw new RuntimeException('Travel or time-off blocks changed. Reload before saving.');
    $cfg['travel_blocks']=booking_blocks_parse($input['travel_blocks']??[]);return $cfg;
}
function booking_blocks_busy(array $cfg): array {
    $rows=$cfg['travel_blocks']??[];
    if(!is_array($rows)||count($rows)>20)throw new RuntimeException('Travel or time-off availability needs review.');
    $busy=[];foreach($rows as$r){
        if(!is_array($r)||!is_int($r['start']??null)||!is_int($r['end']??null)||$r['end']<=$r['start']||$r['end']-$r['start']>90*86400)throw new RuntimeException('Travel or time-off availability needs review.');
        $busy[]=[$r['start'],$r['end']];
    }return $busy;
}
