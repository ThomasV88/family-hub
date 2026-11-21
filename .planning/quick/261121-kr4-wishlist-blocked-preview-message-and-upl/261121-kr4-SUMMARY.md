---
phase: quick-261121-kr4
plan: 01
subsystem: wishlist
tags: [wishlist, link-preview, i18n, docs]
requirements: [GH-27]
key-files:
  modified:
    - app/Wishlist/Fetch/SafeFetcher.php
    - app/Wishlist/Fetch/PagePreview.php
    - app/Wishlist/Catalog/FetchLinkPreview.php
    - app/Recipes/Import/FetchRecipePage.php
    - resources/js/Wishlist/OwnWishPanel.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/manual/en/wishlist.json
    - resources/manual/sk/wishlist.json
    - resources/manual/pl/wishlist.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - tests/Feature/Wishlist/LinkPreviewTest.php
    - tests/Unit/Wishlist/Fetch/SafeFetcherTest.php
    - tests/js/wishlist/LinkPreview.test.js
completed: 2026-11-21
---

# Quick 261121-kr4: Blocked-shop and too-large preview messages (GitHub #27)

SafeFetcher now reports HTTP 401/403/429 as `http_blocked`; the wish panel shows a specific message for it and for `too_large`, with an Upload image button (edit) or a "save first" sentence (create).

## What changed

- `SafeFetcher`: 401/403/429 throw `http_blocked`; other non-200 stay `http_status`. No workaround for bot protection (no UA spoofing, retries or challenge handling).
- `FetchLinkPreview::reason` passes `http_blocked` and `too_large` through; timeout and blocked_address/bad_url unchanged. Logs still hold reason codes only (new Pest test asserts no URL or page text).
- `FetchRecipePage` maps `http_blocked` back to `http_status`, so recipe import behaviour is unchanged (its `http 403` test is green untouched).
- `OwnWishPanel.vue`: `previewBlockReason` computed; for `http_blocked`/`too_large` the generic "could not load" list is replaced by `data-testid="preview-blocked"` text. When the image is missing and `canUpload` is true (edit panel) a `preview-upload` button reuses `pickImage`. In create mode (the owner's real case, upload unavailable until saved) no button is shown; instead `wishlist.preview.save_then_upload` ("Save the gift first, then upload the image from its edit panel.") appears. The always-present Upload button in the edit panel is unchanged.
- New keys `wishlist.preview.blocked_site`, `too_large`, `save_then_upload` in en/sk/pl.

## Docs touched

Manual (all three languages, same sections): `resources/manual/en/wishlist.json`, `resources/manual/sk/wishlist.json`, `resources/manual/pl/wishlist.json`.
Help (existing keys reworded, none added): `resources/help/en.json`, `resources/help/sk.json`, `resources/help/pl.json` (`wishlist.image`, `wishlist.links`).
**SK and PL texts (manual, help, lang keys) are drafts and need native review.**

## Tests

- Pest (compose project `fhkr4`, torn down with `down -v`): tests/Feature/Wishlist, Recipes, Manual, Help and tests/Unit/Wishlist: 716 passed. New: 401/403/429 -> http_blocked (feature and unit), 5 MB page -> too_large, log reason-code test.
- Vitest tests/js/wishlist, help, manual, brand, i18n*, a11y-sweep: 1664 passed (3 new tests: create mode message plus save-first sentence and no button; edit mode button opens picker; too_large message). `HelpSettingsPage` axe test failed intermittently under parallel load in two runs and passes alone (unrelated flake).
- Pint --test: pass. `npm run build`: OK (run in a scratch copy because public/build is root-owned); SW-PRECACHE OK.

## Deviations

- Plan said to build into a scratch outDir; the Laravel plugin ignores `--outDir`, so a scratch copy of the repo was built instead.
- Fixed the unfinished `provides` line of FetchLinkPreview in the plan must_haves.

## Self-Check: PASSED
