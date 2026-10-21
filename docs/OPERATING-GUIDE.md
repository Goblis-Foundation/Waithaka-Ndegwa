# Operating guide

Release: 2026-10-21. The workflows below are implemented and tested with local synthetic fixtures. AWS deployment, restored live data, real account authorization and public/social/email delivery have not been performed. Use the [migration runbook](AWS-MIGRATION.md) and [validation record](VALIDATION.md) for the remaining deployment checks.

Writer's main navigation is **Library, New thought, Journeys, Founder reviews, Publishing, Newsletter, Media, Connections, Operations, Consent and Settings**. New drafts and approvals are private; scheduled work is delivered only when the worker and relevant provider are enabled.

## 1. The editorial promise

The project follows one startup from its starting conditions through real decisions, experiments, systems and outcomes. The website holds the complete, durable record. Social posts introduce a useful part of each chapter and link readers to the evidence.

Use one season per venture. The application permits only one **published** journey in Planning, Building or Operating at a time. You can prepare private drafts for later ventures. Pause or conclude the current public journey before activating the next.

No venture has been selected or invented for this release. Create the first journey from the actual startup you choose, with its real starting date, resources, constraints and learning question.

## 2. Create the first journey

1. Sign in to **Writer → Journeys → New journey**.
2. Enter the title, venture name, starting date, phase, short public summary and starting baseline. Mark estimates, assumptions and facts clearly.
3. Keep publication set to Draft while gathering material. The private notes field is for research and operational details that should not appear publicly.
4. Write the first article through **New thought**. The Plan tab holds the question, private research notes and permission checks.
5. Save, preview and explicitly publish the article, or give it a future publication time. Autosave does not publish changes.
6. Open the journey and add a chapter link to that article. Use the recent-post suggestions or the numeric post ID shown in its Writer edit address.
7. Give it a chapter number, public summary, evidence basis and evidence note. Private evidence notes remain in Writer.
8. Publish the journey and enable the chapter link when ready. A chapter is visible only when its journey, chapter link and linked article are all public and the article’s date is due.

The journey URL stays fixed after first publication. Article slugs and original publication dates remain stable when you revise an article. Removing a chapter link leaves the article in the writing library. My Thoughts, individual topic archives and Founder Stories show 24 records per page with accurate total counts, ordinary newer/older links and page-specific canonical addresses. Older chapters remain reachable as the weekly archive grows; the on-page filter and sort apply only to the current page. Use full search to search across articles.

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
- Midweek: draft the complete website chapter, upload its reviewed images/video to **Media**, and prepare the social package and newsletter edition.
- Before release: review claims, quotations, captions, media access, audience settings, consent, the actual social destinations and the saved newsletter email.
- Release day: publish the article, schedule its approved social package and newsletter edition, and complete assisted tasks.
- Following day: respond in the native platforms and note useful feedback for the next experiment.

The publishing centre schedules one approved package at a selected time. The newsletter schedules one approved edition for a fixed eligible audience. Neither workflow invents future weekly content or automatically repeats it forever. Publish the source article before or at the social release time; linked posts that are no longer public stop delivery. A newsletter imported from an article requires that article to be public already and remain public at delivery.

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
| TikTok | https://www.tiktok.com/@waithakandegwa | Professional, owner-declared |

The live connection must return the intended account; matching a typed name or declaring “professional” is insufficient. TikTok's professional declaration records the owner's account description. It does not verify a Business subtype, app eligibility or publishing permission.

Migration `090_tiktok_professional.sql` corrects the old TikTok `personal` default on both new installations and upgrades. Run it through the normal restricted migration process. It preserves profile URLs, provider identities, encrypted credentials, delivery routes and existing approved revisions/jobs, and leaves a more specific saved declaration unchanged. A review opened before the correction must be refreshed for a new approval; historical approved destinations remain exactly as approved.

### Google

Configure the Google project’s OAuth web application and the exact callback URLs displayed under **App credentials & callback addresses**. Enable the APIs needed for the selected features, complete the applicable consent-screen/testing/verification steps and authorize the account that owns the resources.

Four separate grants are offered:

- **Google Search Console:** read site search performance for a returned verified property. Ownership verification and sitemap submission remain separate actions in Google.
- **Google Analytics:** read an existing GA4 property. The site measurement ID in Writer Settings is a separate configuration.
- **YouTube read access:** read the selected channel’s Data API figures; this is not a YouTube Analytics revenue or watch-time report.
- **YouTube upload access:** the separate connection used by native resumable upload. This grant requests upload permission and owner read permission so the worker can verify the destination, processing and publication settings. A read-only connection cannot upload.

Select **Returned destination**, confirm that it is the intended account/resource, then choose **Confirm destination**. A successful OAuth return alone is still pending confirmation. Use **Read current report** for the connected Google service. Reader-facing Google sign-in is not included.

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
| Direct YouTube | An owned MP4, MOV or WebM video up to 512 MiB, including a private library asset, with explicit settings and processing checks |
| Native TikTok Accounts API | An owned public MP4, MOV or WebM video up to 512 MiB on a verified URL property, with explicit public audience, interaction, promotional and AI settings; separate approved app/grant required |

Instagram is recorded as professional. Its direct route uses Instagram Login. For Reels, Stories, carousels or other unsupported direct formats, use a supported provider/native route; this release’s package supports one media file rather than a carousel builder. The website’s optimized featured WebP image is not automatically converted into an Instagram JPEG. [Meta media requirements](https://developers.facebook.com/reference/media)

Facebook stays a professional **profile** until a Page is independently discovered and confirmed. Selecting “Page” in the form only changes the requested setup path. It does not convert the profile or verify ownership. A professional-mode profile uses assisted posting in this release; Meta’s documented publishing API targets Pages. [Meta Pages API](https://developers.facebook.com/documentation/pages-api)

X remains professional but needs usable API access, user authorization and the required write permissions. Check actual provider pricing/usage settings in the account before activating paid access.

Buffer requires a key from your Buffer account, verification of returned organizations/channels, and selection of the exact channel. Its plan and the underlying platform still determine availability. This centre does not operate other people’s personal accounts simply because their stories appear on the website.

The current Buffer YouTube route supports Shorts with a public video file. Use native YouTube for a full-length video, a private library file or an explicit subscriber-notification choice. Other automatic media routes also need a public asset URL; selecting a private file does not cause it to be silently omitted from a post.

TikTok is recorded as professional. The implemented native publisher uses the separate [TikTok API for Business Accounts API](https://business-api.tiktok.com/portal/docs/accounts-api-overview/v1.3). Its app review and account authorization are independent of Login Kit reporting. This release does not use the Content Posting API Direct Post product, whose [guidelines](https://developers.tiktok.com/doc/content-sharing-guidelines) exclude a private own-account utility. Assisted and separately authorized Buffer routes remain available. Finish the audience, interaction, music, promotional-content and applicable AI-disclosure choices in TikTok when completing an assisted task.

Under **Channel routes**, choose the delivery method and actual Buffer destination when relevant, then select **Save route**. Changes apply to future approvals. Existing revisions keep the destination they were approved for.

Never put a social password into Writer. Use provider app credentials, OAuth or the supported Buffer key field. Removing a saved connection stops local use; revoke the app in the provider account as well when withdrawing permission. Already queued remote posts must be checked in the provider separately.

### TikTok: separate reporting and publishing connections

The **professional** declaration records your account description. TikTok’s returned account data and granted permissions are checked separately; a verified badge does not establish Business API eligibility.

**Read reports:** obtain approval for Login Kit, Display API and `user.info.basic`, `user.info.profile`, `user.info.stats`, `video.list`. Register `https://www.waithakandegwa.com/write/connections/callback/tiktok_read`. In Connections, save the TikTok Login Kit Client Key and Client Secret, authorize **TikTok read access**, inspect the returned username, and confirm the account. **Read current report** shows current profile totals and up to 20 recent public videos. These are cumulative snapshots, without historical growth, revenue or watch-time analysis. Missing counters are shown as unavailable. Read permission does not allow posting. [Login Kit](https://developers.tiktok.com/docs/en/login-kit-web) · [Display API](https://developers.tiktok.com/docs/en/display-api-overview) · [Token renewal](https://developers.tiktok.com/docs/en/oauth-user-access-token-management)

**Native publishing:** create or use the intended API for Business application and complete TikTok’s Accounts API access application/review. Request the exact scopes displayed for **TikTok Accounts API**, including `video.publish`, `user.info.basic`, `user.info.username`, and `user.account.type`. Register `https://www.waithakandegwa.com/write/connections/callback/tiktok_business/`, including the trailing slash. Copy the account-holder authorization URL generated by the approved app’s portal; save it with that app’s separate ID and secret under App credentials. The website validates the URL and adds its own session-bound, single-use state. Authorize, inspect the returned account and confirm the destination. The application checks the inspected app/creator identifiers, scopes, returned username and account settings; typing a handle or selecting professional is insufficient. [Accounts API authentication](https://business-api.tiktok.com/portal/docs/accounts-api-authentication/v1.3)

Verify the canonical media domain or URL prefix through TikTok’s application ownership process. On the supplied AWS configuration, `MEDIA_PUBLIC_URL` is the canonical `.com` site, and the media path is served through the configured CloudFront distribution. The worker reads the provider’s verified property records before posting and checks that the approved public asset belongs to the configured storage path. A public media URL alone is not evidence that TikTok has verified its ownership. Before enabling delivery, verify that the live DNS/CDN route returns the intended public bytes and keep those URLs available during processing. Local preflight cannot prove live CDN routing or prevent an out-of-band overwrite after validation.

Choose **TikTok Accounts API — native video publishing** under TikTok’s channel route. In each package, select an owned public video and explicitly choose **Public**, comments, Duet, Stitch, any own-brand or commercial-partnership declaration, and any AI disclosure. Confirm the native settings, save, inspect the saved preview and approve the package. This adapter rejects both promotional categories being selected together; complete that combination in TikTok. Buffer cannot silently inherit these native settings: clear them or use a route that supports them.

The native adapter checks MIME type, size, owned-file identity, bytes and the current public storage binding. It does not transcode video or locally analyze duration, dimensions or frame rate. The reviewed endpoint documentation specifies MP4, MOV or WebM, a duration of 3–600 seconds subject to a lower returned account limit, at least 360 pixels in each dimension and 23–60 frames per second. The site's 512 MiB upload limit is lower than the endpoint's 1 GB limit. TikTok validates the video during processing. If account settings stop allowing an interaction you approved, the job requires review instead of silently changing the settings. [Video publishing requirements](https://business-api.tiktok.com/portal/docs/publish-a-public-video-post-to-an-owned-account/v1.3)

An accepted `share_id` is a pending publishing task. The worker records it and only polls that task. Completion requires `PUBLISH_COMPLETE` and publicly viewable post IDs returned by TikTok; complete processing without visible IDs remains pending. If a mutation may have succeeded but its acknowledgement is lost, the job stays **Outcome unknown** and is never automatically reposted. Where a task ID is known, a reviewed retry reads its status. Closing a local job does not cancel a task already accepted by TikTok.

If either TikTok connection requires reconnection, reauthorize that specific service. Read and native tokens are encrypted and managed independently. A lost token-renewal response cannot be safely recovered by repeatedly using an old rotating token. No real app review, account authorization, domain verification or live TikTok delivery has been performed by this release.

## 5. Prepare the media

Open **Writer → Media → Upload an asset**. Enter a useful title, choose the file and review **Access** before selecting **Upload media**.

| Access | Intended use | What a reader/provider can access |
| --- | --- | --- |
| Private | Review in Writer, download for assisted posting, or native YouTube upload | Preview/download requires Writer authentication; the approved upload worker transfers the file to YouTube when due |
| Public | Media fetched by a supported social provider or linked publicly | Anyone with the public URL can view/share the file; the public-access acknowledgement is required |

Supported images are JPEG, PNG and WebP up to **25 MiB**. Supported videos are MP4, MOV and WebM up to **512 MiB**, within the actual server upload limit. The media library checks the uploaded file's contents and container; it does not transcode videos or convert WebP into the JPEG needed by direct Instagram delivery. The native YouTube route does not import a remote URL.

Open the asset to review it, use **Download original**, and inspect **File details** when needed. In a publishing package, choose it under **Owned media file**. That selection takes precedence over the public URL and media-type fields. Approval binds the file's identity, storage location/version, size, MIME type and fingerprint; native upload materializes and verifies those exact bytes before transfer.

Upload a new asset whenever a file changes. Older saved selections remain visible even beyond the latest 200 assets. If an existing selection is archived, missing or no longer valid, the form retains its ID and asks you to replace or remove it; a routine save cannot silently downgrade it to a URL or drop it. **Archive asset** removes it from new package selections but preserves existing approved references. Archiving does not remove a public URL or retract a file that a platform has already received. Do not use archiving as a privacy revocation control.

## 6. Prepare, review and release a package

1. Open **Writer → Publishing → New package**. The editor is headed **Prepare a publication**. A package can be linked to an existing article.
2. Select only the channels for this release. Write a suitable version for each audience instead of copying an identical caption everywhere.
3. Include the article link in the caption when useful; the application does not silently append it.
4. Select the reviewed **Owned media file**, or use a public HTTPS media file URL where the route supports it. Native YouTube requires an owned video. A YouTube watch page is not a raw video file. Keep external media URLs available until delivery and use a new URL for a changed asset.
5. Select **Save draft**, then read **Saved review → Review the saved version**. It shows the exact saved text, file, format, visibility, settings and destination.
6. Confirm content review and media permissions, then select **Approve saved revision**.
7. Choose **Release time · Africa/Nairobi**, then **Schedule approved revision**. The worker stores delivery times in UTC. This is when delivery begins; video transfer/processing can finish later.
8. Review delivery records and complete assisted tasks in the native apps. Record the actual post URL, not the general profile URL.

A new draft does not change an approved revision or existing job. Repeated scheduling of the same revision creates no duplicate jobs. If a destination changes, review and approve a new revision for new work.

“Reminder queued” means an email task exists. “Reminder sent” means the mail provider acknowledged the message; it does not prove receipt or reading. Neither status means the social post is published. “Accepted / processing” means the provider accepted work but has not yet confirmed publication. “Outcome unknown” requires checking the provider before any further action; the site does not blindly retry it.

A local cancellation cannot cancel a post already queued remotely. Check the remote queue before closing a record with a provider ID or uncertain outcome.

### Native YouTube delivery

Authorize **YouTube upload access**, confirm the returned channel, then select **Direct API — use the confirmed account** in the YouTube channel route and **Save route**. The worker checks the current grant's actual channel before initializing an upload; choosing a branding URL does not select an upload destination.

In the package's YouTube version, choose the owned video and review **Video title**, caption/description, **Video visibility**, the made-for-children choice, realistic altered/synthetic-content disclosure and **Ask YouTube to notify subscribers about this video**. The saved review displays both Yes and No choices explicitly. Visibility is sent as Private, Unlisted or Public; the adapter never relies on an omitted API default. The category is Education. The **Short** format does not force classification: YouTube determines eligibility.

Follow **Delivery records** after release:

| Display | Meaning and next step |
| --- | --- |
| Uploading · N% acknowledged | The saved session has acknowledged that proportion of the file. The worker continues bounded chunks and queries the same session after an interruption. |
| Uploaded · Processing | A video ID is recorded; YouTube processing is still being checked. |
| Uploaded · Private / Uploaded · Unlisted / Published · Public | Automatic native completion requires owner API confirmation of processing and the approved visibility, audience and disclosure settings. Manual completion records the Writer's verification instead. |
| Reconnect required | Reauthorize the same account and confirm the approved channel before reviewing/resuming the existing job. |
| Needs attention / Outcome unknown | Read the recorded reason and inspect the existing video/session in Studio before proceeding. A missing or mismatched returned setting is not treated as confirmation. |

When offered, open **Review and resume the existing YouTube upload** and select **Resume reviewed job** after resolving the reported issue. It queries/resumes the existing session or video; it does not create another upload. A lost final response can therefore recover the original video ID. An expired or unknown session may require a new reviewed revision, after checking Studio for an existing video. Do not create a replacement merely because an acknowledgement was lost.

If you finish or verify the existing upload in Studio, **Approved content & completion** provides the frozen title, copy, settings and media download. Record the actual video URL only after checking the approved visibility and any pending duplicate work. That manual completion is an operator confirmation, not new API evidence. **Cancel local job** stops future local chunks/checks; it does not delete an accepted video. The scheduled time is not a guaranteed public premiere/release time, and other Studio-only settings remain native-platform work.

## 7. Publish other founders’ stories

The public submission form collects a proposal for review. It is not a self-publishing directory and does not promise coverage.

The private review inbox shows 50 submissions per page, preserves the selected status filter and counts all matching submissions. Its pagination remains authenticated; private contact details and review notes never become archive content.

1. Review the private submission at **Writer → Founder reviews**.
2. Confirm identity, role, contact permission and the intended public wording.
3. Check claims against suitable evidence. Keep contact details and private supporting records out of the article.
4. Obtain specific permission for quotes, photographs, video and identifiable people. The existing Consent module supports private permission records; use it where appropriate.
5. Write the actual article through the normal Writer workflow. Make your relationship, sponsorship, compensation or investment interest clear in the article where relevant.
6. Link that article in the review, confirm permission and claim review, approve the submission and enable public display.
7. Publish the article. The Founder Stories list includes it only when all publication conditions are satisfied.

Do not present founder-reported revenue, customers, fundraising or outcomes as independently verified unless you have done that verification. Describe the period, source and limits. Do not imply that an editorial feature is an endorsement or investment recommendation.

## 8. Prepare and send a newsletter edition

### Subscriptions and the audience

The public subscription form records a request and queues a confirmation email. The address becomes eligible only after the subscriber confirms it. Opening the ordinary confirmation/unsubscribe link displays a form; submitting that form changes the record. Confirmation tokens expire, used confirmations cannot be replayed, and obsolete queued confirmations are suppressed.

Confirmation is bound to the mailbox for which the link was issued, both when sending the email and when accepting the confirmation. Changing an address cannot make its old link confirm a replacement mailbox. Restored legacy confirmation links work only when the original encrypted confirmation-outbox record proves the same issued address and the other validity checks pass. If that evidence is missing or unreadable, retain the historical record and ask the reader to request and confirm a fresh subscription; do not mark it confirmed manually.

Campaigns use a **frozen audience of eligible confirmed subscribers at the moment you schedule**. Pending requests, unsubscribed addresses and suppressed addresses are excluded. New confirmations after scheduling do not join that edition. Immediately before each queued email is sent, the application checks the subscriber's current status, address, consent revision and suppression again. A later unsubscribe or changed consent can therefore remove delivery eligibility even though the recipient remains in the historical audience record.

Writer cannot mark an unconfirmed address as confirmed. The newsletter workflow does not treat an address from a founder submission, personal contact list or social account as a subscription.

### Compose, review, approve and schedule

1. Open **Writer → Newsletter → New edition**. Give it an **Edition title · private label**, **Email subject**, **Inbox preview text** and email body.
2. To reuse the week's public article, open **Start from a public article**, select it and choose **Import and save draft**. Import replaces the subject, preview text and body with the public article and website link. Private working drafts are excluded. Alternatively, write a standalone edition and leave **Linked public article** empty.
3. Use public HTTPS images and links. Private Writer links, subscription tokens and signed-access media links are rejected. The subscription explanation, mailing address and recipient-specific unsubscribe link are added automatically.
4. Choose **Save private draft**. Open **Saved preview & approval → Review the saved email**, inspect the displayed sender/reply-to, subject and preview text, and read the exact email. **Open saved preview** has no live recipient token.
5. Confirm the saved-content and permission checkboxes, then choose **Approve saved edition**. Saving or approving does not send email. The approval fixes the saved content, sender and footer; an edit before scheduling clears that approval.
6. Under **Release controls**, choose the Nairobi release time and acknowledge the currently eligible confirmed audience. Select **Schedule approved edition**. An empty eligible audience cannot be scheduled.
7. Open **Delivery report** to follow recipient outcomes. The worker queues and sends bounded batches, so a large edition may take multiple worker runs after the release time.

Configure the actual sender identity and postal address before approval. Sending also requires enabled background work and a working mail transport. The editor shows **Mail transport disabled** or **Background work is disabled** when those controls are paused. A configured transport is not evidence that the provider accepted a message.

Once scheduled, the edition's approved content and recipient snapshot are locked. **Copy into a new draft** creates a separate edition without approval, recipients or a schedule. Changing the configured sender/site address after approval, or making the linked article private, pauses delivery for review instead of sending content under changed assumptions.

### Pause, resume, cancel and read the report

Use **Pause remaining deliveries** to hold unsent work. **Resume the same edition** selects a future time while preserving the same approved content and original audience. **Cancel remaining deliveries** stops unsent work after the explicit cancellation acknowledgement. Messages already accepted by the provider cannot be recalled, and uncertain outcomes remain recorded.

| Report state | What it establishes |
| --- | --- |
| In audience snapshot / Queued for delivery | The recipient was selected or a message is waiting; no send is confirmed. |
| Accepted by mail provider | The email provider acknowledged the submission. This does not establish inbox placement or reading. |
| Delivery confirmed | Verified provider feedback says the receiving mail server accepted the message. It still does not prove inbox placement or reading. |
| Bounced / Complaint received | Verified provider feedback records the delivery failure or complaint. Hard bounces and complaints suppress future sends. |
| Suppressed / Cancelled | Eligibility or campaign controls prevented remaining delivery. The report retains the reason. |
| Outcome unknown / Needs attention | Investigate the record and provider logs. Unknown sends are not automatically retried. |

**Processing complete** means every recipient has a recorded outcome. It can include suppressions, failures or unknown outcomes; it does not mean every reader received the email. The report provides recipient pages and **Edition history**. Open/click analytics are outside this release.

### Unsubscribe and subscription preferences

Every campaign email includes the normal unsubscribe link plus mail-client one-click headers. The one-click endpoint accepts the recipient token and the email client's explicit `List-Unsubscribe=One-Click` POST without requiring a login. A GET request does not unsubscribe, so merely opening or scanning the URL does not perform that change. Keep these capability links private.

Open **Newsletter → Subscribers**, search/filter, and select a subscription to review its confirmation, consent revision, suppression and history. **Unsubscribe this address** stops newsletter delivery. **Suppress and unsubscribe** records a private reason. **Clear manual suppression** removes only a manual newsletter block and leaves the address unsubscribed; a fresh request and email confirmation are still needed. It does not clear provider bounce/complaint suppression.

RSS remains available at `/feed.xml` and includes only due public articles. It does not depend on campaign delivery.

### Configure and monitor SES feedback

The AWS deployment must configure the sender identity, DKIM, sending access, SES configuration set and the exact SNS event topic. The supplied [AWS migration runbook](AWS-MIGRATION.md) covers these deployment steps and the mail-feedback template. The application receives signed notifications at `/webhooks/ses`; it validates the exact configured topic, certificate URL and signature before applying feedback, and deduplicates repeated events.

After the signed subscription confirmation arrives, open **Writer → Operations → Mail feedback**. Under **Feedback connection**, select **Confirm configured SNS subscription** when offered. This confirms the configured topic through AWS; typing a topic ARN alone does not activate feedback. Verify actual delivery, bounce and complaint handling with controlled messages before enabling campaigns.

**Mail delivery feedback → Recent events** shows event type, result, outbox reference and receipt time. Matching hard-bounce and complaint events create an address suppression checked by future email sends and suppress the corresponding newsletter subscription. A later delivery notification cannot erase a stronger bounce/complaint outcome. Review unmatched events through the private deployment logs rather than assuming they correspond to a campaign.

The application can submit email through SES or SMTP. This release's automated delivery/bounce/complaint feedback adapter is **SES/SNS**; an SMTP configuration does not automatically provide that feedback integration.

## 9. Review Operations

Open **Writer → Operations → Refresh status** for a read-only view of local application state. The page does not apply migrations, connect accounts, send messages or retry jobs.

| Section | How to use it |
| --- | --- |
| Configuration and restoration | Review runtime, key configuration, canonical HTTPS/proxy settings, sessions, database/TLS configuration, public/private storage, mail/feedback configuration and migration status. A key format check does not prove it is the original recovered key. |
| Worker activity | Check recent starts, last reports, stage and outcome. A paused invocation can be healthy while delivery is deliberately disabled. A recent heartbeat confirms an invocation reached this database, not every host or provider. |
| Publishing work | Inspect scheduled/overdue/stale/unknown counts and follow **Open approved package** for completion, reconnect or outcome review. |
| Email outbox | Distinguish pending, overdue, stale claims, unknown outcomes, acknowledged and suppressed records. Follow **Review delivery feedback** for the verified SES event history. |

**Checked locally** means the displayed local check succeeded. **Review needed**, **Needs attention**, **Not verified** and **Paused** describe work still required or a deliberate disabled state. None of these labels certifies live AWS permissions, certificates, network access, a successful backup restore or provider availability.

The deployment controls are separate: `APP_READY` allows the restored application to serve; `WORKER_ENABLED` allows background delivery work; `MAIL_TRANSPORT` selects or disables email submission. Keep restored staging workers and mail paused. In production, review the restored jobs/outbox and confirmed destinations before enabling work so two environments cannot send the same outstanding content.

Use the newsletter's delivery report for edition-specific outcomes and the publishing package for a video's detailed progress. Operations supplies the overview and links. Resolve unknown outcomes using the relevant platform or mail-provider evidence before arranging any retry; refreshing diagnostics does not replay work.

## 10. SEO and useful measurement

Use one canonical article per lesson. Link each chapter to its journey, related articles and the next/previous chapter. Keep descriptive page titles, clear headings, evidence, image descriptions and useful summaries. The site renders crawlable HTML, canonical metadata, structured data, social previews, sitemap and RSS.

After cutover, verify both domains in Search Console, submit the canonical sitemap and inspect important restored pages. A redirect plan, schema or a connection alone does not guarantee ranking.

For the first season, measure sustained publication, completed experiments, qualified reader responses and subscribers who confirm. Use Search Console and GA4 reports to learn which questions attract interested readers. Newsletter reports add evidence of provider acceptance, confirmed server delivery, bounces, complaints and suppression; these are not open/read metrics. Social reach, comments and messages remain in the platforms/Buffer; this release does not aggregate a universal inbox or all-platform analytics dashboard.

The research strategy supplied with the recovered project remains the wider editorial plan. This release incorporates the corrected professional status of Facebook, Instagram and X.

## 11. Deployment handoff and the AWS helper

Use the [AWS migration runbook](AWS-MIGRATION.md), [deployment checklist](../DEPLOYMENT-CHECKLIST.md) and [reviewed installation/cutover helper guide](../deploy/aws/INSTALL-CUTOVER.md) to move the recovered site. The source release does not contain the live database, original private application key or current uploaded files; those inputs must come from the current host. Preserve existing Writer accounts, article URLs, consent records and outstanding queue states.

The helper's offline inventory/readiness/plan commands make the proposed recovery concrete. Each phase plan binds the chosen configuration, release, recovery artifacts and preceding outputs; applying it requires its exact reviewed SHA-256. Explicit live commands check the intended AWS account. The sequence separates data/media/application/edge setup, CloudFront media access, restoration readiness, DNS cutover, worker activation and mail activation. It does not automatically import SQL, generate a replacement key, recreate the first Writer user or certify that an operator's acceptance checks were performed.

Run the documented restore and migrations through the restricted deployment process. Runtime web requests and instance startup do not run migrations. Keep the source backup/key/media and rollback DNS information until live acceptance succeeds. The canonical production address remains `https://www.waithakandegwa.com`, with `waithakandegwa.co.ke` and `www.waithakandegwa.co.ke` redirecting to the corresponding canonical paths after the actual edge/DNS cutover.

This implementation has not made that cutover, authorized real accounts or sent real newsletters/videos/posts. Reader Google login, a universal social inbox, all-platform analytics, automatic weekly content generation and the Content Posting API Direct Post product remain outside the release. Native TikTok publishing is provided by the separately authorized Accounts API adapter.
