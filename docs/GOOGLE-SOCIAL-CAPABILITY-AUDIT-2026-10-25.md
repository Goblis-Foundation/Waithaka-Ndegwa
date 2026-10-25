# Google and social capability audit — 25 October 2026

Observed in the authenticated production connection screens. “Connected” is a stored authorisation, not evidence of every endpoint working today. No real post, message or account-conversion test was performed.

## Google already connected
Search Console (selected domain), GA4 (website property), YouTube read/upload/analytics, selected-file Drive, Gmail personal inbox read, website booking calendar and eight separate Google calendar identities. Calendar destination is isaac@goblisgroup.com; Gmail inbox read is iwaithakaw@gmail.com. Existing integrations include selected calendars, owner-approved booking/Meet creation, scoped date/time/cancellation changes, charts by country/device/channel/page/campaign, sitemap/index inspections, Lighthouse/CrUX configuration, selected Google Doc import, Gemini model discovery and selected-note drafting, and a local aggregate-report MCP adapter. New footer measures GA4 recent activity and recorded visits without a new tracker.

Not connected: Postmaster Tools, Gmail private drafts, and each matching host sending mailbox. Those require distinct authorised grants; calendar access does not imply email-sending rights. Booking sender callback is /write/connections/callback/google_booking_mail in the existing Google Cloud client. AfriTrace calendar remains deferred/excluded from required booking conflict checks as requested.

## Further Google capabilities and implementation boundaries
| Capability | Useful next implementation | Gate / boundary |
|---|---|---|
| Calendar change notifications | Push-triggered refresh with incremental sync tokens, channel renewal and fallback polling | Not implemented; validate channels and renewal; never treat a push as event contents |
| Calendar travel-aware availability | Separate travel buffers from meeting duration; map route estimates for project visits | Search/coordinates and manual buffers exist; Routes/Places APIs need billing/key restrictions and explicit location handling |
| Meet records and transcripts | Owner-selected attendance/transcript import into private notes, then reviewed reports | Not implemented; eligibility/licensing and new Meet/Drive scopes; do not silently record or transcribe participants |
| Gmail drafts | Private branded drafts in the actual chosen mailbox | Adapter present; compose grant not connected. Google grants sending capability as part of compose even when this app only drafts |
| Matching-mailbox sending | Confirm each real account and issue its booking link | Adapter live; no sending mailbox authorised yet |
| Postmaster | Reputation, spam rate/authentication and delivery trends | Adapter present; consent/domain verification and sufficient volume required |
| Drive/Docs | Export a reviewed report into a user-selected folder/document | Current integration imports plain text only; new write flow/version protection needed; never broaden to whole Drive automatically |
| Sheets | Approved aggregate exports and internal progress reports | Not implemented; selected-file permission, explicit export and clear measurement/publication dates |
| Tasks | Explicitly approved note actions linked to daily planner | Not implemented; separate Tasks scope, deduplication and two-way ownership/conflict rules |
| GA4 | Key-event funnels (booking request/approved booking, subscription confirmed), annotations and dashboard comparisons | Current reports/consented events exist; do not count unverified payments or leak form values; server-side events require separate design |
| BigQuery/Looker Studio | Long-term aggregate analysis and optional private dashboards | Not implemented; billing/project/dataset controls and no public raw-data sharing |
| Search Console | Quota-aware scheduled inspections/content-decay/CTR reports | Current read tools exist; structured scheduled analysis can follow; no promise to index every country |
| Indexing API | Notify eligible approved job detail pages of publication/removal | Not general article indexing. Only eligible JobPosting or BroadcastEvent-in-VideoObject use cases |
| YouTube | Captions, thumbnails, playlists and comments moderation workflows | Existing read/upload/analytics does not implement every write operation. New exact-media approvals and appropriate grants are needed |
| Gemini | Better queued drafting with retries/time budgets, grounded citations and cost controls | Text provider had a timeout previously; voice UI exists but real speech transcription not proved. OpenAI PDF preview did succeed |
| Security | Cloud budgets/quota monitoring, restricted API keys, OAuth publishing verification and expiry alerts | Preserve current grants; do not silently enable paid APIs or widen access |

“Explore all Google capabilities” is scoped to this platform’s publishing, scheduling, communication, enterprise reporting and audience needs; it does not justify enabling unrelated advertising, payments, identity administration or broad organisation access.

## Social accounts actually observed
| Platform | Production status | Current implementation / remaining gate |
|---|---|---|
| Instagram @iwaithakaw | Publishing, Insights, comments and messages all connected | Direct adapter currently single JPEG plus approved location/user tags; engagement reads explicit/on demand. Reels/carousels/Stories, reply/moderation writes are further work. Stories eligibility depends on actual account type. No automatic DMs |
| YouTube | Read, upload and analytics connected; direct route selected | Resumable upload/processing and approved metadata; captions/playlists/comment writes remain separate |
| Facebook professional personal profile | Not connected; standard-login app credentials missing | Keep personal profile. No substitute Page. Identity login is separate from posting; native Professional Dashboard/assisted publishing remain the supported route |
| LinkedIn personal member | Member login/publishing and analytics not connected; credentials/products missing | OIDC + Share on LinkedIn; member analytics needs approved product and r_member_postAnalytics. Do not substitute company Page |
| X professional account | Not connected; app credentials missing | OAuth PKCE and provider credits/spending controls. Do not purchase credits or claim unlimited analytics |
| TikTok personal creator | Read and native publishing not connected; credentials/approval missing | Owner’s personal creator account must remain personal. Investigate standard creator Content Posting API; existing Accounts API route is a separate approved product and must not require an organisation-account conversion. Public Direct Post requires provider audit; unapproved clients restricted |
| Buffer | Not connected; own API key/channel verification required | Optional delivery intermediary, not a bypass for platform rights, account types, plan limits or provider review |

The production screens currently do not show the Facebook/LinkedIn/X/TikTok credentials as usable. Earlier sign-ins or developer-portal setup do not establish that encrypted application credentials and correct products are saved in this website. Connecting these accounts is blocked on those concrete prerequisites and fresh account consent; no new connection is claimed.

## Official research
- GA4 realtime/schema: https://developers.google.com/analytics/devguides/reporting/data/v1/realtime-basics and https://developers.google.com/analytics/devguides/reporting/data/v1/realtime-api-schema
- Calendar notifications: https://developers.google.com/workspace/calendar/api/guides/push
- Meet artifacts: https://developers.google.com/workspace/meet/api/guides/artifacts
- Maps routes: https://developers.google.com/maps/documentation/routes/compute_route_directions
- Indexing boundaries: https://developers.google.com/search/apis/indexing-api/v3/quickstart
- LinkedIn member analytics: https://learn.microsoft.com/en-us/linkedin/marketing/community-management/members/post-statistics
- TikTok Direct Post: https://developers.tiktok.com/docs/en/content-posting-api-get-started and https://developers.tiktok.com/docs/en/content-sharing-guidelines
- Meta official API collection: https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api
- X official developer platform: https://developer.x.com/

No new grants, social publication, external communication, API-credit purchase or billing changes were made during the audit.
