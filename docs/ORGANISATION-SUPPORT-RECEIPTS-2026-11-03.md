# Individual, company and institution support receipts

The contribution form now offers Individual, Company / Institution and Anonymous choices, with a live, text-only receipt preview. The purpose is either general support or sponsorship of independent writing. Sponsorship here does not purchase advertising, favourable coverage or editorial control; agreed promotional services remain in the separate commercial quotation flow.

Named supporters provide their exact receipt name and explicitly acknowledge private storage. Individuals use first, optional middle/family and last names. Companies/institutions use their organisational name. Billing address and accounts contact email are optional. Anonymous selection discards stale identity fields server-side and disables them in the browser. Receipt details do not subscribe anyone or initiate email delivery.

## Payment and receipt journey

- M-Pesa, Pesapal and PayPal checkout save an immutable encrypted receipt identity before requesting payment. Only the originating session receives the private receipt link from the ordinary public status page. A public contribution reference alone never reveals payer details.
- Online receipts require matching amount, currency, reference, method and verified live payment evidence. An intent, sandbox payment, mismatch, unresolved review or reversal is not a paid receipt. Status-only M-Pesa evidence additionally requires the owner to verify the original receiving account statement.
- Bank supporters can create a private reference and view frozen receiving instructions. This does not transfer money. The owner must verify the matching receiving statement, amount, currency, payer and unique reference. Changed receiving details block automatic acceptance. Duplicate statement references cannot issue a second receipt.
- Monthly support saves the same private identity. A request for the last reported instalment reuses one receipt record per subscription/payment timestamp. It remains unpaid until matching statement verification. An active subscription is never substituted for a paid instalment; refund/reversal review prevents paid presentation.
- Paid receipts have an escaped, private Print / Save PDF view. Unverified records are labelled as not proof of payment and have no paid-receipt print action. The issuer is Isaac Waithaka Ndegwa. These are support acknowledgements, not charitable deduction certificates or commercial tax invoices.
- New commercial quotations freeze the billed organisation name into their accepted quote hash. The name appears on the private invoice and payment receipt email. Historical quotes are not assigned an invented payer or rewritten.

## Owner administration

Giving & receiving → Support receipts lists private records in pages of 20. Only the configured owner can verify statements or void a receipt with a reason. Voiding changes the receipt record only: it does not refund, delete a transaction or release a used statement reference for reuse. Existing settlement controls and email queues remain intact.

Receipt capabilities contain 256-bit random secrets, use no-store/noindex/no-referrer responses and never appear in public supporter lists. Identity, optional address/email, statement evidence and private capabilities remain in encrypted settings. No credential, card number or bank password is requested. No real payment, institutional identity, bank receipt or email is generated as test evidence.

## Validation and scope

Tests cover identity validation and anonymity, safe preview rendering, encrypted persistence, private capability rejection, live-vs-sandbox gates, amount mismatch, reversal, duplicate statements, original receiving account checks, voiding, recurring receipt reuse, responsive layouts, print behaviour and automated accessibility. CI and all production acceptance jobs must pass before release is accepted.

The provider distinction between subscription approval and completed transaction is consistent with [PayPal subscription transaction definitions](https://developer.paypal.com/api/subscriptions/v1/definitions/transaction/) and its [Subscriptions API](https://developer.paypal.com/api/subscriptions/v1). This release makes no new automatic transaction-history API calls and does not infer paid status from a mandate.

The separate investor-access, learner-email, bootcamp and enterprise-reporting request was explicitly withdrawn and is not included.
