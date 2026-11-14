---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 13
subsystem: bulk-regeneration-backend
tags: [ai, bulk, dry-run, admin, access-gate, audit]
requires: ["09-01", "09-03", "09-12"]
provides: [AiBulkEligibility, AdminAiBulkQuery, AiBulkJobQuery::cancelRunExtractions, settings.ai_agents.bulk routes]
affects: [09-14, 09-16]
key-files:
  created:
    - app/Medical/Queries/AiBulkEligibility.php
    - app/Medical/Queries/AdminAiBulkQuery.php
    - app/Medical/Queries/AiBulkJobQuery.php
    - tests/Feature/Ai/BulkRunTest.php
  modified:
    - app/Http/Controllers/Settings/AiAgentsController.php
    - routes/web/settings-ai-agents.php
    - tests/Support/SensitiveRoutes.php
    - tests/Support/EndpointMatrix/settings-ai-agents.php
    - tests/Unit/Health/MedicalMutationScanTest.php
requirements: [AI-07]
duration: ~35 min
completed: 2026-11-14
---

# Phase 9 Plan 13: Bulk planning and control backend Summary

Mandatory dry run with a stored single-use token (15 min), a one-transaction start, pause/resume/cancel and a counts-only panel, behind gated admin JSON endpoints.

## Commits
- a3a79ae feat(09-13): bulk eligibility counts, candidate pick and run cancel query
- 623ab6b feat(09-13): dry run, single-use start, pause, resume, cancel and counts-only panel
- 7e80d7e feat(09-13): gated bulk endpoints, gate entries, matrix rows and BulkRunTest

## What was built
- `AiBulkEligibility`: `counts(scope)` is one aggregate query with exclusive buckets (unsupported, consent, decided, active, eligible); `nextCandidate(scope, runId)` returns ids only, oldest first, outside the run's items and the per-document cool-down (same quota-failure rule as `AiRunQuery::busy`).
- `AdminAiBulkQuery`: `dryRun` audits first, then inserts the row. `start` locks the token `FOR UPDATE`, checks admin, unused, age, recount, inserts the run in a savepoint (23505 on `ai_bulk_runs_one_open` gives `active`), consumes the token, upserts the admin's notice ack providers (enabled providers), audits `started`. `pause`/`resume`/`cancel` return ok, conflict or missing. `panel()` derives state (paused, stopped, waiting_quota, next_batch, running) and counts from item rows in one grouped query.
- `AiBulkJobQuery::cancelRunExtractions`: queued and waiting go to cancelled(user) with `available_at` NULL in the same UPDATE, `waiting_since` untouched, call rows closed, one `MedicalAiRunFinished` audit each; the running one gets `cancel_requested_at`. Mutation-scan allowlist and pinned key list updated.
- Endpoints, routes, `index` props `bulk` and `bulkConfig`, SensitiveRoutes (POSTs in `accessGranting`, GET in `grantAllowlist`), six matrix rows. The start row's data closure creates a real dry-run token so the success role really starts a run.

## Decisions (defaults picked, none needed an owner call)
- Start with zero eligible documents is allowed (the 09-14 tick completes it at once); the contract did not forbid it.
- `cancelRunExtractions` also sets `outcome = 'cancelled'` on the run's open items it cancels, so history counts of a cancelled run are right without waiting for the tick (09-14 reconcile stays idempotent). The running item is left for reconcile.
- Pause/resume audit `count` is the number of items already handled; cancel's `count` is the number of extractions stopped.
- `nextBatchAfter` is non-null only in state `next_batch`; the next start hour is today's if before it, else tomorrow's.
- Unknown run (or non-uuid) answers 404, invalid transition 409 `{reason: conflict, panel}`.
- History `skipped` is item-derived (outcome skipped), not `skipped_count`, consistent with the "nothing increments skipped_count during the run" rule.

## Deviations from Plan
None for behavior. Test note: the "two concurrent starts" case is covered sequentially (second fresh token while a run is open gives `active`, one run row) because RefreshDatabase wraps each test in one transaction; the unique index is the arbiter and is exercised on the same 23505 path. A true two-connection race was not added.

## Verification
In project fh0913: BulkRunTest, MedicalMutationScanTest, MedicalTableScanTest, AiAgentsPageTest, SensitiveGateMatrixTest, MedicalPrivacyArchTest (77 passed), EndpointMatrixTest filtered to `ai_agents` (9 passed), plus all of tests/Unit/Health with BulkRunTest and AiAgentsPageTest (78 passed). Pint clean. Acceptance greps: content-word grep prints 0, `consumed_at` and `settings.ai_agents.bulk` entries present. Project removed with `down -v`. The full suite was not run (09-19).

## Known Stubs
None. The Vue panel using these endpoints is 09-16; the scheduler tick that fills run items is 09-14.

## Self-Check: PASSED
