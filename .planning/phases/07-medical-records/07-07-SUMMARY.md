---
phase: 07-medical-records
plan: 07
subsystem: settings-admin
tags: [health, access, admin, audit]
requires: [07-03]
provides: [settings.health_people.members.update, settings.health_people.link.update, AdminSubjectQuery]
affects: [tests/Support/SensitiveRoutes.php]
key-files:
  created:
    - app/Medical/Queries/AdminSubjectQuery.php
    - app/Http/Controllers/Settings/HealthPeopleController.php
    - app/Http/Requests/Settings/HealthPeopleMembersRequest.php
    - app/Http/Requests/Settings/HealthPeopleLinkRequest.php
    - routes/web/settings-health-people.php
    - tests/Support/EndpointMatrix/settings-health-people.php
    - tests/Feature/Health/HealthPeopleAdminTest.php
    - resources/js/Components/Settings/HealthAccessCard.vue
    - resources/js/Components/Settings/PersonAccessPanel.vue
    - tests/js/settings/HealthAccess.test.js
  modified:
    - app/Http/Controllers/Settings/MembersController.php
    - resources/js/Pages/Settings/Members.vue
    - resources/js/Components/Settings/PermissionsTab.vue
    - tests/Support/SensitiveRoutes.php
    - tests/Feature/Security/SensitiveGateMatrixTest.php
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 07: Settings admin for people Summary

Admins grant None/View/Change per person, link a person to one member account and switch own-record access, all behind access:admin + access:recent with content-free audit rows.

## Commits
- 5cef940 admin query, routes, requests, audit, safety-net classification
- (task 2) Health access card, person panel, permissions hint, MembersController prop

## Notes
- Members payload: only levels in the request change; unnamed members stay. Self-add by an admin writes medical_admin_self_added only.
- Every link refusal (taken, unknown, non-integer) is the same 422 on user_id with key health.access.link_failed. No "inactive" user concept exists, so that case does not arise.
- No GET route added; both PUTs are in accessGranting() and the prefix is in grantPrefixes(). grantSnapshot now includes medical_subject_members and medical_subjects user_id/self_access_enabled. PROVISIONAL admin-sees-names noted in the SensitiveRoutes docblock (owner confirms in 07-20).
- MembersController imports only App\Medical\Queries\AdminSubjectQuery and calls only listForAdmin() (07-06 arch allowlist covers it; arch tests pass).

## Deviations
- [Decision, as planned] Linking lives only in Settings (admin + recent), not in the Health person form.
- [Rule 3] Pint also reformatted app/Http/Middleware/ThrottleAuthRoutes.php (not mine); reverted, not committed.
- Panel keys reuse permissions.discard_body and health.discard.* copy (no new strings).

## Verification
Full Pest 1723 passed; the 2 failures in MedicalWriteParityTest (07-05/06 files, missing {visit} provider) passed on re-run. Vitest 2458 passed, npm run build SW-PRECACHE OK, Pint clean on own files.

## Known Stubs
None.

## Self-Check: PASSED
