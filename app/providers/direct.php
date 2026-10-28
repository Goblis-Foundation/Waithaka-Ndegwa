<?php
declare(strict_types=1);

/** Direct adapters intentionally expose only formats implemented end-to-end here.
 * LinkedIn Posts API: https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
 * X: https://docs.x.com/x-api/posts/create-post
 * Instagram: https://developers.facebook.com/documentation/instagram-platform/content-publishing
 * Pages: https://developers.facebook.com/documentation/pages-api/posts
 */
/** Official LinkedIn Posts API (versioned), not legacy /v2/ugcPosts. */
function publishing_linkedin_posts_headers(): array {
 return ['X-Restli-Protocol-Version: 2.0.0','Linkedin-Version: 202608'];
}
function publishing_linkedin_posts_payload(string $member,string $text): array {
 if(!preg_match('/^[a-zA-Z0-9_-]{1,120}$/D',$member))
  throw new InvalidArgumentException('The confirmed LinkedIn member identifier is invalid. Reconnect this account.');
 if(trim($text)===''||mb_strlen($text)>3000)
  throw new InvalidArgumentException('LinkedIn requires reviewed text of 1–3,000 characters.');
 return ['author'=>'urn:li:person:'.$member,
  'commentary'=>$text,
  'visibility'=>'PUBLIC',
  'distribution'=>['feedDistribution'=>'MAIN_FEED','targetEntities'=>[],'thirdPartyDistributionChannels'=>[]],
  'lifecycleState'=>'PUBLISHED',
  'isReshareDisabledByAuthor'=>false];
}
/** Successful 201 response alone cannot establish the post permalink without x-restli-id. */
function publishing_linkedin_posts_receipt(array $response): array {
 $id=(string)($response['headers']['x-restli-id']??'');
 if(($response['status']??0)!==201||!preg_match('/^urn:li:(?:share|ugcPost):[0-9]{5,25}$/D',$id))
  throw new PublishingRemoteException('LinkedIn did not confirm a valid post ID. Check your personal profile before another attempt.',0,true);
 return ['status'=>'published','remote_id'=>$id,'external_url'=>'https://www.linkedin.com/feed/update/'.rawurlencode($id).'/'];
}

function publishing_direct_deliver(array $job, array $snapshot, array $connection): array {
    $destination = publishing_decode($job['destination_json']);
    if ($destination['slug'] === 'youtube') return publishing_youtube_deliver($job,$snapshot,$connection);
    if ($destination['slug'] === 'tiktok') return publishing_tiktok_business_deliver($job,$snapshot,$connection);
    $variant = $snapshot['variants'][$destination['slug']];
    $headers = publishing_bearer(publishing_access_token($connection));
    $target = (string)$destination['resource'];
    if ($destination['slug'] === 'x') {
        $response = publishing_http('POST','https://api.x.com/2/tweets',$headers,(['text'=>$variant['text']]+(!empty($variant['platform_options']['location_id'])?['geo'=>['place_id'=>$variant['platform_options']['location_id']]]:[])),false,true)['data'];
        $id = (string)($response['data']['id'] ?? '');
        if ($id === '') throw new PublishingRemoteException('X did not return a post ID. Check your profile before retrying.',0,true);
        return ['status'=>'published','remote_id'=>$id,'external_url'=>'https://x.com/i/web/status/'.rawurlencode($id)];
    }
    if ($destination['slug'] === 'linkedin') {
        $headers=array_merge($headers,publishing_linkedin_posts_headers());
        $response=publishing_http('POST','https://api.linkedin.com/rest/posts',$headers,
            publishing_linkedin_posts_payload($target,$variant['text']),false,true);
        return publishing_linkedin_posts_receipt($response);
    }
    if ($destination['slug'] === 'facebook') {
        if ($destination['account_type'] !== 'page') throw new RuntimeException('Direct Facebook publishing is only enabled for a confirmed Page.');
        $response = publishing_http('POST','https://graph.facebook.com/'.publishing_meta_version().'/'.rawurlencode($target).'/feed',$headers,(['message'=>$variant['text']]+(!empty($variant['platform_options']['location_id'])?['place'=>$variant['platform_options']['location_id']]:[])),true,true)['data'];
        $id = (string)($response['id'] ?? '');
        if ($id === '') throw new PublishingRemoteException('Facebook did not return a post ID. Check the Page before retrying.',0,true);
        return ['status'=>'published','remote_id'=>$id,'external_url'=>'https://www.facebook.com/'.rawurlencode($id)];
    }
    if ($destination['slug'] === 'instagram') {
        $base = 'https://graph.instagram.com/'.publishing_meta_version();
        $result = publishing_http('POST',$base.'/'.rawurlencode($target).'/media',$headers,social_instagram_parameters($variant),true,false)['data'];
        if (empty($result['id'])) throw new RuntimeException('Instagram did not create an image container.');
        // Container creation does not publish. Persist its ID before the separate publishing request.
        $state = ['container_id'=>(string)$result['id'],'phase'=>'container_ready'];
        publishing_job_update((int)$job['id'],['provider_state_json'=>publishing_json($state)]);
        $job['provider_state_json'] = publishing_json($state);
        return publishing_instagram_check($job,$connection);
    }
    throw new RuntimeException('Choose Buffer or assisted publishing for this channel.');
}

function publishing_direct_check(array $job, array $connection): array {
    $destination=publishing_decode($job['destination_json']);
    return match($destination['slug']??'') {
        'youtube'=>publishing_youtube_check($job,$connection),
        'tiktok'=>publishing_tiktok_business_check($job,$connection),
        'instagram'=>publishing_instagram_check($job,$connection),
        default=>throw new PublishingRemoteException('This direct delivery has no pending provider status to check. Review its existing remote post.',422),
    };
}

function publishing_instagram_check(array $job, array $connection): array {
    $state = publishing_decode($job['provider_state_json']);
    if (empty($state['container_id']) || ($state['phase'] ?? '') !== 'container_ready') throw new PublishingRemoteException('Review the Instagram post before another publish attempt.',0,true);
    $headers = publishing_bearer(publishing_access_token($connection));
    $base = 'https://graph.instagram.com/'.publishing_meta_version();
    $status = publishing_http('GET',$base.'/'.rawurlencode($state['container_id']).'?fields=status_code',$headers)['data']['status_code'] ?? '';
    if (in_array($status,['ERROR','EXPIRED'],true)) return ['status'=>'failed','last_error'=>'Instagram could not prepare the image. Check its format and public availability.'];
    if ($status !== 'FINISHED') return ['status'=>'delivered','provider_state_json'=>publishing_json($state),'next_check_at'=>gmdate('Y-m-d H:i:s',time()+60)];
    $destination = publishing_decode($job['destination_json']);
    $state['phase'] = 'publish_attempted';
    publishing_job_update((int)$job['id'],['status'=>'processing','locked_at'=>gmdate('Y-m-d H:i:s'),'provider_state_json'=>publishing_json($state)]);
    $result = publishing_http('POST',$base.'/'.rawurlencode($destination['resource']).'/media_publish',$headers,['creation_id'=>$state['container_id']],true,true)['data'];
    $id = (string)($result['id'] ?? '');
    if ($id === '') throw new PublishingRemoteException('Instagram did not confirm the published media ID. Check your profile.',0,true);
    $url = '';
    try { $url = (string)(publishing_http('GET',$base.'/'.rawurlencode($id).'?fields=permalink',$headers)['data']['permalink'] ?? ''); } catch (Throwable $e) { /* Publication ID is already confirmed. A later read error must not cause a duplicate. */ }
    return ['status'=>'published','remote_id'=>$id,'external_url'=>$url,'provider_state_json'=>publishing_json(['container_id'=>$state['container_id'],'phase'=>'published'])];
}
