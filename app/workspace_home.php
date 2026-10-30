<?php
declare(strict_types=1);

/** Dashboard reads saved local records only; it never refreshes or activates a provider. */
function workspace_home_snapshot(int $uid): array {
    $cfg=comm_config();$owner=(int)($cfg['owner']??0);
    $out=['can_communicate'=>$owner===0||$owner===$uid,'is_owner'=>$owner>0&&$owner===$uid,
        'open'=>null,'overdue'=>null,'devices'=>null,'activities'=>null,'active_total'=>null,
        'timezone'=>'Africa/Nairobi','calendar_observed'=>null];
    if($out['can_communicate'])try{$r=reader_operations_summary();$out['open']=(int)$r['enquiries_open'];$out['overdue']=(int)$r['enquiries_overdue'];}catch(Throwable){}
    try{$out['devices']=count(array_filter(comm_user_devices($uid),static fn($d)=>!empty($d['enabled'])));}catch(Throwable){}
    try{
        $prefs=comm_get('planner.preferences.'.$uid,['timezone'=>'Africa/Nairobi']);$zone=comm_zone($prefs['timezone']??'Africa/Nairobi');
        $out['timezone']=$zone->getName();$date=(new DateTimeImmutable('now',$zone))->format('Y-m-d');
        $rows=array_values(array_filter(planner_day($uid,$date,$out['timezone']),static fn($r)=>!in_array($r['status'],['cancelled','completed'],true)));
        $out['active_total']=count($rows);
        // Ongoing/upcoming work takes priority; past activities remain in the full program.
        $rows=array_values(array_filter($rows,static fn($r)=>$r['end']>time()));
        usort($rows,static fn($a,$b)=>$a['start']<=>$b['start']);$out['activities']=array_slice($rows,0,3);
        $snapshot=comm_get('planner.calendar.'.$uid);$out['calendar_observed']=$snapshot['observed']??null;
    }catch(Throwable){/* Unavailable is not an empty day. */}
    return $out;
}

/** Public-to-private journey pairs contain routes only, never reader or provider data. */
function workspace_home_journeys(): array {
    return [
        ['Writing','/my-thoughts','/write#library','Draft → preview → publish'],
        ['Meetings','/book','/write/meetings','Request → review → confirm'],
        ['Contact','/contact','/write/communication?tab=inbox','Enquiry → follow-up → resolution'],
        ['Subscribers','/newsletter','/write/newsletter','Consent → edition review → delivery'],
        ['Founder stories','/founders','/write/journeys/founders','Submission → exact-copy approval → publication'],
        ['Opportunities','/opportunities','/write/opportunities','Application → private review → response'],
        ['Sponsored publishing','/advertise','/write/desk','Brief → approval → fulfilment'],
        ['Goal 2030','/about#goals-2030','/write/impact','Approved source → evidence → public report'],
        ['Support','/contribute','/write/connections#integrations-finance','Checkout → verified callback → reconciliation'],
    ];
}
