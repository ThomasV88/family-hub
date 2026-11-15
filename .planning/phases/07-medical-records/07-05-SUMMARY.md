---
phase: 07-medical-records
plan: 05
subsystem: health
tags: [medical, visits, specialty, timeline, inertia, vue]
requires: ["07-03"]
provides:
  - "medical_visits table (provenance columns, CHECKs, unique (id, subject_id))"
  - "App\\Medical\\Specialty (30 keys, TAXONOMY_VERSION 1) and Queries\\VisitQuery"
  - "routes health.visits.store|update|destroy, VisitRequest with authorize()-first"
  - "PersonPage props visits/folders/timeline/timelineFilters/view/filter/specialties; panels new-visit and visit"
  - "ViewSwitch, SpecialtyFolder, TimelineList, VisitPanel; tests/Support/MedicalPrivacy/visits.php"
affects: [07-06, 07-10, 07-13, 07-14]
key-files:
  created:
    - database/migrations/2026_11_18_110000_create_medical_visits_table.php
    - app/Models/MedicalVisit.php
    - database/factories/MedicalVisitFactory.php
    - app/Medical/Specialty.php
    - app/Medical/Queries/VisitQuery.php
    - routes/web/health-visits.php
    - app/Http/Controllers/Health/VisitController.php
    - app/Http/Requests/Health/VisitRequest.php
    - tests/Support/EndpointMatrix/health-visits.php
    - tests/Support/MedicalPrivacy/visits.php
    - resources/js/Health/ViewSwitch.vue
    - resources/js/Health/SpecialtyFolder.vue
    - resources/js/Health/TimelineList.vue
    - resources/js/Health/VisitPanel.vue
    - tests/Feature/Health/VisitTest.php
    - tests/js/health/Visits.test.js
  modified:
    - app/Medical/PersonPage.php
    - resources/js/Pages/Health/Person.vue
    - resources/js/Health/PersonHeader.vue
key-decisions:
  - "VisitRequest::authorize() resolves {person} with Edit-scope findVisible and stores it; invalid body to hidden/unknown/malformed/view-only gives the identical 403 (tested, no session errors)"
  - "Specialty::label() reads the nested lang JSON directly (Laravel's flat JSON lookup cannot address health.specialty.*)"
  - "visit panel id must resolve inside the person (any miss 403); new-visit needs Edit level on the person, else 403"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 05: Visits Summary

Editors record, edit and delete doctor visits (date, doctor, one of 30 specialties or Other with free text, diagnosis, notes); the person page shows them as specialty folders (first open) or a timeline with specialty filter chips, and view-only members see them read-only.

## Commits
- e7ff3aa feat(07-05): visits schema, specialty taxonomy, scoped query, routes and controller
- a6d3bbb feat(07-05): specialty folders, timeline and visit panel on the person page

## Notes
- Matrix rows: parent success is 302 for store/update/destroy (redirect to the person page).
- Update/delete are one statement whose WHERE carries id, subject_id and the Edit-scope subquery (test asserts `medical_subject_members` and `"user_id" = ?`); 0 rows -> controller abort(403).
- Panel mutations redirect to the plain person URL, so the view/filter query is dropped after saving (back to default folders view).
- Add visit entry points: header primary button (hidden below 640px container width), empty-state action, and the shell add handler (phone FAB; on desktop the shell also shows an "Add visit" top-bar button, same as Wishlist).
- Privacy area file seeds a visible cardiology visit and canary visits on the hidden and own-off subjects; payloads for store/update; panels for visit id and timeline views/filter.

## Deviations from Plan
1. [Rule 1 - Bug] `Specialty::label()` with `__()` returned the key because lang JSON is nested; label now reads `lang/<locale>.json` `health.specialty` (static cache, falls back to `__()`).
2. [Rule 3] No `health.visit.err_text` key exists in 07-01 copy; doctor/diagnosis/notes length and UTF-8 errors use `health.error.load` (the panel shows its generic alert for them).
3. Discard dialog body reuses `finance.discard.body` as in 07-03 (no `health.discard.body` key).
4. Tests: full Pest 1741 passed, Vitest 108 files / 2523 tests passed, `npm run build` SW-PRECACHE OK, all first-time green (no sibling flakes seen). Pint clean on own PHP files.

## Known Stubs
`documentCount` is 0 until 07-14 (per contract). Timeline entry type `visit` only; 07-13/07-14 add vaccination and document entries and the `vaccinations`/`documents` filter chips.

## Threat Flags
None beyond the register (T-07-22, 22b, 23, 24, 25, 26 covered by tests, grep test and named throttles).

## Self-Check: PASSED
