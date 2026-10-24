# Approved monthly impact aggregates

Implemented 24 October 2026 for Waithaka Ndegwa's public platform.

The site consumes dedicated read-only aggregate endpoints, not company or beneficiary records. Source systems approve publication before export. The personal platform does not expose credentials, enterprise records, payroll, names, contacts or raw evidence.

## Version 2 contract

Required common fields: schema_version 2; exact source identifier; period_start 2026-07-01; period_end 2030-06-30; definition_version 2030-v1; reporting_frequency monthly; report_id (opaque, at most 80 identifier characters); positive integer revision; approved_for_publication true; publication_status approved; measurement_period_start and measurement_period_end (the first and last date of a complete calendar month); measurement_timezone (IANA); approved_at and published_at (explicit ISO 8601 timestamps); metrics (only the source's approved aggregate keys).

Measurement is the source's monthly snapshot, not its publication date or the website's polling date. The existing definitions remain: qualifying enterprises are cumulative since July 2026 and permanent employment is headcount at the measurement cutoff. Do not sum monthly snapshots. Optional indirect beneficiary counts still require an approved public methodology and deduplication declaration. Unknown top-level fields and metric keys reject the whole v2 payload.

Approval and publication must follow the end of the measurement month. Publication cannot precede approval. Invalid dates, unclosed months, future timestamps beyond five minutes of clock tolerance, invalid timezones and out-of-window periods reject. No new API credentials or paid infrastructure are introduced.

## Monthly freshness

A measurement month remains current until the following month's report is due, through the 15th day after that following month ends, in the measurement timezone. September 2026 measurements remain current through 15 November 2026 at 23:59:59 Africa/Nairobi when that is the source timezone. At the next second, they become monthly-update-overdue. The final June 2030 measurement month has no subsequent monthly deadline.

Changing published_at or repeatedly fetching an old report never resets this deadline. A delayed publication can already be overdue. Unreachable sources retain the last approved report, with connection health shown separately from measurement freshness. Missing or unapproved values remain unavailable, never fabricated zeros.

## Delivery, corrections and withdrawals

The site stores attempted_at (last connection check), last_success_at (last successful accepted delivery) and received_at independently from source dates. Writer displays measurement, approval, publication, receipt and connection dates separately. Public reporting displays measurement and publication separately; connection failure has its own label.

For a correction to the same measurement period, retain report_id and increment revision; changed payloads at the same revision, revision rollback, older measurement snapshots and downgrade from v2 to v1 reject. Repeated identical deliveries are accepted without changing publication dates.

To revoke approval, send the same report ID and measurement month, a higher revision, approved_for_publication false, publication_status withdrawn and the withdrawal's published_at. Omit metrics, beneficiary_basis and beneficiary_deduplicated; approved_at can be omitted. This is a control notice with no aggregate data. The public figures are removed. The withdrawal revision prevents an older approved payload from reappearing. A later approved higher revision can restore publication.

## Legacy compatibility

Schema 1 approved feeds remain accepted and normalized with a retained approval flag. Their as_of stays the measurement timestamp; no publication or approval date is fabricated. They retain the 48-hour measurement rule until upgraded. Old cache records with no retained approval marker remain hidden until the next valid source delivery. A v1 report cannot overwrite an accepted v2 source.

## Verification

Tests cover genuine zeros, strict aggregate fields, monthly deadlines, connection failures, publication lag, unchanged polling, corrections, rollback/downgrade prevention, withdrawals and replay, malformed dates, timezone offsets, leap months and the final reporting month. Endpoints and source read-only tokens still need to be supplied by the organizations; this change does not claim live impact results.
