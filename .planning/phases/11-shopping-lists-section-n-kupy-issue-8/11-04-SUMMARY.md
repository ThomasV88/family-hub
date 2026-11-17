---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 04
subsystem: shopping-catalog-backend
tags: [shopping, images, settings, nginx, private-storage]
requires: ["11-01", "11-02"]
provides:
  - "ShoppingImageStore (storeCatalogImage, removeCatalogImage, storeStoreLogo, removeStoreLogo, deleteDirectory, sweepOrphans) under wish-images/shopping/{catalog|stores}/{id}/"
  - "CatalogService, StoreService, UnitService with duplicate and built-in protection"
  - "Routes settings.shopping.{catalog,stores,units}.{store,update,destroy} and shopping.{catalog,stores}.image.{show,store,destroy}"
affects: [11-06, 11-09, 11-11]
key-files:
  created:
    - app/Shopping/Images/ShoppingImageStore.php
    - app/Shopping/Catalog/CatalogService.php
    - app/Shopping/Catalog/StoreService.php
    - app/Shopping/Catalog/UnitService.php
    - app/Http/Controllers/Shopping/ImageController.php
    - app/Http/Controllers/Settings/ShoppingCatalogController.php
    - app/Http/Controllers/Settings/ShoppingStoresController.php
    - app/Http/Controllers/Settings/ShoppingUnitsController.php
    - app/Http/Requests/Shopping/CatalogItemRequest.php
    - app/Http/Requests/Shopping/StoreRequest.php
    - app/Http/Requests/Shopping/UnitRequest.php
    - app/Http/Requests/Shopping/UploadShoppingImageRequest.php
    - routes/web/settings-shopping.php
    - routes/web/shopping-images.php
    - tests/Support/EndpointMatrix/settings-shopping.php
    - tests/Support/EndpointMatrix/shopping-images.php
    - tests/Feature/Shopping/CatalogSettingsTest.php
    - tests/Feature/Shopping/UnitSettingsTest.php
    - tests/Feature/Shopping/ShoppingImageTest.php
    - tests/Feature/Shopping/PruneShoppingImagesTest.php
  modified:
    - app/Wishlist/Catalog/WishImageStore.php
    - app/Console/Commands/PruneWishlist.php
    - app/Http/Middleware/NoStoreDynamicResponses.php
    - docker/nginx/snippets/laravel-locations.conf
    - scripts/check-upload-limit.sh
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 04: Shopping images, catalog, stores and units backend Summary

Catalog pictures and store logos are re-encoded metadata-free JPEGs stored privately under `wish-images/shopping/`, shown only to members with Shopping view (private, no-cache, ETag, 304, uniform 404); catalog items and stores are managed by Shopping editors and custom units by administrators with Shopping edit, and no delete ever breaks an existing list item.

## Commits
- 7cbab1f image store, routes, sweeper skip, prune wiring, cache allowlist, nginx 8m location, upload smoke (`Refs #8`)
- 17e29cd catalog, store and unit services, requests, controllers, write routes, matrix rows, tests (`Refs #8`)

## Tests
Isolated compose project `fh1104` (own Postgres, scratch folder over storage/logs via an override file), removed with `down -v`. Pint on all touched PHP: pass. `check-nginx-template.sh`: OK. `check-upload-limit.sh fh1104`: OK (shopping route reaches PHP with 302, `/login` still 413). Task 1 set (ShoppingImage, PruneShoppingImages, EndpointMatrix, CacheHeaders, Wishlist, Profile, ImportPreview): 453 passed. Task 2 set (Shopping, EndpointMatrix, Security): 582 passed. Full serial Pest: 3120 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change yet: there is no page until 11-06 (which owns `shopping.settings.*`, `shopping.image.*` and `shopping.unit.*` texts and the manual topic and help tips). No `resources/manual/`, help or lang file touched. Translation keys used as strings that 11-06 must add: `shopping.image.err_size|err_type|err_pixels|err_write`, `shopping.settings.err_duplicate`, `shopping.settings.err_builtin_unit`, `shopping.settings.toast.{catalog_saved,catalog_deleted,store_saved,store_deleted,unit_saved,unit_deleted,image_saved,image_removed}`; name and unit validation reuse `shopping.error.name_invalid` and `shopping.error.unit_unknown` (from 11-03).

## Decisions and notes for later plans
- Image form field name is `image`; errors are keyed `image`. Unit errors: `name_en|name_sk|name_pl` (duplicate), `unit` (built-in). Duplicate name error key is `name`.
- Route params are `{item}`, `{store}`, `{unit}`; the settings GET page is added to `routes/web/settings-shopping.php` by 11-06.
- Image `image_version` is bumped on every change; the UI can use it as a cache-buster query.

## Deviations from Plan
- The plan's behavior list says "noGrants / admin without grant -> 404" on the image GET. The route rule `access:module,shopping,view` (required by the EnsureRouteDeclaresAccess and matrix tests, same as wishlist images) answers 403 for members without Shopping view; unknown id and missing image are the uniform 404. The matrix and tests encode 403 for those roles.
- Unit write matrix rows expect 403 for all seven roles: the matrix admin has no module grant and the other roles are not administrators. The positive path (administrator with Shopping edit) is covered in UnitSettingsTest.
- Added `throttle:60,1,shopping.settings.write` to the catalog and store write routes (not in the plan; low-cost flooding guard consistent with T-11-16).
- Added error keys `shopping.image.err_pixels` and `err_write` beside the planned two (mapping of ImageRejected reasons, like profile photos).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-11-14 to T-11-18 covered by ShoppingImageTest, PruneShoppingImagesTest, CatalogSettingsTest, UnitSettingsTest and the matrix).

## Self-Check: PASSED
