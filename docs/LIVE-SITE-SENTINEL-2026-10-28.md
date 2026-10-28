# Daily live-site acceptance sentinel — 28 October 2026

## Why this is separate from the deployment checks

The existing production workflow runs real public acceptance immediately after a code release; the responsive fixtures exercise private/public templates without authenticated third-party actions. It does not monitor subsequent outages, layout regressions or changes outside a deployment. The new **read-only** sentinel adds a daily baseline at **06:20 Africa/Nairobi** (03:20 UTC) plus manual execution and changeset validation.

## Scope and safety

- **Target:** canonical `https://www.waithakandegwa.com`, with no credentials, tokens, cookies carried between visits, authentication attempts, form submissions, publishing, emails, messages, payments or edits.
- **Paths:** homepage, Start Here, Explore, Search, and anonymous Writer login.
- **Views:** 390px mobile and 1366px desktop.
- **Enforced conditions:** HTTP 200, same canonical HTTPS origin, single main/H1, no horizontal overflow, login noindex and private/no-store cache controls; canonical host when a public canonical link is present.
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
