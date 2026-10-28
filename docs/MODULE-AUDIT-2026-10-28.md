# Website and Writer module audit — 28 October 2026

## Scope and evidence

Target: the existing https://www.waithakandegwa.com website, its private Writer application, and `Goblis-Foundation/Waithaka-Ndegwa` on AWS account 466401734428. Starting production revision: `9876e2f52efaad428b1ea11223ed0eafd74aa39d`. Deployment 40713009721, both application matrix checks and the experience-browser check were successful at the start of this audit.

Reviewed all 83 available repository issues/PR entries, the existing implementation register and dated audit/recovery documents, application routing/security/deployment code, and live rendered pages. Forty private module routes and 24 public routes were inspected in the authenticated browser. This was a read-only live inspection of page integrity, landmarks, headings, navigation, layout and readiness indicators, not a claim that every provider action or every data-dependent record was exercised. There were no real messages, payments, publications, account conversions or provider-permission changes.

## Coverage and resulting work

| Area | Live routes reviewed | Finding and action |
|---|---|---|
| Daily work | `/write/control-centre`, `/write/notes`, `/write/voice`, `/write/program`, `/write/communication`, `/write/correspondence`, `/write/meetings`, `/write/booking-mailboxes` | All rendered. Program date controls overflowed the viewport by about 69 px at the inspected desktop width. Constrain grid children and native controls; retain values and timezones. |
| Creation and media | `/write/studio`, `/write/capture`, `/write/new`, `/write/media-centre`, `/write/publishing`, `/write/media` | All rendered. Existing draft, approval, private-media and provider-capability boundaries retained. |
| Commercial publishing | `/write/desk`, `/write/desk/orders`, `/write/desk/settings` | All rendered but lacked a main landmark. Correct outer semantics in index, settings and the shared record editor without changing commercial permissions. |
| Audience | `/write/newsletter`, `/write/monthly-roundup`, `/write/readers`, `/write/contact-controls`, `/write/campaigns`, `/write/seo-checks`, `/write/discovery`, `/write/visitor-statistics` | All rendered. Existing subscription, suppression, search and analytics contracts retained. Sender and delivery acceptance remain open. |
| Journeys and impact | `/write/enterprises`, `/write/opportunities`, `/write/journeys`, `/write/journeys/founders`, `/write/editorial`, `/write/releases`, `/write/impact` | All rendered. Impact page had two H1 headings: retain one page title and make the feed section H2. Source evidence still required. |
| Administration | `/write/connections`, `/write/google`, `/write/microsoft`, `/write/app`, `/write/security`, `/write/reconciliation`, `/write/settings`, `/write/account` | All rendered. Upgrade the implementation register with search, filters, counts and links to all seven open acceptance issues. Preserve all existing entries. |
| Operations, additional diagnostic inspection | `/write/operations` | Recent timer heartbeat, delivery paused, two queued emails. Missing ledger receipts for migrations 150/180; existing Culture helper ran SQL without recording its receipt. Add fixed, idempotent schema/ledger recovery through the existing protected deployment path. |
| Communication navigation | `/write/communication?tab=content` | Content calendar incorrectly highlighted Communication Centre and mobile Inbox. Resolve the current destination by its actual tab. |
| Public content and discovery | `/`, `/my-thoughts`, `/start-here`, `/about`, `/search`, `/explore`, `/startup-journeys`, `/founders`, `/resources`, `/watch`, `/enterprises`, `/opportunities`, `/opportunities/jobs`, `/partner-stories`, `/monthly-roundup` | Rendered with one main and one page heading; no horizontal overflow at the inspected desktop width. Keep existing URLs, approved identity and content. |
| Public participation | `/contact`, `/founders/submit`, `/opportunities/submit`, `/newsletter`, `/advertise`, `/promote`, `/contribute`, `/book`, `/privacy` | Rendered successfully. Forms were inspected without sending genuine details or initiating transactions. The canonical booking route is `/book`; `/booking` correctly returns a not-found page and is not the navigation destination. |

Counts above distinguish the 40 primary private routes from additional Operations/content-calendar checks. Real account-specific records and physical devices are not exhaustively certified by this page inventory.

## Login implementation

The former login was a compact undifferentiated form. Its passkey handler exposed raw browser exception wording, offered no useful busy state and allowed overlapping credential requests. Password and passkey success sent users to different fixed destinations. Failed password attempts discarded the entered email and shared-device choice.

The replacement uses the existing approved logo and blue/green/white identity, a compact responsive introduction, password-manager-compatible fields, explicit labels, password visibility, Caps Lock feedback, accessible status/error messages and sign-in progress. Passkey controls appear only where the browser supports them; ordinary password sign-in remains usable without JavaScript. A cancelled passkey prompt restores the action and explains the password alternative. Duplicate passkey starts are blocked. No password, passkey or token is added to local storage.

A server-side allowlist remembers only recognised workspace destinations, with one explicit content-calendar query. External URLs, action endpoints, arbitrary queries and OAuth parameters are excluded. The destination is consumed after successful sign-in. Passkey success now resets the activity timestamp and uses the same destination logic as passwords. CSRF, verified origin, challenge expiry/one-use, device user verification, rate limits, encrypted storage and revocable trusted-device handling remain enforced.

Existing private-device persistence preference is retained and clearly explained. No password-reset email route is invented: the help explains the supported passkey, existing-device and administrator recovery paths. A self-service recovery flow remains a future security feature requiring verified transport and its own token/abuse tests.

## Schema repair

Migrations `150_subscriber_names.sql` and `180_culture_topic.sql` are unchanged. The recovery helper accepts only their exact reviewed hashes, checks existing ledger checksums, acquires a database-specific lock, adds only absent first/last name columns, verifies their types/defaults, preserves category edits, then records verified receipts. Existing display names, consent/status and subscriber records are never rewritten or inferred.

The deployment reuses the existing exact-account administrator-secret access window, verified database TLS, encrypted RDS recovery-point check, success-bound S3 receipt and EXIT cleanup. Repeated deployments use runtime read access after verification. The application delivery worker remains paused; schema recovery cannot release its email backlog.

## Benchmark basis

These are practical comparisons against authoritative patterns, not a claim of certification or a numerical ranking against every global site:

- [W3C WCAG 2.2 accessible authentication](https://www.w3.org/WAI/WCAG22/Understanding/accessible-authentication-minimum.html): support password managers and paste; do not force cognitive puzzles; retain keyboard access and useful labels/status messages. Full assistive-technology and physical-device acceptance is still open.
- [GOV.UK password input](https://design-system.service.gov.uk/components/password-input/): progressive enhancement, show/hide password control and understandable errors. Adopt these behaviours within the existing personal brand.
- [OWASP Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html): standard forms, generic credential rejection, throttling, passkeys and careful session handling. Preserve the application's security boundaries while improving feedback.
- [Google Core Web Vitals](https://web.dev/articles/vitals): target LCP ≤2.5 s, INP ≤200 ms and CLS ≤0.1 at the 75th percentile, separately for mobile and desktop. Responsive browser checks are not field-performance measurements. No field score is claimed in this release.

## No-drop acceptance register

All original register entries remain. The following issues remain open until independently evidenced:

| Issue | Outstanding acceptance |
|---|---|
| [#70](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/70) | Personal Meta identity and WhatsApp Business capability, sender/webhook/template verification; no corporate Page substitution. |
| [#71](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/71) | Personal LinkedIn, X and TikTok grants and actual publishing entitlement. Existing Instagram/YouTube routes remain intact. |
| [#72](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/72) | Live merchant, signed callbacks, independently approved settlement test and recurring mandate/cancellation proof. |
| [#73](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/73) | Approved sender postal details, controlled delivery acceptance and explicit backlog review before worker activation. |
| [#74](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/74) | Source-owned, approved dated impact feeds. Missing metrics remain unavailable. |
| [#75](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/75) | Account-specific Google/Microsoft grants and further selected-file/calendar/Tasks/Meet workflows. Alias labels do not grant access. |
| [#76](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/issues/76) | Physical-device passkeys/notifications, screen-reader acceptance and measured performance. |

The live integrations interface showed saved connections for several Google, Instagram and meeting capabilities; personal Facebook, LinkedIn, X, TikTok and some Google capabilities were not connected. This is an observed application status, not fresh provider-token verification. Credential values, account IDs, private records and telephone numbers are excluded from this public audit.

The earlier recovery note that the GitHub default branch was `recovery-bootstrap` is superseded by current repository metadata: the default branch is `main`. No repository-setting change is necessary.

## Verification and release evidence

New checks cover seven actual-template module fixtures over ten widths, login fallback without JavaScript, password visibility, cancelled/duplicate passkey attempts, retained form choices, return-URL rejection, preservation of all seven acceptance issues, and partial migration recovery/idempotency/checksum mismatch against an isolated database.

Existing public-journey, layout, browser, PHP 8.3/8.4 + MySQL 8.4, dependency/security and protected AWS deployment gates remain required. Exact PR, final commit, check and production results are recorded in the accompanying production acceptance note after verification. Until then, implementation does not imply successful production acceptance.
