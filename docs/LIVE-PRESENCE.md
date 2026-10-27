# Direct live presence — 27 October 2026

This supersedes the GA4 realtime-based Now description in PUBLISHING-DESK-RELEASE.md. Production verification confirmed that independent current-month/year reports were refreshing, while the realtime Now reading remained unavailable. The public Now counter therefore measures current website presence directly rather than relabelling a delayed or absent Google response.

## Measurement

Visible public pages with explicit Analytics consent heartbeat every 20 seconds. One existing browser session is counted once even with multiple tabs. Hidden/closed tabs send a leave message; crashed or disconnected tabs expire after 60 seconds. Sequence numbers and short-lived tombstones prevent a delayed heartbeat from resurrecting a closed tab. Polling or simply loading a page does not increment any count. No synthetic visitors are seeded in production.

## Privacy and safety

The server stores only HMACs of the existing necessary session ID and per-page random tab nonce, timestamps and sequence numbers in the encrypted settings store. It does not store raw session IDs, account identity, page URLs or IP addresses in the presence map. Existing request rate limits use the site's normal hashed-IP mechanism. Analytics consent is required for a visible heartbeat; revocation can always remove previous presence. Mutations require the exact same origin, current session CSRF and bounded input. Expired entries are removed during subsequent heartbeats and the existing maintenance worker. Private pages do not load the public widget. Saturated, corrupt or unavailable data is withheld rather than reported as a false number.

## Period totals

Current-month and current-year totals remain separate source-bound GA4 totalUsers reports. They refresh every five minutes subject to Google's processing delay. Both labels and date ranges roll over automatically in the verified property time zone. Do not add monthly uniques to calculate a year, sum daily unique users, infer people from page views or increment numbers in browser JavaScript.

## Tests and release

Pure-state tests cover tabs, browsers, replay, late messages, expiry, invalid origins and capacity. An isolated HTTP suite uses real application routes, cookie jars and CSRF tokens against a disposable loopback MySQL database. It tests denial without consent, cross-origin denial, multiple tabs, multiple browsers and leaving. No test visits are added to the production counter. The existing production acceptance must still observe fresh independent period data and an advancing current-presence read timestamp.
