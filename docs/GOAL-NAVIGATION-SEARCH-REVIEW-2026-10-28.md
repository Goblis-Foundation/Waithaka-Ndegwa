# Continued Waithaka recovery: Goal 2030 navigation and private Search Console review

**Decision date:** 28 October 2026. This document describes candidate code, not evidence of a completed live release. See the subsequent production receipt for immutable deployment verification.

## Owner direction

Goal 2030 is not an independent page. Remove its entry from the **shared primary menu** while retaining the complete Goals content, targets, public reporting methods and in-page links on Home and About, plus the contextual footer link. Do not create a dummy Goal 2030 route. The existing `id="goals-2030"` stays unchanged.

## Additional functional recovery

A new on-demand **Search Console · content review** panel in `/write/google#search-review` uses the *already consented* `google_search_console` read grant and its exact confirmed property. No new OAuth scope, API key, billing change, background task, publication or URL submission is required.

- After explicit owner POST and CSRF validation, compare 28 or 90 days of finalized Search Console *page* rows with the immediately preceding equal-length period. Exclude the last three days to reduce incomplete reporting.
- Review the top 1,000 rows Google returns in each period. These are not all search results or sitewide totals. Source pages must pass the existing canonical public-content URL allowlist. No external host, query string, private route or raw provider token is displayed.
- Explain four deterministic signals: comparable click decline (>=30% with >=5 fewer clicks and >=10 earlier clicks), low CTR (>=100 impressions, <2% CTR, position 1–20), pages appearing only in the current returned sample (explicitly **not evidence of zero earlier traffic or a new page**), and comparable click increases (>=30%, >=5 increase from at least 5 earlier clicks).
- Never equate a missing top-row observation to zero. Clearly distinguish cause-free editorial signals from evidence-backed fixes; a high/low position or CTR alone is not a reason for automatic changes.
- Keep the panel private and non-persistent; no public analytics feed or saved third-party document. Routine reports, GSC query text, newsletter, ads and content remain unchanged.

## Acceptance

Test the isolated PHP 8.3 and 8.4 / MySQL 8.4 suites, fixed two-call GSC fixture, private/foreign URL filtering, missing-row behavior and 28-day boundary. Existing Chromium browser checks cover the shared primary menu. New read-only AWS deployment acceptance checks the rendered actual homepage and About, ensuring the main menu omits Goal 2030 while the section/footer links remain.

**External activation gates still separate:** personal Meta/Facebook/WhatsApp, LinkedIn, X and TikTok permissions; real merchant settlement/recurring debit approvals; newsletter postal compliance and future recipient delivery; source-approved 2030 impact data; additional Google/Microsoft scopes. Never interpret a successful UI deployment as provider authorization.
