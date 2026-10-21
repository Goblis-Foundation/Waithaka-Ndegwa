# Waithaka Ndegwa — waithakandegwa.com

A cPanel-friendly PHP/MySQL long-form personal publishing platform designed around a focused **Writer** interface rather than a traditional CMS backend.

## Public identity

**Waithaka Ndegwa**  
**On the frontlines of social change in Africa, contributing to the world’s most pressing problems.**

Primary description:

> My experience and views on social entrepreneurship, agribusiness, infrastructure, innovation, and the world I encounter along the way.

The public main navigation is intentionally limited to **Home, My Thoughts, About and Contact**. Search and **Support My Thoughts** remain separate header actions rather than menu items. The five subjects are surfaced as homepage topic cards, archive filters and post metadata rather than primary navigation:

- Social Entrepreneurship
- Agribusiness
- National Infrastructure
- Innovation
- Travel



## Public design and personal photography

The public site uses a curated set of supplied personal photographs across the homepage and About page. Production copies are stored as optimised WebP assets under `/assets/images/journey/` and are lazy-loaded outside the initial hero. The visual system is editorial rather than corporate, uses the supplied blue/green identity as accents, and is responsive across desktop, tablet and mobile.

The public archive lives at `/my-thoughts`; legacy `/writing` links permanently redirect to the new route. The interface includes instant on-page filtering, sorting, topic lenses and full archive search, with dedicated mobile/tablet layouts.

## Requirements

- Apache/LiteSpeed cPanel hosting with `.htaccess`
- PHP 8.1 or newer
- MySQL/MariaDB
- PHP extensions: PDO MySQL, cURL, mbstring, OpenSSL, DOM, Fileinfo
- GD/WebP recommended for automatic image optimisation
- HTTPS certificate

## First cPanel installation

1. Create a new MySQL database and database user in **cPanel → MySQL Databases**. Grant the user **ALL PRIVILEGES** on the database.
2. Upload the contents of this package to the document root for `www.waithakandegwa.com` (normally `public_html`, or the domain’s configured document root).
3. Confirm PHP 8.1+ is selected for the domain.
4. Open `https://www.waithakandegwa.com/install/`.
5. Enter the database details, the website contact recipient email, and the private Writer login.
6. Complete installation and sign in at `https://www.waithakandegwa.com/write`.
7. Open **Writer → Settings** once to configure the integrations you want.
8. After verification, optionally remove the `/install` directory. The application also creates an installation lock.

No recurring cron job is required. Scheduled posts become public automatically when their `published_at` time is reached because visibility is evaluated at request time.

## Writer workflow

The Writer has been reorganised as a focused publishing desk with a searchable/filterable Library, live word count and reading-time estimates, browser recovery for a new unsaved draft, autosave for saved drafts, and grouped **Publish / Media / SEO** post settings. The normal workflow is deliberately short:

1. Open `/write`.
2. Choose **New thought**.
3. Add title and standfirst.
4. Write or paste long-form content in the distraction-reduced editor.
5. Choose one topic.
6. Add a featured photograph and optional YouTube/Vimeo link.
7. Publish immediately or choose a future publishing time.

On publish, the system automatically:

- creates/maintains the slug;
- calculates reading time at render time;
- generates tags;
- generates SEO title and description when OpenAI is configured;
- falls back to local tag/metadata generation if OpenAI is unavailable;
- adds the page to the dynamic XML sitemap and RSS feed;
- adds Article/BlogPosting structured data;
- uses canonical metadata, Open Graph and X cards;
- finds related thoughts using topic/tag overlap.

The OpenAI integration **does not rewrite the author’s post automatically**.

## Google / SEO

The site includes:

- canonical URLs;
- dynamic `sitemap.xml`;
- dynamic `robots.txt`;
- RSS feed;
- `Person`, `ProfilePage`, `WebSite`, `BlogPosting` and `BreadcrumbList` JSON-LD;
- crawlable server-rendered HTML;
- descriptive per-post metadata;
- large-image social previews;
- Search Console verification field;
- GA4 with consent gating;
- semantic headings and article markup;
- mobile-first responsive layout;
- no `meta keywords` keyword stuffing;
- natural regional content and `spatialCoverage` signals for Kenya, Tanzania, Zanzibar, Uganda, Rwanda and Burundi without creating thin duplicate country pages or false hreflang variants.

Configure Search Console by adding the verification token under **Writer → Settings → Google**, then submit:

`https://www.waithakandegwa.com/sitemap.xml`

## OpenAI

Under **Writer → Settings → OpenAI**, add an OpenAI API key and model. The package defaults to `gpt-5.4-mini` for lightweight editorial metadata tasks, but the value is configurable.

The server calls the OpenAI Responses API for structured editorial metadata. API secrets are encrypted at rest in the settings table with AES-256-GCM using the application key generated at installation.

## M-Pesa

The M-Pesa contribution path uses Safaricom Daraja credentials and STK Push. Configure:

- environment (sandbox/live)
- consumer key
- consumer secret
- shortcode
- passkey
- transaction type (PayBill or Buy Goods)

The callback URL is:

`https://www.waithakandegwa.com/payments/mpesa/callback`

Transactions are recorded locally by reference and updated by the callback. The M-Pesa phone is used to initiate STK Push but is not retained as the contributor identity in the local contribution row. The site does not describe these payments as donations.

## Pesapal cards

The card route uses Pesapal API 3.0. The public card flow allows the contributor to request **KES, USD, EUR or GBP**. Configure:

- environment
- consumer key
- consumer secret
- IPN ID

The IPN endpoint is:

`https://www.waithakandegwa.com/payments/pesapal/ipn`

The callback endpoint is:

`https://www.waithakandegwa.com/payments/pesapal/callback`

You can use **Save & register IPN** in Writer settings to register the endpoint with Pesapal. The public website intentionally presents this route as **Card**, with Visa, Mastercard and American Express branding, while M-Pesa has its own direct Daraja tab. Pesapal requires either an email address or phone number when creating a card checkout; names remain optional. If the visitor selects anonymous support, that transaction contact is sent to Pesapal but is not saved as the contributor’s identity in the website record. Pesapal API 3.0 itself determines the payment methods shown on the hosted checkout and does not expose a standard SubmitOrderRequest field for selecting card-only methods. If your Pesapal hosted checkout still shows M-Pesa, request a card-only/tailored checkout configuration from Pesapal for the merchant account.

## PayPal

PayPal is a separate contribution option for international users. Configure under **Writer → Settings → PayPal**:

- environment (sandbox/live)
- client ID
- client secret

The integration uses server-side OAuth 2.0 and PayPal Orders API v2. PayPal contributions are presented in USD so the public flow is clear and predictable. The contribution confirmation screen is provider-neutral and does not require public recognition or a contributor profile.

Return endpoint:

`https://www.waithakandegwa.com/payments/paypal/return`

Cancel endpoint:

`https://www.waithakandegwa.com/payments/paypal/cancel`


## Contact engine

The contact form requires:

- First Name
- Last Name
- Email
- Phone country code (Kenya `+254` default)
- Phone
- Country (Kenya default)
- City
- Message
- “I am not a robot” check

Messages are stored in MySQL before email delivery is attempted, so a PHP mail failure does not discard the enquiry. Configure Google reCAPTCHA keys under Writer settings for a true reCAPTCHA checkbox; without keys, the form uses the required checkbox plus a honeypot and IP rate limiting.

## Privacy and editorial safeguards

Every post automatically states that the views are Waithaka Ndegwa’s personal views and do not necessarily represent any business, institution or organisation with which he may be associated.

Built-in policy pages cover:

- Editorial independence & personal views
- Privacy
- Cookies
- Legal Policy
- Contribution terms

Have final legal wording reviewed for the exact operating/legal entity and tax treatment before launch.

## Security notes

- Passwords use PHP `password_hash()`.
- Sessions are HTTP-only, SameSite=Lax and secure on HTTPS.
- Forms use CSRF protection.
- Writer login is rate-limited by IP/email hash.
- Contact messages are rate-limited.
- Integration secrets are encrypted in the database.
- PHP/script execution is blocked in upload storage.
- `/app` and `/storage` are denied by `.htaccess`.
- Uploaded images are MIME-checked and limited to 8 MB.
- HTML from the Writer editor is sanitised using an allowlist.

## Backup

Back up both:

- the MySQL database; and
- `/assets/uploads/` plus `/app/config.php`.

The application key in `app/config.php` is required to decrypt stored integration secrets. Protect it and include it in encrypted backups.

## Refinement update

The header keeps **Support My Thoughts** as a separate action outside the navigation menu while retaining **Contribution** as the payment/legal concept. The Contact page has been redesigned as a guided two-column contact experience with quick message prompts, enquiry-reference explanation, privacy messaging and a responsive mobile flow. Homepage/About editorial copy has also been tightened around lived experience, Nyeri roots, practical work and travel.

The bundled journey photographs are gently brightened and delivered as optimised WebP assets. New featured-image uploads are capped at 1600px and encoded as WebP where server support allows it.


## Editorial refinement

Individual My Thoughts posts end with a designed reading close: share controls, automatic tags, a Waithaka Ndegwa author card, personal-views notice and related thoughts. There is deliberately no additional support panel on My Thoughts or post pages; the separate header action is the single prominent **Support My Thoughts** entry point. The About narrative uses Waithaka's own life experience without naming external benefactors or institutions.


## Personal-identity simplification

Named company and organisation affiliations have been removed from the public experience, structured Person data and personal-view notices. The site now describes the practical environments behind the writing generically — social venture building, working with entrepreneurs and agriculture — so **Support My Thoughts** remains clearly a personal publishing contribution rather than an organisational giving path.

The **My Thoughts** archive now has a dedicated editorial landing experience with a featured thought, topic chips, instant on-page filtering, sort controls, reading-time cues, responsive cards, archive principles and a clearer empty state. Search has also been redesigned with topic shortcuts and stronger result context.


## V5 editorial interface refinement

- Homepage **How I approach the world** is now a connected four-stage method: Observe → Connect → Test → Learn.
- **Where the thinking gets tested** is now a professional testing loop across venture, entrepreneur and land realities, returning lessons to My Thoughts.
- **My Thoughts** has a stronger reading map, topic lenses, sticky discovery controls, clearer featured/latest hierarchy and a four-stage “How a thought takes shape” process.
- **About** uses a balanced three-image editorial mosaic, shorter thematic sections, a horizontal experience rail and no oversized portrait.
- **Contact** is intent-first: quick enquiry choices, contextual guidance, a structured three-part form and a clearer follow-up explanation.
- Images receive a subtle lighter editorial treatment while retaining the optimized WebP assets.
- Support My Thoughts is not listed inside the navigation menu; it remains a separate header action.


## Photography usage
The public templates deliberately use each supplied personal photograph only once. Static page photography is not recycled between Home, About, Contact, or article-author sections. Post featured images remain controlled from the Writer interface.

## Contribution confirmation and privacy

Contribution records can remain anonymous. The public site has no donor/supporter wall. Confirmed contributions receive a warm, non-promotional thank-you screen; M-Pesa pending contributions poll the local status endpoint so the page can update automatically after callback confirmation. Provider payloads are reduced/redacted before storage where practical, while transaction reference, method, amount, currency and status remain available for reconciliation.

## cPanel username and cron

The known cPanel username is `afrie015`. The exact document root must still be confirmed in cPanel for `waithakandegwa.com`; do not assume every account uses `/home/afrie015/public_html`. **No recurring cron job is required by this build.**

## Global SEO expansion

The site remains rooted in Kenya and East Africa, but its search metadata now speaks to a genuinely international English-language readership. Home, About, My Thoughts, topic pages, structured data and social metadata have been expanded to reflect relevance to readers in **Europe, the United Kingdom, United States, Canada and the wider international/African diaspora**, while preserving the East African grounding in Kenya, Tanzania/Zanzibar, Uganda, Rwanda and Burundi.

This build deliberately does **not** manufacture duplicate country pages or false country-specific `hreflang` variants. There is one English canonical version of each page. International relevance is expressed through the actual writing, natural page descriptions, structured `spatialCoverage`/audience context and the author/topic model. The OpenAI metadata assistant is instructed to use geographic terms only when genuinely relevant to the article.

## Private consent & media releases

Writer now includes **Consent** as a private internal module at `/write/releases`.

Use it when a photograph, video, recording or conversation needs an explicit record of permission:

1. Open **Writer → Consent → New consent link**.
2. Describe the specific context and select only the permissions you actually need: photo, video, audio and/or quotes/conversation.
3. The system generates an unlisted 64-character private URL under `/consent/<token>`.
4. Send that link directly to the person.
5. The person chooses each permitted use affirmatively, chooses whether they may be named in full, by first name or not named, and signs electronically by typing their name.
6. The system stores the exact consent snapshot, consent version, selected permissions, attribution choice, timestamp, hashed connection address and browser information.
7. A signed person can use the same private link to request withdrawal for future use. Writer records the request and emails the configured contact recipient when mail is available.

The private consent page has no public-site navigation, no Google Analytics, `noindex/nofollow/noarchive`, and is omitted from the sitemap. It asks for minimal information: signer name/electronic signature are required to evidence permission; contact and country are optional. A parent/legal guardian route is available when needed.

The standard consent wording limits the normal scope to the personal website and Waithaka Ndegwa's personal social channels, permits ordinary cropping/clarity edits without misrepresentation, protects the meaning of quotes, and says a synthetic/AI-generated likeness requires separate permission. Final legal wording should still be reviewed by counsel for the jurisdictions and circumstances in which it will be used.

## Private appreciation / expense trace

A signed or pending consent record can hold a **private payment trace** without exposing money on the public website or on the person's consent form.

The intended process is deliberately simple:

1. Make the payment through your normal **M-Pesa** or bank channel.
2. Open the relevant consent record in Writer.
3. Record method, amount, ISO currency, recipient if useful, destination/account if useful, bank/institution for bank transfers, paid date/time and the M-Pesa/bank transaction reference.
4. Optionally attach a JPG, PNG or PDF proof up to 5 MB.
5. The payment event is added to the release audit trail.

M-Pesa trace records use KES. Bank trace records accept any three-letter ISO currency code, including common options such as KES, USD, EUR, GBP, TZS, UGX, RWF, BIF and CAD. Proof files are stored under protected `/storage/` and can only be viewed through an authenticated Writer route.

This version records outbound payments after they are made; it does not attempt to connect generically to every bank. If automatic M-Pesa disbursement is later required, Safaricom Daraja Business-to-Customer can be added as a separate integration once the relevant B2C credentials and production approval are available.

## Writer planning improvements

Each post now has a private **Plan** tab before Publish / Media / SEO. It contains:

- the central question/angle;
- private research/source notes;
- a short pre-publish thinking checklist;
- a reminder to confirm permissions for people, photographs and quoted material.

These fields are stored with the post but are never rendered publicly, never indexed and are not included in the published article body.
