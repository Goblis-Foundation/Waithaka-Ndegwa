# Waithaka Ndegwa · Icon, SEO and communications cycle release
**Decision date:** 28 October 2026.

## No-drop implementation status

### Actual approved visual identity
The approved blue (#073B6A), green (#2F6F3E) and white WN vector from `assets/images/wn-approved-monogram.svg` is the **sole** icon source. `app/brand_assets.php` re-exports the original outline paths in their source colors at app (192/512px), touch (180px), favicon (16/32/48px, ICO and SVG) and maskable safe area sizes. The original wordmark asset hash is protected. No portrait, old person silhouette, tiny tagline or changed font is substituted.

The public PWA manifest now opens the public home page. A distinct `writer.webmanifest` opens the private Writer Communications Centre with scoped shortcuts. Both use the same brand icon but different app names, ids and launch contexts. Outputs are generated **inside the immutable production ZIP**, not uploaded as unreviewed PNG to the Git repository; old preview files in the source tree are not the production brand compiler outputs. Versioned icon links bust stale caches. Website site name/metadata and existing analytics consent remain unchanged.

### SEO and interactive content cards
Public cards keep semantic h2/h3 and real crawlable anchors in server-rendered HTML; responsive image crop, keyboard focus, original metadata, non-script fallback and reduced-motion support are preserved. Hover feedback improves discoverability on pointer devices without converting content into inaccessible button-only cards. Do not invent statistics, author attribution, rich-result eligibility or images.

### Communications Centre — one source-governed marketing calendar
The private `/write/communication?tab=content` adds a 14-day Kenya-time planning view to the existing Inbox/Bookings/People/Insights system, linking current newsletter, publishing, campaigns, source impact and Google analytics functions instead of adding an independent marketing/customer database.

A worker prepares **one daily organizational source brief**, a **weekly original-article research slot**, and **one weekly newsletter slot**, with editable cadence. It never invents current organizational results: only a genuinely approved schema-v2 `impact_snapshot`, exact allowed metric and measurement period may seed proposed source copy. Missing, withdrawn or overdue reports produce a visible `Source evidence required` state with no fabricated caption. Goblis Foundation corroborating market-access values are not summed with AfriTrace's primary measurement.

On the chosen weekly email day, when a verified published article exists from the current week and the owner is configured, an idempotent protected worker may **create a private newsletter campaign draft** using existing `newsletter_save_campaign`. It does not approve, schedule, select unsubscribed recipients, send or create fictitious article text. Exact approved revision, compliant address, consent and provider delivery evidence remain obligations of `newsletter.php`. Existing monthly roundups and confirmed subscriptions remain untouched.

Social channels continue under the existing `publishing_weekly_work` mechanism: platform-appropriate copy, signed-off revision, confirmed destination, permitted API and immutable receipt. Personal Facebook professional profile remains assisted. TikTok creator direct posting requires approved Content Posting API and the user's `video.publish` consent/audit; a Business API login is insufficient. X paid API and provider entitlements are not guessed. No posting budget, arbitrary AI generation, provider message or brand impersonation is enabled by the content calendar.

### Mailchimp capability benchmark
- Mailchimp marketing calendar unites emails and social scheduling: https://mailchimp.com/help/about-marketing-calendar/
- Automated workflows support triggers, delays, branching, email and webhooks: https://mailchimp.com/help/create-customer-journey/
- Native scheduling supports approved Facebook Pages, Instagram business profiles and X, **not every personal account**: https://mailchimp.com/features/social-media/
- Google recommends site hierarchy and valid breadcrumbs that represent actual content paths: https://developers.google.com/search/docs/appearance/structured-data/breadcrumb

## Verified versus pending
The automated *preparation* calendar and week-specific safe newsletter drafts are code pathways; a non-authenticated smoke test cannot certify a real saved draft, an active production heartbeat, a posted social update or an inbox delivery. Complete rollout acceptance requires runtime evidence in the authenticated Writer dashboard. Owner-approved source metrics must first exist. No new OAuth grants, paid provider access, postal address, payment settlement, social publication or enrolment is assumed.

### Outstanding requirements preserved
- Waithaka [#70](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/70): exact personal Meta/WhatsApp identity and delivery.
- [#71](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/71): personal LinkedIn/X/TikTok permission and posting products.
- [#72](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/72): verified commercial settlement and voluntary recurring giving.
- [#73](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/73): postal-compliant email sender and real delivery.
- [#74](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/74): source-approved Goblis Group, Ubunifu Institute, AfriTrace Link, Goblis Foundation data.
- [#75](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/75): scoped Google/Microsoft capabilities and live read-back.
- [#76](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/76): physical-device, assistive-technology and live performance acceptance.

No claim of complete, hour-by-hour recovery of inaccessible source conversations. Dated code, prior decisions, release evidence and open gates remain separately accessible through `/write/reconciliation`.
