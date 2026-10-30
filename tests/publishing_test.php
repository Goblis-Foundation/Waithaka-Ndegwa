<?php
declare(strict_types=1);

// Offline policy tests. No credentials, provider calls or database are needed.
function app_config(?string $key=null, mixed $default=null): mixed { return ['timezone'=>'Africa/Nairobi','app_env'=>'test','base_url'=>'https://www.waithakandegwa.com'][$key]??$default; }
function url(string $path=''): string { return 'https://www.waithakandegwa.com/'.ltrim($path,'/'); }
require_once __DIR__.'/../app/publishing.php';
require_once __DIR__.'/../app/publishing_routes.php';

$count=0;
function check(bool $condition, string $label): void { global $count; if(!$condition)throw new RuntimeException('FAIL: '.$label);$count++; }
function rejects(callable $action,string $label): void { try{$action();}catch(RuntimeException $e){check(true,$label);return;}check(false,$label); }


check(publishing_writer_owner(12,12),'The verified publication owner can approve content.');
check(!publishing_writer_owner(13,12)&&!publishing_writer_owner(0,0),'A Writer cannot inherit owner rights.');
check(!publishing_owner_control('/write/connections','GET')&&publishing_owner_control('/write/connections','POST'),'Collaborators can read a restricted connection overview but cannot alter it.');
foreach(['/write/connections/settings','/write/connections/instagram/report']as$ownerRoute)
    check(publishing_owner_control($ownerRoute,'GET'),'Personal provider actions require owner access.');
foreach(['/write/publishing/1/approve','/write/publishing/1/schedule','/write/publishing/jobs/2/complete','/write/publishing/jobs/2/retry','/write/publishing/jobs/2/cancel']as$releaseRoute)
    check(publishing_owner_control($releaseRoute,'POST'),'Approval and delivery changes are owner-only.');
check(publishing_owner_control('/write/publishing/automation','POST')&&!publishing_owner_control('/write/publishing/automation','GET'),'Shared social cadence may be read but only owner can update it.');
check(!publishing_owner_control('/write/publishing/new','GET')&&!publishing_owner_control('/write/publishing/save','POST'),'Collaborators retain draft preparation without provider approval.');
foreach(['delivered','processing']as$state)check(publishing_delivery_group($state)==='processing','Accepted/in-flight deliveries must stay visible without claiming publication.');
foreach(['ambiguous','unexpected_new_state']as$state)check(publishing_delivery_group($state)==='attention','Unknown or uncertain outcomes require follow-up.');
$filterChannels=[['slug'=>'facebook'],['slug'=>'x']];
check(publishing_delivery_filters(['delivery'=>['published'],'channel'=>['x'],'page'=>['2']],$filterChannels)===['delivery'=>'all','channel'=>'','page'=>1],'Malformed query arrays reset safely.');
check(publishing_delivery_filters(['delivery'=>"published' OR 1=1",'channel'=>'not-a-channel','page'=>'-1'],$filterChannels)===['delivery'=>'all','channel'=>'','page'=>1],'Unrecognized filter inputs cannot become SQL or pagination offsets.');
check(publishing_delivery_filters(['delivery'=>'processing','channel'=>'x','page'=>'2'],$filterChannels)===['delivery'=>'processing','channel'=>'x','page'=>2],'Explicit status/channel/page survive parsing.');
check(str_contains(publishing_delivery_url(['delivery'=>'processing','channel'=>'x'],2),'delivery=processing&channel=x&page=2#delivery-calendar'),'Pagination retains the selected delivery view.');
$memberPost=publishing_linkedin_posts_payload('member_one-123','Approved personal insight.');
check($memberPost['author']==='urn:li:person:member_one-123'&&$memberPost['commentary']==='Approved personal insight.'
    &&$memberPost['visibility']==='PUBLIC'&&$memberPost['distribution']['feedDistribution']==='MAIN_FEED'
    &&!isset($memberPost['specificContent']),'Member-owned LinkedIn Posts API payload uses the current schema, not legacy UGC.');
check(publishing_linkedin_posts_headers()===['X-Restli-Protocol-Version: 2.0.0','Linkedin-Version: 202608'],
    'Versioned LinkedIn Posts API headers are explicit.');
try{publishing_linkedin_posts_payload('urn:li:organization:123','text');check(false,'Organisation URN accepted');}
catch(InvalidArgumentException){check(true,'No organization URN can be substituted for personal member identity.');}
try{publishing_linkedin_posts_payload('member',' ');check(false,'Empty LinkedIn publication accepted');}
catch(InvalidArgumentException){check(true,'Empty LinkedIn publication rejected before provider mutation.');}
$receipt=publishing_linkedin_posts_receipt(['status'=>201,'headers'=>['x-restli-id'=>'urn:li:share:6844785523593134080']]);
check($receipt['status']==='published'&&$receipt['remote_id']==='urn:li:share:6844785523593134080'
    &&str_contains($receipt['external_url'],'feed/update/'),'Only verified LinkedIn creation receipt becomes a published post.');
try{publishing_linkedin_posts_receipt(['status'=>201,'headers'=>[]]);check(false,'Missing LinkedIn receipt must not count');}
catch(PublishingRemoteException $e){check($e->ambiguous,'Unconfirmed LinkedIn mutation requires reconciliation before retry.');}
try{publishing_linkedin_posts_receipt(['status'=>201,'headers'=>['x-restli-id'=>'urn:li:organization:123']]);check(false,'Organization result should not certify personal post');}
catch(PublishingRemoteException $e){check($e->ambiguous,'Mismatched LinkedIn post result remains ambiguous.');}
$directSource=file_get_contents(dirname(__DIR__).'/app/providers/direct.php');
check(str_contains($directSource,"publishing_http('POST','https://api.linkedin.com/rest/posts'")&&!str_contains($directSource,"publishing_http('POST','https://api.linkedin.com/v2/ugcPosts'"),
    'Direct LinkedIn delivery migrated away from the retired UGC route.');

$identity=['status'=>'connected','external_id'=>'actual-returned-id','selected_resource'=>'actual-resource'];
$facebook=['slug'=>'facebook','account_type'=>'professional_profile','delivery_method'=>'direct'];
check(!publishing_capability($facebook,$identity)['usable'],'Facebook professional mode does not become a Page');
$facebook['delivery_method']='manual';check(publishing_capability($facebook)['usable'],'Facebook professional profile can have a real assisted task');
$instagram=['slug'=>'instagram','account_type'=>'professional','delivery_method'=>'direct'];
check(!publishing_capability($instagram)['usable'],'Professional branding does not imply OAuth authorization');
check(publishing_capability($instagram,$identity)['mode']==='direct','Direct declaration is represented distinctly');
$pending=$identity;$pending['status']='pending_confirmation';check(!publishing_capability($instagram,$pending)['usable'],'An observed account still requires destination confirmation');
check(!publishing_capability($instagram,$identity)['usable'],'Generic identity cannot substitute actual Instagram publish scopes');
$igIdentity=array_replace($identity,['service'=>'instagram','external_id'=>'17840000000000001','selected_resource'=>'17840000000000001','scopes_json'=>'["instagram_business_basic","instagram_business_content_publish"]']);check(publishing_capability($instagram,$igIdentity)['usable'],'Scoped confirmed Instagram identity can use native image/Reel formats');
$youtube=['slug'=>'youtube','account_type'=>'channel','delivery_method'=>'direct'];
check(!publishing_capability($youtube,$identity)['usable'],'Upload grant does not advertise an unimplemented direct video adapter');
$bufferChannel=['service'=>'instagram','type'=>'profile','serviceId'=>'42','isDisconnected'=>false,'isLocked'=>false,'isQueuePaused'=>false,'hasActiveMemberDevice'=>true,'allowedActions'=>['scheduleUpdates']];
$instagram['delivery_method']='buffer';$instagram['buffer_mode']='automatic';
check(!publishing_capability($instagram,$identity,$bufferChannel)['usable'],'Buffer consumer Instagram connection cannot auto-publish');
$instagram['buffer_mode']='notification';check(publishing_capability($instagram,$identity,$bufferChannel)['usable'],'Buffer personal reminder remains a user-completed route');
$bufferChannel['hasActiveMemberDevice']=false;check(!publishing_capability($instagram,$identity,$bufferChannel)['usable'],'Reminder requires an actual registered mobile device');
$bufferChannel['hasActiveMemberDevice']=true;$bufferChannel['isDisconnected']=true;check(!publishing_capability($instagram,$identity,$bufferChannel)['usable'],'Disconnected provider channels cannot be scheduled');

$tiktok=['slug'=>'tiktok','account_type'=>'professional','delivery_method'=>'manual'];
check(publishing_account_type_label($tiktok)==='professional (owner-declared)','TikTok professional is an explicit owner declaration, not a verified Business subtype');
check(publishing_account_type_label(['slug'=>'tiktok','account_type'=>'personal'])==='personal','Historical TikTok declarations are never rewritten by presentation');
check(publishing_account_type_label(['slug'=>'tiktok','account_type'=>'business'])==='business','A more specific saved TikTok subtype is preserved');
check(publishing_account_type_label($facebook)==='professional profile','Facebook professional-profile labeling remains distinct from a Page');
check(publishing_capability($tiktok)['usable'],'TikTok professional keeps assisted publishing available');
$tiktok['delivery_method']='direct';check(!publishing_capability($tiktok,$identity)['usable'],'Professional branding does not create a native TikTok authorization');
$tiktok['delivery_method']='buffer';$tiktok['buffer_mode']='automatic';
$tiktokRemote=['service'=>'tiktok','type'=>'profile','serviceId'=>'tiktok-fixture-id','isDisconnected'=>false,'isLocked'=>false,'isQueuePaused'=>false,'hasActiveMemberDevice'=>false,'allowedActions'=>['scheduleUpdates']];
check(!publishing_capability($tiktok,null,$tiktokRemote)['usable'],'TikTok professional still requires a connected and confirmed partner identity');
check(publishing_capability($tiktok,$identity,$tiktokRemote)['usable'],'TikTok partner eligibility uses returned channel rights without inventing a Business subtype');
$tiktokRemote['allowedActions']=[];check(!publishing_capability($tiktok,$identity,$tiktokRemote)['usable'],'TikTok professional cannot bypass missing partner publishing rights');

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
check(count($draft['variants'])===10,'All ten channels have independent variants');
check(count(array_filter($draft['variants'],fn($v)=>$v['enabled']))===0,'A new package does not authorize any platform');
check($draft['variants']['youtube']['privacy']==='private','YouTube visibility is explicit and conservative');
$v=$draft['variants']['instagram'];$v['enabled']=true;$v['media_url']='https://example.com/image.jpg';$v['media_type']='image';$v['alt_text']='Prototype on a table.';$v['text']='The first prototype.';
publishing_validate_variant('instagram',$v,['method'=>'direct']);check(true,'Direct Instagram JPEG variant validates');
$v['media_type']='video';rejects(fn()=>publishing_validate_variant('instagram',$v,['method'=>'direct']),'Direct Instagram does not silently discard unsupported video');
$v=$draft['variants']['x'];$v['text']=str_repeat('a',281);rejects(fn()=>publishing_validate_variant('x',$v,['method'=>'manual']),'Caption constraints are reviewed before scheduling');
$v['text']='Valid text';$v['media_url']='https://example.com/photo.jpg';$v['alt_text']='A photo.';rejects(fn()=>publishing_validate_variant('x',$v,['method'=>'direct']),'Direct X does not silently drop media');
$v['media_url']='';$v['format']='story';rejects(fn()=>publishing_validate_variant('facebook',$v,['method'=>'direct']),'Direct Facebook never turns an approved Story into a feed post');

echo "Publishing policy tests passed: {$count}\n";

check(publishing_client_namespace('facebook_profile')==='facebook_profile','Personal Facebook Login uses a dedicated credential namespace');
check(publishing_client_namespace('facebook_pages')==='facebook','Existing Page credentials keep their namespace');
check(publishing_client_namespace('instagram_insights')==='instagram','Instagram grants retain the existing app credentials');
rejects(fn()=>publishing_client_namespace('unsupported'),'Unknown services cannot choose arbitrary credential settings');

function setting(string $key, mixed $default=''): mixed { return $GLOBALS['clientFixture'][$key] ?? $default; }
$GLOBALS['clientFixture']=['publishing.facebook.client_id'=>'page-app','publishing.facebook.client_secret'=>'page-secret'];
check(publishing_service_client('facebook_profile')===['client_id'=>'','client_secret'=>''],'Page credentials cannot silently authorise a personal profile');
$GLOBALS['clientFixture']['publishing.facebook_profile.client_id']='personal-app';
$GLOBALS['clientFixture']['publishing.facebook_profile.client_secret']='personal-secret';
check(publishing_service_client('facebook_profile')['client_id']==='personal-app','Personal Login selects its dedicated app');
check(publishing_service_client('facebook_pages')['client_id']==='page-app','Personal Login setup does not replace the Page app');

check(social_options_input('instagram',['location_id'=>'123','user_tags'=>'fixture,0.5,0.25'])['location_id']==='123','Instagram location IDs and bounded image tag coordinates normalize');
$rejected=false;try{social_instagram_tags('fixture,2,0');}catch(InvalidArgumentException){$rejected=true;}check($rejected,'Image tags reject coordinates outside image bounds');
$rejected=false;try{social_options_validate('facebook',['platform_options'=>['audience'=>'Friends']],['method'=>'direct']);}catch(RuntimeException){$rejected=true;}check($rejected,'Native audience setting cannot silently become a public API post');
social_options_validate('facebook',['platform_options'=>['audience'=>'Friends','location_label'=>'Nairobi']],['method'=>'manual']);
rejects(fn()=>social_instagram_parameters(['media_url'=>'https://example.com/image.jpg','text'=>'Fixture','alt_text'=>'Fixture','platform_options'=>['location_id'=>'123','user_tags'=>'fixture,0.5,0.5']]),'Instagram Login tags cannot silently pass as implemented native options');
social_options_validate('instagram',['platform_options'=>['location_id'=>'123','user_tags'=>'fixture,0.5,0.5']],['method'=>'manual']);check(true,'Assisted Instagram preserves native tag requirements');
check(publishing_job_label(['status'=>'delivered','destination_json'=>'{"slug":"instagram"}','provider_state_json'=>'{"adapter":"instagram_native_v2","phase":"verification_pending"}'])==='Publication ID received · Verifying','Instagram receipt is not mislabeled verified publication');
$reelBuffer=array_replace(publishing_default_draft()['variants']['instagram'],['text'=>'Reviewed Reel','format'=>'reel','media_type'=>'video','media_url'=>'https://example.com/video.mp4','instagram_settings_confirmed'=>true]);
rejects(fn()=>publishing_validate_variant('instagram',$reelBuffer,['method'=>'buffer']),'Native Reel settings cannot be silently omitted by Buffer');
$subscriber=publishing_default_draft()['variants']['facebook'];$subscriber['enabled']=true;$subscriber['text']='An approved subscriber insight';$subscriber['audience']='subscribers';
rejects(fn()=>publishing_validate_variant('facebook',$subscriber,['method'=>'direct']),'Subscriber content cannot leak through public Facebook API delivery');
rejects(fn()=>publishing_validate_variant('facebook',$subscriber,['method'=>'buffer']),'Subscriber content cannot leak through Buffer');
publishing_validate_variant('facebook',$subscriber,['method'=>'manual']);check(true,'Subscriber audience remains available for assisted Facebook');
check(publishing_owner_control('/write/connections/community','POST'),'Only the publication owner can change Connect destination');
$withAudience=publishing_default_draft();$withAudience['title']='Audience fixture';$withAudience['variants']['facebook']=$subscriber;$withAudience['variants']['x']['video_settings_confirmed']=true;
$normal=publishing_normalize_draft($withAudience);check($normal['variants']['facebook']['audience']==='subscribers'&&$normal['variants']['x']['video_settings_confirmed']===true,'Audience and media consent survive normalization');
check(!in_array('media.write',publishing_services()['x']['scopes'],true)&&in_array('media.write',publishing_services()['x_media']['scopes'],true),'Video grant does not silently widen existing X text authorization');
echo "PASS: platform-specific options retained, validated and never silently dropped by unsupported delivery routes.\n";
