---
phase: 08-ai-extraction-platform
plan: 12
subsystem: ai
tags: [queue, job, state-machine, consent, reaper, leak-tests]
requires: ["08-03", "08-04", "08-05", "08-08", "08-10"]
provides:
  - RunExtractionJob (queue ai, database connection, id-only payload)
  - AiJobQuery (claim, document, abortReason, finishReady/Failed/Cancelled, reapStuck)
  - MedicalDocumentStore::copyTo
  - ai:reap-stuck
affects: [08-13, 08-14, 08-22]
key-files:
  created:
    - app/Medical/Queries/AiJobQuery.php
    - app/Medical/Ai/RunExtractionJob.php
    - app/Console/Commands/AiReapStuck.php
    - tests/Feature/Ai/RunExtractionJobTest.php
    - tests/Feature/Ai/AiJobLeakTest.php
  modified:
    - app/Medical/Documents/MedicalDocumentStore.php
    - tests/Unit/Health/MedicalMutationScanTest.php
requirements-completed: [AI-01, AI-02, AI-03, AI-04]
completed: 2026-11-14
---

# Phase 8 Plan 12: Extraction job Summary

Queued extraction becomes a ready proposal or a content-free failure through an atomic claim, run-time re-verification of starter, Health Edit, membership and consent, a kill-on-abort loop, supersede-on-ready, and a reaper.

## Commits
- a60065c feat(08-12): add extraction job, system-side job query and store copy
- b2c3908 feat(08-12): add ai:reap-stuck and job leak tests

## Verification
- RunExtractionJobTest (22), AiJobLeakTest (5), tests/Unit/Health and tests/Unit/Arch pass; Pint clean.
- Full Pest (1G on the command line): 2169 passed, 0 failed, 193 s. This also confirms the 08-10 fix (MedicalTableScanTest) is green.
- Peak memory 262 MB (memory_get_peak_usage, real 312 MB) against the 512M limit: about 50% (61% real), so phpunit.xml is unchanged.
- Vitest and `npm run build` not run (PHP-only plan, host Node 18).

## Decisions (PROVISIONAL, for 08-22 review)
- "Starter active" is "user exists": the app has no user deactivation flag.
- finishReady/finishFailed/finishCancelled return bool (not void) so the job skips the audit row when the run was already closed elsewhere (reaped or cancelled).
- Any unexpected exception inside handle closes the row failed(worker_lost) and rethrows a fresh `RuntimeException('Extraction job failed.')` with no previous exception; `failed()` does the same for timeout or killed worker and is a no-op on closed rows.
- Abort loop polls abortReason on the runner's interval (2 s) and rechecks access every 10 s; the full recheck also runs after the CLI returns and before persist.
- finishReady takes schema_version from the payload and prompt_version from ExtractionSchema.
- Tests compare jsonb payloads with toEqual (Postgres reorders keys). The leak test reads only log text appended during the test and runs the failure case through the real `queue:work --once`.

## Deviations from Plan
None of substance. Added scan self-tests for copyTo (allowed in the job only).

## Known Stubs
None.

## Threat Flags
None.

## Self-Check: PASSED
