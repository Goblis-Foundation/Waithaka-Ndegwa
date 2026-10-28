# Administration, authentication and productivity release acceptance — 28 October 2026

Implementation: [PR #86](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/86).
Reviewed head: `b6bf6cb4cf3586ce9bf8b15117fde5b2eb908ab6`.
Merged application revision: `d7e30457d67525b4116198a88ccd17599b4a6e62`.

## Pre-release evidence

All seven exact-head checks passed: PHP 8.3 and 8.4 with MySQL 8.4 on both push and pull-request workflows, browser checks, CodeQL and Actions analysis. Each application run passed 85/85 suites plus the separate commercial, visitor-presence, branding and newsletter checks. Locked dependencies passed Composer auditing. The integration checks retain personal verification access for each authenticated user while denying non-owner access to owner-only Google actions.

[Browser run 40741375413](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40741375413) passed 25 interaction, 19 journey-review, 349 public responsive, 874 site-wide template, 351 section-navigation and 331 module/login/register checks. The 38 axe-core 4.11.4 scans found no automatic WCAG A/AA violations in the covered templates. Manual screen-reader, physical-device and field-performance acceptance remains distinct.

The authentication suites validate actual RSA signatures, OpenID claim constraints, session-bound challenges, first-link mailbox proof, approved owner aliases, one-use codes, TOTP replay prevention, encrypted storage and security-epoch invalidation. Provider and inbox acceptance is not implied by isolated tests.

## Production acceptance

[Production workflow 40741859599](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40741859599) completed all three jobs successfully: application validation, AWS deployment and live browser acceptance. Elastic Beanstalk reached Ready/Green at 18:11 UTC. The remaining relay application and public checks completed before final acceptance. The live browser job passed 435 read-only section-selector checks across 25 public pages; the deployment also verified 34 public routes, database health, branding, private-route protection and deployed assets.

Read-only signed-in browser inspection at approximately 21:13–21:17 EAT verified 25 private screens: overview, directory, sign-in settings, operations, Google action list/new action/refresh, implementation register, program, content calendar, commercial desk/orders/settings, impact, studio, publishing, media, newsletter, readers, journeys, Google, Microsoft, devices, settings and account. Every inspected screen loaded the new shared stylesheet, had one main landmark and one H1, and had no horizontal page overflow at 1363 px. The overview and directory were also inspected visually. These are desktop observations; responsive behavior is covered separately by the fixtures.

The existing authenticated session survived deployment. Sign-in settings showed `isaac@waithakandegwa.com`, `isaac@goblisgroup.com` and `isaac@goblisfoundation.org` as super-administrator addresses for the same account. Google and Microsoft reported saved application credentials. No provider sign-in identity was yet linked; authenticator and phone were not enrolled, and SMS service configuration remained outstanding. A temporary 502 during the relay rollout cleared on the post-rollout reload; the final page rendered normally.

Anonymous HTTPS checks returned HTTP 200 for `/healthz` with `ok: true`, and for `/write/login` with both provider buttons, the email-verification explanation and passkey markup. Login retained `private, no-store, max-age=0` and noindex headers, and included no analytics tag.

Operations reported matching migration checksums. At 18:13 UTC the editorial, aggregate audience and approved impact-source preparation stages reported completed; selected-calendar refresh correctly remained disabled. Completion of a preparation stage does not certify complete source data. Delivery remained paused/disabled, with two pending email records and zero provider acknowledgements. The implementation register contained all 33 work areas.

## Continuing activation requirements

The two requested owner aliases share the existing owner's workspace and permissions. First Google/Microsoft linking still requires a genuine sign-in and mailbox proof. Email-code delivery needs a successful real mailbox test. Authenticator and phone enrollment need the owner's device confirmation; SMS additionally needs a configured Twilio Verify service.

Calendar refresh defaults off. Google Docs/Sheets/Tasks operations require their exact confirmed account grants and explicit action review. The delivery worker and previously queued email backlog remain separately controlled. No new OAuth grant, external document/task, social post, newsletter release or charge was performed as release acceptance.

All 33 registered work areas and open acceptance issues #70–76 remain tracked. Deployment is not a claim that unrelated provider entitlements, approved impact data, payment settlement or physical-device acceptance is complete.
