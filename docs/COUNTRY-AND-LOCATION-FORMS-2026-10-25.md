# Country and location forms — 25 October 2026

Kenya and +254 are initial defaults for Founder Stories, contact enquiries, meeting requests and private media-consent forms. Existing foreign-country choices survive form validation. Country and telephone country code remain independently editable because residence and telephone numbering country can differ. Full international phone numbers override the default prefix.

Kenya: County (47 official options), Sub-County (text), Town or Location. Other countries: State / Province / Region, District / Area, City / Town / Locality. Details below country remain optional. No constituency list is misrepresented as a sub-county list. The county source is https://kdsp.devolution.go.ke/counties, checked 25 October 2026.

One reusable component handles labels, autocomplete, Kenya defaults, responsive spacing and country switching. With JavaScript, inactive administrative fields are hidden and disabled; switching back preserves unsent edits. Without JavaScript both labelled alternatives are available; the server uses only the selected country's fields.

Founder and contact detail records are stored atomically with their parent record in the existing encrypted settings store; no production schema migration is needed. Booking details remain inside the existing encrypted booking record. Consent details are retained in the private signed consent snapshot. Private review screens show the structured location. None of these details is added to public founder cards, article schema, analytics or AI context. Phone entry does not verify a WhatsApp contact or grant permission to send messages.

Anonymous giving, payment-provider-specific fields, newsletter subscriptions and simple sign-in are not expanded into location questionnaires. Existing consent snapshots and historical records remain readable without rewriting them.

Verification covers malformed/oversized input, country-specific field exclusion, full international numbers, Kenyan local-number normalization, switching without lost edits and encrypted database round trips. Live acceptance must check production forms after deployment. No genuine enquiry, founder story, signature or meeting request is submitted for testing.
