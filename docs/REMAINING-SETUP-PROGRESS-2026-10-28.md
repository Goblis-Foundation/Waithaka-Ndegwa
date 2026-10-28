# Remaining setup progress — 28 October 2026

## Live acceptance performed

The owner requested continuation of remaining tasks after PR #86. At approximately 21:25 EAT the site's single-owner email test to the existing Writer address, `isaac@waithakandegwa.com`, returned provider acknowledgement. This was one explicit test, separate from the newsletter outbox. It is not proof of inbox delivery.

The existing canonical Foundation Microsoft account successfully returned a fresh, bounded Outlook mail-header result. The test was absent from those returned records. The connected Gmail search also returned no matching test. These observations do not establish non-delivery or prove that either connected mailbox is the destination inbox for this address; inbox acceptance remains open.

The site's existing PageSpeed/Lighthouse check was attempted for the public homepage with its Mobile strategy. The provider returned a rate-limit error. No performance score, Core Web Vitals pass, or physical-device acceptance is claimed. Google and Microsoft navigation was intermittently slow; the inspected initial page controllers make local reads and do not automatically request provider reports. No cause or resolved performance defect is inferred from browser navigation timeouts alone.

The existing Search Console content-review grant was verified with a real on-demand read at 21:41 EAT. The finalized current period (28 September–25 October) returned six eligible page rows; the equal previous period (31 August–27 September) returned three. No review signals were found in the returned sample. This does not certify all site traffic or absence of SEO issues. No permission was widened and no content was published.

## Implemented setup improvements

- Approved-address selection for security step-up, with server-side ownership validation and the existing password/code requirements.
- Signed-in Google/Microsoft linking bound to the current user, recent verification, security epoch and single-use OAuth state. A different local account cannot be linked. Mailbox proof is still required before the durable provider identity is saved.
- Completed-link security-epoch rotation, retained current session and recovery back to Security for cancelled verification.
- Updated release-status wording for the admin redesign and preparation-stage observations already verified in PR #86.

Tests include real signed-token exchanges, cross-user and changed-epoch rejection, exact mailbox challenge ownership, no premature binding, no account switching, and invalidation of remembered sessions. HTTP checks exercise the real CSRF and recent-verification boundary. Owner fixtures now render the actual alias chooser and service setup section.

## Still requires owner or provider evidence

No Google/Microsoft sign-in identity has been linked on behalf of the owner, no authenticator seed or recovery code has been created for the owner's device, and no phone is silently enrolled. The SMS Verify service is not configured. Device enrollment and provider linking require secure owner interaction; SMS needs an existing Verify account/service and credentials. Never put those secrets in repository files or chat.

Issues #70–76 remain open for their remaining provider entitlements, approved source data, sender details, real settlement, device and performance acceptance. No customer message, social post, backlog release, charge, new paid subscription or broad OAuth consent occurred.

## Calendar activation and recovery

Private background refresh was enabled for the eight already-selected accounts at approximately 21:44 EAT; Google change-notification registration remains off. Seven accounts completed real full refreshes. The AfriTrace account (`isaac@afritracelink.com`) failed; a separate bounded calendar-list read returned HTTP 400. This is evidence of a provider/account request failure, not proof of its underlying cause. Saved access and calendar selections were retained for review. Public booking availability continues to use live provider checks.

The live failure exposed two operator-facing defects: first-attempt failures lost retry history because the revision was not recorded, and all failures were retried every minute with no useful classification. The follow-up implementation retains first-attempt revision, classifies safe HTTP statuses and access-renewal versus event-read phases, applies exponential delays with jitter capped at one hour, and rechecks eligibility under the calendar lock. Notifications and manual due checks respect backoff. Reauthorization/selection changes permit recovery immediately; successful refreshes clear failure history. Raw provider bodies and exception messages are never persisted.

The status page now shows the last attempt and next retry eligibility; an unavailable initial snapshot is not displayed as a measured zero. Google workspace wording accurately explains the opt-in encrypted cache. Retry handling follows Google's [Calendar API error guidance](https://developers.google.com/workspace/calendar/api/guides/errors). Local tests cover first-attempt failures, repeated failures, recovery, authorization changes, concurrent eligibility changes and secret-safe classification.

## Release checks and owner setup status

PR [#88](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/88) merged as `ee2d7f42b376726ebdbe5c8838bd5344374f0452`. Production workflow [40748765841](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40748765841) completed application validation, AWS deployment and live browser acceptance successfully. Live Security inspection confirmed all three approved owner addresses, the verification-address chooser, and provider linking controls gated on recent verification.

PR [#89](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/89) merged as `e4a442762100b758c0ea8e5635fc150f1018537c` after all exact-head checks passed. PHP 8.3 and 8.4 with MySQL 8.4 each passed 85/85 application suites; the calendar suite contains 78 assertions. Browser acceptance passed 25 interaction, 19 journey-review, 349 responsive, 874 site-wide template, 351 section-navigation and 331 module checks, plus 38 automated accessibility scans. Real enabled/successful/failed calendar templates are covered. This does not certify manual screen-reader or physical-device acceptance.

A secure browser-auth password request reported submission, but the visible owner page did not advance to email-code verification. A fresh signed-in Security page still required verification, and the dedicated verification route showed no active challenge; no provider identity or authenticator enrollment is claimed. No password, OTP, authenticator seed or recovery code was retrieved or copied into this record. Owner setup remains at the verification gate for a direct secure handoff.

A live implementation-register read retained all 33 work areas, including 23 follow-up/verification items and all seven acceptance issues (#70–76). Existing backlog/authorization requirements have not been silently closed. A later exact-subject Gmail search still returned no delivery-test match; inbox receipt remains unconfirmed.

Live calendar acceptance on the deployed follow-up code confirmed all seven successful snapshots, automatic background refresh beyond the initial manual runs, and the AfriTrace failure specifically at access renewal (HTTP 400). Its last attempt was 19:06 UTC, with next retry eligible at 19:36 UTC. A subsequent manual due-check retained those times, verifying that it did not bypass backoff. The initial failed snapshot displays “Not available.” This narrows the remaining account issue to authorization renewal; the exact Google rejection reason is not inferred from the status alone.

Production workflow [40749982228](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40749982228) completed all three jobs successfully: application validation, AWS deployment and live public-browser acceptance. The deployed application is the merged PR #89 revision above; this acceptance update changes documentation only.
