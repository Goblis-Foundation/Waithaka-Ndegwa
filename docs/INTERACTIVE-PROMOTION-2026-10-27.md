# Interactive promotion journey — 27 October 2026

Owner request: make `/promote` and its process interactive, not merely a long form and passive package cards.

## Changes

- Five-step brief builder: Package; Business; Offer; Contact and files; Review and send.
- Native selectable package cards, persistent selected-scope summary, exact existing prices and post counts, and explicit four-week/28-day language.
- Back/Continue navigation, accessible progress and focus, per-step validation, HTTPS destination guidance, attachment size/type/permission feedback, and editable full review before sending.
- Text and selected package survive step changes and server validation returns. File selection must be repeated after a server response; unsent details are not silently persisted to browser storage.
- A duplicate-click guard and back-forward-cache recovery. Only the final native form submission contacts the existing intake route.
- Expandable process explanations and an improved submission receipt with next steps. No quotation acceptance, charge, review approval or publication occurs by selecting a package or reading a step.
- Existing private client workspace gains an evidence-led next-action panel and expandable status stages linking to quotations/payments, article review, files and delivery evidence. Exact approval digests and the matching current order are used; uncertain payments and scheduled social jobs are not presented as completed.

## Preserved contracts

No price, package, merchant, billing provider, permission, deployment-account, marketing-consent or source-data change. Retain existing CSRF, single-use nonce, server validation, rate limits, encrypted records, private workspace links, file handling, approval/revision checks, commercial activation gates and no-contribution policy on sponsored content. This is a presentation enhancement over the existing workflow, not a parallel billing or publishing engine.

The form is fully visible and submittable without JavaScript. Process and private status explanations use native details/summary controls. The interactive review uses textContent, never client-supplied HTML. There is no external script dependency, browser storage, AI request, tracking event or preview network call.

## Verification

Local in-memory Chromium checks: 34 passed, covering desktop/mobile interaction, required fields, HTTPS validation, unsupported attachments, permission, retained values, safe review rendering, one native-form serialization, duplicate-click guard, back-forward restoration, server-error recovery, receipt state and no-JavaScript fallback. Widths checked: 360, 390, 768, 1024 and 1440 pixels. These used isolated rendered fixtures, not the authenticated production workspace or a real submission.

`tests/promotion_experience_unit.php` adds repository-backed render and evidence-state regressions to the existing Composer test command. The full suite must pass on PHP 8.3 and 8.4 with MySQL 8.4 before merge.

The existing production acceptance chain calls `verify_promotion_release.py`: it reads `/promote`, verifies the five real form sections, package inputs, protected fields, privacy headers and deployed CSS/JavaScript. It makes no POST, sends no customer email, and triggers no payment or approval. Existing release checks are retained, not weakened.

Implementation and deployment are distinct. Record the actual candidate SHA, test result and successful production run after those checks complete.
