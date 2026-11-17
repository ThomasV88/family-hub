---
phase: 10-profile-settings-and-child-accounts-issues-7-6
plan: 03
subsystem: profile-photos-backend
tags: [profile-photo, private-storage, image-pipeline, audit, nginx]
requires: ["10-01"]
provides:
  - "ProfilePhotoStore (put/remove/sweepOrphans) under wish-images/profile-photos/{userId}/"
  - "ProfileAccess::canEdit and ProfilePhotoAccess::canView"
  - "Routes profile.photo.store, profile.photo.destroy, profile.photo.show"
  - "Shared Inertia prop avatars {userId: photo_version}"
  - "AuditEvent::ProfileChangedByOther (field photo|name)"
affects: [10-04, 10-05, 10-07, 10-08]
key-files:
  created:
    - config/profile.php
    - app/Profile/ProfilePhotoStore.php
    - app/Profile/ProfilePhotoWriteFailed.php
    - app/Profile/ProfileAccess.php
    - app/Profile/ProfilePhotoAccess.php
    - app/Profile/AvatarVersions.php
    - app/Http/Controllers/Profile/ProfilePhotoController.php
    - app/Http/Requests/Profile/UploadProfilePhotoRequest.php
    - routes/web/profile.php
    - tests/Support/EndpointMatrix/profile.php
    - tests/Feature/Profile/ProfilePhotoStoreTest.php
    - tests/Feature/Profile/ProfileAccessTest.php
    - tests/Feature/Profile/ProfilePhotoTest.php
  modified:
    - app/Wishlist/Catalog/WishImageStore.php
    - app/Console/Commands/PruneWishlist.php
    - app/Http/Middleware/NoStoreDynamicResponses.php
    - app/Http/Middleware/HandleInertiaRequests.php
    - app/Audit/AuditEvent.php
    - docker/nginx/snippets/laravel-locations.conf
    - scripts/check-upload-limit.sh
    - resources/js/Components/Settings/AuditTable.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - tests/Feature/CacheHeadersTest.php
    - tests/js/settings/AuditPage.test.js
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 10 Plan 03: Profile photo backend Summary

Profile photos are re-encoded through the existing ImageProcessor into 512 px and 192 px metadata-free JPEGs, stored privately on the persisted `wish-images/` volume prefix, edited only by self, admin or a child's manager, and served only through an authenticated route with a uniform 404, `private, no-cache`, ETag and 304.

## Commits
- 89f8121 store, access rules, sweeper safety (`Refs #7`, `Refs #6`)
- 456216d controller, request, routes, audit event, cache allowlist, nginx location, upload-limit smoke, endpoint matrix (`Refs #7`)
- Task 3 commit (shared avatars prop, audit label, lang): see git log, subject "feat(10-03): shared avatars prop, audit label and photo error texts" (`Refs #7`)

## Tests
Isolated compose project `fh1003` (own Postgres, scratch folder over storage/logs), removed with `down -v` afterwards. Pint on all touched PHP files: pass. `check-nginx-template.sh`: OK. `check-upload-limit.sh fh1003`: OK (profile route reaches PHP, `/login` still 413). Targeted Pest: ProfilePhotoStore, ProfileAccess, ProfilePhoto, CacheHeaders, EndpointMatrix, Audit, Wishlist prune: green. Full Vitest: 148 files, 5073 tests passed. Full serial Pest suite: 2869 passed, 1 skipped.

## User documentation
No user-visible change yet: there is no upload UI until 10-04, which writes the manual topic and help tips for photos (including who may see them). The audit label added here only appears once someone edits a child (10-05). No `resources/manual/` file touched.

## Deviations from Plan
- Added `profile.toast.photo_saved` and `profile.toast.photo_removed` (en, sk, pl) next to the owned `profile.photo.err_*` keys, because the controller flashes those toast keys and they would otherwise render as raw keys. Additive; 10-04 can reuse or reword them.
- Added `audit.detail.profile_changed_photo|name|other` (en/sk/pl) for the audit label; the "audit.*" namespace was explicitly this plan's.
- `ImageRejected` reasons map: too_large to err_size, too_many_pixels to err_pixels, busy to err_write, everything else to err_type; err_decode is used for a missing or unreadable upload part.
- sk and pl texts are drafts for native review (err_*, toast, audit detail).
- Avatars prop is a lazy closure returning `(object) map` (an empty `{}` for guests), same pattern as `auth.access`.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (new routes, storage prefix and nginx location are all covered by T-10-13 to T-10-19).

## Self-Check: PASSED
Commits 89f8121 and 456216d and all listed files verified present.
