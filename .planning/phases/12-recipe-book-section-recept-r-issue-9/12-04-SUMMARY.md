---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 04
subsystem: recipes-photos-backend
tags: [recipes, images, nginx, private-storage]
requires: ["12-03"]
provides:
  - "RecipeImageStore add, makeMain, move, remove, deleteDirectory, sweepOrphans under wish-images/recipes/{id}/"
  - "Routes recipes.photos.{store,main,move,destroy,show}"
  - "Sweeper skip, NoStore allowlist entry, nginx 8m location, upload smoke line"
affects: [12-05, 12-08, 12-16]
key-files:
  created:
    - app/Http/Controllers/Recipes/PhotoController.php
    - app/Http/Requests/Recipes/UploadRecipePhotoRequest.php
    - app/Http/Requests/Recipes/MoveRecipePhotoRequest.php
    - routes/web/recipes-photos.php
    - tests/Support/EndpointMatrix/recipes-photos.php
    - tests/Feature/Recipes/RecipePhotoTest.php
    - tests/Feature/Recipes/RecipePhotoPrivacyTest.php
    - tests/Feature/Recipes/PruneRecipeImagesTest.php
  modified:
    - app/Recipes/Images/RecipeImageStore.php
    - app/Wishlist/Catalog/WishImageStore.php
    - app/Console/Commands/PruneWishlist.php
    - app/Http/Middleware/NoStoreDynamicResponses.php
    - docker/nginx/snippets/laravel-locations.conf
    - scripts/check-upload-limit.sh
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 04: Recipe photos backend Summary

Recipe photos are re-encoded, metadata-free JPEGs stored privately under `wish-images/recipes/{id}/`, managed through JSON endpoints (upload, main, move, remove, capped at 10 under a recipe row lock) and served only to members who may see the recipe (private, no-cache, ETag, 304, uniform 404), with the nightly sweepers kept apart and one 8m nginx upload block.

## Commits
- a3da727 image store, wishlist sweeper FOREIGN_PREFIXES, PruneWishlist wiring, NoStore allowlist, nginx 8m location, upload smoke line (`Refs #9`)
- 27b751c photo controller, requests, routes, matrix rows, upload and privacy tests (`Refs #9`)

## Tests
Isolated compose project `fh1204` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. Pint on touched PHP: pass. `check-nginx-template.sh`: OK. `check-upload-limit.sh fh1204`: OK (`recipes=302`, `/login` still 413). Task sets green (PruneRecipeImages, PruneShoppingImages, Wishlist; RecipePhoto, RecipePhotoPrivacy, EndpointMatrix). Full serial Pest: 3380 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change yet: there is no photo field until 12-08. No `resources/manual/`, help or lang file touched. Keys used as strings that 12-08 must add texts for: `recipes.image.err_size`, `err_type`, `err_pixels`, `err_write`, `err_count`.

## Decisions and notes for later plans
- Answer order on writes: module middleware 403, then recipe 404 (hidden or unknown), then 403 (visible non-author), then validation 422 (access is checked in the FormRequest `authorize`, so a hidden recipe never reaches validation), then photo 404 (photo of another recipe).
- The ETag is `sha1(path|version)`; each upload creates new files, so the path already changes. `version` is always 1 (rows are never re-encoded in place).
- `move` renumbers all rows 1..n on a swap, so tied positions cannot make it a no-op. `makeMain` clears then sets inside one transaction (partial unique index).
- Matrix: the parent role authors a shared recipe, so write routes expect 200/302 for the parent and 403 for child and grandparent (visible, not author).
- The privacy differential compares bodies with `app.debug` off (debug traces carry request-specific frames).

## Deviations from Plan
Minor, no rule triggered: added `PruneRecipeImagesTest` check of the nginx snippet (single 8m recipe location, single 3m block); the prune command message now also reports the recipe photo count.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-12 to T-12-16 covered by RecipePhotoTest, RecipePhotoPrivacyTest, PruneRecipeImagesTest, check-upload-limit.sh and the matrix).

## Self-Check: PASSED
