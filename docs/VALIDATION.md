# Validation record

Release date: 2026-10-21. Scope: the recovered V9 website plus this implementation.

## Final local result

**All nine application suites passed: 439 assertions.** The runtime was PHP 8.3.6 with MariaDB 10.11.14 on an isolated local server.

| Suite | Passing assertions |
| --- | ---: |
| Security unit checks | 72 |
| Publishing policies and provider-response classification | 52 |
| Runtime configuration, proxies, keys, media paths and worker activation | 78 |
| Security database workflows | 35 |
| Journeys, founder review, subscriptions and RSS | 75 |
| Publishing database workflows | 16 |
| Sessions, migrations, email outbox and reminder operations | 32 |
| Security and shared-layout HTTP workflows | 38 |
| Publishing approval/scheduling/completion HTTP workflows | 41 |
| **Total** | **439** |

All **73 PHP files and two JavaScript files** passed the final syntax check. The full subprocess output, initial tooling issues and successful reruns are in [local-regression-2026-10-21.txt](../tests/evidence/local-regression-2026-10-21.txt).

The local test server and data were synthetic. HTTP tests exercised actual login sessions, rendered forms, CSRF, article publication boundaries, saved publishing review, stale approvals, scheduling, deduplication and assisted completion. Synthetic completion URLs were recorded but never fetched.

## What the evidence establishes

- Autosaving or submitting a draft cannot silently overwrite the published article.
- Stale Writer edits and stale publication approval fail without overwriting the reviewed state.
- Private preview and Writer routes require authentication; absent CSRF tokens fail.
- Sanitization covers lifted child nodes; consent transitions require a valid open request.
- Payment decisions check their stored provider/order/amount/currency/merchant/environment context; an unverified callback alone is not treated as settled payment.
- One publicly active startup journey is enforced through serialized mutation.
- Founder publication requires the review conditions and a public associated article.
- Subscription confirmation/unsubscribe requires the intended transition; expired or replayed confirmations fail.
- Approved publishing revisions and destinations stay fixed while new drafts change.
- Duplicate scheduling does not create duplicate jobs, and abandoned/uncertain external operations are not blindly replayed.
- Cancelled tasks and obsolete subscription confirmations do not send stale queued emails.
- Provider-acknowledged outbox records repair interrupted contact/reminder bookkeeping without another send.
- Proxy syntax, required application keys and private proof-path mapping fail closed.
- The worker defaults to disabled independently of web readiness, preventing a restored staging queue from draining automatically.

These tests demonstrate behavior under the tested fixtures. They do not establish perfect security, legal compliance or compatibility with every future provider response.

## Other completed checks

- Composer manifest/lock validation and actual installed platform requirements passed.
- The locked production dependency audit returned **no advisories and no abandoned packages** on 2026-10-21.
- CloudFormation static lint passed for data-stack.json and edge-stack.json using cfn-lint 1.58.0.
- Deployment JSON parsing and shell/PHP syntax checks passed.
- The final deployment review checked OAC policy ownership, storage prefixes, HTTPS/Host forwarding, secret-reference syntax and native timer enablement.
- A standalone CLI smoke confirmed the disabled worker exits without database/provider work.
- A targeted source scan found no private-key, AWS access-key, GitHub-token or OpenAI-key patterns in the application/docs/configuration scope. Runtime secrets/configuration and live uploads are excluded from the archive by construction; this scan is not a claim that pattern matching detects all secrets.
- The release builder requires a clean git revision, includes locked production dependencies, checks ZIP integrity and records source/runtime SHA-256 hashes.

## Test isolation

The runner requires explicit numeric loopback database settings and an opt-in write flag. Every full run creates five fresh uniquely named test schemas and refuses existing names. It never drops databases. Individual suites may initialize their own tables only inside the newly created disposable schema.

Processes use an allowlisted environment and an empty private configuration, so inherited production credentials and provider configuration do not reach them. The runner owns and stops its own HTTP server. The email and provider transport tests use synthetic responses; no social post, OAuth grant or actual email was sent.

Two preliminary local runner issues were fixed and retained in the evidence: Composer root-mode invocation needed --no-plugins, and PHP proc_open omitted empty environment values. Neither failed preflight created a database. The runner now preserves explicit empty fixture values without placing nonempty credentials in command-line arguments.

## CI provided, but not executed

The supplied GitHub workflow uses PHP 8.3 and 8.4 with an isolated MySQL 8.4 service. It validates dependencies, installs the committed lock, audits packages, lints and runs all suites.

The official action tags were verified against their upstream repositories and exact refs are recorded in the evidence. No GitHub push or workflow run was performed. Local MariaDB results do not prove MySQL 8.4 or PHP 8.4 compatibility.

## Live acceptance still required

The AWS console was unavailable in this working session. AWS templates have been statically checked, not applied or validated against a live account.

Before cutover, the migration runbook requires the actual database/key/media restore, MySQL 8.4 compatibility check, selected Beanstalk platform, RDS TLS, IAM, certificates, DNS, .htaccess enforcement under Apache/PHP-FPM, CloudFront redirects/cache rules, S3 public/private boundaries, timer execution, mail delivery and backup recovery.

Provider routes still require registered applications, owner authorization, available product permissions and confirmation of returned destinations. Real Buffer, Instagram, Facebook Page, X, LinkedIn, Google and email operations have not been validated end to end. Unsupported formats stay on supported provider/native assisted routes.

Subscription management is implemented; newsletter campaigns/broadcasts, a universal social inbox, reader Google sign-in, native YouTube uploads and a custom TikTok Direct Post adapter are outside this release.
