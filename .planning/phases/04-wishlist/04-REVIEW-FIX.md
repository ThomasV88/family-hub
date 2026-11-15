---
phase: 04-wishlist
fixed_at: 2026-11-13T20:00:00Z
review_path: .planning/phases/04-wishlist/04-REVIEW.md
iteration: 1
findings_in_scope: 14
fixed: 14
skipped: 0
status: all_fixed
---

# Phase 4: Code Review Fix Report

**Fixed at:** 2026-11-13
**Source review:** .planning/phases/04-wishlist/04-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 14 (WR-01..WR-11, IN-02, IN-04, IN-06)
- Fixed: 14
- Skipped: 0
- Not attempted (out of requested scope): IN-01, IN-03, IN-05, IN-07 (partly covered by WR-02), IN-08

Work ran directly on `main` in the main working tree (clean at start, no concurrent session) instead of a separate worktree, because the Docker test stack mounts the repo. Full verification at the end: Pest 959 passed, Vitest 891 passed, `npm run build` printed `SW-PRECACHE OK`. Arch, differential, canary, query-log, owner-action parity and OwnListPrivacy tests all stayed green.

## Fixed Issues

### WR-01: Shared throttle counter
**Files modified:** `routes/web/wishlist*.php`, `routes/web/gift-reminders.php`, `tests/Feature/Wishlist/ReviewFixTest.php`
**Commit:** cfef031
**Applied fix:** every wishlist and gift-reminder throttle now has the route name as its prefix (`throttle:N,1,<route name>`), so each route has its own per-user bucket. Test: exhausting image fetch (429) leaves upload, name-day search and wish creation working. Todo, auth and other non-wishlist numeric throttles still share one counter (not in scope).

### WR-02: Fetch on an uploaded image
**Files modified:** `WishImageController.php`, `FetchWishImage.php`, tests
**Commit:** 7e7bda9
**Applied fix:** `fetch` returns 409 when `image_source === 'uploaded'` and never sets pending or dispatches. The job restores state on every exit (uploaded won the race: `ready`/`none`; failure with an existing image: `ready` instead of `failed`). This also covers IN-07's re-fetch-failure case.

### WR-03: Unchecked image writes
**Files modified:** `WishImageStore.php`, new `WishImageWriteFailed.php`, `WishImageController.php`, tests
**Commit:** 3cddce5
**Applied fix:** both writes are verified; on failure the new files are deleted and `WishImageWriteFailed` is thrown before the row or old files are touched. The controller maps it to the `err_upload` validation error; the job falls into `failed()`, which keeps an old image visible. Test uses a failing disk.

### WR-04: gzip bomb
**Files modified:** `SafeFetcher.php`, `tests/fixtures/fetch/router.php`, `SafeFetcherSocketTest.php`
**Commit:** 60735e1
**Applied fix:** `decode_content => false` plus `Accept-Encoding: identity`, so the cap applies to bytes actually stored. Real-socket test serves a 256 MB-expanded gzip regardless of Accept-Encoding; it fails without the fix and passes with it.

### WR-05: `nameday: []`
**Files modified:** `MemberDatesController.php`, tests
**Commit:** f8fc44c
**Applied fix:** an empty array is a no-op and a missing `calendar` key no longer errors. Note: in this Laravel version the regression test also passes without the change (empty arrays do not reach `validated()`), so the fix is defensive.

### WR-06: Backslashes in text/plain mail
**Files modified:** `WishOccasionNotification.php`, `WishOccasionOutboxTest.php`
**Commit:** b02e97d
**Applied fix:** the HTML part keeps the Markdown-escaped lines and the theme; the mail now carries an explicit text part built from unescaped strings. Test checks names like "Mary-Ann O'Brien" appear without backslashes in text and links stay inert in HTML. Requires human verification of the mail appearance in a real client (HTML part is rendered through a closure around the same `notifications::email` template).

### WR-07: Image route visibility
**Files modified:** `WishImageController.php`, tests
**Commit:** b1d9aec
**Applied fix:** `show` requires the owner to be in `WishlistMembers::ids($viewer)`, and received wishes only for recipient-side viewers (`ViewerContext`, reservation-blind). Every refusal and unknown ids are the same 404.

### WR-08: Omitted `manager_ids`
**Files modified:** `UpdateWishlistManagersRequest.php`, `WishlistManagersTest.php`
**Commit:** 8fdb8a4
**Applied fix:** rule is `present|array|max:10`; omitting the key is a 422 and nothing changes, an explicit `[]` clears.

### WR-09: Image memory budget
**Files modified:** `ImageProcessor.php`, `config/wishlist.php`, `WishImageController.php`, `docker/app/php-fpm-prod.conf`, `ImageProcessorTest.php`
**Commit:** 427dfba
**Applied fix:** decodes are serialized per container with an advisory file lock (wait up to `wishlist.image_lock_wait_seconds`, 20 s, then `busy`, shown as the upload error). Pixel cap kept at 13 MP on purpose (a lower cap would reject normal 12 MP phone photos). Budget documented in the processor and the FPM conf. Measured: peak 59 MB for a 13 MP JPEG under a 128M `memory_limit`. The worker runs one `queue:work` process, so one job at a time. Memory limits in `compose.server.yaml` were not changed.

### WR-10: CC BY-SA notice
**Files modified:** new `database/data/namedays/LICENSE-pl.txt`, `SOURCES.md`, `lang/{en,sk,pl}.json`, `OccasionPanel.vue`, tests
**Commits:** 598e545, 2b0e0ec
**Applied fix:** licence notice file (licence URLs, source pages, revids, modifications, ShareAlike). The notice links to the legal code rather than embedding its full text. In-app attribution now links to the Polish Wikipedia source and the CC BY-SA 4.0 deed (both `rel="noopener noreferrer"`) and has a "modified" note. Three new keys in all three locales (`pl_attribution_source`, `_licence`, `_modified`), covered by the existing parity tests.

### WR-11: Reservation vs manager purge race
**Files modified:** `GivingGuard.php`, `ReservationService.php`, `CoBuyService.php`, `WishlistManagersController.php`, tests
**Commit:** a49de8c
**Applied fix:** `GivingGuard::assertStillGiver` runs inside the reserve, open and contribute transactions: shared lock on the owner's `users` row, then re-read of the manager table (403 if designated). `sync()` takes the exclusive lock on the same row first. Either the designation commits first (403) or the reservation commits first and the follow-up purge removes it. Recipient-side controllers still never touch reservation tables (arch test green). The interleaving itself is not exercised by a two-connection test; the tests cover the in-transaction re-check and the lock query.

### IN-02: Wish deleted mid-reserve
**Files modified:** `ReservationService.php`, tests
**Commit:** f8e1187
**Applied fix:** a foreign key violation (SQLSTATE 23503) becomes a 404 (this Laravel version has no dedicated FK exception class).

### IN-06: Co-buy on received wishes
**Files modified:** `CoBuyService.php`, tests
**Commit:** f8e1187
**Applied fix:** `open` and `contribute` return 409 for received wishes; `close` and `leave` stay allowed so people can still back out.

### IN-04: backup.sh keys
**Files modified:** `scripts/backup.sh`
**Commit:** fde8284
**Applied fix:** `UPLOADS_PATH` and `BACKUP_LOG_FILE` added to the keys read from `.env`. No automated test exists for this script; `bash -n` passes.

---

_Fixed: 2026-11-13_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
