# Verified release: Goal 2030 source readiness and controlled posting automation

## Exact immutable production evidence

**Date:** 28 October 2026. **Repository:** `Goblis-Foundation/Waithaka-Ndegwa` (default/production branch `main`).
- Merged [PR #77](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/77), candidate `721dd0b170ba7abd08faaefaea8af5ba441636c9`.
- Production merge commit `ad5646591f8bae345bee50904adb6aa55b2a7e02`.
- [AWS production workflow 40671010220](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40671010220), **completed / success at 2026-10-28 10:26:38 UTC / 13:26:38 Africa/Nairobi**.
- Application validation job `122757464767` **success**. AWS OIDC target and EB production job `122758531004` **success**, account `466401734428`, `af-south-1`, `waithaka-ndegwa-production`.
- Exact candidate application checks on PHP 8.3 + MySQL 8.4, PHP 8.4 + MySQL 8.4 succeeded in [run 40670603937](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40670603937); second [run 40670595981](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40670595981) also success. [Browser run 40670603998](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40670603998) success. PR CodeQL analysis success.
- The first CI candidate correctly rejected an over-broad test condition that matched the obsolete URL in a source comment; the executable LinkedIn request already used the versioned API. This was corrected in candidate commit `721dd0b...` and the full suites reran successfully before merge.

## Live read-only acceptance recorded in workflow

- `PASS: HTTPS homepage, expected GA4 destination, editorial content and database health.`
- `PASS: Public search page and current labelled responsive controls.`
- `PASS: Kenya-first public journey (read-only): /contact`
- `PASS: Kenya-first public journey (read-only): /founders/submit`
- `PASS: Kenya-first public journey (read-only): /opportunities/submit`
- `PASS: Kenya-first public journey (read-only): /promote`
- `PASS: / has a real Goal 2030 section, but no standalone primary menu link.`
- `PASS: /about has a real Goal 2030 section, but no standalone primary menu link.`
- `PASS: anonymous access remains blocked on /write/impact`
- `PASS: anonymous access remains blocked on /write/publishing/automation`
- `PASS: no private data, payments, posts or provider actions.`
- Existing production smoke additionally passed five-step promotion, approved exact media/package variants, correct shared-journey hash, suitability, article/founder routes, public sitemap/canonical/breadcrumb SEO, archives, private-client isolation, approved logo/icons, data health and advancing read-only visitor-statistics freshness.

## Actual changes

### Four-source data read readiness

The owner-only `/write/impact` screen now explains and evaluates the true readiness state of **Goblis Group / Ubunifu Institute / AfriTrace Link / Goblis Foundation** independently. It shows allowed source metric keys, non-additive Foundation corroboration, secure candidate API path, actual stored connection state and source-governance chain. A configured URL is not a live or approved report.

The existing Waithaka app `app/impact.php` already reads approved version 2 aggregates via HTTPS on a bounded worker with revision/withdrawal and measurement/approval/publication/receipt distinctions. This release does not set source URLs or bearer tokens, fabricate counts, authorize a source approver or transmit raw records. See [four-source federation design](GOAL2030-FOUR-SOURCE-POSTING-RELEASE-2026-10-28.md).

**Ubunifu source recovery:** source-side exporter PR [Ubunifu #100](https://github.com/Ubunifu-Institute/ubunifu-institute/pull/100) merged into UIMS main at `42f3151917a26ae07c8c327cb48731887ee19187`. UIMS impact contract tests and full-source acceptance succeeded, but post-merge read-only staging acceptance failed unresolved DNS/HTTPS/SNI launch gates and Ubunifu AWS destination inventory failed 261 AccessDenied-type read operations. The reviewed source adapter is **not claimed deployed into ECS or serving certified figures**. Its production artifact and persistence also await source-governed activation.

**Group-to-recipient operational exchange:** private metadata-only partner referral is distinct from this public reporting contract. The implementation/audit task is [Goblis Group #239](https://github.com/Goblis-Group/goblis-group/issues/239) with [UIMS interoperability epic #53](https://github.com/Ubunifu-Institute/ubunifu-institute/issues/53). Different organisations retain data-controllership, consent and admission/market/grant decision authority. No raw contact/learner/payroll/beneficiary data was copied.

### Posting automation

The private `/write/publishing/automation` now reads saved route configurations, worker flag and recent heartbeat, registered job-state counts, manual/direct/Buffer distinctions, associated confirmed account identity and **preferred** next platform slots. A preferred slot is not scheduled work, and saved OAuth is not proof of a posting grant.

- The global worker flag and weekly `run` setting must both permit weekly delivery. A paused weekly queue is now excluded from the unscoped generic worker for *new* scheduled jobs; remote results already accepted remain reconciled, never replayed blindly.
- The LinkedIn member text/link adapter uses official versioned `POST https://api.linkedin.com/rest/posts`, `Linkedin-Version: 202608`, valid personal member identity, exact approved text and strictly confirmed `201 / x-restli-id`. This feature is code-tested but **no live LinkedIn account authorization or real post is claimed**.
- Personal Facebook professional-mode profile remains assisted; it is not a Facebook Page. TikTok creator content posting requires a distinct eligible product, `video.publish` grant/user consent and audit for unrestricted public post access; current Business API is a separate provider product. No grant, spending authorization, media conversion, public post or channel selection was silently performed.

## Remaining gates (open, not failed deployment)

1. Source-owned approved numeric reports and governed persistent endpoints for all four organisations; production receiver URLs/tokens; exact shared-service identity, version 2 corrections/withdrawals, consent, replay and real receipt evidence.
2. Completion of UIMS staging/role/read-only AWS acceptance and approved protected ECS promotion; no blanket IAM broadening.
3. Verification of personal platform OAuth products, actual account/destination IDs, X fees, TikTok policy and an owner-approved first real publication with verifiable provider receipt.
4. Production scheduler heartbeat and actual global worker enablement as displayed **inside authenticated Writer**; CI cannot claim it is enabled from source configuration alone.
5. Newsletter sender postal compliance, merchant settlement/recurring mandate and genuine approved Goal 2030 impact source feeds remain separately open.

Track [Waithaka issue #74](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/74) for source activation and [issue #71](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/71) for personal posting permissions. Public HTML smoke and source-code tests are not direct proof of private account runtime operations, provider action outcomes, authentic user-device experience or new paid service activation.

This report is documentation-only and does **not** trigger a new AWS production rollout. The immutable app release SHA above remains the deployed version.
