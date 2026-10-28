# Module audit release acceptance — 28 October 2026

Implementation: [PR #84](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/84).
Merged application revision: `8c673d424c584c4cd528d0b51603c54acff507aa`.
Scope and outstanding commitments: [module audit](MODULE-AUDIT-2026-10-28.md).

## Pre-release evidence

Exact reviewed head `282ba3c5aebb8c94ecfe7bb8fb81396e2fae0c88` passed both PHP 8.3/8.4 + MySQL 8.4 jobs, dependency auditing, CodeQL/Actions analysis and experience-browser checks before merge. The application runner reported 80/80 suites passed, with additional commercial, visitor-presence, branding, newsletter and public-journey suites also successful.

The browser run passed 25 interaction checks, 19 journey-review checks, 349 public responsive checks, 874 site-wide template checks, 351 section-navigation checks and 177 new module/login/register checks. The new checks cover seven actual templates at ten widths, retained login choices, passkey cancellation and duplicate-start protection, the no-JavaScript fallback, and preservation of the seven open acceptance issues. Rendering warnings are fatal in the module fixtures. Layout measurements wait for the browser to settle after viewport resizing.

## Production acceptance

Deployment [40725219845](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40725219845) passed its application-validation and AWS deployment jobs. Elastic Beanstalk reached Ready/Green on the requested release at 16:27 UTC after the encrypted RDS recovery-window check. Production verification passed for 34 public routes, protected workspaces, shared forms, approved branding, PWA identities and database health. The following additional observations were made against the deployed application at 19:27–19:29 EAT:

- Anonymous `/write/login` returned HTTP 200 with the redesigned form, password-manager autocomplete, ordinary POST fallback, `private, no-store, max-age=0` caching and noindex headers/meta. All three login/security assets returned HTTP 200.
- Operations changed from two missing migration receipts to **Migration checksums match this release**. Database queries succeeded. The worker still reported **Paused / Disabled**, and the two pending emails remained unsent.
- `/write/program`, the commercial desk, orders, settings, Impact, the content calendar and the implementation register each had one main landmark and one H1. No page overflow remained at the inspected 1363 px viewport.
- The content calendar highlighted only **Editorial & content calendar**, with no erroneous Inbox highlight.
- The implementation register retained all 28 work areas. Searching for monthly newsletter found its entry; the follow-up filter retained links to every issue #70–76, and clearing the filter restored all entries.
- The apex `.com` and both `.co.ke` forms redirected to the canonical `https://www.waithakandegwa.com/`. Sitemap and RSS endpoints returned HTTP 200 with XML content types. These alias/feed checks preceded the release; the deployment also reruns its production checks.

The Operations canonical-address configuration warning remains a reminder to verify the deployed edge; the public redirects were independently verified. Responsive fixture checks are not physical-device or field-performance measurements. The register's release-verification requirement for the new fixes is evidenced here; it does not close separate device/provider acceptance.

## Continuing boundaries

The final read-only live-browser acceptance job also passed, so all three jobs in production workflow 40725219845 completed successfully.

No genuine email, social post, charge, account conversion or new OAuth grant was performed as an acceptance test. Physical-device, assistive-technology, provider-entitlement, live settlement, approved impact-source and sender/delivery acceptance remain in issues #70–76. A successful software release does not close those issues.
