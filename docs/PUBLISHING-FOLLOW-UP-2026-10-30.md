# Publishing follow-up — 30 October 2026

The publishing summary previously omitted accepted and in-flight deliveries and counted only the attention-ordered 100-row sample. That sample could also hide older completed records. The deployed adapters and external activation requirements remain separately tracked.

The overview now counts all saved delivery records in five explicit groups. Awaiting publication includes accepted and in-flight states; it never increments completed results. Unknown stored states remain in Need a step. Read-only GET filters select status and channel, with 50-record pagination and recovery for invalid inputs and out-of-range pages. Scheduled work is ordered earliest first after actionable and processing records. Queries bind channel/status values; filters and pages perform no provider calls or delivery mutations.

The existing PHP suites cover malformed inputs, unknown states, and real authenticated HTTP/SQL behavior with 102 historical fixture jobs, including page three, preserved filters, empty results and unchanged accepted status. Production-template browser and accessibility fixtures cover all five summary links, form controls, narrow layouts and empty recovery. CI and production evidence are recorded on the accompanying pull request.

Provider consent, real destinations, sender details, settlement, source publication and physical-device acceptance remain open in the existing register. This release does not send posts, emails or charges.
