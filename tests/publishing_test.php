<?php
declare(strict_types=1);

// Offline policy tests. No credentials, provider calls or database are needed.
function app_config(?string $key=null, mixed $default=null): mixed { return ['timezone'=>'Africa/Nairobi','app_env'=>'test','base_url'=>'https://www.waithakandegwa.com'][$key]??$default; }
function url(string $path=''): string { return 'https://www.waithakandegwa.com/'.ltrim($path,'/'); }
require_once __DIR__.'/../app/publishing.php';

$count=0;
function check(bool $condition, string $label): void { global $count; if(!$condition)throw new RuntimeException('FAIL: '.$label);$count++; }
function rejects(callable $action,string $label): void { try{$action();}catch(RuntimeException $e){check(true,$label);return;}check(false,$label); }

$identity=['status'=>'connected','external_id'=>'actual-returned-id','selected_resource'=>'actual-resource'];
$facebook=['slug'=>'facebook','account_type'=>'professional_profile','delivery_method'=>'direct'];
check(!publishing_capability($facebook,$identity)['usable'],'Facebook professional mode does not become a Page');
$facebook['delivery_method']='manual';check(publishing_capability($facebook)['usable'],'Facebook professional profile can have a real assisted task');
$instagram=['slug'=>'instagram','account_type'=>'professional','delivery_method'=>'direct'];
check(!publishing_capability($instagram)['usable'],'Professional branding does not imply OAuth authorization');
check(publishing_capability($instagram,$identity)['mode']==='direct','Direct declaration is represented distinctly');
$pending=$identity;$pending['status']='pending_confirmation';check(!publishing_capability($instagram,$pending)['usable'],'An observed account still requires destination confirmation');
check(publishing_capability($instagram,$identity)['usable'],'Confirmed Instagram professional identity can use the implemented direct format');
$youtube=['slug'=>'youtube','account_type'=>'channel','delivery_method'=>'direct'];
check(!publishing_capability($youtube,$identity)['usable'],'Upload grant does not advertise an unimplemented direct video adapter');
$bufferChannel=['service'=>'instagram','type'=>'profile','serviceId'=>'42','isDisconnected'=>false,'isLocked'=>false,'isQueuePaused'=>false,'hasActiveMemberDevice'=>true,'allowedActions'=>['scheduleUpdates']];
$instagram['delivery_method']='buffer';$instagram['buffer_mode']='automatic';
check(!publishing_capability($instagram,$identity,$bufferChannel)['usable'],'Buffer consumer Instagram connection cannot auto-publish');
$instagram['buffer_mode']='notification';check(publishing_capability($instagram,$identity,$bufferChannel)['usable'],'Buffer personal reminder remains a user-completed route');
$bufferChannel['hasActiveMemberDevice']=false;check(!publishing_capability($instagram,$identity,$bufferChannel)['usable'],'Reminder requires an actual registered mobile device');
$bufferChannel['hasActiveMemberDevice']=true;$bufferChannel['isDisconnected']=true;check(!publishing_capability($instagram,$identity,$bufferChannel)['usable'],'Disconnected provider channels cannot be scheduled');

check(publishing_buffer_post_result(['id'=>'a','status'=>'sent','notificationStatus'=>'notified','externalLink'=>null],'notification')['status']==='reminder_sent','A reminder marked sent is never publication');
check(publishing_buffer_post_result(['id'=>'a','status'=>'sent','notificationStatus'=>'markedAsPublished','externalLink'=>null],'notification')['status']==='reminder_sent','Manual completion without a post URL is not treated as verified publication');
check(publishing_buffer_post_result(['id'=>'a','status'=>'scheduled','notificationStatus'=>null],'automatic')['status']==='delivered','Provider acceptance is distinct from publication');
check(publishing_buffer_post_result(['id'=>'a','status'=>'needs_approval','notificationStatus'=>null],'automatic')['status']==='delivered','Provider approval queue is not publication');
check(publishing_buffer_post_result(['id'=>'a','status'=>'sent','notificationStatus'=>null],'automatic')['status']==='published','Confirmed automatic sent state is publication');
check(publishing_buffer_post_result(['id'=>'a','status'=>'error','notificationStatus'=>null],'automatic')['status']==='failed','Provider errors remain visible');
rejects(fn()=>publishing_buffer_post_result(['status'=>'sent'],'automatic'),'Missing remote ID creates an ambiguous outcome');
try { publishing_buffer_response(['data'=>null,'errors'=>[['message'=>'Execution failed']]],true);check(false,'Unknown mutation result must throw'); }
catch(PublishingRemoteException $e) { check($e->ambiguous,'GraphQL data:null after a mutation is ambiguous and cannot be blindly retried'); }
try { publishing_buffer_response(['data'=>null,'errors'=>[['message'=>'Read failed']]],false);check(false,'Read error must throw'); }
catch(PublishingRemoteException $e) { check(!$e->ambiguous,'A failed read does not imply an uncertain publication'); }

check(!publishing_public_url('https://127.0.0.1/secret'),'Loopback cannot be a delivery asset');
check(!publishing_public_url('https://169.254.169.254/latest/meta-data/'),'Metadata address cannot be a delivery asset');
check(!publishing_public_url('https://example.com/write/edit/1'),'Private Writer URL cannot be distributed');
check(!publishing_public_url('https://example.com/%2563onsent/token'),'Encoded consent routes are excluded');
check(!publishing_public_url('https://example.com/newsletter/confirm?token=example'),'Newsletter confirmation capabilities cannot be distributed');
check(!publishing_public_url('https://example.com/newsletter/unsubscribe?token=example'),'Unsubscribe capabilities cannot be distributed');
check(!publishing_public_url('https://example.com/my-thoughts/example?preview=1'),'Private article previews cannot be distributed');
check(!publishing_public_url('https://example.com/my-thoughts/example?%2570review=1'),'Encoded private-preview query keys cannot be distributed');
check(!publishing_public_url('https://media.example.com/private.jpg?X-Amz-Signature=example&X-Amz-Credential=example'),'Signed private object-storage links cannot be distributed');
check(!publishing_public_url('https://example.com/payments/paypal/return?ref=example'),'Payment return links cannot be distributed');
check(publishing_public_url('https://example.com/my-thoughts/startup?utm_source=linkedin&utm_medium=social'),'Ordinary campaign tracking remains allowed');
check(!publishing_public_url('https://user:pass@example.com/a.jpg'),'URL-embedded credentials cannot be distributed');
check(publishing_public_url('https://media.example.com/approved.jpg'),'Public HTTPS media is accepted');
check(!publishing_job_url_valid('https://x.com/iwaithakaw','x'),'Profile URL cannot falsely complete a posting task');
check(publishing_job_url_valid('https://x.com/iwaithakaw/status/123456789','x'),'Actual X post URL can complete its task');
check(!publishing_job_url_valid('https://x.com.evil.example/a/status/123','x'),'Lookalike social domain is rejected');
check(!publishing_job_url_valid('https://www.instagram.com/p/ABC/','facebook'),'Published link must belong to the intended platform');
check(publishing_job_url_valid('https://www.youtube.com/shorts/ABCDEFGHIJK','youtube'),'Actual YouTube Short URL is accepted');
check(!publishing_job_url_valid('https://www.youtube.com/watch','youtube'),'A watch page without a video ID cannot complete a delivery');
check(publishing_job_url_valid('https://www.youtube.com/watch?v=ABCDEFGHIJK','youtube'),'Actual YouTube watch URL is accepted');
check(publishing_job_label(['status'=>'published','destination_json'=>publishing_json(['slug'=>'youtube','visibility'=>'private'])])==='Uploaded · Private','A private upload is not labelled as a public post');
check(publishing_state_label('reminder_queued')==='Reminder queued','Email enqueueing does not claim reminder delivery');
rejects(fn()=>publishing_google_report(['provider'=>'google','status'=>'pending_confirmation','selected_resource'=>null]),'Google reports require a confirmed resource before any provider call');

$draft=publishing_default_draft(['id'=>12,'slug'=>'first-test','title'=>'First test','standfirst'=>'What we learned.']);
check($draft['source_url']==='https://www.waithakandegwa.com/my-thoughts/first-test','Canonical article route is preserved');
check(count($draft['variants'])===6,'All six channels have independent variants');
check(count(array_filter($draft['variants'],fn($v)=>$v['enabled']))===0,'A new package does not authorize any platform');
check($draft['variants']['youtube']['privacy']==='private','YouTube visibility is explicit and conservative');
$v=$draft['variants']['instagram'];$v['enabled']=true;$v['media_url']='https://example.com/image.jpg';$v['media_type']='image';$v['alt_text']='Prototype on a table.';$v['text']='The first prototype.';
publishing_validate_variant('instagram',$v,['method'=>'direct']);check(true,'Direct Instagram JPEG variant validates');
$v['media_type']='video';rejects(fn()=>publishing_validate_variant('instagram',$v,['method'=>'direct']),'Direct Instagram does not silently discard unsupported video');
$v=$draft['variants']['x'];$v['text']=str_repeat('a',281);rejects(fn()=>publishing_validate_variant('x',$v,['method'=>'manual']),'Caption constraints are reviewed before scheduling');
$v['text']='Valid text';$v['media_url']='https://example.com/photo.jpg';$v['alt_text']='A photo.';rejects(fn()=>publishing_validate_variant('x',$v,['method'=>'direct']),'Direct X does not silently drop media');
$v['media_url']='';$v['format']='story';rejects(fn()=>publishing_validate_variant('facebook',$v,['method'=>'direct']),'Direct Facebook never turns an approved Story into a feed post');

echo "Publishing policy tests passed: {$count}\n";
