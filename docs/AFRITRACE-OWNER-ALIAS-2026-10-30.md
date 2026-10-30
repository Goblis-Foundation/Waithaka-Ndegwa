# AfriTrace address: canonical Foundation alias

The owner confirmed that `isaac@afritracelink.com` is now an alias of `isaac@goblisfoundation.org`, and requested removal of the standalone identity.

- Alias sign-in resolves to the existing owner and canonical Foundation verification address. It does not add a separate administrative user or verification-address choice. Existing password, second-factor and provider-subject checks remain.
- Microsoft recognises the address only as an owner-confirmed alias in its existing Foundation slot. Alias sending is not inferred.
- New independent Google/calendar or booking-sender grants using this alias are rejected. The Foundation identity must be returned and confirmed instead.
- The deployment reconciliation retires matching saved Google calendar/grant records and booking mailboxes locally, clears their credentials and selections, and removes their cached agenda entries/notification bindings. It never revokes the Google project or calls a provider. The canonical connection, unrelated accounts, provider-owned events, historical bookings and original sender traces remain in place.
- Exact already-selected calendar IDs can continue through an already-confirmed canonical account. If an old alias was required for booking conflict checks and its coverage cannot be established, new booking acceptance is paused for review instead of silently weakening conflict checks. Existing bookings remain unchanged.
- A duplicate standalone login is disabled without deleting its historical user data. If the owner itself has the old primary address, its ID remains stable and its email changes only when there is no uniqueness conflict. No private records are copied across user IDs. Retired sign-in bindings require fresh proof through the canonical address.

The local operation is idempotent and transactional, serialized by an advisory lock. The postdeploy hook enforces no active alias calendar or mailbox credentials remain. Its summary exposes counts only. No DNS record, external mailbox, alias forwarding rule, calendar event, email or publication is changed.
