# Wise and PayPal capability audit — 25 October 2026

The private dashboards contain the current detailed inventory: 16 Wise and 19 PayPal capability families, distinguishing implemented functions, native-bank actions, unimplemented adapters and eligibility-dependent products. This is an audit of product families, not a claim that every API endpoint or account permission has been exercised.

Live saved status observed: Wise profile verified, three receiving currencies published, signed event receiver enabled. PayPal Live credentials saved, receiver and automatic order checks enabled. Existing monthly USD 5/10/25/50/100 plans are configured; actual payer subscriptions and settlements are not created for acceptance. Pesapal remains credential-blocked, separate from PayPal.

Priority gaps: Wise reliable incoming-transaction reconciliation and exception handling; PayPal account-wide transaction/fee reporting, a private dispute queue and refund-review preparation. Statements, payout products, advanced cards/wallets and agent/MCP financial access must not be inferred from successful receiving authentication.

Wise current personal-token documentation focuses on business tokens and restricts endpoint/product access. It identifies geography-dependent statement/funding limitations. The site's successful personal-profile probe and balance-list read do not establish access to every Wise Platform endpoint, outgoing funding, cards or embedded onboarding.

PayPal provides Orders/Payments, subscriptions/catalogues, transaction reporting, invoicing, disputes, payouts, vault/payment tokens, checkout methods, partner onboarding and agent tooling. This website uses only the explicitly implemented receiving/subscription subset. Product availability and account scope remain separate checks. No financial AI server, arbitrary refund or payout was activated.

Primary references:
https://docs.wise.com/api-reference
https://docs.wise.com/guides/developer/auth-and-security/personal-api-token
https://docs.wise.com/api-reference/profile/profilelist
https://developer.paypal.com/api/rest
https://developer.paypal.com/api/subscriptions/v1
https://developer.paypal.com/api/transaction-search/v1
https://developer.paypal.com/api/invoicing/v2
https://developer.paypal.com/studio/checkout/advanced
https://developer.paypal.com/ai-tools/toolkit/
