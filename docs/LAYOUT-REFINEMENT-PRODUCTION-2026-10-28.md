# Verified production release — unified margins and public navigation (28 October 2026)

## Exact release identity

- Repository: `Goblis-Foundation/Waithaka-Ndegwa` (repository ID 746133481).
- Pull request: [#67](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/pull/67), successfully merged into production branch `main`.
- PR candidate: `7e8bfa0d46c2c18e3c4afbc588b05e41ca6771fc`.
- Production merge commit: `eaab5282a70d63490f2e240004d7f4521da96dcb`.
- AWS workflow: [40653908009](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40653908009), conclusion **success**, completed **28 October 2026 08:10:31 UTC / 11:10:31 Africa/Nairobi**.
- Validation job 122700226222: **success**.
- AWS deployment job 122701109039: **success**.
- Target: AWS account `466401734428`, region `af-south-1`, Elastic Beanstalk `waithaka-ndegwa-production`.

The deployment log records success for the AWS target validation, Route 53 state recovery, immutable application build, Elastic Beanstalk deployment, existing Google Workspace relay reapplication, public HTTPS/database smoke test, and final publishing/brand/visitor checks.

## Candidate checks

- PHP 8.3 with MySQL 8.4 and PHP 8.4 with MySQL 8.4: [application run 40653449146](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40653449146), both jobs successful.
- [Browser run 40653449268](https://github.com/Goblis-Foundation/Waithaka-Ndegwa/actions/runs/40653449268): success, preserving 25 original interaction checks and 19 shared-journey review checks plus **116 public layout checks across 13 viewport widths** (320–1440px). Headless Chromium checked navigation bounds, no viewport overflow, matching horizontal page margins, responsive main menu, Escape-to-close, active section semantics, and Book time access on narrow screens.
- Browser tests ran against isolated PHP-rendered fixtures and captured screenshots. They do **not** constitute manual visual acceptance of the live site on all physical devices.

## Implemented change

1. A crowded 11-link secondary navigation was replaced with **My Thoughts, Startup Journeys, Resources, Opportunities** and **All sections**. Existing destinations and archives remain accessible at unchanged URLs through Explore, primary links and footer.
2. Consistent public horizontal gutters apply to primary section containers, reading areas, breadcrumbs, and `.ux-page` layouts, including the header at 320px.
3. Fixed legacy mobile header CSS that forced a support/booking pill to remain visible at 320px and caused 37px overflow; Book time stays accessible in the mobile menu.
4. Navigation targets wrap and reflow into a two-column mobile layout, with focus/current-page visibility and no Javascript dependency for the secondary links.
5. Added permanent browser regression gates and the [requirements checkpoint](RECOVERY-CHECKPOINT-2026-10-28.md), without changing content, permissions, database records or connected provider credentials.

## Production observations and limits

The immutable deployment workflow confirms:

- `PASS: HTTPS homepage, expected GA4 destination, editorial content and database health.`
- `PASS: five-step promotion form, selectable packages, review controls, private intake and deployed interaction assets.`
- `PASS: exact deployed shared-journey script hash ...`
- `PASS: Kenya-default contact controls, suitability policy, public journeys, consistent assets, personal purpose, canonical/breadcrumb SEO, public-only sitemap and protected owner routes.`
- `PASS: monthly archive, ... uniform views/counter footer and private workspace boundaries.`
- `PASS: public publishing routes, private intake/client isolation, approved logo hash, finished icon dimensions and database health.`
- Visitor-statistics sources had advancing timestamps, with a read-only confirmation in Africa/Nairobi.

Successful deployment is evidence of the released application and the listed read-only smoke checks; **it is not proof** of external provider posting, customer payments, newsletter delivery to subscribers, real grant authorisation, physical-device visual acceptance, or material index/ranking improvement. No real enquiry, brief, email, charge, publishing action or external provider post was submitted during acceptance.

## Remaining access-dependent and owner-controlled tasks

See the prior [27 October reconciliation](RECONCILIATION-2026-10-27.md) and [28 October recovery checkpoint](RECOVERY-CHECKPOINT-2026-10-28.md). Key remaining gates include authorising distinct personal-profile social/WhatsApp integrations without substituting a corporate portfolio; verifying provider merchant and recurring-payment capabilities; completing newsletter sender compliance with the **real approved postal address**; obtaining source-owned approved Goal 2030 impact feeds; and activating additional Google/Microsoft scopes only after intended-account consent.

The repository's GitHub **default branch setting** remains `recovery-bootstrap`, although active development and AWS production use `main`. An organisation administrator should set the repository default to `main` using GitHub Settings → General → Default branch. This requires repository administrative access and is not changed by a file commit.

This record is documentation-only; the preceding immutable application SHA remains the deployed release. A documentation-only commit must not trigger a second production deployment.
