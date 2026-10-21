# Validation record

Release: 2026-10-21 r3. Scope: the recovered V9 website and the full implementation release, including TikTok reporting/native publishing and archive/media corrections.

## Final combined result

**All 27 suites passed: 1,401 PHP assertions across 26 suites, plus 43 Python deployment regression cases.** The application runtime was PHP 8.3.6 with MariaDB 10.11.14 on an isolated local server. The Python cases mock every AWS operation.

| Suite | Passing assertions or cases |
| --- | ---: |
| Security unit | 72 |
| Publishing policy and provider classification | 61 |
| Runtime configuration, proxy, key, storage and worker policy | 78 |
| Newsletter validation and rendering | 67 |
| YouTube upload policy | 60 |
| TikTok reporting permissions and policy | 45 |
| TikTok Accounts API authorization and publishing policy | 109 |
| Operations diagnostics policy | 38 |
| Signed SNS and email contracts | 31 |
| YouTube resumable HTTP protocol fixture | 46 |
| Security database workflows | 35 |
| Journeys, founder review, subscriptions and RSS | 75 |
| Publishing database workflows | 34 |
| Sessions, migrations, outbox and reminders | 32 |
| Newsletter database, campaigns and consent | 92 |
| Operations and heartbeat database workflows | 18 |
| Signed mail feedback database workflows | 23 |
| Owned media database workflows | 64 |
| YouTube approval, media and worker database integration | 22 |
| TikTok reporting OAuth and database workflows | 34 |
| TikTok Accounts API OAuth, media and worker workflows | 77 |
| Security and shared-layout HTTP workflows | 38 |
| Publishing approval/scheduling HTTP workflows | 50 |
| Newsletter composer/approval/consent HTTP workflows | 52 |
| Media, Operations and feedback HTTP workflows | 24 |
| Archive discovery, canonical URLs and editorial privacy HTTP workflows | 124 |
| **PHP assertion total** | **1,401** |
| Offline AWS installation/cutover regression cases | **43** |

All **115 PHP files and two JavaScript files** passed syntax checks. Four shell files, four Python files and seven JSON files also passed their syntax/parsing checks. CloudFormation lint passed for **data-stack.json, edge-stack.json and mail-feedback-stack.json**, using cfn-lint 1.58.0.

Complete current evidence:

- [Combined regression output](../tests/evidence/full-regression-2026-10-21-r3.txt)
- [Dependency, syntax and deployment checks](../tests/evidence/release-checks-2026-10-21-r3.txt)

The earlier 439-assertion foundation and r2 combined records remain in tests/evidence for provenance; they are superseded by the combined results above. The final command used `composer --no-plugins test` with explicit disposable loopback database settings. `php tests/run.php --help` documents the fixture contract.

### TikTok professional declaration

The owner's 2026-10-21 correction records TikTok as professional, explicitly distinguished from a provider-verified Business subtype or API authorization. Migration `090_tiktok_professional.sql` updates only the legacy current-channel declaration; the historical seed migration and evidence logs remain unchanged. Passing policy and database regressions cover identity/credential/approval/job preservation, stale-review rejection, idempotence, preservation of more specific declarations and unchanged authorization requirements.

## What was exercised

The HTTP suites use actual local sessions, rendered forms, multipart uploads and the application's router. The database suites execute real migrations, constraints, advisory locks and transactions. Provider behavior is synthetic; local test URLs are numeric loopback and synthetic completion links are never fetched.

### Editorial and publishing boundaries

- Autosaved changes stay private until explicit publication; stale article edits and stale approvals fail.
- Writer, private previews, media downloads and reports require authentication. Mutations require the intended method and CSRF checks. Operations rejects mutations.
- One publicly active startup journey is enforced under serialized mutation. Founder publication requires permission/claim review and a public associated article.
- Public archive fixtures include 70 public articles and hidden/draft/future records; navigation, exact counts, stable ordering, canonical URLs, malformed/out-of-range requests and a single-article final page are exercised. Founder review fixtures include 250 submissions and verify authenticated filtered access beyond the former 200-record limit.
- Approved social revisions and destinations stay fixed while later drafts change. Duplicate scheduling creates no duplicate job.
- Direct and partner routes reject unsupported private-media combinations instead of silently dropping the asset.
- Provider acceptance, verified publication, assisted completion evidence and uncertain results remain distinct.

### Newsletter and mail

- Only currently confirmed and eligible subscribers enter a scheduled edition's fixed audience. Content, sender, source, consent revision and address identity are frozen.
- Pause/resume/cancel, stale approval, duplicate scheduling, changed source URL, unsubscribe after queueing and suppression are checked before transport.
- Confirmation is bound to the original mailbox both when sent and consumed. Old tokens cannot activate a replacement address. Legacy tokens need their original encrypted outbox evidence; missing or unreadable evidence requires a new opt-in request without rewriting historical consent.
- One-click unsubscribe uses an inert GET/HEAD and an exact POST without authentication, cookies or redirects. Ordinary human unsubscribe remains CSRF-protected.
- Signed SNS tests cover certificate/topic/signature validation, recipient/provider/tag binding, event deduplication and complaint/bounce/delivery ordering.
- Hard bounces and complaints suppress future email to the address. A manual preference action cannot remove provider suppression.
- Known provider acknowledgements survive dependent bookkeeping failure; later reconciliation does not resend. Uncertain sends stop for review.

### Owned media and YouTube

- Private uploads remain private; spoofed filenames do not determine MIME type. Files have immutable keys, lengths, types and fingerprints.
- Actual multipart upload, authenticated byte-range preview, private-path denial, archive behavior, changed bytes and out-of-bound requests are exercised.
- Staging-directory ownership/permissions, bounded reads, approved snapshot matching and hash verification are enforced in code. Live S3 transport remains a deployment check.
- Older saved media remains selected beyond the latest 200 assets. Archived, missing or incompatible selections remain explicit rather than being silently discarded. New selections and approvals still require an available eligible asset.
- Public-media binding checks reject a changed URL, configured driver/bucket/prefix mismatch, changed file bytes and unpinned S3 versions. Offline AWS SDK fixtures verify the current version before/after reading the approved version and the version returned by the download. Live DNS/CDN routing and out-of-band changes after preflight remain external checks.
- YouTube fixtures cover partial ranges, dropped worker results, lost initialization/final responses, expired sessions, wrong channels and missing/mismatched publication settings.
- Real database integration freezes the exact media/settings, survives later draft edits and asset archiving, and recovers the original private video ID without another upload.

### TikTok reporting and native publishing

- The owner-declared professional label is independent of provider-returned account flags and grants. The additive migration preserves existing identities, encrypted credentials, routes, immutable approvals and jobs; the historical seed remains unchanged.
- Reporting uses the separate Login Kit/Display API grant and actual returned open ID, username and profile/video data. Pending identity confirmation, absent counters, malformed responses, scope changes and serialized rotating-token renewal are covered by pure and real loopback/database fixtures.
- Native publishing uses the separate API for Business Accounts API grant. The copied official portal URL requires one safe opaque client identifier, the exact callback and the necessary scopes; outgoing consent is limited to the four displayed permissions. Token inspection independently verifies the configured app ID and authorized creator, without assuming the portal client key equals the app ID.
- Approval freezes the public owned video, caption, audience, interaction settings, promotional declarations, AI setting and destination. Fresh account restrictions, verified media-property records, actual owned bytes and storage binding are rechecked before publishing. Provider media processing enforces video duration, dimensions, frame rate and encoding; these are not analyzed locally.
- Actual local HTTP/database fixtures exercise accepted tasks, delayed public IDs, codec rejection, missing or uncertain acknowledgements, HTTP failures and HTTP-200 error envelopes. The durable attempt checkpoint prevents automatic reposting; a known share ID is saved before returning and can only resume status reads.
- Exact documented credential errors require reconnection, while entitlement, rate-limit and unknown codes are not misclassified as expired credentials. A reconnectable error during status recovery preserves the accepted task. Token rotation is serialized, uses exact encrypted-credential comparison and cannot replay an uncertain renewal request.

### AWS recovery helper

- Offline plans require the actual account/region and bind the release, SQL backup, original-key fingerprint, media inventory, templates, state and reviewed receipts.
- Fixtures exercise placeholder/cross-account rejection, stale receipts, incomplete restoration, conflicting media, original-key matching, scoped IAM, DNS drift and separate activation phases.
- Beanstalk tests reject paused-state drift, pending settings and rolled-back updates before recording success.
- CloudFormation completion requires the exact change set's completed execution, intended stack status, applied parameters and ownership; an old successful stack response cannot masquerade as completion.
- No test calls AWS. Operator receipts are attestations, not proof that live checks have run.

## Dependency and packaging checks

Composer manifest/lock validation and installed platform requirements passed. The locked dependency audit reported **no security vulnerability advisories** on 2026-10-21. The release adds the official AWS SNS message validator to the existing locked AWS SDK and mail dependencies.

The release builder requires a clean committed source revision, includes locked production dependencies, checks both ZIPs, and records exact source/runtime SHA-256 values. It excludes local credentials/configuration, live uploads, private proof/library files, logs and test databases. Source tests and runbooks are included in the outer release; they are not part of the deployed runtime ZIP. Source-denial marker files remain in the runtime bundle.

The dependency command output includes the local sandbox's root/temp-directory warnings; those commands exited successfully. They are not live server validation. These tests establish the observed fixture behavior, not perfect security or compatibility with every future provider response.

## Isolation and runtime limits

Full tests require the explicit write flag and numeric loopback database target. The runner creates **twelve fresh uniquely named schemas**, never reuses an existing schema and never drops a database. It passes an allowlisted environment and an empty private configuration to child processes. Tests use synthetic accounts, media and recipients, with production transport disabled or replaced by guarded local fixtures. The runner starts/stops only its own HTTP processes.

No real social post, YouTube upload, OAuth grant, email delivery, SQL restoration, AWS provisioning or DNS change occurred during implementation.

## CI is supplied, not executed remotely

The GitHub workflow is configured for PHP 8.3 and 8.4 with MySQL 8.4. It validates/install-checks dependencies, audits packages, lints and invokes the same aggregate runner, including the offline Python suite. No repository push or GitHub workflow execution was performed. Local PHP 8.3/MariaDB results do not certify PHP 8.4 or RDS MySQL 8.4 compatibility.

## Live acceptance still required

The AWS Console remained unavailable in this session. The personal website's actual AWS account/region, live database export, original application key and full uploaded/private media have not been obtained. No other project's account has been assumed.

The runbook still requires a faithful staging restore; selected supported Beanstalk/PHP and MySQL versions; IAM/RDS TLS/network checks; certificates and DNS; Apache/PHP-FPM source protection; CloudFront redirects/cache rules; S3 object/version/private-access checks; an actual large upload; timer heartbeat and delivery controls; backup restoration; and monitoring ownership.

Google and social integrations require real app registration, owner authorization, returned resource confirmation and controlled account-specific tests. Native YouTube requires an owned video and explicit approved settings. Facebook professional-profile posting remains assisted unless a real Page is confirmed. TikTok is recorded as professional by owner declaration; this is not a verified Business subtype or an API grant. Native TikTok requires the separate Accounts API app review, actual account-holder authorization, inspected app/creator/scopes, current settings and verified media domain or URL prefix. Login Kit reporting cannot authorize publishing. No real app review, OAuth callback, TikTok domain verification or public-video delivery has been performed. Assisted and eligible separately authorized partner routes remain available.

SES needs actual sender verification, DKIM and sending access, an exact configuration set/topic, signed subscription confirmation and tested bounce/complaint suppression. Inspect the received message's DKIM coverage of one-click headers. Accepted mail, recipient-server delivery, inbox placement and reading are different outcomes. The SMTP option does not inherit SES feedback; another provider needs equivalent tested feedback integration before campaigns.

Reader Google sign-in, a universal social inbox, all-platform engagement analytics and video transcoding/carousel production are outside this implementation. Operations is local diagnostic information; it does not independently certify any live infrastructure or provider permission.
