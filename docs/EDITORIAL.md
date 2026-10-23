# Learning resources, evidence and watch pages

This release adds useful reading paths and author-controlled learning material. It does not seed a fictional venture, claim results, expose private research, authorize a social account or publish to an external platform.

## Public experience

`/start-here` offers three paths: follow Startup Journeys in sequence, work through an idea with a blank tool, or read Founder Stories and their evidence. Only actual published journeys appear. The weekly series remains an editorial commitment, not a fabricated archive of weekly progress.

`/resources` begins with four original blank tools: a problem interview worksheet, an experiment and decision sheet, a first customer checklist and a pilot cost worksheet. Each contains practical prompts and a genuine plain-text download. These static tools have no invented publication date or venture results. They can also be copied into a private Writer form and adapted under a new URL.

Published articles gain a contents list when they have at least two meaningful H2/H3 headings. Anchors are generated after sanitization, remain stable for unchanged headings and are unique even when headings repeat. Articles also include a newsletter link and can show separately reviewed evidence cards and meaningful update/correction notes. The existing private source and planning notes are never imported.

Published journey pages can include an experiment and decision log. An entry appears only when both the entry and its parent journey are published and due. Planned and in-progress work is labelled as such. A concluded, inconclusive or stopped experiment needs actual findings and a decision.

`/watch` lists reviewed watch pages. Each dedicated page puts the video first, followed by its actual upload date, duration and an escaped, readable transcript. No placeholder video is seeded.

## Writer workflow

Open **Resources & evidence** in Writer (`/write/editorial`). Choose **New resource**, **New experiment / decision**, or **New watch page**. Alternatively, use one of the blank tools to prepare an editable resource copy.

1. Enter the title, summary and type-specific content. An experiment must link to an existing Startup Journey. Resource contents support the existing sanitized HTML subset.
2. **Save draft** to save the private working copy. This does not change a public or scheduled snapshot. **Private saved preview** requires Writer authentication, has no-store/noindex headers, and emits neither public structured data nor the analytics loader.
3. Review the exact contents, claims, permissions and publication time. Tick the explicit publication acknowledgment and choose **Publish reviewed version**. A future Nairobi time schedules that reviewed version. Later draft edits do not replace it automatically.
4. Once an item has been published, its slug remains fixed. Publishing a reviewed change updates the current public page while keeping the original publication date. The public modification date changes only when the published snapshot changes, not on a draft save.
5. **Archive from public site** removes the public item and its resource download while retaining the working copy. Copies that a reader already downloaded cannot be recalled. A new explicit publication is needed to make an archived item public again.

The form carries a version number. A stale form cannot save, publish or archive over a newer version; reload before reviewing again. Publication acknowledgment is deliberately not remembered after a save.

### Article evidence and corrections

In the existing article editor, use **Manage public evidence & updates** in the Private plan panel. The destination is `/write/editorial/posts/{id}`.

Cards have an explicit basis (firsthand observation, documented records, interview, self-report or third-party source), a public summary, optional observation date/source URL, and limitations. There are at most 12 cards. Private preview URLs, newsletter tokens, signed object links, credentials and local URLs are rejected. Do not put confidential information into fields intended for publication.

Save privately while preparing an article. The article must already be public before its evidence can be released. Publish a changed article body in the normal editor, then describe a meaningful update or correction in this editor. Ordinary saves and typo fixes need no new public date. Replacing already-public evidence requires an explanatory note. Notes are append-only; a later note can clarify an earlier one. The published pending note is cleared from the working copy so a routine later publication does not duplicate it.

`editorial_article_modified($post)` supplies only the latest explicitly published meaningful note, or `null`. The shared article schema/head use the original publication time when no meaningful update exists. Archiving an article hides its cards, notes and modification date from public responses.

### Watch page requirements

Upload the video and thumbnail to **Media Library** with explicit **public** visibility. Watch pages accept owned MP4 or WebM files up to **512 MiB**, plus an owned public JPEG, PNG or WebP thumbnail. Private files are never exposed automatically. A watch page needs a summary, an accurate transcript, the actual duration in seconds, the actual upload date/time and explicit permission/accuracy confirmation.

Publication freezes server-owned asset metadata and verifies file ownership, configured public URL, MIME/container, byte length and SHA-256 integrity through the media library. It does not fully analyze codecs or establish transcript accuracy; review actual playback and content before publication. No remote URL import, automatic transcript generation, provider authorization or video upload is performed by this module.

A complete, released page emits `VideoObject` with the real content URL, stable thumbnail, upload date, duration and transcript. The watch URL is not mislabelled as a separate embeddable player. Public requests check the approved asset’s current database binding and local availability without fetching arbitrary URLs. If the binding changes or a local file is missing, the watch page and schema are suppressed. For S3, byte/current-version validation occurs at publication; continued object availability needs the existing operational monitoring. Archiving an asset removes it from new approvals while preserving an existing immutable published reference, as in the media library’s established contract.

## Integration and validation

Migration `110_editorial.sql` creates `editorial_items`, `editorial_post_meta` and `editorial_post_updates`. Apply it through the existing migration command; no request-time schema mutation is used. The bootstrap includes `app/editorial.php` and `app/editorial_routes.php`; `editorial_dispatch($path)` handles its public and authenticated routes.

Public helpers are `editorial_public_item`, `editorial_public_items`, `editorial_public_post`, `editorial_public_experiments`, `editorial_sitemap_entries` and the uncapped `editorial_search_documents`. Search documents contain only public resource/watch projections; built-in tools are explicitly marked `builtin=true` and remain undated. Published/scheduled snapshots, parent gates and valid video media determine visibility. Private `draft_json`, source notes and working-copy timestamps do not enter search.

After an explicit publish/archive commit, the module asks the optional discovery module to reconcile its public inventory. This is database-only. The discovery worker also catches scheduled releases and removals. A reconciliation failure does not roll back an already committed publication or falsely report that publication failed.

Actual resource download links carry `data-analytics-event="resource_download"` and a resource content ID. Actual chapter navigation links carry `episode_continue` and the destination post ID. The shared consent-aware analytics implementation decides whether to send an event; these attributes do not send analytics themselves.

Focused suites are `tests/editorial_unit.php`, `tests/editorial_database.php` and `tests/editorial_http.php`. Database/HTTP suites require `APP_ENV=testing`, `WK_TEST_ALLOW_WRITES=1`, an explicitly named disposable `waithaka_test_*_test` database on numeric loopback and synthetic Writer fixtures. The HTTP suite uses `TEST_BASE_URL` (or `WK_TEST_BASE_URL`) with the same local database. Fixtures clean up their own rows and files. They do not call a provider, send email or publish to a production site.
