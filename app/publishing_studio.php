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
