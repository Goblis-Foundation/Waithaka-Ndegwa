# PayPal notifications and independent read-only reconciliation
The selected existing live app is Waithaka Ndegwa Website, in the owner’s iwaithakaw@gmail.com PayPal account. Its credentials authenticated in the private website dashboard. HomeView was not reused. A dated new app was created before the owner selected the existing app and remains unused.

Implemented /webhooks/paypal, bounded JSON, exact environment certificate-host validation, PayPal verification_status SUCCESS checks, app/webhook binding, known-order/capture correlation and duplicate audit suppression. No webhook can create a contribution, capture a payment, issue a refund or settle money from its own payload. Refund/reversal notices are held for review.
An explicit independent worker checks only new PayPal contributions from activation onward, using authoritative GET order reads and the existing amount/currency/payee/reference checks. Legacy queues remain gated.
The owner must verify authentication, register all six displayed events against the exact URL and save its webhook ID. Saving verifies the subscription through the current app’s API before enabling it. Changing credentials/environment invalidates the binding.

The Wisetag/Business hosted link is an actual owner-provided permanent Wise link, not inferred from a name or account number. Its separate save controls and second-choice bank details were deployed in PR28; the actual link remains required.
No real payment was sent, captured or refunded during implementation.
Sources:
https://developer.paypal.com/api/rest/webhooks/rest/
https://developer.paypal.com/payment-methods/webhooks/
https://wise.com/help/articles/5qGvWQuTiX0RSSvxWvKcBC/how-to-use-your-open-link-to-get-paid-through-your-website
