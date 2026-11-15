---
phase: 04-wishlist
reviewed: 2026-11-13T00:00:00Z
depth: deep
files_reviewed: 62
files_reviewed_list:
  - app/Wishlist/ViewerContext.php
  - app/Wishlist/WishAccess.php
  - app/Wishlist/WishlistMembers.php
  - app/Wishlist/Money.php
  - app/Wishlist/Catalog/FetchWishImage.php
  - app/Wishlist/Catalog/ImageProcessor.php
  - app/Wishlist/Catalog/ListPage.php
  - app/Wishlist/Catalog/WishImageStore.php
  - app/Wishlist/Catalog/WishPresenter.php
  - app/Wishlist/Catalog/WishService.php
  - app/Wishlist/Giving/CoBuyService.php
  - app/Wishlist/Giving/GiverListPage.php
  - app/Wishlist/Giving/GivingGuard.php
  - app/Wishlist/Giving/ManagerReservationPurger.php
  - app/Wishlist/Giving/PurgeManagerReservations.php
  - app/Wishlist/Giving/ReservationPresenter.php
  - app/Wishlist/Giving/ReservationRetention.php
  - app/Wishlist/Giving/ReservationService.php
  - app/Wishlist/Giving/ShoppingSummary.php
  - app/Wishlist/Fetch/SafeFetcher.php
  - app/Wishlist/Fetch/UrlResolver.php
  - app/Wishlist/Fetch/PublicIpv4Policy.php
  - app/Wishlist/Fetch/SystemDnsResolver.php
  - app/Wishlist/Fetch/MetaImageExtractor.php
  - app/Wishlist/Occasions/OccasionGivers.php
  - app/Wishlist/Occasions/OccasionAccess.php
  - app/Wishlist/Occasions/OccasionCalendar.php
  - app/Wishlist/Occasions/OccasionsPage.php
  - app/Wishlist/Occasions/UpcomingOccasions.php
  - app/Wishlist/Occasions/ReminderLeadDays.php
  - app/Wishlist/Occasions/NameDayCalendar.php
  - app/Wishlist/Occasions/AnnualDate.php
  - app/Http/Controllers/Wishlist/ListController.php
  - app/Http/Controllers/Wishlist/Giving/CoBuyController.php
  - app/Http/Controllers/Wishlist/Giving/ContributionController.php
  - app/Http/Controllers/Wishlist/Giving/ReservationController.php
  - app/Http/Controllers/Wishlist/Giving/ShoppingController.php
  - app/Http/Controllers/Wishlist/Owner/WishController.php
  - app/Http/Controllers/Wishlist/Owner/WishImageController.php
  - app/Http/Controllers/Wishlist/Owner/ReceivedController.php
  - app/Http/Controllers/Wishlist/Owner/OccasionController.php
  - app/Http/Controllers/Wishlist/Owner/MemberDatesController.php
  - app/Http/Controllers/Wishlist/Owner/NameDayController.php
  - app/Http/Controllers/Settings/WishlistManagersController.php
  - app/Http/Controllers/Settings/GiftReminderSettingsController.php
  - app/Http/Requests/Wishlist/*.php (5 files)
  - app/Http/Requests/Settings/UpdateWishlistManagersRequest.php
  - app/Models/Wish.php, WishContribution.php, WishOccasion.php, WishReminderSetting.php, WishReservation.php, WishUrl.php, WishlistManager.php
  - app/Console/Commands/EnqueueOccasionReminders.php
  - app/Console/Commands/PruneWishlist.php
  - app/Notifications/WishOccasionNotification.php
  - app/Notifications/Outbox/WishOccasionHandler.php
  - app/Notifications/Outbox/OutboxHandlers.php
  - app/Jobs/SendOutboxItem.php
  - app/Http/Controllers/HomeController.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - app/Http/Middleware/NoStoreDynamicResponses.php
  - app/Http/Middleware/RequireAccess.php
  - routes/web/wishlist*.php, gift-reminders.php, settings-wishlist-managers.php
  - database/migrations/2026_11_15_*.php
  - resources/js/Pages/Wishlist/Index.vue
  - resources/js/Wishlist/ReservationBox.vue, ShoppingSummary.vue, GiftListView.vue, WishImage.vue
  - scripts/backup.sh, restore-drill.sh, backup-freshness.sh, check-upload-limit.sh
  - docker/app/Dockerfile, php-prod.ini, php-uploads.ini, php-fpm-prod.conf
  - docker/nginx/snippets/laravel-locations.conf, fastcgi-app.conf, security-headers.conf
  - compose.yaml, compose.server.yaml
  - scripts/namedays/build-pl.php, build-sk.php, database/data/namedays/SOURCES.md
findings:
  critical: 0
  warning: 11
  info: 8
  total: 19
status: issues_found
---

# Phase 4 (Wishlist): Code Review Report

**Reviewed:** 2026-11-13
**Depth:** deep
**Files Reviewed:** 62 (groups counted as one where listed together)
**Status:** issues_found

## Summary

Primary focus: can the wish recipient, or a designated manager of that list, learn of a reservation or co-buy through any surface? I could not construct a leak. Specific results:

- **Single branch point.** `ListController` picks the reservation-blind `ListPage` for recipient-side viewers and `GiverListPage` for everyone else. `ListPage` never touches reservation tables. Props go through the explicit `WishPresenter` allowlist, not model dumps.
- **Write paths.** Every giving route runs `GivingGuard::authorize` (403 for the owner and managers) before any reservation table is read. `ReservationService` and `CoBuyService` re-assert with `assertGiver`.
- **`wishes.updated_at`.** Reservation writes never touch the `wishes` row. `WishReservation` has no `$touches` and the schema has no FK or column on `wishes`.
- **Flash and shared props.** `flash_data.reservation_conflict` is only produced inside a request that passed the giver guard. It is session-scoped to that giver.
- **`/wishlist/shopping`.** It returns only the caller's own contribution rows and excludes every owner for whom the caller is recipient-side. This holds even before the manager purge job has run.
- **Ordering, counts, pagination.** The recipient's ordering is priority, created_at, id. Reserved-last ordering exists only in the giver page. There is no pagination and no reservation-derived count.
- **Reminders.** Recipients are `wishlist >= edit` minus the occasion owner and that owner's managers. The set is recomputed at send time in `WishOccasionHandler::stillRelevant`. Payloads, push text and mail contain only ids, names and dates.
- **Audit and logs.** The wishlist module is not audited, `ReservationService` writes no log, and the unique-violation exception is caught.
- **Races.** `wish_reservations.wish_id` is unique, and every co-buy transition locks the reservation row.
- **Images.** Image bytes are re-encoded to a metadata-free JPEG after a pixel cap. The private disk has `serve => false`.
- **SSRF.** One DNS resolve, IPv4 pinning with `CURLOPT_RESOLVE` plus a prereq connected-IP check, manual redirects re-validated per hop, a port allowlist, an FQDN-only host regex and an explicit deny list.
- **Backups.** Uploads and the DB dump stream directly into an encrypted restic repo. There is no plaintext file.

No critical issues. The warnings are robustness, availability, correctness and licence problems. None of them leaks reservation state, but WR-11 is a privacy-adjacent hygiene gap.

## Warnings

### WR-01: All `throttle:N,1` routes share one per-user counter

**File:** `routes/web/wishlist.php`, `wishlist-giving.php`, `wishlist-images.php`, `wishlist-occasions.php`, `gift-reminders.php` (every `throttle:N,1`)
**Issue:** Laravel's `ThrottleRequests::resolveRequestSignature` returns only the user id for authenticated users. For numeric `throttle:max,decay` with no prefix, the route is not part of the key. Every wishlist route (and every other numeric throttle in the app) therefore increments the same counter for a user, while each route compares it with its own maximum.
- The 10/min image fetch and 20/min upload limits are consumed by unrelated requests, such as typing in the name-day typeahead (limit 120).
- Conversely, a burst on the name-day search or wish edits can push the counter past 60, so reservation toggles return 429.
This is not a privacy leak (counters are per user). It makes the intended per-route limits meaningless and produces confusing 429s on giver actions.
**Fix:** Give each limit its own prefix, or use named limiters:
```php
->middleware(['access:module,wishlist,edit', 'throttle:60,1,wishlist-reserve'])
->middleware(['access:module,wishlist,edit', 'throttle:10,1,wishlist-image-fetch'])
```

### WR-02: "Fetch image" on a wish with an uploaded image leaves it stuck in `pending` and hides the image

**File:** `app/Http/Controllers/Wishlist/Owner/WishImageController.php:79-88`, `app/Wishlist/Catalog/FetchWishImage.php:41-44`
**Issue:** `fetch()` sets `image_status = 'pending'` unconditionally and dispatches the job. `FetchWishImage::handle` returns early when `image_source === 'uploaded'` and never resets the status. `WishService::markPendingForFetch` guards against exactly this case; the controller does not. The result is that `WishPresenter::imageUrl` returns null for any status other than `ready`. The uploaded image disappears from the UI, a skeleton shows, and the front end polls 10 times and gives up.
**Fix:** In the controller, refuse (or no-op) when `$model->image_source === 'uploaded'`, matching the service:
```php
if ($model->image_source === 'uploaded' || $model->urls()->doesntExist()) {
    return back(303);
}
```
Also make the job's early return restore the previous state, for example `if ($wish->image_status === 'pending') { set 'ready' when image_path !== null }`.

### WR-03: `WishImageStore::put` ignores `Storage::put` failures; the `local` disk is configured `throw => false`

**File:** `app/Wishlist/Catalog/WishImageStore.php:22-31`, `config/filesystems.php` (`'throw' => false`)
**Issue:** `$disk->put()` returns `false` on a full disk or a permissions mismatch on the `uploads` volume, and does not throw. The code then writes `image_status = 'ready'` with paths to files that do not exist. It deletes the previous image files. `WishImageController::show` then 404s. For an upload the user gets a success toast and silently loses the old image too. With a misconfigured volume owner every upload "succeeds".
**Fix:** Check both writes before touching the row or deleting old files:
```php
if ($disk->put($full, $image->fullBytes) === false || $disk->put($thumb, $image->thumbBytes) === false) {
    $disk->delete([$full, $thumb]);
    throw new \RuntimeException('wish image write failed');
}
```
Map the exception to a validation error or `markFailed` in the job.

### WR-04: SSRF fetcher caps compressed bytes, not decoded bytes (gzip bomb)

**File:** `app/Wishlist/Fetch/SafeFetcher.php:127-154`, `readCapped` at 194-209
**Issue:** The Guzzle default `decode_content => true` makes curl send `Accept-Encoding` and decode the body into the default `php://temp` sink. The `on_headers` Content-Length check and the `progress` callback reflect on-the-wire size (and, depending on libcurl, possibly raw size). `readCapped` only runs after the whole decoded body is already in the sink. A hostile product page, or one reached through a redirect, can serve a few hundred KB of gzip that expands to GBs. It spills to a temp file on disk or into worker memory (the worker has `mem_limit` 160m) within the 10 s timeout, killing the queue worker. Any member with wishlist edit can trigger this by saving a URL.
**Fix:** Disable decoding so the cap applies to bytes actually stored:
```php
'decode_content' => false,
'headers' => [... , 'Accept-Encoding' => 'identity'],
```
Alternatively use a custom `sink` stream that throws once `ftell` exceeds `$maxBytes`.

### WR-05: `nameday: []` (empty array) passes validation and then causes a 500

**File:** `app/Http/Controllers/Wishlist/Owner/MemberDatesController.php:27-31`, `app/Http/Requests/Wishlist/MemberDatesRequest.php:50-52`
**Issue:** `nameday` is `sometimes|nullable|array`. An empty array or `{}` is not "present" for `required_with:nameday`, so the nested rules do not fire. `validated()` still contains `nameday => []`. The controller checks `$nameday === null || ! in_array($nameday['calendar'], ...)`, which hits an undefined array key and becomes an `ErrorException` (500). An authenticated member with edit can trigger it. It is a robustness defect, not a privacy issue.
**Fix:** Treat an empty value as a clear request, or reject it:
```php
if ($nameday === null || $nameday === [] || ! in_array($nameday['calendar'] ?? null, NameDayCalendar::calendars(), true)) {
```

### WR-06: Gift reminder mail shows literal backslashes in the text/plain part

**File:** `app/Notifications/WishOccasionNotification.php:20-24, 63-68`
**Issue:** `plain()` backslash-escapes every ASCII punctuation character so names cannot become Markdown. That is correct for the HTML part. The Laravel notification mail also renders a `text/plain` alternative from the same raw lines (`notifications::email-plain`) without Markdown processing. Common names such as "Mary-Ann", "O'Brien" or "Anna-Mária", and custom titles with punctuation, therefore appear as `Mary\-Ann` and `O\'Brien` in plain-text mail clients. The greeting name is escaped the same way. This is a correctness defect in user-visible output. It does not affect the security property.
**Fix:** Do not hand-escape. Pass user content through Markdown-safe means, for example `new HtmlString(e($name))` lines via a custom view, or `MailMessage::view()/markdown()` with `{{ }}` escaping. Alternatively escape only `[]()*_`<>\` and `!`, which is the minimal set that stops links, images and emphasis.

### WR-07: Image route authorizes by module level only, not by list visibility

**File:** `routes/web/wishlist-images.php:5-7`, `app/Http/Controllers/Wishlist/Owner/WishImageController.php:22-37`
**Issue:** `show` checks `access:module,wishlist,view` and then serves any `wish` id. It does not apply the `WishlistMembers` check that `ListController` applies. Any wishlist-view member can read:
- the image of a wish of a member whose wishlist access was revoked (their list now 403s);
- the image of a wish that is `received` (hidden from giver views);
- ids by enumeration (404 vs 200 reveals which wishes have ready images).
These are low-sensitivity product photos, but this is the IDOR class the phase was asked to check. The route does not leak reservation state.
**Fix:** After loading the wish, require the owner to be in `WishlistMembers::ids($viewer)`. For non-recipient-side viewers also require `received_at === null`. Return a uniform 404 otherwise.

### WR-08: Omitting `manager_ids` silently removes every designated manager

**File:** `app/Http/Requests/Settings/UpdateWishlistManagersRequest.php:25`, `app/Http/Controllers/Settings/WishlistManagersController.php:33-40`
**Issue:** `manager_ids` is `sometimes|array`. A request without the key gives `managerIds() = []`, and `sync()` deletes all managers for that owner (`whereNotIn('manager_id', [])`). The route is admin-only, but one malformed or partial PUT removes access that owners rely on. The removed managers silently become givers, so they see reservations on that list. That is a privacy-relevant state change caused by a missing parameter.
**Fix:** Use `'manager_ids' => ['present', 'array', 'max:10']` so an explicit empty array is required to clear.

### WR-09: Image processing memory budget exceeds the container limits

**File:** `docker/app/php-fpm-prod.conf:2-3`, `docker/app/php-prod.ini`, `compose.server.yaml` (`mem_limit` app 192m, worker 160m), `config/wishlist.php:21`
**Issue:** `pm.max_children = 4` with `memory_limit=128M` and `mem_limit: 192m` for the whole app container. A 13 MP image costs about 52 MB for the GD bitmap plus the 5 MB byte string(s), plus libpng/libwebp allocations outside `memory_limit`. Four parallel maximum-size uploads, which an edit-level member can send (and the shared throttle in WR-01 does not stop), can exceed 192 MB and get the container OOM-killed, taking down the site. The worker (160m) has the same exposure from fetched images.
**Fix:** Lower `image_max_pixels` (about 8 MP is plenty for 1280 px output), or gate the processor with a semaphore/lock so only one runs at a time. Raise `MEM_APP`/`MEM_WORKER`, or set `pm.max_children` to 2.

### WR-10: Polish name-day data: CC BY-SA licence text and attribution are incomplete

**File:** `database/data/namedays/` (no `LICENSE-pl`), `lang/en.json:907` (and sk/pl), `resources/js/Wishlist/OccasionPanel.vue:301`, `database/data/namedays/pl.json`
**Issue:** The data is derived from Polish Wikipedia (CC BY-SA 4.0). The repo ships the MIT text for the Slovak data but no licence file or link for the Polish data. The in-app attribution is a plain string ("Wikipedia contributors, CC BY-SA 4.0") shown only in the picker hint:
- it does not link to the licence or to the source page;
- it does not indicate that the data was modified (parsed, names normalised, qualifiers dropped), which CC BY-SA 4.0 section 3(a) requires.
`SOURCES.md` records the derived-work status but nothing in the shipped artefacts carries the notice.
**Fix:** Add `database/data/namedays/LICENSE-pl.txt` (or a NOTICE) with the licence URL, source URL, revids and a "modified" statement. Make the in-app attribution a link to `https://creativecommons.org/licenses/by-sa/4.0/` and to the Polish Wikipedia source. Keep the per-file `license` field.

### WR-11: Reservation can slip past the manager-designation purge (TOCTOU), then lingers until the daily sweep

**File:** `app/Wishlist/Giving/ReservationService.php:21-35`, `app/Http/Controllers/Wishlist/Giving/ReservationController.php:16-17`, `app/Wishlist/Giving/PurgeManagerReservations.php`
**Issue:** The recipient-side check (`ViewerContext::for`) runs twice before the transaction and is never re-checked inside it. If the designation commits and the purge job runs between the guard and the insert, the new manager's reservation survives. Other givers then see the manager as organizer of a gift on a list the manager now manages, until `wishlist:prune` (daily, `purgeAll`) cleans it. The manager still cannot see anything (`ListPage` is blind and `ShoppingSummary` excludes recipient-side owners), so there is no leak to recipient-side viewers. The window and the stale record are still unintended. The same gap applies to the purge job being delayed when the worker is down.
**Fix:** Inside the reserve and contribute transactions, re-check the manager table with a lock, for example `WishlistManager::where(owner, giver)->lockForUpdate()->exists()` then abort 403. Have `sync()` take a `FOR UPDATE` on the same rows.

## Info

### IN-01: Inertia page props are not history-encrypted; logout does not clear history
**File:** `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/Pages/Wishlist/Index.vue`
**Issue:** The repo does not enable Inertia history encryption or `clearHistory` (no `encryptHistory`/`clearHistory` call found). The giver page's props (organizer names, amounts) stay in `history.state`. On a shared family iPad, Back after logout could restore a giver page without a server round trip. Server responses are `no-store`, so this concerns only the in-browser history. Confirm the behaviour against the installed Inertia v3 defaults.
**Fix:** Enable `Inertia::encryptHistory()` globally and `Inertia::clearHistory()` on logout.

### IN-02: Wish deleted mid-reserve yields a 500
**File:** `app/Wishlist/Giving/ReservationService.php:24-33`
**Issue:** If the owner deletes the wish between `findOrFail` and the insert, the FK violation is not a `UniqueConstraintViolationException`, so it surfaces as a 500 with a logged SQL error.
**Fix:** Catch `ForeignKeyConstraintViolationException` and `abort(404)`.

### IN-03: Larger body limit on the upload location applies to unauthenticated clients and every method
**File:** `docker/nginx/snippets/laravel-locations.conf` (`location ~ ^/wishlist/wishes/[0-9]+/image$`)
**Issue:** Anyone can push up to 8 MB per request at that path before the app authenticates them (nginx buffers to disk, then PHP answers 302/419).
**Fix:** Optional: `limit_except POST { deny all; }` and a `limit_req` zone on that location.

### IN-04: `UPLOADS_PATH` and `BACKUP_LOG_FILE` are documented as `.env` keys but never loaded from `.env`
**File:** `scripts/backup.sh:13-14, 24-27`
**Issue:** The header lists `UPLOADS_PATH` under "Env (from .env or the environment)", but `load_env` only reads a fixed key list that omits it. A path set only in `.env` is silently ignored and the default is used.
**Fix:** Add the key to the list or correct the comment.

### IN-05: Magic numbers duplicated instead of configuration
**File:** `app/Http/Controllers/Wishlist/Owner/WishImageController.php:46` (`5 * 1024 * 1024`), `app/Console/Commands/PruneWishlist.php:21` (`60`)
**Issue:** The 5 MiB cap duplicates `config('wishlist.upload_max_kb')` and `fetch_image_max_bytes`. The orphan cutoff is hard-coded.
**Fix:** Read both from `config/wishlist.php`.

### IN-06: Giver co-buy actions do not check `received_at`
**File:** `app/Wishlist/Giving/CoBuyService.php:20-70`
**Issue:** `ReservationService::reserve` returns 409 for received wishes. `open`, `close`, `contribute` and `leave` still operate on a received wish's retained reservation (retained 30 days by `ReservationRetention`).
**Fix:** Add `abort_if($wish->received_at !== null, 409)` in the transitions, or lock the wish row `FOR SHARE`.

### IN-07: A failed re-fetch hides an existing, still-valid fetched image
**File:** `app/Wishlist/Catalog/WishService.php:121-129`, `FetchWishImage::markFailed`
**Issue:** Changing links sets `pending`. On failure it becomes `failed`, which hides the old image although its files and `image_path` remain. There is no restore path except re-fetching or uploading.
**Fix:** Keep `ready` while a re-fetch is in flight and only replace on success, or restore `ready` when `image_path !== null` on failure.

### IN-08: Backup and restore-drill robustness
**File:** `scripts/backup.sh:79-86`, `scripts/restore-drill.sh:79-91`
**Issue:**
- The live-volume `tar -cf -` can exit 1 when a file changes during the read (the worker writes images). The script treats that as a failure and forgets the snapshot.
- The restore drill only lists the tar (`tar -tf`). It never extracts, so it does not prove file integrity.
- `backup-freshness.sh` strips only double quotes from `.env` values, while the other scripts strip both kinds.
**Fix:** Tolerate tar exit 1 (`--warning=no-file-changed` where supported), extract into a scratch dir in the drill and compare counts, and unify `.env` value stripping.

---

_Reviewed: 2026-11-13_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
