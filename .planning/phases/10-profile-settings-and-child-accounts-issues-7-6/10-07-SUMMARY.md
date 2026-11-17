---
phase: 10-profile-settings-and-child-accounts-issues-7-6
plan: 07
subsystem: avatars
tags: [avatars, profile-photo, health, wishlist, todo, service-worker]
requires: ["10-03", "10-04"]
provides:
  - "PersonPage person.photoUserId (linked user id or null, only for persons the viewer may open)"
  - "Photos at PersonChips, GiftListView, ContributorList, TaskColumn, VisibilityField, HealthAccessCard, PersonHeader"
  - "Source-scan guard: every <UiAvatar> binds user-id or src"
affects: [10-08]
key-files:
  created:
    - tests/Feature/Health/PersonPagePhotoTest.php
    - tests/js/ui/AvatarCallSites.test.js
  modified:
    - app/Medical/PersonPage.php
    - resources/js/Health/PersonHeader.vue
    - resources/js/Components/Settings/HealthAccessCard.vue
    - resources/js/Wishlist/PersonChips.vue
    - resources/js/Wishlist/GiftListView.vue
    - resources/js/Wishlist/ContributorList.vue
    - resources/js/Todo/TaskColumn.vue
    - resources/js/Todo/VisibilityField.vue
    - tests/js/sw-precache.test.js
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 10 Plan 07: Avatars everywhere Summary

Every remaining avatar call site now passes the member id, so a member's photo replaces the initial across the app. The health person payload gains `photoUserId`, set only for persons the viewer may already open.

## Commits
- 73afb5e health person payload carries the linked photo id (`Refs #7`)
- Closing commit "feat(10-07): show member photos at every remaining avatar call site" (`Fixes #7`)

## Tests
Isolated compose project `fh1007` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v` afterwards (no containers left). Pint on touched PHP: pass. Targeted Pest (PersonPagePhotoTest, MedicalPrivacyArchTest, tests/Unit/Health): 61 passed. Targeted Vitest: 54 files, 2484 tests passed. Full Vitest: 152 files, 5161 tests passed (manual, help coverage and a11y included). Full serial Pest suite: 2917 passed, 1 skipped.

## Decisions
- PersonSwitcher keeps its initials chip and shows no photo (research Open Question 3, orchestrator decision); the source-scan test asserts PersonSwitcher does not use UiAvatar.
- The source-scan allowlist contains `StyleGuide/Sections/40-Display.vue` (permanent) and `Components/Settings/MemberRow.vue` (temporary; MemberRow is already wired by 10-05, so 10-08 can remove that entry).

## User documentation
No user-visible change beyond what 10-04 documented ("your photo replaces your initial everywhere", photo visibility rule). No `resources/manual/` file touched.

## Deviations from Plan
None. HealthAccessCard already received `linkedUserId` from the admin subject query, so no backend change was needed there. TaskBoard already passed `person-id`.

## Known Stubs
None.

## Threat Flags
None. T-10-36 (photoUserId only in the payload of an openable person; tests for no-account person, refusal and the people list), T-10-37 (switcher unchanged), T-10-38 (sw.js assertions) covered by tests.

## Self-Check: PASSED
Both commits and all created files verified present.
