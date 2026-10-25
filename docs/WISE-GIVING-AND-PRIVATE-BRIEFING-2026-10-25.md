# Wise receiving instructions and private booking briefing

Fourth giving option: EUR SEPA credit transfer (IBAN/BIC), USD ACH credit (ACH routing/account/type), GBP UK Faster Payments (sort code/account). Owner-only /write/wise; encrypted settings record; per-currency explicit public enable; checksum and format validation; no invented account details. Incoming transfers are not automatically settled or receipted.

## Activation
Enter the actual Wise-issued receiving fields and verify them before checking publication. No API token is needed to display receiving instructions. This is a bank-transfer route, not a debit mandate or hosted Wise checkout. Existing M-Pesa, Pesapal and PayPal paths are unchanged.

## Automation research / next activation
Wise Platform supports incoming-credit events, balance/statement data and profile-level webhooks, but exact endpoints and permissions depend on account/profile/country and API programme. Personal-token statement retrieval is restricted by registered country (currently US, Canada, Australia, New Zealand, Singapore, Malaysia per Wise docs). Do not promise a generic token unlocks it for a Kenyan profile.

After verifying eligibility: create a unique contribution reference; receive signature-verified credit events; re-fetch authenticated transaction evidence; match profile, currency, received amount and reference; deduplicate transaction IDs; put ambiguous, net-fee, reversal and missing-reference cases in review; acknowledge only verified receipts. Never infer settlement from a browser return, a screenshot or an unsigned webhook. Do not request outgoing-transfer permissions. Read-only statement import/manual reconciliation remains a fallback if supported account access is unavailable. No Wise token, subscription, money movement, receipt or external notification was created by this release.

Sources checked 25 October 2026:
- https://wise.com/help/articles/2898124/how-do-i-receive-money-to-my-wise-account-details
- https://docs.wise.com/guides/developer/auth-and-security/personal-api-token
- https://docs.wise.com/guides/developer/webhooks
- https://docs.wise.com/guides/multi-currency-accounts/event-notifications

## Booking
Remove provider/AI consent controls from the public booking form. Public attachments remain private and enter manual host review; public submissions cannot opt themselves into AI even by forging old fields. The owner can explicitly request an authorised AI draft in the private booking review workspace or write a manual report. Existing queued attachments with previously recorded explicit consent retain their provider snapshot. Approval remains blocked until a briefing report is saved. Privacy text accurately reflects this boundary.

## Implemented connection preparation
Owner-only encrypted token/profile/environment settings; no token echo. Explicit profile and standard currency-balance verification, separate read-only statement capability check. HTTPS allowlisted API operations, no redirects, bounded response, no outgoing transfer/funding/recipient routes. A /webhooks/wise receiver verifies raw-body RSA-SHA256 against pinned environment-specific Wise public keys, matches exact configured subscription/profile/schema, persists a deduplicated encrypted event review record, and acknowledges quickly without external calls. Disabled by default; credentials and subscription not yet supplied. Events are hints awaiting reconciliation, never payment settlement. Automatic receipts and matching are not active in this release. No Wise webhook subscription is created automatically.

Signature and current event sources:
- https://docs.wise.com/guides/developer/webhooks/event-handling
- https://docs.wise.com/api-reference/webhook-event/eventbalancesupdate
- https://docs.wise.com/api-reference/profile/profilelist
- https://docs.wise.com/api-reference/balance/balancelist
- https://docs.wise.com/api-reference/balance-statement
