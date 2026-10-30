# Publishing studio and Waithaka Ndegwa Connect

30 October 2026 · Africa/Nairobi. Continues the existing 33-area recovery register; none of its account, payment, delivery, source or device requirements is closed by this software.

## Channel coverage

| Platform | Native implementation | Assisted / separately verified provider |
|---|---|---|
| Facebook professional profile / Connect | Identity confirmation only | Posts, photos, videos, Reels, Stories, subscriber-only audience and native effects |
| Facebook confirmed Page | Existing text/link; owned public MP4 Video or Reel with persisted mutation boundaries | Photos, Stories, native tags/locations/audience options |
| X | Existing text/link; owned MP4 via separate `x_media` consent | Images, threads, polls and unverified options |
| Instagram | Existing JPEG and owned-video Reels | Stories, carousels, Instagram Login tagging, music and native effects |
| YouTube | Owned-video upload with visibility, audience and notification settings; reviewed channel management | Native operations outside the implemented studio |
| TikTok | Separately authorized Accounts API owned-video delivery | Photos, music and native effects |
| LinkedIn | Member text/link publishing | Video, documents, polls, images and multi-image formats |

A saved connection is not proof of account entitlement, real publication or settlement. Buffer may be used only through an actually returned and verified channel and a format implemented by the existing adapter.

## Facebook Connect

Public-facing community name: **Waithaka Ndegwa Connect**. Owner settings are at `/write/connections#connect-community`. The exact group or subscription URL must be supplied from Facebook; no group ID or membership is inferred. The public newsletter page links to the saved destination only after URL validation, otherwise it offers the established Facebook profile. Saving the URL does not rename a group or subscribe anyone. Email newsletter membership, Facebook subscriptions and website giving stay separate.

The Facebook variant has an explicit public/general or subscribers-only audience. Subscribers-only is accepted exclusively for assisted delivery, frozen in the approved revision and displayed in review. The operator must check the subscriber audience inside Facebook and verify the actual publication. No automatic profile or group posting is claimed.

## Video delivery and recovery

Facebook Page videos/Reels require the actual selected Page token, Page posting/read scopes, a public immutable owned MP4 and explicit public-audience/media-rights review. Reels use start, hosted upload and finish, persisting intent and the returned video ID. The returned upload URL is never followed: the documented fixed host/path is constructed. Ordinary Video uses the Page video endpoint. Processing/publication is independently checked against video ID, owning Page, description, permalink and provider status. Unsupported native options stop approval rather than disappearing.

X video uses a separate OAuth service with `tweet.read tweet.write users.read media.write offline.access`; existing text grants are unchanged. Register `/write/connections/callback/x_media` on the existing X web application and obtain the distinct user consent. If both text and video connections are saved, their selected account must match. Upload uses the current v2 initialize/append/finalize protocol, 4 MiB bounded segments from hash-verified owned media and bounded processing reads. The create-post boundary is persisted before its sole attempt. Known post ID is saved before author/media receipt checks. X rewrites URLs, so the exact submitted caption remains in the immutable local revision; receipt verification checks post identity, author and attachment rather than claiming identical returned text.

MP4 workspace limit: 512 MiB. Account/provider duration, dimensions, encoding, quotas and paid API entitlements still apply. This release does not purchase API access, enable accounts or publish a real post. Each actual public action still requires the existing exact-content approval and scheduling workflow.

Unknown initialization/upload/publication outcomes are not replayed automatically. Only saved processing or known-receipt states can resume. Ten processing/read-back checks and a 24-hour age bound stop indefinite polling; a reviewed resume only continues the existing saved record. A failed upload must be inspected before a new revision is approved. No crash recovery path allocates a replacement post silently.

## Primary API references checked

- Meta-owned Facebook collection: https://www.postman.com/meta/facebook/documentation/r56bjfd/facebook-api (Page tokens, Reel start/hosted upload/status/finish).
- Meta Page video reference: https://developers.facebook.com/docs/graph-api/reference/page/videos/ (documentation retrieval was rate-limited here; live capability remains subject to account acceptance).
- X v2 chunked upload: https://docs.x.com/x-api/media/quickstart/media-upload-chunked
- X initialization: https://docs.x.com/x-api/media/initialize-media-upload
- X post creation: https://docs.x.com/x-api/posts/create-post

## Validation and remaining acceptance

The isolated suite exercises both Facebook formats and X video, changed grants/accounts/content/assets, uncertain writes, processing recovery, bounded polling and cross-owner receipts. Browser fixtures render the actual six-channel composer and check caption escaping, preview updates, selection summary, Connect audience and responsive layout. Accessibility scans include the full composer. Full PHP/MySQL and browser CI plus production acceptance must pass before the release is called deployed. Genuine owner OAuth grants, selected Page/channel identity, actual media acceptance and owner-reviewed publication receipts remain independent acceptance requirements in issues #71 and #75. The exact Connect destination remains unset until supplied from Facebook.
