# Subscription and email design — 23 October 2026

Owner instruction: first name, last name and email; intuitive subscription; appreciative welcome; designed unsubscribe message; shared branded header, personalised salutation, Waithaka Ndegwa footer and all six personal social links, including contact-engine mail.

## Implemented flow

- Public signup asks for three labelled fields, with given-name/family-name/email autocomplete, mobile email keyboard and server-side validation. Unicode names are supported without an ASCII-only name pattern. Existing display names are preserved; migration 150 adds separate name columns without guessing legacy family names.
- Explicit newsletter consent, rate limits, honeypot and CSRF remain. Generic request responses do not disclose whether an address is already subscribed. Email confirmation expires after 24 hours; following GET links never changes consent.
- Confirmation queues one appreciative welcome within the same transaction as activation. Its dedupe key includes subscriber and consent revision. Before transport the worker holds the subscriber lock, verifies current status, address, revision and suppression. An unsubscribed reader never receives a stale welcome.
- Newsletter editions retain recipient-specific body unsubscribe, preferences and RFC 8058 one-click headers. Unsubscribe is immediate; the one-click POST needs neither login nor extra action and sends no follow-up message.
- Human unsubscribe offers an unchecked optional one-time email receipt. The beautiful receipt is transactional, contains no reactivation button, and is queued only on a real state transition when requested. Repeats do not send again. A newer consent revision invalidates obsolete receipts.
- Contact acknowledgements are queued after durable enquiry storage, show the saved reference and thank the sender by first name. They do not repeat submitted message text to an unverified email address and never enrol the enquirer in marketing. Owner notifications and private reader/founder links use the same email chrome.
- Shared table layout, inline styles, live text branding, generous spacing, image-free buttons and six named social links work without loading remote images. Plain-text alternatives retain link destinations. Unknown legacy names receive a neutral greeting rather than an invented first name.
- Writer → Newsletter → Preview email designs provides private, sandboxed synthetic confirmation, welcome, unsubscribe and contact examples. No real recipient tokens or sends are created by viewing previews.

## Research and deliberate decisions

Primary sources checked on 23 October 2026:

- Google, Email subscription guidelines: https://support.google.com/mail/answer/15263077?hl=en — confirmed opt-in, immediate easy one-click unsubscribe, distinct subscription and transactional traffic, list identification.
- Google, Email sender guidelines FAQ: https://support.google.com/mail/answer/14229414?hl=en — one-click headers and body-link distinction, authentication and bulk sender requirements.
- W3C WAI H98: https://www.w3.org/WAI/WCAG21/Techniques/html/H98 — programmatically identified input purposes and browser autocomplete.
- Mailchimp, About Double Opt-in: https://mailchimp.com/help/about-double-opt-in/ — separate confirmation and welcome stages.

No Mailchimp subscription or third-party tracking pixel was introduced. This is a native implementation. No unsubscribe reason, account sign-in or retention offer is required. The optional human receipt fulfils the design request while keeping machine one-click opt-out silent. Transactional messages explain why they were sent and provide a newsletter-management route; they do not falsely claim that opting out of newsletters cancels replies to an explicit enquiry.

## Deployment and acceptance gates

Sending remains paused until scoped Google Workspace SMTP relay, approved sender identity, reply address, postal details and an actual inbox delivery test are ready. Configure separately verified newsletter and transactional sender addresses before bulk release, as recommended by Google; the current shared transport does not claim this separation is active. Existing SPF/DKIM setup does not prove email delivery. Production previews are not an Outlook/Gmail/Apple Mail rendering certification.

Tests cover names, escaping, all social links, text alternatives, idempotence, unsubscribe receipt choice, recipient binding, consent revision changes and obsolete welcome suppression. No real emails or campaigns were sent by implementation tests.
