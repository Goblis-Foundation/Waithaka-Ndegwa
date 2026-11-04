# Inaugural article: implemented reading experience

This is a presentation update to `/my-thoughts/impact-driven-inspiration`, not an image mockup, replacement article, new publication or homepage feature.

## Content and editorial control

The currently saved Writer body and `editorial_toc()` output remain authoritative. The helper partitions sanitized HTML at existing H2 anchors without changing or dropping any markup. All eight original chapters, the quotation, links, publication date, reading time, author, support, sharing and newsletter controls remain. Other article slugs and content without a valid chapter structure retain the legacy template. No migration, database write or automatic republishing is introduced.

## Original photographs selected from the user's supplied set

The optimized copies already committed to `assets/images/journey` are reused. No generated image is included.

| Placement | Repository original | Matching supplied filename |
| --- | --- | --- |
| Opening portrait | conversation.webp | 506050203_10231096406447347_2322524546820694887_n(1).jpg |
| Education chapter | early-learning.webp | 505574164_10231085451973492_4900828669874742074_n(1).jpg |
| Social entrepreneurship chapter | farm-cassava.webp | 508152811_10231159679309129_651556503856915405_n(1).jpg |
| Writing and travel chapter | travel-open-bus.webp | 505638723_10231076994042049_6833675833147275925_n(1).jpg |
| Writing and travel chapter | skydiving.webp | 508347579_10231161531515433_7475535375751455911_n(1).jpg |

All five photographs retain their natural proportions and open at full size in a new tab. Captions describe the depicted scene without asserting an unverified date, venue, achievement or location.

## Reading design

Split introductory layout; eight numbered chapters; readable text column; sticky desktop chapter index; expandable mobile index; keyboard focus; active-chapter indication; native anchor fallback; no-JavaScript content and chapter links. Styling and JavaScript are scoped to the first article. The existing global outline recognizes `data-page-outline`, avoiding duplicate navigation. The homepage remains article-free.

## Acceptance

`tests/inaugural_story_test.php` covers lossless content partition, retained anchors, edited-heading handling, fallback, asset availability and the no-homepage-articles policy. The render fixture uses the real site layout and article partial. Chromium checks 360, 390, 768, 1024 and 1440px, loaded images, intrinsic aspect ratios, overflow, chapter navigation and no-script reading. A successful Deploy production workflow invokes read-only live acceptance; that additionally checks the canonical, article schema, sharing/support/newsletter and homepage/archive preservation. Network mutations and third-party requests are blocked in the test browser.

Passing fixture tests is not production proof. Verify the production workflow, live acceptance JSON and actual screenshots before marking the redesign live.
