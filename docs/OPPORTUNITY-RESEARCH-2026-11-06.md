# Waithaka opportunity research — 6 November 2026

This batch is the first executed result of the enabled daily Waithaka opportunity research schedule after the prior scheduled run returned no result artifact.

## Published candidates

Two opportunities pass the current publication standard and are represented in `app/content/research-opportunities-20261106.json`:

1. **Ashoka Fellowship** — rolling nomination/self-nomination route; Ashoka East Africa serves Kenya, Uganda, Rwanda and Tanzania. Financial support is needs-based and is not presented as a guaranteed grant.
2. **ILO safe labour migration writing competitions** — Kenya is eligible; the official ILO announcement gives a **25 November 2026** deadline without a cutoff time. The three tracks cover university essays, research papers, and journalism/storytelling. Travel and accommodation are stated only for first-place winners invited to the December Zanzibar meeting.

Both use official organiser pages and the existing Publishing Desk opportunity workflow. They are not employment-opportunity submissions and they do not submit any application on behalf of a reader.

## Held from active publication

- **Global Innovation Fund** — the current FAQ and Apply for Funding page state that applications are not currently open. It remains a watch item rather than being mislabelled as an active opportunity.
- **Pan-African Social Enterprise Summit 2026** — current official pages expose conflicting November event dates. It remains `verification_needed` until the organiser's current date is reconciled. No registration or travel commitment is made.

## Controls

The release is idempotent, does not send mail or social posts, and does not apply or register for any programme. Date-only deadlines are not converted into invented cutoff times. Held research cannot enter the active public directory through this release.
