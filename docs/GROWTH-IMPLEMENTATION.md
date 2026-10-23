# Global discovery, reader journeys and Google tag

> **Current status — 23 October 2026:** See [weekly requirements audit](REQUIREMENTS-AUDIT-2026-10-23.md) for deployed features, verified connections and open blockers. Release-specific statements below are historical. Production is deployed on a fresh AWS installation; old-host recovery is superseded. Google Workspace is the selected mail path; sending and delivery work remain paused.


Release r4 continues the recovered personal website. It adds the owner's requested **G-RN0YXC2WCH** measurement destination and implements the practical growth features from the SEO review. The release package contains working application code, migrations, tests and operating instructions. It does not establish a live AWS deployment or a connected Google/social account.

## 1. Google tag installation

`views/layout.php` includes `views/partials_google_tag.php` immediately after the opening `<head>`. Every measured public page uses this shared template. The partial contains one asynchronous Google library loader and one configuration command for the chosen measurement ID. The old JavaScript loader has been removed from `assets/js/site.js`, so the page cannot load a second tag through that file.

Additive migration `100_analytics.sql` installs `G-RN0YXC2WCH` on both recovered and fresh databases and enables the public-page setting once. Subsequent Writer changes remain intact because completed migrations do not run again. **Writer → Settings → Google** provides a separate enable switch; a deliberately blank or invalid measurement ID does not fall back to an old property.

The consent defaults are established before the library loads. The library loads on measured public pages, while the documented `ga-disable-<measurement-id>` switch prevents Analytics measurement and Analytics cookies until the reader permits analytics. Advertising storage, advertising user data, Google signals and ad personalisation remain disabled. A consent change updates the tag on the same page. Declining analytics also removes the site's Analytics cookies. A returning consent choice is read from browser-local storage.

Automatic page-view sending is disabled in the configuration. The application queues one explicit page view when analytics is allowed, and repeated clicks on the consent button do not queue another view. A consent withdrawal immediately turns measurement off. The implementation tests execute the real PHP-rendered bootstrap in an offline JavaScript runtime; they do not impersonate or contact Google.

Writer, installation, private consent links, newsletter signup/action/preference forms and links, founder submissions/follow-up, search, contact and contribution/payment forms are excluded. These pages contain private capabilities or user-entered information. Ordinary public articles, collections, resources, video pages and information pages share the tag.

Measurement URLs retain the canonical path, legitimate archive page number and a small, validated set of campaign identifiers. Arbitrary queries, search phrases, email addresses, private tokens and fragments are discarded. Referrer reporting uses only the referring origin, not its path or query. The event handler accepts a fixed set of event names and public content identifiers; it never reads form fields.

### What to verify after deployment

1. In Google Analytics, confirm that the web data stream for the intended property has measurement ID **G-RN0YXC2WCH** and the canonical website address.
2. Use Google's Tag Assistant on the home page and an actual article. Confirm one destination and, after choosing Allow analytics, one application page view for the page load.
3. Repeat with Necessary only, returning consent, and a private newsletter link. The private link must have no Google tag.
4. Review the data stream's Enhanced Measurement settings. Google can independently measure browser-history changes when that option is enabled, even with `send_page_view:false`. This site uses ordinary server-rendered navigation; disable that history option if additional scripts introduce history changes. Do not enable collection that captures private form details.
5. Check Realtime and then the normal reports. A successful local test or an HTML tag does not prove that the live property has received a hit. Browser blockers, consent and the actual stream configuration affect collection.
6. Authorise the separate Google Analytics read connection and select the actual numeric GA4 property in Writer if reports should appear there. A measurement ID installs collection; it is not an OAuth grant or a numeric property ID.

Primary documentation: [Google tag installation](https://developers.google.com/tag-platform/gtagjs), [consent defaults and updates](https://developers.google.com/tag-platform/security/guides/consent), [disable and privacy settings](https://developers.google.com/tag-platform/security/guides/privacy), [manual page views and Enhanced Measurement](https://developers.google.com/analytics/devguides/collection/ga4/views).

## 2. Public reading and learning experience

The compact main navigation is Home, My Thoughts, Start Here, About and Contact. Startup Journeys, Founder Stories, Resources, Watch, weekly updates and RSS remain available through the home page, Start Here and footer. This gives a first-time reader a clear route through the site while keeping the original writing and article URLs.

**Start Here** introduces the main reading paths: follow a venture from its first question, test an idea using the resources, explore the topics, or read another founder's reviewed story. No startup outcome or biography has been invented to fill an empty collection.

**Resources** contains four original blank tools: a problem interview worksheet, an experiment and decision sheet, a first customer checklist, and a pilot cost worksheet. Readers can read them online and download editable text without an account. Writer can create additional resources from these starting points. A resource's private working copy is separate from its explicit public release.

**Article reading** gains a heading-based table of contents for suitable long articles, a newsletter invitation, explicitly public evidence cards, and meaningful update/correction notes. Public evidence is authored separately from private source notes. Ordinary draft saves do not masquerade as meaningful publication updates. The About page no longer emits a new modification date on every request.

**Experiment records** belong to the existing startup journey. They can describe the question, hypothesis, method, findings, decision, limitations and next step. The author determines what is ready to publish. A private or unavailable parent journey does not expose its experiment records.

**Watch** is a collection for actual video-first pages with an owned public video, thumbnail, transcript and publication details. A valid released video gets `VideoObject` data. Adding a video to an ordinary article does not silently convert it into a video landing page. The collection is honest when no video is published.

Public content is visible by default without JavaScript. Scroll animation is an optional enhancement for off-screen elements, with a short visibility fallback. Existing public photographs have actual 480-pixel and 960-pixel WebP derivatives where smaller dimensions are useful. Templates emit dimensions and `srcset` only for files that really exist. Missing local images have a safe fallback; remote images retain their provided URL without server-side fetching.

See [Editorial tools](EDITORIAL.md) for authorship, permission and publication details.

## 3. Campaigns and coordinated posting

**Writer → Campaigns** links a source article to:

- its intended reader action and a saved success measure;
- private planning notes, protected from stale concurrent edits;
- separate Facebook, Instagram, X, TikTok, LinkedIn, YouTube and newsletter links;
- source-linked social packages and their actual delivery states;
- source-linked newsletter editions and scheduled audience counts.

Campaign links use stable content identifiers such as `post-42`. They do not encode an email address or subscriber ID. A draft, future article or withdrawn article is not offered as a public campaign destination. New social drafts receive channel-specific links automatically; existing approved revisions are unchanged. Newsletter prefill is a draft convenience and does not save, approve or send an edition.

The website remains the complete weekly account. Each platform can use its own text, photograph or video hook. The publishing centre retains the existing saved-review, explicit approval, destination verification and scheduling process. Professional account labels are preserved for Facebook, Instagram, X and TikTok; available publishing methods still depend on the provider returning an eligible authorised account. A Facebook professional profile does not become a Page through this release.

The browser records consent-dependent editorial actions such as opening the newsletter invitation, continuing to a chapter and initiating a resource download. These are action counts, not proof of completed subscription or reading. Confirmed subscription and delivery records in the application remain the authoritative operational counts.

## 4. Reader relationships

The newsletter now supports following selected topics and startup journeys. A reader changes preferences through a private, expiring link bound to the original mailbox and current consent record. Preference changes do not undo an unsubscribe or suppression. The campaign audience is still frozen at scheduling and checked again before sending; a later narrower preference can prevent a queued send without silently adding new recipients to that edition.

The **Reader desk** records enquiries by type, reference, status, next action and response history. The contact record and work item are committed before email notification is attempted, so a mail outage cannot erase a received enquiry. Only a name, email and message are required; other contact/location details are optional. Sending an enquiry does not subscribe the person to marketing.

Founder submissions gain a receipt and protected clarification/permission follow-up. A received submission, a reply, editorial review and a published founder story remain separate states. Neither a receipt nor a permission response automatically publishes a story. Historical records are available for review without retrospectively sending messages.

See [Reader workflows](READER-WORKFLOWS.md) for expiry, mailbox binding, suppression, approval and response handling.

## 5. Search, reporting and indexing

Site search now covers eligible articles, approved founder stories, due public startup journeys, resources and video pages. It has real result counts, content-type filters and server-rendered pagination. Private working copies, source notes and future/withdrawn content are not searchable. Search-result pages are excluded from Analytics and search-engine indexing.

**Writer → Discovery** adds a Google Search Console report with query, page, country and device views for current and previous 28-day or 90-day periods. It uses the existing confirmed property and documented read-only API, saves the actual report result and records its period and limitations. It does not fabricate data for disconnected accounts or pretend that all Console reports have an API. Links to the Google/Bing consoles cover reports that need the native interface.

An operations attention summary brings together failed/uncertain publishing outcomes, email issues, connection review, stale/missing Search Console reports, reader follow-up and search notification status.

IndexNow uses a local inventory of the URLs that are actually public. The worker detects new, changed, scheduled and removed public content, keeps revision-bound notification records and records attempts separately from accepted responses. Removed public URLs can be reported as tombstones; private draft URLs are never used as new submission candidates. Provider acceptance does not guarantee indexing or ranking.

Sending requires all of: production mode, the existing worker enablement, `INDEXNOW_ENABLED=1`, and the Writer IndexNow opt-in with its generated key. The release and Beanstalk defaults leave these external notifications disabled. The Writer reconciliation action updates local inventory only. This is an IndexNow integration; it does not misuse Google's restricted Indexing API for ordinary articles.

See [Discovery](DISCOVERY.md) for report provenance, notification receipts, retries and image behaviour.

## 6. Upgrade and AWS release

Preserve the live database, uploads, private media and the exact original application encryption key. The package is application code, not a replacement for the live records. Apply the migration runner against the restored staging database before switching application traffic. New additive migrations are 100 (Analytics), 110 (editorial tools), 120 (reader workflows), 130 (discovery) and 140 (campaign plans). Previously applied migration files are unchanged.

Use the AWS migration runbook and guarded cutover helper included in this release. Keep mail, publishing and IndexNow paused while staging is verified. The canonical address is `https://www.waithakandegwa.com`; the .co.ke and apex aliases retain their corresponding path and query through the edge redirect. The HTML/default edge behaviour is uncached, so new private routes are covered without adding an unsafe positive minimum TTL. Public assets retain the dedicated cache behaviour.

The implementation session had no confirmed AWS account/region for this personal site, no AWS CLI credentials and an AWS console displaying Site Unavailable. No account was inferred from another project, no DNS record was changed and no live provider action was performed. Deployment therefore remains a concrete infrastructure access step after the release is available.

The live acceptance pass should cover the canonical/alias HTTPS redirects, an existing article, a new public resource, valid sitemap/RSS XML, the new Google tag, private-link headers, Writer login, restored content/media, a controlled email confirmation, Google OAuth property selection and one deliberately approved delivery per enabled social route. Actual live Google reception, provider authorisation and delivery must be verified in their own interfaces.
