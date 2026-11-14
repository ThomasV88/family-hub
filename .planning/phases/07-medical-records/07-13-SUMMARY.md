---
phase: 07-medical-records
plan: 13
subsystem: health
tags: [medical, vaccinations, inertia, vue]
requires: ["07-11"]
provides:
  - "medical_vaccinations (bigint identity id + public_id uuid, next_due_on CHECK, partial index on next_due_on)"
  - "Queries\\VaccinationQuery (forSubject with server status, attentionCount, scoped find/create/update/delete, atomic markGiven)"
  - "routes health.vaccinations.store|update|destroy|given, VaccinationRequest and MarkVaccinationGivenRequest with authorize()-first"
  - "PersonPage props vaccinations, vaccinationAttention; panels new-vaccination and vaccination; timeline entries and vaccinations filter"
  - "VaccinationList, VaccinationPanel, tests/Support/MedicalPrivacy/subjects_vaccinations.php"
affects: [07-15, 07-16]
key-files:
  created:
    - database/migrations/2026_11_18_150000_create_medical_vaccinations_table.php
    - app/Models/MedicalVaccination.php
    - database/factories/MedicalVaccinationFactory.php
    - app/Medical/Queries/VaccinationQuery.php
    - routes/web/health-vaccinations.php
    - app/Http/Controllers/Health/VaccinationController.php
    - app/Http/Requests/Health/VaccinationRequest.php
    - app/Http/Requests/Health/MarkVaccinationGivenRequest.php
    - tests/Support/EndpointMatrix/health-vaccinations.php
    - tests/Support/MedicalPrivacy/subjects_vaccinations.php
    - resources/js/Health/VaccinationList.vue
    - resources/js/Health/VaccinationPanel.vue
    - tests/Feature/Health/VaccinationTest.php
    - tests/js/health/Vaccinations.test.js
  modified:
    - app/Medical/PersonPage.php
    - resources/js/Pages/Health/Person.vue
    - resources/js/Health/TimelineList.vue
    - tests/js/health/Person.test.js
key-decisions:
  - "Bigint id is hidden and never selected into props; record URLs and props carry public_id only"
  - "markGiven locks the old row (scoped SELECT FOR UPDATE), clears next_due_on (scoped UPDATE), inserts the new dose, all in one transaction"
  - "Status and dueInDays computed in PHP from the family-timezone date; due_soon = 0..medical.due_soon_days"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 13: Vaccinations Summary

Editors record, edit and delete vaccinations (vaccine, date given, optional next due) and mark a dose as given: the new dose is inserted and the old row's next due date cleared atomically, so an old reminder goes irrelevant. The person page shows a Vaccinations side card (due items first with Overdue / Due in n days / Due date tags), a "Vaccination due" warning counting only this person's items, and vaccination entries in the timeline with a Vaccinations filter chip.

## Commits
- 5f52b1a feat(07-13): schema, scoped query with due status, mark-as-given, routes
- 2af086c feat(07-13): vaccinations card, due alert, panel with mark-as-given and timeline entries

## Verification
- Full Pest: 1881 passed (php -d memory_limit=1G). Vitest: 114 files, 2724 tests passed. `npm run build`: SW-PRECACHE OK. Pint clean on own files.
- VaccinationTest covers CHECKs, unique public_id, hidden bigint, status edges (yesterday, today, +30, +31, none), per-person attention count with a hidden overdue canary, ordering, CRUD, provenance, scoped statements, atomic mark-given (forced CHECK failure leaves old row unchanged), identical 403 for hidden/own_off/unknown/malformed/mismatched/view-only targets, authorize-first for invalid bodies on store, update and given, panels and filters.
- Matrix rows for all four routes pass; the area file joins the leak net.

## Deviations from Plan
1. [Rule 1 - Bug] Pint run on a path glob touched an unrelated file (ThrottleAuthRoutes.php); reverted, not committed.
2. [Rule 1 - Bug] `tests/js/health/Person.test.js` updated: the side column now holds three cards.
3. The `given` toast carries `params.date` (ISO date) because `health.vaccination.given` is "Given {date}".

## Known Stubs
None. `notes` is accepted and returned in props but the panel has no notes input (not in UI-SPEC).

## Threat Flags
None beyond the register (T-07-63b, 64, 65, 66, 67 covered by tests).

## Self-Check: PASSED
