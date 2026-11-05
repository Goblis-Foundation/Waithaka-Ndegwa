# Opportunity lifecycle and source review

Owner authorisation: 4 November 2026, following the approved closure, extension-review and 90-day archival proposal.

## Public behaviour

* Exact deadlines close at their recorded instant. The view evaluates expiry even between worker runs.
* Date-only deadlines retain their original precision. The latest worldwide end of that civil date (the following date at 12:00 UTC, corresponding to UTC-12) is an internal, conservative editorial expiry boundary, never an asserted organiser deadline. Readers are told that the published date has passed.
* An undisclosed or rolling deadline is not fabricated. It stays subject to source review.
* Closed pages leave current results immediately. After 90 days they leave ordinary directory views, the current publishing sitemap and default recommendation collections. An explicit Archived filter and the original historical URL remain available. No permanent deletion is implemented.
* Both the main application action and explicit application invitations in the article body are suppressed on closure. Historical source references remain readable. Optional reader support remains separate.

## Official-source review

The existing production systemd worker runs the new bounded review stage independently of the legacy delivery switch. Read-only public HTTPS checks run daily, six-hourly in the last seven days, daily for fourteen days after closure, then weekly until archival. Each pass checks at most two listings; backlog and source/provider failures are visible in the owner dashboard. Requests pin public IPv4 addresses, validate TLS, bound response size/time and allow only same-host redirects. PDF-only, blocked, dynamic or unavailable sources require manual inspection; a failed fetch is never a deadline extension or closure.

Only saved official public text and the listing's public facts are sent to the site's existing configured AI provider. There are no private applicant records in the prompt. AI is bounded to 32 changed-source analyses per UTC day, with no blind immediate retry. If the provider key or quota is unavailable, deterministic lifecycle and source checking continue, while the dashboard reports the AI limitation.

AI findings are suggestions, not publication authority. A supporting quote must occur verbatim in a saved source. Changes to fees, eligibility, funding or application URLs require normal editorial revision. Deadline proposals require the owner to verify source evidence and the same application round, then explicitly approve the exact update at `/write/desk/reviews`. This route preserves publication dates, URLs, private drafts and the native approval digest. Confirmed extensions reopen deadline-closed records and reset retention; manually closed and withdrawn records cannot be reopened by this workflow. A new cohort needs a separate listing.

## Audit and safety

Private encrypted records retain check times, source hashes, current/previous text, AI status and proposals. Public revision history identifies the official source and reviewed deadline. No application submission, email dispatch, social send, payment, hosting migration, secret exposure or permanent deletion is added. The existing support route is unchanged. Source dates marked as reported retain their uncertainty.

Tests cover exact/date-only bounds, archive timing, unknown dates, cadence, private-draft preservation, source failures, AI evidence validation, manual closures, withdrawal protection, native approval/publication and no outbound queue changes. The dedicated live checks are read-only.
