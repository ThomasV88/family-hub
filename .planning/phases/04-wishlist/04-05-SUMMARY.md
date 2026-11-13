---
phase: 04-wishlist
plan: 05
subsystem: wishlist-backend
tags: [laravel, privacy, wishlist, arch-test, permissions]
requires: ["04-01"]
provides:
  - "wishes, wish_urls, wishlist_managers schema (no reservation column)"
  - "App\\Wishlist\\{ViewerContext,WishAccess,WishlistMembers,Money,WishPriority}"
  - "App\\Wishlist\\Catalog\\{WishService,WishPresenter,ListPage} (reservation-blind)"
  - "routes wishlist.index and wishlist.wishes.* ; settings.members.wishlist_managers.update"
  - "Pest arch rule: owner side never uses WishReservation, WishContribution or App\\Wishlist\\Giving"
affects: [04-06, 04-07, 04-08, 04-09]
key-files:
  created:
    - config/wishlist.php
    - database/migrations/2026_11_15_100000_create_wishlist_tables.php
    - app/Models/Wish.php
    - app/Models/WishUrl.php
    - app/Models/WishlistManager.php
    - app/Wishlist/ViewerContext.php
    - app/Wishlist/WishAccess.php
    - app/Wishlist/Catalog/ListPage.php
    - app/Http/Controllers/Wishlist/ListController.php
    - app/Http/Controllers/Settings/WishlistManagersController.php
    - tests/Unit/Arch/WishlistPrivacyArchTest.php
  modified:
    - app/Http/Controllers/Settings/MembersController.php
decisions:
  - "Toast key for manager save is settings.members.wishlist_managers.saved (copy lives under settings.members per 04-01)"
  - "Title over max length maps to wishlist.error.save_failed (no dedicated title_long key exists)"
  - "WishlistManagersController::sync returns newly added manager ids for plan 04-08 purge job (private, not yet used)"
metrics:
  completed: 2026-11-13
  tasks: 3
---

# Phase 4 Plan 05: Wishlist backend Summary

Reservation-blind wish CRUD with up to 5 links, per-member lists visible to all with wishlist access, admin-designated list managers, and a Pest arch test that forbids owner-side code from touching reservation classes.

## Commits
- a854590: schema, models, factories, Money, ViewerContext, WishAccess, arch test
- 1d9ee51: Catalog service/presenter/ListPage, controllers, routes, matrix
- 8c9064b: wishlist managers controller, Members props, matrix

## Structure
- `wishes` has no reservation column, counter or flag; `Wish` has no `$with`, `$appends` or reservation relation (tested).
- `ViewerContext` (one query) decides recipient side: owner or designated manager only; edit grant and admin never qualify. `WishAccess::canEditList` has no admin bypass.
- `WishPresenter` is an explicit allowlist (no model dump). Non-recipient views never receive `received`.
- `ListController` is the single branch point for plan 04-08; `?member`/`?wish` probing, `wish=new` without edit and unknown ids return the same 403; other query params (e.g. `available`) are ignored.

## Deviations from Plan
- [Rule 3] Manager-saved toast key uses `settings.members.wishlist_managers.saved` instead of `members.wishlist_managers.saved` (per 04-01 summary).
- Task 1 committed with WishCrudTest holding only the first (red-until-Task-2) case, as the plan allows.
- Pint touched unrelated files (ThrottleAuthRoutes.php, routes/console.php) when run on `routes`; reverted, not part of any commit.

## Verification
- Full Pest: 754 passed. Full Vitest: 773 passed on re-run (first run had 1 failure in a 04-06 file while it was being written). Pint clean on this plan's files. Matrix covers 7 roles for all 7 new routes.

## Known Stubs
- `image.thumb_url` and `full_url` are always null until plan 04-07 (contract).

## Self-Check: PASSED
