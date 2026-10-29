# Daily live-site acceptance sentinel — 28 October 2026

## Why this is separate from the deployment checks

The existing production workflow runs real public acceptance immediately after a code release; the responsive fixtures exercise private/public templates without authenticated third-party actions. It does not monitor subsequent outages, layout regressions or changes outside a deployment. The new **read-only** sentinel adds a daily baseline at **06:20 Africa/Nairobi** (03:20 UTC) plus manual execution and changeset validation.

## Scope and safety

- **Target:** canonical `https://www.waithakandegwa.com`, with no credentials, tokens, cookies carried between visits, authentication attempts, form submissions, publishing, emails, messages, payments or edits.
- **Paths:** homepage, Start Here, Explore, Search, Contact, Book, Contribute, Newsletter, founder submissions, opportunity submissions, Opportunities and anonymous Writer login (12 paths). These routes are rendered anonymously; no form is submitted.
- **Views:** 320px narrow mobile, 390px mobile, 768px tablet and 1366px desktop (48 read-only route/viewport checks).
- **Enforced conditions:** HTTP 200, same canonical HTTPS origin, single main/H1, no horizontal overflow, login noindex and private/no-store cache controls; canonical host when a public canonical link is present. Form counts are recorded but submissions, login attempts and checkout actions are blocked.
- **Recorded rather than falsely certified:** Chromium synthetic navigation and rendering timings (TTFB, DOM content loaded, FCP, LCP, CLS), bounded JavaScript errors, axe-core WCAG A/AA findings and representative viewport screenshots. From the 29 October accessibility-gate release onward, any automated axe WCAG A/AA violation or incomplete scan fails the synthetic monitor; findings remain in the JSON artifact for triage. Passing automated rules is not a manual screen-reader or WCAG conformance certification.
- **Output:** `report.json` and screenshots in the GitHub Actions artifact, retained 14 days. Structural failures fail the run and are visible through the repository's normal Actions notification controls. No third-party monitoring subscription is introduced.

## How to run and interpret

Open `Actions → Live-site experience sentinel → Run workflow` in `Goblis-Foundation/Waithaka-Ndegwa`. The scheduled run operates on default branch `main` and may be delayed by GitHub. Examine `report.json`, URLs, timing and screenshot before classifying a failure. An unavailable public site, provider/network issue or synthetic browser artifact does not establish the root cause. No automatic rollback or publication is performed.

Synthetic values are **not** the real-user Core Web Vitals 75th-percentile field values. Chrome/Android/iOS physical-device flows, passkeys, push notifications, manual screen-reader testing, traffic-based Core Web Vitals, provider OAuth identity, inbox delivery and payment settlement remain independently open.

## No-drop reconciliation

The canonical implementation register remains `/write/reconciliation`, with all **33 previously recorded work areas** preserved. GitHub issues #70–76 remain open:

| Issue | Independent acceptance still required |
|---|---|
| #70 | Owner's personal Meta profile and WhatsApp Business eligibility, exact account and webhook proof |
| #71 | Personal LinkedIn, X and TikTok approved provider grants, publishing and receipts |
| #72 | Live M-Pesa, Pesapal, PayPal and eligible Wise support/recurrence settlement and cancellations |
| #73 | Approved postal sender address, verified transport/inbox and explicit newsletter delivery release |
| #74 | Source-owned dated approved impact aggregates from Goblis Group, Ubunifu, Foundation and AfriTrace |
| #75 | Exact Google/Microsoft account/scopes, calendar reauthorization and reviewed productivity actions |
| #76 | Real mobile/tablet, screen reader, notification/passkey and measured field performance |

**Not changed:** owner access aliases, already deployed authentication, branded logo/favicon, Google Workspace mail, monthly roundup schedule (first of each month at 00:00 Kenya time beginning 1 November 2026), daily organizational source brief, weekly original article research and weekly draft-only newsletter. The editorial worker and queued outbound email backlog remain separately gated; social/personal channels do not acquire publishing rights from a monitoring deployment.

This addition provides ongoing evidence and does not close issues #70–76 or claim that account-dependent features are live.

## Confirmed release and post-merge acceptance — 28 October 2026

- Recovery and implementation: [PR #91](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/91), merged application commit `ffaa9bda9f2ad4642ea61c8fa163fe2d8f429f17` on production branch `main`.
- The exact PR head `3053e1ece60f167aa4e85be9dd9964604b3a381d` passed all seven checks (PHP 8.3/8.4 on MySQL 8.4, Actions/CodeQL analysis, and the standalone live sentinel).
- The initial standalone browser evidence [run 40765036098](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40765036098) passed all 10 anonymous public/login probes at mobile/desktop widths, with zero structural failures and zero reported axe WCAG A/AA violation rules in the covered samples. Artifact `waithaka-live-site-sentinel` contains JSON and screenshots. Not a physical-device/field-CWV audit.
- The post-merge sentinel [run 40765440482](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40765440482) also succeeded.
- [Production deployment workflow 40765440434](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40765440434) completed **successfully** for the merged commit: **Validate application — success; Deploy to AWS account 466401734428 — success; Verify centered public section selectors on the live website — success**. The workflow reports completion at 20:51:46 UTC (23:51:46 Africa/Nairobi).
- This is evidence of a successfully deployed read-only monitor and existing production regression tests. It is **not** evidence that owner OAuth identity, SMS enrollment, real payouts, inbox deliveries, monthly sender details, approved source metrics or genuine device acceptance have been finished. The existing register and issues #70–76 remain open without status inflation.

## 29 October extension (separate release candidate)

The next change expands the monitoring script from the original ten browser probes to 48 and adds form-entry-point coverage without submitting data. The original ten-check evidence in the 28 October release record remains historically accurate; the expanded candidate must earn its own CI and live-deployment acceptance.

The Google Calendar owner recovery change preserves a previous selection **only** for the same returned Google subject as a prechecked choice pending explicit confirmation. A different subject remains a different account. Stale or unavailable calendar IDs are not offered for confirmation, and pending authorization cannot read the old calendar. The owner can reauthorize access from Google workspace, review the returned calendar list, and confirm it. Existing event content, OAuth secrets and contacts are not copied to the report. These code changes do not fix a provider's HTTP 400 themselves or grant a missing OAuth scope.

## 29 October 2026 — PR #92 production acceptance

- **Merged:** [PR #92](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/92) from `fix/2026-10-29-account-recovery-and-mobile-acceptance`; reviewed head `34869ea459e936e99cce4a3469304e618772f5db`; exact merged production commit `10113a04f014692a8fc9bdfec76d24f575bc9d69`.
- **Pre-merge:** eight exact-head check jobs succeeded, including the PHP 8.3/8.4 and MySQL 8.4 matrix, CodeQL/Actions analysis, isolated responsive-browser tests and [48-probe live sentinel](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40815388580). Its 48 read-only HTTP/structure checks across 12 paths and four widths returned 0 structural failures and 0 automated axe WCAG A/AA violation rules in the scanned samples.
- **Post-merge:** [live-site sentinel 40815734517](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40815734517) and [experience-browser run 40815734502](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40815734502) succeeded.
- **Production AWS deployment:** [workflow 40815734467](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40815734467) completed successfully at **29 October 2026 04:16:16 UTC / 07:16:16 Africa/Nairobi**. All three jobs succeeded: application validation, deployment to AWS account `466401734428`, and independent live on-page selector acceptance. The final job reports **445 passed live read-only selector checks across 25 pages**.
- **Safety:** The deployment fixes preservation of selections after same-subject Google Calendar reauthorization, while pending confirmation denies calendar API access and old unavailable calendar IDs cannot be reselected. This code change does not itself renew AfriTrace's previously failing Google grant or prove mailbox delivery. Browser probes render forms without submissions; neither field performance nor physical assistive-device conformance was measured.
- **Separate unfinished gates preserved:** issues [#70](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/70) through [#76](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/76) stay open for provider identity/grants, real settlement, sender postal details/inbox acceptance, certified monthly source impact data, account-level reauthorization and physical-device/field-performance testing. Previously queued emails were not released and no social post or payment was initiated.
- **Source contract review:** code for schema-v2 source-owned approved-impact exports exists in the repositories `Goblis-Group/goblis-group`, `Ubunifu-Institute/ubunifu-institute`, `AfriTrace-Link/AfriTrace-Link` and `Goblis-Foundation/GoblisFoundation`; their own README contracts withhold metrics until a governed, persistent, source-approved monthly artifact is published and externally verified. No unapproved figure was imported or published.

## 29 October — accessibility regression gate candidate

The existing 48 anonymous browser probes already passed automated axe checks in prior CI. This change upgrades the same scoped WCAG A/AA rules from informational to a **failure gate** and also fails if axe itself cannot run. No physical device, user account, payment, personal content or form submission is involved. Incomplete/manual-review items are preserved as evidence but require separate human inspection. Issue #76 remains open for genuine assistive technology, physical devices and field Core Web Vitals. Production status must be verified against this candidate's exact checks and AWS workflow before declaring deployment complete.
