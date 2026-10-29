# Goal 2030 — approved-impact source availability observation
**Implementation date:** 29 October 2026.

## Purpose and authority

The personal website maintains Goal 2030 progress only from independently approved, dated, source-owned monthly aggregate reports. The four controlled source repositories provide schema-v2 read-only exporters but an exporter implementation alone does **not** mean that a current signed/approved production artifact has been published. A missing source report is unavailable, not zero. A private API token or a source URL must never be committed to GitHub.

This tool is **a public availability observer, not an approval gate or data ingestion worker**. It checks four hardcoded, owned/public HTTPS candidate routes and records only their availability classification, HTTP status and UTC observation time. Nothing fetched is written to the consumer's production database or exposed in the website's public Goal 2030 counts.

## Source ownership and supported routes

| Source authority | Candidate read-only route | Allowed metric authority |
|---|---|---|
| Goblis Group | `https://www.goblisgroup.com/api/public/impact/waithaka/` | Cumulative qualifying startups; month-end permanent staff |
| Ubunifu Institute | `https://www.ubunifuinstitute.com/api/public/impact/waithaka/` | Incubated social enterprises; optional verified jobs |
| AfriTrace Link | `https://www.afritracelink.com/api/public/impact/waithaka/` | Distinct-enterprise market access, primary measurement |
| Goblis Foundation | `https://www.goblisfoundation.org/api/public/impact/waithaka/` | Corroborating market access, never summed with AfriTrace |

A candidate route's exact path must be verified through the source's production edge/release configuration. A 301/302 is not followed; it is reported separately to avoid querying unrelated hosts. Anonymous 401/403 responses signify that a separate, owner-approved read-only authorization is needed. A 503 is expected when an approved publication artifact is not available. Responses are bounded to 64 KiB. No response body, error message, report ID, metric, source approval evidence or token enters artifacts or logs.

## Workflow and interpretation

- GitHub Actions: **Approved-impact source availability**, a read-only schedule at 07:05 Africa/Nairobi (04:05 UTC), with manual and exact-change CI runs. GitHub schedules can be delayed and are not guaranteed at the precise minute.
- Script: `deploy/aws/observe_impact_sources.py`.
- Tests: `tests/observe_impact_sources_test.py`, entirely local and synthetic.
- Evidence: `waithaka-impact-source-availability` artifact with `report.json`, retained 14 days.
- Status values: `unreachable`, `route_not_ready`, `redirect_not_followed`, `authorization_required`, `publication_not_available`, `unexpected_content_type`, `response_too_large`, `invalid_json`, `invalid_contract`, `source_withheld`, `approved_wire_report_seen`.
- **approved_wire_report_seen only means** anonymous HTTP returned minimal schema-v2 fields, a matching source ID, a publication flag and aggregate container. It does not prove approval signatures, deduplication, complete definitions, source persistent storage, consumer acceptance, or a public published result. Live-authority and publication must be verified through the independent source system and Waithaka's existing `impact_validate` and revision/withdrawal mechanisms.

An unavailable route or missing approval is **not** a CI failure; the status is recorded for investigation. This monitor does not open or close GitHub issues automatically, reauthorize an account, approve/withdraw a monthly report, create a financial transaction, publish an article, or send an external message.

## Completion and handoff

Issue [#74](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/74) remains open until the four source owners have supplied approved reports, production route/storage identity and exact metric definitions, the existing consumer `/write/impact` checks have read and accepted them with correct reporting/approval/publication dates, and approved public figures have been verified without sensitive records or double counting. The owner-only Writer configuration is covered separately by PR #93. No guessed totals, synthetic report artifacts or copied production keys are allowed.

This release does not change the 1 November monthly website roundup or activate newsletter delivery, existing email backlog or personal social posting.
