---
phase: 07-medical-records
plan: 03
subsystem: health
tags: [medical, privacy, visibility, inertia, vue]
requires: ["07-01", "07-02"]
provides:
  - "medical_subjects / medical_subject_members schema with CHECK constraints"
  - "App\\Medical\\{Viewer, Visibility, PersonPage} and Queries\\SubjectQuery"
  - "routes health.index, health.people.show, health.people.store"
  - "Health/Person page, PersonSwitcher, PersonHeader, PersonPanel"
  - "tests/Support/MedicalFixtures (grantHealth, subject, matrixParent) and EndpointMatrix/health.php"
affects: [07-04, 07-05, 07-17]
tech-stack:
  patterns: ["one two-branch scope (members UNION own linked), no admin branch", "hidden = unknown = malformed = one 403, resolved in the controller"]
key-files:
  created:
    - database/migrations/2026_11_18_100000_create_medical_subjects_tables.php
    - app/Models/MedicalSubject.php
    - database/factories/MedicalSubjectFactory.php
    - config/medical.php
    - app/Medical/Viewer.php
    - app/Medical/Visibility.php
    - app/Medical/Queries/SubjectQuery.php
    - app/Medical/PersonPage.php
    - routes/web/health.php
    - app/Http/Controllers/Health/PersonController.php
    - app/Http/Requests/Health/StorePersonRequest.php
    - resources/js/Pages/Health/Person.vue
    - resources/js/Health/PersonSwitcher.vue
    - resources/js/Health/PersonHeader.vue
    - resources/js/Health/PersonPanel.vue
    - tests/Support/MedicalFixtures.php
    - tests/Support/EndpointMatrix/health.php
    - tests/Feature/Health/SubjectTest.php
    - tests/js/health/Person.test.js
key-decisions:
  - "Own linked profile counts as Edit; effective level = min(module level, subject level)"
  - "A panel kind with any ?id= aborts 403 until a later slice teaches PersonPage to resolve ids; unknown kinds are ignored"
  - "new-person panel is ignored (null) for a module-view member"
metrics:
  tasks: 3
  completed: 2026-11-14
---

# Phase 7 Plan 03: Person slice Summary

A member with Health edit can create a person (no account needed), becomes its "Can change" member in the same transaction as the content-free audit row, and lands on `/health/people/{uuid}`. Every read goes through one scope (`Visibility::subjectIds`: member rows UNION own linked profile with self access on; no admin branch). Hidden, unknown, malformed and mismatched-panel ids answer one identical `Errors/Forbidden` 403 with no `access_denied` row.

## Commits

- 497624e feat(07-03): subjects schema, scope, SubjectQuery, fixtures
- f5560d2 feat(07-03): routes, create request, identical 403, matrix rows
- 14834be feat(07-03): Person page, switcher, header, create panel
- style commit for Pint on SubjectTest

## Verification

- SubjectTest (16 + route tests), SensitiveGateMatrixTest (three health routes replayed stale and fresh) green.
- Full Pest: 1679 tests; Vitest 106 files / 2452 tests; `npm run build` SW-PRECACHE OK; Pint clean on own files.
- Compose project fh0703 removed with `down -v`.

## Deviations from Plan

1. **Task 2 commit is red until task 3.** Two route tests assert `->component('Health/Person')`, which Inertia checks against the file on disk; they went green when Person.vue landed in the next commit.
2. **[Rule 3] Postgres aborted-transaction in constraint tests:** each refused insert in SubjectTest runs in its own `DB::transaction` savepoint (RefreshDatabase wraps the test).
3. **Discard dialog body** reuses `finance.discard.body` ("Your changes are not saved."): `health.discard` (07-01) has no body key and `UiConfirmDialog` requires one. The secondary panel button reuses `ui.close`. A `health.discard.body` / `health.cancel` key would be cleaner (07-20 or later lang pass).
4. **Full-suite flake, not mine:** first full Pest run had one failure, `FinancePrivacyTest` (Inertia asset version 409 vs 200), caused by the sibling's `npm run build` changing the manifest mid-run. Re-run once alone: green.
5. Non-name fields in StorePersonRequest use Laravel's default messages (no `health.*` key exists for them); the panel maps any non-key message to the generic `health.error.load` alert.

## Notes for later plans

- Nav item `health` in `resources/js/Shell/modules.js` still has `href: null`; wiring it to `/health` was not in this plan's file list.
- Side column container is present and empty (`data-testid="person-side"`); 07-05+ fill it. PersonHeader has no action buttons yet.
- `PersonPage::PANELS` and the "any id aborts 403" rule are the extension point for visit/medication/allergy panels.

## Known Stubs

Person main column shows the "No visits yet" empty state and an empty side column by design; 07-05 and later slices add visits and cards.

## Threat Flags

None beyond the plan's register (T-07-10..17 all covered by tests: identical 403, zero access_denied rows, no admin branch, module-denied parity, transactional creator grant with FailingAuditLogger rollback, matrix replay).

## Self-Check: PASSED
