# Four-source Goal 2030 data federation and controlled social posting — 28 October 2026

## Identity, data ownership and implemented state

This release integrates with established code; it does not consolidate organisation databases, copy student or beneficiary records or manufacture achievements. The Goal 2030 section remains on Home and About, not in the main menu.

| Owner | Contract source | Approved fields | Actual source code and pending live gate |
|---|---|---|---|
| Goblis Group (GOS) | `goblis_group` | `startups`, `permanent_staff` | `Goblis-Group/goblis-group/integrations/waithaka-impact`; existing private approved aggregate projection and revision publisher; live operator-approved export and public route still to verify. |
| Ubunifu Institute (UIMS) | `ubunifu` | `social_enterprises`, optional `ubunifu_permanent_staff` | `Ubunifu-Institute/ubunifu-institute` PR #100 merged into `main` 28 Oct, merge SHA `42f3151917a26ae07c8c327cb48731887ee19187`. Includes native reconstructed PHP adapter, exporter, source publication and offline runtime tests. Protected ECS production promotion, persistent publication storage and first approved report remain separate. |
| AfriTrace Link | `afritrace` | `market_access` | `AfriTrace-Link/AfriTrace-Link/integrations/waithaka-impact`; source-side contract/publisher/export endpoint available in repository; source approval, runtime routing, persistent volume and live acceptance remain. |
| Goblis Foundation | `goblis_foundation` | corroborating `market_access`, optional deduplicated indirect aggregate | `Goblis-Foundation/GoblisFoundation/integrations/waithaka-impact`; source-side contract/publisher/export endpoint code; signed approval, route and actual metrics need verification. Foundation is NOT a second additive market-access source. |

### Direction of the measurement flow

Each source independently computes and certifies its own measures with its lawful source authority. Its trusted publisher serializes only exact approved JSON schema v2 and retains a private approval reference and immutable revision history. On publication, a read-only HTTPS endpoint exposes *only* the source-owned approved aggregates or a higher-revision withdrawal notice.

The Waithaka personal website reads the four source endpoints through `app/impact.php`, validates the domain/HTTPS and strict metric schema, stores source-owned measurement/approval/publication timestamps independently from consumer receipt and last check, and displays values only when explicitly approved. Its existing worker checks configured endpoints no more than every 15 minutes; an endpoint URL, a successful deployment or an HTTP 503 without an approved artifact is **not** impact evidence. Counters stay unavailable until real data arrives. Date-bounded cumulative enterprises are not summed across months. Permanent workers are headcount at month-end. Indirect beneficiaries require an approved methodology and source deduplication. Corrections require higher revision, and withdrawal removes previously approved public figures.

For the Group, its *certified* portfolio-level startup and employment measurements can be provided to its own external subsidiary/governance reporting without handing Ubunifu or Foundation direct read access to GOS payroll and contact tables. The **public personal Goal 2030 report** uses source-independent aggregates. Goblis Group must not be treated as a master source for Ubunifu admissions, Foundation programme allocation, or AfriTrace participant records. The source portfolios retain separate controllers and approvals; shared enterprise pseudonymous references could support a later bilateral, scoped consent and deduplication agreement, but **do not enable that interchange without bilateral authentication, signed agreements, purpose limitation, tenant authorization and withdrawal/replay UAT**.

The existing UIMS partner connector in `integrations/partner-boundaries` remains `SPECIFICATION_ONLY_DISABLED`, with signed envelopes and metadata-only referral inbox. A service referral does not prove admission, programme funding, market access, or income; source account activation is a separate release.

### Operator path for live feeds

1. Each source deploys its reviewed export reader in the governed application runtime, verifies durable private report storage, route, TLS, and *no report returns unavailable*, without inserting a synthetic artifact into production.
2. Each owning organisation adopts an exact certified monthly record, verifies consent/source definitions and immutable approval evidence, and publishes only the exact approved JSON bytes through its private source publisher; source revisions and withdrawals preserve the lineage.
3. The Waithaka Writer opens `/write/impact` and configures the source's actual read-only HTTPS endpoint and its dedicated bearer token *if required* inside private encrypted settings. Never paste source tokens into GitHub, this document, URLs or query strings. The provided path is a **candidate route only**.
4. Explicitly Check configured sources, then verify independent source period, approval, published and received dates plus correction/withdrawal behavior; confirm the values with the source owner before describing feeds as live. Subsequent production worker polling is bounded.
5. Only source-approved public aggregates may appear in Goal 2030 or newsletter editorial mentions; targets and unavailable values do not transform into achievements.

## Posting automation changes

`/write/publishing/automation` now shows the saved global worker flag, last weekly heartbeat, registered job states, named saved routes, supported direct/Buffer/assisted delivery modes and next **preferred** slots. A schedule is **not** a queued publication.

- Existing author workflow is still required: finished public source article, platform-appropriate text/media, explicit exact-version approval, verified personal account and consented scopes, and per-channel scheduled jobs.
- The weekly scheduler now refuses **new** delivery unless both the global worker and weekly run flag permit processing. The general unscoped worker now excludes pending registered weekly jobs while the weekly run flag is off, closing an unscoped bypass. Already accepted provider results can still be reconciled and are not silently duplicated.
- Manual assisted channels, notably a Facebook **professional personal profile**, remain assisted and can receive authorized reminders. The website does not substitute a corporate Page or a company business portfolio. Buffer may automate only capabilities it actually confirms for the exact account and tier.
- LinkedIn direct **personal member text/link** adapter is updated from deprecated `/v2/ugcPosts` to the versioned official Posts API `POST /rest/posts` with `Linkedin-Version: 202608` and `X-Restli-Protocol-Version: 2.0.0`. It requires the confirmed member identity and `w_member_social` grant. Only a verified `201` with recognized `x-restli-id` marks a post as published; an uncertain result is flagged for remote reconciliation, never blindly retried. This is implementation, **not proof that the member grant is active**. Official reference: https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-08
- X usage, account entitlement and spending controls remain provider responsibilities. Instagram and YouTube adapters keep their format/upload-stage and processing safeguards.
- The repository's existing `tiktok_business` connector is a distinct approved Business API product. It **does not** grant personal creator Content Posting API rights. TikTok public Direct Post requires eligible app approval, `video.publish` user consent, and successful client audit; unaudited Direct Post clients may only submit private-viewable content. Native creator posting should remain assisted until the proper product and audit are accepted. Official reference: https://developers.tiktok.com/docs/en/content-posting-api-get-started

### Release and acceptance policy

The branch includes pure policy checks for worker/weekly pause isolation, mode differentiation, approved source authority, missing vs zero source status, versioned LinkedIn payload and unknown post receipt. The normal PHP 8.3/8.4 + disposable MySQL suite, isolated Chromium journey suite and protected AWS OIDC production deployment must complete on the final head. Deployment acceptance additionally requests only unauthenticated private pages to confirm they remain protected. Do not create a test payment, email, actual social post, signed donor/student/enterprise record, source measurement or cross-company credential.

## Still open after source-code release

Real source data approval, protected ECS/GOS/Foundation/AfriTrace runtime endpoints and storage, four-way live reporting acceptance, complete personal-platform OAuth/permissions, the live posting worker heartbeat and an explicitly authorized first production post all remain independently verifiable gates. An internal source-code merge and a passing smoke test are not evidence of those account-specific facts.
