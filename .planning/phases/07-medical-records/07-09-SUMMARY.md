---
phase: 07-medical-records
plan: 09
subsystem: medical-records
tags: [ai-consent, privacy, audit, vue]
requires: [07-05, 07-06]
provides:
  - AiConsent::allows() fail-closed check for Phase 8
  - SystemQuery (viewer-less boolean reads)
  - SubjectQuery::setConsent (Edit-scoped, audited in one transaction)
  - AiConsentControl in the person header
affects: [07-12, 07-15, 08]
key-files:
  created:
    - app/Medical/AiConsent.php
    - app/Medical/Queries/SystemQuery.php
    - app/Http/Controllers/Health/AiConsentController.php
    - app/Http/Requests/Health/AiConsentRequest.php
    - routes/web/health-consent.php
    - tests/Support/EndpointMatrix/health-consent.php
    - tests/Support/MedicalPrivacy/consent.php
    - tests/Feature/Health/AiConsentTest.php
    - resources/js/Health/AiConsentControl.vue
    - tests/js/health/AiConsent.test.js
  modified:
    - app/Medical/Queries/SubjectQuery.php
    - resources/js/Health/PersonHeader.vue
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 09: Per-person AI consent Summary

Per-person AI-consent flag (off by default) that only members who can change the person can set, behind a consent dialog, audited in the same transaction, with one fail-closed `AiConsent::allows()` for Phase 8.

## What was built

- `SubjectQuery::setConsent`: transaction, Edit-scoped locked read, Edit-scoped UPDATE (`ai_consent`, `_changed_at`, `_changed_by`), audit `MedicalConsentChanged {subject, value on|off}`. Same value writes nothing and no audit row. A failing audit rolls back the update.
- `SystemQuery::aiConsentFor` (no SQL for a non-uuid) and `AiConsent::allows(MedicalSubject|string)`: reads the DB every call, ignores the in-memory attribute, any Throwable gives false. Docblock tells Phase 8 to call it at dispatch and right before the CLI.
- `AiConsentRequest` with authorize() first (findVisible with Edit scope), `AiConsentController`, route `PATCH /health/people/{person}/ai-consent` (`health.people.ai_consent.update`, access edit, throttle 30/min), matrix row and privacy-area payload.
- `AiConsentControl.vue` mounted in `PersonHeader.vue`: switch with explanation linked by aria-describedby, the "not available yet" line, consent dialog (default focus "Keep AI off"), immediate turn-off, danger alert on failure with the switch kept on the saved value, read-only "AI: on/off" tag for view-only viewers. Existing `health.ai.*` strings (en/sk/pl) were reused; no lang changes.

## Verification

- AiConsentTest, SensitiveGateMatrixTest, Unit/Health scans, MedicalPrivacyArchTest: pass.
- Vitest full: 110 files, 2576 tests pass. `npm run build`: OK. Pint on own PHP files: clean.
- Full Pest: see deviations (MedicalPrivacyTest is not mine and does not complete).

## Deviations from Plan

**1. [Rule 3] SystemQuery is not `final`.** The plan asked for a mocked SystemQuery to prove the DB-error path, and Mockery cannot mock a final class. Left non-final.

**2. Full Pest could not be completed cleanly (out of scope, sibling files).** `Tests\Feature\Health\MedicalPrivacyTest` and `MedicalWriteParityTest` fail before and independent of this plan: `tests/Support/MedicalPrivacy/allergies.php` (07-08) reads `$ctx['hidden']['person']` in its `hidden` seed, but areas run in sorted file order so `allergies` seeds before `subjects` creates that id ("Undefined array key person"). The privacy test also exhausts the 512M phpunit memory limit. Neither involves consent.php (it declares no seeds). Reported to the caller; 07-08 owner should fix the ordering (for example by seeding people in an earlier-sorted area or guarding the id).

## Known Stubs

None. ("AI reading is not available yet" is the specified product line.)

## Threat Flags

None beyond the plan's threat model.

## Commits

- 889a7f5 feat(07-09): per-person AI consent, SystemQuery and AiConsent::allows
- 4bdb83b feat(07-09): AI consent control in the person header

## Self-Check: PASSED
