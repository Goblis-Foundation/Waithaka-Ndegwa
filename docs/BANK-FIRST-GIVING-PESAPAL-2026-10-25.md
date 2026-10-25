# Bank-first giving and Pesapal connection — 25 October 2026

Supersedes the previous hosted-Wise-link-first instruction: show published USD/ACH, EUR/SEPA and GBP/Faster Payments accounts first, then the permanent Wise link. USD remains initially open; Card remains the first payment tab with USD default. Existing bank details, enabled currencies, hosted link and notification subscriptions are preserved. Private receiving details follow the same order and each account retains its own save control.

Payment rail assets are compact typographic method identifiers, not invented certification seals or a claim of direct scheme membership. They are shown on the bank transfer tab, public account headings and private currency tabs. Banks may offer standing orders; this site does not initiate an automatic bank debit.

Live inspection before implementation: Pesapal connection page selected Sandbox; consumer key and consumer secret were not marked saved; verification was disabled and no IPN ID was shown. No live card connection or recurring entitlement is claimed.

The dedicated Pesapal screen now displays exact callback URLs, setup status and an owner/CSRF/rate-limited Connect & verify IPN action. It authenticates with the saved environment-specific merchant credentials, reuses an exact existing IPN or registers this website's GET callback, then independently reads it back before saving. Wrong URLs, malformed/error lists, inactive or differently configured callbacks fail verification. Configuration fingerprints invalidate stale verification after credential/environment changes. No order, capture, transfer, recurring plan or payment is created by connection verification.

Live activation still requires the intended merchant's consumer key and secret entered privately in Writer. The site does not reuse another organisation's credentials. Provider authentication and IPN acceptance do not prove settlement or recurring-payment approval.

Primary references:
https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/authentication
https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/registeripnurl
https://developer.pesapal.com/how-to-integrate/e-commerce/api-30-json/getregisteredipn
