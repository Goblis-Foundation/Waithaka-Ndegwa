<?php
declare(strict_types=1);
/** Monthly editorial automation is distinct from giving subscriptions and impact measurement. */
function roundup_zone(): DateTimeZone { return new DateTimeZone('Africa/Nairobi'); }
function roundup_window(?DateTimeImmutable $now=null): array {
    $now=($now??new DateTimeImmutable('now'))->setTimezone(roundup_zone());
    $release=$now->modify('first day of this month')->setTime(0,0,0);
    $start=$release->modify('-1 month');
    return ['key'=>$start->format('Y-m'),'start'=>$start->getTimestamp(),'end'=>$release->getTimestamp(),'release'=>$release->getTimestamp(),'month'=>$start->format('F'),'month_year'=>$start->format('F Y'),'welcome_month'=>$release->format('F'),'welcome_year'=>$release->format('Y'),'release_local'=>$release->format('Y-m-d\TH:i'),'timezone'=>'Africa/Nairobi'];
}
function roundup_key(string $key): string {
    if(!preg_match('/^[2-9][0-9]{3}-(0[1-9]|1[0-2])$/D',$key))throw new InvalidArgumentException('Choose a calendar month.');return $key;
}
function roundup_window_for(string $key): array {
    roundup_key($key);$start=new DateTimeImmutable($key.'-01 00:00:00',roundup_zone());return roundup_window($start->modify('+1 month'));
}
function roundup_due(array $window,int $now,string $first='2026-11-01'): bool {
    $firstDate=new DateTimeImmutable($first.' 00:00:00',roundup_zone());
    return $window['release']>=$firstDate->getTimestamp()&&$now>=$window['release']&&$now<$window['release']+7*86400;
}
function roundup_policy_text(): string {
    return 'Publish one monthly website roundup at 00:00 Africa/Nairobi on the first, starting 1 November 2026, welcoming readers into the new month and reviewing the immediately preceding calendar month. Include only verified public website items and public approved source excerpts. Exclude private drafts, correspondence, contact details, bookings, financial records and unpublished evidence. Never invent events or impact totals, add monthly headcounts together, turn missing reports into zero, or treat receipt dates as measurement dates. Paid features need explicit newsletter permission and visible sponsorship disclosure; the newsletter is not a purchased social round. Email only the existing confirmed eligible newsletter audience, with current suppression/preferences checks and one-click unsubscribe. Do not enrol clients or enquirers automatically. Freeze one edition and audience per month, keep an audit trail, and never resend an uncertain delivery. Sender changes pause email until reviewed. No unrelated queue is released by this automation.';
}
function roundup_policy_hash(): string {return hash('sha256',roundup_policy_text());}
function roundup_public_path(string $key): string {return '/monthly-roundup/'.roundup_key($key);}
function roundup_title(array $window): string {return 'Welcome to '.$window['welcome_month'].' — '.$window['month'].' in review';}
function roundup_item(array $input,array $window,string $origin): ?array {
    $type=$input['type']??'';$types=['article','opportunity','employment','resource','experiment','video','founder','impact','partner'];
    if(!in_array($type,$types,true)||empty($input['public'])||!is_int($input['published']??null)||$input['published']<$window['start']||$input['published']>=$window['end'])return null;
    if(!empty($input['sponsored'])&&empty($input['newsletter_permission']))return null;
    $url=$input['url']??'';$p=is_string($url)?parse_url($url):false;$base=parse_url($origin);
    if(!$p||!$base||($p['scheme']??'')!==($base['scheme']??'')||($p['host']??'')!==($base['host']??'')||($p['port']??null)!==($base['port']??null)||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment']))return null;
    $path=$p['path']??'';
    if(!preg_match('~^/(?:my-thoughts|opportunities|partner-stories|resources|experiments|watch|founders|startup-journeys)/[a-z0-9][a-z0-9/-]*$~D',$path)&&$path!=='/about')return null;
    if(preg_match('~/(?:submit|review|evidence|follow-up|story-review|preview)(?:/|$)~i',$path))return null;
    $title=$input['title']??'';$excerpt=$input['excerpt']??'';
    if(!is_string($title)||!is_string($excerpt)||!mb_check_encoding($title.$excerpt,'UTF-8')||trim(strip_tags($title))==='')return null;
    $out=['type'=>$type,'title'=>mb_substr(trim(strip_tags($title)),0,200),'excerpt'=>mb_substr(trim(strip_tags($excerpt)),0,500),'url'=>$url,'published'=>$input['published'],'closed'=>!empty($input['closed']),'sponsored'=>!empty($input['sponsored'])];
    if($out['sponsored'])$out['sponsor']=mb_substr(trim(strip_tags((string)($input['sponsor']??''))),0,180);
    return $out;
}
function roundup_select(array $candidates,array $window,string $origin): array {
    $items=[];foreach($candidates as$candidate){$item=roundup_item($candidate,$window,$origin);if($item)$items[$item['url']]=$item;}
    uasort($items,static fn($a,$b)=>[$a['published'],$a['url']]<=>[$b['published'],$b['url']]);return array_values($items);
}
function roundup_sections(): array {return ['article'=>'Writing and ideas','opportunity'=>'Opportunities shared','employment'=>'Enterprise opportunities','resource'=>'Practical resources','experiment'=>'Learning and experiments','video'=>'Watch and learn','founder'=>'Founder stories','impact'=>'Approved public progress','partner'=>'Sponsored partner stories'];}
function roundup_escape(string $s): string {return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function roundup_body(array $window,array $items,bool $email=false,string $archive=''): string {
    $esc='roundup_escape';$title='Welcome to '.$window['welcome_month'].' '.$window['welcome_year'].'.';
    $html='<h2>'.$esc($title).'</h2><p>Thank you for being part of this community. As we begin a new month, here is a look back at the public writing, opportunities and practical updates shared in '.$esc($window['month_year']).'.</p>';
    if(!$items)$html.='<p>No new public items were recorded for this edition. Thank you for staying connected; the website remains open for you to explore at your own pace.</p>';
    $shown=0;$cap=$email?30:1000;
    foreach(roundup_sections()as$type=>$heading){$group=array_values(array_filter($items,static fn($r)=>$r['type']===$type));if(!$group||$shown>=$cap)continue;$html.='<h2>'.$esc($heading).'</h2>';
        foreach($group as$item){if($shown++>=$cap)break;$html.='<h3>'.($item['sponsored']?'Sponsored feature: ':'').'<a href="'.$esc($item['url']).'"'.($item['sponsored']?' rel="sponsored noopener"':'').'>'.$esc($item['title']).'</a></h3>';
            if($item['sponsored'])$html.='<p>Paid feature for '.$esc($item['sponsor']).'.</p>';
            if($item['excerpt']!=='')$html.='<p>'.$esc($item['excerpt']).'</p>';
            if($item['closed'])$html.='<p><strong>Closed — included for the monthly record, not as an open application call.</strong></p>';
        }
    }
    if($email&&$archive!=='')$html.='<p><a href="'.$esc($archive).'">Read the complete '.$esc($window['month']).' roundup ('.count($items).' public updates)</a></p>';
    $html.='<h2>Into the new month</h2><p>May '.$esc($window['welcome_month']).' bring useful ideas, thoughtful conversations and opportunities to put learning into practice. Thank you for reading and sharing the work.</p><p>Waithaka Ndegwa<br>People · Ideas · Impact<br>www.waithakandegwa.com · @iwaithakaw</p>';
    return $html;
}
