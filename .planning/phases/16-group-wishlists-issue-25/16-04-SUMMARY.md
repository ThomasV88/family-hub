---
phase: 16-group-wishlists-issue-25
plan: 04
subsystem: wishlist
tags: [wishlist, groups, giving, reservations, shopping]
requires: [16-01, 16-03]
provides:
  - GivingGuard::assertGiverOfWish, assertStillGiverOfWish (shared lock on group row), group branch of authorize
  - GiverListPage::renderGroup, ListController ?group= branch, groups and group_candidates props
  - ShoppingSummary group sections
affects: [16-05, 16-06, 16-07]
key-files:
  modified:
    - app/Wishlist/Giving/GivingGuard.php
    - app/Wishlist/Giving/ReservationService.php
    - app/Wishlist/Giving/CoBuyService.php
    - app/Wishlist/Giving/GiverListPage.php
    - app/Wishlist/Giving/ShoppingSummary.php
    - app/Http/Controllers/Wishlist/ListController.php
  created:
    - tests/Feature/Wishlist/GroupGivingTest.php
decisions:
  - "G-10: no group occasions; reminders unchanged, occasion strip attached to group renders like personal ones"
  - "G-11: shopping sections for groups keyed g{id}, shape owner {id:null,name}, group {id,name}, items; personal sections keep ['owner','items']; personal first, then groups by name"
metrics:
  tasks: 2
  completed: 2026-11-20
---

# Phase 16 Plan 04: Group giving and page branch Summary

Group lists now open on `/wishlist?group={id}` with one branch point (recipient-side gets the reservation-blind ListPage, everyone else the GiverListPage), reservations and co-buy follow the group mode with a race-safe re-check under a shared lock on the group row, and "Your shopping" gets per-group sections.

User documentation: no user-visible change yet (server only; documented in 16-08). No `resources/manual/` files touched.

## Commits
- Task 1: GivingGuard, ReservationService, CoBuyService (per-mode rules, lock)
- Task 2: ListController branch, GiverListPage::renderGroup, ShoppingSummary, GroupGivingTest (14 tests)

## Deviations from Plan

**1. [Rule 1 - Bug] NULL-safe shopping filter.** Group wishes have `owner_id` NULL, so `owner_id NOT IN (...)` would have silently dropped every group row; the personal and group predicates are now separate OR branches.

**2. [Test adaptation] Race-guard test.** `ReservationService::reserve` builds a fresh ViewerContext, so a membership added before the call is refused by the earlier LogicException. The in-transaction guard is therefore tested by calling `assertStillGiverOfWish` directly (403) and the service path asserts the LogicException.

**3. Pint reordered imports** in the touched files (applied before commit).

No new routes (no matrix rows) and no privacy-net or arch declarations were needed. Error/toast keys: none new. The create redirect `/wishlist?group={id}` from 16-02 now resolves to a real page; the frontend Vue does not use the new props yet (16-06/16-07).

## Verification
Pest `tests/Feature/Wishlist tests/Feature/Access tests/Unit`: 1618 passed, 1 skipped, 0 failed (14718 assertions); includes EndpointMatrixTest and WishlistPrivacyArchTest; CodexRunnerTest flake did not occur. GroupGivingTest 14 tests green. Pint --test: PASS (1293 files). Compose project fh1604 torn down with `down -v`.

## Known Stubs
None.

## Self-Check: PASSED
