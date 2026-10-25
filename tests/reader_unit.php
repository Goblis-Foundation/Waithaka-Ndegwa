<?php
declare(strict_types=1);

/** Pure capability and boundary tests. No DB, configuration file or network. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$CONFIG = ['app_env' => 'testing', 'app_key' => 'reader-unit-key-only-not-for-production', 'base_url' => 'https://www.waithakandegwa.com', 'timezone' => 'Africa/Nairobi'];
$APP_INSTALLED = false;
foreach (['functions', 'journeys', 'newsletter', 'reader', 'contact_experience'] as $module) require_once __DIR__.'/../app/'.$module.'.php';
$checks = 0;
function reader_unit_check(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException('FAIL: '.$label); $checks++; }
function reader_unit_reject(callable $action, string $label): void { try { $action(); } catch (ReaderValidationException $error) { reader_unit_check(true, $label); return; } reader_unit_check(false, $label); }

reader_unit_check(reader_text("  A\r\nB  ", 'Text', 10) === "A\nB", 'Text normalizes line endings without discarding readable content.');
foreach ([['bad'], "bad\0text", "bad\x1Btext", "\xff", str_repeat('a', 11)] as $value) reader_unit_reject(fn() => reader_text($value, 'Text', 10), 'Malformed or oversized text is rejected.');
reader_unit_reject(fn() => reader_text(' ', 'Text', 10, true), 'Required content cannot be whitespace.');
reader_unit_check(reader_ids(['4', 2, '4']) === [2, 4], 'Preference identifiers are unique and deterministically ordered.');
foreach (['0', -1, '1.1', '1e3', '2147483648', [], '1x'] as $value) reader_unit_reject(fn() => reader_id($value), 'Invalid identifiers cannot reach SQL.');
reader_unit_reject(fn() => reader_ids('1'), 'A scalar cannot stand in for a list.');
reader_unit_reject(fn() => reader_ids([1, 2, 3], 2), 'Preference list size is bounded before querying.');

$now = time(); $nonce = str_repeat('a', 64); $token = reader_sign_link('preferences', $nonce, $now + 3600);
$parsed = reader_parse_link($token, 'preferences', $now);
reader_unit_check($parsed !== null && $parsed['nonce_hash'] === hash('sha256', $nonce), 'A signed capability is scoped to its opaque nonce.');
reader_unit_check(!str_contains($token, '@') && !str_contains($token, 'subscriber'), 'No subscriber address or identity is embedded.');
reader_unit_check(reader_parse_link($token, 'founder', $now) === null, 'A preference capability cannot authorize founder access.');
reader_unit_check(reader_parse_link($token.'x', 'preferences', $now) === null, 'Trailing token data is rejected.');
reader_unit_check(reader_parse_link(str_replace($nonce, str_repeat('b', 64), $token), 'preferences', $now) === null, 'Nonce substitution invalidates the signature.');
reader_unit_check(reader_parse_link(reader_sign_link('preferences', $nonce, $now), 'preferences', $now) === null, 'Expiry is exclusive at the exact boundary.');
reader_unit_check(reader_parse_link(reader_sign_link('preferences', $nonce, $now + 32 * 86400), 'preferences', $now) === null, 'Signed capabilities beyond the maximum lifetime are rejected.');
reader_unit_check(reader_parse_link($token, 'unknown', $now) === null, 'Unknown token purposes fail closed.');
$CONFIG['app_key'] = 'reader-rotated-fixture-key-not-production';
reader_unit_check(reader_parse_link($token, 'preferences', $now) === null, 'An application-key change invalidates previous signatures.');
$CONFIG['app_key'] = 'reader-unit-key-only-not-for-production';

reader_unit_check(reader_preference_matches(['receive_all' => 1], []), 'All-writing subscribers remain eligible for standalone editions.');
reader_unit_check(!reader_preference_matches(['receive_all' => 0, 'topic_ids' => [], 'journey_ids' => []], ['topic_ids' => [1]]), 'No choices means no matching edition.');
reader_unit_check(reader_preference_matches(['receive_all' => 0, 'topic_ids' => [1]], ['topic_ids' => [1], 'journey_ids' => [2]]), 'A topic match alone is sufficient.');
reader_unit_check(reader_preference_matches(['receive_all' => 0, 'journey_ids' => [2]], ['topic_ids' => [1], 'journey_ids' => [2]]), 'A journey match alone is sufficient.');
reader_unit_check(!reader_preference_matches(['receive_all' => 0, 'topic_ids' => [1]], ['topic_ids' => [2]]), 'Unrelated topics do not match.');
reader_unit_check(!reader_preference_matches(['receive_all' => 0, 'topic_ids' => [1]], []), 'Old approvals without scope cannot broaden a restricted audience.');

$contact = ['reference' => 'WK-FIXTURE-12345', 'first_name' => 'Reader', 'email' => 'reader@example.invalid', 'message' => 'An editorial question.'];
$values = reader_contact_values($contact, ['enquiry_type' => 'correction']);
reader_unit_check($values['enquiry_type'] === 'correction' && $values['phone'] === '' && $values['last_name'] === '', 'A useful enquiry does not require unnecessary phone or family-name data.');
reader_unit_reject(fn() => reader_contact_values($contact, ['enquiry_type' => 'subscribe']), 'Enquiry types cannot request a marketing subscription.');
reader_unit_reject(fn() => reader_contact_values(array_replace($contact, ['email' => "a@example.com\r\nBcc: b@example.com"])), 'Contact address header injection is rejected.');
reader_unit_reject(fn() => reader_contact_values(array_replace($contact, ['reference' => '../private'])), 'Contact references have a bounded safe shape.');
reader_unit_reject(fn() => reader_contact_values(array_replace($contact, ['first_name' => ['invalid']])), 'Array-shaped contact fields are rejected.');

foreach (['/newsletter/preferences', '/newsletter/preferences/request', '/founders/follow-up', '/founders/follow-up/request', '/%2566ounders/follow-up'] as $path) reader_unit_check(!newsletter_public_url('https://www.waithakandegwa.com'.$path), 'Private reader service URLs cannot enter public email content.');
$snapshot = ['subject' => 'Fixture', 'preheader' => '', 'body_html' => '<p>Approved text.</p>', 'site_url' => 'https://www.waithakandegwa.com', 'sender' => ['name' => 'Fixture', 'postal_address' => 'Synthetic address']];
$preview = newsletter_html($snapshot);
reader_unit_check(str_contains($preview, 'Email preferences') && !str_contains($preview, '?token='), 'Email previews describe preferences without generating capabilities.');
$rendered = newsletter_html($snapshot, 'https://example.com/unsubscribe', 'https://example.com/preferences?a=1&b=2');
reader_unit_check(str_contains($rendered, 'preferences?a=1&amp;b=2'), 'Recipient preference URLs are escaped in delivery HTML.');
echo 'Reader pure checks: '.$checks." passed.\n";


$contactDraft=contact_draft_values(['first_name'=>'  Synthetic  ','message'=>'Private draft <text>','enquiry_type'=>'speaking','email'=>['bad'],'csrf'=>'must-not-save','not_robot'=>'1']);reader_unit_check($contactDraft['first_name']==='Synthetic'&&$contactDraft['message']==='Private draft <text>','Failed form values preserve author text for escaped rendering');reader_unit_check($contactDraft['email']===''&&!isset($contactDraft['csrf'])&&!isset($contactDraft['not_robot']),'Draft retains only bounded form fields, never captcha/session controls');reader_unit_check(contact_draft_values(['enquiry_type'=>'unsupported'])['enquiry_type']==='general','Unknown enquiry purpose cannot alter routing');reader_unit_check(array_keys(contact_guides())===['collaboration','speaking','media','reader_question','correction','general'],'Purpose paths map to existing typed enquiries');reader_unit_check(contact_form_nonce(str_repeat('a',32))!==''&&contact_form_nonce([])===''&&contact_form_nonce('invalid')==='','Only bounded form tokens can deduplicate successful receipts');
