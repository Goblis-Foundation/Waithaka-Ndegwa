# Lifecycle release acceptance

## Required before production completion

- All application, browser, guide, curated-content and lifecycle PR checks pass on the exact reviewed head.
- Temporary source preparation and integration workflows are absent from the final diff.
- Production uses the existing protected AWS release workflow, with no hosting migration or credential changes.
- Post-deployment read-only checks inspect sixteen public pages at mobile and desktop widths, current/closed/archived application behaviour, optional support, archive filtering and private owner-review access.
- Archive handling is tested at the 90-day boundary without waiting for a real listing to expire. The production directory retains original historical URLs and excludes archived entries from ordinary discovery and the current sitemap.
- Disposable database fixtures use future dates independent of real opportunities; pure policy tests separately cover actual date precision and elapsed deadlines.
- Source failures preserve previous evidence and never publish a change. Saved evidence remains paired with its successful source URL.
- AI analysis is limited to public listing/source text and 32 analyses per UTC day; missing configuration is reported, not hidden. A literal supporting excerpt and explicit owner approval are required before a same-round deadline revision is published.
- Public revision metadata is written only after the approved native public snapshot succeeds. Existing private editor work, manual closures, withdrawals, slugs and publication dates are protected.

No automated application submissions, mail campaigns, social dispatches, payments or permanent deletion are part of this release. Additional researched opportunities are a separate content release.
