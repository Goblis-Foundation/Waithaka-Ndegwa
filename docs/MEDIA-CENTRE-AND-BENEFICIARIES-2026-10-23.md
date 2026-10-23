# Dashboard media centre and beneficiary reporting correction

23 October 2026. This record supersedes the public-media-centre interpretation in the earlier website review. The requested media centre belongs inside Writer.

## Accepted corrections

- Ubunifu: 2,030 enterprises × an average of five permanent staff = **10,150 permanent staff**. These jobs are generated indirectly through Ubunifu’s incubation, within the enterprises that employ the staff. They are not Ubunifu Institute payroll positions. This is a target assumption. Actual staff must come from the source reporting system and are not calculated from enterprise enrolments or enterprise counts.
- Organisation names and logos remain website links; raw domain labels and visible new-tab messages are removed. External links retain safe rel attributes and existing new-tab behaviour.
- Goblis Group retains both startup and employment targets; Ubunifu now has enterprise and employment targets; AfriTrace Link remains a project inside the Goblis Foundation section.
- Media centre is `/write/media-centre`, a protected publishing and analytics dashboard. The previously added public `/media-centre` redirects to Contact and is removed from public navigation/sitemap. Existing private posting/editor/media/connection workflows remain authoritative.

## Indirect beneficiaries: research and counting decision

[International Potato Center research, published through FCDO](https://www.gov.uk/research-for-development-outputs/tracking-indirect-beneficiaries-of-complex-development-interventions-in-agriculture) distinguishes direct project reach from benefits diffusing through household and social networks. This is a useful framework, not a universal numerical multiplier.

[KNBS census release](https://www.knbs.or.ke/2019-kenya-population-and-housing-census-results/) reports a 2019 Kenya household average of 3.9, while its more detailed [Household and Family Dynamics analytical report](https://www.knbs.or.ke/wp-content/uploads/2024/05/KPHC-Census-Analytical-Report-on-Household-and-Family-Dynamics-Vol.XI_.pdf) is indexed with a 3.8 conventional-household average. The full analytical PDF could not be retrieved in this session. These differently scoped historical statistics are not forecasts, do not describe every African country and do not show that every co-resident benefits. Neither is used to manufacture indirect totals.

| Organisation | Potential indirect beneficiaries to investigate | Evidence required before publishing a count |
| --- | --- | --- |
| Goblis Group | Employee household members benefiting from earnings; people benefiting through supplier/service livelihoods | Household and livelihood evidence, direct-staff exclusions, deduplicated people and source-approved methodology |
| Ubunifu Institute | Founder/employee household members; community members indirectly benefiting from social/environmental work | Enterprise follow-up and outcome evidence, separate direct service recipients, deduplicated survey or documented sampling |
| Goblis Foundation / AfriTrace Link | Producer/worker household members and local supplier/logistics networks benefiting from improved market access | Evidence beyond profile registration or buyer introductions; traceable livelihood pathways, defined geographic coverage and no double counting |

Each public organisation section now describes these groups and shows “Count awaiting verified reporting” until evidence is available. It does not label all suppliers, customers or community residents as beneficiaries. No grand total combines enterprises, employees and indirect people.

The source contract remains compatible with existing schema version 1 feeds. Ubunifu can optionally send `metrics.ubunifu_permanent_staff`. Every source may optionally send `metrics.indirect_beneficiaries`, but must also supply a public `beneficiary_basis` and `beneficiary_deduplicated: true`. Counts are nonnegative integer aggregates, direct people must be excluded, and raw individual records remain prohibited. Foundation is the public source for its programme-wide indirect count; AfriTrace's market-access count stays primary for enterprise connections.

## Private dashboard implementation

- One Media centre entry exposes Create social post, calendar/deliveries, images/video assets, account integrations, Studio/AI, six account analytics cards and website referral reports.
- Posting uses the existing per-channel draft, approval, schedule and confirmed-delivery workflow. This release does not send social posts, enable paused delivery workers or authorise external accounts.
- Reports are fetched only after an authenticated, CSRF-protected refresh request, with an hourly limit. No provider calls occur merely by opening the dashboard.
- Provider/account/period/source labels remain visible. Last 20 snapshots per platform are stored encrypted in existing settings, using a per-platform write lock. Provider failures preserve saved history. There is no new infrastructure subscription.
- All six platforms also allow owner-recorded native analytics, with required account identity, date range and report/export source. Missing fields remain unavailable, while an actual reported zero is kept. Native entries are explicitly distinguished from API data.
- Counts from different platforms and periods are not summed as unique reach or beneficiaries. No false comparison of lifetime counters with period totals.

## Provider capabilities and limits

| Platform | Implemented report route | Dependency / limitation |
| --- | --- | --- |
| LinkedIn member | Separate analytics OAuth service and 28 completed UTC days of member aggregate impressions and members reached | Approved member analytics product, `r_member_postAnalytics`, owner authorisation and confirmed member; posting grant alone is insufficient |
| X | Confirmed user's follower/following/post totals | OAuth read scope, account identity match and paid API entitlement where applicable; these are cumulative counters |
| Instagram | Confirmed professional-account follower and media totals | Existing authorised professional account; native period Insights may be recorded separately |
| Facebook | Confirmed Page follower snapshot | Page access, not professional-profile insights. Native professional-profile analytics use the labelled report form |
| YouTube | Confirmed channel views, videos and non-hidden subscriber counts | Existing YouTube read/upload connection; Data API totals are not a period-specific YouTube Analytics report |
| TikTok | Existing approved read adapter's profile totals | Display API permissions and confirmed account; separate native publishing permission remains unchanged |

Primary technical sources: [LinkedIn Member Post Statistics](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/members/post-statistics?view=li-lms-2026-10), [X metrics](https://docs.x.com/x-api/fundamentals/metrics), [X fields](https://docs.x.com/x-api/fundamentals/fields), [Meta's official Instagram API workspace](https://www.postman.com/meta/instagram/overview), plus the existing official Google/TikTok adapter documentation in source. Direct Meta documentation requests were rate-limited; do not represent all private native metrics as verified API coverage. LinkedIn uses version 202610, total aggregation and an exclusive API end date. The additional grant does not alter or silently broaden existing publishing grants.

## Validation scope

Tests cover target arithmetic, backwards-compatible impact feeds, indirect-count methodology/deduplication requirements, malformed dates/counts, zero-versus-missing semantics, platform binding, API identity mismatch, private HTTP routes, CSRF, all six dashboard cards, native report persistence, failed-refresh retention and separation from public content. CI validates PHP 8.3/8.4 with isolated MySQL 8.4 before the existing AWS deployment. Live account metrics still require owner-authorised accounts and cannot be validated using synthetic fixture reports.

## Simple connection entry (follow-up)

The Media centre now has six prominent account cards with a single primary Connect action. Buttons enter the existing authenticated CSRF-protected OAuth start route directly. Facebook Page discovery no longer requires changing the saved professional-profile route first; discovery does not change account type or delivery settings. Returned account/destination confirmation remains required. LinkedIn analytics and TikTok native publishing remain separate, expandable permission choices rather than silently broadening a basic grant. Missing provider app credentials still block OAuth and are explained by the connection setup screen; a button is never proof that an account is authorised.

## AfriTrace geographic scope and approved logo

The owner confirmed planned AfriTrace Link coverage across all 55 African countries. The 30,000-enterprise target applies across that footprint collectively, not per country. Public copy describes planned coverage, not verified current operations in every country. The supplied horizontal white-background logo replaces the previous standalone symbol in the Goblis Foundation project card. Beneficiary evidence must reflect countries actually reached; no Kenya household proxy is generalised across the footprint.

## Professional dashboard organisation

The shared Writer shell now groups every existing module under Workspace, Writing, Media & Social, Audience, and Impact & Settings. A persistent desktop sidebar replaces the crowded horizontal link strip; the same navigation uses a keyboard-accessible menu on phones and tablets. The Overview preserves the existing searchable article library and live status filters, adds the most recent private draft, six useful workflow cards and database-derived counts for confirmed social platform access, scheduled jobs and tasks needing attention. It makes no external API calls while loading. Connect buttons, approval gates, source reports and account settings remain intact. Private pages remain no-store/noindex and omit visitor analytics.
