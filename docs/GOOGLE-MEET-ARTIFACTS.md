# Selected Google Meet artifacts

Implemented route: `/write/google/meet`, linked from Google workspace. The route requires the authenticated communications owner. GET only shows local state; provider reads require an explicit CSRF-protected POST and are rate-limited.

## Consent and account binding

`google_meet` is a separate OAuth service using the identity scopes and only `https://www.googleapis.com/auth/meetings.space.readonly`. Its callback is `/write/connections/callback/google_meet`; register this exact HTTPS callback in the existing Google OAuth client before connecting. Enable the Meet REST API in that client's Google Cloud project. Provider app verification, account eligibility and Workspace restrictions must be accepted independently. No existing grant is silently upgraded.

The returned Google subject is confirmed through the existing account-selection flow. Every provider operation checks owner, connected status, selected subject and actual scope. Selection forms bind the connection ID, subject, selection, owner, verification time and scopes. A changed identity/grant fails before use, and is checked again before displaying results or saving a transcript.

## Reading and retaining artifacts

- Read ended conferences from the last seven days on request, then select one conference to read its existing recordings/transcripts. Provider timestamps and resource names identify the meeting; no invented title or participant directory is fetched.
- Build Google Drive/Docs browser links from strict file IDs. Do not forward credentials to `exportUri`, download recordings, enable recording/transcription or change a meeting.
- A selected transcript must be `FILE_GENERATED`, ended, and returned with the exact requested name. Explicit retention consent is required. Read all transcript-entry pages, up to five pages/500 entries and 250,000 text bytes; invalid/repeated pagination, duplicate entries, mismatched parents, empty results and oversized responses fail without saving partial text.
- Persist copies in encrypted `comm_put` settings under a dedicated owner index. Stable per-owner/account/transcript IDs make duplicate submissions idempotent. Preserve text literally and escape it when rendering; number speakers consistently without importing participant identities.
- Retained transcripts are private records, never editorial posts, public library items, AI inputs or automatic tasks. The owner may read/remove a saved copy after disconnecting. Removing overwrites the local encrypted value and removes its index entry; the Google original stays intact. Existing backup retention still applies.
- Keep at most 100 copies. There is no background polling, automatic import, shared view or recording activation.

Google retains Meet API transcript entries for 30 days after the conference ends. API entries can differ from a later-edited Google Doc. An empty response is not proof that no meeting or artifact existed.

## Verification and outstanding acceptance

`tests/google_meet_unit.php` covers account/owner isolation, grant changes during reads, exact artifact validation, pagination, duplicate imports, size limits, no partial persistence, retained-copy removal, CSRF/consent dispatch and escaped rendering using an offline provider fixture. The standard test runner includes it on PHP 8.3/8.4 and in production validation. The real production template has disconnected, selected and saved synthetic fixtures in the responsive browser and accessibility suites.

Live acceptance remains pending deployment, Google OAuth callback/API setup and explicit account consent. After consent, verify one genuinely available conference and transcript, compare the private copy with the Meet API source, repeat the import to confirm no duplicate, then remove the local copy and verify the original remains in Google. Do not mark this completed on unit tests alone.

## Official API references reviewed

- [Work with artifacts](https://developers.google.com/workspace/meet/api/guides/artifacts)
- [Conference records list](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords/list)
- [Transcript resource](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords.transcripts)
- [Transcript entries list](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords.transcripts.entries/list)
- [Transcript entry fields](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords.transcripts.entries)
- [Recording resource](https://developers.google.com/workspace/meet/api/reference/rest/v2/conferenceRecords.recordings)
- [Google Docs destination](https://developers.google.com/workspace/meet/api/reference/rest/v2/DocsDestination)
