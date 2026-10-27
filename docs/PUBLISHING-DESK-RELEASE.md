# Opportunities, sponsored publishing, approved branding and live footer

## Verified production release

Application commit: `ac76c6675b9d8a25b32d01924da0b6ba105e483b`.
Production workflow: `40515568150`; deployment job: `122229550745`.
Elastic Beanstalk application version: `r17-e1339bf1963d`.
Target: AWS account `466401734428`, region `af-south-1`, environment `waithaka-ndegwa-production`.

Production acceptance completed successfully on 27 October 2026 at 13:58:40 UTC (16:58:40 Africa/Nairobi). It verified the HTTPS homepage, database health, public publishing routes, private intake/client isolation, exact approved horizontal-logo hash, finished icon dimensions, independent current-month/current-year readings and an advancing direct-presence read timestamp. No synthetic visitors were seeded in production. These are deployment/HTTP verification results, not proof of a real customer payment or a delivered client campaign.

## Owner entry points

- `/write/desk`: curated opportunities and sponsored-feature drafts.
- `/write/desk/opportunity/new`: structured funding, fellowship, event and programme form.
- `/write/desk/sponsored/new`: commercial brief and exact-copy approvals.
- `/write/desk/orders`: quotations, named social destinations, prepaid entitlements and publication evidence.
- `/write/desk/settings`: verified commercial supplier, receiving accounts and capacity.
- `/write/visitor-statistics`: direct live presence, source-bound period diagnostics and an explicit period refresh.

## Public entry points

`/opportunities`, `/partner-stories`, `/promote`. Employment submissions retain their existing review routes.

## Commercial safeguards

Prepaid commercial orders are separate from contributions. Checkout remains disabled until the legal supplier, invoice details and intended receiving accounts are confirmed in the protected settings. No live customer charge, gateway transaction acceptance or recurring debit mandate is claimed by this release. Four-week campaigns mean 28 days; renewals require a new accepted quote. An existing article is not recreated once per social post.

Client factual approval, owner approval and verified payment are separate gates. Sponsored templates omit contribution requests and disclose the paying client. Changed facts or creatives invalidate approvals. Replacement schedules require client acceptance and do not replay uncertain provider posts. Preparing a draft or receiving a provider acknowledgement is not proof of a delivered social post. Automatic versus assisted delivery remains dependent on each connected destination and its actual permissions.

## Branding

The approved horizontal lockup is imported losslessly from the user-approved logo kit. Its pinned source SHA-256 is `f674df8d4a9f30df3ce663b7f001af8fd1b18984d251ff5924c9ff01bac77c0b`. The compiler retains its 720 × 160 proportions and generates the PNG fallback. Browser, Apple and PWA icons are rasterized from the approved monogram paths with four-times supersampling; no portrait is regenerated. The immutable application bundle contains all finished icon files with hashes in its runtime manifest. Favicon and app-icon URLs use the actual CDN cache key `v=20261027`; the service worker does not cache private pages or live visitor API responses.

## Visitor counters

Now is measured directly on the website, independently of Google's realtime-report response. Visible public pages send a same-origin, session-bound heartbeat every 20 seconds only after Analytics consent. Multiple tabs count once per browser; hidden/closed tabs send leave messages and disconnected browsers expire after 60 seconds. Sequence numbers prevent delayed heartbeats from reviving closed tabs. No raw session ID, page URL, IP address or user account is stored in the encrypted presence map. See `LIVE-PRESENCE.md` for the full measurement and privacy boundaries.

Current-month and current-year totals are separate GA4 totalUsers reports, scoped to the verified website stream. They refresh every five minutes, subject to Google's processing latency. The year is never the sum of monthly unique counts. Both labels and reporting date ranges roll over automatically in the verified property timezone, currently Africa/Nairobi. The browser refreshes the aggregate display every 20 seconds without a page reload. Polling never increments a count or triggers an upstream Google request. Wrong-period, stale, thresholded or unavailable readings are withheld rather than fabricated. Consent and blockers affect coverage.

## Validation

Existing application suites passed on PHP 8.3 and 8.4 / MySQL 8.4. Added coverage includes commercial contracts, payment replay, client isolation, exact-copy approvals, rescheduling, sponsorship disclosure, calendar rollover, consent, stale-data expiry, public dispatch, approved-brand exports and CDN/offline icon handling. Direct-presence tests include actual isolated HTTP requests with session cookies and CSRF tokens, multiple browsers/tabs, consent refusal, cross-origin rejection and leaving. Production acceptance subsequently passed for the deployed application commit above.
