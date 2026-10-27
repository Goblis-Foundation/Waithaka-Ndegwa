# Waithaka Ndegwa requirements reconciliation — 27 October 2026

## Scope and evidence

This batch reconciles the instructions visible in this chat, recovered work context and dated repository records from 23–26 October. The complete 26 October work-chat transcript was not accessible through the available retrieval surfaces. Do not claim that an unavailable transcript was exhaustively reviewed. The source audit found no open repository issues before this batch; absence of issues is not proof that all requirements are complete.

The private `/write/reconciliation` register preserves 20 requirement areas and distinguishes implemented code from account activation, source data and unimplemented capabilities. It retains WhatsApp/personal-profile boundaries, Google/Microsoft identity distinctions, approved impact reporting, private media evidence and separate giving/commercial payments.

## This release

- Remove new speaking-invitation entry points from contact choices, professional information and Start Here. Stale purpose links fall back safely; historical enquiries and documentary photographs are not deleted. The old exact saved meeting label is changed to Media & interviews without changing availability or duration.
- Use the approved blue/green/white logo in service emails, acknowledgements, replies prepared through the application, newsletters and native draft bodies. Keep mixed-case name/slogan, personalised greeting, website, handle, six existing social links, text alternative and unsubscribe controls. Do not rewrite original incoming third-party emails or previously frozen messages.
- Add an owner-only correspondence log for received website enquiries, acknowledgement state, recorded manual replies, encrypted outgoing emails and Gmail draft metadata. Saved message previews are sandboxed. Queued, provider-accepted, delivered and read are not synonyms; uncertain sends are not automatically repeated.
- Extend optional client contact completion with the existing country/county/phone component. Kenya/+254 defaults, 47 county choices, optional Sub-County and Town/Location, equivalent overseas fields and independent phone code are preserved. The encrypted client profile is excluded from article facts and AI prompts. Simple sign-in, newsletter and anonymous giving remain short.
- Consolidate public footer typography, Personal views notice, spacing, colour, three equal visitor columns, responsive alignment and tabular numerals. Existing consent-aware live presence and independently queried monthly/yearly totals remain intact.
- Add the monthly roundup described below, plus private controls and a public archive.

## Automatic monthly newsletter

The owner authorised automatic publication at midnight on the first day of each month, welcoming readers into the new month and reviewing the previous calendar month. The first release is **1 November 2026, 00:00 Africa/Nairobi**, covering October.

A native systemd calendar timer uses `OnCalendar=*-*-01 00:00:00 Africa/Nairobi`, one-second accuracy, no random delay and persistent recovery. The existing bounded worker provides catch-up processing. The timer invokes only `--monthly-roundup`; neither path enables unrelated newsletter, payment, social or historical outbox queues.

Sources are already-public website articles and meaningful public corrections, opportunities, employment listings still public, resources, published videos and approved public impact-report announcements. It is a website-publication roundup, not a claim to know every offline event or private activity. Public article links carry the details. Unpublished/private records, contact details, bookings, payment information and unapproved impact claims are excluded. Sponsored features are excluded unless newsletter permission is expressly recorded; purchased social rounds do not include an uncontracted newsletter placement.

The public edition records its source snapshot, policy fingerprint, planned midnight and actual publication time separately. It welcomes the new month, groups previous-month updates, labels closed calls and gives an honest empty-month message instead of invented achievements. Email highlights link to the complete archive when the source list is long.

The existing confirmed newsletter audience is frozen once per edition, with per-recipient eligibility, preferences, suppression and opt-out checks. New enquiries or clients are never automatically subscribed. One-click unsubscribe and personalised branded HTML/plain text remain. Later signups do not receive a duplicate backfill. A changed sender, expired catch-up window or changed public source can pause unsent delivery. A unique month key and durable campaign/outbox identity prevent duplicate editions and resends after uncertain outcomes.

Email scheduling starts at midnight; inbox arrival is controlled by mail providers and may be later. A valid configured sender, reply identity, postal information and functioning transport remain necessary. Readiness logs expose booleans and next-run time only, never recipient content or credentials. Deployment success is not proof of a future edition's inbox delivery.

## Routes

- Public archive: `/monthly-roundup`; individual editions: `/monthly-roundup/YYYY-MM`.
- Monthly policy, source preview and history: `/write/monthly-roundup`.
- Correspondence evidence: `/write/correspondence`.
- Requirements and remaining gates: `/write/reconciliation`.
- Optional client location: linked from the private feature's contact/delivery area; `/client/client-contact/{id}` or owner `/write/client-contact/{id}`.

## Still separate, not claimed complete by this batch

Live merchant setup and recurring commercial debit mandates; WhatsApp Cloud API sender/template/webhook approval; unverified social login/publishing/analytics grants; matching host mailbox grants; Postmaster activation; source-owned approved impact feeds; new Calendar push/Meet artifacts/Docs-Sheets exports/Tasks/BigQuery/Looker/YouTube moderation workflows; additional Microsoft and fitness/network integrations. These are retained in the register rather than dropped or falsely described as deployed.

## Acceptance

Baseline and added suites must pass on PHP 8.3 and 8.4 with disposable MySQL 8.4. Added tests exercise calendar/year/leap boundaries, public-only projection, prior-month selection, sender binding, source snapshot, duplicate prevention, frozen audience, opt-outs, unrelated-queue isolation, encrypted client contact and correspondence evidence. Old tests are updated only where the owner deliberately retired speaking invitations or required an actual email logo; security and existing feature regressions remain.

Production acceptance reads public routes, uniform footer CSS, retired contact choices, shared location controls and protected route responses, then repeats existing approved-logo/icon/database/live-visitor checks. It submits no genuine enquiry, customer payment, calendar invitation, newsletter or synthetic visitor heartbeat. Record exact deployed SHA and workflow outcome separately after release.
