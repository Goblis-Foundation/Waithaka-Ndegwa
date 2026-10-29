# Circular Waithaka Ndegwa favicon and website/Writer app icons — 29 October 2026

## Intended experience

The previous favicon looked square because the build painted an opaque white square behind the WN monogram. The new release gives it a deliberate **circular blue outline and white face**, retaining the two original blue and green WN path geometries with no new lettering, silhouette, portrait, small tagline or unapproved logo substitute.

- **Browser favicon:** transparent corners, circular edge and approved two-colour vector paths, including multi-resolution PNG/ICO and a scalable SVG. Browser tab appearance may vary with the tab/theme and OS.
- **Website and Writer installed PWAs:** shared circular WN brand, independently named and scoped manifests, and transparent corners for `any` icons. The same art is used for Apple touch icons.
- **Maskable launcher icon:** opaque white background and circular outlined mark, with WN geometry within central launcher-safe bounds. Android launchers may apply their own mask.
- **Header brand:** the existing approved horizontal 720 × 160 lockup stays byte-identical and the original approved source SVG is not changed.
- **Cache:** asset and manifest URLs use `20261029c`, service worker `wn-app-v6`. The public PWA starts at `/`; Writer starts at `/write/communication`.

## Implementation

The immutable release builder compiles the derived icon assets at deployment from `assets/images/wn-approved-monogram.svg`. It supersamples the circular outline and approved path layers before resampling; non-maskable PNGs have genuine alpha at the four corners. The SVG favicon retains actual outlined WN paths (not text or a raster-embedded image).

Tests require: transparent nonmaskable corners, opaque maskable corners/central mark, both brand colours, valid multi-size ICO, approved unchanged header hash, correct PNG dimensions, separate PWA identities, new cache keys, and correctly-versioned public/Writer favicon references. The standard production workflow also tests live HTTPS icons/manifest and the branded PWA routes.

## Boundaries

This is a visual identity / cache update only. It does not release email, social posts or payments, alter owner accounts, update content, widen provider scopes or close any of issues #70–76. The original lockup, typography and Google Analytics consent remain unchanged. Installer icons pinned by installed operating systems may refresh only when the platform next updates its PWA resources; users may have to remove/re-add an older home-screen shortcut if their launcher refuses to refresh artwork.
