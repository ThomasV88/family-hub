---
phase: 08-ai-extraction-platform
plan: 06
subsystem: medical-records
tags: [ai, provenance, verify, validation]
requires: []
provides:
  - "XQuery::create(..., string $source = 'manual') for Visit, Medication, Allergy, Vaccination"
  - "XQuery::verify(Viewer, MedicalSubject, string $id): bool"
  - "XRequest::fieldRules() and fieldMessages() (public static), used by rules()/messages()"
affects: [08-09, 08-15, 08-20]
key-files:
  modified:
    - app/Medical/Queries/VisitQuery.php
    - app/Medical/Queries/MedicationQuery.php
    - app/Medical/Queries/AllergyQuery.php
    - app/Medical/Queries/VaccinationQuery.php
    - app/Http/Requests/Health/VisitRequest.php
    - app/Http/Requests/Health/MedicationRequest.php
    - app/Http/Requests/Health/AllergyRequest.php
    - app/Http/Requests/Health/VaccinationRequest.php
  created:
    - tests/Feature/Health/RecordProvenanceTest.php
decisions:
  - "source is only a method argument (manual|ai, InvalidArgumentException otherwise); FIELDS allowlists unchanged so client input cannot set it"
  - "verify is one Edit-scoped UPDATE guarded by source='ai' AND verified_at IS NULL; also touches updated_at"
  - "Vaccination markGiven keeps 'manual' via the insert default"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 8 Plan 06: Record provenance and verify Summary

The four Phase 7 record queries now accept an AI source on create and offer a scoped verify transition, and each record type exposes one shared rule set for the manual form and the AI accept path.

## Commits
- 4518df3 feat(08-06): source argument and verify transition in the four record queries
- 6f12b35 refactor(08-06): one shared rule set per record type

## Deviations from Plan
None in behaviour. Notes:
- verify also sets `updated_at` (consistent with the other updates).
- Allergy `fieldRules()` keeps severity `in:mild,moderate,severe` as is; the DB allows 'unknown' for AI rows, so 08-15 must decide how an AI allergy with unknown severity is validated (provisional: the accept path edits it to a real severity, or 08-15 widens its own rule, not the manual one).
- Pint reformatted one fully-qualified type in the test file.

## Verification
- RecordProvenanceTest plus the four existing record test files: 96 passed.
- Full Pest: 2050 passed, 3 failed in the first run, all in/around SensitiveGateMatrixTest. Re-running that file alone: 20 passed. The failures were most likely transient state from parallel plans editing the shared tree (routes/matrix files of other plans), not from this plan's files. 08-21 should confirm on the integrated tree.
- Vitest, build: not run (no frontend files touched; run at wave merge).
- Compose project fh0806 removed with `down -v`.

## Known Stubs
None.

## Self-Check: PASSED
