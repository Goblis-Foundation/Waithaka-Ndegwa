# Reviewed Microsoft actions

Owner-only routes: /write/microsoft/actions, /new and /{32-hex-action-id}. Existing read grants, aliases and booking-conflict calendars are retained.

## Implemented
- Separate optional User.Read/offline_access/Mail.ReadWrite grant for native Outlook drafts; no Mail.Send scope or send endpoint. The personal Microsoft mailbox is not the Gmail inbox.
- Separate optional User.Read/offline_access/Calendars.ReadWrite grant for single-meeting creation, rescheduling and cancellation. Existing read account ID must match the returned write account ID. Each grant requires explicit destination confirmation and can be removed independently.
- Prepare an encrypted local immutable plan, preview the frozen branded content, explicitly review, then explicitly apply. The exact frozen payload, account ID, meeting snapshot and grant fingerprint are bound to approval. Reviews expire after 30 minutes; changes require a new plan or fresh review. CSRF, owner checks, version checks and advisory locks protect actions.
- Drafts include the established email header, personalised salutation, Waithaka Ndegwa signature, social links and subscription information. Native creation is independently read back for draft state, subject, recipients and normalised body text. Finish sending in Outlook.
- Single future meetings support selected attendees, Nairobi dates/times, location, reminders and an optional Teams request for an eligible organisation calendar. Teams provider eligibility is read from the actual calendar.
- Rescheduling/cancellation loads only active single meetings organised by the signed-in user. Series are handled in Outlook. Current event revision and attendees are checked immediately before mutation; rescheduling includes If-Match and preserves the existing body/online meeting information while appending the reason.
- Cancellation sends the reviewed reason through Microsoft. Acceptance is labelled as provider acceptance, not recipient delivery.
- One-use application persists executing before the request. Unknown/timeout/5xx or failed read-back outcomes never automatically retry. Completed, rejected, stale and discarded records remain in the private audit trail. Calendar creates have stable transaction IDs. Application does not run from a background worker.

## Limits
No alias sending, mailbox sending, recurring-series edits, SharePoint/Office editing, Teams chat, Planner, Power Automate or Copilot operations are implemented by this release. A native draft is not a sent message. Calendar action notifications require the explicit Apply step. This manual action studio does not reserve public booking slots; use the combined agenda to review conflicts. History currently retains at most 500 actions.

## Validation
Pure fixtures exercise grant isolation, exact approvals, expiry, replay, ambiguous outcomes, independent read-back, organiser ownership, revision changes, original body preservation, Teams eligibility and canonical identity matching. Real local HTTP tests cover owner/auth/CSRF gates, encrypted preparation, sandboxed previews and missing write permission. No real draft, invitation, meeting, cancellation or email is created by automated tests.

Primary references: https://learn.microsoft.com/en-us/graph/api/user-post-messages ; https://learn.microsoft.com/en-us/graph/api/calendar-post-events ; https://learn.microsoft.com/en-us/graph/api/event-update ; https://learn.microsoft.com/en-us/graph/api/event-cancel
