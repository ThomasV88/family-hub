---
phase: 16-group-wishlists-issue-25
plan: 01
subsystem: wishlist
tags: [wishlist, groups, access, schema]
requires: []
provides:
  - wishlist_groups and wishlist_group_members tables, wishes.group_id and received_by_id
  - GroupAccess, GroupMode, ViewerContext and WishAccess group rules
affects: [16-02, 16-03, 16-04]
key-files:
  created:
    - database/migrations/2026_12_05_100000_create_wishlist_groups_tables.php
    - app/Models/WishlistGroup.php
    - app/Models/WishlistGroupMember.php
    - database/factories/WishlistGroupFactory.php
    - app/Wishlist/Groups/GroupMode.php
    - app/Wishlist/Groups/GroupAccess.php
    - tests/Feature/Wishlist/GroupAccessTest.php
  modified:
    - app/Models/Wish.php
    - database/factories/WishFactory.php
    - app/Wishlist/ViewerContext.php
    - app/Wishlist/WishAccess.php
    - config/wishlist.php
    - tests/Unit/Arch/WishlistPrivacyArchTest.php
decisions:
  - "ViewerContext::for loads managers and group memberships with a single UNION query so the existing one-query test stays valid unedited"
metrics:
  tasks: 2
  completed: 2026-11-20
---

# Phase 16 Plan 01: Group wishlist data model and access rules Summary

Group lists exist as their own rows with a member pivot; a wish belongs to a person or a group (DB CHECK `wishes_owner_xor_group`), and one tested access layer decides membership, recipient/giver side and edit rights (no admin bypass).

User documentation: no user-visible change: data model and access layer only.

## Commits
- e3214d7: schema, models, factories, GroupMode, config limits, schema tests
- 0ede9b7: GroupAccess, ViewerContext/WishAccess group rules, arch rule, access tests

## Deviations from Plan

**1. [Rule 1 - Bug] ViewerContext query count.** The plan's "one join query for memberships" made `ViewerContext::for` run two queries and broke the existing test `builds the viewer context with one query` (WishModelTest). Fixed by merging managers and group memberships into one UNION ALL query; existing tests unedited.

**2. [Rule 3] Test helper.** Failing-constraint tests run inside a savepoint (`grpAccFails`) because a failed statement aborts the Postgres test transaction. Mode CHECK is tested with a raw insert since the enum cast rejects invalid values first.

The migration `down()` was written per plan but not executed in a test.

## Verification
Pest: GroupAccessTest, WishModelTest, WishCrudTest, ReservationPrivacyTest, ChildListTest, WishlistManagersTest, tests/Unit, tests/Feature/Wishlist, tests/Feature/Access all green (full run 1563 passed before the query-count fix; affected suites re-run green). Pint --test passes. No new routes, so no endpoint-matrix rows needed; no privacy-net declarations were needed.

## Known Stubs
None.

## Self-Check: PASSED
