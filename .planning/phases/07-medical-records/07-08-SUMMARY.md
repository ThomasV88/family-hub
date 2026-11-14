---
phase: 07-medical-records
plan: 08
subsystem: health
tags: [medical, allergies, severity, inertia, vue]
requires: ["07-05"]
provides:
  - "medical_allergies table (severity CHECK, provenance CHECKs)"
  - "Queries\\AllergyQuery (severe-first list, scoped find/create/update/delete)"
  - "routes health.allergies.store|update|destroy, AllergyRequest with authorize()-first"
  - "PersonPage prop allergies; panels new-allergy and allergy"
  - "AllergyList (side card) and AllergyPanel; tests/Support/MedicalPrivacy/subjects_allergies.php"
affects: [07-11, 07-15]
key-files:
  created:
    - database/migrations/2026_11_18_120000_create_medical_allergies_table.php
    - app/Models/MedicalAllergy.php
    - database/factories/MedicalAllergyFactory.php
    - app/Medical/Queries/AllergyQuery.php
    - routes/web/health-allergies.php
    - app/Http/Controllers/Health/AllergyController.php
    - app/Http/Requests/Health/AllergyRequest.php
    - tests/Support/EndpointMatrix/health-allergies.php
    - tests/Support/MedicalPrivacy/subjects_allergies.php
    - resources/js/Health/AllergyList.vue
    - resources/js/Health/AllergyPanel.vue
    - tests/Feature/Health/AllergyTest.php
    - tests/js/health/Allergies.test.js
  modified:
    - app/Medical/PersonPage.php
    - resources/js/Pages/Health/Person.vue
    - tests/js/health/Person.test.js
key-decisions:
  - "Request accepts mild|moderate|severe only; 'unknown' stays reserved for AI rows"
  - "Severity tag is UiTag with TriangleAlert icon plus the severity word (never colour alone); Mild neutral, Moderate warn, Severe danger"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 08: Allergies Summary

Editors record, edit and delete allergies (substance, reaction, severity); the person page shows them in a side card, severe first, with a text-and-icon severity tag, and view-only members see the card and panel read-only.

## Commits
- f61b8c1 feat(07-08): schema, scoped query, routes, person page props and privacy area
- 696d1d1 feat(07-08): allergies side card and panel on the person page
- (final) fix(07-08): sort allergies privacy area after subjects, pint import order

## Verification
- Full Pest: 1804 passed (php -d memory_limit=1G). Vitest: 110 files, 2576 tests passed. `npm run build`: SW-PRECACHE OK. Pint clean on own files.
- AllergyTest covers CHECKs, CRUD, provenance, scoped statements, severity order, identical 403 for hidden/own_off/unknown/malformed/mismatched/view-only targets, and invalid body to those person ids giving the same 403 with no session errors.
- Memory: the first full run with a half-finished sibling area file died at the 512M limit after an ErrorException (a failing test's trace, not a real peak); the clean full run passed under 512M. Peak not measured.

## Deviations from Plan
1. [Rule 1 - Bug] Privacy area file renamed from `allergies.php` to `subjects_allergies.php`. The harness loads area files in sorted name order and the hidden seed needs `$ctx['hidden']['person']` from `subjects.php`; `allergies.php` sorts before it (the 07-05 comment "subjects runs first" only holds for names after "subjects"). Siblings 07-09/07-10 hit the same ordering and adjusted their files.
2. [Rule 1 - Bug] `tests/js/health/Person.test.js` updated: the side column is no longer empty (it now holds the allergies card).
3. Pint import-order fix on AllergyController (separate small commit).

## Known Stubs
None.

## Threat Flags
None beyond the register (T-07-37b, 38, 39, 40, 41 covered by tests).

## Self-Check: PASSED
