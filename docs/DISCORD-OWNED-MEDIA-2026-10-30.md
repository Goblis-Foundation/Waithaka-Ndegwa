# Discord owned-media completion

30 October 2026 · Africa/Nairobi. Continues the deployed Threads media and nine-service launch-readiness work. All 33 work areas and seven independent external acceptance streams remain retained.

## Implemented behavior

The direct Discord webhook route now accepts one owned public JPEG/PNG or MP4 up to a conservative workspace limit of 10 MB, with an optional description up to 500 characters. Other files, arbitrary remote media, private library files, native options, forums and thread destinations remain assisted. The separate Threads setup description now matches its deployed photo/video capability.

Before sending, the current verified server/channel/webhook, exact grant fingerprint, immutable content revision and library snapshot are checked. The library stages and hashes the exact original version. A changed/unavailable file or withdrawn public source article stops before mutation intent. Mention controls remain disabled and embeds suppressed. Multipart uses one `files[0]` part plus `payload_json` with a deterministic SHA-256 filename and the approved description. Server paths and storage credentials are never serialized into message content or saved provider state.

A creation marker is persisted before the single POST with `wait=true`. A timeout without a message ID remains ambiguous and cannot recreate the message. A known message ID is persisted before verification, so interrupted or mismatched receipt checks can read that same message without another upload. Temporary original files are released on success and failure.

Verification reads the known webhook message and checks exact caption, webhook/channel, absence of mentions and one matching attachment. Its MIME, size, description, filename and original bytes must match the approval. The fresh signed URL is restricted to the exact `cdn.discordapp.com/attachments/{channel}/{attachment}/{filename}` path and permitted signature parameters. Reads use no provider authorization header, follow no redirect, have a 20-second time limit and stop after the approved byte count. Signed URLs and file bodies are never persisted or logged. If Discord transforms an image or bytes cannot be established, the job remains unverified for owner review; it does not claim byte identity or retry publication. Five read checks bound verification before an explicit reviewed resume.

Relevant owned images can be suggested during preparation only when they match the context, permitted MIME and 10 MB limit. New channels and existing drafts/queues do not become enabled automatically.

## Verification

Expanded isolated fixtures cover the multipart shape, exact attachment descriptions, success for JPEG/PNG/MP4, changed metadata/bytes, signed-URL boundaries, missing source storage, source withdrawal, temporary-file cleanup, uncertain send and receipt-only recovery. Existing media-library storage tests remain authoritative for real local/S3 version, MIME and hash materialization. Production account authorization and an actual owner-approved publication are separate from offline fixtures and deployment.

## Official references

- https://docs.discord.com/developers/resources/webhook#execute-webhook
- https://docs.discord.com/developers/resources/message#attachment-object
- https://docs.discord.com/developers/reference#uploading-files
- https://docs.discord.com/developers/reference#signed-attachment-cdn-urls
