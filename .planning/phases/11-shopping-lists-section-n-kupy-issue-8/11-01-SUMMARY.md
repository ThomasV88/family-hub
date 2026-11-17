---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 01
subsystem: access
tags: [permissions, shopping, presets, migration, manual]
requires: []
provides:
  - "Module::Shopping (last case, not audited, not sensitive)"
  - "Preset::grants() from an explicit keyed map"
  - "Migration 2026_11_28_100000: CHECK with shopping, backfill from todo, reversible down()"
  - "Five-module permission save, Shopping column in the matrix and Members permissions tab"
affects: [11-02, 11-03]
key-files:
  created:
    - database/migrations/2026_11_28_100000_add_shopping_module_permissions.php
    - tests/Feature/Shopping/ModuleBackfillTest.php
  modified:
    - app/Access/Module.php
    - app/Access/Preset.php
    - app/Http/Requests/Settings/UpdatePermissionsRequest.php
    - resources/js/Settings/presets.js
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/manual/en/admin.json
    - resources/manual/sk/admin.json
    - resources/manual/pl/admin.json
    - tests/Feature/Access/PresetTest.php
    - tests/Feature/Access/AccessGateTest.php
    - tests/Feature/Settings/MembersPageTest.php
    - tests/Feature/Settings/PermissionSaveTest.php
    - tests/Support/EndpointMatrix/settings-members.php
    - tests/Feature/Auth/GoogleLoginTest.php
    - tests/Feature/Invites/InviteAcceptTest.php
    - tests/Feature/StagingSeedTest.php
    - tests/js/settings/PermissionsTab.test.js
    - tests/js/settings/MembersPage.test.js
    - tests/js/settings/Invitations.test.js
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 01: Shopping permission module Summary

Shopping is a fifth module in the permission layer with To-do-like defaults, a migration that gives every existing member a shopping grant equal to their todo grant, and a five-module permission save in the UI and API.

## Commits
- 2eae957 shopping module, keyed presets, backfill migration, tests (`Refs #8`)
- 62bb547 five-module save, lang, admin manual, fixtures (`Refs #8`)

## Tests
Isolated compose project `fh1101` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. Pint on app/Access, the request, tests and the migration: pass. Full Pest: 2948 passed, 1 skipped. Full Vitest: 153 files, 5185 tests passed (includes ManualCoverage and HelpCoverage). Pest `tests/Feature/Manual` and `Help` green.

## User documentation
`admin.json` section `permissions` in en, sk, pl: module list now names Shopping and the four preset lines include it (Guest: To-do and Shopping view only). Section `where` does not list modules, so it is unchanged. Same section ids, counts and links. No help tip change (no new field).

Drafts for native review: `resources/manual/sk/admin.json`, `resources/manual/pl/admin.json`, and the `permissions.module.shopping` plus `permissions.preset.*_hint` strings in `lang/sk.json` and `lang/pl.json` (sk "Nákupy", pl "Zakupy").

## Deviations from Plan
- **[Rule 3] Extra test files updated** because the fifth module broke them: `tests/Feature/Auth/GoogleLoginTest.php`, `tests/Feature/Invites/InviteAcceptTest.php`, `tests/Feature/StagingSeedTest.php` (grant row counts 4 to 5), `tests/js/settings/Invitations.test.js` (preset hint text and fixtures).
- `app/Http/Requests/Settings/UpdatePermissionsRequest.php`: only the docblock changed ("five module keys"); the rule already iterates `Module::cases()`.
- The permission save writes a `permission_changed` audit row for any module change, shopping included (this is the existing behaviour, independent of `Module::audited()`); the new test asserts one shopping audit row and audit counts in two existing tests rose from 3 to 4.
- Backfill test seeds shopping rows only while the five-module CHECK is active (a shopping row cannot be seeded after `down()`); idempotency is shown by re-running `up()`.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-11-01 to T-11-04 covered by PresetTest, ModuleBackfillTest, PermissionSaveTest).

## Self-Check: PASSED
