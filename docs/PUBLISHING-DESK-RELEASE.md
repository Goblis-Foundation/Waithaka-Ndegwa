# Opportunities, sponsored publishing, approved branding and live footer

## Owner entry points

- `/write/desk`: curated opportunities and sponsored-feature drafts.
- `/write/desk/opportunity/new`: structured funding, fellowship, event and programme form.
- `/write/desk/sponsored/new`: commercial brief and exact-copy approvals.
- `/write/desk/orders`: quotations, named social destinations, prepaid entitlements and publication evidence.
- `/write/desk/settings`: verified commercial supplier, receiving accounts and capacity.
- `/write/visitor-statistics`: source-bound visitor diagnostics and an explicit refresh.

## Public entry points

`/opportunities`, `/partner-stories`, `/promote`. Employment submissions retain their existing review routes.

## Commercial safeguards

The release installs prepaid commercial order handling separately from contributions. Checkout stays disabled until the legal supplier, invoice details and intended receiving accounts are confirmed in the protected settings. This is not a claim that gateway live credentials or any subscription mandate have been activated. No customer is automatically enrolled in a recurring debit. Four-week campaigns mean 28 days; renewals require a new accepted quote. An existing article is not recreated once per social post.

Client factual approval, owner approval and verified payment are separate gates. Sponsored templates omit contribution requests and disclose the paying client. Changed facts or creatives invalidate approvals. Replacement schedules require client acceptance and do not replay uncertain provider posts. A provider receipt or prepared draft is not proof of a delivered social post.

## Branding

The approved horizontal lockup is imported losslessly from the existing user-approved logo kit. The compiler pins its source hash, keeps its 720 × 160 proportions and generates PNG fallback. Browser, Apple and PWA icons are rasterized from the approved monogram paths with four-times supersampling; no portrait is regenerated. The immutable application bundle contains all finished icon files, with hashes recorded in its runtime manifest. Modify the approved source assets deliberately, not the derived files.

## Visitor counters

The footer is an aggregate display, not a browser-side incrementing counter. Public visible pages with Analytics consent emit `wn_live_presence` at most once per 25 seconds; the normal cadence is 30 seconds. Hidden pages stop presence events. GA4 distinct active users for that event in the current and previous minute are refreshed server-side every minute. This measures recent activity, not instantaneous physical occupancy.

Month-to-date and year-to-date `totalUsers` reports are fetched separately every five minutes, scoped to the verified website stream. GA4 processing latency still applies. The year is never the sum of monthly unique counts. The browser polls the read-only local snapshot every 30 seconds; no public request triggers an upstream API request. The property time zone determines labels and date boundaries. Wrong-period and expired readings are withheld, never copied forward or replaced by fabricated zeroes. Consent and blockers affect coverage. No Analytics explanatory text is added to the public footer.

## Validation

Existing application suites remain mandatory on PHP 8.3 and 8.4 / MySQL 8.4. Added tests cover commercial contracts, payment replay, client isolation, approvals, rescheduling, disclosure, visitor calendar rollover, consent, live-data expiry, public dispatch and compiled brand assets. Production acceptance must verify the actual deployed commit, public routes, protected client workspace, aggregate endpoint, logo hash and icon dimensions before calling the release live.
