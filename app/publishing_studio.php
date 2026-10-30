<?php
declare(strict_types=1);

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
        'facebook'=>'Profile and Connect: assisted posts, videos, Reels and Stories. Confirm subscriber-only audience in Facebook. A separately confirmed Page supports direct text/link posts and owned MP4 videos/Reels; Page photos and Stories remain assisted.',
        'x'=>'Direct text/link posts; owned MP4 video with separate X media consent. Images, threads, polls and unverified formats use assisted publishing or a verified Buffer route. Review API spending in X.',
        'instagram'=>'Direct JPEG posts and owned-video Reels. Stories, carousels, music, stickers and Instagram Login tags use assisted publishing.',
        'youtube'=>'Owned-video uploads and Shorts, with explicit visibility and audience. YouTube decides Short eligibility. Channel studio manages reviewed captions, thumbnails, playlists and comments.',
        'tiktok'=>'Owned-video delivery through a separately authorized Accounts API and verified media domain. Photo posts, native effects and music use assisted publishing.',
        'linkedin'=>'Direct member text/link posts. Images, documents, video, polls and multi-image posts use assisted publishing or a verified Buffer route.',default=>'Prepare, review and confirm the published result.'};
}
