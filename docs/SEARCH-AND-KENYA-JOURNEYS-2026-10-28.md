# Search and Kenya-first journey reconciliation — 28 October 2026

## Owner requirements

Recheck margins, particularly the visible public Search page, and retain the agreed Kenya-first location journeys. On all **relevant** public/private forms the default residence country is Kenya, the independently editable initial phone calling code is +254, Kenya offers the verified 47 counties, and applicable local address details are County → Sub-County → Ward → Town/Area. Other countries use the selected country's region/state/province, district/area and city/locality fields. Do not silently change the phone calling code when the country selection changes. A full international number remains valid.

The exact historical requirement includes Ward; it was missing from the prior common partial. Sub-County and Ward remain **bounded optional text** because an authoritative universal mapping was not adopted. A constituency must not be mislabeled a Sub-County and no fabricated Ward selection lists may appear. This refines the 25 October form policy without changing earlier enrolment or approval rules.

## Implemented scope

- Public `/search` now has labelled query/type controls and intentional, responsive spacing inside the same horizontal page gutters as the header, results and footer. The actual PHP view uses `search-page-form`; the previous CSS targeted an obsolete `search-form` and left the active search inputs insufficiently laid out. The mobile main menu now includes Search.
- The shared partial `views/partials_location_fields.php` includes optional Ward, retains Kenya and +254 as independent defaults, uses all 47 counties, preserves unsent edits when switching countries, and avoids disabled/inapplicable fields in scripted submissions. Without JavaScript, both labelled groups remain available and server-side country selection determines which group is retained.
- Backend parsing and record privacy are preserved in `app/location.php`, contact enquiries, founder submissions, paid-feature client profiles and public opportunity workflows. Consent, booking and private client contact already use the shared component. No existing completed records are rewritten; older location records without Ward remain readable. No new database migration is necessary because the optional hierarchy is stored in existing structured private location snapshots and current opportunity records.
- Search changes are visual only: published-only indexing, canonical/robots settings, filters and pagination remain as before. Location field additions do not expand public display of private contact details or automatic AI/analytics sharing.
- No extra country/telephone fields are imposed on anonymous giving, payment-provider checkout, newsletter opt-in or simple authentication; they are not full location-intake journeys.

## Acceptance

The same existing PHP and MySQL application matrices, isolated Chromium experience tests and GitHub OIDC AWS deployment apply. Added checks cover 320px–1440px search layout, margin alignment, visible and non-overlapping form controls, mobile search access, Kenya/+254 on all shared form contexts, 47 counties, optional Ward, international residence switching, retained entered values and no-script fallback. An additional **read-only** post-deployment check requires the current search HTML/CSS and Kenya hierarchy on public contact, founder, opportunity and promotion pages. It submits no real record, signs no release, sends no email and initiates no payment or provider request.

## Unfinished capabilities carried forward

The 20-area owner reconciliation register remains authoritative for missing provider credentials, personal account grants, WhatsApp activation evidence, recurring payment settlement confirmation, approved newsletter postal address, verified impact feeds, additional Google/Microsoft scopes and real physical-device visual acceptance. These are not declared enabled by a layout or location field deployment.

The owner has completed the repository default-branch correction: `Goblis-Foundation/Waithaka-Ndegwa` now reports `main`. Do not overwrite older dated audit evidence; this new fact supersedes their repository-settings note.
