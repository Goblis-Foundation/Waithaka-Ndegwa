<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/functions.php';require dirname(__DIR__).'/app/reader.php';require dirname(__DIR__).'/app/communication.php';require dirname(__DIR__).'/app/planner.php';require dirname(__DIR__).'/app/workspace_reports.php';require dirname(__DIR__).'/app/studio.php';
function wc(bool $v,string $why):void{if(!$v)throw new RuntimeException($why);}
function wx(callable $fn):void{try{$fn();}catch(InvalidArgumentException){return;}throw new LogicException('Expected invalid input.');}
$r=planner_routine_input(['timezone'=>'Africa/Nairobi','routine'=>"1,2,3,4,5,6,7 | 22:00 | 06:00 | Sleep | personal\n1,3 | 09:00 | 10:00 | Focus | focus"]);$rows=planner_routine_expand($r,'2026-11-04');wc(count($rows)===9,'Seven sleep blocks plus two focus blocks');wc($rows[0]['end']-$rows[0]['start']===8*3600,'Sleep crosses midnight');wc($rows[0]['recipients']===[]&&$rows[0]['reminder']===0,'Routine cannot send invitations or flood reminders');wc((new DateTimeImmutable('@'.$rows[0]['start']))->setTimezone(comm_zone('Africa/Nairobi'))->format('Y-m-d')==='2026-11-02','Week anchored to Monday');
foreach(["8 | 09:00 | 10:00 | Invalid | personal","1 | 25:00 | 06:00 | Invalid | personal","1 | 09:00 | 09:00 | Invalid | personal","1 | 09:00 | 10:00 | Invalid | execute"]as$text)wx(fn()=>planner_routine_input(['routine'=>$text]));
$structured=['timezone'=>'Africa/Nairobi','routine_complete'=>'1','routine_rows'=>[['days'=>[1,2],'start'=>'06:00','end'=>'07:00','title'=>'Wake up | prepare','kind'=>'personal']]];wc(planner_routine_input($structured)['rows'][0]['title']==='Wake up | prepare','Structured form preserves normal punctuation');wx(fn()=>planner_routine_input(array_replace($structured,['routine_complete'=>''])));wx(fn()=>planner_routine_input(array_replace($structured,['routine_rows'=>[['days'=>[],'start'=>'06:00','end'=>'07:00','title'=>'Empty days','kind'=>'personal']]])));
$d=planner_routine_input(['timezone'=>'America/New_York','routine'=>'7 | 02:30 | 03:30 | Gap | personal']);wx(fn()=>planner_routine_expand($d,'2026-03-08'));
$base=['title'=>'Place','start'=>'2026-11-02T09:00','end'=>'2026-11-02T10:00','location'=>'Nairobi','latitude'=>'-1','longitude'=>'36'];$search=planner_fields($base+['location_mode'=>'search'],'Africa/Nairobi');wc($search['latitude']===''&&str_contains(planner_maps($search),'query=Nairobi'),'Search mode ignores stale coordinates');$coord=planner_fields($base+['location_mode'=>'coordinates'],'Africa/Nairobi');wc($coord['location']===''&&str_contains(planner_maps($coord),'query=-1%2C36'),'Coordinate mode ignores stale search');wx(fn()=>planner_fields(array_replace($base,['location_mode'=>'coordinates','longitude'=>'']),'Africa/Nairobi'));
$b=['first'=>'Synthetic','last'=>'Visitor','agenda'=>'Discuss an idea'];$brief=booking_brief_initial($b);wc(booking_report_ready($brief),'Agenda-only briefing ready without AI');wc(!booking_report_ready(booking_brief_initial($b+['document'=>['name'=>'fixture.pdf']])),'Attachment blocks approval until report exists');wc(!booking_report_ready(['brief_status'=>'ready','brief_report'=>' ']),'Empty report cannot approve');wc(booking_document_upload([],false)===[],'No attachment needs no analysis consent');wx(fn()=>booking_document_upload(['error'=>UPLOAD_ERR_OK],false));
$layout=file_get_contents(dirname(__DIR__).'/views/layout.php');wc(!str_contains($layout,'document_record.php'),'No QR inserted on public pages');$booking=file_get_contents(dirname(__DIR__).'/views/booking.php');wc(!str_contains($booking,'preferred_time')&&!str_contains($booking,'preferred_date'),'Booking has no visitor-proposed time fallback');wc(str_contains($booking,'booking_document')&&str_contains($booking,'enctype="multipart/form-data"'),'Attachment form supported');
echo "PASS: weekly overnight routines, DST rejection, Maps input choice, briefing approval gate and public QR removal.\n";

wc(array_keys(booking_platforms())===['google_meet','phone','zoom','microsoft','webex','other'],'Requested platform display order');wc(booking_platform_input([])['platform']==='google_meet','Meet is default');wx(fn()=>booking_platform_input(['platform'=>'phone','platform_contact'=>'missing country code']));wx(fn()=>booking_platform_input(['platform'=>'other']));wx(fn()=>booking_platform_input(['platform'=>'signal','platform_contact'=>'@fixture']));
$fixture=['id'=>str_repeat('a',32),'type'=>'intro','agenda'=>'Synthetic','start'=>1793606400,'end'=>1793608200,'email'=>'fixture@example.invalid','first'=>'Synthetic','last'=>'Visitor'];$calendarCfg=['types'=>['intro'=>['name'=>'Intro']]];wc(isset(booking_calendar_body($fixture,$calendarCfg)['conferenceData']),'Default calendar request creates Meet');
$zoom=$fixture+['platform'=>'zoom','approved_join'=>'https://zoom.us/j/123456789'];$payload=booking_calendar_body($zoom,$calendarCfg);wc(!isset($payload['conferenceData'])&&$payload['location']===$zoom['approved_join'],'Zoom invitation uses approved link without an extra Meet');wc(!booking_platform_ready(['platform'=>'zoom'])&&booking_platform_ready($zoom),'Hosted alternatives need approved join link');
echo "PASS: requested meeting platform order, defaults, contact validation and provider-specific invitation payloads.\n";

$context=booking_ai_context(['agenda'=>'Synthetic agenda','platform'=>'phone','platform_contact'=>'+254700000000','email'=>'private@example.invalid','platform_detail'=>'Private address']);wc(!str_contains($context,'+254700000000')&&!str_contains($context,'private@example.invalid')&&!str_contains($context,'Private address'),'Separate booking contacts and arrangement details excluded from AI context');

wc(preg_match('/^[a-v0-9]{5,1024}$/D',booking_event_id($fixture))===1,'Calendar event id obeys Google base32hex contract');wc(booking_calendar_body($fixture,$calendarCfg)['id']===booking_event_id($fixture),'Creation and conflict recovery use identical deterministic ids');

wc(booking_document_provider([])==='openai','New booking forms default to the working OpenAI provider');
wc(booking_document_request_provider([])==='gemini','Legacy form consent stays with Gemini when provider snapshot is absent');
wc(booking_document_request_provider(['document_provider'=>'openai'])==='openai','New form provider snapshot is retained');
wx(fn()=>booking_document_request_provider(['document_provider'=>'unrecognised']));

wc(voice_format('audio/mp4','clip.m4a')===['m4a','audio/m4a'],'Safari audio MIME normalized for Gemini');
wx(fn()=>voice_format('text/html','clip.mp3'));wx(fn()=>voice_format('audio/mpeg','clip.html'));
$audio=voice_gemini_input('synthetic bytes','audio/webm');wc($audio[1]['type']==='audio'&&!isset($audio[1]['uri'])&&base64_decode($audio[1]['data'])==='synthetic bytes','Audio stays inline with no remote URL');
wx(fn()=>voice_gemini_input(str_repeat('x',4*1024*1024+1),'audio/webm'));wx(fn()=>voice_id('../another-user'));
$stale=false;try{voice_revision(['version'=>2],['version'=>1]);}catch(RuntimeException){$stale=true;}wc($stale,'Stale transcripts cannot overwrite newer edits');
$html=workspace_rich_text("# Heading\n\n**Important** and *careful*\n\n- First\n- Second\n\n1. Next\n\n> Quoted\n<script>alert(1)</script>");
wc(str_contains($html,'<h2>Heading</h2>')&&str_contains($html,'<strong>Important</strong>')&&str_contains($html,'<ul><li>First</li>')&&str_contains($html,'<ol><li>Next</li>'),'Notes formatting keeps semantic headings emphasis and lists');
wc(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'Raw note HTML is always escaped');
echo "PASS: private voice format bounds, inline AI payload, ownership identifiers, versions and escaped formatting.\n";

wc(!str_contains($booking,'document_consent')&&!str_contains($booking,'document_provider')&&!str_contains($booking,'sent to OpenAI'),'Internal AI workflow is absent from public booking form');
$manual=booking_brief_initial($b+['document'=>['name'=>'fixture.pdf','ai_consent'=>false]]);
wc($manual['brief_status']==='needs review'&&!booking_report_ready($manual),'Private attachments without AI authorisation require host review and never queue automatically');
$prior=booking_brief_initial($b+['document'=>['name'=>'fixture.pdf','ai_consent'=>true]]);
wc($prior['brief_status']==='pending','Existing explicitly consented attachments retain their processing path');
wc(wise_giving_iban('DE89370400440532013000'),'IBAN checksum valid');
wc(!wise_giving_iban('DE89370400440532013001'),'IBAN typo rejected');
wx(fn()=>wise_giving_validate('USD',['enabled'=>1,'holder'=>'Fixture','bank'=>'Fixture','routing'=>'000000000','account'=>'12345678','account_type'=>'checking','bank_address'=>'Fixture address']));
$gb=wise_giving_validate('GBP',['enabled'=>1,'holder'=>'Fixture','bank'=>'Fixture','sort_code'=>'12-34-56','account'=>'12345678','bank_address'=>'Fixture bank address']);
wc($gb['sort_code']==='123456','UK sort code preserves all digits');
wx(fn()=>wise_giving_validate('GBP',['enabled'=>1,'holder'=>'Fixture','bank'=>'Fixture','sort_code'=>'123456','account'=>'1234567']));
wc(!wise_giving_validate('EUR',[])['enabled'],'Unconfigured currencies stay disabled');
echo "PASS: private briefing controls, attachment review gate and Wise receiving validation.\n";

$key=openssl_pkey_new(['private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);$pub=openssl_pkey_get_details($key)['key'];$raw='{"fixture":"signed"}';openssl_sign($raw,$sig,$key,OPENSSL_ALGO_SHA256);
wc(wise_api_signature($raw,base64_encode($sig),$pub),'Valid Wise-style RSA signature accepted');
wc(!wise_api_signature($raw.' ',base64_encode($sig),$pub),'Tampered event rejected');
wc(!wise_api_signature($raw,'invalid-base64!',$pub),'Malformed signature rejected');
$cfg=['profile'=>'123','subscription'=>'fixture-sub'];$event=['schema_version'=>'4.0.0','subscription_id'=>'fixture-sub','event_type'=>'balances#update','data'=>['resource'=>['profile_id'=>123],'currency'=>'EUR','amount'=>10,'transaction_type'=>'credit']];
wc(wise_api_webhook_data($event,$cfg,true)['status']==='test only','Test notifications never acknowledge contributions');
wc(wise_api_webhook_data($event,$cfg,false)['status']==='awaiting reconciliation','Signed credits still need contribution reconciliation');
wx(fn()=>wise_api_webhook_data($event,array_replace($cfg,['profile'=>'456']),false));
wx(fn()=>wise_api_webhook_data($event,array_replace($cfg,['subscription'=>'other']),false));
wx(fn()=>wise_api_request([],'POST','/transfers',[]));
wc(openssl_pkey_get_public(wise_api_public_key('production'))!==false&&openssl_pkey_get_public(wise_api_public_key('sandbox'))!==false,'Pinned Wise environment keys are valid');
echo "PASS: Wise webhook signatures, profile binding, test isolation and money-movement operation rejection.\n";

wc(visitor_statistics_number(['rows'=>[['metricValues'=>[['value'=>'0']]]]])===0,'An explicitly measured zero remains zero');
wc(visitor_statistics_number([])===null,'Missing analytics does not fabricate zero');
$thresholded=['rows'=>[['metricValues'=>[['value'=>'7']]]],'metadata'=>['subjectToThresholding'=>true]];
wc(visitor_statistics_number($thresholded)===null,'Thresholded aggregate withheld');
$streams=[['name'=>'properties/123/dataStreams/456','webStreamData'=>['measurementId'=>'G-FIXTURE','defaultUri'=>'https://www.example.invalid']]];
wc(visitor_statistics_binding($streams,'G-FIXTURE','www.example.invalid')['id']==='456','Footer binds to exact deployed tag and host');
$threw=false;try{visitor_statistics_binding($streams,'G-FIXTURE','different.example.invalid');}catch(RuntimeException){$threw=true;}wc($threw,'Wrong website stream rejected');
echo "PASS: footer aggregate binding and missing/thresholded data handling.\n";

$ke=booking_platform_input(['platform'=>'phone','phone_code'=>'+254','phone'=>'0712 345 678','country'=>'Kenya','city'=>'Nairobi']);
wc($ke['phone']==='+254712345678'&&$ke['platform_contact']===$ke['phone'],'One Phone / WhatsApp method uses normalised Kenyan contact');
wc($ke['country']==='Kenya'&&$ke['city']==='Nairobi','Country and city saved in private booking');
$global=booking_contact_input(['phone_code'=>'+254','phone'=>'+442079460123','country'=>'United Kingdom','city'=>'London']);
wc($global['phone']==='+442079460123','Full international phone overrides default calling code');
wc(booking_contact_input([])['country']==='Kenya'&&booking_contact_input([])['phone_code']==='+254','Kenyan defaults');
wx(fn()=>booking_platform_input(['platform'=>'phone']));wx(fn()=>booking_contact_input(['phone_code'=>'+0','phone'=>'712345678']));
wc(booking_method_text(['platform'=>'whatsapp','platform_contact'=>'+254712345678'])==='WhatsApp · contact: +254712345678','Historical WhatsApp bookings keep their original method');
echo "PASS: unified Phone / WhatsApp, Kenya defaults, international contact parsing and legacy method display.\n";

$providerFields=integration_hub_values('paypal',['client_id'=>'fixture-id','secret'=>'fixture-secret','environment'=>'sandbox','mpesa_shortcode'=>'unexpected']);
wc(array_keys($providerFields)===['paypal.client_id','paypal.secret','paypal.environment'],'Focused connection setup cannot alter another provider');
wc(integration_hub_values('paypal',['client_id'=>'','secret'=>'','environment'=>'sandbox'])===['paypal.environment'=>['value'=>'sandbox','secret'=>false]],'Blank credentials retain saved secrets');
wx(fn()=>integration_hub_values('paypal',['environment'=>'invalid']));
wx(fn()=>integration_hub_values('unknown',[]));
wx(fn()=>integration_hub_values('openai',['api_key'=>"fixture".chr(10)."secret"]));
echo "PASS: provider-scoped integration settings and retained secrets.\n";

foreach(['EUR','USD','GBP']as$currency)wc(isset(wise_giving_fields($currency)['bank_address']),'Every Wise currency includes bank address');
wc(str_contains(file_get_contents(dirname(__DIR__).'/views/write/wise.php'),'Bank address exactly as shown in Wise'),'Bank address uses a multiline area with source guidance');
echo "PASS: EUR SEPA, USD ACH and GBP UK bank address fields.\n";
