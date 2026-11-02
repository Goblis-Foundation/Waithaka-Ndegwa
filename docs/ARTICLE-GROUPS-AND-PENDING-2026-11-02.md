# Article grouping and pending-task audit — 2 November 2026

## Verified baseline

GitHub main was `c8cb65d48c79da633bf5aaef9851cef61d9aad07` (PR #124). Production workflow [41403432778](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/41403432778) completed application validation, AWS deployment, publishing acceptance and 445 live read-only browser checks across 25 pages. This confirms the previous search-review/Nike-import release and corrected monthly archive verification; it does not certify external account grants or data completeness.

## Implementation in this continuation

- Four reading paths: Personal Experiences, My Enterprise Journey, Founder Stories, Ideas & Perspectives. Home, My Thoughts, Start Here and All sections expose the paths without putting topic categories in the main menu.
- Personal Experiences, My Enterprise Journey and Ideas & Perspectives have dedicated archives under `/my-thoughts/group/`, with server-side phrase search, topic filtering, deterministic pagination, canonical collection URLs and sitemap discovery. Filtered searches are noindex.
- The article editor supports multiple groups independently of topics. Private drafts/autosaves retain their own grouping until explicit publication; an older client preserves the current selection. Existing unclassified articles remain discoverable in Ideas & Perspectives. No personal experiences or venture achievements are fabricated.
- Founder Stories retains its existing separate final-copy approval workflow. Published enterprise profiles automatically contribute related due public articles to My Enterprise Journey; withdrawing a profile removes that automatic association. Canonical article pages link to their public groups and enterprise profiles.
- The enterprise directory distinguishes its profile catalogue from Goblis Group’s approved startup count toward 30 successful startups by June 2030. Missing/withdrawn reports show no numeric result; approved zero and dated stale results remain distinguishable. Profiles, articles and operating labels do not qualify a startup as successful. The separate Ubunifu target is preserved.
- The article editor has explicit labels for its settings. Expanded responsive coverage exposed and repairs the narrow-screen formatting toolbar/grid overflow; four setting tabs fit within the writing workspace.
- Enterprise article lookup now applies the canonical article’s founder final-copy visibility guard, closing an alternate-discovery gap.
- Migration 210 adds only a nullable grouping field. A narrow, checksum-checked deployment command uses the existing pinned production migration identity, locks concurrent upgrades and verifies repeat application. Runtime permissions are unchanged. The immutable bundle requires its hook, command, module, views and CSS.

## Pending acceptance streams checked against GitHub

The repository has exactly six open issues (no open PR at the time of this inventory):

| Issue | Remaining acceptance |
| --- | --- |
| [#71](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/71) | Actual personal LinkedIn/X/TikTok products, grants, destinations and approved publication receipts. |
| [#72](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/72) | Exact merchant identities, signed callbacks, real settlement, recurring consent/cancellation and commercial reconciliation. |
| [#73](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/73) | Approved newsletter postal address, verified sender/inbox/suppression acceptance and individual backlog review before delivery activation. |
| [#74](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/74) | Four source-owned approved impact reports, definitions, corrections/withdrawals and consumer read-back. Source exporter code does not establish approved measurements. |
| [#75](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/75) | Account-specific Google/Microsoft scope, licensing and real-operation acceptance; optional BigQuery/Looker and broader productivity capabilities remain unfinished. |
| [#76](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/76) | Actual phones/tablets, passkey/push, assistive technology, field performance and recovery/alert acceptance. Automated tests are distinct. |

[#70](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/70) is closed, but personal Meta/WhatsApp sender ownership, coexistence/product permissions, templates, signed callbacks and approved delivery evidence remain separately tracked. Issue closure is not acceptance evidence.

Additional retained work: WeChat/Snapchat/Reddit capability requests; further eligible Meta and Microsoft actions; travel-aware availability/richer analytics; exact Google Health/Strava consent and Health Connect physical-device/history-import acceptance; mapped Nike source coverage; genuine owner/source material for startup journeys and founder features; repeatable real AI-provider acceptance; ten-channel production-owner manual reinspection. No new account consent, real message/post, transaction or bulk import is implied by this release.

Historical completeness remains unverified: the selected fresh AWS installation explicitly excluded the former host’s database/uploads at the owner’s direction. Record-level reconciliation requires the actual source records and consent history; connection grants are not data transfers.

## Complete register retained

All 33 existing IDs remain. States below are the register’s recorded implementation/acceptance states; they are not newly verified private account status.

| ID | Area | Recorded state | Workspace |
| --- | --- | --- | --- |
| writer-login | Writer sign-in and session continuity | Implemented; production verified | [/write/security](https://www.waithakandegwa.com/write/security) |
| admin-redesign | Professional administration and module navigation | Implemented; production verified | [/write/control-centre](https://www.waithakandegwa.com/write/control-centre) |
| admin-identities | Owner aliases, Google/Microsoft sign-in and verification | Implemented; provider acceptance pending | [/write/security/sign-in](https://www.waithakandegwa.com/write/security/sign-in) |
| module-integrity | Module layout and migration integrity | Implemented; production verified | [/write/operations](https://www.waithakandegwa.com/write/operations) |
| worker-delivery | Paused worker and queued delivery | Activation pending | [/write/operations](https://www.waithakandegwa.com/write/operations) |
| pwa-brand-20261028 | Consistent website and Writer app identity | Production assets verified; device acceptance pending | [/](https://www.waithakandegwa.com/) |
| communications-cycle-20261028 | Mailchimp-style editorial, newsletter and social coordination | Implemented; runtime/provider acceptance pending | [/write/communication?tab=content](https://www.waithakandegwa.com/write/communication?tab=content) |
| brand | Approved personal branding | Implemented | [/write/media](https://www.waithakandegwa.com/write/media) |
| goal-navigation | Goal 2030 is a section, not a page | Implemented; production verified | [/about#goals-2030](https://www.waithakandegwa.com/about#goals-2030) |
| footer | Uniform footer and visitor counters | Implemented | [/write/visitor-statistics](https://www.waithakandegwa.com/write/visitor-statistics) |
| sitewide-flow | Public page and journey alignment | Implemented; production verified | [/explore](https://www.waithakandegwa.com/explore) |
| search-ui | Public search layout and mobile discovery | Implemented; production verified | [/search](https://www.waithakandegwa.com/search) |
| speaking | Remove speaking invitations | Implemented; production verified | [/contact](https://www.waithakandegwa.com/contact) |
| location | Country and administrative location rules | Implemented / extended | [/write/desk](https://www.waithakandegwa.com/write/desk) |
| email | Branded acknowledgements and responses | Implemented; delivery status distinct | [/write/correspondence](https://www.waithakandegwa.com/write/correspondence) |
| monthly | Automatic monthly newsletter | Implemented; future delivery gated | [/write/monthly-roundup](https://www.waithakandegwa.com/write/monthly-roundup) |
| opportunities | Grants, funding, conferences and programmes | Implemented | [/write/desk](https://www.waithakandegwa.com/write/desk) |
| features | Paid sponsored features | Implemented; activation gated | [/write/desk/settings](https://www.waithakandegwa.com/write/desk/settings) |
| jobs | Founder-approved employment opportunities | Implemented | [/write/opportunities](https://www.waithakandegwa.com/write/opportunities) |
| microsoft-actions | Reviewed Microsoft actions | Implemented; grant-dependent | [/write/microsoft/actions](https://www.waithakandegwa.com/write/microsoft/actions) |
| microsoft-domain | Microsoft domain and alias | Configuration verification | [/write/microsoft](https://www.waithakandegwa.com/write/microsoft) |
| accounts | Google Workspace and mailbox identities | Verify live permissions | [/write/google](https://www.waithakandegwa.com/write/google) |
| whatsapp | Personal-brand WhatsApp and Meta | External activation / verification | [/write/opportunities/delivery](https://www.waithakandegwa.com/write/opportunities/delivery) |
| socials | Personal social publishing and monetisation | Posting schedule implemented; individual provider access pending | [/write/connections](https://www.waithakandegwa.com/write/connections) |
| planner | Daily/weekly programme and trusted devices | Existing implementation; acceptance retained | [/write/program](https://www.waithakandegwa.com/write/program) |
| impact | Approved public impact reporting | Source exporters implemented; approved data activation pending | [/write/impact](https://www.waithakandegwa.com/write/impact) |
| google-expansion | Additional Google capabilities | Partly implemented; further grants pending | [/write/google](https://www.waithakandegwa.com/write/google) |
| google-actions | Reviewed Google Docs, Sheets and Tasks | Implemented; provider acceptance pending | [/write/google/actions](https://www.waithakandegwa.com/write/google/actions) |
| calendar-refresh | Private selected-calendar incremental refresh | Selected-account refresh verified; renewal follow-up pending | [/write/google/sync](https://www.waithakandegwa.com/write/google/sync) |
| private-preparation | Private preparation observability | Implemented; runtime verified | [/write/operations](https://www.waithakandegwa.com/write/operations) |
| other-networks | Other networks and private activity services | Partly implemented; separate consent and device acceptance | [/write/activity](https://www.waithakandegwa.com/write/activity) |
| giving | Giving and commercial payments stay separate | Preserved | [/write/connections](https://www.waithakandegwa.com/write/connections) |
| consent | Media consent, evidence and private documents | Preserved | [/write/media-centre](https://www.waithakandegwa.com/write/media-centre) |

## Validation

Focused policy, fresh-database and HTTP tests cover cross-listing, legacy fallback, draft/public separation, scheduling, founder final-copy boundaries, public enterprise association and withdrawal, search escaping, topic filtering, pagination, migration recovery and idempotency. Actual templates are added to responsive and automated accessibility fixtures, including unknown/zero/reported enterprise progress and the article editor. Production acceptance visits the new group routes and verifies sitemap and progress disclosure.

Local test evidence and remote PHP/MySQL, browser/accessibility and AWS acceptance are reported independently in the release PR and final handoff. The application register does not claim real provider delivery from these tests.

## Migration rollout correction

The first feature rollout, workflow 41425419942, failed before applying migration 210. Its public continuity monitor observed no faulty samples; read-only preflight 41428044259 confirmed the prior version Ready/Green and identified the new hook's missing migration authority at `bin/article-groups-migrate.php:13`. PR #126 adds bounded, filtered retained-log diagnostics without publishing raw server logs.

A subsequent attempt, workflow 41430472853, verified the encrypted RDS recovery point but stopped on a private-bucket PutObject denial before changing the serving environment; its continuity monitor again observed no faulty samples. The final correction resolves owned-stack metadata directly and requires no private-media write.

The corrected release registers migration 210 in the existing checksum receipt, owned encrypted RDS recovery and temporary administrator-read cleanup path. A checksum pinned in the production workflow authorizes only this exact additive migration when fresh bootstrap and every legacy migration are already complete; other schema/bootstrap changes retain their existing separate gate. An existing completion receipt returns subsequent releases to ordinary code-only deployment.

For this article-only migration, the hook reads the exact owned data stack's metadata to resolve its administrator-secret identifier. It validates the stack name/ARN, account/region, database host/name, uniqueness and completeness before fetching the secret into process memory. The instance role receives only the exact administrator-secret read and `DescribeStacks` on that owned stack, both independently expiring after one hour; the normal runtime-secret permission remains available. Existing EXIT cleanup removes both temporary grants on success/failure. The code-only additional-batch rollout, sender/worker comparisons, routing and environment configuration remain unchanged. No private-media write, authority file or additional environment variable is required. Completion receipts use the deployment role's existing release-bucket write scope. No administrator password is saved in source, logs or release artifacts. AWS documents that application-version and environment-configuration updates must be separate operations; this path avoids such a reconfiguration entirely (https://docs.aws.amazon.com/elasticbeanstalk/latest/api/API_UpdateEnvironment.html).

Offline tests execute the actual shell plan and option-generation blocks: missing/completed receipt, exact/stale checksum, unrelated migration/bootstrap rejection, absent command, unchanged code-only rollout, foreign/incomplete/ambiguous stack rejection, exact one-hour permission scope, backup ordering and cleanup failure/success. Live deployment and public acceptance remain required after merge.
