# Release changes — 2026-10-21

## Recovered foundation

Based on the recovered V9 archive dated 2026-08-31. The pristine local git baseline is 5d69ca2. Original site branding, personal photographs, editorial topic structure, article addresses and consent records remain the migration foundation. The recovered archives contain source code; the live database, private configuration/application key and uploaded files were not present.

## Added

- Startup Journey index, journey pages, ordered article chapters, starting baseline, phases, evidence basis, outcomes and a serialized one-public-active-venture rule.
- Founder submission form, private review queue, source/consent checks and controlled publication.
- Double opt-in email subscriptions, token confirmation/expiry/replay controls, unsubscribe and RSS.
- Publishing packages, platform variants, immutable approved snapshots, destination binding, scheduling, completion evidence and event history.
- Verified OAuth/resource flows for Google, Meta, LinkedIn and X; verified Buffer channel selection.
- Limited direct adapters, supported Buffer adapter and assisted completion for all six channels.
- Read-only Search Console, GA4 and YouTube channel reports with separate grants.
- Explicit migrations, encrypted database sessions, encrypted deduplicated email outbox, SES/SMTP delivery, S3 public/private storage and a separately enabled worker.
- AWS data/edge templates, Beanstalk hooks/settings, IAM example, build script and isolated CI/test runner.

## Corrected

- Live article changes remain private drafts until explicit publication; stale saves fail without overwriting another edit.
- Existing slugs and original publication dates are retained.
- Missing CSRF tokens fail, preview requires authentication, and sanitization also examines lifted child nodes.
- Consent mutation requires an open unexpired request; repeat/racing transitions are rejected.
- Payment state is bound to the stored provider/order/amount/currency/merchant/environment and reconciled without trusting callbacks alone.
- Unknown social/email outcomes stop for review instead of blind retry.
- A cancelled or obsolete publishing task cannot send a stale reminder. Sent outbox records repair interrupted dependent bookkeeping without another send.
- Both domains have a path-preserving canonical design. Private/token routes suppress analytics, indexing, cache storage and referrers.
- Only validated proxy ranges are trusted. Required application keys fail closed for installed CLI/web runtimes.
- Strict legacy private-proof paths map to the same S3 key when S3 mode is enabled.

## Explicit limits

No AWS account resources were deployed; no live data was imported; no DNS was changed. Provider credentials and permissions are not fabricated. Newsletter subscription management is present, while newsletter campaigns/broadcasts are not. Native YouTube upload and custom TikTok Direct Post are not implemented. Google sign-in for readers, a universal social inbox and cross-platform engagement analytics are not included.
