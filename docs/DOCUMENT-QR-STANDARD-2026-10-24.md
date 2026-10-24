# Document QR and print standard — 24 October 2026

## Implemented coverage
- Public website pages, including articles, professional information and policies, have an online-version reference with locally generated QR and Print / save PDF.
- Protected consent records, linked founder submission records and saved article editors have a protected workspace reference.
- Standalone recipient consent forms and final-copy founder review pages point to the protected owner record, never their bearer access token. Their label explicitly requires authorised workspace sign-in.
- QR generation is local, with pinned MIT-licensed qrcode-generator source, four-module white quiet zone, black modules and 36 mm print size. On small screens it is 30 mm.
- URL and reference remain readable if JavaScript is unavailable. QR codes require JavaScript; print only after the QR is visible.
- Print styling removes navigation, action forms and interactive controls, avoids QR block splitting and reserves page margins. A document reference follows the content rather than overlaying page text.

## Privacy and meaning
No external QR service, token, email, contact details, query string or fragment enters the QR payload. Links are same-origin. Public links show the current online version; they do not certify immutable copies. Private records still enforce existing workspace access controls. Review references identify the review round; the QR does not approve the story.

## Scope boundaries
Uploaded third-party documents and payment evidence remain byte-for-byte originals. This does not add a QR to original uploads, calendar files, raw data exports or configuration files. There is no separate Word/PDF generation engine in this release; browser Print / save PDF uses the document layout. Future generated document engines must reuse this reference and privacy policy.

## Verification
Offline Node checks cover prohibited secret/external URLs, actual QR encoding, quiet-zone dimensions and print boundaries. PHP syntax and broader application suites run in CI. Real-device scan and final visual print inspection remain acceptance checks.

## Dependency
Source: https://github.com/kazuhikoarase/qrcode-generator
Pinned commit: 83b7e8fe3fddd3b0368dbafd6ce56995bd25e3c8
File: js/dist/qrcode.js
Licence preserved at assets/js/vendor/QRCODE-LICENSE.txt.
