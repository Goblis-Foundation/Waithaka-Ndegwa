<?php
declare(strict_types=1);
/** One channel inventory for drafts, preparation, schedules and campaign attribution. */
function publishing_networks(): array {return ['linkedin'=>'LinkedIn','facebook'=>'Facebook','instagram'=>'Instagram','x'=>'X','youtube'=>'YouTube','tiktok'=>'TikTok','threads'=>'Threads','telegram'=>'Telegram','discord'=>'Discord','signal'=>'Signal'];}
function publishing_network_limits(): array {return ['linkedin'=>3000,'facebook'=>20000,'instagram'=>2200,'x'=>280,'youtube'=>5000,'tiktok'=>2200,'threads'=>500,'telegram'=>4096,'discord'=>2000,'signal'=>6000];}
function publishing_empty_variant(string $slug,string $title=''): array {return ['enabled'=>false,'text'=>'','media_url'=>'','media_asset_id'=>0,'media_type'=>$slug==='youtube'?'video':'image','alt_text'=>'','title'=>$title,'format'=>$slug==='youtube'?'video':'post','privacy'=>'private','made_for_kids'=>false,'ai_generated'=>false,'notify_subscribers'=>false,'tiktok_allow_comments'=>false,'tiktok_allow_duet'=>false,'tiktok_allow_stitch'=>false,'platform_options'=>[],'tiktok_brand_organic'=>false,'tiktok_branded_content'=>false,'tiktok_settings_confirmed'=>false,'instagram_settings_confirmed'=>false,'instagram_share_to_feed'=>false,'video_settings_confirmed'=>false,'audience'=>'public'];}

function publishing_audience_input(mixed $value): string {if(!is_string($value)||!in_array($value,['public','subscribers'],true))throw new RuntimeException('Choose the exact intended audience. An invalid audience cannot become public.');return $value;}
