# Personal Meta integration status — 23 October 2026

These connections belong to Waithaka Ndegwa's personal site, not a Goblis or Afrisol social account. A Meta developer-app permission marked Ready for testing is not an account-holder grant or production review approval.

| Capability | Implementation | Activation / limit |
| --- | --- | --- |
| Instagram publishing | Connected @iwaithakaw; approved single JPEG feed delivery | Reels, carousels and Stories still use assisted/provider routes |
| Instagram Insights | Separate grant, account confirmation, daily reach graph and source-labelled report history | Live account grant and first report verified; do not sum daily reach into weekly unique people |
| Instagram comments | Separate grant and private read desk for comments on recent owned media | Account grant and owned recent-media list read verified; replies and moderation remain in Instagram |
| Instagram messaging | Separate grant and private read desk for selected recent conversations | Account grant and conversation-list read verified (empty API list); no automated replies or message-body storage; provider eligibility can limit results |
| Facebook professional profile | Assisted publishing and source-labelled native report entry | Current Business Login app rejects public-profile-only authorization. Dedicated standard personal Login settings now use a separate encrypted credential namespace, with no fallback to the Page app. A supported personal Login app and account authorisation are still required. Page credentials do not authorize this profile |
| Facebook Pages | Optional separate legacy integration | Not the user's requested posting destination |
| Local analytics MCP | Read-only stdio bridge for latest saved Facebook/Instagram aggregate reports | Disabled by default; no remote endpoint, secrets, identities, inbox, publication or provider refresh |
| Meta Ads MCP / Marketing API | App use cases prepared | Site adapter and ad-account grants still pending; no ad budget, campaign or delivery changes made |
| Threads | App use case prepared | OAuth, publishing and reporting adapters still pending |
| Instagram webhooks | Not configured | Requires published app in Meta; receiver/subscriptions still pending |
| Live Video, oEmbed and additional Meta products | App use cases prepared where available | Implementation, eligibility and review remain separate work |

## Engagement privacy

The private desk performs no API calls on GET. Each POST requires an authenticated Writer session, CSRF verification and a shared 12-per-hour read limit. It verifies the provider account, fetches a bounded fresh list and allows details only for an item from that list. Paging URLs are never followed or exposed. Text is escaped. No reply/send/delete/moderation endpoints exist in this release. No conversation/comment bodies are saved or sent to AI.

## Local aggregate MCP

`bin/meta-mcp.php` implements newline-delimited stdio JSON-RPC. It supports protocol revisions 2026-07-28, 2025-11-25 and 2025-06-18. It exposes one tool, `meta_saved_analytics`, with a platform enum of `facebook` or `instagram`. Only allowlisted numerical metrics, period, source category and observation time are returned. No account identities, source-reference free text, credentials or engagement data are exposed. No provider calls or mutation tools exist.

Deployment does not enable it. A trusted operator can launch it under an approved local MCP client with `WAITHAKA_META_MCP=1 php /absolute/release/path/bin/meta-mcp.php`, using the site's read-only database configuration and decryption key. Restrict the process account and file permissions. Do not expose it through HTTP, a shell-execution tool, or a publicly reachable proxy. Disable by terminating the client process and removing its configuration. This bridge is distinct from Meta's hosted Ads MCP service and does not grant advertising access.

## Primary references

- https://developers.facebook.com/documentation/instagram-platform/insights
- https://developers.facebook.com/documentation/instagram-platform/instagram-graph-api/reference/ig-media/comments
- https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-instagram-login/conversations-api
- https://developers.facebook.com/documentation/business-messaging/instagram-messaging/get-started
- https://developers.facebook.com/blog/post/2026/07/16/meta-ads-mcp-server/
- https://modelcontextprotocol.io/specification/2025-11-25/basic/transports
- https://modelcontextprotocol.io/specification/2025-11-25/server/tools

Meta references were checked on 23 October 2026. Search returned the official permission and endpoint references; direct document retrieval was throttled. Live account grants, exact endpoints and UI results must still be verified before marking a capability active.
