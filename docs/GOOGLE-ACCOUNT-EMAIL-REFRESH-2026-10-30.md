# Verified Google account email refresh — 30 Oct 2026

The owner requested `isaac@ubunifuinstitute.co.ke` → `isaac@ubunifuinstitute.com`. The old address is currently a saved Google Calendar connection label, not a hardcoded website address. A blind label replacement would misrepresent the returned provider identity, and treating it like the separate AfriTrace/Foundation alias retirement would incorrectly discard a grant.

Connected calendar accounts now have an Update a renamed account disclosure. Enter the desired primary email and verify it through the existing Google grant. One fixed read-only OpenID userinfo call must return the same immutable Google subject and the exact requested, verified email. Only the account display name changes, guarded by account ownership, status, prior label and encrypted-credential compare-and-set. Tokens, scopes, selected calendars, calendar identifiers, verification dates and other accounts remain intact. A different subject, old address, unverified email, malformed response or concurrent update stops the rename.

This does not rename a mailbox at Google, add a login alias, authorize a different Google identity, rewrite historical bookings or change email delivery. Google must first return the new primary address for that same account. Existing calendar IDs and source calendar names remain provider values.

Verification includes pure identity validation, database account isolation, same-subject refresh, unchanged grants/destinations, concurrent updates and a production-template responsive/accessibility fixture. Full CI and production evidence belong on the PR; no live rename is claimed before provider confirmation.

The existing read-only Search Console review was independently exercised on production on 30 Oct at 20:52 EAT: two finalized equal 28-day windows returned eligible page samples through the existing selected property. Results remain private; this is not a complete traffic total or proof that every page has no issues. No content was edited or indexing request submitted.

The existing Google Analytics global-readership report also returned its graph and table through the authorized property. No private metric values, tokens, query terms, calendar events or report contents are copied into this public release record.
