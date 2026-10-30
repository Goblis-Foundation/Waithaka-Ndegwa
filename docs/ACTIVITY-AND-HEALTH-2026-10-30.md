# Private activity connections, targets and reports — 30 October 2026

`/write/activity` is scoped to the current Writer user. A super-admin or alias is not automatically treated as the same athlete. The public website, AI context, editorial cycle, newsletter and social publishing do not receive health records.

## Implemented routes

| Source | Supported connection | Activation required |
| --- | --- | --- |
| Google Health | Dedicated web OAuth client, linked Health identity verification, exact-account confirmation, renewable read grant and paginated workout reads | Enable Google Health API, register the exact callback, link the Google account in the Google Health app, authorize and confirm |
| Strava | OAuth read grant; optional explicit `activity:read_all`; exact athlete verification, confirmation and renewable access | Register the personal app/callback and consent to its actual scopes |
| Samsung Health | Selected source app through Android Health Connect companion, followed by reviewed CSV import; mapped owner export also supported | Samsung-to-Health-Connect sharing, device permissions and manual companion installation/export |
| Google Fit | Health Connect companion for shared workouts; mapped legacy owner export | Device sharing/permissions; no new retiring Fit REST connection is created |
| Health Connect | Native read-only Android companion; local user-selected CSV destination and explicit website preview/confirmation | Physical Android acceptance and installation; this is a manual transfer, not background web access |

Google's current migration guide recommends Google Health for web/cloud integrations and Health Connect for device-first access. Fit APIs are supported only through the end of 2026 and have not accepted new developer registrations since May 2024. The separate Google Health scope used is `googlehealth.activity_and_fitness.readonly`, with `GET /v4/users/me/identity` and paginated `exercise` data points. The implementation drops GPS, heart rate, sleep, clinical and unrelated response fields.

Official references checked for this release:

- https://developer.android.com/health-and-fitness/health-connect/migration/fit
- https://developers.google.com/health/setup
- https://developers.google.com/health/reference/rest/v4/users/getIdentity
- https://developers.google.com/health/reference/rest/v4/users.dataTypes.dataPoints/list
- https://developers.google.com/health/data-types/workouts
- https://developer.samsung.com/health/blog/en/health/blog/accessing-samsung-health-data-through-health-connect
- https://developer.android.com/health-and-fitness/health-connect/get-started
- https://developers.strava.com/docs/authentication/
- https://www.strava.com/legal/api_policy

## Targets and graphs

Weeks run Monday–Sunday, months use calendar boundaries and annual reports use the calendar year in the selected timezone (Nairobi by default). An anchor date selects the period, including historical periods and targets up to one year ahead. Entirely future periods are upcoming and do not trigger cloud reads or certify progress. Custom reports span at most one year and do not create calendar targets.

Activity count, active minutes, distance in kilometres and session steps have independent optional targets. Target keys include the exact source, period, starting date and timezone. Version checks prevent stale tabs overwriting targets. Apply period selects a target calendar without calling or requiring a provider. Targets are user-entered goals with no clinical recommendations or automatic carry-forward. Cumulative performance graphs include the period target and a table of exact values. Weekly/monthly graphs use days; annual graphs use twelve months. Print/PDF and private CSV exports are user initiated.

Each report uses one source. Stable source IDs deduplicate exact repeats. Overlapping recordings remain visible but are excluded from summary/graph totals. Missing measurements remain unknown, not zero. Pace uses active duration only. Target percentages require complete pagination or explicit owner-declared import coverage, no overlaps, and complete measurements for that metric. Partial/missing records cannot certify attainment. Future graph buckets remain upcoming.

## Data lifecycle and safety

Cloud workout results exist only in the current response and are not stored server-side. This avoids a persistent Strava activity cache and its retention/deletion problems. Every requested cloud report re-verifies the athlete and reads the current provider records. Provider pagination has a strict request/row cap, and capped reads are labelled incomplete. Rate-limit failures do not automatically retry. A connection-generation check prevents an in-flight result surviving a concurrent disconnect. No provider activity writes are supported.

OAuth state is one-use, expiring and bound to the Writer user, source, client configuration and grant generation. App credentials, tokens, local imports and targets use the existing encrypted settings store. Applications are configured only by the workspace owner; credentials are never rendered. Google Health credentials are separate from Google Workspace credentials. Disconnect removes local credentials and attempts provider revocation; failure to confirm remote revocation is stated explicitly.

CSV imports accept only the documented summary columns, up to 2 MB / 2,000 activities. Unmapped vendor archives are not falsely represented as supported. Raw files stay in PHP's temporary upload area; only validated fields enter an encrypted fifteen-minute preview. Confirmation is one-use. Merge updates matching source IDs and retains earlier months; replacement discards the previous snapshot. Merge does not infer deletions absent from the new file, so users must replace/delete copies when appropriate. Deletion clears the selected saved copy and pending preview. Downloads neutralize spreadsheet formulas.

## Android companion

`android/health-companion` builds a test APK through `health-companion.yml`. It requests workout, distance and step reads only; individual metric denial leaves blanks. It has no network, background, GPS, clinical, write or history permission. It reads a selected recent 7/30-day period, filters Samsung Health/Google Fit package origins when chosen, aggregates cumulative metrics by source origin, previews the selection and saves only to the user's chosen document destination. Permission is checked before reads and saving. Active duration is left absent because it cannot be assumed from session elapsed time.

These builds are for manual installation and device acceptance, not an asserted Play Store release or a verified phone connection. Health Connect's granted-history limit applies; annual device reports need the user's earlier reviewed imports or a complete mapped export. Local destination files are controlled by the user and are not deleted by a server purge.

## Acceptance

Unit coverage exercises normalization, timestamps, metrics, source separation, overlaps, weekly/monthly/annual boundaries, target versions, graphs, CSV privacy, OAuth scopes/state/identity, refresh, disconnect and no persistent Strava activity cache. Authenticated HTTP checks cover encrypted preview/confirmation, CSRF, target periods, reports/exports, deletion and public-page isolation. Production-template browser fixtures cover weekly, annual and empty/import states, responsive layout and automated accessibility. The compiled APK permission list is verified against exactly the three intended read permissions. Android compilation/lint is independent of physical-device permission acceptance. Real credentials, consent, phone installation and actual provider reads are separate activation steps.
