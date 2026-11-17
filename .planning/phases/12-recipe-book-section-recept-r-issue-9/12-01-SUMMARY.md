---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 01
subsystem: access
tags: [permissions, recipes, presets, migration, manual]
requires: []
provides:
  - "Module::Recipes (last case, not audited, not sensitive)"
  - "Preset arms with recipes: Parent, Child, Grandparent edit, Guest view"
  - "Migration 2026_11_29_100000: CHECK with six names, backfill from todo, reversible down()"
  - "Six-module permission save, Recipes column in the matrix and Members permissions tab"
affects: [12-02, 12-07]
key-files:
  created:
    - database/migrations/2026_11_29_100000_add_recipes_module_permissions.php
    - tests/Feature/Recipes/ModuleBackfillTest.php
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

# Phase 12 Plan 01: Recipes permission module Summary

Recipes is a sixth module in the permission layer with To-do-like defaults, a migration that gives every existing member a recipes grant equal to their todo grant (ON CONFLICT DO NOTHING, never higher), and a six-module permission save in the UI and API.

## Commits
- f65c3c4 recipes module, keyed presets, backfill migration, Access tests, ModuleBackfillTest (`Refs #9`)
- aed5363 six-module save, lang, admin manual, fixtures (`Refs #9`)

## Tests
Isolated compose project `fh1201` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v` (no containers left). Pint on all touched PHP: pass. Full Pest: 3311 passed, 1 skipped. Full Vitest: 168 files, 5540 tests passed (includes ManualCoverage, HelpCoverage, a11y). Pest `tests/Feature/Manual` and `Help` are part of the full run.

## User documentation
User-visible change: the Permissions screens (Members permissions tab, permission matrix) show a Recipes column and the four preset hints name Recipes. `resources/manual/{en,sk,pl}/admin.json` section `permissions`: module list, four preset lines, and a sentence that recipes are private or shared by their author with no administrator bypass (D18). Same section ids, counts and links. No help tip change (no new field). No navigation entry yet (12-07).

Drafts for native review: `resources/manual/sk/admin.json`, `resources/manual/pl/admin.json`, and `permissions.module.recipes` plus `permissions.preset.*_hint` in `lang/sk.json` (Receptár) and `lang/pl.json` (Receptarz).

## Deviations from Plan
- **[Rule 3] Same extra fixtures as Phase 11** were updated for the sixth module: GoogleLoginTest, InviteAcceptTest, StagingSeedTest (grant counts 5 to 6), Invitations.test.js, plus the audit counts in PermissionSaveTest (4 to 5; the default payload now includes recipes). All were in the plan's file list.
- Added a five-key payload (no recipes) refusal case and a recipes-save-and-audit test to PermissionSaveTest, and a recipes probe to AccessGateTest.
- Test helper `seedGrant` renamed `seedRecipeGrant` in ModuleBackfillTest to avoid a global Pest function clash with the Shopping test.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-01 to T-12-03 covered by ModuleBackfillTest, full suites, PermissionSaveTest).

## Self-Check: PASSED
