
## 2026-10-22 — r17 clean AWS hosting automation
- Clean AWS install selected; legacy-host recovery is no longer a production prerequisite.
- Route 53 white-label DNS, registrar handoff, fresh Writer/database bootstrap, Elastic Beanstalk and CloudFront are now orchestrated by `deploy/aws/fresh_hosting.sh`.
- Google Workspace SMTP relay replaces SES as the selected application-mail path.
# Release changes — 2026-10-21 r5

## 2026-10-21 r5 — Branded authoritative DNS

- Added a separate reviewed Route 53 workflow for the exact four `ns1`–`ns4.waithakandegwa.com` names serving both the `.com` and `.co.ke` zones. Create, reuse and existing-zone adoption are explicit; a physical set mismatch requires a replacement-zone migration.
- Added complete paginated exports, real authoritative A/AAAA capture, stable one-to-one server mapping, non-destructive source import, narrow NS/SOA/address changes and registrar glue/delegation instructions. Existing mail, verification, child delegations, routing metadata and resource aliases are retained; conflicting records stop preparation.
- Added per-batch intent/change-ID journals, whole-zone drift comparisons, Route 53 quota-aware batching, recorded `INSYNC` waits and safe response-loss recovery. New plans and readiness reports cannot bypass an unfinished submitted plan. An exact interrupted plan can resume beyond its initial evidence-age limit while still checking hashes and actual identities/state.
- Added direct authoritative, parent referral/glue/DS and two-resolver observations, including sequential domain cutover. Failed/incomplete verification revokes prior stabilization readiness; a successful full report is bound to the observed mapping and DNSSEC state.
- Added an optional production website-cutover gate bound to a recent exact account/two-zone verification report, plus reviewed TTL stabilization and retained rollback instructions. No registrar or DNSSEC write is automatic.
- Included dated public observations and focused offline tests. This release does not change the live DNS or deploy the website; AWS/registrar access and current private recovery/export inputs remain external prerequisites.

## 2026-10-21 r4 — Google tag and global discovery

- Installed the owner-supplied G-RN0YXC2WCH destination through additive migration 100 and one shared immediate-head tag, with consent defaults, explicit off/blank behaviour, no duplicate application page views, constrained UTM values and private-form exclusions.
- Added Start Here, original downloadable startup worksheets, resource/watch publishing, journey experiment records, article contents links, public evidence and immutable meaningful update/correction records. Private working copies remain separate.
- Added topic/journey newsletter preferences, mailbox-bound expiring links, pre-send preference checks, durable enquiry tracking and protected founder clarification/permission follow-up.
- Added real paginated multi-content search, confirmed-property Search Console comparisons, public-only IndexNow inventory and durable attempts, and operations attention summaries.
- Added private editorial campaign plans joining article purpose, per-channel links, social delivery records and newsletter editions. Draft creation still requires saved review and approval before delivery.
- Preserved the personal photography and created 28 actual responsive WebP derivatives. Public content is visible without JavaScript. About no longer reports a fabricated fresh modification time.
- Added migrations 100, 110, 120, 130 and 140 without modifying the historical migration ledger. See GROWTH-IMPLEMENTATION.md and VALIDATION.md for use, evidence and external deployment limits.


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
- Direct text/link adapters for X, LinkedIn and confirmed Facebook Pages; direct single-JPEG Instagram delivery; supported Buffer delivery and assisted completion for all six channels.
- Native TikTok Accounts API video publishing with its own approved API for Business application and owner grant, inspected app/account/permission binding, current account settings, verified URL-property checks, exact approved interaction/promotional/AI settings and durable publish-task checkpoints. Known task IDs resume status reads; uncertain mutation outcomes cannot automatically repost. This is separate from the developer Content Posting API Direct Post product.
- Read-only Search Console, GA4 and YouTube channel reports with separate grants. A separate TikTok Login Kit/Display API connection provides current profile totals and up to 20 recent public videos, with serialized rotating-token renewal and reconnection after an uncertain refresh result.
- Crawlable pagination for My Thoughts, topic archives and Founder Stories, with complete eligible counts, stable ordering, normal navigation links and a canonical URL for each page. The authenticated founder review inbox also supports filtered pagination beyond its previous fixed limit.
- Explicit migrations, encrypted database sessions, encrypted deduplicated email outbox, SES/SMTP delivery, S3 public/private storage and a separately enabled worker covering publishing, payments, newsletters and email.
- Read-only Operations view for local runtime/restoration configuration, migration state/checksums, worker history, overdue/stale publishing and outbox work, connection attention and links to Mail feedback.
- AWS data/edge/mail-feedback templates, Beanstalk hooks/settings, systemd timer, bounded upload configuration, scoped IAM examples, build script and isolated CI/test runner.
- AWS recovery/cutover helper with offline inventory/plans, explicit account checks for live commands, artifact-bound reviewed phases, non-overwriting verified media copy, original-key comparison, retained state and separate readiness/DNS/worker/mail activation gates. Actual SQL restoration and acceptance remain operator steps.

## Corrected

- TikTok is recorded as professional at the owner's direction. Additive migration `090_tiktok_professional.sql` corrects only the legacy `personal` default without changing account identities, encrypted credentials, selected routes, immutable approvals or queued jobs. Writer labels this as an owner declaration; it does not imply a verified Business subtype or API permission. The committed historical seed migration is unchanged.
- Live article changes remain private drafts until explicit publication; stale saves fail without overwriting another edit.
- Existing slugs and original publication dates are retained.
- Missing CSRF tokens fail, preview requires authentication, and sanitization also examines lifted child nodes.
- Consent mutation requires an open unexpired request; repeat/racing transitions are rejected.
- Payment state is bound to the stored provider/order/amount/currency/merchant/environment and reconciled without trusting callbacks alone.
- Unknown social/email outcomes stop for review instead of blind retry.
- Interrupted YouTube uploads query the saved session before sending more bytes; a lost final acknowledgement recovers the existing video ID. An expired or unknown session cannot silently start another upload. Missing or mismatched owner settings require review rather than a false completion claim.
- Later draft edits cannot change approved media or publication settings. Private owned media cannot be silently omitted by a route that requires a public URL.
- Saved media selections remain visible beyond the latest 200 assets. Archived, missing or incompatible selections stay explicit until replaced or removed; ordinary editing cannot silently discard them.
- Native TikTok public-media preflight binds the approved URL to the configured storage object and exact bytes. S3 checks compare the current version before and after reading the approved version; provider-property checks cannot substitute for owned-file verification.
- Newsletter audience snapshots exclude pending, unsubscribed and suppressed addresses. Changed consent or address identity blocks queued delivery, and pause/cancel controls apply to remaining work without replaying accepted or uncertain messages.
- Migration `080_newsletter_confirmation_binding.sql` adds a private issuance-address hash for new confirmation tokens. Confirmation transport and token consumption must match the original mailbox. Legacy tokens remain unchanged and are accepted only when their encrypted immutable confirmation outbox proves the same issued address; missing or unreadable evidence requires fresh opt-in rather than inferring consent from the current address.
- Public and private media have separate storage prefixes. Staging copies enforce ownership, permissions, bounded reads, length/MIME/fingerprint checks and cleanup; supplied deployment settings accommodate the application's 512 MiB video limit.
- A cancelled or obsolete publishing task cannot send a stale reminder. Sent outbox records repair interrupted dependent bookkeeping without another send.
- Both domains have a path-preserving canonical design. Private/token routes suppress analytics, indexing, cache storage and referrers.
- Only validated proxy ranges are trusted. Required application keys fail closed for installed CLI/web runtimes.
- Strict legacy private-proof paths map to the same S3 key when S3 mode is enabled.

## Explicit limits

No AWS account resources were deployed; no live data was imported; no DNS was changed. No real social/Google account was authorized, video/social post published or newsletter campaign sent during implementation. Local synthetic fixtures establish application and protocol behavior; live AWS, MySQL 8.4, OAuth/provider and mail acceptance remain deployment checks documented separately.

Facebook is a professional profile, Instagram and X are professional, LinkedIn is a member profile, YouTube is a channel and TikTok is professional by owner declaration. A declared type does not establish a connection, verify a Business subtype or grant permissions. Facebook Page delivery requires a separately discovered Page. Native TikTok delivery uses the separately reviewed [API for Business Accounts API](https://business-api.tiktok.com/portal/docs/accounts-api-overview/v1.3), not the Content Posting API product whose [Direct Post guidelines](https://developers.tiktok.com/doc/content-sharing-guidelines) exclude a private own-account utility. Actual app approval, scopes, account authorization, media-domain verification and controlled live acceptance remain required. Assisted and eligible authorized partner routes remain available.

Google sign-in for readers, a universal social inbox, all-platform engagement analytics, video transcoding/carousel production and the TikTok Content Posting API Direct Post product are not included. Native TikTok accepts owned public videos up to the site's 512 MiB limit; actual duration, dimensions, frame rate and encoding are checked by TikTok during processing, not by a local video analyzer. Native video scheduling starts delivery at the chosen time and does not promise an exact public release instant. Email acceptance and server delivery are distinct from inbox placement or reading. Operations and the AWS helper's local checks do not certify a live deployment or a successful restore.
