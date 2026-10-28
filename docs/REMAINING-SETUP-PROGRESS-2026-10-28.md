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
