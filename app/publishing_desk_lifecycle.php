<?php
declare(strict_types=1);

/** Every client notice is scoped to its own record; this never releases legacy mail. */
function desk_register_mail(int $outbox,string $record,string $email,int $expires): void {
    if($outbox<1||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid client notification.');
    comm_put('desk.mail.'.$outbox,['id'=>$outbox,'record'=>desk_id($record),'email'=>$email,'expires'=>$expires]);
}
function desk_mail_work(): void {
    if(app_config('app_env')!=='production')return;
    $ids=[];
    foreach(comm_records('desk.mail',4000)as$m){
        $r=desk_get($m['record']);$valid=$m['expires']>time()&&hash_equals($r['client']['email']??'',$m['email']);
        if(!$valid){db()->prepare("UPDATE email_outbox SET status='suppressed',error_code='desk_notice_obsolete' WHERE id=? AND status='pending'")->execute([$m['id']]);continue;}
        $ids[]=(int)$m['id'];
    }
    if($ids)email_work(10,null,$ids);
}
function desk_round_jobs(int $package): array {
    $q=db()->prepare('SELECT j.* FROM publishing_jobs j JOIN publishing_revisions v ON v.id=j.revision_id WHERE v.package_id=? ORDER BY j.id');$q->execute([$package]);return $q->fetchAll();
}
/** Never replace a partially delivered or uncertain round: it could duplicate paid work. */
function desk_replaceable_round(array $jobs): bool {
    if(!$jobs)return true;
    $published=array_filter($jobs,static fn($j)=>$j['status']==='published');
    if(count($published)===count($jobs))return false;
    if($published)throw new InvalidArgumentException('A round is partially delivered. Reconcile the remaining placement separately rather than duplicating the published post.');
    foreach($jobs as$j)if(!empty($j['remote_id'])||!empty($j['provider_state_json'])||in_array($j['status'],['processing','delivered','ambiguous'],true))throw new InvalidArgumentException('A provider outcome is uncertain or already accepted. Resolve it before replacing any dates.');
    return true;
}
function desk_propose_schedule(string $id,array $in,int $uid): void {
    comm_lock('desk.catalog',function()use($id,$in,$uid){
        $o=desk_order($id);$r=desk_get($o['record']);
        if($o['state']!=='paid'||!empty($o['cancellation_requested'])||!empty($o['settlement']))throw new InvalidArgumentException('Only an unsettled paid campaign may propose replacement slots.');
        $reason=desk_text($in['note']??'','Reason',2000,true);$new=desk_round_times($o['quote'],(string)($in['new_start']??''));
        if($new[0]<time()+86400||desk_closed($r,end($new)))throw new InvalidArgumentException('Allow 24 hours for agreement and keep every date before the offer closes.');
        $indices=[];$times=$o['times'];
        foreach($times as$i=>$old){$jobs=isset($o['campaign'][$i])?desk_round_jobs((int)$o['campaign'][$i]['package']):[];if(desk_replaceable_round($jobs)){$indices[]=$i;$times[$i]=$new[$i];}}
        if(!$indices)throw new InvalidArgumentException('All rounds have been delivered. Create a distribution-only renewal instead.');
        desk_capacity(array_map(static fn($i)=>$times[$i],$indices),$o['quote']['channels'],$id);
        $p=['times'=>$times,'indices'=>$indices,'reason'=>$reason,'created'=>time(),'expires'=>min(time()+72*3600,$new[0]-3600)];$p['hash']=hash('sha256',json_encode([$id,$o['quote']['hash'],$p],JSON_THROW_ON_ERROR));
        $o['paused']=true;$o['schedule_proposal']=$p;$o['audit'][]=['at'=>time(),'by'=>$uid,'action'=>'schedule_proposed'];comm_put('desk.order.'.$id,$o);
        desk_delivery_guard();desk_send_client($r,'Review your replacement publishing dates');
    });
}
function desk_accept_schedule(string $id,string $email,string $hash): void {
    comm_lock('desk.catalog',function()use($id,$email,$hash){
        $o=desk_order($id);$p=$o['schedule_proposal']??null;
        if(!$p||!hash_equals($o['client_email'],$email)||!hash_equals($p['hash'],$hash)||$p['expires']<=time()||$o['state']!=='paid'||!empty($o['cancellation_requested']))throw new InvalidArgumentException('This replacement schedule changed or expired. Request a current proposal.');
        $r=desk_get($o['record']);if(!hash_equals($r['client']['email']??'',$email)||desk_closed($r,max($p['times'])))throw new InvalidArgumentException('Client or offer is no longer eligible.');
        $times=array_map(static fn($i)=>$p['times'][$i],$p['indices']);desk_capacity($times,$o['quote']['channels'],$id);
        foreach($p['indices']as$i){
            if(isset($o['campaign'][$i])){
                $round=$o['campaign'][$i];$jobs=desk_round_jobs((int)$round['package']);
                if(!desk_replaceable_round($jobs))throw new InvalidArgumentException('A round completed while the proposal was open. Request a revised schedule.');
                foreach($jobs as$j)db()->prepare("UPDATE publishing_jobs SET status='cancelled',last_error='Client accepted replacement dates; this revision is retired.' WHERE id=? AND (remote_id IS NULL OR remote_id='')")->execute([$j['id']]);
                $map=comm_get('desk.package.'.$round['package']);$map['retired']=true;comm_put('desk.package.'.$round['package'],$map);
                $o['previous_rounds'][]=$round+['round'=>$i,'retired_at'=>time()];unset($o['campaign'][$i]);
            }
        }
        $o['times']=$p['times'];$o['schedule_history'][]=$p+['accepted_at'=>time(),'accepted_by'=>$email];unset($o['schedule_proposal']);$o['paused']=false;
        comm_put('desk.order.'.$id,$o);
    });
}
/** Safe HTML link metadata is applied after the common sanitizer. */
function desk_sponsored_html(string $html): string {
    $safe=sanitize_html($html);$dom=new DOMDocument();$old=libxml_use_internal_errors(true);
    try{
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$safe.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        foreach($dom->getElementsByTagName('a')as$link)$link->setAttribute('rel','sponsored noopener noreferrer');
        $body=$dom->getElementsByTagName('body')->item(0);$out='';if($body)foreach($body->childNodes as$child)$out.=$dom->saveHTML($child);return $out;
    }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
}
/** Paid package content must match both the approved media and its contracted format. */
function desk_contract_variant(array $r,array $variant,array $channel): void {
    desk_check_variant($r,$variant,$channel['slug']);
    $asset=(int)($variant['media_asset_id']??$variant['media_asset']['id']??0);
    if(($channel['format']??'')==='image'&&(!$asset||($variant['media_type']??'')!=='image'))throw new InvalidArgumentException('This contracted account requires the approved image, not a text-only substitute.');
    if(($channel['format']??'')==='text_link'&&($asset||!empty($variant['media_url'])))throw new InvalidArgumentException('This account was quoted for its supported text/link route. Approve a new contract before changing format.');
}
function desk_mail_registry_gate(int $outbox,array $payload): bool {
    $m=comm_get('desk.mail.'.$outbox);if(!$m)return false;
    $r=desk_get($m['record']);return $m['expires']>time()&&hash_equals($m['email'],(string)($payload['to']??''))&&hash_equals($m['email'],$r['client']['email']??'');
}
