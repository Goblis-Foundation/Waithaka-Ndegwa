<?php
declare(strict_types=1);
require_once __DIR__.'/publishing_networks.php';

function publishing_connect_url_valid(string $url): bool {
    if(!publishing_public_url($url))return false;$p=parse_url($url);$host=strtolower($p['host']??'');
    if(!in_array($host,['facebook.com','www.facebook.com','m.facebook.com'],true)||isset($p['fragment']))return false;
    return preg_match('~^/(?:groups/[A-Za-z0-9.]+/?|[A-Za-z0-9.]+/(?:subscribe|subscriptions)/?)$~D',$p['path']??'')===1;
}
function publishing_studio_formats(string $slug): array {
    return match($slug){'facebook'=>['post'=>'Post','video'=>'Video','reel'=>'Reel','story'=>'Story'],'instagram'=>['post'=>'Post','reel'=>'Reel','story'=>'Story'],'youtube'=>['video'=>'Video','short'=>'Short'],default=>['post'=>'Post']};
}
function publishing_studio_format_note(string $slug): string {
    return match($slug){
        'threads'=>'Direct text/link posts and one owned public JPEG/PNG or MP4 through a separate Threads grant. Media waits for processing and a matching owned-post receipt. Carousels and native options use assisted publishing.',
        'telegram'=>'Bot delivery to the exact confirmed channel or supergroup: text, owned public photos or MP4 videos. Telegram returns a sent-message receipt; it does not prove that subscribers read it.',
        'discord'=>'Direct text/link messages and one owned public JPEG/PNG or MP4 up to 10 MB to the verified server/channel webhook. Mentions are disabled. Caption, attachment description and original bytes are checked. Forums and thread destinations remain assisted.',
        'signal'=>'Assisted private-group posting: prepare and download approved content, send it in Signal, then record your private completion reference. No bot, member import or automatic Signal delivery is claimed.',
        'facebook'=>'Profile and Connect: assisted posts, videos, Reels and Stories. Confirm subscriber-only audience in Facebook. A separately confirmed Page supports direct text/link posts and owned MP4 videos/Reels; Page photos and Stories remain assisted.',
        'x'=>'Direct text/link posts; owned MP4 video with separate X media consent. Images, threads, polls and unverified formats use assisted publishing or a verified Buffer route. Review API spending in X.',
        'instagram'=>'Direct JPEG posts and owned-video Reels. Stories, carousels, music, stickers and Instagram Login tags use assisted publishing.',
        'youtube'=>'Owned-video uploads and Shorts, with explicit visibility and audience. YouTube decides Short eligibility. Channel studio manages reviewed captions, thumbnails, playlists and comments.',
        'tiktok'=>'Owned-video delivery through a separately authorized Accounts API and verified media domain. Photo posts, native effects and music use assisted publishing.',
        'linkedin'=>'Direct member text/link posts. Images, documents, video, polls and multi-image posts use assisted publishing or a verified Buffer route.',default=>'Prepare, review and confirm the published result.'};
}

/** Describe the saved declaration without asserting a provider-verified account subtype. */
function publishing_account_type_label(array $channel): string {
    $type=(string)($channel['account_type']??'');
    if (($channel['slug']??'')==='tiktok'&&$type==='professional') return 'professional (owner-declared)';
    return str_replace('_',' ',$type);
}

function publishing_studio_route_status(array $channel): string {
    if(($channel['delivery_method']??'manual')==='manual')return 'Assisted · publication is completed in the platform';
    $service=($channel['delivery_method']??'')==='buffer'?'buffer':publishing_service_for_channel($channel['slug']);$c=publishing_connection($service);
    if(!$c||($c['status']??'')!=='connected'||empty($c['selected_resource']))return 'Connection required · save a draft while account setup is completed';
    $text='Saved connection: '.($c['display_name']??'Confirmed destination').' · review format permissions before approval';
    if($channel['slug']==='x'&&$channel['delivery_method']==='direct'){$media=publishing_connection('x_media');$text.=$media&&($media['status']??'')==='connected'&&!empty($media['selected_resource'])?' · media connection saved':' · separate video connection required';}
    return $text;
}

/** Every stored outcome belongs to a visible follow-up bucket. */
function publishing_delivery_groups(): array {
    return ['attention'=>['label'=>'Need a step','states'=>['assisted','reminder_queued','reminder_sent','failed','reconnect','ambiguous']],
        'processing'=>['label'=>'Awaiting publication','states'=>['processing','delivered']],
        'scheduled'=>['label'=>'Scheduled','states'=>['scheduled']],
        'published'=>['label'=>'Completed in platform','states'=>['published']],
        'cancelled'=>['label'=>'Cancelled','states'=>['cancelled']]];
}
function publishing_delivery_group(string $state): string {
    foreach(publishing_delivery_groups() as $key=>$group)if(in_array($state,$group['states'],true))return $key;
    return 'attention';
}
function publishing_delivery_filters(array $query,array $channels): array {
    $group=is_string($query['delivery']??null)?$query['delivery']:'all';
    if($group!=='all'&&!isset(publishing_delivery_groups()[$group]))$group='all';
    $channel=is_string($query['channel']??null)?$query['channel']:'';
    if(!in_array($channel,array_column($channels,'slug'),true))$channel='';
    $page=is_string($query['page']??null)&&preg_match('/^[1-9][0-9]{0,5}$/D',$query['page'])?(int)$query['page']:1;
    return ['delivery'=>$group,'channel'=>$channel,'page'=>$page];
}
function publishing_delivery_url(array $filters,int $page=1): string {
    return url('/write/publishing').'?'.http_build_query(['delivery'=>$filters['delivery'],'channel'=>$filters['channel'],'page'=>$page],'','&',PHP_QUERY_RFC3986).'#delivery-calendar';
}
function publishing_delivery_overview(array $query,array $channels): array {
    $filters=publishing_delivery_filters($query,$channels);$counts=array_fill_keys(array_keys(publishing_delivery_groups()),0);
    foreach(db()->query('SELECT status,COUNT(*) total FROM publishing_jobs GROUP BY status')->fetchAll() as $row)$counts[publishing_delivery_group((string)$row['status'])]+=(int)$row['total'];
    $where=[];$params=[];
    if($filters['channel']!==''){$where[]='c.slug=?';$params[]=$filters['channel'];}
    if($filters['delivery']!=='all') {
        $groups=publishing_delivery_groups();$states=$groups[$filters['delivery']]['states'];
        if($filters['delivery']==='attention'){
            $states=[];foreach($groups as$key=>$group)if($key!=='attention')$states=array_merge($states,$group['states']);
            $where[]='j.status NOT IN ('.implode(',',array_fill(0,count($states),'?')).')';
        }else $where[]='j.status IN ('.implode(',',array_fill(0,count($states),'?')).')';
        $params=array_merge($params,$states);
    }
    $from=' FROM publishing_jobs j JOIN publishing_revisions r ON r.id=j.revision_id JOIN publishing_packages p ON p.id=r.package_id JOIN publishing_channels c ON c.id=j.channel_id';
    $condition=$where?' WHERE '.implode(' AND ',$where):'';
    $st=db()->prepare('SELECT COUNT(*)'.$from.$condition);$st->execute($params);$total=(int)$st->fetchColumn();
    $pages=max(1,(int)ceil($total/50));$filters['page']=min($filters['page'],$pages);$offset=($filters['page']-1)*50;
    // Unfinished deliveries stay ahead of history; scheduled work is earliest first.
    $st=db()->prepare('SELECT j.*,p.title,p.id package_id,c.name channel_name'.$from.$condition.
        " ORDER BY CASE WHEN j.status IN ('published','cancelled') THEN 3 WHEN j.status='scheduled' THEN 2 WHEN j.status IN ('processing','delivered') THEN 1 ELSE 0 END, CASE WHEN j.status='scheduled' THEN j.scheduled_at END ASC,j.scheduled_at DESC,j.id DESC LIMIT 50 OFFSET ".$offset);
    $st->execute($params);
    return ['jobs'=>$st->fetchAll(),'deliveryCounts'=>$counts,'deliveryFilters'=>$filters,'deliveryTotal'=>$total,'deliveryPages'=>$pages];
}
