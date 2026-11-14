---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 14
subsystem: bulk-regeneration-scheduler
tags: [ai, bulk, scheduler, queue, audit]
requires: ["09-10", "09-13"]
provides: [AiBulkJobQuery::tick, ai:bulk-tick, ai-bulk-tick schedule]
key-files:
  created:
    - app/Console/Commands/AiBulkTick.php
    - tests/Feature/Ai/BulkTickTest.php
  modified:
    - app/Medical/Queries/AiBulkJobQuery.php
    - routes/console.php
    - tests/Feature/Ai/AiScheduleTest.php
requirements: [AI-07]
completed: 2026-11-14
---

# Phase 9 Plan 14: Bulk scheduler tick Summary

One tick every five minutes reconciles items, completes the run, applies every pacing gate and enqueues at most one document on the `ai-bulk` queue with the same audit trail as an interactive run.

## Commits
- 81f75da feat(09-14): bulk tick with reconcile, completion, pacing gates, command and schedule

## What was built
- `AiBulkJobQuery::tick(now)`: one transaction under `pg_advisory_xact_lock(hashtext('ai-bulk-tick'))`. Prunes dry runs older than a day, reconciles open items (ready/superseded read, failed, cancelled(decided) skipped, other cancelled, missing row gone), then handles the single active run.
- Completion rule (revised): every count comes from item rows by outcome, so each skip is counted once. The run completes when `nextCandidate` is null and no item is open, checked before the pacing gates. At completion `skipped_count` is written once as skipped items plus `max(0, eligible_total - items)` (documents that dropped out after the dry run). A paused run is never completed. Audit `AiBulkRunChanged {completed}` with the run admin as actor.
- Gates: local hour >= start hour, items today < cap, no open item, spacing, global active < `max_global_active`, a bulk candidate agent. Only the agent gate failing sets `blocked_since`; an enqueue clears it.
- Enqueue: person row `FOR SHARE`, recheck of kind, consent and accepted decision, then extraction (queued, `bulk_run_id`, `started_by` = admin, first bulk agent), attempt-1 `ai_calls` row, item row, `MedicalAiRunRequested` audit, `last_enqueued_at`. A race since the pick or a `23505` on `one_active` inserts a skipped item only (savepoint, so the tick transaction survives).
- `ai:bulk-tick`: dispatches `RunExtractionJob` on `ai.bulk.queue`; a dispatch exception calls `failQueued` (item reconciles to failed); prints `enqueued=N reconciled=N completed=N`.
- Schedule `ai-bulk-tick` every five minutes, `withoutOverlapping`.

## Decisions (defaults picked)
- Daily cap counts all items of the run enqueued since local midnight, including race-skipped ones (same definition as the panel's `todayUsed`). `enqueued_at` is written explicitly from the tick time (not DB `now()`), so time-travel tests and the cap agree.
- Test seam `AiBulkJobQuery::$afterPick` (static closure, null in production) opens the race window between pick and person lock.
- Global headroom counts queued, running and waiting extractions, the same set as the interactive global limit.
- Concurrency test: RefreshDatabase wraps the test in one transaction, so a real two-tick race is not reproducible. Instead the test verifies a second tick is a no-op and that a second connection (`btlock`, purged in afterEach) sees the advisory lock held.

## Deviations from Plan
None for behavior. Pint reordered imports in the test file only.

## Observation for a later plan (not changed here)
`AiJobQuery::hasAcceptedDecision` (09-10 finishReady bulk guard) only looks at accepted decisions on `ready` extractions, while eligibility and the tick recheck look at any extraction of the document. A decision that lives only on a superseded row would be caught at pick time but not by the job guard if it appeared after enqueue. Existing 09-10 tests rely on the current behavior, so I left it.

## Verification
Project fh0914: BulkTickTest, AiScheduleTest, MedicalMutationScanTest (31 passed), then tests/Feature/Ai, tests/Unit/Ai, tests/Unit/Arch and the mutation scan (434 passed). Pint clean. Project removed with `down -v`. No record table changes in any case (row counts asserted). Full suite is 09-19.

## Known Stubs
None.

## Self-Check: PASSED
