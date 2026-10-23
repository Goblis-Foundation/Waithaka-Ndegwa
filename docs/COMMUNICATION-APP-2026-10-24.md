# Communication Centre and installable app — 24 October 2026

## Implemented

- Communication Centre: Today, website enquiry inbox/search, people and enquiry history, booking requests, availability rules and truthful booking-state counts; existing social, newsletter, founder, calendar and publishing workspaces remain accessible.
- Native public `/book` page: meeting types, host-date picker, editable visitor timezone, live selected-calendar checks, booking details and consent, instant confirmation or approval requests, private management links, cancellation and rescheduling.
- Separate optional `google_booking` OAuth grant. Requires an owned destination calendar confirmed by the owner. Google Calendar must allow its exact callback `/write/connections/callback/google_booking`. Existing eight read connections are not overwritten. Optional personal Outlook conflicts use calendarView, not unsupported personal-account getSchedule.
- Weekly working hours, date exclusions/travel blocks, notice, horizon, preparation buffers, daily limits and editable durations. Concurrent website reservations serialize under a database advisory lock and recheck remote availability. Pending requests and uncertain provider mutations block availability.
- Calendar event creation uses deterministic IDs, Google Meet conference requests and explicit invitations. Ambiguous mutations remain `review`; they are not blindly retried. Provider acknowledgements are distinct from email inbox delivery.
- Booking mail has personalised branded chrome and social/contact/preference footer. Dedicated encrypted jobs process only this booking service; legacy newsletter/social/payment queues remain paused. Worker opt-in is separate and defaults off. Uses the existing minute timer and instance identity, without new paid infrastructure.
- PWA manifest, install instructions, shortcuts, mobile navigation, offline fallback and update prompt. Service worker never caches authenticated HTML/API data or queues offline external actions. Explicit device draft copies can be saved/restored/cleared; these are opt-in browser-local text, not encrypted server drafts.
- Standard Web Push subscription and VAPID signing using empty payloads. Only fixed official FCM/Mozilla/Apple HTTPS endpoints accepted; redirects off. Generic privacy-preserving notifications route to bookings/inbox after a same-origin authenticated check. Quiet hours, categories, device removal and expired-endpoint status. Browser permission is always user initiated.
- AI reply, meeting-agenda and action-extraction modes in Studio use only explicitly supplied text. No automatic private inbox/calendar transmission to AI.

## Activation and acceptance still required

Booking remains closed until owner selects hours/types/conflict calendars, grants the separate Google write permission, confirms an owned destination, and enables booking service notifications. These choices are not inferred from existing read grants. Current user can configure them in Communication Centre > Availability.

Push delivery requires browser support, an installed Home Screen app on relevant iOS versions, explicit browser permission and owner notification preferences. Device delivery, physical phone/tablet layouts and inbox placement require real-device acceptance; automated tests cannot establish them.

The combined centre has separate optional Gmail read and draft connections. Inbox reads are bounded and explicit; no message-body replication, automatic marking, attachment fetching, HTML tracking images or Gmail sending endpoint is implemented. Gmail drafts include branded HTML and plain-text alternatives. Gmail API enablement, exact callbacks, owner consent and any required Google verification remain external activation dependencies. Unsupported social DMs are not silently imported. Instagram private reads remain explicit in the existing engagement workspaces. Facebook remains a personal professional profile. Zoom/Teams/Webex creation remains native-provider work; this release automatically creates Google Meet only.

Passkey enrollment and discoverable sign-in use the locked lbuchs/webauthn library, required user verification, exact origin validation and expiring one-use session challenges. Existing-password verification is required to add/remove keys. Biometrics/device PIN are handled by the authenticator; the website never receives biometric data. Session inventory covers sessions observed after this release, supports revocation, and offers an optional inactivity lock (off by default). Actual passkey enrollment/device acceptance remains owner-controlled and unverified until exercised.

## Storage and operational boundaries

Single-owner records use distinct encrypted rows in the existing `settings` table, avoiding a production schema/privilege expansion. Prefix reads are capped: 2,000 bookings, 200 devices and 10,000 booking mail records. Exceeding caps fails closed and requires archival/migration. Advisory locks, per-record state and deterministic provider IDs prevent duplicate website reservations; Google calendars do not provide atomic reservations against independent external edits.

No live test booking, meeting invitation, social post, newsletter batch or push permission is created merely by deployment. Notification keys are generated privately on first App settings use. Secrets and private calendar/event bodies are absent from this document.

## Research references

Calendly scheduling/help, Cal.com API v2, Front shared inbox, Brevo Conversations, HubSpot Conversations, Buffer Community, Google Calendar events/freeBusy, Microsoft Graph calendarView/getSchedule, WCAG 2.2, Apple Web Push/WebKit Home Screen requirements. Current-source research completed in the conversation on 24 October 2026.

## Live acceptance

Core release fdec060 deployed successfully. Live Writer confirmed Today, Availability, App settings, update checks, Devices & passkeys and a dedicated booking-worker heartbeat. All eight existing Google accounts were selected for conflict checks and five proposed meeting types saved. Public booking remains closed pending destination authorization. Device alerts remain off; no browser permission, passkey enrollment or external invitation was triggered.

Gmail release 1e8cf07 passed all 55 suites on PHP 8.3 and 8.4/MySQL 8.4. Live external Gmail reads/drafts remain unverified without their separate grants. Google Cloud console returned Site Unavailable in this browser. Owner must register the exact additional callbacks shown in Connections for google_booking, google_gmail_read and google_gmail_draft, and enable Gmail API for mail features.

Final review removed a nested public main landmark and narrowed the booking grant from full calendar read to free/busy plus owned-event access. These changes do not widen existing connections.

Reliability acceptance: delayed Google Meet conference links are refreshed by the scoped worker with bounded, identity-checked reads. First installation never forces a reload; an update activated from another tab asks before reloading unsaved work. An offline JavaScript lifecycle fixture covers these reload boundaries.
