# Public performance measurements — 30 Oct 2026

The independent GitHub Public performance audit measures Home, Start Here, My Thoughts and Contact with simulated mobile and desktop settings. It uses Lighthouse 13.6.0 in fresh, anonymous Chrome profiles and blocks browser requests other than GET/HEAD. It does not sign in, submit forms, accept cookies, purchase, publish or read a private workspace. No API key, new provider grant, analytics consent or PageSpeed API quota is needed.

Each audit retains the full Lighthouse HTML/JSON, measured URL/time/tool version, category scores, LCP/FCP/TBT/CLS/Speed Index, prioritized audit recommendations and a Markdown summary for 14 days. The audit-code commit is explicitly distinct from the deployed website version. Exact URL mismatches, HTTP failures, runtime errors and incomplete metrics fail the job. Low scores and review-budget breaches remain measured warnings instead of being hidden or converted to passing field performance.

Pushes affecting only the audit implementation start the fixed public measurements; PR events run offline contracts only to avoid duplicate measurement. A manual workflow dispatch reruns the same allowlisted eight measurements. There is no additional recurring schedule and no extra wait on the production deployment job. Results from a branch audit describe the currently live site, not undeployed branch changes.

A Lighthouse score is a lab signal. Simulated mobile is not a physical phone; TBT is not INP; a sample is not a CrUX 75th-percentile result. Physical devices, assistive technologies, passkeys/push, traffic-based field metrics, exact provider grants and genuine social/payment/email outcomes remain separately tracked in the retained register and issues #71–76.

Implementation follows the pinned primary-source programmatic API and desktop configuration:
- https://github.com/GoogleChrome/lighthouse/blob/v13.6.0/docs/readme.md
- https://github.com/GoogleChrome/lighthouse/blob/v13.6.0/core/config/desktop-config.js

Initial measurements and resulting software corrections will be recorded on the accompanying PR; this document does not invent a baseline score.
