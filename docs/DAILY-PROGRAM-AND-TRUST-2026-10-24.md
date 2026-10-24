# Daily program and renewable device trust — 24 October 2026

The portal home and Communication Centre include a daily program. The full planner supports date/week navigation, timed meetings/tasks/focus/travel/project/personal items, project tags, location coordinates, Google Maps search/directions, priorities, editable entries, conflicts, completion, snooze, cancellation reasons and running-late notices.

## Device trust
A separate 256-bit random bearer credential restores expired ordinary sessions on a recognised private device. The host-only Secure/HttpOnly/SameSite cookie is renewable after a day of active use, with a 180-day inactivity expiry. Only its SHA-256 verifier enters the encrypted settings record. Active sessions remain short lived; the remembered credential survives browser restart and application release. Sign-out, stable device revocation, account deactivation and password epoch changes prevent restoration. Optional user-selected idle locking still applies. Shared devices can opt out at sign-in.

## Calendars and notices
Google snapshots use only owned accounts and explicitly selected calendars. Microsoft, Zoom and Webex are included where supported connections exist. Refresh is explicit, or every 15 minutes after the owner opts in. Snapshots report source errors and incomplete/stale observations; no free-time inference is made. Website booking conflicts retain their existing live checks.

Google organiser events may be cancelled or given a lateness update only with an existing event-write grant, fresh snapshot, fresh provider read and ETag match. Google handles guest notifications. Read-only accounts keep an Open source event path. Microsoft/Zoom/Webex source views remain read-only; native cancellation uses the source service. A guessed duration is labelled as a planning placeholder.

Local program cancellation/late notices require a reason and an explicit recipient checkbox. Imported recipients are discarded. Website booking management supports cancellation reasons and lateness from both owner and attendee, using the existing private capability for the attendee. Neither local planning nor a program import silently creates an invitation. Ambiguous remote mutations and email outcomes require reconciliation instead of blind retries.

## Program interpretation
CSV, ICS and dated plain text are parsed locally. DOCX XML is bounded and external entities disabled; deterministic dated text is supported. PDF/images and optional Word interpretation use the configured Gemini model only after explicit file-specific consent. Raw originals are not persisted. Interpreted items are encrypted, reviewed and editable before selected entries are committed. Missing dates/times, ambiguous DST, unsupported recurring ICS and invalid intervals are not invented. Imports expire after a day and confirmed stages discard preview content. File digest/row keys prevent accidental repeated imports.

## Reminders
Opt-in Web Push uses the existing dedicated communication worker, not paused newsletter/social/payment queues. Reminder leads: 5, 10, 15, 30, 60 or 120 minutes; snooze: ten minutes before start. Quiet hours apply. Empty push payloads keep private meeting details off push services and lock screens. Device provider acceptance is not proof of delivery. Late/missed windows are bounded and there is no stale reminder flood. Browser/OS permission and installed Home Screen mode on supported Apple mobile devices remain necessary.

## Primary design sources
- OWASP: https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html
- Google Calendar: https://developers.google.com/workspace/calendar/api/v3/reference/events
- Maps URLs: https://developers.google.com/maps/documentation/urls/get-started
- Apple Web Push: https://developer.apple.com/documentation/usernotifications/sending-web-push-notifications-in-web-apps-and-browsers
- MDN Push API: https://developer.mozilla.org/en-US/docs/Web/API/Push_API
- Gemini document processing: https://ai.google.dev/gemini-api/docs/document-processing

## Acceptance limits
Offline/isolated database tests cover parsing, review gates, ownership, encrypted storage, expiry, password changes, revocation and idempotency. Real-device restart/notification acceptance, provider calendar mutation acceptance and AI interpretation with a real document require connected accounts/devices; no actual attendee notices or calendar mutations are performed in the test suite.
