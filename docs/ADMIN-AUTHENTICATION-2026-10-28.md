# Administration and sign-in redesign — 28 October 2026

## Professional administration

A shared stylesheet provides consistent navigation, typography, spacing, focus states, forms, tables, cards and mobile controls across private modules. The approved WN logo, blue/green/white identity and original portrait remain intact. The overview uses actual saved counts and a Nairobi date; unavailable data is not presented as zero. The directory preserves existing destinations and adds sign-in settings. No module is removed.

## Owner identities

The owner's explicitly requested `isaac@goblisgroup.com` and `isaac@goblisfoundation.org` addresses map to the existing active owner user selected in Communications configuration. They have the same super-administrator workspace access and records. They are not duplicate users or separate mailboxes. No public registration, domain-wide membership or automatic elevation of other users is introduced. A nonexistent/inactive configured owner cannot be resolved through an alias.

## Sign-in and verification

- Google and Microsoft use authorization-code flows with S256 PKCE, session-bound one-use state, nonce, restricted RS256 keys, actual signature validation and exact client/issuer/time checks. Microsoft tenant and signing-key issuer constraints are checked. Only `openid profile email` scopes are requested; no mail/calendar/publishing grant is requested by sign-in.
- Existing registered Google Calendar and Microsoft meeting callback URLs are reused. A separate sign-in session state is intercepted before authenticated integration routing. Other integration callbacks retain their original handling. Existing provider application credentials are reused; no credentials are shown in HTML or logs.
- An email/UPN claim can propose an approved address but never grants access by itself. A first provider binding requires a one-use code sent to that exact approved address. The durable link is provider + issuer + subject; mutable display email claims cannot replace it. An existing address cannot be silently rebound to another subject.
- New production password/passkey sign-ins require verification before a session or remembered-device credential is issued. Codes go to the entered approved password alias or the bound provider email. Codes are random, keyed hashes, expire with their ten-minute session-bound challenge, permit at most six attempts and are single use. Resending invalidates the old code and does not reset the attempt budget. Per-account/IP limits also apply.
- Security emails use the existing configured SMTP/SES transport directly after primary authentication. They do not enter or release the bulk outbox. Only provider acknowledgement is reported; an unavailable transport fails closed. Previously authenticated sessions and remembered devices retain their existing validity so deployment does not forcibly sign out the owner. New remembered credentials are issued only after verification. Password or factor changes invalidate other remembered credentials through the existing security epoch.
- Authenticator enrollment uses a local QR code or manual setup key, SHA1 TOTP with six digits and 30-second steps, an encrypted seed, confirmation before activation and atomic counter replay prevention. Recovery codes are generated securely, shown once, retained only as keyed hashes and consumed once.
- SMS uses an explicitly configured Twilio Verify service and a phone number proved by a successful verification. The application checks exact verification SID, service, recipient and approved status. No public-profile phone is silently enrolled. No Twilio account or paid subscription is created. Configuration and enrollment are separate.
- Security setup changes require the current password plus recent verification. Provider unlink, factor removal/replacement and recovery regeneration are protected by CSRF, ownership, rate limits and fresh verification.

## Operational acceptance

`/write/security/sign-in` lists approved addresses, linked identities, factor enrollment and provider setup. Google/Microsoft sign-in requires correctly registered callbacks and valid application credentials. Email needs a working transaction transport. Authenticator and phone enrollment require the owner to complete the corresponding device/code confirmation. These are real-user/provider acceptance steps, not something simulated tests can certify.

No authentication secret, live OTP, TOTP seed, recovery code, phone credential or private identity token is committed. Synthetic fixtures use `.invalid` accounts. No newsletter, social post, customer email, external document, task, financial charge or new OAuth consent is performed by deployment.

## Standards and tests

The implementation follows [OpenID Connect token validation](https://openid.net/specs/openid-connect-core-1_0.html), [Microsoft stable identity guidance](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference), [OWASP MFA and OTP handling guidance](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html), [RFC 6238](https://www.rfc-editor.org/rfc/rfc6238), and [Twilio Verify confirmation](https://www.twilio.com/docs/verify/api/verification-check). Authenticator/passkey methods are preferable to SMS for stronger resistance to account takeover.

Tests use actual RSA signatures, RFC TOTP vectors and isolated persistence/transport fixtures. They cover nonce/audience/issuer/algorithm mismatch, one-use state, mutable-email resistance, alias isolation, initial mailbox proof, duplicate binding, OTP expiry/attempt limits, replay, failure recovery and exact SMS acknowledgement. Browser checks include keyboard/no-JavaScript paths, ten responsive widths and pinned axe-core WCAG A/AA scans. Automated scanning is not a claim of full accessibility conformance; physical devices and screen readers remain in issue #76.
