---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 10
subsystem: ai
tags: [queue, quota, fallback, waiting, bulk, consent]
requires: ["09-03", "09-09"]
provides:
  - Agent fallback loop in RunExtractionJob (quota and auth fall through, everything else terminal)
  - Database waiting state with ai:resume-waiting (every minute) and a 24 h cap
  - Per-attempt ai_calls rows, producing agent on the extraction, bulk never-supersede-accepted guard
  - Provider acknowledgment filter and notice_required failure
key-files:
  created:
    - app/Console/Commands/AiResumeWaiting.php
    - tests/Feature/Ai/WaitingRunTest.php
  modified:
    - app/Medical/Ai/RunExtractionJob.php
    - app/Medical/Queries/AiJobQuery.php
    - config/ai.php
    - routes/console.php
    - tests/Feature/Ai/RunExtractionJobTest.php
    - tests/Feature/Ai/FinishReadyAtomicTest.php
    - tests/Feature/Ai/AiScheduleTest.php
    - tests/Feature/Ai/AiJobLeakTest.php
    - tests/Feature/Ai/AiPrivacyDifferentialTest.php
    - tests/Unit/Ai/ModelIdTest.php
decisions:
  - "finishReady signature is (id, payload, entryCount, pagesRead, pagesTotal, agent, model, durationMs); an extra finishedAsDecided(id) lets the job audit a bulk cancelled(decided) finish."
  - "bulkRunCancelled fails closed: a missing run or any status other than active/paused counts as cancelled (paused rows still finish)."
  - "A parked run closes any still-open call row as cancelled (an attempt that never began), so waiting rows never hold an open ai_calls row."
  - "Between attempts every non-image file is removed from the work dir, so the next agent never sees the previous agent's result or schema files."
  - "enabled agents empty -> no_agent; enabled but none acknowledged (bulk: first enabled only unless fallback_agents) -> notice_required, before any resume computation."
metrics:
  tasks: 3
  completed: 2026-11-14
---

# Phase 9 Plan 10: Quota-aware fallback loop and waiting state Summary

The Phase 8 single-agent job is now an ordered agent loop: it tries the next acknowledged, healthy, not-cooling-down agent on a usage limit or an expired login, parks the run in the database (waiting, resumed by `ai:resume-waiting`) when no agent is usable, and fails as `quota_exhausted` / `quota_timeout` past the 24 h cap.

## Tasks
1. **AiJobQuery** (commit 97986a7): claim returns `bulk_run_id`; `beginAttempt` / `closeAttempt` (one ai_calls row per attempt), `parkWaiting` (first park sets `waiting_since`), `waitingSince`, `resumeDue` (FOR UPDATE SKIP LOCKED, resets `queued_at`), `rewait`, `bulkRunCancelled`, `ackedProviders`; `finishReady` records the agent and, for bulk rows, finishes `cancelled(decided)` when the current ready proposal has an accepted decision; `reapStuck` also fails waiting rows older than cap + 5 min as `quota_timeout` (available_at NULL in the same UPDATE, waiting_since kept, audit row).
2. **Job, resumer, schedule** (commit 1c0b43f): loop per the plan contract, bulk run-time rule in `accessProblem`, acknowledgment filter re-read before every attempt, `min_call` budget check, `parkWaiting` with cap check, `ai:resume-waiting` (ai queue, or ai-bulk for bulk rows, dispatch failure -> rewait), `ai.timeouts.job` 660, schedule `ai-resume-waiting` every minute without overlapping. No `release()` anywhere.
3. **Leak tests** (commit 241e41e): canary across quota fallback + Copilot repair, parked run, Copilot session log, resumed jobs payload.

## Deviations from Plan

**1. [Rule 3 - Blocking] Phase 8 tests pinned values this plan changes.** `tests/Unit/Ai/ModelIdTest.php` (job budget 330 -> 660) and the reaper test in `AiJobLeakTest` (running threshold 450 s -> job + 120 = 780 s, so the old-running fixture is now 14 min old). Also outside `files_modified`: `AiPrivacyDifferentialTest` (and the setup helpers in `RunExtractionJobTest` / `AiJobLeakTest`) now insert the starter's `ai_notice_acks` row, because a run with no acknowledged provider now ends `notice_required` by design.
**2. [Rule 3 - Blocking] "bad outcomes" test sets `ai.timeouts.min_call` to 1.** The job now passes a deadline, so `process` = 2 s would otherwise be below the 30 s `min_call` and fail as timeout before spawning.
**3. SKIP LOCKED test is a SQL-shape assertion**, not a second-connection race: RefreshDatabase hides uncommitted rows from a second connection, so the test asserts `for update skip locked` and `limit` in the executed SQL (same precedent as ReminderEnqueueTest). No extra DB connections are opened.
**4. Interactive/bulk recheck:** "active" for the bulk admin is just exists + `is_admin` (the users table has no active flag).

## Provisional / for 09-20
C6 bulk run-time rule is PROVISIONAL owner decision A10: the starting admin needs only to exist, be admin, the run be not cancelled, and the person's consent be on (Health Edit and membership are skipped). Fallback if the owner declines: restrict bulk to persons the admin edits.

## Known Stubs
None.

## Threat Flags
None beyond the plan threat model (T-09-34..40, T-09-84 mitigated and tested).

## Verification
In project fh0910: FinishReadyAtomicTest, WaitingRunTest, RunExtractionJobTest, AiScheduleTest, AiJobLeakTest, MedicalMutationScanTest, AiArchTest plus ModelIdTest, AiPrivacyDifferentialTest, LoginHealthTest, AiCallsGuardTest, AiPhase9SchemaTest: 145 passed. Pint clean on touched files (a pre-existing style issue in `config/security.php` is untouched). Project removed with `down -v`. Full suite is left to 09-19.

## Self-Check: PASSED
Commits 97986a7, 1c0b43f, 241e41e exist; AiResumeWaiting.php and WaitingRunTest.php exist.
