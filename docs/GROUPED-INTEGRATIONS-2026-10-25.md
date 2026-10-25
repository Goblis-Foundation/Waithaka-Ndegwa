# Grouped integrations
The connection hub groups Meta, Google, Finance, Meetings, other social platforms and AI/tools. Each capability has its own Connect/Reconnect or Manage action; OAuth credentials/callbacks are beside that service, not at the bottom. Missing credentials open inline setup; Save & connect proceeds to the actual provider authorisation. Provider-account confirmation remains required. Optional Pages do not replace the personal Facebook profile.

Finance includes Wise, M-Pesa, Pesapal and PayPal, each with focused setup; Wise links directly to its API/token/webhook section. Payment authentication checks do not initiate any payment, register a debit mandate or prove receiving readiness. API keys saved are distinct from a verified account. Dedicated finance/AI pages change only their provider’s whitelisted settings and retain blank secrets; owner, method and CSRF protections remain. Callback and permission details remain available within each service. No credentials are echoed.

Existing OAuth connections, approvals, confirmed destinations, scopes and channel routes remain unchanged. Creating the hub does not connect an account or grant more access.
