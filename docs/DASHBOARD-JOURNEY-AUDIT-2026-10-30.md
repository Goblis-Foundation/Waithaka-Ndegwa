# Dashboard and journey audit — 30 October 2026

The home screen now prioritizes Today, Needs attention, Create & publish and the article library. The searchable six-group directory retains the existing tools. Home no longer repeats the group tabs and metric cards. Three remaining saved activities appear first, with the full program one step away. All calendar data is a saved snapshot; opening the dashboard does not refresh, authorize or send anything.

## Corrections

- Authentication resumes narrowly allowlisted editor, preview, publishing, founder and newer workspace GET routes. Mutations, OAuth callbacks, unknown URLs and private query parameters cannot be replayed.
- Library public links and published counts use the same public SQL policy as the website, including scheduled publication dates and withheld founder stories. Saved working drafts remain clearly identified and previewable privately.
- The library initially reveals six rows, supports further rows, page filtering, bound whole-library search and pagination. Without JavaScript, every loaded article and native disclosure remains available.
- Unavailable calendar, inbox or device records remain unknown rather than a zero or an empty day. Owner inbox data is never read for collaborators. Launch-readiness access remains owner-only.
- The private shell links back to the website. The existing public-footer rule excluding Writer sign-in links is preserved. No public response depends on the signed-in identity.

## Public → private journeys

| Visitor entry | Protected management | Acceptance boundary |
| --- | --- | --- |
| My Thoughts | Article library/editor | Private preview, actual due/public visibility |
| Book time | Meetings | Request/review/confirmation, exact mailbox consent |
| Contact | Communication inbox | Private enquiry and follow-up records |
| Newsletter | Newsletter editions | Subscription consent, sender/suppression/delivery evidence |
| Founders | Founder reviews | Exact-version approval before publication |
| Opportunities | Employment reviews | Private applications and explicit response |
| Advertise | Publishing desk | Brief, approval, billing and fulfilment |
| Goal 2030 | Impact reporting | Source-approved evidence, unknown is never zero |
| Contribute | Finance connections | Live merchant, signed callback and settlement evidence |

## Pending external acceptance remains open

Issues #71–76 remain open. Real LinkedIn/X/TikTok product access and credentials; actual Threads, Telegram, Discord and Signal destinations; the exact Facebook Connect subscription/group URL; merchant settlement and recurring mandates; approved postal sender and inbox/suppression tests; source-approved impact feeds; actual Google/Microsoft grants and read-backs; and physical-device/assistive-technology/field-performance acceptance cannot be manufactured by a code release. The 33-area implementation register and all seven activation gates remain available. No real posts/messages, bulk releases, charges or provider consent were performed.

## Validation

Added production-template browser fixtures for populated, empty, unknown-data and collaborator home screens, including responsive and automated accessibility coverage. Added local-state/privacy unit checks, safe-return regression checks and authenticated HTTP assertions for whole-library search, due scheduled visibility, future-publication preview and public/private boundaries. Full CI and production release evidence is recorded on the accompanying pull request.
