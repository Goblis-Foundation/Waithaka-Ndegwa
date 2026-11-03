# Support acknowledgements and Foundation handoff

Public Support page has a read-only feed refreshed every minute while visible. Optional consent is off by default. Individual supporters may display their saved first name or Anonymous; organisations display Anonymous. The public projection contains only name, amount and currency. No receipt capabilities, references, email, surname, address or payment method are exposed. Existing records are never opted in retrospectively.

A bounded index contains only receipt IDs and is encrypted. Every read rechecks current live receipt eligibility; pending, sandbox, reversed, voided or withdrawn records are excluded. Owner-only CSRF-protected removal changes acknowledgement visibility without altering payment or receipt validity. The feed considers the latest 60 opted-in records and returns at most 12 qualifying contributions. No cross-currency total is calculated. Recurring consent applies to verified instalments. A provider outage clears stale rendered acknowledgements.

The private Writer Foundation access page links to Ubunifu’s shared authenticated issuer workspace. Source: Ubunifu-Institute/ubunifu-institute PR175, merged e04704f2c8168df6108d5625c6c885b5b3d80216. An existing approved allocation and authorised operator are required; no capacity, learner record, email or pass is created by this deployment. Live issuer/account acceptance remains separate from source implementation.

Validation: focused identity/projection tests, disposable MySQL verification-state tests, public feed safe-DOM rendering and responsive browser checks, normal release pipeline. No real payments or invitation emails are used as test data.
