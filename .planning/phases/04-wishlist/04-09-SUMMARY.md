---
phase: 04-wishlist
plan: 09
subsystem: wishlist-occasions
tags: [laravel, inertia, vue, occasions, name-days, privacy]
requires: [04-01, 04-03, 04-05, 04-06]
provides:
  - users.birth_date (hidden, never in auth.user) and wish_occasions table
  - OccasionCalendar::upcoming / occurrenceOn and readonly OccasionOccurrence (contract for 04-10, 04-11, 04-12)
  - Wishlist/Occasions page with OccasionPanel (dates and custom modes)
affects: [04-10, 04-11, 04-12]
tech-stack:
  added: []
  patterns: [FormRequest::authorize carries record-level access so unauthorized callers get 403 before validation, name day stored as a snapshot row]
key-files:
  created:
    - database/migrations/2026_11_15_120000_create_wish_occasions_table.php
    - app/Models/WishOccasion.php
    - database/factories/WishOccasionFactory.php
    - app/Wishlist/Occasions/OccasionOccurrence.php
    - app/Wishlist/Occasions/OccasionCalendar.php
    - app/Wishlist/Occasions/OccasionsPage.php
    - app/Wishlist/Occasions/OccasionAccess.php
    - app/Http/Controllers/Wishlist/Owner/OccasionController.php
    - app/Http/Controllers/Wishlist/Owner/MemberDatesController.php
    - app/Http/Controllers/Wishlist/Owner/NameDayController.php
    - app/Http/Requests/Wishlist/OccasionRequest.php
    - app/Http/Requests/Wishlist/MemberDatesRequest.php
    - routes/web/wishlist-occasions.php
    - tests/Support/EndpointMatrix/wishlist-occasions.php
    - resources/js/Pages/Wishlist/Occasions.vue
    - resources/js/Wishlist/OccasionList.vue
    - resources/js/Wishlist/OccasionRow.vue
    - resources/js/Wishlist/OccasionPanel.vue
    - resources/js/Wishlist/MemberDatesTable.vue
    - resources/js/Wishlist/occasionIcons.js
  modified:
    - app/Models/User.php
decisions:
  - "Admin edits another member's dates only with the wishlist edit grant (admin alone has no module grants, consistent with the matrix)"
  - "Panel state mirrors ?panel= in the URL through a partial reload (only: today); server props stay independent of it"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 4 Plan 09: Occasions Summary

Birth dates, SK/PL name days (stored as snapshots), Christmas and custom occasions resolve to dates in the family time zone through `OccasionCalendar` (built on `AnnualDate::next`), with an Occasions screen and panel to maintain them.

## What was built

- Schema: `users.birth_date` (hidden, cast date, absent from `auth.user`) and `wish_occasions` with CHECKs (kind, month, day, calendar, nameday and custom requirements) and a partial unique index of one name day per member.
- `OccasionCalendar`: `upcoming(today, horizon)` sorted by date then type; `occurrenceOn(subjectType, subjectId, date)` for the reminder send-time re-check (Christmas subjectId is the year). Feb 29 is observed on Feb 28 in non-leap years; days-away is a whole-day count across DST.
- Endpoints (six routes, throttled, matrix rows for all seven roles): page, custom create/update/delete, member dates PUT, name-day search JSON.
- Access: dates by the member, a designated manager or an admin, all with wishlist edit; custom occasions by creator or admin. Unknown ids and non-members answer the same 403 (checked in `FormRequest::authorize`, so before any validation answer). Name-day rows give 404 on the custom routes.
- UI: upcoming list with icons, days-away tags and open-list links; dates table (day and month only for others); custom list; panel with dates mode (debounced search, 8 radios, PL attribution) and custom mode; "Close" button and discard dialog, never "Cancel".

## Deviations from Plan

**1. [Rule 3 - Blocker] OccasionCalendar does not use WishlistMembers**
- `WishlistMembers` requires a viewer user, but `upcoming(today, horizon)` has none. The calendar queries `module_permissions` directly for users with wishlist view access (same rule). `WishlistMembers` and `WishAccess` are unmodified.

**2. [Rule 1 - Bug] occurrenceOn date parsing**
- Carbon strict mode throws on malformed input instead of returning null; replaced by a regex and `checkdate` pre-check so bad dates return null.

**3. Test data**
- The plan's example name day (sk, "Ľubomír", 11, 9) is used only for calendar snapshot rows (factory, unvalidated). Endpoint tests use the real calendar entry (Ľubomír on 08-13) because `NameDayCalendar::has` validates against the vendored data.
- Occasions.test.js asserts the Inertia component file exists (`Wishlist/Occasions`).

## Notes for the orchestrator (not caused by this plan)

- A full `pest` run in the shared tree dies with the 128M memory limit in `tests/Unit/Wishlist/ImageProcessorTest.php` (04-07's 12 MP budget test, `tests/Support/ImageFixtures.php` line 12). With `php -d memory_limit=1G ./vendor/bin/pest` all 900 tests pass (10738 assertions). Vitest: 55 files, 802 tests pass. `npm run build` prints SW-PRECACHE OK.
- Running Pint on whole directories reformatted two unrelated files (`ThrottleAuthRoutes.php`, `routes/console.php`); both were reverted with `git checkout -- <file>` and are untouched. Pint on this plan's files only is clean.
- `EndpointMatrixTest` failed transiently while 04-07 had image routes without matrix rows; it passed in the final full run.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-04-45..T-04-50 mitigations implemented and tested: birth year privacy test, IDOR 403 tests, creator-only edits, date and name validation, search limits and throttle, text-only rendering).

## Commits

- 6e7f6b4 feat(04-09): wish_occasions schema, birth date privacy and OccasionCalendar
- b5190a3 feat(04-09): occasions page props, member dates, custom occasions, name day search
- e9f89de feat(04-09): occasions screen and occasion panel

## Self-Check: PASSED

All created files exist and all three commits are in git log.
