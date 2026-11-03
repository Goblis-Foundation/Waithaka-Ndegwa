# Travel and partial-day booking availability

The owner can now enter private travel/time-off intervals in Communication Centre → Availability. Existing whole-day exclusions and live selected-calendar checks remain in place.

Each interval has start/end local dates and its own IANA timezone, stored as exact UTC instants. Changing the host timezone does not reinterpret saved blocks. Up to 20 intervals of at most 90 days are supported; identical instants are deduplicated. Blank start/end fields remove a row. Invalid dates, nonexistent spring-forward times and ambiguous fall-back boundaries are rejected rather than guessed. A block can span the daylight-saving transition using unambiguous boundaries.

The existing meeting preparation buffer applies around each interval. The same availability function checks new booking requests, owner approval and rescheduling. Existing bookings are neither cancelled nor moved. The owner must separately review conflicts with already accepted bookings. No travel route, location, distance or journey duration is inferred, and no Google/Microsoft event or notification is created by entering a block.

Records remain in the existing encrypted configuration. Saves run under the reservation lock. A block revision protects against stale edits; legacy forms without the new fields preserve current intervals. Malformed saved intervals fail closed instead of opening availability. Visitor responses contain available slots only, not the private block list or reasons.

Validation includes exact buffered boundaries, cross-midnight foreign zones, timezone-independent storage, DST rejection and spanning intervals, deduplication, size limits, stale writes, encrypted database round-trip and responsive production-template fixtures. Full CI and AWS production checks are required for release acceptance.

This completes manually specified travel/time-off blocking. Automatic routing, travel duration estimates, itinerary ingestion and automatic changes to working hours while abroad are not implemented. Provider permissions, real payment/delivery acceptance, approved impact data, physical devices and the explicitly deferred Postmaster remain separate items in the main audit register. No real travel block or meeting has been invented for production testing.
