# Writer provider access and approved-impact authority — 29 October 2026

## Scope and reason
This is an additive least-privilege hardening release for Waithaka Ndegwa's personal website and private Writer application. It preserves the existing database, confirmed provider grants, publication drafts, already approved content and GitHub/AWS deployment flow. The stable owner account (including its approved aliases) is the authority for personal channels and public impact source configuration.

Audit identified that any authenticated Writer could previously reach the owner’s shared **Connections** settings, inspect provider reporting, disconnect a shared account, select publication routes or approve and schedule external publishing. The private **Goal 2030** feed configuration and source synchronization were likewise accessible without an explicit owner check. The Google Workspace page used shared saved connections for reporting and selected-file Picker access without verifying `connected_by` on the requesting Writer.

## Implemented boundaries

- **Personal social and provider connections:** collaborators retain a restricted, read-only `/write/connections` overview with no provider IDs or credential inputs. Credential changes, OAuth starts/callbacks, identity confirmation, reports, disconnects and channel-route writes require the configured owner. The provider callbacks still require the original session-bound OAuth state.
- **External publication:** approval, scheduling, remote-job completion, retry and cancellation actions require the owner. The shared weekly automation configuration also requires the owner for edits; its read-only screen and the existing draft creation/review routes remain available to authenticated collaborators.
- **Google Workspace:** the authenticated requesting Writer ID is threaded into personal GA4/Search Console/YouTube reports, Google URL diagnostics, Postmaster, Google Doc Picker-token and selected-file imports. Any `publishing_connections.connected_by` mismatch fails before a token is returned or a provider request executes. Private connection-health metadata for another Writer is omitted. Trusted server-side aggregate visitor preparation remains able to use the existing service connection without a request-user context.
- **Global API settings:** owner-only for shared Google API keys, Gemini model configuration/usage and paid/synthetic performance probes. Existing per-account selected-calendar reads remain isolated by Writer ID.
- **Goal 2030:** collaborators retain a safe, read-only `/write/impact` overview with no source URLs, tokens or update controls. Source endpoint URLs, bearer tokens and manual checks require the configured owner. Existing schema-v2 signed approval requirements, correction/withdrawal logic, missing-is-not-zero safeguards and private source data boundaries remain unchanged.
- **Calendar reconfirmation integrity:** the existing optimistic concurrency update for selected calendars now checks the affected row. A changed authorization cannot be incorrectly reported as a successful save; it requires a fresh review.

## Tests and release conditions

- Synthetic Google connection with `connected_by=7` can be read by Writer 7, but another Writer fails *before* querying external reporting endpoints.
- Synthetic non-owner HTTP session receives restricted read-only Goal 2030 and Connections overview pages without token/credential controls; changes, source checks, provider actions, Google global configuration and social approval/automation return HTTP 403 with valid CSRF. CSRF errors still take precedence for invalid submissions. The full original owner publishing HTTP journey runs under a disposable synthetic owner and restores the previous test configuration on exit.
- Core publishing tests assert owner-only mutation routes and preserve collaborators’ draft paths.
- Every existing PHP/MySQL, browser, privacy, newsletter, payment and AWS deployment gate remains required. This patch makes no genuine social, payment or email API call and does not release the mail outbox.

## Remaining external acceptance — no drop

GitHub issues #70–76 remain open. Connecting personal Meta/WhatsApp, LinkedIn/X/TikTok products, live merchant settlement, sender postal/inbox acceptance, four source-certified monthly impact artifacts, Google/Microsoft account reauthorizations and physical-device/field-performance tests all require separate genuine evidence. A successful CI/deployment does not close them.

## Owner access continuity

The original active owner account, not an email alias by itself, determines authorization. `isaac@waithakandegwa.com`, `isaac@goblisgroup.com` and `isaac@goblisfoundation.org` continue to map through the existing approved authentication and mailbox-proof process. No duplicate super-administrator user, domain-wide elevation or OAuth bypass is introduced.
