<?php
declare(strict_types=1);
require_once __DIR__.'/reconciliation_register.php';
/** Owner-only evidence. Viewing correspondence never sends, retries or rebrands incoming mail. */
function correspondence_owner(): int {require_auth();$uid=(int)current_user()['id'];if($uid!==(int)(comm_config()['owner']??0))throw new InvalidArgumentException('Owner access required.');return $uid;}
function correspondence_status(string $state): string {
    return ['pending'=>'Queued; not sent','sending'=>'Sending; outcome unconfirmed','sent'=>'Accepted by mail provider; inbox delivery not proved','ambiguous'=>'Unconfirmed; do not resend automatically','suppressed'=>'Suppressed; not sent','failed'=>'Failed; review required','cancelled'=>'Cancelled; not sent','created'=>'Draft accepted; not sent','creating'=>'Draft request in progress','review'=>'Draft outcome needs review'][$state]??'Review status';
}
function correspondence_payload(array $row): array {
    $p=json_decode(decrypt_secret((string)$row['payload']),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($p)||!is_string($p['to']??null)||!is_string($p['subject']??null)||!is_string($p['html']??null))throw new RuntimeException('Protected message unavailable.');return $p;
}
function correspondence_page(array $in,int $uid): array {
    $view=is_string($in['view']??null)?$in['view']:'received';if(!in_array($view,['received','outgoing','drafts'],true))$view='received';
    $page=filter_var($in['page']??1,FILTER_VALIDATE_INT);if(!$page||$page<1||$page>100000)throw new InvalidArgumentException('Choose a valid page.');
    $search=is_string($in['q']??null)?trim($in['q']):'';if(!mb_check_encoding($search,'UTF-8')||mb_strlen($search)>120)throw new InvalidArgumentException('Use a search term up to 120 characters.');
    $rows=[];$total=0;$limit=25;$offset=($page-1)*$limit;
    if($view==='received'){
        $where='NOT EXISTS(SELECT 1 FROM reader_enquiry_trash t WHERE t.enquiry_id=r.id)';$params=[];
        if($search!==''){$where.=' AND (LOCATE(?,c.reference)>0 OR LOCATE(?,c.first_name)>0 OR LOCATE(?,c.last_name)>0 OR LOCATE(?,c.email)>0)';$params=[$search,$search,$search,$search];}
        $from=' FROM reader_enquiries r JOIN contact_messages c ON c.id=r.contact_id WHERE '.$where;
        $q=db()->prepare('SELECT COUNT(*)'.$from);$q->execute($params);$total=(int)$q->fetchColumn();
        $q=db()->prepare('SELECT r.id,r.status,r.created_at,r.last_response_at,c.reference,c.first_name,c.last_name,c.email'.$from.' ORDER BY r.created_at DESC,r.id DESC LIMIT '.$limit.' OFFSET '.$offset);$q->execute($params);$rows=$q->fetchAll();
        foreach($rows as&$row){$q=db()->prepare('SELECT id,status,sent_at FROM email_outbox WHERE dedupe_key=?');$q->execute([hash('sha256','contact-receipt:'.$row['reference'])]);$row['acknowledgement']=$q->fetch()?:null;$q=db()->prepare("SELECT COUNT(*) FROM reader_enquiry_events WHERE enquiry_id=? AND event_type='response_recorded'");$q->execute([(int)$row['id']]);$row['recorded_responses']=(int)$q->fetchColumn();}unset($row);
    }elseif($view==='outgoing'){
        $filter=is_string($in['status']??null)?$in['status']:'';$where='1=1';$params=[];
        if($filter!==''){if(!in_array($filter,['pending','sending','sent','ambiguous','suppressed','failed','cancelled'],true))throw new InvalidArgumentException('Choose a valid email status.');$where='status=?';$params=[$filter];}
        if($search!==''){if(!ctype_digit($search)||strlen($search)>12)throw new InvalidArgumentException('Search outgoing mail by numeric log ID. Recipients and content are encrypted.');$where.=' AND id=?';$params[]=(int)$search;}
        $q=db()->prepare('SELECT COUNT(*) FROM email_outbox WHERE '.$where);$q->execute($params);$total=(int)$q->fetchColumn();$q=db()->prepare('SELECT * FROM email_outbox WHERE '.$where.' ORDER BY id DESC LIMIT '.$limit.' OFFSET '.$offset);$q->execute($params);$rows=$q->fetchAll();
        foreach($rows as&$row){try{$p=correspondence_payload($row);$row['recipient']=$p['to'];$row['subject']=$p['subject'];}catch(Throwable){$row['recipient']='Unavailable';$row['subject']='Protected message could not be read';}unset($row['payload']);}unset($row);
    }else{$drafts=array_values(array_filter(comm_records('draft',2000),static fn($r)=>(int)($r['user']??0)===$uid));usort($drafts,static fn($a,$b)=>($b['at']??0)<=>($a['at']??0));$total=count($drafts);$rows=array_slice($drafts,$offset,$limit);}
    return ['view'=>$view,'page'=>$page,'search'=>$search,'status'=>$filter??'','rows'=>$rows,'total'=>$total,'pages'=>max(1,(int)ceil($total/$limit))];
}
function correspondence_dispatch(string $path): bool {
    if($path!=='/write/correspondence'&&$path!=='/write/reconciliation'&&!preg_match('~^/write/correspondence/email/([1-9][0-9]{0,11})/preview$~D',$path,$m))return false;
    require_auth();private_response_headers();journeys_method(['GET','HEAD']);try{$uid=correspondence_owner();}catch(InvalidArgumentException){http_response_code(403);echo 'Owner access required.';return true;}
    if(!empty($m[1])){$q=db()->prepare('SELECT * FROM email_outbox WHERE id=?');$q->execute([(int)$m[1]]);$row=$q->fetch();if(!$row){http_response_code(404);return true;}
        try{$p=correspondence_payload($row);}catch(Throwable){http_response_code(404);return true;}
        header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'; img-src ".base_url()."; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");header('Referrer-Policy: no-referrer');header('Content-Type: text/html; charset=UTF-8');echo $p['html'];return true;
    }
    if($path==='/write/reconciliation'){render_write('reconciliation',['writeTitle'=>'Implementation register','items'=>reconciliation_items()]);return true;}
    try{render_write('correspondence_log',correspondence_page($_GET,$uid)+['writeTitle'=>'Email & response log','error'=>'']);}
    catch(InvalidArgumentException $e){http_response_code(422);render_write('correspondence_log',['writeTitle'=>'Email & response log','view'=>'received','page'=>1,'pages'=>1,'total'=>0,'rows'=>[],'search'=>'','status'=>'','error'=>$e->getMessage()]);}return true;
}
