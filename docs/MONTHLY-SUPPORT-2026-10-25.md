# Monthly support
Public /contribute/monthly supports recurring PayPal approval with provider-owned fixed monthly USD 5/10/25/50/100 plans. A private owner setup prepares and verifies each plan, then separately publishes plan availability. No supporter is enrolled by owner setup or by visiting a page. Every supporter explicitly approves recurring terms in PayPal. No card data is collected here.
Private capability links allow status refresh and CSRF-protected cancellation of future payments; they do not refund completed payments. Provider subscription status, next billing time and provider-reported last payment are separate. No active-mandate total is treated as money received. Webhooks schedule read-only verification of known subscriptions; no arbitrary subscription is imported. No automatic recovery of outstanding balances; first failed payment threshold is one.
A monthly bank standing order can be configured in the supporter's own bank using the published Wise receiving details. The hosted Wise link is not described as a recurring debit mandate.
Pesapal recurring card payments require merchant recurring facility, account_number, hosted subscriber consent and recurring-IPN reconciliation. Safaricom Ratiba requires its distinct Daraja product and merchant onboarding. Neither provider has verified website credentials yet; these automatic routes are not exposed as active. Their official documented facilities remain activation work.
Primary references:
https://developer.paypal.com/api/subscriptions/v1/
https://developer.paypal.com/subscriptions/webhooks/
https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/recurringpayments
https://developer.safaricom.co.ke/apis/MpesaRatiba
No real supporter subscription or charge was created during implementation.
