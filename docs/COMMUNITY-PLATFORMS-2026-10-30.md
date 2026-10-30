# Community publishing expansion

30 October 2026 · Africa/Nairobi. Additive continuation of the existing 33-area recovery register; existing account, settlement, sender, approved-source and physical-device acceptance stays open.

| Channel | Implemented route | Activation required |
|---|---|---|
| Threads | Native text/link and single owned public JPEG/PNG or MP4; saved processing container, exact profile and owned-post read-back | Separate Threads app/consent, registered `/write/connections/callback/threads`, actual profile confirmation |
| Telegram | Bot text and owned public JPEG/PNG/MP4 to one channel/supergroup; send receipt | BotFather token, exact @username or -100… ID, bot administrator/posting rights, explicit destination confirmation |
| Discord | Incoming channel webhook text/link messages; mentions and embeds suppressed; independent message read-back | Exact incoming webhook, read-verified guild/channel identity, explicit destination confirmation |
| Signal | Owner-declared assisted group/contact target, approved media download and private Writer completion reference | Exact private destination label and owner confirmation; send in Signal |

The shared channel inventory now drives ten draft variants, preparation limits, channel settings, weekly slots, campaign attribution and reporting controls. The database migration uses INSERT IGNORE; old channels, grants, settings, approvals and existing queued jobs are not rewritten. New channels start assisted, unselected in existing drafts. A new source URL never comes from a Signal group invite, Telegram invite, Discord invite or token-bearing webhook/API URL. New public profile identities are not invented or inserted into the public footer.

Configure services at `/write/connections#integrations-communities`; select delivery routes separately. Threads credentials are separate from Facebook and Instagram. Telegram and Discord setup performs only identity/permission reads; it never sends a test message. Credentials are encrypted, never rendered back in forms, logged in provider errors, put into approved destination snapshots or exposed through public APIs. Discord webhook paths are parsed against the exact HTTPS discord.com host; query strings, fragments and extra paths are rejected. No arbitrary-host redirect is followed. Signal stores a private label, not an invitation capability or member list. An owner-declared label never becomes a bot/webhook grant. Existing commercial fulfillment remains limited to its previously reviewed service destinations.

Each native delivery binds the immutable content revision, exact service, account, destination and grant fingerprint. Current provider identity/permissions are read again before sending. Create/publish intent is persisted first. An interrupted write cannot automatically allocate another message. Known Threads/Discord receipt IDs are persisted before follow-up reads, with a five-check bound; explicit resume reads the saved receipt and does not publish again. Threads inventory verification is bounded to the first 25 returned owned posts and stops for review when the post cannot be found.

Telegram returns a complete sent-message response. The workspace verifies the returned chat ID, message ID, text/caption and requested media receipt. It labels completion “Sent · Telegram acknowledged”. It does not claim an independent later-message GET, subscriber readership or reach. No bots' member lists, personal inboxes or private conversations are imported. Private t.me/c message links remain private workspace records.

Signal has no automatic delivery in this implementation. Its completion form requires the Writer to verify the exact named destination and approved content, then save a private reference without an invitation link. Status is “Completed · Writer confirmed”. No fabricated public permalink, official bot support or provider receipt is claimed.

Media limits: Telegram photo 10 MB; video MP4 50 MB; media caption 1,024 characters. Native provider image/video eligibility still applies. Threads single owned public JPEG/PNG (8 MB) and MP4 (workspace limit 50 MB) are implemented with preserved media descriptions up to 500 characters. Saved containers wait for provider eligibility; five bounded one-minute processing reads precede owner review. A known ready container publishes once, with the intent persisted first. Unknown creation/publication outcomes are never replayed. Changed media versions or a withdrawn source article block publication. Remote caption, media type, owned-post inventory and permalink are verified separately. Threads carousels/native options and Discord attachments/forums/threads remain assisted. Native options are not silently dropped. New reporting cards accept explicitly sourced manual metrics; missing data stays unavailable and publishing consent is never treated as an Insights grant. Signal does not gain automated membership or read-receipt analytics.

Primary references checked:

- Meta-owned Threads collection: https://www.postman.com/meta/threads/documentation/dht3nzz/threads-api
- Telegram Bot API: https://core.telegram.org/bots/api
- Discord webhooks: https://docs.discord.com/developers/resources/webhook
- Signal private groups: https://support.signal.org/hc/en-us/articles/360007319331-Group-chats

Validation includes isolated transport/uncertain-write fixtures, changed account/grant checks, permission denial, credential endpoint validation, migration idempotence, legacy-draft retention and actual ten-channel template/browser/accessibility checks. Production and genuine per-account consent/publication acceptance are separate. No actual post, paid service, audience import, group creation or queue activation is performed by code preparation.
