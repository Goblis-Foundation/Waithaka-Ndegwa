<?php
declare(strict_types=1);

/** Read on explicit request only. No message/comment bodies are persisted or sent to AI. */
function publishing_instagram_engagement(array $connection, string $action, string $resource='', ?callable $get=null): array {
    $service=$connection['service']??'';
    $scope=match($service){'instagram_comments'=>'instagram_business_manage_comments','instagram_messages'=>'instagram_business_manage_messages',default=>throw new InvalidArgumentException('Choose Instagram comments or messages.')};
    if(($connection['status']??'')!=='connected'||empty($connection['selected_resource'])||!in_array($scope,publishing_decode($connection['scopes_json']??null),true))throw new InvalidArgumentException('Connect, authorize and confirm this Instagram feature first.');
    $isMessages=$service==='instagram_messages';
    if(!in_array($action,$isMessages?['list','conversation']:['list','comments'],true))throw new InvalidArgumentException('Choose a supported reading action.');
    $headers=publishing_bearer(publishing_access_token($connection));
    $get??=static fn(string $url,array $h):array=>publishing_http('GET',$url,$h)['data'];
    $root='https://graph.instagram.com/'.publishing_meta_version().'/';$id=(string)$connection['selected_resource'];
    $who=$get($root.rawurlencode($id).'?fields=id,user_id,username',$headers);
    if((string)($who['user_id']??$who['id']??'')!==$id)throw new InvalidArgumentException('Instagram returned a different account.');
    // Only owned media or conversations from a fresh provider list can be selected.
    $query=$isMessages?'/conversations?platform=instagram&fields=id,updated_time&limit=20':'/media?fields=id,caption,timestamp&limit=10';
    $response=$get($root.rawurlencode($id).$query,$headers);
    if(!isset($response['data'])||!is_array($response['data']))throw new InvalidArgumentException('Instagram did not return a supported list. Check the account permissions.');
    $items=[];foreach(array_slice($response['data'],0,$isMessages?20:10)as$row){
        if(!is_array($row))continue;$itemId=$row['id']??null;if(!is_string($itemId)||$itemId===''||strlen($itemId)>255||preg_match('/[\x00-\x1f]/',$itemId))continue;
        $items[]=['id'=>$itemId,'label'=>$isMessages?'Conversation '.$itemId:mb_substr((string)($row['caption']??'Untitled media'),0,160),'time'=>mb_substr((string)($row[$isMessages?'updated_time':'timestamp']??''),0,80)];
    }
    $result=['items'=>$items,'rows'=>[],'selected'=>'','has_more'=>!empty($response['paging']['next']),'read_at'=>gmdate('c'),'is_messages'=>$isMessages];
    if($action==='list')return $result;
    if(!in_array($resource,array_column($items,'id'),true))throw new InvalidArgumentException('Choose an item returned for this account. Older items can be reviewed in Instagram.');
    $edge=$isMessages?'/messages?fields=id,created_time,from,message&limit=20':'/comments?fields=id,text,timestamp&limit=20';
    $details=$get($root.rawurlencode($resource).$edge,$headers);
    if(!isset($details['data'])||!is_array($details['data']))throw new InvalidArgumentException('Instagram did not return a supported response.');
    $result['selected']=$resource;$result['detail_has_more']=!empty($details['paging']['next']);
    foreach(array_slice($details['data'],0,20)as$row){if(!is_array($row))continue;$result['rows'][]=['id'=>mb_substr((string)($row['id']??''),0,255),'text'=>mb_substr((string)($row[$isMessages?'message':'text']??''),0,20000),'time'=>mb_substr((string)($row[$isMessages?'created_time':'timestamp']??''),0,80),'from'=>$isMessages?mb_substr((string)($row['from']['username']??$row['from']['id']??'Not returned'),0,255):'Not requested'];}
    return $result;
}
