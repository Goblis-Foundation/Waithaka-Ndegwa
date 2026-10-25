# Founder-approved employment opportunities

## Delivered workflow
Public directory `/opportunities`, mobile-friendly private submit/save-and-resume form, email capability links, owner-only `/write/opportunities` inbox, clarification requests, frozen exact-copy review, up to three founder review packages, separate owner publication, withdrawal and deadline closure. Related published enterprise profiles show their live opportunities. Founder Stories links to the directory and submission form.

Initial submissions and working drafts are encrypted in the existing settings store. A small encrypted catalogue stores summary fields separately. All mutations serialize through a catalogue advisory lock and commit the record, summary and email queue together. Capacity is explicitly bounded at 500 retained submissions; no record is silently evicted. No migration, infrastructure, paid service or additional subscription is introduced.

## Consent and privacy
Private contact, relationship/authority evidence, questions, founder notes, snapshots and audit history are never in the public projection. Publication uses a whitelist of validated listing fields. The founder's business contact is immutable within a workflow. Owner checks identity/authority privately before requesting clarification or final approval. This is an editorial verification record, not automatic company verification.

One-use protected forms, hidden-field screening, five-per-minute IP limit, sender limit and 24-hour duplicate suppression protect submission. Valid suspicious submissions are held in the private inbox without notifications. Expiring random links are bound to current version and listing digest. GET/HEAD never approve or consume a link. CSRF-protected explicit POST is required. Replies invalidate the token; continuing open workflows receive a replacement status link. Expired links require the reviewer to issue another request.

Clarifications do not consume final review rounds. Each final package consumes one round. Founder edits and editorial changes invalidate approval. The third nonapproval or expired third review becomes closed without publication. Expired approval state is evaluated at access time; no cron is needed to prevent publication. Audit preserves the underlying request and response times rather than inventing a delivery time.

The publication action is separate and requires the approved digest and a current deadline. Past deadlines immediately remove the public application CTA, listing from the directory/enterprise areas and JobPosting schema. Old detail URLs return 410. Renewals start a fresh submission and require fresh approval.

## Delivery
Emails reuse the existing branded header, personalised salutation, Waithaka Ndegwa signature, social links and service/subscription footer. Queue IDs are shown privately; queue acceptance is not a delivery receipt. An owner can prepare a WhatsApp review message only for a supplied number with explicit WhatsApp permission and an active review link. Sending remains a deliberate action in WhatsApp. This release does not claim automated WhatsApp API delivery or receipt tracking for opportunity notifications.

## Job quality and search
Separate employment, paid contract, internship and volunteering labels; permanent/temporary status; country and Kenya county/sub-county/town; Kenya/+254 defaults; remote eligibility; essential/desirable criteria; accessibility; compensation range or an explicit non-disclosure reason; deadline/timezone; application destination/process; no application fee.

Applications go to the employer's public HTTPS destination or email. The platform does not collect CVs, identity documents or candidate records, and does not select candidates. Evidence is recorded as private notes in this release; a dedicated opportunity evidence-attachment flow and scheduled publication are still pending.

Only live individual paid employment/contract listings receive JobPosting JSON-LD. Lists, drafts, unpaid roles, internships/volunteering and closed detail responses do not. Remote country eligibility and published/closing dates are included. No promise of Google rich-result inclusion or automatic LinkedIn/Meta job publication is made.

Primary reference checked 26 October 2026: https://developers.google.com/search/docs/appearance/structured-data/job-posting (individual listing markup, accurate visible content, remote eligibility, closure and removal of expired job markup).

## Verification
Pure storage/queue fixtures cover consent and ownership boundaries, version binding, one-use tokens, explicit publication, edits invalidating approval, three-round limit, expiration, private/public projection, duplicate suppression, suspicious-message holds, input validation and rollback after queue failure. The isolated real-HTTP suite exercises submission → owner review → founder POST approval → publication → withdrawal, CSRF and privacy headers with disabled transport and fixture cleanup. Production QA does not submit fake opportunities or send notifications.
