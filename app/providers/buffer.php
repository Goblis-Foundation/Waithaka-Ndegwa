<?php
declare(strict_types=1);

// Current GraphQL schema: https://developers.buffer.com/reference.html (2026-10-21).
function publishing_buffer_query(string $key, string $query, array $variables = [], bool $mutation = false): array {
    $result = publishing_http('POST','https://api.buffer.com',publishing_bearer($key),['query'=>$query,'variables'=>(object)$variables],false,$mutation)['data'];
    return publishing_buffer_response($result,$mutation);
}

function publishing_buffer_response(array $result, bool $mutation): array {
    if (!empty($result['errors'])) {
        $codes = array_column(array_column($result['errors'],'extensions'),'code');
        $authorization = count(array_intersect($codes,['UNAUTHENTICATED','UNAUTHORIZED','FORBIDDEN'])) > 0;
        // Execution errors can follow a side effect even when non-null propagation makes data null.
        // Only a typed mutation rejection below can prove that creation was rejected.
        throw new PublishingRemoteException($authorization?'Buffer requires reconnection or different permissions.':'Buffer could not confirm this operation. Review the account and API settings.', $authorization?401:400, $mutation);
    }
    if (!isset($result['data']) || !is_array($result['data'])) throw new PublishingRemoteException('Buffer returned no operation data.',0,$mutation);
    return $result['data'];
}

function publishing_buffer_connect(string $key, int $userId): void {
    $key = trim($key);
    if ($key === '') throw new RuntimeException('Enter your Buffer API key.');
    $account = publishing_buffer_query($key,'query { account { id name email organizations { id name } } }')['account'] ?? [];
    if (empty($account['id'])) throw new RuntimeException('Buffer did not return an account identity.');
    $resources = []; $channels = [];
    foreach ($account['organizations'] ?? [] as $organization) {
        $resources[] = ['id'=>$organization['id'],'name'=>$organization['name'] ?? $organization['id'],'type'=>'buffer_organization'];
        $result = publishing_buffer_query($key,'query Channels($input: ChannelsInput!) { channels(input: $input) { id name displayName service serviceId type externalLink organizationId isDisconnected isLocked isQueuePaused hasActiveMemberDevice allowedActions } }',['input'=>['organizationId'=>$organization['id']]]);
        foreach ($result['channels'] ?? [] as $channel) $channels[] = $channel;
    }
    publishing_save_observed_connection('buffer',['access_token'=>$key],[],['id'=>$account['id'],'name'=>$account['name'] ?? $account['email'],'resources'=>$resources,'metadata'=>['channels'=>$channels]],$userId);
}

function publishing_buffer_channels(?array $connection): array {
    if (!$connection || $connection['status'] !== 'connected') return [];
    return array_values(array_filter(publishing_decode($connection['metadata_json'])['channels'] ?? [],static fn($c)=>(string)($c['organizationId']??'')===(string)$connection['selected_resource']));
}

function publishing_buffer_channel(string $id, array $connection): array {
    $key = publishing_credentials($connection)['access_token'] ?? '';
    $r = publishing_buffer_query($key,'query Channel($input: ChannelInput!) { channel(input: $input) { id name service serviceId type externalLink organizationId isDisconnected isLocked isQueuePaused hasActiveMemberDevice allowedActions } }',['input'=>['id'=>$id]]);
    if (empty($r['channel']['id'])) throw new RuntimeException('The selected Buffer channel no longer exists.');
    return $r['channel'];
}

function publishing_validate_buffer_destination(array $channel, array $remote, string $mode): void {
    $expectedService = $channel['slug'] === 'x' ? 'twitter' : $channel['slug'];
    if (($remote['service']??'') !== $expectedService) throw new RuntimeException('The selected Buffer channel belongs to another social platform.');
    if (!empty($remote['isDisconnected']) || !empty($remote['isLocked'])) throw new PublishingRemoteException('Reconnect or unlock this channel inside Buffer.',401);
    if (!empty($remote['isQueuePaused'])) throw new RuntimeException('This Buffer channel is paused. Resume its queue before scheduling.');
    if (!in_array('scheduleUpdates',$remote['allowedActions'] ?? [],true)) throw new RuntimeException('This Buffer connection does not have scheduling rights on the selected channel.');
    if ($channel['slug'] === 'facebook' && ($channel['account_type'] !== 'page' || ($remote['type']??'') !== 'page')) throw new RuntimeException('Facebook automatic delivery requires a discovered Page, not a professional profile.');
    if ($channel['slug'] === 'instagram' && $mode === 'automatic' && ($remote['type']??'') !== 'business') throw new RuntimeException('Buffer did not identify this Instagram connection as professional. Choose notification publishing or refresh its professional connection.');
    if ($mode === 'notification' && (!in_array($channel['slug'],['instagram','tiktok','youtube'],true) || empty($remote['hasActiveMemberDevice']))) throw new RuntimeException('This notification route needs a supported channel and a linked Buffer mobile device.');
}

function publishing_buffer_input(array $job, array $snapshot): array {
    $destination = publishing_decode($job['destination_json']);
    $variant = $snapshot['variants'][$destination['slug']];
    $input = ['channelId'=>$destination['buffer_channel_id'],'text'=>$variant['text'],'schedulingType'=>$destination['buffer_mode'],'mode'=>'shareNow','assets'=>[]];
    if ($variant['media_url'] !== '') {
        $asset = ['url'=>$variant['media_url']];
        if ($variant['media_type'] === 'image' && $variant['alt_text'] !== '') $asset['metadata'] = ['altText'=>$variant['alt_text']];
        $input['assets'][] = [$variant['media_type']=>$asset];
    }
    $slug = $destination['slug'];
    if ($slug === 'instagram') $input['metadata']['instagram'] = ['type'=>$variant['format'],'shouldShareToFeed'=>true,'isAiGenerated'=>$variant['ai_generated']];
    if ($slug === 'facebook') $input['metadata']['facebook'] = ['type'=>$variant['format']];
    if ($slug === 'youtube') $input['metadata']['youtube'] = ['title'=>$variant['title'],'categoryId'=>'27','privacy'=>$variant['privacy'],'madeForKids'=>$variant['made_for_kids'],'isAiGenerated'=>$variant['ai_generated']];
    if ($slug === 'tiktok') $input['metadata']['tiktok'] = ['isAiGenerated'=>$variant['ai_generated']];
    return $input;
}

function publishing_buffer_post_result(array $post, string $mode): array {
    if (empty($post['id'])) throw new PublishingRemoteException('Buffer did not return a post ID. Check its queue before retrying.',0,true);
    $state = (string)($post['status'] ?? '');
    $url = (string)($post['externalLink'] ?? '');
    if ($url !== '' && !publishing_public_url($url)) $url = '';
    if ($mode === 'notification') {
        // Even markedAsPublished is user-reported; an actual external post URL remains required here.
        $status = !empty($post['notificationStatus']) || $state === 'sent' ? 'reminder_sent' : 'delivered';
        if (($post['notificationStatus']??'') === 'markedAsPublished' && $url !== '') $status = 'published';
    } else $status = $state === 'sent' ? 'published' : 'delivered';
    $error = null;
    if ($state === 'error') { $status = 'failed'; $error = 'Buffer reports a delivery error. Open the existing Buffer post; do not create a duplicate.'; }
    if (in_array($state,['draft','needs_approval'],true)) $error = 'The post is saved in Buffer and requires approval or scheduling there.';
    return ['status'=>$status,'remote_id'=>(string)$post['id'],'external_url'=>$url,'last_error'=>$error,'provider_state_json'=>publishing_json(['buffer_status'=>$state,'notification_status'=>$post['notificationStatus'] ?? null]),'next_check_at'=>gmdate('Y-m-d H:i:s',time()+300)];
}

function publishing_buffer_deliver(array $job, array $snapshot, array $connection): array {
    $destination = publishing_decode($job['destination_json']);
    $remote = publishing_buffer_channel($destination['buffer_channel_id'],$connection);
    publishing_validate_buffer_destination($destination,$remote,$destination['buffer_mode']);
    if ((string)$remote['serviceId'] !== (string)$destination['service_id'] || (string)$remote['organizationId'] !== (string)$destination['organization_id']) throw new RuntimeException('The Buffer destination changed after approval. Create and approve a new package revision.');
    $key = publishing_credentials($connection)['access_token'];
    $result = publishing_buffer_query($key,'mutation Publish($input: CreatePostInput!) { createPost(input: $input) { ... on PostActionSuccess { post { id status externalLink notificationStatus } } ... on MutationError { message } } }',['input'=>publishing_buffer_input($job,$snapshot)],true)['createPost'] ?? [];
    if (empty($result['post'])) {
        // A typed mutation rejection confirms that no post was created.
        if (isset($result['message'])) throw new PublishingRemoteException('Buffer rejected this post. Check the channel, media format and plan limits before retrying.',422,false);
        throw new PublishingRemoteException('Buffer did not confirm whether the post was created. Inspect its queue.',0,true);
    }
    return publishing_buffer_post_result($result['post'],$destination['buffer_mode']);
}

function publishing_buffer_check(array $job, array $connection): array {
    $key = publishing_credentials($connection)['access_token'] ?? '';
    $post = publishing_buffer_query($key,'query Post($input: PostInput!) { post(input: $input) { id status externalLink notificationStatus } }',['input'=>['id'=>$job['remote_id']]])['post'] ?? [];
    $destination = publishing_decode($job['destination_json']);
    return publishing_buffer_post_result($post,$destination['buffer_mode']);
}
