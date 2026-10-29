# Sitewide settings authority — 29 October 2026

## Critical access boundary

The legacy `/write/settings` route configures shared Google Analytics and Search Console identifiers, contact recipient and sender email addresses, reCAPTCHA controls, OpenAI model/API credentials and M-Pesa, Pesapal and PayPal merchant environment/keys. The route previously required only a Writer login, so a collaborator could alter sitewide communication, collection and financial configurations. This was a materially different authorization boundary from the owner-only payment integrations and OAuth setup already implemented.

## Implemented behavior

- The authenticated Writer ID must match the configured active owner from `comm_config()['owner']`; neither an email string alone, shared company alias nor an absent owner ID grants access.
- The owner retains the existing complete settings workflow, including CSRF and its saved settings. The patch does not change any saved provider token, environment, merchant callback or email sender.
- Other Writers receive an informative, private and uncached **read-only** page with no existing credential values or fields. A valid-CSRF POST returns HTTP 403 and changes no setting. An invalid-CSRF POST still returns HTTP 419 before authorization.
- Global configuration is not copied into the read-only view. Personal account/security work, private drafts and delegated Writer modules remain available.
- Real production acceptance must verify the exact owner account and ensure that unrelated OAuth accounts cannot bypass this boundary. No genuine payment, newsletter, social post or provider test is created as part of this code release.

## Test and backlog boundaries

`tests/implementation_http_test.php` now checks the read-only view, denied global mail/analytics/payment mutation and retained CSRF boundary against a real isolated synthetic Writer session. The established PHP 8.3/8.4, MySQL 8.4, browser accessibility and AWS deployment checks remain mandatory.

Outstanding issue #70–76 remains unchanged. In particular, this settings protection does not supply merchant settlement (#72), mail delivery acceptance or the correct sender postal address (#73), and it does not create external social OAuth rights (#70/#71). The 33-area implementation register remains intact; these controls are additive security corrections.
