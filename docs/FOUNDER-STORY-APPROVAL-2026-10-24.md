# Founder final-copy approval — 24 October 2026

Founder Stories require the founder's approval of a frozen public-intended story snapshot before the article can publish. General permission, claims review and final-copy approval remain separate. A linked story cannot be detached or moved to bypass the approval process.

The Writer workflow starts in Founder submissions. Draft a linked article, save it privately, then request review from the submission screen. Confirm the founder's own email, international WhatsApp number, and recorded permission for review notifications. Nominator details are never assumed to be the founder's identity.

The founder receives one private, random capability link through separately tracked email and WhatsApp notifications. Database records retain only its hash; notification payloads are encrypted. GET/HEAD does not approve. POST requires session CSRF and an explicit decision. The snapshot includes public story text, title, introduction, image/video URLs and captions, series/place labels, search title/description, and existing public evidence/updates. Internal writing plans, private research, intake, contact details and approval evidence are not shared. Public evidence-card edits for linked Founder Stories must be incorporated into the article draft and sent for approval; their separate publishing route is locked.

The founder can approve the exact version, decline, or propose an edited title/introduction/story and notes. Proposals stay private. The editor can import them into a private draft, refine them, and request another founder review. Publication compares the saved final version against the approved hash. A changed version needs another review. Pending changed drafts supersede old links; identical pending drafts cannot consume another round.

There are at most three requests per story. A decline, third response without approval, or unanswered expired request discards the story from publication and archives its post. Snapshots and audit records remain private. Discarded stories cannot be resubmitted through ordinary moderation. There is no permanent deletion.

The configurable response window defaults to 14 days (1–30 allowed). It begins only after both providers have accepted their notifications and never moves because of a worker poll. Provider acceptance does not prove delivery or reading. Unconfigured providers are marked blocked. Unknown outcomes are ambiguous and never automatically resent; retries require operator reconciliation. If both notifications cannot be accepted within 30 days, the unapproved story is archived with a delivery-failure reason rather than attributing refusal to the founder.

A dedicated worker handles only requested Founder Story notifications and expiry independently of paused newsletter/social/payment queues. No existing campaign queue is enabled. Email uses the existing transport, suppression controls and personalized branded email design. WhatsApp uses a separately configured Cloud API sender, saved encrypted access token, opt-in evidence and a Meta-approved template with body parameters: founder name, story title, round number, private review URL. Facebook/Instagram login alone does not supply a WhatsApp sender.

Deployment requires migration 170 through the existing backup/migration/release workflow. No real founder notifications, production fixtures, test bookings or social posts are created during validation. Tests use guarded disposable MySQL databases, local HTTP and injected provider acknowledgements. Live email/WhatsApp acceptance is not claimed until verified with an authorized recipient.

Official provider references:
- https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/overview
- https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/send-messages

