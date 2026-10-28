# Centering the shared “On this page” selector — 28 October 2026

## Diagnosis
The supplied screenshot shows a full-bleed blue-bordered **“On this page / Choose a section…”** dropdown immediately beneath the Discover strip. It is not the public Search form or the Search icon in the header. The defect also appears on **Start Here**.

The root cause is `assets/js/unified-experience.js`: the generic progressive enhancement used `top.prepend(nav)`, which inserted the dropdown as a child of the first hero/content section. The hero background is full bleed, so the dropdown escaped the shared `.container` gutters. This shared code is used by any public page containing at least three eligible `h2` headings (unless it has an explicit navigation control), not only on Home.

## Fix
The generated selector is a top-level **sibling immediately before the page content root**, after any pre-existing Discover strip and breadcrumb. It is assigned `class="ux-page-outline container"` and `data-page-outline`, so it inherits the same responsive left and right margins as the header, cannot be accidentally duplicated, and cannot be trapped inside a hero or card grid. The public element retains the exact headings, option values, keyboard/focus behavior and JavaScript-disabled fallback.

`assets/css/layout-refinement.css` now explicitly handles the selector, its label and the dropdown at desktop, tablet and mobile widths. The page-specific Search area, original header, website portrait/logo, content, privacy flows and other routes are unchanged.

Private Writer navigation preserves its original layout; this release does not redefine the workspace content grid.

## Acceptance and safety
- Isolated PHP-rendered Home fixture now includes three headings to reproduce the original issue, plus the full actual Start Here and 14 other page fixtures.
- New browser test checks **both horizontal edges** of every generated dropdown against the header, ensures it is before rather than inside page content, verifies option focus, no horizontal overflow and 320–1440px responsive behavior. It reports every checked page that creates the dynamic selector.
- The existing browser, PHP 8.3/8.4, MySQL, CodeQL and AWS OIDC gates remain.
- After deployment, a separate **read-only live Chromium job** checks Home, Start Here and 23 other public routes, where a selector is generated. The job blocks non-GET and third-party calls; it does not submit a form, send a message, charge, subscribe, fetch private data or post to social networks.

A passing browser gate proves the checked desktop/mobile viewport layouts, not physical-device or assistive-technology acceptance. Outstanding provider and source-governed integrations stay in the recovery issue register.
