---
phase: 04-wishlist
plan: 12
subsystem: wishlist
tags: [occasions, dashboard, inertia, vue, privacy]
requires: [04-06, 04-08, 04-09]
provides: [UpcomingOccasions presenter, occasions prop on /wishlist and Overview, OccasionStrip, UpcomingOccasionsCard]
affects: [Pages/Wishlist/Index.vue, Pages/Home.vue]
key-files:
  created:
    - app/Wishlist/Occasions/UpcomingOccasions.php
    - resources/js/Wishlist/OccasionStrip.vue
    - resources/js/Dashboard/UpcomingOccasionsCard.vue
    - tests/Feature/Wishlist/OccasionStripTest.php
    - tests/Feature/Wishlist/HomeOccasionsTest.php
    - tests/js/wishlist/OccasionStrip.test.js
    - tests/js/UpcomingOccasionsCard.test.js
  modified:
    - app/Http/Controllers/Wishlist/ListController.php
    - app/Http/Controllers/HomeController.php
    - resources/js/Pages/Wishlist/Index.vue
    - resources/js/Pages/Home.vue
    - tests/js/Home.test.js
decisions:
  - "Presenter exposes strip() (config strip_days, limit 3, family timezone) on top of next(); controllers call strip() in a closure prop"
  - "Overview shows UpcomingOccasionsCard in place of the wishlist ModuleCard when occasions is non-null"
metrics:
  completed: 2026-11-13
---

# Phase 4 Plan 12: Occasion strip and Overview card Summary

Reservation-blind upcoming occasions (next 3 within 60 days, next-occurrence dates only, no birth years) on the wishlist page strip and an Overview card, via a new `UpcomingOccasions` presenter built only on `OccasionCalendar`.

## Commits
- 6e9903d feat: presenter and occasions prop (ListController both branches, lazy HomeController prop gated by wishlist view)
- 51eace4 feat: OccasionStrip, UpcomingOccasionsCard, Index.vue and Home.vue wiring, tests
- style commit: Pint fix for OccasionStripTest

## Verification
- Pest full: 916 passed. Vitest full: 61 files, 884 passed. Build prints SW-PRECACHE OK. ReservationPrivacyTest green.
- Strip identical for owner, manager and giver; unchanged after seeding a reservation; Guest preset gets null; partial reload skips the prop.
- axe clean for strip and card in en/sk/pl and dark. No new i18n keys were needed (occasions.card.* and occasions.all already existed).

## Deviations from Plan
None for this plan's files.

## Deferred Issues
- Pint `--test` over `app/Http/Controllers` flags `NotificationSettingsController.php` (ordered_imports). That file belongs to plan 04-11 and was not touched.
- During my per-task Vitest run, `GiftWishPanel.test.js` and `WishImage.test.js` failed transiently while 04-10 was mid-edit; the final full run was green.

## Known Stubs
None.

## Self-Check: PASSED
