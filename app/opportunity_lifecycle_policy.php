<?php
declare(strict_types=1);
/** Deterministic dates and retention; never infer an organiser timezone from the reader. */
function ol_seed(array $r): array {
    static $seeds;
    if($seeds===null){$file=__DIR__.'/content/opportunity-lifecycle-sources.json';$seeds=is_file($file)?json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR):[];}
    return array_replace($seeds[$r['slug']??'']??[],is_array($r['lifecycle']??null)?$r['lifecycle']:[]);
}
function ol_date_valid(string $date): bool {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
    return $d!==false&&$d->format('Y-m-d')===$date;
}
function ol_boundary(array $r): array {
    $f=$r['fields']??[];
    if(($r['kind']??'')!=='opportunity')return ['at'=>$f['offer_expires']??null,'precision'=>'exact'];
    if(isset($f['deadline']))return ['at'=>(int)$f['deadline'],'precision'=>'exact'];
    $seed=ol_seed($r);$date=(string)(($f['deadline_date']??'')!==''?$f['deadline_date']:($seed['date']??''));
    if($date===''||!ol_date_valid($date))return ['at'=>null,'precision'=>'unknown'];
    // UTC-12 is the last civil timezone to finish a calendar day. This is an
    // editorial expiry boundary, NOT an invented organiser cutoff time.
    $at=(new DateTimeImmutable($date.'T00:00:00+00:00'))->modify('+1 day +12 hours')->getTimestamp();
    return ['at'=>$at,'precision'=>($seed['reported']??false)?'reported_date':'date','date'=>$date];
}
function ol_closed(array $r,?int $now=null): bool {
    $now??=time();if(in_array($r['state']??'',['closed','withdrawn'],true))return true;
    $at=ol_boundary($r)['at'];return $at!==null&&$at<=$now;
}
function ol_closed_at(array $r): ?int {
    if(($r['state']??'')==='withdrawn')return null;
    $seed=ol_seed($r);$at=ol_boundary($r)['at'];
    if(($seed['close_reason']??'')==='manual')return isset($seed['closed_at'])?(int)$seed['closed_at']:null;
    if($at!==null)return $at;
    return isset($seed['closed_at'])?(int)$seed['closed_at']:null;
}
function ol_archived(array $r,?int $now=null): bool {
    $now??=time();if(($r['kind']??'')!=='opportunity'||!empty($r['fields']['sponsored'])||($r['state']??'')==='withdrawn')return false;
    $at=ol_closed_at($r);return ol_closed($r,$now)&&$at!==null&&$now>=$at+90*86400;
}
function ol_public_record(array $r): array {
    if(isset($r['public']['fields']))$r['fields']=$r['public']['fields'];return $r;
}
function ol_status(array $r,?int $now=null): string {
    $now??=time();if(($r['state']??'')==='withdrawn')return 'Withdrawn';
    if(ol_archived($r,$now))return 'Archived';
    $b=ol_boundary($r);
    if(ol_closed($r,$now))return $b['precision']==='exact'?'Closed':'Closed - published date passed';
    if($b['at']!==null&&$b['at']-$now<=7*86400)return $b['precision']==='exact'?'Closing soon':'Closing soon - confirm cutoff';
    if(!empty($r['fields']['review_notice'])||$b['precision']==='unknown')return 'Confirm details';
    return 'Open';
}
function ol_interval(array $r,?int $now=null): ?int {
    $now??=time();if(($r['state']??'')==='withdrawn'||empty($r['public_visible'])||ol_archived($r,$now))return null;
    $seed=ol_seed($r);if(($seed['close_reason']??'')==='manual')return null;
    $at=ol_closed_at($r);$closed=ol_closed($r,$now);
    if($closed)return $at!==null&&$now-$at<=14*86400?86400:7*86400;
    $deadline=ol_boundary($r)['at'];return $deadline!==null&&$deadline-$now<=7*86400?6*3600:86400;
}
function ol_directory_match(array $r,string $status,?int $now=null): bool {
    if(($r['kind']??'')!=='opportunity')return $status==='closed'?ol_closed($r,$now):($status==='all'||!ol_closed($r,$now));
    $archived=ol_archived($r,$now);if($status==='archived')return $archived;if($archived)return false;
    $closed=ol_closed($r,$now);if($status==='closed')return $closed;if($status==='all')return true;if($closed)return false;
    if($status==='soon'){$at=ol_boundary($r)['at'];return $at!==null&&$at<=($now??time())+7*86400;}
    return true;
}
/** No active invitation remains in the body of an expired listing. Sources remain readable. */
function ol_public_body(string $body,array $r,?int $now=null): string {
    if(($r['kind']??'')!=='opportunity'||!ol_closed($r,$now))return $body;
    $dom=new DOMDocument();$old=libxml_use_internal_errors(true);
    try{$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$body.'</body></html>',LIBXML_NONET);
        foreach(iterator_to_array($dom->getElementsByTagName('a'))as$a){
            if($a->getAttribute('href')===($r['fields']['apply_url']??'')&&preg_match('/apply|application|register|submit/i',$a->textContent)){
                $span=$dom->createElement('span');$span->appendChild($dom->createTextNode('Applications closed. See the official source for any later round.'));$a->parentNode->replaceChild($span,$a);
            }
        }
        $out='';foreach($dom->getElementsByTagName('body')->item(0)->childNodes as$node)$out.=$dom->saveHTML($node);return $out;
    }finally{libxml_clear_errors();libxml_use_internal_errors($old);}
}
