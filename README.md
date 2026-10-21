# Waithaka Ndegwa — startup journeys and publishing

Release: 2026-10-21. Built on the recovered V9 PHP/MySQL website, with the original editorial identity and personal photography preserved.

**This is an implemented, locally tested release package. It has not been deployed to AWS, imported the live database, connected provider accounts or published social posts.**

## Start here

- [Operating guide](docs/OPERATING-GUIDE.md): weekly startup series, founder submissions, Google and social publishing.
- [AWS migration runbook](docs/AWS-MIGRATION.md): restore, stage, validate and cut over both domains.
- [Validation and limits](docs/VALIDATION.md): evidence, actual runtime and remaining external checks.
- [Release changes](docs/CHANGELOG.md).
- [Deployment checklist](DEPLOYMENT-CHECKLIST.md).

The canonical production address is `https://www.waithakandegwa.com`. The AWS edge template redirects both apex and www versions of `waithakandegwa.co.ke`, and the .com apex, to the corresponding canonical path. Existing article addresses are retained.

## Implemented experience

Public navigation includes Home, My Thoughts, Startup Journeys, Founder Stories, About and Contact. Support My Thoughts remains a separate action. The new modules add:

- One publicly active startup journey at a time, ordered chapters linked to existing articles, starting conditions, evidence notes and outcomes.
- Founder submissions with a private review process. Publication requires approval, permission checks, claim review and a linked public article.
- Double opt-in subscriptions, confirmation and unsubscribe flows, plus an XML-safe RSS feed. **A newsletter campaign composer and broadcast sender are not part of this release.**
- A Writer publishing centre with separate drafts for six channels, saved-copy review, explicit approval, immutable revisions, scheduled jobs, assisted completion and delivery records.
- A connections centre with verified account/resource selection, encrypted credentials, separate Google grants and actual read-only reports.
- Corrected draft isolation, CSRF checks, consent transitions, payment verification, database sessions, durable email delivery and S3 storage.

No sample startup, fabricated progress, founder endorsement or fabricated engagement data is published.

## Account configuration

| Channel | Declared account type | Release capability |
| --- | --- | --- |
| Facebook | Professional profile | Assisted publishing; direct text/link posting only after a real Facebook Page is discovered and confirmed |
| Instagram | Professional | Supported Buffer route or direct single JPEG image post after OAuth |
| X | Professional | Supported Buffer route or direct text/link posting after user OAuth and API access |
| LinkedIn | Member profile | Supported Buffer route or direct text/link posting after member authorization |
| YouTube | Channel | Supported Buffer or Studio-assisted delivery; separate read/upload grants, but no native upload delivery adapter |
| TikTok | Personal | Supported Buffer route or native assisted delivery; no custom TikTok Direct Post adapter |

Every route depends on the connected provider returning the intended identity and, where relevant, resource. Account labels alone do not grant API permission.

## Runtime

PHP 8.3 or 8.4, Apache with the supplied .htaccess enabled, MySQL 8.4 planned for AWS, and the extensions declared in composer.json. The local complete regression ran on PHP 8.3 and MariaDB 10.11; the supplied CI matrix targets PHP 8.3/8.4 and MySQL 8.4 and has not been run remotely.

Install exact dependencies with:

```bash
composer install --no-dev --prefer-dist --no-interaction --no-scripts --no-plugins --optimize-autoloader
composer check-platform-reqs --no-dev
```

Environment variables override the legacy private configuration. `.env.example` documents the variables; it is **not** automatically loaded. Preserve the original application encryption key when restoring existing data.

Schema changes run only through `bin/migrate.php`. The public installer is disabled. The runtime database account needs data access, not schema-change privileges. A minute worker runs social jobs, payment reconciliation and the email outbox.

## Verification and packaging

`php tests/run.php --unit` runs isolated pure suites. `php tests/run.php --lint` checks application syntax. For full testing, use a disposable loopback database server and the explicit settings in `php tests/run.php --help`; the runner creates new uniquely named schemas and never drops/reuses a database.

`bin/build-release.py` builds a source archive and an Elastic Beanstalk application bundle from a clean git revision and installed locked dependencies. It excludes live configuration, uploads, logs, test fixtures and local databases. The complete delivery archive also contains the baseline/change git history for recovery.

## Before live launch

Obtain the current database export, original application key/private configuration, public uploads and private proof files from the current host. Restore and test them in a restricted staging environment. Configure the actual AWS account, DNS, certificates, mail identity and provider applications. The complete sequence is in the migration runbook. Never initialize an empty database over the existing site.

This release supersedes the V9 instructions that said no cron was needed or advised opening a browser installer.
