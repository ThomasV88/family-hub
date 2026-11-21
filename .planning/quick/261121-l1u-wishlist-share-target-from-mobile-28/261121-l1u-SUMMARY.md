---
phase: quick-261121-l1u
plan: 01
subsystem: wishlist
tags: [pwa, share-target, wishlist, manual]
requirements: [GH-28]
key-files:
  created:
    - app/Wishlist/Catalog/SharedLink.php
    - app/Http/Controllers/Wishlist/Owner/ShareTargetController.php
    - tests/Unit/Wishlist/SharedLinkTest.php
    - tests/Feature/Wishlist/ShareTargetTest.php
    - tests/js/wishlist/SharePrefill.test.js
  modified:
    - vite.config.js
    - routes/web/wishlist.php
    - app/Http/Controllers/Wishlist/ListController.php
    - resources/js/sw.js
    - scripts/check-sw-precache.mjs
    - resources/js/Pages/Wishlist/Index.vue
    - resources/js/Wishlist/OwnWishPanel.vue
    - tests/Support/EndpointMatrix/wishlist.php
    - resources/manual/en/wishlist.json
    - resources/manual/sk/wishlist.json
    - resources/manual/pl/wishlist.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
metrics:
  completed: 2026-11-21
---

# Quick 261121-l1u: Wishlist share target from mobile (GitHub #28) Summary

Android Chrome can share a product page to the installed FamilyHUB, which opens the new-wish form on the viewer's own list with the link and title filled in and the existing link preview running; nothing is saved until Save.

## What changed

- Manifest `share_target` (GET `/wishlist/share`, params title/text/url) in `vite.config.js`. Name FamilyHUB and the familyhub icons are unchanged.
- `GET /wishlist/share` (`wishlist.share`, middleware `access:module,wishlist,edit` plus `throttle:30,1`): `ShareTargetController` reads only string query values, runs `SharedLink::from`, flashes `wishlist.share` and redirects to `/wishlist?wish=new`. Title and URL are never in the redirect address, are not logged and are not persisted. Guests go through login and return to the share URL with its query (asserted in a test).
- `SharedLink`: url param first, else the first http(s) URL in the text (trailing punctuation trimmed); rejects javascript:/data:/ftp:/file:, credentials and URLs over 2048 chars; title from the param, else the text without the URL; control chars stripped, capped at `wishlist.title_max`.
- `ListController::index` exposes the flash as Inertia prop `share_prefill` (that render only).
- `Index.vue` passes it as `prefill` to `OwnWishPanel` only for the viewer's own list in create mode (not group, not manager view). `OwnWishPanel` applies it once per opening: fills an empty title and empty first link, then calls the existing `onFirstUrl` so the #16 preview runs. Snapshot untouched, so the form is dirty and the unsaved-changes guard applies. #16 and #27 logic is untouched.
- Service worker: comment that `/wishlist/share` is network-only; `check-sw-precache.mjs` now fails the build if it appears in the precache.
- EndpointMatrix row `wishlist.share`: guest 302, admin 403, parent/child/grandparent 302, guestPreset 403, noGrants 403.

## Tests

- Pest (compose project `fhl1u`, `down -v` afterwards): tests/Unit/Wishlist, tests/Feature/Wishlist, tests/Feature/Access: 740 passed (incl. new SharedLinkTest, ShareTargetTest, EndpointMatrix `wishlist.share`); tests/Feature/Manual + Help: 49 passed. Pint --test: PASS, 1299 files.
- Vitest (wishlist, help, manual, brand, i18n, a11y-sweep, sw-precache suites): 1681 passed, 7 failed in the first parallel run, all in HelpSettingsPage ("Axe is already running", the known flake). Rerun alone: 16/16 passed. tests/js/wishlist alone: 254 passed (5 new in SharePrefill.test.js).
- `npm run build` in a scratch copy (public/build is root-owned): SW-PRECACHE OK. Built `manifest.webmanifest` contains name FamilyHUB, the familyhub icons and `share_target`.

## User documentation (SK/PL are drafts, need native review)

- resources/manual/en/wishlist.json: new section `share-from-phone` after `add-wish` (steps plus notes: Android Chrome with installed app, reinstall if FamilyHUB is missing from the Share list, iOS not supported so copy and paste, nothing saved until Save, group lists not a target).
- resources/manual/sk/wishlist.json, resources/manual/pl/wishlist.json: same section ids and block structure. DRAFTS, native review needed.
- resources/help/en.json, sk.json, pl.json: key `wishlist.links` got one added sentence about sharing from the phone (en 345, sk 322, pl 348 characters, under 400). Meaning of the key unchanged. SK and PL are DRAFTS.

## Deviations from Plan

- `OwnListView.vue` was in the plan's file list but needed no change: `OwnWishPanel` is rendered directly by `Index.vue`, so the prefill goes there.
- No new help key; the existing `wishlist.links` text was extended as the plan allowed.

## Known limits

- Share-target registration happens at app install; users with an older install must reinstall once (stated in the manual). Not testable here on a real device; verify on staging with Android Chrome.

## Self-Check: PASSED
