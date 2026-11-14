---
phase: 08-ai-extraction-platform
plan: 04
subsystem: database
tags: [postgres, migrations, ai, leak-net, append-only]
requires: []
provides:
  - medical_ai_extractions and medical_ai_decisions with database-enforced invariants
  - ai_calls with lifecycle guard triggers and ai_calls_prune definer function
  - ai_agent_status, ai_notice_acks, ai:prune-calls command
affects: [08-05, 08-14, later Phase 8 plans]
tech-stack:
  added: []
  patterns: [definer-function prune, lifecycle trigger, partial unique indexes]
key-files:
  created:
    - database/migrations/2026_11_19_100000_create_medical_ai_tables.php
    - database/migrations/2026_11_19_110000_create_ai_call_tables.php
    - app/Models/MedicalAiExtraction.php
    - app/Models/MedicalAiDecision.php
    - app/Models/AiCall.php
    - app/Models/AiAgentStatus.php
    - database/factories/MedicalAiExtractionFactory.php
    - database/factories/AiCallFactory.php
    - app/Console/Commands/AiPruneCalls.php
    - tests/Feature/Ai/AiSchemaTest.php
    - tests/Feature/Ai/AiCallsGuardTest.php
  modified:
    - tests/Support/MedicalPrivacy.php
    - tests/Unit/Health/MedicalMutationScanTest.php
key-decisions:
  - "PROVISIONAL: a lifecycle-guarded ai_calls counts as append-only (confirmed in 08-22)"
  - "PROVISIONAL: proposals kept until superseded or the document is deleted; no time-based proposal prune built"
requirements-completed: [AI-02, AI-03]
duration: ~40 min
completed: 2026-11-14
---

# Phase 8 Plan 04: AI proposal and call-log data model Summary

Proposals, per-entry decisions and a content-free, lifecycle-guarded call log, with the new medical tables inside the leak net.

## Commits
- a0d5fcc feat(08-04): proposal and decision tables with leak-net registration
- acbac98 feat(08-04): content-free ai_calls with lifecycle guard and definer prune

## Deviations from Plan

**1. [Rule 3 - Blocking] SET ROLE fh_app impossible from the test connection**
- Tests connect as fh_owner, which is not a member of fh_app, so `SET ROLE fh_app` fails ("permission denied to set role").
- Fix: AiCallsGuardTest opens two extra connections outside the RefreshDatabase transaction (real fh_app login, password from FH_APP_PASSWORD or the dev default `fh_app_dev`, and a fresh owner connection). Rows they commit are removed in afterEach via the owner opt-in (`ai.prune`). The prune command test switches the default connection to the fh_app one for the call.
- Files: tests/Feature/Ai/AiCallsGuardTest.php

**2. Vitest and `npm run build` not run** — this plan touches no JS and the host has Node 18; left to the orchestrator's end-of-wave run.

## Notes
- Full Pest (memory_limit 1G): 2082 passed, 1 failed. The failure, MedicalLeakSuiteTest "real surfaces stay green", compared HTML before/after and differed; it passes alone (7 passed). Cause is almost certainly concurrent edits by parallel plans in the shared tree (Health requests/queries and Dockerfile were modified by others during the run), not this plan. Re-run in the integrated tree.
- phpunit.xml 512M not changed; the run used 1G, peak not measured.
- Pint run on all this plan's PHP files.
- Scheduling of ai:prune-calls is left to 08-14; config('ai.call_retention_months') defaults to 12 until 08-05 adds config/ai.php.
- medical_ai_decisions has no subject_id: later query code must join medical_ai_extractions with the membership predicate (noted in MedicalPrivacy.php).

## Known Stubs
None.

## Self-Check: PASSED
