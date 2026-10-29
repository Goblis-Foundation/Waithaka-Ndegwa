# Launch activation controls — 29 October 2026

## Purpose and authority

The private owner-only `/write/activation` screen sits between the already deployed Waithaka Ndegwa application and the remaining external activation work. It is **not** an authorization broker, email queue release, merchant status engine, or provider live test. It reads an allowlisted subset of locally saved connection/configuration states and supplies exact links to existing account setup screens. Only the configured, active Writer owner ID may open it. An email alias, organization affiliation or Writer login alone is never enough.

The permanent `/write/reconciliation` register remains authoritative for 33 recorded work areas. Seven provider/real-world acceptance issues remain explicitly open. Their numbers, descriptions, routes and next steps are fixed in `app/activation_centre.php` and do not disappear when a provider shows a locally saved connection.

## Implemented

- Add a branded accessible and responsive activation workspace, linked from the Writer sidebar, directory and implementation register.
- Show seven independent acceptance gates: personal Meta/WhatsApp (#70), LinkedIn/X/TikTok (#71), payments (#72), newsletter (#73), Goal 2030 (#74), Google/Microsoft (#75), and physical devices/field performance (#76).
- Show only allowlisted local facts: recognized provider connection states, counts of credentials explicitly configured for a live merchant environment (not monetary amounts or settlement), sender-field/transport booleans, status of the four approved impact snapshots, Google calendar connection counts and whether the outbound worker is enabled **in configuration**.
- Preserve `unknown` where reads fail. Neither disconnected accounts, missing source data, failed payments nor unavailable metrics are displayed as verified zero outcomes. Do not expose OAuth access tokens, identifiers, raw email addresses, phone numbers, card information, subscriber records, event details, payment amounts or unpublished content.
- Enforce GET/HEAD-only, private/no-store headers, active session, exact configured owner ID and an explicit 403 response for other writers. No provider network calls, scheduling, SMS, webhook registrations, financial operations, email sends, publication or mass replays are made when viewing the dashboard.
- Add a standalone synthetic regression that checks all seven issues, secret non-disclosure, exact owner boundaries, responsive markup and unknown-state semantics, and wire it into every PHP/MySQL CI matrix run.

## Existing state retained

- Primary domain: https://www.waithakandegwa.com; production repository `Goblis-Foundation/Waithaka-Ndegwa`, default branch `main`, AWS account `466401734428` / `af-south-1`.
- Approved WN identity, website articles and media, SEO and PWA, Google Workspace mail relay, previous calendar selections, 33 tracked work areas, seven acceptance issues and paused mail backlog.
- The public monthly roundup is scheduled for 1 November 2026 at 00:00 Africa/Nairobi; subscriber email readiness is **not** implied by public archive scheduling. Weekly editorial slots may prepare private copy/drafts only when source evidence and the workflow allow. No automatic social publication from arbitrary source notes.
- No new paid API entitlement, OAuth grant, merchant settlement, phone number enrollment, sender postal address, source-owner metric approval or device proof is invented.

## Release evidence and limits

Require the exact-head PHP 8.3/8.4 MySQL 8.4 suites, branch security/CodeQL checks, read-only public-site accessibility monitor and AWS deployment followed by independent live browser acceptance. A successful application release is not provider/account acceptance. To close any issue #70–76, attach direct provider evidence and separate operator approval to that issue.

The new dashboard intentionally does not show a green 'live' label based on a saved token. It is a handoff for account-holder actions and immutable receipts; all external activity must use the existing purpose-scoped route and its approval requirements.
