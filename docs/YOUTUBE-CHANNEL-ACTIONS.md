# Reviewed YouTube channel actions

`/write/google/youtube` is a private communications-owner workspace linked from Google workspace. It implements playlist creation, appending an owned video to an owned playlist, timed SRT caption upload, media-library thumbnails and single-comment moderation. Existing video-upload, analytics and read-only grants remain separate. No background job executes these actions.

## Account setup

The new `google_youtube_manage` service requests identity plus `https://www.googleapis.com/auth/youtube.force-ssl`. This is a channel-management grant, not a read-only permission. Google app verification, account restrictions, YouTube Data API enablement, custom-thumbnail eligibility and quota must be verified independently. Add `/write/connections/callback/google_youtube_manage` on the canonical HTTPS site to the existing Google OAuth client before connecting. Confirm the returned channel through the existing selection flow. An earlier alias, analytics or upload connection does not establish this grant.

Every operation checks the authenticated owner, matching `connected_by`, connected state, actual scope, confirmed subject, selected channel and grant revision. Before preparation and execution, `channels.list(mine=true)` must return the selected channel. Source videos/playlists/comments must belong to that exact channel. Callback discovery reuses the existing Google channel inventory; no provider client, billing account or paid quota is created.

## Review, execution and recovery

- GET displays local state only. A CSRF-protected, rate-limited preparation POST reads selected provider records and stores an encrypted immutable preview. Preparation itself never changes YouTube.
- Review binds exact payload, source revisions, account and channel. Applying needs another exact confirmation within 30 minutes, the current local version, unchanged grant and an immediate source read. These checks detect observed changes; YouTube does not provide a transaction spanning all those reads and a write.
- MySQL action and target locks serialize application requests. The execution marker is persisted before the request. An interrupted, failed, completed or unknown action cannot execute again. Known results may be rechecked using GET only. A crash before the remote ID is saved requires Studio review; it never creates a replacement automatically.
- Returned IDs are saved before independent verification. Explicit rejection, provider acknowledgement, processing/unknown outcome and independently verified results remain distinct. No credentials, raw provider errors or private content are written to logs or public reports.
- Keep up to 200 action records per owner, using encrypted communication settings and an owner-specific index. Unused drafts may be discarded; attempted actions remain evidence. Private media and caption/comment previews never become public website content or AI inputs.

## Supported operations and result limits

| Action | Validation and result |
| --- | --- |
| Playlist | Explicit private/unlisted/public visibility, title and description. Read back exact channel and content. |
| Playlist entry | Owned playlist and owned video; preserve existing items and append. An already-present video is rejected before mutation. Read back the returned entry ID and exact playlist/video relationship. |
| Captions | Explicit draft/public setting; timed UTF-8 SRT up to 100,000 bytes/1,000 cues. Existing language/name tracks are preserved. No deprecated auto-sync, translation, recording or transcription. Retain returned track ID; verify channel-owned video, metadata, serving status and downloaded SRT cue text/timing. Pending processing needs a read-only recheck. |
| Thumbnail | Ready immutable JPEG/PNG media-library asset, application limit 2 MiB and 4096 pixels per side; recheck size, hash and actual image. The private preview serves only exact approved bytes under owner authentication. Upload once; compare returned thumbnail URLs with video metadata. This is provider acknowledgement and metadata verification, not proof of pixel equality after YouTube transformation. Visual acceptance remains in Studio. |
| Comment | One exact owned-channel comment and its reviewed text/revision. Explicit publish/hold/reject request, always `banAuthor=false`; no reply, deletion or bulk moderation. Publish/hold need independent exact-text/state read-back. Rejection hides replies and cannot be reversed here. It is recorded only as acknowledged, requiring Studio review; absence from an API response is not misrepresented as independent proof. Provider restrictions may reject a requested transition. |

Quota is visible before action approval. At review time, playlist creation/entry, thumbnail and moderation writes cost approximately 50 units each; caption insertion costs 400, caption listing 50, and caption download 200. Source checks and rechecks add quota. The provider's current limits remain authoritative; the app never buys quota or retries a mutation automatically. Google's API thumbnail limit increased to 50 MB in September 2026; this application's smaller 2 MiB limit is intentional.

## Verification

`tests/youtube_actions_unit.php` runs isolated fixtures covering all five actions, immutable review, source changes, owner/account/channel isolation, grants and scope changes, timeout/denial/read-back recovery, no duplicate writes, pending captions, thumbnail bytes, rejection acknowledgement, malformed identifiers, SRT bounds, CSRF/consent and escaped production rendering. The standard PHP/MySQL runner includes it. Production templates are also included in responsive and automated accessibility fixtures.

These tests do not call a real provider. Deployment, actual Google consent, genuine owned-channel reads and separately reviewed real actions are distinct acceptance. Do not close provider issues or infer a real publication, settlement, delivery or private-device acceptance from this software implementation. All 33 retained work areas remain in the register.

## Official references checked 30 October 2026

- https://developers.google.com/youtube/v3/docs/playlists/insert
- https://developers.google.com/youtube/v3/docs/playlistItems/insert
- https://developers.google.com/youtube/v3/docs/playlistItems/list
- https://developers.google.com/youtube/v3/docs/captions/insert
- https://developers.google.com/youtube/v3/docs/captions/list
- https://developers.google.com/youtube/v3/docs/captions/download
- https://developers.google.com/youtube/v3/docs/thumbnails/set
- https://developers.google.com/youtube/v3/docs/comments/setModerationStatus
- https://developers.google.com/youtube/v3/docs/comments
- https://developers.google.com/youtube/v3/revision_history
