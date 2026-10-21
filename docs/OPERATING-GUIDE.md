# Operating guide

## 1. The editorial promise

The project follows one startup from its starting conditions through real decisions, experiments, systems and outcomes. The website holds the complete, durable record. Social posts introduce a useful part of each chapter and link readers to the evidence.

Use one season per venture. The application permits only one **published** journey in Planning, Building or Operating at a time. You can prepare private drafts for later ventures. Pause or conclude the current public journey before activating the next.

No venture has been selected or invented for this release. Create the first journey from the actual startup you choose, with its real starting date, resources, constraints and learning question.

## 2. Create the first journey

1. Sign in to **Writer → Startup Journeys → New journey**.
2. Enter the title, venture name, starting date, phase, short public summary and starting baseline. Mark estimates, assumptions and facts clearly.
3. Keep publication set to Draft while gathering material. The private notes field is for research and operational details that should not appear publicly.
4. Write the first article through **New thought**. The Plan tab holds the question, private research notes and permission checks.
5. Save, preview and explicitly publish the article, or give it a future publication time. Autosave does not publish changes.
6. Open the journey and add a chapter link to that article. Use the recent-post suggestions or the numeric post ID shown in its Writer edit address.
7. Give it a chapter number, public summary, evidence basis and evidence note. Private evidence notes remain in Writer.
8. Publish the journey and enable the chapter link when ready. A chapter is visible only when its journey, chapter link and linked article are all public and the article’s date is due.

The journey URL stays fixed after first publication. Article slugs and original publication dates remain stable when you revise an article. Removing a chapter link leaves the article in the writing library.

### Suggested first season

This is an editorial starting plan, not a claim that a startup can complete every stage in twelve weeks. Stay longer at a stage when the evidence requires it.

| Week | Reader’s question | Evidence or practical output |
| --- | --- | --- |
| 0 | What is the venture, and what are we starting with? | Baseline, initial resources, constraints, known unknowns and disclosure of your role |
| 1 | Whose problem are we solving? | Problem statement and an interview plan |
| 2 | Does the problem appear outside our own assumptions? | Anonymized interview findings, sample limits and counterexamples |
| 3 | Why might this solution work? | Testable hypotheses and a small experiment design |
| 4 | What is the smallest useful offer? | Prototype or manual service with clear acceptance criteria |
| 5 | Will anyone commit money, time or attention? | Real pilot evidence, including refusals and reasons |
| 6 | Do the numbers make sense? | Dated unit-cost assumptions, actual expenditure and scenario comparison |
| 7 | How will delivery work repeatedly? | Process map, owner for each task and quality checks |
| 8 | How will we find and retain customers? | One tested acquisition route and a defined measurement window |
| 9 | What systems are necessary now? | Working records, follow-up process, invoicing and support arrangements |
| 10 | What broke, and what did we change? | Failure analysis and evidence from the revised process |
| 11 | Should this venture continue, change or stop? | Results against the original decision criteria |
| 12 | What should another founder take away? | Outcome, reusable template and explicit next decision |

Keep legal, tax, licensing and regulated activity discussions tied to the actual venture and jurisdiction. Obtain relevant professional input when the venture requires it; this website does not supply automatic approval to operate a business.

### Repeatable chapter structure

Use a short opening result, the question being tested, what happened, the evidence and its limits, what you changed, and the next experiment. Include a dated metrics table only when the measurements exist. Distinguish revenue from receipts, spending from estimates, and customer statements from independently verified outcomes.

## 3. A workable weekly rhythm

Use a predictable publication day in **Africa/Nairobi** time. The suggested rhythm is:

- Early week: conduct the experiment, gather evidence and obtain permissions.
- Midweek: draft the complete website chapter and select one useful lesson for social distribution.
- Before release: review claims, quotations, captions, public media files, consent and the actual destinations.
- Release day: publish the article, schedule its approved social package, and complete any assisted tasks.
- Following day: respond in the native platforms and note useful feedback for the next experiment.

The publishing centre schedules one approved package at a selected time. It does not invent future weekly chapters or automatically repeat a package forever. Publish the source article before or at the social release time; linked posts that are no longer public stop delivery.

## 4. Connect the accounts

Open **Writer → Connections**. Public profile links remain usable before OAuth is configured.

The profile defaults are:

| Service | Profile | Declared type |
| --- | --- | --- |
| LinkedIn | https://www.linkedin.com/in/iwaithakaw/ | Member |
| Facebook | https://www.facebook.com/iwaithakaw | Professional profile |
| Instagram | https://www.instagram.com/iwaithakaw | Professional |
| X | https://x.com/iwaithakaw | Professional |
| YouTube | https://www.youtube.com/@waithakandegwa | Channel |
| TikTok | https://www.tiktok.com/@waithakandegwa | Personal |

The live connection must return the intended account; matching a typed name or declaring “professional” is insufficient.

### Google

Configure the Google project’s OAuth web application and the exact callback URLs displayed under **App credentials & callback addresses**. Enable the APIs needed for the selected features, complete the applicable consent-screen/testing/verification steps and authorize the account that owns the resources.

Four separate grants are offered:

- **Search Console:** read site search performance for a returned verified property. Ownership verification and sitemap submission remain separate actions in Google.
- **Google Analytics:** read an existing GA4 property. The site measurement ID in Writer Settings is a separate configuration.
- **YouTube read:** read the selected channel’s Data API figures; this is not a YouTube Analytics revenue or watch-time report.
- **YouTube upload:** separate authorization for future/native upload capability. This release still sends video through a supported Buffer route or assisted YouTube Studio workflow.

Select and confirm the returned site, property or channel before it becomes connected. Reader-facing Google sign-in is not included.

Use the existing Google measurement property only after confirming its ownership. Public analytics scripts load after consent; private Writer, preview, payment, consent and subscription-token pages suppress analytics. URL query strings are excluded from the reported page location.

### Social routes

Start with assisted delivery to establish a reliable weekly process. Add automatic routes for the formats you actually use.

| Route | What this release can send |
| --- | --- |
| Assisted | Prepared copy and media reference for all six services; complete the native steps and record the actual post URL |
| Buffer | Supported text, one image or one video according to the connected channel; automatic delivery or supported mobile notification |
| Direct X | Text/link posts |
| Direct LinkedIn | Text/link posts |
| Direct Facebook | Text/link posts to a real confirmed Page |
| Direct Instagram | One public JPEG image with its caption |

Instagram is recorded as professional. Its direct route uses Instagram Login. For Reels, Stories, carousels or other unsupported direct formats, use a supported provider/native route; this release’s package supports one media file rather than a carousel builder. The website’s optimized featured WebP image is not automatically converted into an Instagram JPEG. [Meta media requirements](https://developers.facebook.com/reference/media)

Facebook stays a professional **profile** until a Page is independently discovered and confirmed. Selecting “Page” in the form only changes the requested setup path. It does not convert the profile or verify ownership. A professional-mode profile uses assisted posting in this release; Meta’s documented publishing API targets Pages. [Meta Pages API](https://developers.facebook.com/documentation/pages-api)

X remains professional but needs usable API access, user authorization and the required write permissions. Check actual provider pricing/usage settings in the account before activating paid access.

Buffer requires a key from your Buffer account, verification of returned organizations/channels, and selection of the exact channel. Its plan and the underlying platform still determine availability. This centre does not operate other people’s personal accounts simply because their stories appear on the website.

Never put a social password into Writer. Use provider app credentials, OAuth or the supported Buffer key field. Removing a saved connection stops local use; revoke the app in the provider account as well when withdrawing permission. Already queued remote posts must be checked in the provider separately.

## 5. Prepare, review and release a package

1. Open **Writer → Publishing → Prepare a publication**. A package can be linked to an existing article.
2. Select only the channels for this release. Write a suitable version for each audience instead of copying an identical caption everywhere.
3. Include the article link in the caption when useful; the application does not silently append it.
4. Use approved, public HTTPS media files. A YouTube watch page is not a raw video file. Keep approved URLs available until delivery and use a new URL for a changed asset.
5. Save the draft, then read **Saved review**. It shows the exact saved text, media, format, visibility and destination.
6. Confirm content review and media permissions, then approve the saved revision.
7. Schedule the revision in Nairobi time. The worker stores/delivers scheduled jobs in UTC.
8. Review delivery records and complete assisted tasks in the native apps. Record the actual post URL, not the general profile URL.

A new draft does not change an approved revision or existing job. Repeated scheduling of the same revision creates no duplicate jobs. If a destination changes, review and approve a new revision for new work.

“Reminder queued” means an email task exists. “Reminder sent” means the mail provider acknowledged the message; it does not prove receipt or reading. Neither status means the social post is published. “Accepted / processing” means the provider accepted work but has not yet confirmed publication. “Outcome unknown” requires checking the provider before any further action; the site does not blindly retry it.

A local cancellation cannot cancel a post already queued remotely. Check the remote queue before closing a record with a provider ID or uncertain outcome.

## 6. Publish other founders’ stories

The public submission form collects a proposal for review. It is not a self-publishing directory and does not promise coverage.

1. Review the private submission at **Writer → Startup Journeys → Founder submissions**.
2. Confirm identity, role, contact permission and the intended public wording.
3. Check claims against suitable evidence. Keep contact details and private supporting records out of the article.
4. Obtain specific permission for quotes, photographs, video and identifiable people. The existing Consent module supports private permission records; use it where appropriate.
5. Write the actual article through the normal Writer workflow. Make your relationship, sponsorship, compensation or investment interest clear in the article where relevant.
6. Link that article in the review, confirm permission and claim review, approve the submission and enable public display.
7. Publish the article. The Founder Stories list includes it only when all publication conditions are satisfied.

Do not present founder-reported revenue, customers, fundraising or outcomes as independently verified unless you have done that verification. Describe the period, source and limits. Do not imply that an editorial feature is an endorsement or investment recommendation.

## 7. Subscriptions and distribution

The subscription form records a request, sends a confirmation email through the outbox, and activates the subscriber only after confirmation. Opening a confirmation/unsubscribe link displays a form; the confirming POST changes the record. Tokens expire and used confirmations cannot be replayed. Obsolete queued confirmations are suppressed.

This release does **not** compose or broadcast weekly newsletter campaigns. Before sending editions, add a campaign workflow or a supported email-platform integration with the confirmed-only audience, unsubscribe handling, bounce/complaint handling and delivery reconciliation. Do not export pending or unsubscribed addresses as an active mailing list.

RSS is already available at `/feed.xml` and includes only due public articles.

## 8. SEO and useful measurement

Use one canonical article per lesson. Link each chapter to its journey, related articles and the next/previous chapter. Keep descriptive page titles, clear headings, evidence, image descriptions and useful summaries. The site renders crawlable HTML, canonical metadata, structured data, social previews, sitemap and RSS.

After cutover, verify both domains in Search Console, submit the canonical sitemap and inspect important restored pages. A redirect plan, schema or a connection alone does not guarantee ranking.

For the first season, measure sustained publication, completed experiments, qualified reader responses and subscribers who confirm. Use Search Console and GA4 reports to learn which questions attract interested readers. Social reach and engagement remain in the platforms/Buffer; this release does not aggregate a universal inbox or all-platform analytics dashboard.

The research strategy supplied with the recovered project remains the wider editorial plan. This release incorporates the corrected professional status of Facebook, Instagram and X.
