# Website research, continuity review and implementation

Reviewed 23 October 2026. Website: https://www.waithakandegwa.com/.

## Direction

Retain the personal publication, its identity, existing URLs and evidence-led African perspective. Improve routes into existing content, rather than rebuilding already accepted publishing systems. Research does not establish search rankings, measured business results or a live Core Web Vitals pass. The accepted hosting baseline remains US$113.22/month before usage and tax; this release adds no infrastructure services. Optional AI calls use the existing configured provider and incur usage charges.

## Research and decisions

| Area | Primary source / benchmark | Decision implemented or retained |
| --- | --- | --- |
| Global search and AI discovery | [Google AI features](https://developers.google.com/search/docs/appearance/ai-features), [generative AI content](https://developers.google.com/search/docs/fundamentals/using-gen-ai-content) | Useful original text, crawlable internal links, accurate visible claims and metadata. No invented country pages, special AI markup, bulk generated articles or ranking guarantees. |
| Mobile indexing | [Google mobile-first indexing](https://developers.google.com/search/docs/crawling-indexing/mobile/mobile-sites-mobile-first-indexing) | Same server-rendered content and canonical URLs across devices. Responsive navigation, touch-friendly controls and no app-only primary content. |
| Content credibility | [Google Article structured data](https://developers.google.com/search/docs/appearance/structured-data/article) | Preserve author/article metadata, real publication dates, evidence and corrections; no fabricated modification dates or achievements. |
| Performance | [web.dev Core Web Vitals](https://web.dev/articles/vitals) | Aim for field p75 LCP ≤2.5s, INP ≤200ms, CLS ≤0.1. Keep responsive photographs, lazy media and lightweight JS. New pages add no front-end framework or social embed scripts. Field measurements still require real traffic. |
| Accessibility | [WCAG 2.2 target size](https://www.w3.org/WAI/WCAG22/Understanding/target-size-minimum.html) | Visible focus, keyboard editor tabs, Escape navigation, mobile 44px controls, readable inputs and responsive layouts. This is not a claim of comprehensive WCAG certification. |
| Modern publishing | [Ghost editor benchmark](https://ghost.org/help/using-the-editor/) | A coherent capture → draft → media → review → publish workflow using the existing versioned editor, avoiding duplicate CMS modules. New private Studio, quick capture, AI assistance, draft export, media search and phone instructions. |
| Mobile sharing | [MDN Web Share API](https://developer.mozilla.org/en-US/docs/Web/API/Web_Share_API) | Native sharing after a user click where supported; existing links and copy remain fallbacks. Adds Facebook sharing without asking visitors to connect accounts. |
| AI privacy | [OpenAI data controls](https://developers.openai.com/api/docs/guides/your-data) | Explicit selected-text request, CSRF/authentication, input/token/rate limits, store:false. No automatic attachment of private notes. Provider retention can still apply. No autonomous publication or claims of research verification. |
| LinkedIn | [Share on LinkedIn](https://learn.microsoft.com/en-us/linkedin/consumer/integrations/self-serve/share-on-linkedin) | Retain confirmed member OAuth and w_member_social permission gates; do not confuse a profile link with an authorised publishing connection. |
| TikTok | [Content sharing guidance](https://developers.tiktok.com/doc/content-sharing-guidelines/) | Preserve the existing reviewed capability-specific workflow. Do not represent a private own-account utility as a general Direct Post integration. Native completion remains honest fallback where required. |

The 21 October global SEO and growth research report and recovered r22 implementation were reconciled with current source before this release. Earlier recommendations already represented in source include Startup Journeys, founder screening and permission, resources, evidence/corrections, reader follow-up, newsletter lifecycle, public search, discovery reports, campaigns, owned media and provider-aware social publishing.

## Requirement continuity

| Accepted requirement | Current handling |
| --- | --- |
| Personal website and “My Thoughts”; five topics outside main menu | Preserved. Compact main navigation retained; six entry cards expose existing collections. Footer exploration split into shorter groups. |
| Social entrepreneur, innovation strategist and ecosystem builder biography | Full accepted biography retained on About and summary on Home; reusable short biography in public Media centre. |
| 1 July 2026–30 June 2030 goals | 30 startups and 2,030 permanent staff grouped under Goblis Group; 2,030 social enterprises under Ubunifu; 30,000 market connections through AfriTrace Link under Goblis Foundation. |
| Correct Goblis Group logo supplied 23 October | Exact uploaded artwork used, with visible name/tagline intact. Earlier guessed Group artwork replaced in active public view. |
| Logos with organisation context and website links opening new tabs | Three organisation sections directly after opening; AfriTrace project nested under Foundation. All four external website links remain, safely labelled for new tabs. Same component used on Home and About. |
| Progress fetched from organisation systems | Existing aggregate-only reporting clients retained. Missing feeds remain unavailable, not zero; no manufactured results. Source owners must still supply approved endpoints/tokens. |
| Sign in and post | Existing Writer login, account password change, versioned private drafts, preview, scheduling and explicit publish retained. New Studio visible in Writer navigation and dashboard. |
| Phone/tablet authoring | Studio capture saves into the same drafts. Dictation uses the device keyboard. Mobile navigation, media uploads/search, large inputs, downloads and unsaved warnings improved. Online save required; no promise of offline sync. |
| AI idea drafting | Four explicit tasks: outline, investigation questions, clarity and channel-specific social suggestions. Human review, verification markers, consent and bounded API usage. No AI key is provisioned or upgraded by this release. |
| Social integration | Existing LinkedIn/Meta/X/YouTube/TikTok/provider-specific capabilities preserved, including manual completion and approval/delivery distinctions. Public links, native sharing and private authorised publishing remain distinct. No accounts are connected merely by adding links. |
| Media centre | New public /media-centre: biography, topics, interview request guidance, Watch, editorial independence, official social links and permission-aware image requests. Existing private owned-media library remains /write/media. |
| One active Startup Journey and founder submissions | Existing weekly journey intent, founder review, permissions and evidence preserved. No fake publication schedule or stories inserted. |
| Support My Thoughts | Existing M-Pesa, Pesapal and PayPal presentation and integrations retained. No payment/account settings changed. |
| Google tag G-RN0YXC2WCH, consent, Google Workspace mail | Existing analytics privacy and canonical property retained. No duplicate tag or SES substitution. Mail/dispatch worker gates not changed. |
| AWS, Git automation, .com canonical, .co.ke redirect and DNS | Existing deployment path retained. No new hosting stack, domain duplication or nameserver change. |

## Operating workflow

1. Sign in at /write/login; open Studio at /write/studio.
2. Capture an idea and save it privately. A successful save opens the existing editor.
3. Optionally copy only suitable text into Studio AI assistance. Requests need an API key in existing Settings and explicit consent. The result is editable unverified text, not a saved publication.
4. Add sources, rights/consent and media; use the existing draft preview. Downloads contain title, summary and article body, not source notes or private planning fields.
5. Review and explicitly publish or schedule. Prepare per-channel packages in Publishing; confirm resource identities and permissions in Connections.
6. Check actual delivery receipts. Do not infer successful posts from queueing or account links.

## Remaining external dependencies

- Owner login uses the previously provisioned account; initial password remains in the existing private AWS secret, never in this repository. Change it in My account after first sign-in.
- Live AI requests depend on a valid owner-configured API key/model/billing. Configuration presence does not prove provider availability.
- Direct social publishing requires the owner's OAuth grants, correct returned account/resource, provider approvals and any paid API entitlement. Profile links alone provide none of these.
- Newsletter sending requires the previously identified Workspace relay, postal address and delivery approval. Existing disabled worker gates stay disabled until readiness is verified.
- Reporting needs approved aggregate feeds from Goblis Group, Ubunifu, AfriTrace Link and corroborating Foundation reporting.
- No automatic cross-post, newsletter campaign or external message was sent by this implementation.

## Validation

Added pure tests for malformed/oversized AI input, safe idea escaping, restricted tasks and evidence/review instructions. Extended the isolated HTTP suite to exercise authentication, no-store/no-analytics Studio, capture CSRF, private draft creation, absent AI configuration, consent, public Media centre and sitemap. Existing goal-link tests now check the three organisational sections and all four destinations. CI tests PHP 8.3 and 8.4 against disposable MySQL 8.4 before the existing production deployment. Live and visual results are recorded separately after deployment; responsive CSS alone is not proof of device testing.
