<?php
declare(strict_types=1);

/** Pure validation and rendering checks. No configuration file, DB or network. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$CONFIG=['app_env'=>'testing','timezone'=>'Africa/Nairobi','base_url'=>'https://www.waithakandegwa.com',
    'mail'=>['from'=>'newsletter@example.com','from_name'=>'Newsletter fixture','reply_to'=>'reply@example.com','postal_address'=>'Synthetic fixture address']];
$APP_INSTALLED=false;
require_once __DIR__.'/../app/functions.php';
require_once __DIR__.'/../app/newsletter.php';
$checks=0;
function nl_unit_check(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException('FAIL: '.$label);$checks++;}
function nl_unit_reject(callable $action,string $label):void{try{$action();}catch(NewsletterValidationException|NewsletterConflictException $error){nl_unit_check(true,$label);return;}nl_unit_check(false,$label);}

nl_unit_check(newsletter_public_url('https://example.com/article?utm_source=writing'),'Ordinary HTTPS campaign links remain valid.');
nl_unit_check(newsletter_public_url('mailto:reply@example.com'),'A plain reply address is allowed.');
nl_unit_check(!newsletter_public_url('mailto:reply@example.com?body=private'),'Mailto commands are rejected.');
foreach([
    'http://example.com/article','https://user:secret@example.com/article','https://example.com:444/article',
    'https://127.0.0.1/a','https://192.168.1.2/a','https://[::1]/a','https://app.internal/a',
    'https://example.com/write/preview','https://example.com/payments/paypal?order=1',
    'https://example.com/newsletter/confirm','https://example.com/newsletter/unsubscribe',
    'https://example.com/newsletter/one-click','https://example.com/article?preview=1',
    'https://example.com/article?%2574oken=private','https://example.com/article?X-Amz-Signature=private',
    'https://example.com/article?X-Goog-Credential=private','https://example.com/article?Key-Pair-Id=private',
    'https://example.com/article?state=private','https://example.com/article?code=private',
    'https://example.com/article?api_key=private','https://example.com/%2577rite/preview',
    'https://example.com/a'."\r\n".'Injected: value',
]as$link)nl_unit_check(!newsletter_public_url($link),'Private capability or unsafe URL is rejected: '.strtok($link,'?'));
$CONFIG['base_url']='http://127.0.0.1:8123';
nl_unit_check(newsletter_public_url('http://127.0.0.1:8123/my-thoughts/fixture'),'The exact isolated test origin is allowed.');
nl_unit_check(!newsletter_public_url('http://127.0.0.1:8124/fixture'),'Different loopback test origins are rejected.');
$CONFIG['app_env']='production';
nl_unit_check(!newsletter_public_url('http://127.0.0.1:8123/fixture'),'The loopback exception is unavailable in production.');
$CONFIG['app_env']='testing';$CONFIG['base_url']='https://www.waithakandegwa.com';

$clean=newsletter_body('<p onclick="bad()">Keep <strong>these words</strong>.</p><script>bad()</script><a href="/my-thoughts/fixture">Read</a><img src="https://example.com/photo.jpg" onerror="bad()">');
nl_unit_check(!preg_match('/<script|onclick|onerror/i',$clean),'Email HTML removes scripts and event handlers.');
nl_unit_check(str_contains($clean,'https://www.waithakandegwa.com/my-thoughts/fixture'),'Relative public article links become absolute.');
nl_unit_check(str_contains($clean,'max-width:100%;height:auto'),'Email images receive bounded rendering dimensions.');
nl_unit_check(newsletter_body($clean)===$clean,'Sanitized email HTML is stable across saved preview and approval.');
nl_unit_reject(fn()=>newsletter_body('<a href="https://example.com/?token=secret">Private</a>'),'Private capability links cannot enter an edition.');
nl_unit_reject(fn()=>newsletter_body(str_repeat('x',180001)),'Oversized email HTML is rejected.');
nl_unit_reject(fn()=>newsletter_values(['title'=>'Fixture','subject'=>"Subject\r\nBcc: outside@example.com"]),'Subject header injection is rejected.');
nl_unit_reject(fn()=>newsletter_values(['title'=>['not a scalar']]),'Array form values are rejected.');
nl_unit_reject(fn()=>newsletter_values(['title'=>str_repeat('x',181)]),'Draft titles are bounded.');
nl_unit_check(newsletter_values(['title'=>'Work in progress'])['subject']==='','Incomplete private drafts can be saved.');
nl_unit_check(newsletter_id('',true)===null&&newsletter_id('42')===42,'Optional IDs and valid versions parse explicitly.');
foreach(['-1','0','1.5','1e3','2147483648',[]]as$id)nl_unit_reject(fn()=>newsletter_id($id),'Malformed identifiers do not reach SQL.');

$draft=newsletter_values(['title'=>'Fixture','subject'=>'Exact approved subject','preheader'=>'Inbox fixture','body_html'=>'<p>Reviewed words.</p>'])+['draft_version'=>1];
$snapshot=newsletter_snapshot($draft);$preview=newsletter_html($snapshot);
nl_unit_check($snapshot['sender']['from']==='newsletter@example.com'&&$snapshot['draft_version']===1,'Approval snapshots include sender identity and saved version.');
nl_unit_check(str_contains($preview,'Synthetic fixture address')&&str_contains($preview,'unique to each recipient'),'Saved preview contains mailing address and honest unsubscribe placeholder.');
nl_unit_check(!str_contains($preview,'?token=')&&!str_contains($preview,'List-Unsubscribe'),'Saved preview never creates a recipient capability.');
$CONFIG['mail']['postal_address']='';
nl_unit_reject(fn()=>newsletter_snapshot($draft),'Approval requires a configured mailing address.');
$CONFIG['mail']['postal_address']='Synthetic fixture address';$CONFIG['mail']['from_name']="Fixture\r\nInjected";
nl_unit_reject(fn()=>newsletter_snapshot($draft),'Sender header injection cannot be approved.');
$CONFIG['mail']['from_name']='Newsletter fixture';
nl_unit_reject(fn()=>newsletter_snapshot(array_replace($draft,['subject'=>''])),'Empty subjects cannot be approved.');
nl_unit_reject(fn()=>newsletter_snapshot(array_replace($draft,['body_html'=>'<img src="https://example.com/p.jpg">'])),'An image-only email must include accessible explanatory text before approval.');
$later=(new DateTimeImmutable('+1 hour',new DateTimeZone('Africa/Nairobi')))->format('Y-m-d\TH:i');
nl_unit_check(newsletter_schedule_time($later)===(new DateTimeImmutable($later,new DateTimeZone('Africa/Nairobi')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),'The local scheduling field is converted to UTC.');
nl_unit_reject(fn()=>newsletter_schedule_time('2026-02-31T12:00'),'Invalid calendar dates are rejected.');
nl_unit_reject(fn()=>newsletter_schedule_time('2000-01-01T00:00'),'Past schedules are rejected.');
nl_unit_reject(fn()=>newsletter_schedule_time((new DateTimeImmutable('+2 years'))->format('Y-m-d\TH:i')),'Unbounded distant schedules are rejected.');

$payload=['to'=>'Reader@example.com','subject'=>'Fixture','html'=>'<p>Body</p>','headers'=>['List-Unsubscribe'=>'<https://example.com/newsletter/one-click?token=opaque>','List-Unsubscribe-Post'=>'List-Unsubscribe=One-Click']];
nl_unit_check(newsletter_envelope_hash($payload)===newsletter_envelope_hash(array_replace($payload,['to'=>' reader@example.com ','headers'=>array_reverse($payload['headers'],true)])),'Envelope identity tolerates address case and header map order only.');
nl_unit_check(newsletter_envelope_hash($payload)!==newsletter_envelope_hash(array_replace($payload,['html'=>'<p>Changed body</p>'])),'Changing approved email content changes the envelope fingerprint.');
$candidate=['email'=>'reader@example.com','status'=>'confirmed','confirmed_at'=>'2026-10-21 12:00:00','suppressed_at'=>null,'consent_revision'=>5];
$delivery=['email_hash'=>newsletter_email_hash($candidate['email']),'consent_revision'=>5];
nl_unit_check(newsletter_recipient_eligible($candidate,$delivery),'An unchanged confirmed subscriber remains eligible.');
foreach([
    ['status'=>'pending'],['confirmed_at'=>null],['suppressed_at'=>'2026-10-21 12:00:00'],
    ['email'=>'different@example.com'],['consent_revision'=>6],['email'=>'invalid'],
]as$change)nl_unit_check(!newsletter_recipient_eligible(array_replace($candidate,$change),$delivery),'Changed consent or address cannot revive a frozen audience entry.');
nl_unit_check(newsletter_delivery_key('newsletter-campaign:'.str_repeat('a',64))===str_repeat('a',64),'Campaign dedupe keys parse only their expected prefix.');
nl_unit_check(newsletter_delivery_key('newsletter-confirm:'.str_repeat('a',64))===null,'Confirmation tokens cannot identify campaign deliveries.');
echo "PASS: {$checks} newsletter unit assertions\n";
