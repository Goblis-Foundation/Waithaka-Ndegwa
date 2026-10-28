# Production release — Search margins and Kenya-first journeys (28 October 2026)

## Exact release evidence

- Canonical repository: `Goblis-Foundation/Waithaka-Ndegwa`; default/production branch: `main`, confirmed by GitHub repository metadata on 28 October 2026.
- Implementation: [PR #68](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/68), merged successfully.
- Candidate: `7e3133555999fda99aba6f142428701a6893ded5`.
- Production merge SHA: `0ac57c32c863191ccbe9b56146159301f7d24d73`.
- AWS deployment workflow: [40657268949](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40657268949), conclusion `success`, completed **28 October 2026 08:39:03 UTC / 11:39:03 Africa/Nairobi**.
- Production application validation job: `122711241335`, conclusion `success`.
- AWS deployment job: `122712237132`, conclusion `success`, account `466401734428`, region `af-south-1`, environment `waithaka-ndegwa-production`.
- Exact candidate PHP 8.3/MySQL 8.4 and PHP 8.4/MySQL 8.4 checks: [40656933284](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40656933284), both succeeded.
- Candidate browser checks: [40656852101](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40656852101), success. The isolated browser log reports 25 original interaction checks, 19 shared-journey review checks, and **242 responsive public layout/search/location checks** across 13 viewport widths.

## Live read-only acceptance

The deployed workflow log records these exact read-only successes:

- `PASS: HTTPS homepage, expected GA4 destination, editorial content and database health.`
- `PASS: Public search page and current labelled responsive controls.`
- `PASS: Deployed responsive search stylesheet is served.`
- `PASS: Kenya-first public journey (read-only): /contact`
- `PASS: Kenya-first public journey (read-only): /founders/submit`
- `PASS: Kenya-first public journey (read-only): /opportunities/submit`
- `PASS: Kenya-first public journey (read-only): /promote`
- `PASS: Read-only public journey acceptance; no form or provider action.`

The original production publishing/branding checker also succeeded, including five-step sponsored promotion, deployed review script hash, Kenya-default controls, suitability gates, canonical/breadcrumb metadata, public sitemap, protected client access, monthly archive, shared footer, approved logo and icon dimensions and independently advancing visitor-statistics timestamps.

## User-visible changes

1. Search uses its current form selector `search-page-form`, visible labels, responsive two/three-control layout, one aligned page gutter and content filters. It is reachable from mobile Menu as well as the existing desktop icon.
2. In relevant contact/location forms the default country remains **Kenya**, and an independent phone calling-code field initially reads **+254**. No personal phone number is prefilled.
3. Kenya location includes a dropdown of 47 counties, text-entry **Sub-County**, optional **Ward**, and **Town/Area**; overseas forms show State/Province/Region, District/Area and City/Town/Locality without preserving inactive Kenyan fields in submissions.
4. Country changes do not automatically change an explicit telephone calling code. Navigating between countries preserves unsubmitted field edits when returning.
5. Contact, founder, booking, private consent, sponsored-promotion, employment-opportunity and private client-intake forms reuse the shared component. The opportunity contact telephone entry is a separate, independently editable contact control.
6. Legacy location records without Ward remain valid. Privacy controls, consent, source approval and real transaction safeguards remain unchanged.

Sub-County and Ward text fields are optional, bounded, and not falsely represented as complete official dropdown datasets. This does not add locations to short subscription, login, anonymous giving or payment-provider checkout flows.

## Limits and remaining tasks

The browser screenshots came from isolated PHP-rendered fixtures. Public production acceptance was read-only HTTP verification, **not** hands-on physical-device testing or submission of real personal data. No signed consent, email, payment, social posting, meeting request or provider activation occurred as part of these tests.

See [project recovery checkpoint](RECOVERY-CHECKPOINT-2026-10-28.md) and [27 October reconciliation](RECONCILIATION-2026-10-27.md). The account-dependent backlog remains: personal Facebook, LinkedIn, X, TikTok and WhatsApp publishing/auth grants; merchant and recurring-payment verification; approved newsletter postal address and delivery; verified source-owned Goal 2030 reporting feeds; additional Google/Microsoft provider scopes; and owner/device-specific acceptance. Those cannot be claimed active without specific provider/source evidence.

This documentation-only commit records the already verified production SHA and is not itself a second application release.
