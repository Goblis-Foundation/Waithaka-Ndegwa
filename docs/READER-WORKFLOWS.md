# Reader preferences, enquiries and founder follow-up

This module extends the existing newsletter consent, contact and founder moderation records. It does not import an audience, subscribe enquiry senders, publish founder submissions or send directly to an email provider. All notification requests use the encrypted outbox.

## Newsletter choices

The public `/newsletter` form offers all writing, individual topics and currently public Startup Journeys. The default remains all writing. A reader can clear all writing and choose topics or journeys; either kind of match is sufficient. Choosing none means no new edition matches.

Choices are saved inside the original signup transaction. The subscription remains pending until the existing, address-bound confirmation succeeds. An anonymous repeat signup cannot change an already-confirmed reader's choices. A new signup after unsubscribe still requires the original acknowledgement and a fresh confirmation. Suppression is never cleared by these flows.

`/newsletter/preferences/request` accepts an email address and returns the same acknowledgement for unknown, pending, unsubscribed, suppressed and eligible addresses. Only a currently eligible confirmed address receives a management link. The request is rate-limited and does not reactivate a subscription.

The management page can save choices or explicitly unsubscribe from every edition. Saving preferences preserves subscription status and suppression. A genuine preference change increments the existing `consent_revision`, so editions frozen for the previous choices are skipped before dispatch. A newly matching reader is not added to an already-scheduled audience. A reader who makes no change does not unnecessarily invalidate delivery snapshots.

### Edition audience and approval

At newsletter approval, `reader_audience` freezes the linked public article's topic and its public, published journey membership. Matching happens when the audience is scheduled and is checked again during queueing and immediately before transport, alongside the existing consent, email-address, suppression and revision checks.

- Readers who selected all writing can receive standalone and article-linked editions.
- Restricted readers receive an edition only if its approved topic or journey matches a choice.
- Standalone editions have no topic/journey scope, so they go only to all-writing readers.
- Older approved revisions have no frozen scope. They also go only to all-writing readers; the application does not infer or broaden their scope later.
- Existing subscribers without a preference record retain their prior all-writing behaviour.
- Archived or unavailable previously selected identifiers can be preserved without exposing a private title. They do not make a private journey eligible for a new approved scope.

Each queued edition keeps its existing one-click and human unsubscribe mechanisms and gains a private preferences link. Previewing or approving an edition never creates a real recipient capability.

The Writer campaign handoff `/write/newsletter/new?post=ID` prefills a subject, safe summary and full-article campaign link for a public source. It performs no save, approval or send. A draft or future source retains its selected ID and a publish-first notice, with an empty body and no private article URL. Existing save and approval gates still require a public linked source.

## Signed private links

Reader links use an opaque 256-bit random nonce, an expiry and an HMAC scoped to the action and the installed `APP_KEY`. They contain no email address or database record ID. The database stores only the nonce hash, subject binding and lifecycle metadata; the full URL exists only in the encrypted email payload or the authorized response.

Management-request and founder links last seven days. Per-edition preferences links last thirty days. GET and HEAD are read-only. Human changes require both the signed capability and the ordinary session CSRF token. Preference saves consume the capability and revoke earlier preference links. Founder responses consume their capability and rotate to a new one with the original expiry, without extending its lifetime.

Preferences capabilities bind to the current normalized address and consent revision. Founder capabilities bind to the submitter address. Changed addresses, used or revoked capabilities, expired links, unsubscribe and suppression cannot bypass the relevant access checks. A different token purpose cannot authorize the other workflow.

Private pages send `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex, nofollow, noarchive`; rendering suppresses analytics and uses a token-free canonical URL. Preferences pages do not display the subscriber's address or name. Private service URLs are excluded from authored newsletter links even when no token is present.

Access to a founder capability establishes possession of the private link, not independent founder identity. The editor must still assess identity, evidence and the exact permission scope. Do not share these links, include them in public articles or copy them into analytics, screenshots or source notes. Configure proxy/application access logging to redact token query values. Preserve the installed application key during deployment; rotating it invalidates private signatures and can also make recovered encrypted outbox/provider records unreadable.

## Durable enquiries

The contact form stores an enquiry type independently of the message: general, speaking, collaboration, reader question, media or correction. Name, email and message are required; family name, phone and detailed location are optional.

`reader_contact_submit($message, $input)` validates input, saves the original `contact_messages` record and its work item in one transaction, commits, and then attempts the Writer notification. A notification failure does not undo the saved enquiry or its reference. Queueing does not set `contact_messages.email_sent`; the existing email acknowledgement path remains responsible for that flag.

Writer routes:

- `/write/readers` lists enquiries, with status filters and pagination.
- `/write/readers/enquiries/{id}` shows the original message and records type, status, next action, next-action due date, response history and last response time.
- An actual response is recorded only after the author supplies a note and confirms that it was sent using their email or another agreed channel. The form itself does not send a reply.
- Version checks reject stale edits. Private history records the changes and actor.

The queue exists independently of notification delivery. `notification_state` records the notification enqueue attempt, while `email_sent` records the existing provider acknowledgement. A queued label is not evidence of delivery to an inbox. Use the operations/outbox records for subsequent transport failures or uncertain outcomes.

No enquiry action creates a newsletter subscription. Historical contact records are backfilled into the Writer queue as new items requiring review, without retrospective notifications or guesses about whether the author answered them elsewhere.

## Founder receipts and protected correspondence

An original founder submission is saved by the existing submission function. Its reader hook creates a follow-up record and queues a receipt containing the submission reference and expiring private link. An email failure leaves the original record available for review; the Writer can explicitly queue another link. Repeating the initial receipt hook does not duplicate a successfully queued receipt.

`/founders/follow-up/request` requires the matching reference and submitter email and returns a generic acknowledgement. `/founders/follow-up?token=...` exposes only the reference, venture name, editorial status and correspondence deliberately written in the follow-up workflow. It does not display submitter email, original evidence notes or internal moderation notes.

`/write/readers?queue=founders` lists the protected follow-up work. `/write/readers/founders/{id}` lets the editor:

1. Write a question for the submitter and queue a private link. For a new/in-review record this uses the existing `needs_info` editorial state.
2. Read a private reply, then mark the clarification reviewed.
3. Describe the exact publication scope and request a permission response from a founder.
4. Queue a fresh receipt/follow-up link to the stored address.
5. Open the original `/write/journeys/founders/{id}` moderation form for the actual editorial decision.

A submitter can answer an open question, grant the displayed scope with an explicit acknowledgement, decline it or withdraw permission. Permission records retain the exact scope and response. A nominator cannot grant another person's founder permission. A grant never sets `permission_confirmed`, approves claims or publishes a story; those remain separate existing moderation gates.

A decline or withdrawal clears the founder collection's permission/public flags immediately. A previously published standalone article is a separate editorial record and still needs an explicit removal/correction review; the follow-up page explains that limit. Opening a new permission request also clears the collection's previous permission/public flags and advances its moderation version. The earlier evidence reference remains available as history, but cannot authorize the new scope while a response is outstanding.

Once the protected permission workflow has been opened, moderation requires a fresh grant through the founder's signed private link for the current request. Reopening a request after a decline or withdrawal cannot restore permission, even if the editor rechecks the confirmation box or changes the reference text. After the founder grants the displayed scope, the editor must separately review the evidence, claims and article before confirming publication. Records that have never entered this workflow (`not_requested`), including nominations with separately obtained founder permission, retain the original documented offline moderation path. There is no offline override for a declined, withdrawn or unanswered protected request. Stale reply and Writer forms are rejected with a conflict rather than overwriting a changed question or scope.

## Runtime integration contracts

Load `reader.php` and `reader_routes.php` after the newsletter/journey foundations. Dispatch `reader_dispatch($path)` before the existing newsletter and journey dispatchers. The module owns `/newsletter`, `/newsletter/preferences...`, `/founders/follow-up...` and `/write/readers...`.

Existing foundations call these narrow hooks when present:

| Hook | Placement and contract |
| --- | --- |
| `reader_contact_submit(array $message, array $input = []): int` | Replaces the contact INSERT/notification block; returns the durable work-item ID. |
| `reader_capture_signup_preferences(int $subscriberId): void` | Inside the original signup transaction, after confirmed addresses have returned unchanged and before a confirmation token is issued. |
| `reader_founder_received(string $reference): void` | After the original founder submission is stored. |
| `reader_founder_reviewed(int $id, int $actor): void` | Inside the moderation transaction after its UPDATE; withdrawal conflicts roll back that transaction. |
| `reader_email_gate(string $key, array $payload, int $outboxId): array` | For `reader-link:*` outbox keys, while the existing recipient lock is held. Returns `action` (`send`, `defer`, `suppress`), `reason` and held `locks`. |
| `reader_email_unlock(array $gate): void` | Called in the worker's `finally`, after transport and acknowledgement. |
| `reader_operations_summary(): array` | Returns only integer `enquiries_open`, `enquiries_overdue`, `founder_waiting`, `founder_replies_pending` counts. |

The bootstrap private-path boundary includes `/newsletter/preferences` and descendants, `/founders/follow-up` and descendants, and all Writer paths. The new stylesheet is `assets/css/reader.css`.

## Migration and verification

`120_reader.sql` only adds reader tables and backfills workflow pointers for existing contacts/founder records. It does not rewrite earlier migration files, subscription status, tokens, source articles, private evidence or public URLs. New workflow timestamps are UTC; original contact/subscriber timestamps retain the application's Nairobi semantics. Apply the migration through the existing checksum-verified runner while workers are paused, then deploy the matching application code before enabling delivery.

The focused tests use the existing isolated local harness, disabled mail and synthetic records:

- `tests/reader_unit.php`: 44 checks of capability signatures, expiry/purpose separation, validation, private URL boundaries, audience matching and preview safety.
- `tests/reader_database_test.php`: 66 checks of double opt-in, address/consent binding, expiry and subscriber locks, suppression/unsubscribe, frozen audience filtering, notification failure durability, enquiry versioning and founder moderation, including withdrawal/re-request consent replay and fresh-grant recovery.
- `tests/reader_http_test.php`: 45 checks of actual forms, shared layouts, authentication, CSRF, method restrictions, private headers, absence of subscriber/evidence leakage, receipt/reply routes and campaign prefills.

These runs establish local application behaviour. They do not establish production domain configuration, provider delivery, mailbox access, sender approval or live deployment. Normal production activation still requires the project's existing deployment and mail readiness evidence.
