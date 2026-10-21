# Release changes — 2026-10-21

## Recovered foundation

Based on the recovered V9 archive dated 2026-08-31. The pristine local git baseline is 5d69ca2. Original site branding, personal photographs, editorial topic structure, article addresses and consent records remain the migration foundation. The recovered archives contain source code; the live database, private configuration/application key and uploaded files were not present.

## Added

- Startup Journey index, journey pages, ordered article chapters, starting baseline, phases, evidence basis, outcomes and a serialized one-public-active-venture rule.
- Founder submission form, private review queue, source/consent checks and controlled publication.
- Double opt-in email subscriptions, token confirmation/expiry/replay controls, recipient-specific unsubscribe and RSS.
- Newsletter edition composer with public-article import, saved email preview, explicit immutable approval, frozen confirmed-only audience at scheduling, current-consent/suppression checks before sending, pause/resume/cancel, draft duplication and recipient reports.
- Mail-client one-click unsubscribe headers and a POST-only token endpoint. Writer subscriber controls support unsubscribe, manual suppression and reviewed removal of manual suppression without bypassing fresh confirmation or provider suppression.
- Signed SES/SNS delivery, bounce and complaint handling, exact-topic validation, deduplicated feedback, explicit signed subscription confirmation in Writer and address suppression for hard bounces/complaints.
- Immutable public/private media library with authenticated private preview/download, bounded image/video types and sizes, file fingerprints, approval snapshots, S3 version references and guarded staging copies. Archiving preserves existing approvals and public URLs.
- Publishing packages, platform variants, immutable approved snapshots, destination binding, scheduling, completion evidence and event history.
- Verified OAuth/resource flows for Google, Meta, LinkedIn and X; verified Buffer channel selection.
- Native YouTube resumable upload using the separate confirmed upload/read grant, owned videos up to 512 MiB, explicit visibility/made-for-kids/altered-content/subscriber-notification choices, encrypted session checkpoints, acknowledged-byte progress and owner processing verification.
- Direct text/link adapters for X, LinkedIn and confirmed Facebook Pages; direct single-JPEG Instagram delivery; supported Buffer delivery and assisted completion for all six channels. TikTok remains assisted or through an eligible separately authorized partner.
- Read-only Search Console, GA4 and YouTube channel reports with separate grants.
- Explicit migrations, encrypted database sessions, encrypted deduplicated email outbox, SES/SMTP delivery, S3 public/private storage and a separately enabled worker covering publishing, payments, newsletters and email.
- Read-only Operations view for local runtime/restoration configuration, migration state/checksums, worker history, overdue/stale publishing and outbox work, connection attention and links to Mail feedback.
- AWS data/edge/mail-feedback templates, Beanstalk hooks/settings, systemd timer, bounded upload configuration, scoped IAM examples, build script and isolated CI/test runner.
- AWS recovery/cutover helper with offline inventory/plans, explicit account checks for live commands, artifact-bound reviewed phases, non-overwriting verified media copy, original-key comparison, retained state and separate readiness/DNS/worker/mail activation gates. Actual SQL restoration and acceptance remain operator steps.

## Corrected

- Live article changes remain private drafts until explicit publication; stale saves fail without overwriting another edit.
- Existing slugs and original publication dates are retained.
- Missing CSRF tokens fail, preview requires authentication, and sanitization also examines lifted child nodes.
- Consent mutation requires an open unexpired request; repeat/racing transitions are rejected.
- Payment state is bound to the stored provider/order/amount/currency/merchant/environment and reconciled without trusting callbacks alone.
- Unknown social/email outcomes stop for review instead of blind retry.
- Interrupted YouTube uploads query the saved session before sending more bytes; a lost final acknowledgement recovers the existing video ID. An expired or unknown session cannot silently start another upload. Missing or mismatched owner settings require review rather than a false completion claim.
- Later draft edits cannot change approved media or publication settings. Private owned media cannot be silently omitted by a route that requires a public URL.
- Newsletter audience snapshots exclude pending, unsubscribed and suppressed addresses. Changed consent or address identity blocks queued delivery, and pause/cancel controls apply to remaining work without replaying accepted or uncertain messages.
- Migration `080_newsletter_confirmation_binding.sql` adds a private issuance-address hash for new confirmation tokens. Confirmation transport and token consumption must match the original mailbox. Legacy tokens remain unchanged and are accepted only when their encrypted immutable confirmation outbox proves the same issued address; missing or unreadable evidence requires fresh opt-in rather than inferring consent from the current address.
- Public and private media have separate storage prefixes. Staging copies enforce ownership, permissions, bounded reads, length/MIME/fingerprint checks and cleanup; supplied deployment settings accommodate the application's 512 MiB video limit.
- A cancelled or obsolete publishing task cannot send a stale reminder. Sent outbox records repair interrupted dependent bookkeeping without another send.
- Both domains have a path-preserving canonical design. Private/token routes suppress analytics, indexing, cache storage and referrers.
- Only validated proxy ranges are trusted. Required application keys fail closed for installed CLI/web runtimes.
- Strict legacy private-proof paths map to the same S3 key when S3 mode is enabled.

## Explicit limits

No AWS account resources were deployed; no live data was imported; no DNS was changed. No real social/Google account was authorized, video/social post published or newsletter campaign sent during implementation. Local synthetic fixtures establish application and protocol behavior; live AWS, MySQL 8.4, OAuth/provider and mail acceptance remain deployment checks documented separately.

Facebook is a professional profile, Instagram and X are professional, LinkedIn is a member profile, YouTube is a channel and TikTok is personal. A declared type does not establish a connection or grant permissions. Facebook Page delivery requires a separately discovered Page. TikTok's current [Direct Post guidelines](https://developers.tiktok.com/doc/content-sharing-guidelines) exclude a private own-account utility; its assisted/partner route retains the platform's eligibility and content rules.

Google sign-in for readers, a universal social inbox, all-platform engagement analytics, video transcoding/carousel production and native TikTok Direct Post for this private utility are not included. YouTube upload scheduling starts transfer at the chosen time and does not promise an exact public release instant. Email acceptance and server delivery are distinct from inbox placement or reading. Operations and the AWS helper's local checks do not certify a live deployment or a successful restore.
