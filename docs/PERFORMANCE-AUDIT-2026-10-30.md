# Public performance measurements — 30 Oct 2026

The independent GitHub Public performance audit measures Home, Start Here, My Thoughts and Contact with simulated mobile and desktop settings. It uses Lighthouse 13.6.0 in fresh, anonymous Chrome profiles and blocks browser requests other than GET/HEAD. It does not sign in, submit forms, accept cookies, purchase, publish or read a private workspace. No API key, new provider grant, analytics consent or PageSpeed API quota is needed.

Each audit retains the full Lighthouse HTML/JSON, measured URL/time/tool version, category scores, LCP/FCP/TBT/CLS/Speed Index, prioritized audit recommendations and a Markdown summary for 14 days. The audit-code commit is explicitly distinct from the deployed website version. Exact URL mismatches, HTTP failures, runtime errors and incomplete metrics fail the job. Low scores and review-budget breaches remain measured warnings instead of being hidden or converted to passing field performance.

Pushes affecting only the audit implementation start the fixed public measurements; PR events run offline contracts only to avoid duplicate measurement. A manual workflow dispatch reruns the same allowlisted eight measurements. There is no additional recurring schedule and no extra wait on the production deployment job. Results from a branch audit describe the currently live site, not undeployed branch changes.

A Lighthouse score is a lab signal. Simulated mobile is not a physical phone; TBT is not INP; a sample is not a CrUX 75th-percentile result. Physical devices, assistive technologies, passkeys/push, traffic-based field metrics, exact provider grants and genuine social/payment/email outcomes remain separately tracked in the retained register and issues #71–76.

Implementation follows the pinned primary-source programmatic API and desktop configuration:
- https://github.com/GoogleChrome/lighthouse/blob/v13.6.0/docs/readme.md
- https://github.com/GoogleChrome/lighthouse/blob/v13.6.0/core/config/desktop-config.js

Initial measurements and resulting software corrections will be recorded on the accompanying PR; this document does not invent a baseline score.

## Continuation evidence

The preceding contact/domain release (PR #119, commit `718d485b2c802fa507849b7a36c2028215202aca`) passed all three production jobs in run `41085190532`. Live inspection verified phone/country visibility, active required fields, international switching and the `afrisoltech.com` footer credit. The dashboard register is updated to reflect the deployed contact requirements and individual owner-review rule.

A fresh bounded anonymous source observation on 30 October returned Group HTTP 403 and Ubunifu/AfriTrace/Foundation HTTP 503. These statuses do not establish a cause, approved data or numerical impact values. Provider credentials/consents, approved sender address, authentic impact reports, settlement evidence and physical-device acceptance remain pending.

Offline Node audit contracts pass. Initial GitHub jobs were queued before execution; the isolated local fallback could install the pinned tools but Chromium could not open its debugging socket (`Operation not permitted`). No performance score or optimization result is claimed from that failed attempt. Full runner execution is required before accepting the new audit implementation.
