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
