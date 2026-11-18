---
phase: quick-261118-rkf
plan: 01
subsystem: wishlist
tags: [wishlist, link-preview, safefetcher, autofill, i18n]
requirements: [GH-16]
key-files:
  created:
    - app/Wishlist/Fetch/PagePreview.php
    - app/Wishlist/Catalog/FetchLinkPreview.php
    - app/Http/Controllers/Wishlist/Owner/LinkPreviewController.php
    - resources/js/Wishlist/linkPreview.js
    - tests/Feature/Wishlist/LinkPreviewTest.php
    - tests/js/wishlist/LinkPreview.test.js
  modified:
    - app/Wishlist/Fetch/MetaImageExtractor.php
    - routes/web/wishlist-images.php
    - resources/js/Wishlist/OwnWishPanel.vue
    - resources/js/Wishlist/LinksEditor.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/manual/en/wishlist.json
    - resources/manual/sk/wishlist.json
    - resources/manual/pl/wishlist.json
    - tests/Unit/Wishlist/Fetch/MetaImageExtractorTest.php
    - tests/Support/WishlistPrivacy.php
completed: 2026-11-18
---

# Quick 261118-rkf: Wishlist autofill from first link (#16) Summary

Pasting the first link of a new wish fills an empty Title and Notes from the page and shows the found product picture as a thumbnail, through a queued job that uses only SafeFetcher.

## What changed

- `MetaImageExtractor`: new `title()` and `description()` (priorities, tag stripping, whitespace collapse, mb_substr caps 120 / 2000) and a last-resort fallback to the first `<img>` with width or height >= 200. `candidates()` order is unchanged; DOM loading moved to a shared private helper.
- `PagePreview` value object; `FetchLinkPreview` queued job (tries 1, timeout 60); result stored in cache for 5 minutes bound to the user id. Reason codes: timeout, blocked (blocked_address, bad_url), no_tags, unreachable. Logs carry reason codes only.
- `LinkPreviewController`: `POST /wishlist/link-preview` (access edit, throttle 15/min), `GET /wishlist/link-preview/{token}` (poll, 404 for other users or unknown token), `GET /wishlist/link-preview/{token}/image` (thumbnail).
- Frontend: `LinksEditor` emits `first-url` on blur or paste of row 0 with a valid http(s) URL. `OwnWishPanel` triggers once per panel open, only when the wish had no link on open. Polls every 1.5 s up to 20 times. Fills title/notes only if blank at start and still blank on arrival. Inline status, found-image note, and a list of the missing pieces by name with the reason. Button "Reload from first link" for existing wishes (also re-triggers the existing image fetch route when no uploaded image exists). Logic lives in the new `resources/js/Wishlist/linkPreview.js` composable to keep OwnWishPanel edits small for #17.

## Is the image shown immediately?

Yes (beyond the plan text, per owner intent). The worker fetches up to 3 image candidates through SafeFetcher (same size/type limits) and runs the first valid one through the existing ImageProcessor; only the 480 px thumbnail is kept in cache for 5 minutes and served from our own origin by the authenticated `/wishlist/link-preview/{token}/image` route (user-bound token, no-store). The browser never contacts the remote site. The saved wish image is still attached on save by the existing FetchWishImage job.

## Verification

- Pest (docker compose project `fhrkf261118`, torn down with `down -v`): `tests/Feature/Wishlist`, `tests/Feature/Manual`, `tests/Feature/Help`: 244 passed. Earlier filter run (MetaImageExtractor, LinkPreview, FetchWishImage, SafeFetcher): 112 passed.
- Vitest: `tests/js/wishlist`, `help`, `manual`, `brand`: 24 files, 359 passed.

## User documentation (CLAUDE.md rule)

Touched: `resources/manual/en|sk|pl/wishlist.json` (Links bullet in the add-wish section; section ids and counts unchanged), `resources/help/en.json`, `sk.json`, `pl.json` (reworded `wishlist.links`; no new key). `resources/help/topics.json` not changed (no new key). **SK and PL texts (manual, help, lang `wishlist.preview.*`) are drafts and need native review.**

## Deviations from Plan

1. [Rule 3] Added `resources/js/Wishlist/linkPreview.js` (composable) and `tests/js/wishlist/LinkPreview.test.js`; not in the plan file list but keeps the panel edit small.
2. [Rule 3] Extra route `GET /wishlist/link-preview/{token}/image` and thumbnail in the form (owner request). `FetchLinkPreview` takes a third constructor argument (user id) and also fetches the image.
3. [Rule 1] `tests/Support/WishlistPrivacy.php` got a `token` provider because the privacy route sweep failed on the new `{token}` route.
4. `resources/help/topics.json` and `lang` key `reload` used instead of a new help key; no `wishlist.link_reload` tip added.
5. The `Fixes #16` commit is the final docs/summary commit (no separate single commit as Task 3 text suggested), per orchestrator instruction.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register (new endpoints covered by T-rkf-01..05; thumbnail endpoint is user-bound like the poll endpoint).

## Self-Check: PASSED
