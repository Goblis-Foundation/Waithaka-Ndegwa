<?php
declare(strict_types=1);
require_once __DIR__.'/../app/publishing_networks.php';require_once __DIR__.'/../app/providers/communities.php';
final class PublishingRemoteException extends RuntimeException{public function __construct(string $m,public readonly int $httpStatus=0,public readonly bool $ambiguous=false){parent::__construct($m);}}
$n=0;$saved=[];$calls=[];$mode='ok';$posts=0;
function ck(bool $b,string $m):void{$GLOBALS['n']++;if(!$b)throw new RuntimeException($m);}
function denied(callable $f,string $m):void{try{$f();}catch(RuntimeException){ck(true,$m);return;}throw new RuntimeException($m);}
function publishing_json(array $v):string{return json_encode($v,JSON_THROW_ON_ERROR);}
function publishing_decode(?string $v):array{return json_decode($v??'',true)?:[];}
function publishing_bearer(string $v):array{if($v===''||preg_match('/[\r\n]/',$v))throw new RuntimeException('Bad token');return ['Authorization: Bearer '.$v];}
function publishing_public_url(string $url):bool{return str_starts_with($url,'https://media.example.com/');}
function publishing_job_url_valid(string $url,string $slug):bool{return preg_match($slug==='threads'?'~^https://www\.threads\.net/@fixture/post/[A-Za-z0-9]+$~D':'~^https://t\.me/(?:fixture|c/[0-9]+)/[0-9]+$~D',$url)===1;}
function media_library_approved_asset(int $id):array{ck($id===9,'Exact owned Telegram media selected');return $GLOBALS['asset'];}
function publishing_job_update(int $id,array $f):void{ck($id===11,'Selected job only');$GLOBALS['saved']=array_replace($GLOBALS['saved'],$f);}
function setup(string $slug):array{
 $GLOBALS['calls']=[];$GLOBALS['mode']='ok';$GLOBALS['posts']=0;
 $meta=match($slug){'telegram'=>['chat_type'=>'channel','chat_title'=>'Fixture channel','username'=>'fixture'],'discord'=>['guild_id'=>'55555','channel_id'=>'66666','webhook_name'=>'Fixture hook'],default=>[]};
 $c=['id'=>3,'service'=>$slug,'status'=>'connected','external_id'=>'12345','selected_resource'=>match($slug){'telegram'=>'-10077777','discord'=>'55555:66666',default=>'12345'},'scopes_json'=>publishing_json($slug==='threads'?['threads_basic','threads_content_publish']:[]),'metadata_json'=>publishing_json($meta),'verified_at'=>'2026-10-30 10:00:00','connected_by'=>7];
 $d=['slug'=>$slug,'method'=>'direct','connection_id'=>3,'connection_external_id'=>'12345','resource'=>$c['selected_resource'],'community_grant'=>publishing_community_fingerprint($c)];$v=publishing_empty_variant($slug);$v['text']='Approved message @everyone';$v['enabled']=true;
 $snap=['destinations'=>[$slug=>$d],'variants'=>[$slug=>$v]];$json=publishing_json($snap);$j=['id'=>11,'method'=>'direct','snapshot_json'=>$json,'content_hash'=>hash('sha256',$json),'destination_json'=>publishing_json($d),'provider_state_json'=>null,'remote_id'=>null];$GLOBALS['saved']=$j;return [$j,$snap,$c,$v,$d];
}
function transport(string $slug,string $method,string $path,array $creds,?array $body=null):array{
 global $calls,$posts,$mode;$calls[]=[$slug,$method,$path,$body];
 if($path==='getMe')return ['id'=>12345,'is_bot'=>true];
 if($path==='getChat')return ['id'=>$mode==='wrong-destination'?-10088888:-10077777,'type'=>'channel','title'=>'Fixture channel','username'=>'fixture'];
 if($path==='getChatMember')return ['user'=>['id'=>12345],'status'=>$mode==='no-rights'?'member':'administrator','can_post_messages'=>true];
 if($slug==='discord'&&$path==='')return ['id'=>'12345','type'=>1,'guild_id'=>'55555','channel_id'=>$mode==='wrong-destination'?'88888':'66666','name'=>'Fixture hook'];
 if($path==='/me?fields=id,username')return ['id'=>$mode==='wrong-destination'?'88888':'12345','username'=>'fixture'];
 if($method==='POST'){
  ck(!empty(publishing_community_state($GLOBALS['saved'])),'Mutation intent saved before remote boundary');
  if($mode==='timeout')throw new PublishingRemoteException('Timeout',0,true);
  if($path==='/12345/threads')return ['id'=>'44444'];$posts++;
  if($path==='/12345/threads_publish'){if($mode==='timeout-publish')throw new PublishingRemoteException('Publish timeout',0,true);return ['id'=>'77777'];}
  if($slug==='discord'){if(isset($body['_file'])){$parts=publishing_discord_multipart($body);$payload=publishing_decode($parts['payload_json']);ck($parts['files[0]'] instanceof CURLFile&&!isset($payload['_file'])&&$payload['attachments'][0]['filename']===publishing_discord_filename($body['_file']['asset']),'Multipart uses an owned file and never embeds its local path');}ck($path==='?wait=true'&&$body['allowed_mentions']===['parse'=>[]]&&$body['flags']===4,'Discord waits for receipt and suppresses mentions/embeds');return ['id'=>'77777'];}
  if($slug==='telegram'){ck(!isset($body['parse_mode'])&&$body['chat_id']==='-10077777','Telegram sends literal reviewed text to exact channel');return ['message_id'=>77,'chat'=>['id'=>-10077777]]+ (isset($body['caption'])?['caption'=>$body['caption']]:['text'=>$mode==='wrong-receipt'?'other':$body['text']])+($path==='sendPhoto'?['photo'=>[['file_id'=>'fixture-photo']]]:($path==='sendVideo'?['video'=>['file_id'=>'fixture-video']]:[]));}
 }
 if($slug==='discord'&&$path==='/messages/77777')return ['id'=>'77777','webhook_id'=>'12345','channel_id'=>'66666','content'=>$mode==='wrong-receipt'?'other':'Approved message @everyone','mention_everyone'=>$mode==='mentions']+(isset($GLOBALS['discord_attachment'])?['attachments'=>$GLOBALS['discord_attachment']]:[]);
 if(str_starts_with($path,'/44444?'))return ['id'=>$mode==='wrong-container'?'88888':'44444','status'=>$GLOBALS['container_status']??'FINISHED'];
 if(str_starts_with($path,'/77777?'))return ['id'=>'77777','text'=>$mode==='wrong-receipt'?'other':'Approved message @everyone','media_type'=>$GLOBALS['receipt_type']??'TEXT_POST','permalink'=>'https://www.threads.net/@fixture/post/AbCd'];
 if(str_starts_with($path,'/12345/threads?'))return ['data'=>$mode==='wrong-owner'?[]:[['id'=>'77777']]];
 throw new RuntimeException('Unexpected fixture request');
}
$credentials=static fn(array $c):array=>['access_token'=>'12345:'.str_repeat('a',25),'webhook_id'=>'12345','webhook_token'=>str_repeat('b',30)];
foreach(['threads','telegram','discord']as$slug){
 [$j,$snap,$c]=setup($slug);$r=publishing_community_deliver($j,$snap,$c,'transport',$credentials);ck($r['status']==='published'&&$posts===1,$slug.' has one matching receipt');$saved=array_replace($saved,$r);$before=count($calls);denied(fn()=>publishing_community_deliver($saved,$snap,$c,'transport',$credentials),'No create after existing task');ck(count($calls)===$before,'No network after replay denial');
 [$j,$snap,$c]=setup($slug);$mode='timeout';denied(fn()=>publishing_community_deliver($j,$snap,$c,'transport',$credentials),'Uncertain mutation retained');ck(!publishing_community_can_resume($saved),'No resume of uncertain mutation');$before=count($calls);denied(fn()=>publishing_community_check($saved,$c,'transport',$credentials),'No check can replay uncertain create');ck(count($calls)===$before,'No duplicate request');
 foreach(['grant','content','destination','identity']as$bad){[$j,$snap,$c]=setup($slug);if($bad==='grant')$c['verified_at']='changed';if($bad==='content')$j['content_hash']=str_repeat('f',64);if($bad==='destination')$c['selected_resource']='88888';if($bad==='identity')$mode='wrong-destination';denied(fn()=>publishing_community_deliver($j,$snap,$c,'transport',$credentials),'Changed '.$bad.' stops delivery');ck($posts===0,'No post after changed '.$bad);}
 [$j,$snap,$c]=setup($slug);$mode='wrong-receipt';if($slug==='telegram'){denied(fn()=>publishing_community_deliver($j,$snap,$c,'transport',$credentials),'Mismatched Telegram acknowledgement is uncertain');}
 else{$r=publishing_community_deliver($j,$snap,$c,'transport',$credentials);ck($r['status']==='delivered'&&$saved['remote_id']==='77777','Known receipt retained');$saved=array_replace($saved,$r);$mode='ok';$r=publishing_community_check($saved,$c,'transport',$credentials);ck($r['status']==='published'&&$posts===1,'Recovery reads known receipt without resend');}
}
[$j,$snap,$c]=setup('telegram');$mode='no-rights';denied(fn()=>publishing_community_deliver($j,$snap,$c,'transport',$credentials),'Telegram bot posting rights required');ck($posts===0,'No message without posting rights');
foreach(['https://evil.invalid/api/webhooks/12345/'.str_repeat('a',30),'https://discord.com/api/webhooks/12345/'.str_repeat('a',30).'?wait=true','https://discord.com/api/webhooks/12345/'.str_repeat('a',30).'/messages/123']as$url)denied(fn()=>publishing_discord_secret($url),'Reject dangerous webhook destination');
$secret=publishing_discord_secret('https://discord.com/api/webhooks/12345/'.str_repeat('a',30));ck($secret['webhook_id']==='12345','Exact webhook parsed');
foreach(['https://evil.invalid','/messages/1?redirect=evil','/messages/1#x']as$path)denied(fn()=>publishing_community_endpoint('discord',$path,$secret),'Fixed webhook endpoint');
foreach(['x','',[],'subscribers ']as$a)denied(fn()=>publishing_audience_input($a),'Invalid audience never becomes public');
ck(count(publishing_networks())===10&&count(publishing_network_limits())===10,'Ten networks in one inventory');
foreach(['threads','discord','signal']as$slug){$v=publishing_empty_variant($slug);$v['text']='Approved';$v['media_url']='https://media.example.com/image.jpg';denied(fn()=>publishing_community_validate_variant($slug,$v,['method'=>'direct']),'Unsupported native format not dropped');publishing_community_validate_variant($slug,$v,['method'=>'manual']);ck(true,'Assisted media retained');}
foreach(['image/jpeg','video/mp4']as$mime){
 [$j,$snap,$c,$v]=setup('telegram');$asset=['id'=>9,'mime'=>$mime,'bytes'=>100,'visibility'=>'public','public_url'=>'https://media.example.com/owned','sha256'=>str_repeat('a',64)];$GLOBALS['asset']=$asset;$v['media_asset_id']=9;$v['media_asset']=$asset;$v['media_url']=$asset['public_url'];$v['media_type']=$mime==='video/mp4'?'video':'image';$snap['variants']['telegram']=$v;$j['snapshot_json']=publishing_json($snap);$j['content_hash']=hash('sha256',$j['snapshot_json']);$GLOBALS['saved']=$j;
 $r=publishing_community_deliver($j,$snap,$c,'transport',$credentials);ck($r['status']==='published'&&$posts===1,'Owned Telegram photo/video receipt checked');
 $v['text']=str_repeat('x',1025);denied(fn()=>publishing_community_validate_variant('telegram',$v,['method'=>'direct']),'Long media caption rejected');$v['text']='Approved';$v['media_asset']['bytes']=60000000;denied(fn()=>publishing_community_validate_variant('telegram',$v,['method'=>'direct']),'Oversized media rejected');
}
// Media containers remain recoverable before publication, but uncertain writes never replay.
function published_where():string{return "p.status='published'";}
function db():object{return new class {function prepare(string $sql):object{return new class {function execute(array $ids):void{ck($ids===[456],'Delayed media rechecks exact source article');}function fetchColumn():mixed{return false;}};}};}
function media_setup(string $mime):array {
 [$j,$snap,$c,$v]=setup('threads');$a=['id'=>9,'mime'=>$mime,'bytes'=>100,'visibility'=>'public','public_url'=>'https://media.example.com/owned','sha256'=>str_repeat('b',64)];$GLOBALS['asset']=$a;$GLOBALS['receipt_type']=$mime==='video/mp4'?'VIDEO':'IMAGE';$GLOBALS['container_status']='IN_PROGRESS';
 $v['media_asset_id']=9;$v['media_asset']=$a;$v['media_url']=$a['public_url'];$v['media_type']=$mime==='video/mp4'?'video':'image';$v['alt_text']='An approved accessible description';$snap['variants']['threads']=$v;$j['snapshot_json']=publishing_json($snap);$j['content_hash']=hash('sha256',$j['snapshot_json']);$GLOBALS['saved']=$j;return [$j,$snap,$c,$v];
}
foreach(['image/jpeg','image/png','video/mp4']as$mime){
 [$j,$snap,$c,$v]=media_setup($mime);$r=publishing_community_deliver($j,$snap,$c,'transport',$credentials);$saved=array_replace($saved,$r);
 ck($r['status']==='delivered'&&$posts===0&&publishing_community_can_resume($saved),'Owned media saves processing container before any publish');
 $create=array_values(array_filter($calls,static fn($call)=>$call[1]==='POST'))[0];ck(($create[3][$mime==='video/mp4'?'video_url':'image_url']??'')===$v['media_url']&&($create[3]['alt_text']??'')===$v['alt_text'],'Exact source URL, type and description preserved');
 $r=publishing_community_check($saved,$c,'transport',$credentials);$saved=array_replace($saved,$r);ck($posts===0&&$r['status']==='delivered','In-progress container is only read');
 $container_status='FINISHED';$r=publishing_community_check($saved,$c,'transport',$credentials);$saved=array_replace($saved,$r);ck($posts===1&&$r['status']==='published','Ready media publishes once and reads matching format/caption/ownership');
 denied(fn()=>publishing_community_deliver($saved,$snap,$c,'transport',$credentials),'Published media container never recreated');
}
foreach(['ERROR','EXPIRED','PUBLISHED','UNKNOWN']as$status){[$j,$snap,$c]=media_setup('video/mp4');$r=publishing_community_deliver($j,$snap,$c,'transport',$credentials);$saved=array_replace($saved,$r);$container_status=$status;if(in_array($status,['ERROR','EXPIRED'],true))ck(publishing_community_check($saved,$c,'transport',$credentials)['status']==='failed','Terminal processing stops without resend');else denied(fn()=>publishing_community_check($saved,$c,'transport',$credentials),'Unexpected/already-published processing stops');ck($posts===0,'No publish without exact ready status');}
foreach(['asset','grant','container','timeout','format','bound']as$failure){
 [$j,$snap,$c]=media_setup('image/jpeg');$r=publishing_community_deliver($j,$snap,$c,'transport',$credentials);$saved=array_replace($saved,$r);$container_status='FINISHED';
 if($failure==='asset')$GLOBALS['asset']['sha256']=str_repeat('c',64);if($failure==='grant')$c['verified_at']='changed';if($failure==='container')$mode='wrong-container';if($failure==='timeout')$mode='timeout-publish';if($failure==='format')$receipt_type='TEXT_POST';
 if($failure==='bound'){$s=publishing_community_state($saved);$s['processing_checks']=5;$saved['provider_state_json']=publishing_json($s);ck(publishing_community_check($saved,$c,'transport',$credentials)['status']==='failed'&&$posts===0,'Processing check budget stops repeated requests');}
 elseif($failure==='format'){ck(publishing_community_check($saved,$c,'transport',$credentials)['status']==='delivered'&&$posts===1,'Incorrect remote format cannot become published');$receipt_type='IMAGE';$r=publishing_community_check($saved,$c,'transport',$credentials);ck($r['status']==='published'&&$posts===1,'Known media receipt verification does not republish');}
 else{denied(fn()=>publishing_community_check($saved,$c,'transport',$credentials),'Changed media/account/container or uncertain publish stops');if($failure==='timeout'){ck(!publishing_community_can_resume($saved),'Uncertain publication intent cannot be replayed');$before=count($calls);denied(fn()=>publishing_community_check($saved,$c,'transport',$credentials),'Publish timeout is not retried');ck(count($calls)===$before,'No call after uncertain publish');}else ck($posts===0,'Changed approval boundary cannot publish');}
}
[$j,$snap,$c,$v]=media_setup('image/jpeg');foreach(['visibility','mime','bytes','alt_text']as$bad){$copy=$v;if($bad==='alt_text')$copy['alt_text']=str_repeat('x',501);else $copy['media_asset'][$bad]=match($bad){'visibility'=>'private','mime'=>'image/webp',default=>8000001};denied(fn()=>publishing_community_validate_variant('threads',$copy,['method'=>'direct']),'Reject invalid or oversized media and descriptions');}
[$j,$snap,$c]=media_setup('image/jpeg');$snap['source_post_id']=456;$j['snapshot_json']=publishing_json($snap);$j['content_hash']=hash('sha256',$j['snapshot_json']);$GLOBALS['saved']=$j;$r=publishing_community_deliver($j,$snap,$c,'transport',$credentials);$saved=array_replace($saved,$r);$container_status='FINISHED';denied(fn()=>publishing_community_check($saved,$c,'transport',$credentials),'Withdrawn source article blocks delayed publication');ck($posts===0,'No post after source withdrawal');
unset($GLOBALS['receipt_type'],$GLOBALS['container_status']);
// Discord upload + receipt verification uses only synthetic bytes and no network.
function media_library_materialize(array $a,int $limit):array{
 ck($limit===10000000,'Conservative upload byte limit');if(!empty($GLOBALS['stage_failure']))throw new RuntimeException('Synthetic storage unavailable');
 $file=tempnam(sys_get_temp_dir(),'discord-fixture-');file_put_contents($file,$GLOBALS['discord_bytes']);$GLOBALS['discord_file']=$file;return ['path'=>$file,'bytes'=>strlen($GLOBALS['discord_bytes']),'mime'=>$a['mime'],'sha256'=>hash('sha256',$GLOBALS['discord_bytes']),'temporary'=>true];
}
function media_library_release(array $opened):void{if(is_file($opened['path']))unlink($opened['path']);$GLOBALS['released']=($GLOBALS['released']??0)+1;}
function discord_setup(string $mime):array{
 [$j,$snap,$c,$v]=setup('discord');$GLOBALS['discord_bytes']='Synthetic approved attachment '.$mime;$a=['id'=>9,'mime'=>$mime,'bytes'=>strlen($GLOBALS['discord_bytes']),'visibility'=>'public','public_url'=>'https://media.example.com/owned','sha256'=>hash('sha256',$GLOBALS['discord_bytes'])];$GLOBALS['asset']=$a;$GLOBALS['released']=0;$GLOBALS['stage_failure']=false;
 $v['media_asset_id']=9;$v['media_asset']=$a;$v['media_url']=$a['public_url'];$v['media_type']=$mime==='video/mp4'?'video':'image';$v['alt_text']='Approved attachment description';$snap['variants']['discord']=$v;$j['snapshot_json']=publishing_json($snap);$j['content_hash']=hash('sha256',$j['snapshot_json']);$GLOBALS['saved']=$j;
 $filename=publishing_discord_filename($a);$GLOBALS['discord_attachment']=[['id'=>'99999','filename'=>$filename,'size'=>$a['bytes'],'content_type'=>$mime,'description'=>$v['alt_text'],'url'=>'https://cdn.discordapp.com/attachments/66666/99999/'.$filename.'?ex=abc&is=def&hm=123']];return [$j,$snap,$c,$v];
}
$reader=static function(string $url,array $a):bool{ck(publishing_discord_attachment_url($url,'66666','99999',publishing_discord_filename($a)),'Fresh signed URL has the exact channel, attachment and approved filename');return empty($GLOBALS['bad_remote_bytes']);};
foreach(['image/jpeg','image/png','video/mp4']as$mime){[$j,$snap,$c,$v]=discord_setup($mime);$r=publishing_community_deliver($j,$snap,$c,'transport',$credentials,$reader);$saved=array_replace($saved,$r);ck($r['status']==='published'&&$posts===1,'Discord attachment caption/type/description and bytes verified');ck($released===1&&!is_file($discord_file),'Staged file always cleaned after successful upload');$before=count($calls);denied(fn()=>publishing_community_deliver($saved,$snap,$c,'transport',$credentials,$reader),'Existing media post is never recreated');ck(count($calls)===$before,'No network call after completed media replay');}
foreach(['filename','size','content_type','description','url','ephemeral','extra','bytes','caption','mentions']as$bad){[$j,$snap,$c,$v]=discord_setup('image/png');$correct=$discord_attachment;if($bad==='extra')$discord_attachment[]=$discord_attachment[0];elseif($bad==='bytes')$GLOBALS['bad_remote_bytes']=true;elseif($bad==='caption')$mode='wrong-receipt';elseif($bad==='mentions')$mode='mentions';else $discord_attachment[0][$bad]=match($bad){'size'=>999,'ephemeral'=>true,'url'=>'https://evil.invalid/attachment',default=>'changed'};
 $r=publishing_community_deliver($j,$snap,$c,'transport',$credentials,$reader);$saved=array_replace($saved,$r);ck($r['status']==='delivered'&&$posts===1,'Changed '.$bad.' cannot count as verified publication');ck($released===1&&!is_file($discord_file),'Staging copy cleared while verification awaits');$discord_attachment=$correct;$GLOBALS['bad_remote_bytes']=false;$mode='ok';$r=publishing_community_check($saved,$c,'transport',$credentials,$reader);ck($r['status']==='published'&&$posts===1,'Known receipt recovery reads without another upload');}
[$j,$snap,$c,$v]=discord_setup('image/jpeg');$mode='timeout';denied(fn()=>publishing_community_deliver($j,$snap,$c,'transport',$credentials,$reader),'Unknown Discord send remains non-replayable');ck($released===1&&!is_file($discord_file)&&!publishing_community_can_resume($saved),'Timeout cleans staging and retains creation intent without duplicate');
[$j,$snap,$c,$v]=discord_setup('video/mp4');$GLOBALS['stage_failure']=true;denied(fn()=>publishing_community_deliver($j,$snap,$c,'transport',$credentials,$reader),'Unavailable source cannot upload');ck($posts===0&&empty($saved['provider_state_json']),'Storage failure precedes mutation intent and leaves safe review/retry');$GLOBALS['stage_failure']=false;
[$j,$snap,$c,$v]=discord_setup('image/png');$snap['source_post_id']=456;$j['snapshot_json']=publishing_json($snap);$j['content_hash']=hash('sha256',$j['snapshot_json']);$GLOBALS['saved']=$j;denied(fn()=>publishing_community_deliver($j,$snap,$c,'transport',$credentials,$reader),'Withdrawn article blocks staged Discord delivery');ck($posts===0&&$released===1,'Source withdrawal cannot leak an attachment');
[$j,$snap,$c,$v]=discord_setup('image/jpeg');foreach(['bytes','visibility','mime','alt_text']as$bad){$copy=$v;if($bad==='alt_text')$copy['alt_text']=str_repeat('x',501);else $copy['media_asset'][$bad]=match($bad){'bytes'=>10000001,'visibility'=>'private',default=>'application/pdf'};denied(fn()=>publishing_discord_media_validate($copy),'Reject unsupported/private/oversized media or description');}
$name=publishing_discord_filename($v['media_asset']);$base='https://cdn.discordapp.com/attachments/66666/99999/'.$name;
foreach([$base.'?evil=abc',$base.'#fragment',str_replace('cdn.discordapp.com','evil.invalid',$base),str_replace('/66666/','/77777/',$base),str_replace('https://','http://',$base),$base.'?hm[]=abc']as$url)ck(!publishing_discord_attachment_url($url,'66666','99999',$name),'Credential-free byte reads reject untrusted host/path/capability forms');
unset($GLOBALS['discord_attachment'],$GLOBALS['bad_remote_bytes']);
echo 'Passed '.$n." community delivery/recovery checks.\n";
