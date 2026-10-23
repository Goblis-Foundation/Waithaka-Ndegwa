# Waithaka Ndegwa — startup journeys and publishing

Release: 2026-10-22 r17. Built on the recovered V9 PHP/MySQL website, with the original editorial identity and personal photography preserved, and prepared for a clean AWS installation.

**AWS account 466401734428 now has the IAM, Cape Town network and persistent RDS/S3 foundations deployed. This release continues from that state with fresh Route 53, application, CloudFront and Google Workspace automation. It does not import the former host database or uploads.**

## Start here

- [Google tag and growth implementation](docs/GROWTH-IMPLEMENTATION.md): G-RN0YXC2WCH, reading paths, resources, evidence, campaign coordination, reader follow-up and search discovery.
- [Operating guide](docs/OPERATING-GUIDE.md): weekly startup series, founder submissions, media, Google/social publishing, newsletters and Operations.
- [Fresh AWS hosting](deploy/aws/FRESH-AWS-HOSTING.md): the current clean-install route from the deployed AWS foundation through Route 53, application bootstrap, CloudFront and Google Workspace.
- [Branded nameservers](deploy/aws/BRANDED-NAMESERVERS.md): background on `ns1`–`ns4.waithakandegwa.com` and Route 53 white-label operation.
- [Legacy migration runbook](docs/AWS-MIGRATION.md): retained for recovery history only; it is not the selected production route.
- [Validation and limits](docs/VALIDATION.md): evidence, actual runtime and remaining external checks.
- [Release changes](docs/CHANGELOG.md).
- [Deployment checklist](DEPLOYMENT-CHECKLIST.md).

The canonical production address is `https://www.waithakandegwa.com`. The AWS edge template redirects both apex and www versions of `waithakandegwa.co.ke`, and the .com apex, to the corresponding canonical path. Existing article addresses are retained.

The intended authoritative nameservers for both domains are `ns1.waithakandegwa.com`, `ns2.waithakandegwa.com`, `ns3.waithakandegwa.com` and `ns4.waithakandegwa.com`. The fresh DNS helper creates one shared Route 53 delegation set and two public zones, snapshots currently published core `.com` records for continuity, adds ACM validation and the Google Workspace baseline, and generates the exact registrar glue handoff. The actual nameserver addresses are obtained from Route 53 and are never guessed. The user performs only the external registrar glue/delegation update.

## Implemented experience

Public navigation includes Home, My Thoughts, Start Here, About and Contact. Startup Journeys, Founder Stories, Resources, Watch, weekly updates and RSS are linked through the home page, Start Here and footer. Support My Thoughts remains a separate action. The new modules add:

- One shared Google tag with the supplied G-RN0YXC2WCH destination, explicit enable/off settings, consent-controlled collection, once-per-page application page views, restricted campaign identifiers and private-form exclusions.
- Start Here reading paths, original downloadable blank startup resources, author-managed resource/experiment/video pages, public evidence cards, meaningful correction history, long-article contents links and a newsletter invitation.
- A private campaign workspace joining each source article, objective, channel-specific links, social packages and newsletter editions without bypassing their approval processes.
- Reader topic/journey preferences, expiring mailbox-bound preference/founder links, a durable enquiry queue and protected founder clarification/permission follow-up.
- Complete paginated public search, Google query/page/country/device period comparisons, IndexNow inventory/receipts/worker gates and consolidated operations attention items.
- Visible-by-default content, actual responsive derivatives of existing public photographs, intrinsic image dimensions and meaningful rather than request-time modification dates.
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

Writer navigation includes **Library, New thought, Journeys, Founder reviews, Campaigns, Resources & evidence, Reader desk, Discovery, Publishing, Newsletter, Media, Connections, Operations, Consent and Settings**. The weekly process is to publish the complete chapter, prepare its channel versions and newsletter edition, review each saved version, then schedule the approved work. Each chapter and edition needs its own content and approval; scheduling does not invent or repeat future weekly content. Campaigns connects these records without automatically publishing or sending them.

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

Environment variables override the legacy private configuration. `.env.example` documents the variables; it is **not** automatically loaded. The selected clean install generates a new application key in Secrets Manager; no legacy application key is required.

Schema changes run only through `bin/migrate.php`. The public installer is disabled. The runtime database account needs data access, not schema-change privileges. The supplied systemd timer invokes a bounded worker after the prior run finishes; the worker handles social/YouTube jobs, payment reconciliation, newsletter queueing and the email outbox, while recording activity for Operations.

`APP_READY`, `WORKER_ENABLED` and `MAIL_TRANSPORT` are separate controls. A restored staging site can be inspected with delivery work paused. Keep the worker disabled until the fresh site, actual destinations and release checks have been reviewed. Mail remains disabled until the Google Workspace SMTP relay has been configured for the fixed AWS NAT egress IP and accepted in production. Newsletter approval also needs the configured sender and postal address; changing these after approval requires review again.

Google Workspace SMTP relay is the selected primary application mail path. The application uses `smtp-relay.gmail.com:587` with STARTTLS and IP authentication from the fixed NAT egress address; no SMTP password is stored. Workspace Email Log Search is used for provider-side delivery diagnostics. The older SES/SNS feedback adapter remains available in source but is not the selected primary transport.

## Verification and packaging

`php tests/run.php --unit` runs isolated pure suites. `php tests/run.php --lint` checks application syntax. For full testing, use a disposable loopback database server and the explicit settings in `php tests/run.php --help`; the runner creates new uniquely named schemas and never drops/reuses a database.

`bin/build-release.py` builds a source archive and an Elastic Beanstalk application bundle from a clean git revision and installed locked dependencies. It excludes live configuration, uploads, logs, test fixtures and local databases. The complete delivery archive also contains the baseline/change git history for recovery.

The selected AWS path is `deploy/aws/fresh_hosting.sh`: prepare Route 53 and the registrar handoff, then continue automatically after delegation through the clean database/Writer bootstrap, Elastic Beanstalk, CloudFront and Workspace-mail handoff. The older migration helper remains only for recovery history.

## Before live launch

This deployment intentionally starts with a fresh AWS database and storage layer. No current-host database, uploads or legacy application key are required. Run `deploy/aws/fresh_hosting.sh --prepare-dns`, perform the generated registrar glue/delegation handoff, then run `deploy/aws/fresh_hosting.sh --after-registrar` to bootstrap the clean Writer/database, deploy Elastic Beanstalk and CloudFront, and switch Route 53 website aliases. Google Workspace SMTP relay/DKIM and provider OAuth authorizations remain explicit account-owner handoffs.

Google sign-in for readers and a universal social inbox remain outside this release. TikTok native publishing uses the separate Accounts API integration. Reports cover selected Search Console/GA4 properties, YouTube Data API channel figures and TikTok profile/public-video snapshots; they do not provide every platform's native analytics.

This release supersedes the V9 instructions that said no cron was needed or advised opening a browser installer.

## r6 dedicated AWS workload account

The deployment target is now explicitly **AWS account 466401734428 (Waithaka Ndegwa)** under **AWS Organizations management account 891923193638 (AfriTrace Link)**. The management account is an organization/governance boundary only and is not a website hosting target.

The primary workload region is **Africa (Cape Town), `af-south-1`**. CloudFront remains global and its viewer ACM certificate is created in **`us-east-1`**. Route 53 hosted zones and the reusable delegation set behind `ns1`–`ns4.waithakandegwa.com` are owned by **466401734428**.

Before any AWS mutation read `deploy/aws/ACCOUNT-466401734428-HOSTING-PLAN.md` and run the read-only `deploy/aws/account_readiness.py`. The release includes target-pinned production and DNS example configurations, a two-AZ network baseline, and Elastic Beanstalk IAM baseline. Every existing guarded live phase still checks STS against the explicitly configured account before mutation.
