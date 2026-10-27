# Verified production acceptance — 27 October 2026

## Exact release

- Application commit: `7be64187e51af1f748aac38eea71ce4ac4a65645`.
- Main implementation: PR62; narrow contact search/social metadata correction: PR63.
- Production workflow: `40535038415`.
- Application validation job: `122297875497`, success.
- AWS deployment job: `122299234596`, success, completed at **2026-10-27 15:59:56 UTC / 18:59:56 Africa/Nairobi**.
- Target remains AWS account `466401734428`, region `af-south-1`, environment `waithaka-ndegwa-production`.
- Both PHP 8.3 / MySQL 8.4 and PHP 8.4 / MySQL 8.4 candidate checks succeeded in run `40534476815`.

The AWS installation, existing Workspace mail-relay application, homepage/database smoke test and the combined production acceptance step all passed. The final acceptance retained its original requirements; the earlier contact metadata failure was fixed rather than ignored.

## Verified release scope

The combined production check confirms the public monthly archive, removed speaking-invitation wording in rendered contact/professional/Start Here pages, country/county/region/phone controls, shared uniform Personal views and visitor-counter footer rules, protected monthly/correspondence/register routes, private client-contact boundary, approved logo hash, finished icon dimensions, database health and refreshed visitor aggregates.

The application also includes the owner-only correspondence evidence log, shared approved-logo email layout, private client location completion, monthly publication policy and a 20-area requirements register. Existing portraits, opportunities and sponsored-feature approvals, separate commercial activation and consent-aware visitor measurements remain preserved.

These are application, automated-test and HTTP acceptance results. They do not certify every email client's visual rendering, grant missing third-party scopes, prove a customer payment or prove delivery of a future newsletter.

## Monthly newsletter schedule and remaining email prerequisite

The installed native timer is calendar-anchored to **00:00 Africa/Nairobi on the first day of each month**, beginning **1 November 2026** and reviewing October's public website updates. The website publishes a monthly welcome and roundup. Email processing uses a single frozen campaign and the existing confirmed eligible newsletter audience, with suppression/preferences/unsubscribe and duplicate-send controls.

Website scheduling and successful subscriber delivery are separate. **The approved postal address and complete newsletter sender configuration still require confirmation before email delivery can be treated as ready.** No address has been invented or silently substituted, and no test newsletter was sent to real subscribers. A functioning SMTP relay alone does not satisfy every newsletter-sender prerequisite. Recipient inbox arrival is not guaranteed at precisely midnight.

Private correspondence, contact details, bookings, payment records, unpublished drafts and unapproved impact information are not newsletter source material. Paid placements are not silently expanded into newsletter advertising.

## Locations

- `/monthly-roundup`: public archive.
- `/write/monthly-roundup`: automatic policy, public-source preview and edition history.
- `/write/correspondence`: private acknowledgement, outgoing-email and draft evidence.
- `/write/reconciliation`: preserved requirements and remaining gates.
- `/write/desk`: opportunities, sponsored features and client contact completion.

## Reconciliation limits

The full 26 October work-chat transcript was not accessible. The audit uses the instructions visible in this chat, recovered work context and dated implementation records. It must not be described as an exhaustive review of an unavailable transcript.

External merchant setup, WhatsApp sender/templates/webhooks, unverified social/mailbox grants, source-owned approved impact feeds and the additional Google/Microsoft/network capabilities listed in the register are retained as outstanding work. This successful deployment is not a claim that every account-dependent or future capability has been activated.

See `RECONCILIATION-2026-10-27.md` for the full implementation and acceptance details. This documentation-only follow-up does not change the deployed application or trigger another production release.
