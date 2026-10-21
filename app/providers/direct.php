<?php
declare(strict_types=1);

/** Direct adapters intentionally expose only formats implemented end-to-end here.
 * LinkedIn: https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/share-on-linkedin
 * X: https://docs.x.com/x-api/posts/create-post
 * Instagram: https://developers.facebook.com/documentation/instagram-platform/content-publishing
 * Pages: https://developers.facebook.com/documentation/pages-api/posts
 */
function publishing_direct_deliver(array $job, array $snapshot, array $connection): array {
    $destination = publishing_decode($job['destination_json']);
    $variant = $snapshot['variants'][$destination['slug']];
    $headers = publishing_bearer(publishing_access_token($connection));
    $target = (string)$destination['resource'];
    if ($destination['slug'] === 'x') {
        $response = publishing_http('POST','https://api.x.com/2/tweets',$headers,['text'=>$variant['text']],false,true)['data'];
        $id = (string)($response['data']['id'] ?? '');
        if ($id === '') throw new PublishingRemoteException('X did not return a post ID. Check your profile before retrying.',0,true);
        return ['status'=>'published','remote_id'=>$id,'external_url'=>'https://x.com/i/web/status/'.rawurlencode($id)];
    }
    if ($destination['slug'] === 'linkedin') {
        $headers[] = 'X-Restli-Protocol-Version: 2.0.0';
        $response = publishing_http('POST','https://api.linkedin.com/v2/ugcPosts',$headers,[
            'author'=>'urn:li:person:'.$target,'lifecycleState'=>'PUBLISHED',
            'specificContent'=>['com.linkedin.ugc.ShareContent'=>['shareCommentary'=>['text'=>$variant['text']],'shareMediaCategory'=>'NONE']],
            'visibility'=>['com.linkedin.ugc.MemberNetworkVisibility'=>'PUBLIC']
        ],false,true);
        $id = (string)($response['data']['id'] ?? $response['headers']['x-restli-id'] ?? '');
        if ($id === '') throw new PublishingRemoteException('LinkedIn did not return a post ID. Check your profile before retrying.',0,true);
        return ['status'=>'published','remote_id'=>$id,'external_url'=>'https://www.linkedin.com/feed/update/'.rawurlencode($id).'/'];
    }
    if ($destination['slug'] === 'facebook') {
        if ($destination['account_type'] !== 'page') throw new RuntimeException('Direct Facebook publishing is only enabled for a confirmed Page.');
        $response = publishing_http('POST','https://graph.facebook.com/'.publishing_meta_version().'/'.rawurlencode($target).'/feed',$headers,['message'=>$variant['text']],true,true)['data'];
        $id = (string)($response['id'] ?? '');
        if ($id === '') throw new PublishingRemoteException('Facebook did not return a post ID. Check the Page before retrying.',0,true);
        return ['status'=>'published','remote_id'=>$id,'external_url'=>'https://www.facebook.com/'.rawurlencode($id)];
    }
    if ($destination['slug'] === 'instagram') {
        $base = 'https://graph.instagram.com/'.publishing_meta_version();
        $result = publishing_http('POST',$base.'/'.rawurlencode($target).'/media',$headers,['image_url'=>$variant['media_url'],'caption'=>$variant['text'],'alt_text'=>$variant['alt_text']],true,false)['data'];
        if (empty($result['id'])) throw new RuntimeException('Instagram did not create an image container.');
        // Container creation does not publish. Persist its ID before the separate publishing request.
        $state = ['container_id'=>(string)$result['id'],'phase'=>'container_ready'];
        publishing_job_update((int)$job['id'],['provider_state_json'=>publishing_json($state)]);
        $job['provider_state_json'] = publishing_json($state);
        return publishing_instagram_check($job,$connection);
    }
    throw new RuntimeException('Choose Buffer or assisted publishing for this channel.');
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
