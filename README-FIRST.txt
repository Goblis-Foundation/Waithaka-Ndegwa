WAITHAKA NDEGWA — CPANEL ROOT-READY PACKAGE
===========================================

This ZIP is intentionally packaged WITHOUT an extra parent folder.

INSTALLATION
1. In cPanel, open the DOCUMENT ROOT assigned to www.waithakandegwa.com.
2. Upload this ZIP into that exact directory.
3. Extract it there.
4. Confirm index.php and .htaccess are directly inside the document root — NOT inside another waithakandegwa_site folder.
5. Confirm PHP 8.1+.
6. Open: https://www.waithakandegwa.com/install/
7. Complete the one-time database/Writer installation.

EXPECTED ROOT CONTENTS
index.php
.htaccess
install/
app/
assets/
storage/
views/
manifest.webmanifest

BLANK-PAGE PROTECTION
The package now logs uncaught PHP/server errors to storage/logs/application.log and displays a safe diagnostic page instead of a blank white page.

THIS BUILD
----------
• Public archive: /my-thoughts (legacy /writing links redirect permanently).
• Personal-photo editorial redesign for home and About pages.
• Contributions: M-Pesa, card via Pesapal, and PayPal.
• Configure payment/API credentials after installation under Writer → Settings.

REFINEMENT UPDATE
- “Support My Thoughts” remains a separate header action outside the navigation menu (the contribution page and legal wording remain Contribution).
- Contact page redesigned with guided message prompts, structured follow-up explanation, improved mobile layout and message character count.
- Homepage and About copy refined to be more vivid, personal and experience-led.
- Journey photography is gently brightened and delivered as optimised WebP assets.
- Future uploaded featured images are capped at 1600px and stored as lighter WebP files.
- Named company/organisation affiliations have been removed from public content and Person structured data so Support My Thoughts remains clearly personal.
- My Thoughts now has a featured-thought layout, instant archive filtering, sort controls, topic chips and stronger mobile discovery.
- Search has been redesigned with topic shortcuts and clearer result states.

GOOGLE ANALYTICS
- GA4 Measurement ID G-WCKVHN823V is preconfigured.
- One Google tag is installed in the shared public layout immediately after <head>.
- Consent Mode v2 keeps analytics storage denied until the visitor allows analytics.
- Do not paste a second Google tag into individual pages.

- The public Pesapal route is card-branded. Pesapal controls methods inside its hosted checkout; request card-only merchant configuration if needed.

V6 REBUILD: every public page has been rebuilt on one normalized editorial design system. Home, My Thoughts, About, Contact, Support, Search, post detail, policy pages and the global header/footer now share consistent spacing, image ratios, typography, cards, forms and responsive behavior. The previous layered V5 styling was removed rather than patched.

V8 REFINEMENT
-------------
- Pesapal Card supports requested currency selection: KES, USD, EUR and GBP.
- Anonymous support is supported; names are optional. Pesapal still requires one transaction contact (email or phone) to initiate card checkout.
- Contribution confirmation has a redesigned thank-you/status experience with automatic status refresh for pending transactions.
- Regional SEO has been strengthened naturally for Kenya, Tanzania, Zanzibar, Uganda, Rwanda and Burundi.
- My Thoughts has a more intuitive topic/filter/archive flow and a clearer thought-development process.
- Writer has been reorganised into Library + focused editor with Publish / Media / SEO tabs, live word/read counts, autosave and mobile-friendly navigation.
- No cron job is required. See CRON-JOBS.txt.

PRIVATE CONSENT / MEDIA RELEASES
After installation, Writer includes a private Consent section at /write/releases. It creates unlisted /consent/<token> links for photo/video/audio/conversation permissions and keeps optional M-Pesa/bank appreciation records internally. These pages are not added to public navigation or the sitemap.
