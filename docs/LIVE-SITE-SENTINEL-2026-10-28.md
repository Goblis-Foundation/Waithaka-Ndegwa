# Daily live-site acceptance sentinel — 28 October 2026

## Why this is separate from the deployment checks

The existing production workflow runs real public acceptance immediately after a code release; the responsive fixtures exercise private/public templates without authenticated third-party actions. It does not monitor subsequent outages, layout regressions or changes outside a deployment. The new **read-only** sentinel adds a daily baseline at **06:20 Africa/Nairobi** (03:20 UTC) plus manual execution and changeset validation.

## Scope and safety

- **Target:** canonical `https://www.waithakandegwa.com`, with no credentials, tokens, cookies carried between visits, authentication attempts, form submissions, publishing, emails, messages, payments or edits.
- **Paths:** homepage, Start Here, Explore, Search, Contact, Book, Contribute, Newsletter, founder submissions, opportunity submissions, Opportunities and anonymous Writer login (12 paths). These routes are rendered anonymously; no form is submitted.
- **Views:** 320px narrow mobile, 390px mobile, 768px tablet and 1366px desktop (48 read-only route/viewport checks).
- **Enforced conditions:** HTTP 200, same canonical HTTPS origin, single main/H1, no horizontal overflow, login noindex and private/no-store cache controls; canonical host when a public canonical link is present. Form counts are recorded but submissions, login attempts and checkout actions are blocked.
- **Recorded rather than falsely certified:** Chromium synthetic navigation and rendering timings (TTFB, DOM content loaded, FCP, LCP, CLS), bounded JavaScript errors, axe-core WCAG A/AA findings and representative viewport screenshots. Axe findings appear in the JSON artifact for triage and do not yet form a pass/fail gate; they are not a manual screen-reader or WCAG certification.
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
