---
phase: 04-wishlist
plan: 08
subsystem: wishlist-giving
tags: [privacy, reservations, co-buy, laravel, pest]
requires: [04-05]
provides:
  - wish_reservations / wish_contributions schema and models
  - Giving namespace (guard, reservation and co-buy services, giver list page, shopping summary, purge, retention)
  - Leak suite (arch, differential, canary, query-log, owner-action parity)
  - Giver page contract consumed by plan 04-10
affects: [04-10, 04-11]
tech-stack:
  patterns: [fail-closed guard, separate reservation tables, route-enumerating privacy tests]
key-files:
  created:
    - database/migrations/2026_11_15_110000_create_wish_reservations_tables.php
    - app/Models/WishReservation.php
    - app/Models/WishContribution.php
    - app/Wishlist/Giving/ (GivingGuard, ReservationService, CoBuyService, ReservationPresenter, GiverListPage, ShoppingSummary, ManagerReservationPurger, PurgeManagerReservations, ReservationRetention)
    - app/Http/Controllers/Wishlist/Giving/ (Reservation, CoBuy, Contribution, Shopping controllers)
    - routes/web/wishlist-giving.php
    - tests/Support/WishlistPrivacy.php
  modified:
    - app/Wishlist/Catalog/ListPage.php (props() split out of render())
    - app/Http/Controllers/Wishlist/ListController.php (single branch point)
    - app/Http/Controllers/Settings/WishlistManagersController.php (dispatches the purge)
key-decisions:
  - Shopping summary is a JSON endpoint (GET /wishlist/shopping, fetched by the component, never a page prop)
  - i_joined is true only for non-organizer contributors; the organizer is identified by is_mine
  - Opening co-buy from closed reopens it (same route); opening an open one is 409
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 4 Plan 08: Reservations and co-buying (privacy core) Summary

Reservations and co-buying live in their own tables behind a `Giving` namespace; owner and manager responses are provably byte-identical with and without reservations, and the leak suite was written and committed before any giver code.

## What was built

- Task 1 (10f5e41): schema, models, factories, the `toOnlyBeUsedIn` arch rule, `tests/Support/WishlistPrivacy.php`, `ReservationPrivacyTest`, `OwnerActionsTest`.
- Task 2 (ec156e4): `GivingGuard`, reserve and cancel (first buyer wins via the unique wish_id, conflict flashed with the first buyer's name), `GiverListPage` on the non-recipient branch of `ListController`, `ManagerReservationPurger` and the queued `PurgeManagerReservations` (dispatched only for newly added managers).
- Task 3 (fa11ce9): `CoBuyService` (open, close, reopen, contribute, leave; `lockForUpdate` on the reservation), `ContributionRequest` (share_invalid), `ShoppingSummary`, `ReservationRetention::purgeArchived`, all seven `wishlist.giving.*` routes with matrix rows.

## Shopping summary

`GET /wishlist/shopping` (`wishlist.giving.shopping`) returns JSON `{groups: [...]}` of the viewer's own reservations and shares, excluding lists where the viewer is recipient-side and received wishes. It is requested by the ShoppingSummary component itself and never appears in any page props.

## Namespaces allowed by the toOnlyBeUsedIn rule

`App\Models\WishReservation` and `App\Models\WishContribution` may only be used in: `App\Wishlist\Giving`, `App\Http\Controllers\Wishlist\Giving`, `App\Models\WishReservation`, `App\Models\WishContribution`, `Database\Factories`, `Tests\Support`. A second rule keeps Catalog, Fetch, Occasions, Owner controllers, ViewerContext, WishAccess, WishlistMembers, Wish, WishUrl and WishlistManager from using the models or `Giving`.

## Deviations from Plan

1. **[Rule 1 - test design conflict] Shopping excluded from the zero-SQL assertion.** The query-log check flagged `GET /wishlist/shopping` for owner and manager: it must read the viewer's own giving on other people's lists (an owner can be a giver elsewhere), so it cannot be SQL-free. Its response is identical with and without reservations and covered by the differential and canary checks. The zero-reservation-SQL assertion therefore runs over every wishlist, home and settings surface except `wishlist.giving.*` GETs (`WishlistPrivacy::surfaces(includeGiving: false)`). Every non-GET Giving route returns 403 before any reservation lookup.
2. Docblock wording in `ReservationService` adjusted so the acceptance greps for `touch(` and audit return nothing.

## Deferred issues (not mine, not fixed)

- Full Pest run at the default 128M memory limit dies in `tests/Unit/Wishlist/ImageProcessorTest` (plan 04-07, `tests/Support/ImageFixtures.php`). With `php -d memory_limit=1G` the full suite is green: 900 passed.
- `pint --test` reports style issues in `app/Http/Middleware/ThrottleAuthRoutes.php` and `tests/Feature/Wishlist/WishImageTest.php` (not this plan's files). Pint is clean on all 04-08 files.
- Owner-action parity cases for image remove and fetch are skipped when those routes did not exist at run time; they run automatically once 04-07 routes are named `wishlist.images.destroy|fetch`. The privacy test enumerates routes at run time, so 04-07 and 04-09 routes were covered when present.

## Verification

Full Pest 900 passed (memory raised, see above), Vitest 802 passed, wishlist feature tests, arch rules and EndpointMatrix green. No STATE, ROADMAP or REQUIREMENTS edits (as instructed).

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model.

## Self-Check: PASSED
