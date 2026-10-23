# Public discovery and private reporting

This module extends the existing PHP application. It does not deploy infrastructure, connect an account, submit URLs or send a message when installed. Migration `130_discovery.sql` adds its own tables without changing recovered content.

## Public search

`GET /search?q=phrase&type=all&page=2` searches published My Thoughts articles, approved founder stories, Startup Journeys, Resources and Watch pages. The content filter accepts `all`, `post`, `founder`, `journey`, `resource` and `video`. The default page size is 24.

Search matches the literal phrase, ignoring Unicode letter case. Percent signs and underscores are ordinary characters. Input is limited to 200 characters. Results sort by publication time descending, then canonical URL for a stable tie break. The count covers the complete matching set, including results beyond the former 60-post and 1,000-resource boundaries. Page one has a clean canonical URL; malformed and out-of-range pages return HTTP 404. Search result pages have `noindex,follow`, and their query text is excluded from the site's analytics.

An article must have a due, non-null publication date and `published` or `scheduled` status. A founder card is the public associated article after all existing founder approval, permission, claims-review and consent-record gates pass. Multiple submissions referring to the same article produce one card. Submission identity, contact information, proposed claims and private evidence never become searchable. A post without the founder permission gates may remain an ordinary published article; those private submissions do not classify it as a founder story.

A journey must be explicitly published and due. Its searchable content includes its public baseline, question, outcomes, published episode summaries and public experiment log. Public article evidence cards and due correction notes are searchable too. Private working copies, future correction notes and archived experiments remain excluded. The editorial hook supplies only frozen public resource/watch projections. Built-in original blank tools have an explicit `builtin` flag and a known template URL; they appear without a fabricated publication date. Other resources and Watch pages require actual due publication dates.

The implementation streams explicit public database projections and retains only matching card data. It does not impose a silent content-count cap. It is intended for this personal publishing platform; a very large archive should move the same projections and visibility rules into a dedicated search index after measurement, preserving exact counts, consent gates and deletion handling.

## Search Console reports

Open **Writer → Discovery → Google Search Console**. Connect and confirm a property through the existing Google Search Console connection first. The module uses the already supported `webmasters.readonly` permission; it does not request a new Google grant.

An authenticated, CSRF-protected refresh makes eight reads through Google's documented Search Analytics `query` endpoint:

| Report | Current period | Previous period |
|---|---|---|
| Property totals, no dimensions | One request | One request |
| Top queries | Up to 100 rows | Up to 100 rows |
| Countries | Up to 100 rows | Up to 100 rows |
| Devices | Up to 100 rows | Up to 100 rows |

Choose 28 or 90 days. Both periods have equal length, are adjacent and do not overlap. The current period ends three days before the refresh date in `America/Los_Angeles`, the API's reporting timezone. Requests use `type=web`, `dataState=final` and `aggregationType=byProperty`. The saved record states its property, dates, completion time and row bounds.

Property totals come from the undimensioned requests. They are not sums of a limited query table. Search Console does not promise every query row, and anonymized or omitted rows cannot be treated as zero. A missing row is shown as **Not returned**; a failed refresh is recorded as failed and retains the previous complete snapshot. A failure never becomes a zero-traffic report. The application does not claim that a traffic change was caused by an individual campaign.

Reports bind to the verified account, confirmed property, scope grant and verification observation. A changed connection/property during a refresh discards the incomplete result. A newly selected property does not inherit the previous property's report. Credentials, raw provider error bodies and private recipient details are not returned to the reporting views. Reports are private and served with `no-store` and `noindex` headers. Refreshes are limited to four per Writer account per hour.

The page links to the actual Google Search Console and Bing Webmaster Tools consoles for their other available reports. It does not invent an API for current console-only Insights, AI-search, indexing or inspection dashboards. Google's general Indexing API is not used for ordinary blog articles.

Official protocol references, checked 21 October 2026:

- [Search Analytics: query](https://developers.google.com/webmaster-tools/v1/searchanalytics/query) — endpoint, scopes, inclusive Pacific dates, dimensions, limits and finalized-data semantics. The page identifies its update as 11 August 2026.
- [Search Console](https://search.google.com/search-console) — linked console, no automatic dashboard import.
- [Bing Webmaster Tools](https://www.bing.com/webmasters) — linked console, no automatic AI-report import.
- [Google Indexing API prerequisites](https://developers.google.com/search/apis/indexing-api/v3/quickstart) — the API's restricted content eligibility; this site does not use it for ordinary articles.

## IndexNow: local queue before any external notification

### Permission and runtime gates

Notifications are disabled by default. All of these must be true to contact IndexNow:

1. `APP_ENV=production` and a canonical HTTPS origin with no custom port.
2. `WORKER_ENABLED=1` for the existing CLI worker.
3. `INDEXNOW_ENABLED=1` in environment configuration.
4. The Writer has checked **Allow IndexNow notifications for this site** and saved the preference.
5. A valid ownership key exists and the authenticated CLI worker invokes `discovery_work()`.

The public and Writer HTTP routes cannot send IndexNow requests. **Check public URL changes** is a local database reconciliation only. Test environments require an explicitly supplied in-process synthetic transport and cannot reach the real endpoint. Development and staging do not send notifications through this module. Staging remains safe even if a restored database contains an enabled Writer preference.

Saving preferences generates a random 64-character ownership key if needed. The exact `/{key}.txt` path returns the key as UTF-8 plaintext only while the Writer preference is enabled. No other guessed key is served. The form can rotate the key. Opening the local proof URL confirms only that this site serves it; it is not a claim that IndexNow has validated the domain. Protect the key like a domain-verification capability even though the protocol requires its exact URL to be publicly retrievable.

### Public inventory and scheduling

`discovery_sync_public_urls()` compares the complete current public projection with a stored inventory. It queues one revision for an addition, public-content change, reappearance or removal. It must run after a content transaction commits. Editorial publishing already calls it through a guarded after-commit hook; the worker also performs reconciliation, so scheduled releases and deletions are found without depending on every editor write path.

The inventory includes canonical article, journey, resource and Watch URLs and their public collection pages, Start Here, home and About. Public article evidence/corrections, journey episode/experiment content and complete frozen resource/watch hashes participate in change detection. Private drafts do not enter the inventory. Existing timestamps alone are not used to detect child-content or same-second HTML changes. Old pending revisions are cancelled when superseded.

A URL is eligible only on the configured canonical origin and an explicit public path allowlist. Alternate domains, queries, fragments, user information, arbitrary ports, preview links, newsletter capabilities, private Writer paths and download capabilities are rejected. Deletion notifications can only refer to a URL that was genuinely public in the stored inventory. A caller cannot manufacture a deletion notification for a never-public slug.

The worker takes an advisory lock, reconciles the public inventory, checks each selected revision against the current stored public state and submits one URL per request to the fixed endpoint `https://api.indexnow.org/indexnow`. It follows no redirects and sends no application bearer credentials. The JSON envelope contains the canonical host, key, same-host key-file location and public URL. The protocol supports larger batches; the conservative single-URL implementation gives each local revision an unambiguous receipt history.

### Honest states and retries

| Local state | What is known |
|---|---|
| `pending` | A public change is saved locally; no request has begun. |
| `attempting` | An attempt was recorded immediately before calling the transport. It is not yet an HTTP receipt. |
| `accepted` | IndexNow returned HTTP 200 for that notification. |
| `verification_pending` | IndexNow returned HTTP 202; ownership-key validation is pending. |
| `retry` | A timeout, incomplete response, HTTP 408/429 or server error is waiting for backoff. |
| `rejected` | A permanent/unsupported response or retry limit needs review. |
| `cancelled` | A newer revision superseded the work or the URL/revision is no longer eligible. |

**Neither HTTP 200 nor HTTP 202 proves indexing, ranking, citation or search visibility.** Each attempt preserves its number, start/completion time, observed HTTP status and a fixed safe error code. Raw response bodies are discarded. A crash leaves an unfinished attempt; after 15 minutes the next worker marks it interrupted and allows another notification. Repeating an IndexNow notification is not a repeat publication to a social account.

Transient failures use exponential delays beginning at 60 seconds, bounded to one day. `Retry-After` seconds or HTTP dates can increase the delay, within the same bound. After eight consecutive failures, the item stops for review. Invalid-request, invalid-key and host/schema failures stop without automatic retries. An authenticated Writer can queue a recheck of the latest rejected or verification-pending revision after reviewing the cause; earlier attempt receipts remain intact. The UI shows exact queue pagination and the latest 20 attempt receipts.

Official protocol references:

- [IndexNow documentation](https://www.indexnow.org/documentation) — added/updated/deleted URL envelope, ownership proof, same-host constraints and HTTP status meanings.
- [IndexNow FAQ](https://www.indexnow.org/faq) — official global endpoint and sharing between participating search engines.

## Operations exceptions and article campaigns

Discovery shows saved counts for failed, unknown or reconnect-required publishing jobs; unconfirmed/reconnect connections; failed or uncertain email outcomes; waiting and rejected/verification-pending indexing work; and a missing/stale Search Console snapshot. Optional Reader Relationships counts are included when that module is loaded. The summary contains counts and fixed internal review links, not addresses, credentials or payloads.

The page links to Operations for worker/configuration evidence and to Article Campaigns for the article-specific objective and distribution record. These states remain distinct: social publication, provider-acknowledged email, indexing receipt and measured search performance are not interchangeable outcomes.

## Responsive images

`discovery_image_attributes(?string $source, array $variants = [], string $sizes = '(max-width: 720px) 100vw, 50vw'): array` returns escaped-at-render attributes for verified existing local images. It supplies actual width and height and generates `srcset`/`sizes` only for actual existing, matching-aspect derivatives. Recognized siblings end in `-320w`, `-480w`, `-640w`, `-800w`, `-960w`, `-1200w` or `-1600w`; explicit local variant paths can be supplied too.

The helper writes no files, fetches no arbitrary URL and makes no S3 metadata request. Existing HTTPS remote originals retain their original `src` without invented derivatives. Missing/unsafe local files fall back to the social-default image. The parent integration's image-build utility creates optional derivatives separately; the helper never claims a candidate exists until it verifies the real file and dimensions.

## Integration contracts

- Load `app/discovery.php` and `app/discovery_routes.php` after their existing functions, journeys, publishing and optional editorial/reader dependencies.
- Dispatch `discovery_dispatch($path)` before the recovered `/search` route. It owns `/search`, `/write/discovery` and its POST actions, and the exact active ownership-key path.
- Add `discovery_work($limit)` as a stage of the existing CLI worker. There is no unauthenticated HTTP worker endpoint.
- Optional publication hooks call `discovery_sync_public_urls()` after commit and catch/log failures so a discovery failure cannot falsely report a failed publication. The next worker reconciliation repairs missed inventory changes.
- `discovery_search($query, $page = 1, $type = 'all', $perPage = 24)` returns the query, filter, per-type counts, card results and common pagination structure; an invalid/out-of-range page returns `null`.
- `discovery_notify_public_url($url, $event)` validates the path and reconciles the real inventory. It does not trust the supplied event to create a public state and returns the latest known queue ID or `null`.
- `discovery_operations_summary()` returns arrays containing `label`, integer `count` and internal `url`.
- The editorial hook `editorial_search_documents()` must be uncapped and return public-only `id`, `type`, `title`, `summary`, plain `body`, `url`, `published_at`, `updated_at`, explicit `builtin` and optional `public_hash`. Dynamic publication gates are rechecked. Known blank templates are the only undated exception.

## Repeatable validation

The aggregate `composer test` orchestration runs each suite in a separate process and provides newly named disposable databases. CI is prepared for PHP 8.3/8.4 with MySQL 8.4; the local focused checks used PHP 8.3.6 and MariaDB 10.11.14. Passing local MariaDB tests is not evidence that CI or AWS production has run.

Focused pure checks:

```bash
php tests/discovery_test.php
```

Focused database setup and test, using only a new isolated local schema (change the suffix for every new run):

```bash
WK_TEST_ALLOW_WRITES=1 WK_TEST_DB_HOST=127.0.0.1 WK_TEST_DB_PORT=3307 \
WK_TEST_DB_USER=root WK_TEST_DB_PASS='' \
php tests/prepare.php --database=waithaka_test_discovery_unique_test

APP_ENV=testing WK_TEST_ALLOW_WRITES=1 \
WK_TEST_DB_HOST=127.0.0.1 WK_TEST_DB_PORT=3307 \
WK_TEST_DB_USER=root WK_TEST_DB_PASS='' \
WK_TEST_DB_NAME=waithaka_test_discovery_unique_test \
php tests/discovery_database_test.php
```

`tests/discovery_http_test.php` uses the aggregate's temporary loopback PHP server with `TEST_BASE_URL`, explicit `DB_*` fixture variables, `APP_ENV=testing`, `APP_READY=1`, disabled email transport, a temporary empty configuration file and `WK_TEST_ALLOW_WRITES=1`. Its real HTTP assertions cover public/private visibility, deep pagination, type filters, escaped input, real 404/405 responses, login, CSRF, cache/robots headers, ownership-proof retrieval and local-only queue creation. It creates and cleans its own synthetic content and Writer fixture. No live search-console report or external notification is performed.
