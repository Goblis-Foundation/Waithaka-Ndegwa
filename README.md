# Waithaka Ndegwa — startup journeys and publishing

Release: 2026-10-21 r3. Built on the recovered V9 PHP/MySQL website, with the original editorial identity and personal photography preserved.

**This is an implemented, locally tested release package. It has not been deployed to AWS, imported the live database, connected provider accounts or published social posts.**

## Start here

- [Operating guide](docs/OPERATING-GUIDE.md): weekly startup series, founder submissions, media, Google/social publishing, newsletters and Operations.
- [AWS migration runbook](docs/AWS-MIGRATION.md): restore, stage, validate and cut over both domains.
- [Reviewed installation and cutover helper](deploy/aws/INSTALL-CUTOVER.md): offline plans, recovery evidence and explicitly applied AWS phases.
- [Validation and limits](docs/VALIDATION.md): evidence, actual runtime and remaining external checks.
- [Release changes](docs/CHANGELOG.md).
- [Deployment checklist](DEPLOYMENT-CHECKLIST.md).

The canonical production address is `https://www.waithakandegwa.com`. The AWS edge template redirects both apex and www versions of `waithakandegwa.co.ke`, and the .com apex, to the corresponding canonical path. Existing article addresses are retained.

## Implemented experience

Public navigation includes Home, My Thoughts, Startup Journeys, Founder Stories, About and Contact. Support My Thoughts remains a separate action. The new modules add:

- One publicly active startup journey at a time, ordered chapters linked to existing articles, starting conditions, evidence notes and outcomes.
- Founder submissions with a private review process. Publication requires approval, permission checks, claim review and a linked public article.
- Newsletter subscriptions and campaigns: double opt-in, private edition drafts, saved email previews, explicit approval, a frozen audience of eligible confirmed subscribers, scheduling, pause/resume/cancel controls and recipient delivery records. Eligibility is checked again before sending.
- Recipient-specific unsubscribe links and mail-client one-click unsubscribe headers. Authenticated SES/SNS delivery, bounce and complaint feedback updates records; hard bounces and complaints suppress future sends to the affected address.
- An immutable media library for public and private images/videos, with authenticated private preview/download, SHA-256 fingerprints and approval-bound file references. Images support JPEG/PNG/WebP up to 25 MiB; video supports MP4/MOV/WebM up to 512 MiB, subject to the deployed upload limits.
- A Writer publishing centre with separate drafts for six channels, saved-copy review, explicit approval, immutable revisions, scheduled jobs, assisted completion and delivery records.
- Native resumable YouTube upload from an owned video, with explicit visibility, audience, altered-content disclosure and subscriber-notification settings. Upload progress, processing, review and reconnection are separate states; interrupted work resumes the saved session rather than creating another video.
- Native TikTok Accounts API publishing of an owned public video, with a separate approved app/grant, explicit audience, interaction and promotional settings, media-domain verification, and accepted-versus-public completion tracking.
- A connections centre with verified account/resource selection, encrypted credentials, separate Google grants and actual read-only reports. TikTok Login Kit and Display API add verified account/profile statistics and up to 20 recent public-video snapshots through a separate read-only grant.
- Read-only Operations diagnostics for local configuration, migration checksums, worker activity, publishing jobs, connections and the email outbox, with a separate Mail feedback view.
- Corrected draft isolation, CSRF checks, consent transitions, payment verification, database sessions, durable email delivery and S3 storage.
- Crawlable article/journey pages, canonical metadata, structured data, a sitemap and an XML-safe RSS feed. My Thoughts, topic archives and Founder Stories have server-rendered pagination, accurate totals, stable ordering and a canonical address for each page. The private founder inbox is also paginated.

No sample startup, fabricated progress, founder endorsement or fabricated engagement data is published.

## Account configuration

| Channel | Declared account type | Release capability |
| --- | --- | --- |
| Facebook | Professional profile | Assisted publishing; direct text/link posting only after a real Facebook Page is discovered and confirmed |
| Instagram | Professional | Supported Buffer route or direct single JPEG image post after OAuth |
| X | Professional | Supported Buffer route or direct text/link posting after user OAuth and API access |
| LinkedIn | Member profile | Supported Buffer route or direct text/link posting after member authorization |
| YouTube | Channel | Native resumable upload of an owned video after authorizing the separate YouTube upload connection and confirming its channel; supported Buffer Shorts or Studio-assisted delivery |
| TikTok | Professional, owner-declared | Native public-video publishing through a separately approved and authorized Accounts API app; supported Buffer or assisted delivery; separate read-only account/video reports |

Every route depends on the connected provider returning the intended identity and, where relevant, resource. Account labels alone do not grant API permission. TikTok is recorded as professional at the owner's direction; this declaration does not establish a verified Business account or authorize a native API route.

Native TikTok publishing uses the separate [API for Business Accounts API](https://business-api.tiktok.com/portal/docs/accounts-api-overview/v1.3), whose own-account application and access rules must be satisfied. It does not use the Content Posting API Direct Post product that excludes a private own-account upload utility. The app ID, returned account, granted scopes and live account settings determine availability. The Login Kit read grant cannot post. Facebook professional mode remains a profile: only a separately discovered and confirmed Page enables the Page route. See the operating guide for exact formats and setup.

## Editorial and delivery controls

Writer navigation is **Library, New thought, Journeys, Founder reviews, Publishing, Newsletter, Media, Connections, Operations, Consent and Settings**. The weekly process is to publish the complete chapter, prepare its channel versions and newsletter edition, review each saved version, then schedule the approved work. Each chapter and edition needs its own content and approval; scheduling does not invent or repeat future weekly content.

Saving or approving an edition/package does not send it. Newsletter confirmation is bound to the original mailbox; restored legacy links require evidence of that address from their encrypted confirmation outbox or a fresh opt-in. A newsletter's recipients are fixed when scheduled: later confirmations do not join that edition, while later unsubscribes, address/consent changes and suppressions can prevent a queued send. A scheduled edition's content and audience stay locked; copying it creates a new unapproved draft without recipients.

Provider acceptance is distinct from publication or delivery. TikTok completion needs a successful task status and returned publicly viewable post IDs; an accepted task is still processing. Automatic YouTube completion requires confirmation of processing and the approved settings, with private/unlisted/public labels preserved; assisted/manual completion records the Writer's verification instead. An SES delivery event records acceptance by the receiving mail server, not proof of inbox placement or reading. Unknown outcomes remain available for investigation instead of automatic resend.

Private media is accessible through authenticated Writer routes and can be transferred by the approved native YouTube job. Other automatic media routes need a public asset URL. Archiving removes an asset from new selections; existing approvals and public URLs remain valid. An older saved selection stays visible even after it falls outside the latest 200 files; unavailable selections require explicit replacement or removal, preventing an ordinary draft save from silently discarding media. Upload a new asset when the file changes.

## Runtime

PHP 8.3 or 8.4, Apache with the supplied .htaccess enabled, MySQL 8.4 planned for AWS, and the extensions declared in composer.json. The local complete regression ran on PHP 8.3 and MariaDB 10.11; the supplied CI matrix targets PHP 8.3/8.4 and MySQL 8.4 and has not been run remotely.

Install exact dependencies with:

```bash
composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins --optimize-autoloader
composer check-platform-reqs --no-dev
```

Environment variables override the legacy private configuration. `.env.example` documents the variables; it is **not** automatically loaded. Preserve the original application encryption key when restoring existing data.

Schema changes run only through `bin/migrate.php`. The public installer is disabled. The runtime database account needs data access, not schema-change privileges. The supplied systemd timer invokes a bounded worker after the prior run finishes; the worker handles social/YouTube jobs, payment reconciliation, newsletter queueing and the email outbox, while recording activity for Operations.

`APP_READY`, `WORKER_ENABLED` and `MAIL_TRANSPORT` are separate controls. A restored staging site can be inspected with delivery work paused. Keep the worker disabled and mail transport disabled until the restored queues, actual destinations, sender identity, feedback connection and release checks have been reviewed. Newsletter approval also needs the configured sender and postal address; changing these after approval requires review again.

SES feedback uses the exact configured SNS topic and verified signatures at `/webhooks/ses`. After a signed subscription request arrives, **Operations → Mail feedback → Confirm configured SNS subscription** completes the operator step. Verify real delivery/bounce/complaint handling before enabling campaigns. SMTP can submit messages, but this release's automated feedback adapter is SES/SNS.

## Verification and packaging

`php tests/run.php --unit` runs isolated pure suites. `php tests/run.php --lint` checks application syntax. For full testing, use a disposable loopback database server and the explicit settings in `php tests/run.php --help`; the runner creates new uniquely named schemas and never drops/reuses a database.

`bin/build-release.py` builds a source archive and an Elastic Beanstalk application bundle from a clean git revision and installed locked dependencies. It excludes live configuration, uploads, logs, test fixtures and local databases. The complete delivery archive also contains the baseline/change git history for recovery.

The AWS helper validates recovery inputs and writes exact plans offline. Explicit live commands check the chosen account, and `apply` requires the reviewed plan's SHA-256. It supports phased data/application/edge setup, verified media copy, the CloudFront media-policy binding, readiness, DNS cutover and separately gated worker/mail activation. SQL restore, original-key verification, migrations and live acceptance remain documented operator steps; the helper does not invent missing recovery data or initialize a replacement site.

## Before live launch

Obtain the current database export, original application key/private configuration, public uploads and private proof files from the current host. Restore and test them in a restricted staging environment. Configure the actual AWS account, DNS, certificates, mail identity and provider applications. The complete sequence is in the migration runbook. Never initialize an empty database over the existing site.

Live AWS deployment, DNS changes, account authorization and real provider delivery have not been performed. Google sign-in for readers, a universal social inbox, all-platform engagement analytics and the Content Posting API Direct Post product are outside this release. TikTok native publishing uses the separate Accounts API integration. Reports cover selected Search Console/GA4 properties, YouTube Data API channel figures and TikTok profile/public-video snapshots; they do not provide every platform's native analytics.

This release supersedes the V9 instructions that said no cron was needed or advised opening a browser installer.
