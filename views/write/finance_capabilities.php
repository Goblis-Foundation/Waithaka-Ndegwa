<?php
$capRows=$capProvider==='wise'?[
 ['Receiving accounts','Implemented','Published USD ACH, EUR SEPA and GBP Faster Payments details; each account has its own save/publish control.'],
 ['Permanent payment link','Implemented','Optional Wise hosted link, after bank details. Wise controls the funding options it offers.'],
 ['Profile and currency discovery','Implemented','Explicit profile and currency verification; no public balance amounts.'],
 ['Incoming-credit webhooks','Implemented','Signed profile/subscription-matched events enter private review. A notification alone does not settle a contribution.'],
 ['Balance statements','Adapter; eligibility check needed','Explicit last-24-hour read test exists. Access depends on profile, country, authentication and token permissions; statement content is not retained by this check.'],
 ['Automatic receipt matching','Not implemented','Needs reliable transaction evidence, currency/amount/reference matching, duplicate handling and owner-reviewed exceptions before receipts.'],
 ['Standing orders','Bank-managed','Supporters may create recurring transfers in their banks. This site cannot create or cancel those bank instructions.'],
 ['Rates, quotes and delivery estimates','Not implemented','Wise offers quotes, fees, rates and estimates; these are outside this receiving-only adapter.'],
 ['Recipient accounts and contacts','Not implemented','Wise supports recipient workflows with eligible access. No recipient creation or editing is exposed here.'],
 ['Transfers and batch payments','Not implemented','Transfers, statuses and batch groups are separate outgoing-payment workflows, not enabled by the receiving connection.'],
 ['Funding, conversions and direct debits','Not implemented; eligibility-dependent','Money movement needs eligible account access and transaction-specific controls. A saved token is not proof of permission.'],
 ['API bank-detail provisioning and incoming transfers','Partner/eligibility-dependent','Wise Platform account, local-detail and incoming-transfer APIs are broader than publishing existing bank instructions.'],
 ['Cards, transactions and spend controls','Partner/eligibility-dependent','Issuing cards, viewing card details, digital-wallet provisioning and spend controls need the appropriate Wise product agreement.'],
 ['KYC, profile onboarding and embedded accounts','Partner-dependent','No customer onboarding, identity-document capture, delegated OAuth or embedded Wise account offering is implemented.'],
 ['Security and operations','Partially implemented','Encrypted token, profile binding, signed callbacks, deduplication and narrow endpoint allowlist exist. Token expiry/revocation monitoring and reconciliation alerts need further work.'],
 ['MCP and AI','Documentation only; not connected','Wise offers a Docs MCP for developer reference. It is not this website’s account connection and does not authorize financial actions.']
]:[
 ['One-time checkout and order reads','Implemented','Hosted payer approval, guarded capture and authenticated order/amount/currency/reference reconciliation exist.'],
 ['Monthly subscriptions','Implemented','USD 5, 10, 25, 50 and 100 plans, payer-approved recurring support, status refresh and cancellation of future payments. Plan availability is checked separately below.'],
 ['Products and plan management','Implemented subset','Website-specific product and exact monthly-plan setup/read-back; no arbitrary price changes or existing-supporter plan revisions.'],
 ['Signed webhooks','Implemented','Signature verification, deduplication, known-record matching and credential-bound activation. Events do not themselves prove settlement.'],
 ['Refund and reversal notices','Review only','Incoming refund/reversal notices are held for review. The site does not initiate a refund.'],
 ['Refund creation','Not implemented','PayPal’s Payments API supports eligible refunds; an owner-reviewed exact payment, amount and reason would be needed.'],
 ['Authorizations and delayed capture','Not implemented','The site uses CAPTURE orders. Authorize/void and delayed/partial capture workflows are separate products.'],
 ['Transaction search and financial reports','Not implemented; permission-dependent','Account-wide search, fee/net reporting and statement-style reconciliation require reporting access. An Orders token does not establish that permission.'],
 ['Disputes and evidence','Not implemented','Dispute listing, deadlines, evidence, offers and resolution actions need a dedicated private workflow and permitted API access.'],
 ['Invoices and reminders','Not implemented','PayPal can create, send and track invoices. Voluntary writing support remains distinct from invoiced goods/services.'],
 ['Payouts and batch disbursements','Not implemented; approval-dependent','Merchant approval, supported country/currency and recipient-specific payment authorization are separate from receiving contributions.'],
 ['Cards, Apple Pay, Google Pay and local methods','Eligibility-dependent; not implemented','Availability varies by merchant product, country, device and payer. Hosted PayPal funding choices are not a guarantee that all these methods are enabled here.'],
 ['Saved payment tokens and vault','Not implemented','Tokenization, saved funding sources and reference transactions need appropriate consent and product eligibility. No card data is stored here.'],
 ['Pay Later and Venmo','Eligibility-dependent; not implemented','Regional and product eligibility applies; these are not advertised as universally available.'],
 ['Multi-currency and FX','Implemented subset','Current public PayPal support is USD. Other currencies, conversions and withdrawal/account controls are not implemented.'],
 ['Shipping tracking and commerce','Not implemented','Tracking, physical goods, fulfillment and commerce-specific seller-protection flows are outside voluntary support.'],
 ['Partner referrals and marketplace onboarding','Partner-dependent','No onboarding of other merchants, split-payment marketplace or third-party account management is implemented.'],
 ['MCP, agent toolkit and AI','Available from provider; not connected','PayPal offers an agent toolkit/MCP. No financial agent server is connected and no autonomous refunds, payouts or subscription changes are enabled.'],
 ['Reliability and account permissions','Partially implemented','Encrypted credentials, environment binding, idempotency and known-payment verification exist. Broader permission checks, reporting exports and dispute alerts remain separate work.']
];
?>
<link rel="stylesheet" href="<?=asset('css/finance-capabilities.css')?>">
<section class="finance-capabilities" aria-labelledby="<?=e($capProvider)?>-capabilities-title">
<span class="writer-kicker">Capability audit · 25 October 2026</span><h2 id="<?=e($capProvider)?>-capabilities-title"><?=e($capProvider==='wise'?'Wise':'PayPal')?> · what is available here</h2>
<p>Implementation and provider eligibility are different. This inventory does not grant access, activate a product or move money. Connection status above reflects saved checks; unsupported features are identified below.</p>
<?php if($capProvider==='paypal'):?><p><strong>Monthly plans currently offered:</strong> <?=count(monthly_paypal_choices())?> · <a href="<?=url('/write/monthly-support')?>">Review monthly support</a></p><?php endif;?>
<div class="finance-capability-list"><?php foreach($capRows as$row):?><details><summary><strong><?=e($row[0])?></strong><span><?=e($row[1])?></span></summary><p><?=e($row[2])?></p></details><?php endforeach;?></div>
<details class="finance-capability-sources"><summary>Official references and next steps</summary>
<?php if($capProvider==='wise'):?><ul><li><a href="https://docs.wise.com/api-reference" target="_blank" rel="noopener noreferrer">Wise API resource catalogue</a></li><li><a href="https://docs.wise.com/guides/developer/auth-and-security/personal-api-token" target="_blank" rel="noopener noreferrer">Token limits and account eligibility</a></li></ul><p>Prioritise verified incoming-payment evidence and reconciliation before automatic acknowledgements. Account statements and partner products require separate eligibility checks.</p>
<?php else:?><ul><li><a href="https://developer.paypal.com/api/rest" target="_blank" rel="noopener noreferrer">PayPal API catalogue</a></li><li><a href="https://developer.paypal.com/api/transaction-search/v1" target="_blank" rel="noopener noreferrer">Transaction reporting</a></li><li><a href="https://developer.paypal.com/api/invoicing/v2" target="_blank" rel="noopener noreferrer">Invoicing</a></li><li><a href="https://developer.paypal.com/ai-tools/toolkit/" target="_blank" rel="noopener noreferrer">Agent toolkit and MCP</a></li></ul><p>Prioritise read-only reporting and a private dispute/refund review queue. Additional methods and payouts require separate merchant eligibility; the existing receiving connection is not evidence of that approval.</p><?php endif;?>
</details></section>
