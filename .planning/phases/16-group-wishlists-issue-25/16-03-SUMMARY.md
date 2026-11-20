---
phase: 16-group-wishlists-issue-25
plan: 03
subsystem: wishlist
tags: [wishlist, groups, owner-side, props]
requires: [16-01]
provides:
  - group wish create/update/delete/received/images through wish-based authorization
  - ListPage::groupProps / renderGroup (reservation-blind), WishPresenter received_by for group wishes
affects: [16-04]
key-files:
  created:
    - tests/Feature/Wishlist/GroupWishCrudTest.php
  modified:
    - app/Http/Requests/Wishlist/WishRequest.php
    - app/Http/Requests/Wishlist/UploadWishImageRequest.php
    - app/Http/Controllers/Wishlist/Owner/WishController.php
    - app/Http/Controllers/Wishlist/Owner/ReceivedController.php
    - app/Http/Controllers/Wishlist/Owner/WishImageController.php
    - app/Wishlist/Catalog/WishService.php
    - app/Wishlist/Catalog/ListPage.php
    - app/Wishlist/Catalog/WishPresenter.php
decisions:
  - "Group wish creation uses group_id on POST /wishlist/wishes (prohibits owner_id); owner routes authorize per wish via WishAccess::canEditWish"
metrics:
  tasks: 2
  completed: 2026-11-20
---

# Phase 16 Plan 03: Group wish owner side Summary

Members of a group manage its wishes through the existing owner endpoints (create with group_id, edit, delete, received with the marking member's id, images), enforced per wish server-side, and `ListPage::groupProps` returns the reservation-blind group list in the contract shape.

User documentation: no user-visible change yet (server only; documented in 16-08). No `resources/manual/` files touched.

## Commits
- c0406b9: wish-based authorization, createForGroup, received_by_id, image visibility for group wishes
- 867cf95: ListPage::groupProps/renderGroup, presenter received_by

## Deviations from Plan

**1. [Test calibration]** The "owner and group together" case asserts session validation errors (302 with `owner_id`/`group_id` errors) instead of status 422, because the non-JSON test request redirects back. Behaviour is the same validation refusal.

No new routes, so no endpoint-matrix rows were needed; no privacy-net declarations were needed (arch test passes).

## Verification
Pest: GroupWishCrudTest (11 tests), WishCrudTest, OwnerActionsTest, WishImageTest, ChildListTest, ListPageTest, ReservationPrivacyTest, WishlistPrivacyArchTest green; tests/Feature/Wishlist and tests/Feature/Access together 539 passed. A mid-run full pass showed failures only in 16-02's in-progress GroupManagementTest, which passed on re-run. Pint --test passes on app/Http, app/Wishlist and the new test file (a repo-wide run flagged only my test file's import order, fixed before commit).

## Known Stubs
None.

## Self-Check: PASSED
