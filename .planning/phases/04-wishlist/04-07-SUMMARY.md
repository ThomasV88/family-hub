---
phase: 04-wishlist
plan: 07
subsystem: wishlist-images
tags: [images, gd, queue, ssrf, upload, cache-headers, vue]
requires: [04-02, 04-04, 04-05, 04-06]
provides:
  - ImageProcessor / WishImageStore (hardened shared image pipeline, private local disk)
  - FetchWishImage queued job (worker-only fetch via SafeFetcher)
  - wishlist.images.{show,store,destroy,fetch} routes
  - panel image controls (upload, load from link, remove, polling)
affects: [04-13 live fetch check]
key-files:
  created:
    - app/Wishlist/Catalog/ImageProcessor.php
    - app/Wishlist/Catalog/ProcessedImage.php
    - app/Wishlist/Catalog/ImageRejected.php
    - app/Wishlist/Catalog/WishImageStore.php
    - app/Wishlist/Catalog/FetchWishImage.php
    - app/Http/Controllers/Wishlist/Owner/WishImageController.php
    - app/Http/Requests/Wishlist/UploadWishImageRequest.php
    - routes/web/wishlist-images.php
    - scripts/check-upload-limit.sh
    - tests/Support/ImageFixtures.php
    - tests/Support/image-memory-probe.php
    - tests/Support/EndpointMatrix/wishlist-images.php
    - tests/Unit/Wishlist/ImageProcessorTest.php
    - tests/Feature/Wishlist/WishImageTest.php
    - tests/Feature/Wishlist/FetchWishImageTest.php
    - tests/js/wishlist/WishImage.test.js
  modified:
    - app/Wishlist/Catalog/WishService.php
    - app/Wishlist/Catalog/WishPresenter.php
    - app/Http/Middleware/NoStoreDynamicResponses.php
    - tests/Feature/CacheHeadersTest.php
    - tests/TestCase.php
    - phpunit.xml
    - resources/js/Wishlist/OwnWishPanel.vue
    - resources/js/Wishlist/WishImage.vue
decisions:
  - "Image route cache header: private, no-cache + ETag + Vary Cookie + nosniff, not the UI-SPEC private, no-store"
  - "Upload authorisation runs in UploadWishImageRequest::authorize so non-owners get 403 before validation"
metrics:
  tasks: 3
  commits: [b5849a1, 7e17054, 88f806a]
  completed: 2026-11-13
---

# Phase 4 Plan 07: Wish images Summary

Product images end to end: a worker job loads og:image/twitter:image/JSON-LD candidates through SafeFetcher, a manual upload fallback, one hardened pipeline for both (byte sniffing, pixel cap, resize before rotate, metadata-free JPEG), private authenticated serving, and the owner panel controls.

## Commits

- b5849a1 image pipeline and private store
- 7e17054 job, endpoints, cache-header exemption, auto-fetch on save, upload-limit smoke
- 88f806a panel image controls and tests

## Image cache header decision

The image route answers `Cache-Control: private, no-cache` with a quoted sha1-of-path ETag, `Vary: Cookie` and `X-Content-Type-Options: nosniff`, instead of the UI-SPEC wording "private, no-store". Reason (orchestrator decision): every view still revalidates through the authenticated route, so access is re-checked each time, while an unchanged image costs a 304 instead of a full download on phones. `NoStoreDynamicResponses` exempts only `wishlist.images.show` with status 200 or 304; 404/403 on that route and every other route still get `no-store, private` (CacheHeadersTest, both cases).

## Measured results

- 12 MP (4000x3000) orientation-6 JPEG: peak memory delta **54.5 MiB** (limit 72 MiB), output 960x1280. Measured in a child PHP process started with `-d memory_limit=128M` (`tests/Support/image-memory-probe.php`), so it does not depend on suite memory.
- Line numbers in `ImageProcessor.php`: `getimagesizefromstring` line 27 before `imagecreatefromstring` line 43; source `imagedestroy` line 54 before the first `imagerotate` line 116 / `imageflip` line 127.
- Greps: no `getClientMimeType|getMimeType()` in ImageProcessor, no `Storage::url|'public'` in WishImageStore, no `SafeFetcher|Http::` in controller or WishService, `nosniff` 1x in controller, `wishlist.images.show` 1x in middleware.
- `bash scripts/check-upload-limit.sh fh0407`: `UPLOAD-LIMIT OK upload=302 other=413`. The upload route reaches PHP (302 from the app's own redirect for the CSRF-less POST, not 413/404); the same 2 MiB body on /login gets nginx 413.
- Full suites: Pest 900 passed, Vitest 821 passed (56 files), build prints SW-PRECACHE OK, Pint clean on this plan's PHP files.

## Deviations from Plan

**1. [Rule 3 - Blocking] check-upload-limit.sh details.** The migrate step needs the owner role (`-e DB_USERNAME=fh_owner -e DB_PASSWORD=fh_owner_dev`; the app role has no DDL). busybox `wget --post-file` masked the nginx 413 (it reported the app's 302), so the POST is sent as raw HTTP over busybox `nc` (with a short `sleep` to keep stdin open until PHP answers). Expected code is 302 rather than the plan's 419; still never 413/404.

**2. [Rule 2 - Missing critical] Tests must never touch the network.** The test queue is `sync`, so saving a wish with links runs `FetchWishImage` inline against the real `SystemDnsResolver`. `tests/TestCase.php` now binds `DnsResolver` to an empty `FakeDnsResolver` (the container injects it into `SafeFetcher`); fetch tests swap in their own `SafeFetcher` with a Guzzle MockHandler.

**3. [Rule 1 - Bug] `imagefill` on truecolor allocates a flood-fill buffer as large as the bitmap** (48 MB for 12 MP, OOM in the fixture and wasteful in the pipeline). Replaced with `imagefilledrectangle` in the processor and fixtures.

**4. [Rule 3 - Blocking] phpunit.xml `memory_limit` 256M.** The full Pest run peaks at about 123 MiB and failed with the 128M dev default once sibling plans added tests (my own tests peak at about 59 MiB). The 12 MP budget is verified separately at 128M in the child process.

**5. [Rule 2] Upload authorisation before validation.** `UploadWishImageRequest::authorize` checks `canEditList`, so a non-owner gets 403 whatever the body (the endpoint matrix posts no file, so the owner row is 302 from validation and everyone else 403).

**6. Create mode.** The panel still closes after the first save (existing behaviour; no `flash_data` consumer exists in the JS), so in create mode the upload button is disabled with no extra text and the image can be added by reopening the wish. Polling is scoped to the open panel's own wish rather than every visible card.

## Known Stubs

None. Live fetching from real shops is checked in plan 04-13.

## Threat Flags

None beyond the plan's threat model (T-04-28..35 mitigated as specified; WishImageStore::sweepOrphans exists but no scheduler entry is added by this plan).

## Self-Check: PASSED

All created files exist and commits b5849a1, 7e17054, 88f806a are in git log.
