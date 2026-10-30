<?php
declare(strict_types=1);
function publishing_community_fingerprint(array $c): string {return hash('sha256',publishing_json([$c['id'],$c['service'],$c['external_id'],$c['selected_resource'],$c['scopes_json']??'',$c['metadata_json']??'',$c['verified_at']??'',(int)($c['connected_by']??0)]));}
function publishing_community_capability(array $channel,?array $c): array {
 $slug=$channel['slug'];$ok=$slug!=='signal'&&($channel['delivery_method']??'')==='direct'&&$c&&($c['service']??'')===$slug&&($c['status']??'')==='connected'&&!empty($c['selected_resource']);
 if($ok&&!empty(publishing_decode($c['metadata_json']??'')['owner_declared']))$ok=false;
 if($ok&&$slug==='threads')$ok=!array_diff(['threads_basic','threads_content_publish'],publishing_decode($c['scopes_json']??''));
 return ['usable'=>(bool)$ok,'mode'=>'direct','reason'=>$ok?'Confirmed community destination. Approve each exact post before delivery.':'Configure and confirm this exact service. Signal is assisted; Buffer is not implemented for these new routes.'];
}
function publishing_community_validate_variant(string $slug,array $v,array $d): void {
 if(($d['method']??'')==='manual')return;
 if($slug==='signal'||($d['method']??'')!=='direct'||($v['format']??'')!=='post'||!empty($v['platform_options'])||!empty($v['ai_generated']))throw new RuntimeException('Use assisted publishing for this format, native option or disclosure. No selected option will be discarded.');
 $media=!empty($v['media_url'])||!empty($v['media_asset_id']);if($slug==='discord'&&$media)throw new RuntimeException('This direct route supports text and links. Media remains assisted.');
 if(!is_string($v['text']??null)||!mb_check_encoding($v['text'],'UTF-8')||mb_strlen($v['text'])>publishing_network_limits()[$slug]||(!$media&&trim($v['text'])===''))throw new RuntimeException('Review this community caption and its character limit.');
 if($slug==='threads'&&$media){$a=$v['media_asset']??[];$video=($v['media_type']??'')==='video';
  if(!in_array($v['media_type']??'',['image','video'],true)||!is_array($a)||(int)($a['id']??0)<1||(int)($v['media_asset_id']??0)!==(int)$a['id']||($a['visibility']??'')!=='public'||($a['public_url']??'')!==($v['media_url']??'')||!publishing_public_url((string)($v['media_url']??''))||!in_array($a['mime']??'',$video?['video/mp4']:['image/jpeg','image/png'],true)||!is_int($a['bytes']??null)||$a['bytes']<1||$a['bytes']>($video?50000000:8000000)||!preg_match('/^[a-f0-9]{64}$/D',(string)($a['sha256']??'')))throw new RuntimeException('Threads needs one owned public JPEG/PNG up to 8 MB or MP4 up to this workspace’s 50 MB limit. Provider processing must confirm eligibility.');
  if(!is_string($v['alt_text']??'')||!mb_check_encoding($v['alt_text']??'','UTF-8')||mb_strlen($v['alt_text']??'')>500)throw new RuntimeException('Review the Threads media description, up to 500 characters.');
 }
 if($slug==='telegram'&&$media){$a=$v['media_asset']??[];$video=($v['media_type']??'')==='video';
  if(!is_array($a)||(int)($a['id']??0)<1||(int)($v['media_asset_id']??0)!==(int)$a['id']||($a['visibility']??'')!=='public'||($a['public_url']??'')!==($v['media_url']??'')||!publishing_public_url((string)($v['media_url']??''))||!in_array($a['mime']??'',$video?['video/mp4']:['image/jpeg','image/png'],true)||!is_int($a['bytes']??null)||$a['bytes']<1||$a['bytes']>($video?50000000:10000000)||!preg_match('/^[a-f0-9]{64}$/D',(string)($a['sha256']??''))||mb_strlen($v['text'])>1024)throw new RuntimeException('Telegram needs an owned public JPEG/PNG up to 10 MB or MP4 up to 50 MB, caption up to 1,024 characters.');
 }
}
function publishing_community_id(mixed $v): string {if(!is_string($v)||!preg_match('/^[0-9]{1,40}$/D',$v))throw new PublishingRemoteException('The provider did not return a valid identifier. Review the saved task.',0,true);return $v;}
function publishing_discord_secret(string $url): array {if(!preg_match('~^https://discord\.com/api(?:/v10)?/webhooks/([0-9]{1,20})/([A-Za-z0-9._-]{20,200})$~D',$url,$m))throw new RuntimeException('Use the exact discord.com webhook URL, without query, fragment or extra path.');return ['webhook_id'=>$m[1],'webhook_token'=>$m[2]];}
function publishing_community_endpoint(string $service,string $path,array $creds): array {
 if($service==='telegram'){$t=$creds['access_token']??'';if(!is_string($t)||!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{20,200}$/D',$t)||!in_array($path,['getMe','getChat','getChatMember','sendMessage','sendPhoto','sendVideo'],true))throw new RuntimeException('Invalid Telegram credential or endpoint.');return ['https://api.telegram.org/bot'.$t.'/'.$path,[]];}
 if($service==='discord'){$id=$creds['webhook_id']??'';$t=$creds['webhook_token']??'';if(!is_string($id)||!preg_match('/^[0-9]{1,20}$/D',$id)||!is_string($t)||!preg_match('/^[A-Za-z0-9._-]{20,200}$/D',$t)||!preg_match('~^(?:|\?wait=true|/messages/[0-9]{1,20})$~D',$path))throw new RuntimeException('Invalid Discord credential or endpoint.');return ['https://discord.com/api/v10/webhooks/'.$id.'/'.$t.$path,[]];}
 if($service==='threads'&&preg_match('~^/(?:me|[0-9]{1,40})(?:/(?:threads|threads_publish))?(?:\?[^\x00-\x20#]*)?$~D',$path))return ['https://graph.threads.net/v1.0'.$path,publishing_bearer((string)($creds['access_token']??''))];
 throw new RuntimeException('Unsupported community endpoint.');
}
function publishing_community_http(string $service,string $method,string $path,array $creds,?array $body=null): array {
 [$url,$headers]=publishing_community_endpoint($service,$path,$creds);if(!in_array($method,['GET','POST'],true))throw new RuntimeException('Unsupported community method.');if($method==='GET'&&$body)$url.=(str_contains($url,'?')?'&':'?').http_build_query($body,'','&',PHP_QUERY_RFC3986);
 $mutation=$method==='POST';$raw='';$ch=curl_init($url);$o=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$raw):int{if(strlen($raw)+strlen($chunk)>1048576)return 0;$raw.=$chunk;return strlen($chunk);}];
 if($method==='POST'){$headers[]=$service==='threads'?'Content-Type: application/x-www-form-urlencoded':'Content-Type: application/json';$o[CURLOPT_POSTFIELDS]=$service==='threads'?http_build_query($body??[],'','&',PHP_QUERY_RFC3986):publishing_json($body??[]);}$o[CURLOPT_HTTPHEADER]=$headers;curl_setopt_array($ch,$o);$ok=curl_exec($ch)!==false;$code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);$r=json_decode($raw,true);
 if(!$ok||$code<200||$code>=300||!is_array($r)||!empty($r['error'])||($service==='telegram'&&($r['ok']??null)!==true))throw new PublishingRemoteException('The community provider did not confirm this request. Review the saved task, permissions and limits.',in_array($code,[401,403,429],true)?$code:0,$mutation);
 return $service==='telegram'&&is_array($r['result']??null)?$r['result']:$r;
}
function publishing_community_observe(string $service,array $creds,string $target,?callable $http=null): array {
 $http??='publishing_community_http';
 if($service==='telegram'){
  if(!preg_match('/^(?:-100[0-9]{1,16}|@[A-Za-z][A-Za-z0-9_]{4,31})$/D',$target))throw new RuntimeException('Use an exact Telegram channel/supergroup @username or -100… ID. Direct messages and ordinary groups are excluded.');
  $who=$http('telegram','GET','getMe',$creds);if(($who['is_bot']??null)!==true||!is_int($who['id']??null))throw new RuntimeException('Telegram did not confirm a bot identity.');
  $chat=$http('telegram','GET','getChat',$creds,['chat_id'=>$target]);$id=(string)($chat['id']??'');$type=$chat['type']??'';if(!preg_match('/^-100[0-9]{1,16}$/D',$id)||!in_array($type,['channel','supergroup'],true))throw new RuntimeException('Telegram did not return an eligible community.');
  $member=$http('telegram','GET','getChatMember',$creds,['chat_id'=>$id,'user_id'=>$who['id']]);if(($member['user']['id']??null)!==$who['id']||!in_array($member['status']??'',['administrator','creator'],true)||($type==='channel'&&($member['status']??'')!=='creator'&&($member['can_post_messages']??null)!==true))throw new RuntimeException('The bot needs administrator/posting permission in this community.');
  $meta=['chat_type'=>$type,'chat_title'=>(string)($chat['title']??''),'username'=>(string)($chat['username']??'')];$name=mb_substr($meta['chat_title'],0,150).' · '.$id;return ['id'=>(string)$who['id'],'name'=>$name,'resources'=>[['id'=>$id,'name'=>$name,'type'=>$type]],'metadata'=>$meta];
 }
 if($service==='discord'){$r=$http('discord','GET','',$creds);$id=publishing_community_id($r['id']??null);$g=publishing_community_id($r['guild_id']??null);$c=publishing_community_id($r['channel_id']??null);if(($r['type']??null)!==1||$id!==$creds['webhook_id'])throw new RuntimeException('Use an incoming webhook for the intended Discord server/channel.');$meta=['guild_id'=>$g,'channel_id'=>$c,'webhook_name'=>(string)($r['name']??'')];$name=mb_substr($meta['webhook_name'],0,100).' · server '.$g.' / channel '.$c;return ['id'=>$id,'name'=>$name,'resources'=>[['id'=>$g.':'.$c,'name'=>$name,'type'=>'server_channel','url'=>'https://discord.com/channels/'.$g.'/'.$c]],'metadata'=>$meta];}
 throw new RuntimeException('Unsupported community verification.');
}
function publishing_community_setup(string $service,array $input,int $owner): void {
 if(!in_array($service,['telegram','discord','signal'],true))throw new RuntimeException('Unsupported community setup.');
 if($service==='signal'||($input['assisted_only']??'')==='1'){$label=$input['destination_label']??'';if(!is_string($label)||trim($label)===''||mb_strlen($label)>150||preg_match('/[\x00-\x1f\x7f]|https?:\/\//i',$label))throw new RuntimeException('Enter an exact private group/channel label, up to 150 characters, without an invitation link.');$label=trim($label);$id='declared:'.hash('sha256',$label);$creds=[];$observed=['id'=>$id,'name'=>$label,'resources'=>[['id'=>$id,'name'=>$label,'type'=>'owner_declared']],'metadata'=>['owner_declared'=>true,'delivery'=>'assisted']];}
 else{$secret=$input['credential']??'';$target=$input['destination']??'';if(!is_string($secret)||!is_string($target))throw new RuntimeException('Enter valid connection fields.');$creds=$service==='discord'?publishing_discord_secret(trim($secret)):['access_token'=>trim($secret)];$observed=publishing_community_observe($service,$creds,trim($target));}
 publishing_save_observed_connection($service,$creds,[],$observed,$owner);
}
function publishing_community_expected(array $job,array $c): array {
 $json=$job['snapshot_json']??'';if(!is_string($json)||!hash_equals((string)($job['content_hash']??''),hash('sha256',$json)))throw new PublishingRemoteException('The approved community revision changed.',422);$s=publishing_decode($json);$d=publishing_decode($job['destination_json']??'');$slug=$d['slug']??'';
 if(!in_array($slug,['threads','telegram','discord'],true)||($s['destinations'][$slug]??null)!=$d||($d['method']??'')!=='direct'||($c['service']??'')!==$slug||($c['status']??'')!=='connected'||(int)($d['connection_id']??0)!==(int)($c['id']??0)||($d['connection_external_id']??'')!==($c['external_id']??null)||($d['resource']??'')!==($c['selected_resource']??null)||($d['community_grant']??'')!==publishing_community_fingerprint($c))throw new PublishingRemoteException('The approved community account or destination changed. Approve a new revision.',422);
 $v=$s['variants'][$slug]??[];publishing_community_validate_variant($slug,$v,$d);return [$d,$v];
}
function publishing_community_state(array $job): array {$s=publishing_decode($job['provider_state_json']??'');return ($s['adapter']??'')==='community_v1'?$s:[];}
function publishing_community_can_resume(array $job): bool {$s=publishing_community_state($job);return ($job['method']??'')==='direct'&&((($s['phase']??'')==='verification'&&!empty($s['remote_id']))||(($s['slug']??'')==='threads'&&($s['phase']??'')==='media_processing'&&!empty($s['container_id'])));}
function publishing_community_checkpoint(array &$job,array $s,array $extra=[]): void {$f=['provider_state_json'=>publishing_json($s)]+$extra;publishing_job_update((int)$job['id'],$f);$job=array_replace($job,$f);}
function publishing_community_pending(array $s): array {return ['status'=>'delivered','provider_state_json'=>publishing_json($s),'next_check_at'=>gmdate('Y-m-d H:i:s',time()+60),'last_error'=>'A publication ID was saved. Receipt verification is pending; no create request will be repeated.'];}
function publishing_community_live(array $d,array $c,array $creds,callable $http): void {
 if($d['slug']==='threads'){$r=$http('threads','GET','/me?fields=id,username',$creds);if(($r['id']??'')!==$d['resource']||$c['external_id']!==$d['resource']||array_diff(['threads_basic','threads_content_publish'],publishing_decode($c['scopes_json']??'')))throw new PublishingRemoteException('Threads did not confirm the approved profile and grant.',422);return;}
 $r=publishing_community_observe($d['slug'],$creds,$d['slug']==='telegram'?$d['resource']:'',$http);if($r['id']!==$c['external_id']||($r['resources'][0]['id']??'')!==$d['resource']||$r['metadata']!=publishing_decode($c['metadata_json']??''))throw new PublishingRemoteException('The community identity, permissions or destination changed.',422);
}
function publishing_community_deliver(array $job,array $snapshot,array $c,?callable $http=null,?callable $credentials=null): array {
 [$d,$v]=publishing_community_expected($job,$c);if(!empty($job['provider_state_json'])||!empty($job['remote_id']))throw new PublishingRemoteException('An existing community task cannot be recreated.',0,true);
 $http??='publishing_community_http';$creds=$credentials?$credentials($c):($d['slug']==='threads'?['access_token'=>publishing_access_token($c)]:publishing_credentials($c));publishing_community_live($d,$c,$creds,$http);
 if(!empty($v['media_asset_id'])&&media_library_approved_asset((int)$v['media_asset_id'])!==$v['media_asset'])throw new PublishingRemoteException('The approved community media changed.',422);
 $s=['adapter'=>'community_v1','phase'=>'creating','slug'=>$d['slug'],'approval_hash'=>$job['content_hash'],'grant'=>$d['community_grant'],'checks'=>0];publishing_community_checkpoint($job,$s);
 if($d['slug']==='telegram'){
  $body=['chat_id'=>$d['resource']];$method='sendMessage';if(!empty($v['media_url'])){$method=$v['media_type']==='video'?'sendVideo':'sendPhoto';$body[$v['media_type']==='video'?'video':'photo']=$v['media_url'];$body['caption']=$v['text'];}else{$body['text']=$v['text'];$body['link_preview_options']=['is_disabled'=>true];}
  $r=$http('telegram','POST',$method,$creds,$body);$id=$r['message_id']??null;
  if(!is_int($id)||$id<1||(string)($r['chat']['id']??'')!==$d['resource']||($r[isset($body['caption'])?'caption':'text']??'')!==$v['text']||(isset($body['video'])&&empty($r['video']['file_id']))||(isset($body['photo'])&&empty($r['photo'])))throw new PublishingRemoteException('Telegram did not confirm a matching send receipt. Review the destination; do not resend.',0,true);
  $meta=publishing_decode($c['metadata_json']??'');$u=$meta['username']??'';$url=$u!==''?'https://t.me/'.$u.'/'.$id:'https://t.me/c/'.substr($d['resource'],4).'/'.$id;if(!publishing_job_url_valid($url,'telegram'))throw new PublishingRemoteException('Telegram receipt has no valid message link. Review it before another attempt.',0,true);
  $s['phase']='acknowledged';$s['remote_id']=(string)$id;$s['receipt']='send_response_only';publishing_community_checkpoint($job,$s,['remote_id'=>(string)$id]);return ['status'=>'published','remote_id'=>(string)$id,'external_url'=>$url,'provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'Telegram acknowledged the matching send receipt. Later-message read-back and readership are not asserted.'];
 }
 if($d['slug']==='discord'){$r=$http('discord','POST','?wait=true',$creds,['content'=>$v['text'],'allowed_mentions'=>['parse'=>[]],'flags'=>4]);$s['remote_id']=publishing_community_id($r['id']??null);}
 else{
  $media=!empty($v['media_url']);$body=['media_type'=>$media?($v['media_type']==='video'?'VIDEO':'IMAGE'):'TEXT','text'=>$v['text']];
  if($media){$body[$v['media_type']==='video'?'video_url':'image_url']=$v['media_url'];if(($v['alt_text']??'')!=='')$body['alt_text']=$v['alt_text'];}
  $r=$http('threads','POST','/'.$d['resource'].'/threads',$creds,$body);$s['container_id']=publishing_community_id($r['id']??null);
  if($media){$s['phase']='media_processing';$s['created_at']=time();$s['processing_checks']=0;publishing_community_checkpoint($job,$s);return publishing_threads_processing_pending($s);}
  $s['phase']='publishing';publishing_community_checkpoint($job,$s);$r=$http('threads','POST','/'.$d['resource'].'/threads_publish',$creds,['creation_id'=>$s['container_id']]);$s['remote_id']=publishing_community_id($r['id']??null);
 }
 $s['phase']='verification';publishing_community_checkpoint($job,$s,['status'=>'delivered','remote_id'=>$s['remote_id']]);return publishing_community_check($job,$c,$http,static fn(array $unused):array=>$creds);
}
/** Poll a saved container; never recreate it or replay an uncertain publication. */
function publishing_threads_processing_pending(array $s): array {return ['status'=>'delivered','provider_state_json'=>publishing_json($s),'next_check_at'=>gmdate('Y-m-d H:i:s',time()+60),'last_error'=>'Threads is processing the approved media. The saved container will be checked before publication.'];}
function publishing_threads_processing(array $job,array $c,array $d,array $v,array $s,callable $http,callable $credentials): array {
 if(empty($v['media_asset_id'])||media_library_approved_asset((int)$v['media_asset_id'])!==$v['media_asset'])throw new PublishingRemoteException('The approved Threads media changed. Do not publish this container.',422);
 if((int)($s['processing_checks']??0)>=5||!is_int($s['created_at']??null)||$s['created_at']<time()-86400)return ['status'=>'failed','provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'Review the saved Threads container after five processing checks or expiry. No media was recreated.'];
 $s['processing_checks']++;publishing_community_checkpoint($job,$s,publishing_threads_processing_pending($s));$creds=$credentials($c);publishing_community_live($d,$c,$creds,$http);
 $r=$http('threads','GET','/'.publishing_community_id($s['container_id']).'?fields=id,status,error_message',$creds);
 if(($r['id']??'')!==$s['container_id'])throw new PublishingRemoteException('Threads returned a different media container.',422);
 $status=$r['status']??'';if($status==='IN_PROGRESS')return publishing_threads_processing_pending($s);
 if(in_array($status,['ERROR','EXPIRED'],true))return ['status'=>'failed','provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'Threads could not prepare this media or the container expired. Review its encoding and the saved task. No new upload was attempted.'];
 if($status==='PUBLISHED')throw new PublishingRemoteException('Threads says this container was already published. Review the remote outcome; no publish request will be repeated.',0,true);
 if($status!=='FINISHED')throw new PublishingRemoteException('Threads did not confirm that this media is ready.',422);
 $snapshot=publishing_decode($job['snapshot_json']);if(!empty($snapshot['source_post_id'])){$q=db()->prepare('SELECT p.id FROM posts p WHERE p.id=? AND '.published_where());$q->execute([$snapshot['source_post_id']]);if(!$q->fetchColumn())throw new PublishingRemoteException('The linked article is no longer public. This media container was not published.',422);}
 // Persist intent before the only publish call. A timeout or crash requires manual review.
 $s['phase']='publishing';publishing_community_checkpoint($job,$s,['next_check_at'=>null]);
 $r=$http('threads','POST','/'.$d['resource'].'/threads_publish',$creds,['creation_id'=>$s['container_id']]);$s['remote_id']=publishing_community_id($r['id']??null);$s['phase']='verification';$s['checks']=0;publishing_community_checkpoint($job,$s,['remote_id'=>$s['remote_id'],'status'=>'delivered']);
 return publishing_community_check($job,$c,$http,static fn(array $unused):array=>$creds);
}
function publishing_community_check(array $job,array $c,?callable $http=null,?callable $credentials=null): array {
 [$d,$v]=publishing_community_expected($job,$c);$s=publishing_community_state($job);if(!publishing_community_can_resume($job)||($s['approval_hash']??'')!==$job['content_hash']||($s['grant']??'')!==$d['community_grant'])throw new PublishingRemoteException('Only a known community receipt can be read again. No publication is replayed.',0,true);
 if(($s['phase']??'')==='media_processing')return publishing_threads_processing($job,$c,$d,$v,$s,$http??'publishing_community_http',$credentials??static fn(array $connection):array=>['access_token'=>publishing_access_token($connection)]);
 if((int)$s['checks']>=5)return ['status'=>'failed','provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>'Review the saved publication after five receipt checks. No post was resent.'];$s['checks']++;publishing_community_checkpoint($job,$s,publishing_community_pending($s));$http??='publishing_community_http';$creds=$credentials?$credentials($c):($d['slug']==='threads'?['access_token'=>publishing_access_token($c)]:publishing_credentials($c));publishing_community_live($d,$c,$creds,$http);
 if($d['slug']==='discord'){$r=$http('discord','GET','/messages/'.publishing_community_id($s['remote_id']),$creds);$meta=publishing_decode($c['metadata_json']??'');if(($r['id']??'')!==$s['remote_id']||($r['webhook_id']??'')!==$c['external_id']||($r['channel_id']??'')!==($meta['channel_id']??'')||($r['content']??'')!==$v['text']||!empty($r['mention_everyone'])||!empty($r['mentions'])||!empty($r['mention_roles']))return publishing_community_pending($s);$url='https://discord.com/channels/'.$meta['guild_id'].'/'.$meta['channel_id'].'/'.$s['remote_id'];}
 else{$r=$http('threads','GET','/'.publishing_community_id($s['remote_id']).'?fields=id,text,media_type,permalink',$creds);if(($r['id']??'')!==$s['remote_id']||($r['text']??'')!==$v['text']||($r['media_type']??'')!==(!empty($v['media_url'])?($v['media_type']==='video'?'VIDEO':'IMAGE'):'TEXT_POST')||!publishing_job_url_valid((string)($r['permalink']??''),'threads'))return publishing_community_pending($s);$inventory=$http('threads','GET','/'.$d['resource'].'/threads?fields=id&limit=25',$creds);$found=false;foreach($inventory['data']??[]as$item)if(($item['id']??'')===$s['remote_id'])$found=true;if(!$found)return publishing_community_pending($s);$url=$r['permalink'];}
 $s['phase']='published';return ['status'=>'published','remote_id'=>$s['remote_id'],'external_url'=>$url,'provider_state_json'=>publishing_json($s),'next_check_at'=>null,'last_error'=>null];
}
