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

## Verified production release evidence — 29 October 2026

### Owner launch workspace (PR #97)

- Candidate reviewed: `493ff69a501d24b528cdcc55b250e3a7924b3624`; merged on main as `6a9b957fd05d7943fd32783a45520a5dc301092a`.
- The exact PR head passed all seven checks: PHP 8.3 and 8.4 with MySQL 8.4 on push/PR, CodeQL and Actions analysis, plus the responsive browser suite.
- [Production workflow 40827574739](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40827574739) completed successfully at **29 October 2026 06:20:43 UTC / 09:20:43 Africa/Nairobi**. Its three jobs — application validation, AWS deployment and independent live section-selector acceptance — all succeeded. The final browser job reported **445 live read-only selector checks across 25 pages**.
- The private owner-only route is `/write/activation`. Its isolated authorization/evidence unit test passed. This does not prove a real owner login on a separate physical device, third-party account activation, email inbox delivery or settled payment.

### Hide owner-only navigation from collaborators (PR #98)

- After detecting that the link was visible to non-owner Writers, PR #98 added identity-based navigation filtering and regression tests. An initial test fixture failed due to its missing `csrf_field()` stub; this was corrected and the exact revised head `43ae9d0fc912af99f9835ab30e8744ca27784b00` passed all seven checks.
- Merged main application commit: `666613a258d9826b6782868bdc05dbe7497873a7`.
- [Production workflow 40828922652](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40828922652) completed successfully at **29 October 2026 06:34:07 UTC / 09:34:07 Africa/Nairobi** with application validation, AWS deployment and the independent live browser job all passing. The live browser job again reported **445 read-only selector checks across 25 pages**.
- Other Writers do not see the privileged activation link in the navigation or implementation register. The server-side exact-owner 403 gate remains mandatory even when a user knows the route.

### Completion boundaries

The software changes above are live. The **seven acceptance issues #70–76 remain open**, with the complete **33-area implementation register** preserved. Stored connection flags are not current provider receipts; real financial settlement, official creator/direct posting entitlement, verified WhatsApp identity, newsletter sender postal/inbox proof, certified source-owned impact artifacts, device enrollment and field performance still require their own authoritative evidence. The existing outbound worker/bulk backlog is not released by these two deployments. The public monthly roundup schedule remains separate from email delivery.
