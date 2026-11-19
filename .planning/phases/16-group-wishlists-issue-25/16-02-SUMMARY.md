---
phase: 16-group-wishlists-issue-25
plan: 02
subsystem: wishlist
tags: [wishlist, groups, management, routes]
requires: [16-01]
provides:
  - GroupService (create, update, syncMembers, leave, delete)
  - GroupReservationPurger (purgeGroup, purgeMember, purgeAll)
  - routes wishlist.groups.store|update|destroy|members.update|leave
affects: [16-03, 16-06, 16-07]
key-files:
  created:
    - app/Wishlist/Groups/GroupService.php
    - app/Wishlist/Giving/GroupReservationPurger.php
    - app/Http/Requests/Wishlist/GroupRequest.php
    - app/Http/Requests/Wishlist/GroupMembersRequest.php
    - app/Http/Controllers/Wishlist/Groups/GroupController.php
    - app/Http/Controllers/Wishlist/Groups/GroupMembersController.php
    - routes/web/wishlist-groups.php
    - tests/Support/EndpointMatrix/wishlist-groups.php
    - tests/Feature/Wishlist/GroupManagementTest.php
  modified:
    - app/Console/Commands/PruneWishlist.php
decisions:
  - "G-07 to G-09 and G-13 implemented as planned (confirmed mode switch purges the whole list; added members purged on gifts lists, removed or leaving members on shared lists; delete removes wishes and images; no step-up, no audit)"
metrics:
  tasks: 2
  completed: 2026-11-20
---

# Phase 16 Plan 02: Group management Summary

Members create and manage group lists through five throttled server routes; mode and membership changes cancel the affected reservations in the same transaction, and the group code stays reservation-blind (the controllers pair service calls with the Giving-side purger).

User documentation: no user-visible change yet: server routes without UI; documented in 16-08. No `resources/manual/` files touched.

## Commits
- Task 1: service, form requests, purger
- Task 2: controllers, routes, prune hook, matrix rows, feature tests

## Deviations from Plan

**1. [Test adaptation] Acceptance grep for lockForUpdate.** The plan asked for at least 4 textual occurrences in GroupService; there are 2 (a shared `lock()` helper used by every method, plus the actor row lock in `create`). Behavior is as specified: every method locks the group row first.

**2. [Test adaptation] 403 body comparison.** Test env renders debug traces, so non-member vs unknown-id bodies are compared by status and `message`, not raw content.

**3. Fixtures.** `WishReservationFactory` adds an organizer contribution automatically and allows one reservation per wish; tests were written accordingly (separate wishes per reservation).

Error keys introduced (strings come in 16-06): `wishlist.group.error.{confirm_mode, self_required, member_not_eligible, member_limit, too_many_members, too_many_groups, name_required, name_too_long, mode_invalid}`; toasts `wishlist.toast.{group_created, group_saved, group_left, group_deleted}`.

Create redirects to `/wishlist?group={id}`; the index page does not read `group` until 16-03/16-06.

## Verification
GroupManagementTest (24), arch test, tests/Feature/Wishlist, Access (EndpointMatrixTest), Security (SensitiveGateMatrixTest), tests/Unit: 1820 passed, 1 failed: `CodexRunnerTest` timing assertion (4.09 s vs 4.0 s, flaky under parallel load, unrelated). Pint clean after fixing import order in the new test.

## Known Stubs
None.

## Self-Check: PASSED
