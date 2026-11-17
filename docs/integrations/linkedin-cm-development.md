# LinkedIn Community Management development profile (DRAFT, not active)

This additive profile is deliberately **unregistered** in Writer. No runtime settings, credentials, redirect URLs, production connections or existing OIDC integration are changed. No network transport exists. It cannot publish, schedule or authorize any request. This is a reviewable foundation, not a completed connection or production automation.

The inspected Waithaka Ndegwa Pages app has Community Management Development Tier and r_basicprofile/w_member_social, but not OpenID Connect. Its self-profile endpoint is `/v2/me`, not `/v2/userinfo`. Member IDs are app-specific. Existing `linkedin` and `linkedin_analytics` connections must retain their OIDC behavior; never copy their credentials or member IDs into this profile. Do not alter the Ubunifu app.

## Gates before any live development connection

1. Independent review; an authorized isolated development environment and credential provisioning workflow. Production and staging must reject this profile. The hard transport stop must not be replaced by a production feature flag.
2. Owner approval for registering the exact development callback. Keep current owner-only OAuth routes, random single-use state, session/user binding, ten-minute expiry, encrypted token storage, canonical HTTPS callback and CSRF controls. Add integration tests for replay, state/service/session/user mismatch and expiry before registration. Never log code, token or secret.
3. Separate credential namespace and connection row `linkedin_cm_dev`; request only r_basicprofile and w_member_social with explicit LinkedIn consent. Verify required granted scopes. Read authenticated self only and require the owner to confirm the returned member before a test publication. No harvesting, organization grants or invitation permissions.
4. Durable shared, atomic app/member rolling-24h budget reservation for **every** request, counting all tokens/workers and retries, with conservative provider-reported remaining quotas. The pure helper only checks arithmetic; it is NOT a rate limiter. Development limits currently 500/app and 100/member/24h. Do not enable if other integrations share this app and their consumption cannot be reconciled.
5. A manually approved test post to the confirmed personal member only, exact payload and author shown first. Reuse current Posts API validation; block generic automatic/scheduled workers. Save a creation receipt; independently read back where granted; ambiguous outcomes require reconciliation, never blind retry. Honor Retry-After and avoid repeated token retries.
6. Mocked OAuth/database/transport tests and approved live sandbox smoke test, including revoked/expired/declined tokens, quota contention, wrong app/member and duplicate protection. No live tokens or posts in unit tests.
7. Standard-tier review or another explicitly approved production-capable app before ongoing unattended publishing. Development tier is for build/test, not production entitlement.

Run offline foundation tests: `php tests/linkedin_cm_dev_unit.php`.

Official references (checked 17 November 2026):
- https://learn.microsoft.com/en-us/linkedin/marketing/increasing-access?view=li-lms-2026-09
- https://learn.microsoft.com/en-us/linkedin/shared/integrations/people/profile-api
- https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
