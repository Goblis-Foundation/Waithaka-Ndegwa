# Instagram Login: reviewed image and Reel delivery

The native adapter supports JPEG posts and owned-public-video Reels on the confirmed professional Instagram account. The owner keeps their existing personal professional/Creator identity. No Facebook Page, business portfolio, account conversion, real post or new paid product is created by implementation or deployment.

## Account and media requirements

- Use the existing `instagram` connection, confirmed returned account ID and actual `instagram_business_basic` plus `instagram_business_content_publish` scopes. Generic configured branding and an Insights/comment/message grant cannot substitute publishing authority.
- Before container creation and publication checks, the API must independently return the exact approved Business/Creator profile. A changed connection, channel, scope, owner or content hash stops the saved job before another mutation.
- Direct Reels require an immutable, ready, public media-library MP4/MOV snapshot of at most 512 MiB. Private assets are never made public automatically. The asset metadata must still match before creating the container. Meta fetches its public URL and checks video/audio encoding, dimensions, duration and account eligibility. Media transformation and provider-side fetch integrity are not independently certified by this adapter.
- The owner explicitly reviews the public professional-account audience, feed-sharing choice, media rights and provider requirements. These fields survive draft normalization and immutable revision approval. Feed sharing starts off; settings confirmation starts unchecked.
- Instagram Login's official collection excludes tagging. Location/user/native tag choices remain in assisted drafts and fail the direct capability gate. Stories, carousels, stickers, music and AI disclosure remain assisted until their exact account/API capability is independently verified; do not silently omit metadata or force a different account type.

## Saved publication boundaries

1. Verify the immutable revision and confirmed profile. Persist an initialization marker before creating a single image/Reel container. Container creation itself is not publication.
2. Save the returned container ID before polling it. Check no more than five times automatically, at approximately one-minute intervals, with a 24-hour age boundary. The same known container may be explicitly resumed after owner review. No replacement container is created by a resume.
3. Only a `FINISHED` container may enter publication. Persist `publish_attempted` before `media_publish`. An incomplete response, missing ID or crash cannot replay that request. An already-`PUBLISHED` container without a known publication ID requires native-profile review.
4. Save the returned publication ID before any read-back. Verify exact caption, media type/product, safe Instagram permalink and membership in the approved account's media inventory. Inventory reads are capped at four pages of 25 IDs; provider `paging.next` URLs are never followed. Known IDs can be rechecked without another publication request.
5. Pending read-back remains pending, bounded to five automatic verification checks. Mismatched, missing or temporarily unavailable receipts do not become verified publication. Explicit resume only checks that known publication again. If outcome is unknown without a known ID, inspect Instagram before creating any separately approved replacement.

The existing cross-host MySQL worker lock and immutable publishing jobs serialize delivery. State is checkpointed before remote side effects. Only the owner can approve/schedule/resume/complete/cancel external publishing work. Scheduling existing approved revisions remains idempotent. No outbound queue is released by deployment.

Legacy JPEG container jobs retain their existing checker rather than being recreated. Their processing polls are now bounded; an already-published legacy container becomes ambiguous for manual review rather than polling forever or issuing a second publish request.

## Transport and verification

All credentials stay in Authorization headers on the fixed versioned `graph.instagram.com` host. Only the adapter's numeric profile/container/media routes are accepted. Redirects, provider-selected paging hosts and arbitrary endpoints are blocked. Each HTTP response is capped at 1 MiB and 45 seconds. Raw provider error bodies are neither shown nor retained. Expired-token code 190, permission and rate-limit outcomes receive safe classifications.

`tests/instagram_native_unit.php` isolates provider calls and persistence. It covers JPEG/Reel success, exact feed choice, asset/hash/grant changes, lost initialization/publish responses, missing IDs, five-check limits, rejected/expired/already-published containers, consumer/wrong profiles, caption/product/permalink/ownership mismatches, cursor recovery/loops, safe errors, access-token reuse and legacy no-replay behavior. Production Reel-setting partials are included in responsive and accessibility fixtures. The standard PHP/MySQL runner includes the suite.

Real provider acceptance still needs one owner-reviewed owned video, actual account/grant checks and a genuine verified publication receipt. No sample post or account change is made merely to generate test evidence. Keep issues #71/#75 and the 33-area register's provider-acceptance requirements until that evidence exists.

## Official references checked 30 October 2026

- Meta's official Instagram Login collection: https://www.postman.com/meta/instagram/folder/1z5vxzu/instagram-api-with-instagram-login
- Meta's official Reel container/status/publish examples: https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api?entity=request-23987686-3fe78620-2258-44b6-893f-42d76c7200d7
- Content publishing: https://developers.facebook.com/documentation/instagram-platform/content-publishing

The direct Meta documentation was unavailable/rate-limited during this check. Implementation relies on the official Meta collection for the verified Reel flow and Instagram Login permission/host limitations. Unverified formats are not inferred from third-party descriptions. The application's 512 MiB video limit is intentionally lower than the collection's provider limit.
