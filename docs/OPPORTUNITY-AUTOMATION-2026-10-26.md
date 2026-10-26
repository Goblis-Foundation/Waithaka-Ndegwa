# Opportunity automation, evidence and delivery

This completes the three software features deferred after PR55. External WhatsApp activation remains dependent on an eligible Cloud API sender, access token, app secret, webhook verification token and an approved opportunity-specific utility template. Existing Facebook/Instagram sessions are not substituted for these credentials.

## Publication
- Owner can schedule only an exact founder-approved listing, explicitly confirming publication. An IANA timezone is recorded; default is Africa/Nairobi. Publication must be at least one minute ahead and before the application deadline.
- Existing minute worker checks due schedules. It rechecks owner identity, approved digest, deadline and schedule state under the same catalogue lock/transaction as publication and the notification queue. No worker or new infrastructure is provisioned.
- Editing scheduled content cancels the schedule and invalidates approval. Cancelling just the schedule keeps the listing private and approved. Founder can withdraw through a renewed private status link. Delayed workers never release an expired role.
- Scheduled listings have no public projection or job markup before actual release. The posted date is the actual release time. Repeated workers cannot publish the same schedule twice.

## Evidence
- Initial submission and editable private follow-up responses can attach one PDF, PNG or JPEG per response, up to 4 MB; at most five files per opportunity. Owner can attach evidence during editorial review. Permission is required.
- Server validates upload origin, error/size and MIME, generates its own filename, and stores in the existing private media location. File hash and metadata stay in the encrypted record. Database failures clean up newly uncommitted uploads.
- Downloads require owner authentication or that exact record's active founder capability, with integrity verification, no-store, no-referrer, noindex, sandbox and attachment-only headers. No public asset URL is created; files are not submitted to AI. This is not an applicant/CV collection area.

## Email
- A scoped opportunity worker delivers only notification IDs belonging to these records, even while unrelated legacy delivery remains paused. It uses the existing transport, recipient suppression and ambiguous-send handling.
- Both scoped and general workers suppress obsolete opportunity-link emails by checking the outbox ID, immutable recipient, current version, digest and active token. Mail transport acceptance is labelled separately from inbox delivery.

## WhatsApp
- Owner-only `/write/opportunities/delivery` has its own Connect controls, encrypted secret inputs, approved template name/language and Graph API version. Stored secrets are never redisplayed. Existing Founder Stories Cloud API sender can be reused explicitly, with a separate opportunity template. Sender-ID verification is read-only and sends no message.
- Automatic review delivery requires all three: configured/enabled sender, explicit founder WhatsApp opt-in with a valid number, and owner selection on that review request. Old/manual requests do not silently become API sends. Held submissions do not send notifications.
- One job per record version. Persist `sending` before the provider call. API acknowledgement is `accepted`, distinct from `sent`, `delivered` and `read`. Timeouts, interrupted workers and unconfirmed acceptance stay `unknown`, never automatically retried. A blocked, definitely unsent job can be explicitly retried after setup. Provider-reported failures are retained for review.
- Callback `/webhooks/whatsapp/opportunities` supports verification-token handshake and raw-body HMAC-SHA256 with the sender app secret. Unsigned requests fail closed. Only status events for a known outgoing provider message, sender and recipient are applied; incoming messages are ignored. Duplicate/out-of-order callbacks do not regress delivery proof.
- Early status callbacks for known in-flight recipients are buffered in a bounded encrypted queue (100 events, one hour) and reconciled once the provider ID is recorded. Unrelated events are not retained. Callback URLs contain no application secret or access token.
- Configure the callback on the correct Meta app and subscribe to WhatsApp messages events. Saving configuration does not prove template approval, webhook subscription or actual delivery. No test message is sent during production QA and no usage/billing settings are changed.

## Tests
Extend pure workflow fixtures for schedule timing, consent invalidation, delayed worker, cancellation, once-only publication, file bounds and path rejection, explicit WhatsApp intent, acceptance vs delivery, no replay, signature tampering, identity matching, duplicate/out-of-order/early callbacks, unknown-send handling and obsolete email suppression.
Extend isolated HTTP checks for multipart upload, owner/capability downloads, private headers, wrong capability, owner delivery setup, CSRF, unsigned webhook and schedule cancellation before the existing publish/withdraw flow. Transports are disabled; only local fixture evidence is created and cleaned up.

## Primary security reference
Meta's own Cloud API SDK documentation describes verification-token GET handshake and raw-body `X-Hub-Signature-256` verification: https://whatsapp.github.io/WhatsApp-Nodejs-SDK/api-reference/webhooks/start/ . The archived SDK is a protocol reference only; no package is added. Current Meta template/status documentation was requested but returned a rate-limit response during research. The implementation uses the existing direct Graph HTTP adapter, with configured version and test fixtures; live provider eligibility and receipts must be verified before claiming production delivery.
