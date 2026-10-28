# Google productivity and private preparation — 28 October 2026

This extends the module audit and preserves every previously tracked commitment. Implementation is distinct from account consent and proof of a genuine provider action.

## Implemented workflows

- `/write/google/actions`: prepare immutable selected text, tab-separated tables or task notes; verify the account and destination; explicitly review and create once. New Docs and Sheets are private files in My Drive. Tasks use the one confirmed list. No existing document, spreadsheet or task is edited or deleted.
- Each action has an owner, version, exact approval digest, 30-minute review validity, connection fingerprint and durable encrypted history. Grant or account changes block execution. Provider results are independently read back. Uncertain creation is never automatically repeated; a known remote record can be checked using GET only. Spreadsheet cells are literal strings, including formula-looking input.
- `/write/google/sync`: optional private refresh of at most 30 selected calendars. Each worker invocation processes up to four due calendars. Complete paginated responses replace a bounded encrypted snapshot atomically; deltas handle cancellations and expired tokens trigger a fresh full read. Cached fields exclude attendees, descriptions, attachments and transcripts. Stale, changed-account or out-of-window reads fall back to the live agenda. Public booking availability always remains live.
- Optional authenticated Google notifications mark a calendar for refresh. Random channel secrets are retained only as hashes, resource and sequence checks reject spoofing/replays, and channels renew with overlap. Notifications cannot supply event contents or change appointments. Turning refresh off purges event copies. Preference generations and shared commit locks prevent a late response from restoring disabled data.
- Operations shows private editorial preparation, approved impact refresh, aggregate audience refresh and selected-calendar refresh separately from outbound delivery. Monitoring records stage state/time/safe counts, not mail contents, calendar titles or provider secrets.

## Activation boundaries

Calendar refresh and notifications default off. Docs/Sheets require the existing confirmed `drive.file` scope. Tasks has its own OAuth grant and confirmed list. Deployment creates no external document, task or notification channel and sends no marketing email. The previously paused delivery worker and pending backlog are not released by these features.

No Meet artifact import, BigQuery/Looker integration, YouTube captions/playlists/moderation, additional social/fitness adapter, genuine settlement or source-data approval is claimed. Those commitments remain visible in the implementation register and issues #70–76.

## Verification

Local tests cover immutable approval, owner and grant isolation, exact read-back, literal spreadsheet cells, no-retry outcomes, paginated sync, token reset, notification identity, stale fallback and disable-during-request races. Browser fixtures use the actual views and assets. CI runs application tests on PHP 8.3/8.4 with MySQL 8.4, responsive interaction checks and pinned axe-core accessibility scans. See the subsequent release acceptance record for actual run results; this file does not itself certify production/provider activation.

## Primary implementation references

- [Google Calendar incremental synchronization](https://developers.google.com/workspace/calendar/api/guides/sync)
- [Google Calendar push notifications](https://developers.google.com/workspace/calendar/api/guides/push)
- [Drive multipart uploads and text-to-Doc conversion](https://developers.google.com/workspace/drive/api/guides/manage-uploads)
- [Sheets spreadsheet creation](https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets/create)
- [Sheets literal and formula cell values](https://developers.google.com/workspace/sheets/api/reference/rest/v4/spreadsheets/cells)
- [Google Tasks creation](https://developers.google.com/workspace/tasks/reference/rest/v1/tasks/insert)
