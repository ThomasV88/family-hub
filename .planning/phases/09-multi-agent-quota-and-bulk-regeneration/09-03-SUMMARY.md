---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 03
subsystem: database
tags: [postgres, migrations, ai, bulk, quota]
requires: []
provides:
  - ai_agent_settings (seeded codex on/1, copilot off/2), cooldown columns, ai_notice_acks.providers
  - waiting extraction status, 'decided' cancel reason, bulk_run_id, per-attempt ai_calls rows
  - ai_bulk_dry_runs, ai_bulk_runs (one open), ai_bulk_run_items
affects: [09-04, 09-06, 09-09, later bulk plans]
key-files:
  created:
    - database/migrations/2026_11_21_100000_create_ai_agent_settings.php
    - database/migrations/2026_11_21_110000_extractions_waiting_and_bulk_columns.php
    - database/migrations/2026_11_21_120000_create_ai_bulk_tables.php
    - app/Models/AiAgentSetting.php
    - app/Models/AiBulkRun.php
    - app/Models/AiBulkDryRun.php
    - database/factories/AiBulkRunFactory.php
    - tests/Feature/Ai/AiPhase9SchemaTest.php
  modified:
    - database/factories/MedicalAiExtractionFactory.php
    - tests/Feature/Ai/AiSchemaTest.php
    - tests/Feature/Ai/AiCallsGuardTest.php
decisions:
  - "M2 down() throws a RuntimeException naming the waiting count and never rewrites rows; a 'decided' cancel row would also make the narrower check fail loudly."
  - "ai_bulk_runs.blocked_since added (planner addition) for the derived Stopped display."
  - "Migration testing needs DB_USERNAME=fh_owner DB_PASSWORD=fh_owner_dev (the app role cannot create tables)."
metrics:
  tasks: 3
  completed: 2026-11-14
---

# Phase 9 Plan 03: Phase 9 data model Summary

Three migrations with database-level guarantees (deferred-unique agent order, waiting-state CHECK, one_active including waiting, unique call attempt, bulk_run_id in the ai_calls immutable identity, one open bulk run), three models, two factory changes and updated pinned tests.

## Commit
- 828d7e8 feat(09-03): agent settings, waiting status, per-attempt call rows and bulk tables

## Verification
- `AiPhase9SchemaTest`, `AiSchemaTest`, `AiCallsGuardTest`: 36 passed.
- Whole Phase 8 `tests/Feature/Ai` directory in fh0903: 85 passed (no other test pinned the old schema).
- `migrate:fresh`, `migrate:rollback --step=3`, `migrate` round trip succeeded as `fh_owner`.
- Pint clean on all 11 PHP files. Project fh0903 removed with `down -v`.

## Deviations from Plan
None of substance. Test-only additions: the M2 down() test runs the rollback inside a transaction and rolls it back.
Note: the plan's verify commands omit `-e DB_USERNAME=fh_owner`; migrations need it (see compose.yaml line 4).

## Known Stubs
None.

## Self-Check: PASSED
