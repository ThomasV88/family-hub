---
phase: 08-ai-extraction-platform
plan: 01
subsystem: i18n-audit
tags: [i18n, audit, ai, health]
requires: []
provides:
  - Phase 8 copy in EN/SK/PL (health.ai.run/status/review/provenance, settings.ai_calls, settings.ai_login)
  - AuditEvent MedicalAiRunRequested, MedicalAiRunFinished, MedicalAiEntryDecided
affects: [08-02..08-06 and later UI/backend plans]
key-files:
  created: [tests/js/i18n-phase8.test.js]
  modified: [lang/en.json, lang/sk.json, lang/pl.json, app/Audit/AuditEvent.php, tests/Unit/MedicalLangTest.php, tests/js/settings/AuditHealth.test.js, tests/js/i18n-phase7.test.js]
key-decisions:
  - "PROVISIONAL: provenance tags live under health.ai.provenance.* (not health.ai.label.*) because health.ai.label is already the consent control string"
  - "Audit labels live under audit.detail.* (existing location), not audit.event.*"
metrics:
  completed: 2026-11-14
---

# Phase 8 Plan 01: Copy and audit events Summary

All Phase 8 strings in EN, SK and PL with identical key sets (about 170 keys, 5 plural keys with 2/4/4 segments) plus three content-free audit events (ids and enums only, health group).

## Commits

- b138bf7 feat(08-01): Phase 8 copy in EN, SK and PL
- a98f393 feat(08-01): three content-free AI audit events

## Deviations from Plan

**1. [Rule 3 - Blocking] `health.ai.label.*` key collision.** `health.ai.label` already exists as a string ("Allow AI to read documents of this person", used by `AiConsentControl.vue`). A nested `label` object cannot coexist. The provenance keys (`ai_extracted`, `unverified`, `verified`, `verify_action`, `verified_toast`) are therefore at `health.ai.provenance.*`. Later plans must use `health.ai.provenance.*`. The consent label string is untouched.

**2. [Rule 3 - Blocking] Edited `tests/js/i18n-phase7.test.js`** (not in files_modified): its "exactly ten plural keys among health.*" assertion now excludes `health.ai.*` keys, which are covered by i18n-phase8.test.js.

**3. Audit labels** were added to `audit.detail.medical_ai_*` (where the existing medical labels are; `AuditTable.vue` falls through to `audit.detail.${event}`), not `audit.event.*` as the plan text suggested. No AuditTable change was needed. No extra keys for status/decision meta values were added; the audit table does not print them.

**4. Extra keys beyond the plan list:** `health.ai.run.review` ("Review suggestions"), `settings.ai_calls.filter_label`, `health.ai.status.pages_read` plural. SK/PL copy for keys absent from UI-SPEC are drafts flagged for native review.

## Notes

- `health.ai.not_available` retained for 08-16 to remove.
- Full Vitest: 123 files, 3494 tests pass. A first full run hit a 5 s timeout in StyleGuide.test.js under load from parallel siblings; it passes with `--testTimeout=30000` and is unrelated.
- `npm run build` passes. Pint passes on AuditEvent.php and MedicalLangTest.php.
- Full Pest: 2083 tests pass (23827 assertions, 172 s, memory_limit=1G override; peak not measured separately).
- Existing unrelated working-tree changes (docker/app/Dockerfile, .dockerignore) belong to a sibling plan and were not touched.

## Known Stubs

None.

## Self-Check: PASSED

Files and commits b138bf7, a98f393 exist.
