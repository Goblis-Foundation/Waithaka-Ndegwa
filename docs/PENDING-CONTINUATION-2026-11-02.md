# Pending-work continuation — 2 November 2026

Baseline: production/main `35e4125f7b9bd411f8d9f465e7b5e4444825483f` (PR #122). This continuation preserves the 33 recorded work areas and the seven independently evidenced acceptance streams. Source completion below does not assert deployment or account activation.

## Scheduled Search Console reviews

`/write/google/search-reviews` adds owner-only opt-in reads using the existing confirmed Search Console grant. The owner reviews the exact returned account and selected property, a 28- or 90-day window and a 24-hour or seven-day interval. Settings start disabled. Saving an opt-in starts the next interval without calling a provider; the owner may explicitly request a read after setup, at most once per hour. The existing production preparation timer executes due reviews independently of outbound delivery.

Each run uses two adjacent equal-length finalized periods ending three days ago. Existing decline/CTR review rules and the 1,000-row sample limit remain explicit. Missing rows are unavailable, never fabricated zeroes. The result is an editorial prompt, not a diagnosis or a traffic prediction. No content editing, indexing request, message, AI call or social publication occurs.

Settings, attempt state and up to six bounded summaries use the existing encrypted settings store. Permission is bound to the account, property, scopes, verification and application. A stale setup form cannot opt a different target into scheduled reads. A changed connection pauses work; disabling clears saved summaries, advances the configuration version and prevents an in-flight response being saved. Attempt intent is persisted before I/O. Failed reads retain the previous dated report and store no raw provider error. There is no retry loop. Owner-only GET/HEAD performs no provider call.

## Nike Run Club mapped file history

Nike Run Club joins the private activity workspace as an independent **owner-mapped CSV source**, not a direct Nike API/login or background sync. Obtain the owner's records and map them to the existing `/write/activity/template` schema. Use stable source identifiers, explicit timestamp offsets, metres and measured active seconds; unavailable measurements stay blank. Unmapped archives, GPS, heart rate and clinical columns remain rejected.

The existing encrypted preview, explicit one-use confirmation, merge/replacement, source/account separation, deletion, weekly/monthly/annual targets, graphs and CSV/print reporting now cover this source. Launch readiness includes its exact import next step and the public activity privacy notice describes this flow. Annual coverage remains an explicit owner declaration for imports, not provider verification.

## Validation and release gates

Focused tests cover scheduled opt-in, exact target binding, stale versions, ownership, due times, bounded history, failed reads, cancellation and connection changes during I/O; Nike import source isolation and missing-measurement handling; and the HTTP owner boundary. The browser fixture inventory includes empty/saved search-review views and the additional activity setup card. The immutable production bundle explicitly requires the new search module and view.

A pre-existing opportunity test used a server-local timestamp as a UTC timestamp for its past-date rejection. It now uses `gmdate`, preserving the actual rejection requirement in Nairobi and UTC.

Local evidence and required remote CI/AWS acceptance must be reported separately. A local MariaDB run does not substitute for the PHP 8.3/8.4 + MySQL 8.4 CI matrix, automated browser/accessibility jobs, or a production deployment. Never merge by claiming those checks passed without actual run evidence.

## Retained unfinished work

- Personal Facebook/WhatsApp sender, app/product permissions, coexistence, templates, signed callbacks and actual approved delivery evidence; closing issue #70 alone does not establish acceptance.
- LinkedIn/X/TikTok/Threads/Telegram/Discord actual credentials, identities, destinations and publication receipts. Signal remains assisted. WeChat, Snapchat and Reddit capability requests remain unfinished.
- Newsletter approved personal postal address, sender/inbox and suppression acceptance; individual unsent-backlog review. No queue is released by this continuation.
- M-Pesa/Pesapal/PayPal/Wise actual merchant, signed callback, settlement, recurring-consent/cancellation and commercial invoice acceptance.
- Four source-owned approved impact reports, precise definitions, correction/withdrawal and consumer read-back. Previous HTTP responses are dated observations, not current measurements or numerical zeroes.
- Google Health/Strava dedicated apps and owner consent; physical Health Connect companion/source/history-permission and import acceptance. Mapped Nike import support does not establish source coverage or a connected Nike account.
- Account-specific Docs/Sheets/Tasks/Meet/Gmail/Postmaster/YouTube-management/Microsoft consent and real operation acceptance; canonical Foundation identity remains authoritative for its aliases.
- Further eligible Meta products and engagement actions; optional Microsoft expansion; selected-project BigQuery/Looker, travel-aware availability and richer analytics work remain separate unfinished capabilities, not activated by these source changes.
- Physical phones/tablets, passkey/push, assistive technology, field Core Web Vitals, recovery rehearsal, failure-alert delivery, expired-grant and cost-monitoring acceptance.
- Owner/source material for genuine startup journeys, founder features and resource examples; repeatable real AI-provider acceptance. No fabricated personal experiences or metrics.
- Final manual ten-channel production-owner reinspection remains distinct from the previously successful registration/read-back deployment hook.
- Historical data completeness remains unverified. The selected AWS installation explicitly excluded the former host database/uploads at the owner's direction. No historical transfer is claimed; record-level source/destination reconciliation requires the actual source records and appropriate consent history. Existing connection grants are not bulk data transfers.

Keep these gates visible in the existing register and launch workspace. No new provider scope, credential purchase, charge, real post, message or historical data import is performed by this release.

## Local acceptance evidence

- PHP 8.3.6 / isolated MariaDB 10.11.14: **101/101 application suites passed**, followed by every additional Composer test-script entry (including private HTTP presence, roundup and commercial database checks).
- Focused scheduled-review suite: **41 checks**, including exact-target stale-form rejection; actual encrypted persistence/deletion also passed in the Google database suite.
- Activity suite: **65 checks**; actual HTTP Nike preview, one-use confirmation, report, deletion and other-source retention passed in the growth suite.
- PHP/JavaScript syntax checks, strict Composer manifest/platform checks and locked dependency audit passed. No security advisory was returned by that audit.
- Synthetic desktop rendering was inspected in the managed preview. New browser/accessibility fixtures are prepared; required remote automated jobs and physical-device acceptance are still pending.
- The current main baseline was independently rechecked against GitHub. The existing deployment run `41122160115` completed all three production jobs for `35e4125`; an anonymous live health response returned `{"ok":true}` and the existing read-only public-journey verifier passed. These observations verify the baseline, not deployment of this continuation.
