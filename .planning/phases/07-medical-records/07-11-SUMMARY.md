---
phase: 07-medical-records
plan: 11
subsystem: health
tags: [medical, medications, inertia, vue]
requires: ["07-08"]
provides:
  - "medical_medications table (ended_on >= started_on CHECK, provenance CHECKs)"
  - "Queries\\MedicationQuery (forSubject with server-side ongoing split, current(), scoped find/create/update/delete)"
  - "routes health.medications.store|update|destroy, MedicationRequest with authorize()-first"
  - "PersonPage prop medications (with ongoing); panels new-medication and medication"
  - "MedicationList (side card, ended behind disclosure) and MedicationPanel; tests/Support/MedicalPrivacy/subjects_medications.php"
affects: [07-16, 07-18]
key-files:
  created:
    - database/migrations/2026_11_18_140000_create_medical_medications_table.php
    - app/Models/MedicalMedication.php
    - database/factories/MedicalMedicationFactory.php
    - app/Medical/Queries/MedicationQuery.php
    - routes/web/health-medications.php
    - app/Http/Controllers/Health/MedicationController.php
    - app/Http/Requests/Health/MedicationRequest.php
    - tests/Support/EndpointMatrix/health-medications.php
    - tests/Support/MedicalPrivacy/subjects_medications.php
    - resources/js/Health/MedicationList.vue
    - resources/js/Health/MedicationPanel.vue
    - tests/Feature/Health/MedicationTest.php
    - tests/js/health/Medications.test.js
  modified:
    - app/Medical/PersonPage.php
    - resources/js/Pages/Health/Person.vue
    - tests/js/health/Person.test.js
key-decisions:
  - "Ongoing = ended_on null or >= today, today = now(family.timezone) computed in PHP and passed to the query; the client never compares dates"
  - "MedicationQuery attributes() keeps stored values for fields the client did not send (notes has no input yet), so a panel save cannot wipe notes"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 11: Medications Summary

Editors record, edit and delete medications (name, dose, schedule, start, end; end cannot precede start). The person page shows an Ongoing list with ended medications behind a "Show ended medications" disclosure; status comes from the server in the family time zone. View-only members see the card and panel read-only.

## Commits
- 69f4370 feat(07-11): schema, scoped query, routes, person page props and privacy area
- 4a4861b feat(07-11): medications side card and panel on the person page

## Verification
- Full Pest: 1852 passed (php -d memory_limit=1G). Vitest: 111 files, 2630 tests passed. `npm run build`: SW-PRECACHE OK. Pint clean on own files.
- MedicationTest covers CHECKs, CRUD, provenance, scoped statements, ongoing-first ordering, current() incl. the UTC-vs-Bratislava date edge, identical 403 for hidden/own_off/unknown/malformed/mismatched/view-only targets, and invalid bodies to those person ids giving the same 403 with no session errors.
- Memory: fixtures are tiny; the full Pest run completed under the 1G limit. Peak not measured inside the container (the `time -v` figure of 38 MB is the docker client only).
- Mid-way, a combined Health+Security run twice showed failures in DocumentServe/DocumentUpload/MedicalWriteParity tests while the sibling plans were editing document files; the parity test passed alone, and the final full run was green.

## Deviations from Plan
1. [Rule 1 - Bug] Privacy area file named `subjects_medications.php` (not `medications.php`) so it sorts after `subjects.php`, per the 07-08 finding.
2. [Rule 1 - Bug] `tests/js/health/Person.test.js` updated: the side column now holds two cards.
3. [Rule 2 - Correctness] Update keeps stored values for unsent fields (notes), so editing in the panel cannot null them.

## Known Stubs
None. The `notes` column is accepted by the API and returned in props but the panel has no notes input (not in UI-SPEC).

## Threat Flags
None beyond the register (T-07-52c, 53, 54, 55, 56 covered by tests).

## Self-Check: PASSED
