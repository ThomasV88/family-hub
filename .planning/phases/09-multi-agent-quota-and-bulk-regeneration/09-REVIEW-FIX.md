---
phase: 09-multi-agent-quota-and-bulk-regeneration
fixed_at: 2026-11-14T00:00:00Z
review_path: .planning/phases/09-multi-agent-quota-and-bulk-regeneration/09-REVIEW.md
iteration: 1
findings_in_scope: 17
fixed: 15
skipped: 2
status: partial
---

# Phase 9: Code Review Fix Report

**Fixed at:** 2026-11-14
**Source review:** `.planning/phases/09-multi-agent-quota-and-bulk-regeneration/09-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 17 (CR-01, WR-01 to WR-09, IN-01 to IN-07)
- Fixed: 15 (IN-01, IN-02, IN-03, IN-06 and IN-07 by documentation only, IN-05 in part)
- Skipped: 2 (WR-02 as a product decision; the uncounted rest of IN-05 is listed below)

Each fix is one commit, listed by hash. Every fix has tests. The logic fixes (CR-01, WR-01, WR-06, WR-07, WR-08) are "fixed: requires human verification".

## Fixed Issues

### CR-01: The notice acknowledgment is a bare boolean (fixed: requires human verification)

**Files modified:** `app/Medical/Queries/AiRunQuery.php`, `app/Medical/Queries/AdminAiBulkQuery.php`, `app/Medical/Queries/AiAgentSettingsQuery.php`, `app/Medical/Ai/StartResult.php`, `app/Http/Controllers/Health/AiRunController.php`, `app/Http/Controllers/Settings/AiAgentsController.php`, `app/Http/Requests/Health/AiRunRequest.php`, `resources/js/Health/Ai/RunAiControl.vue`, `resources/js/Health/Ai/AiNoticeDialog.vue`, `resources/js/Settings/AiAgents/BulkRegenPanel.vue`, tests
**Commit:** 49ef5a1
**Applied fix:**
- The run request and the bulk Start now send `providers`, the keys the dialog named. The server reads the enabled set under `pg_advisory_xact_lock_shared('ai-agent-settings')`. Every provider change (`AiAgentSettingsQuery::change`) takes the same lock exclusively, so the set cannot change between the check and the stored acknowledgment.
- On a mismatch, or on `acknowledged` without a list, the run answers 409 `notice_required` with the current provider list and the bulk Start answers 409 `providers_changed` with it. Nothing is stored, the bulk dry-run token is not consumed, and the dialog opens again naming the current set. The stored acknowledgment is exactly the verified set.
- Vue: `AiNoticeDialog` takes `providers` and hands them back on confirm; `RunAiControl` and `BulkRegenPanel` send the shown set and adopt the set from the 409 answer.
- Tests: a provider enabled between the dialog and the confirm (HTTP and query level, both paths), no list means no acknowledgment, order and duplicates are accepted, an unknown key is 422, and two Vitest cases that re-open the dialog and send the new set. About 40 existing tests send `providers` now.

### WR-01: No run-viability gate in the bulk tick (fixed: requires human verification)

**Files modified:** `app/Medical/Queries/AiBulkJobQuery.php`, `tests/Feature/Ai/BulkTickTest.php`
**Commit:** a11e9c5
**Applied fix:** The tick ends a run whose starting admin is gone or no longer an admin (status `cancelled`, queued work cancelled, audit row). It picks agents only from the starter's acknowledged set; with no usable agent it sets `blocked_since`, which the panel shows as Stopped after the configured hours, and it resumes by itself once the acknowledgment is fixed. Not done: freezing the provider set on `ai_bulk_runs` at Start. The tick and the job still read the admin's live `ai_notice_acks` row, so a later interactive acknowledgment by the same admin can still widen what an old run may use (that would need a migration).

### WR-03: Bulk audit rows carried person and document ids

**Files modified:** `app/Medical/Queries/AiRunAudit.php` (new), `app/Audit/AuditEvent.php`, `app/Medical/Queries/AiBulkJobQuery.php`, `app/Medical/Queries/AiJobQuery.php`, `app/Medical/Ai/RunExtractionJob.php`, `app/Http/Controllers/Settings/AuditController.php`, tests
**Commit:** df4a96c
**Applied fix:** Bulk enqueue, finish, cancel and reap now write `{bulk: true}` (plus `status` for finish rows) and no ids; the run id and counts stay on `AiBulkRunChanged`. `AuditController::present()` also strips `subject` and `document` from any row marked bulk. `MedicalLangTest` was updated for the new `bulk` meta key (it asserts the exact allowlist). Audit rows written before this fix cannot be identified as bulk and still carry ids; the audit log is append-only, so they are left as they are.

### WR-04: Work-dir clean-up kept the previous attempt's credential copy

**Files modified:** `app/Medical/Ai/CopilotRunner.php`, `app/Medical/Ai/RunExtractionJob.php`, tests
**Commit:** cc4706a
**Applied fix:** `CopilotRunner` deletes its per-run home (with the login copy) and empties its temp dir as soon as the process ends, before the reply is read; the repair call seeds a fresh home. `clearAttemptFiles()` now also removes directories (such as `copilot-home`) and the contents of `tmp/`, keeping only the page images. Tests: runner home and tmp removal, a second call sees nothing from the first, and a job-level clean-up test. One existing runner test was changed because it asserted that the home still existed after the run; it now asserts that it is gone. The root cause (both credential volumes in one container under one uid) is recorded in DEFERRED.md.

### WR-05: Copilot idle timeout killed slow `--stream off` answers

**Files modified:** `app/Medical/Ai/CopilotRunner.php`, `config/ai.php`, `tests/Unit/Ai/CopilotRunnerTest.php`
**Commit:** 3d089e6
**Applied fix:** New `ai.copilot.idle_timeout` (null by default), so only the total time bound applies. Tests: a fake CLI that is silent for 3 s with a 1 s shared idle bound still answers; a configured Copilot idle bound still kills a silent run.

### WR-06: Copilot daily ceiling counted call rows (fixed: requires human verification)

**Files modified:** `app/Medical/Ai/AgentHealth.php`, `app/Medical/Queries/AiSpawnCounter.php` (new), `app/Medical/Ai/Agents/AgentRegistry.php`, `app/Medical/Ai/Agents/CopilotAgent.php`, `config/ai.php`, `database/migrations/2026_11_22_100000_add_copilot_spawn_counter_to_ai_agent_status.php` (new), tests
**Commits:** c8a9a4c, 96eac0e (the counter's raw SQL moved into a Queries class, as `MedicalTableScanTest` requires)
**Applied fix:** `ai_agent_status` gets `spawn_day` and `spawn_count`. The agent reports every process start (the repair call is a second one) through a callback; the registry increments the counter atomically. The ceiling compares spawns for the local day. New key `ai.copilot.bulk_reserve` (10): bulk candidates stop at `daily_runs - bulk_reserve`, so interactive runs keep headroom. `max_cap` was not lowered; the reserve is what protects interactive use. A new migration must be run on deploy.

### WR-07: Local-day bounds bound inconsistently (fixed: requires human verification)

**Files modified:** `app/Medical/Ai/LocalDay.php` (new), `app/Medical/Ai/AgentHealth.php`, `app/Medical/Queries/AiBulkJobQuery.php`, `app/Medical/Queries/AdminAiBulkQuery.php`, tests
**Commit:** 46535f0
**Applied fix:** One helper returns the start of the local day as a UTC instant, bound as ISO 8601 with an offset. AgentHealth, the tick and the panel all use it. The panel's "next batch" time now uses wall-clock `setTime` so a 23 or 25 hour day keeps the start hour. Tests: helper cases around local midnight, summer and winter, both DST days, and an end-to-end test that the tick and the panel count the same window on those days.

### WR-08: Quota parsing

**Files modified:** `app/Medical/Ai/QuotaSignatures.php`, `config/ai.php`, `tests/Unit/Ai/QuotaSignaturesTest.php`
**Commit:** c04c251
**Applied fix:** (fixed: requires human verification) Every parser contributes all the times it finds; stale ones are ignored, ones beyond `max_reset_days` are ignored, and the nearest plausible one wins. A printed reset never lasts longer than the new `ai.quota.max_cooldown_hours` (24). A clock time inside a full date is not a second candidate. Two existing expectations changed on purpose: a 1 day 23 h reset is now capped to 24 h, and a reset 42 h out needs a larger configured cap. Not done: requiring the signature at the start of a line, and recording hits instead of durations after the first one in a day.

### WR-09: Waiting migration `down()`

**Files modified:** `database/migrations/2026_11_21_110000_extractions_waiting_and_bulk_columns.php`, `tests/Feature/Ai/AiPhase9SchemaTest.php`
**Commit:** e118ab6
**Applied fix:** `down()` counts `decided` rows next to `waiting` rows and refuses with an explicit message before any change. Test: the refusal leaves the schema untouched and the rollback runs once the row is resolved.

### IN-04: A tick that dies between commit and dispatch

**Files modified:** `app/Medical/Queries/AiBulkJobQuery.php`, `config/ai.php`, `tests/Feature/Ai/BulkTickTest.php`
**Commit:** bfb6808
**Applied fix:** The tick closes bulk rows still queued after `ai.bulk.dispatch_grace_minutes` (60) through `failQueued`, and the next reconcile records the item as failed so the run goes on.

### IN-01, IN-02, IN-03, IN-05 (part), IN-06, IN-07: notes and cheap cleanups

**Files modified:** `docs/ops/server.md`, `config/ai.php`, `scripts/check-compose.sh`, `ops/env/ai-worker.env.example`, `resources/js/Settings/AiAgents/BulkRegenPanel.vue`
**Commit:** 6f6a399
**Applied fix:** Runbook notes for rollback (IN-01), pause semantics (IN-03), interactive latency (IN-07) and bulk retention (IN-06); a config note that `--available-tools` is unverified live (IN-02); `GH_ENTERPRISE_TOKEN` and `GITHUB_ENTERPRISE_TOKEN` are refused in config and in the compose checks, the ai-worker memory comment is corrected, and the bulk panel's start-hour fallback is now 1 (IN-05).

## Skipped Issues

### WR-02: Bulk lets a non-member admin's acknowledgment stand in for the editors of every consenting person

**File:** `app/Medical/Ai/RunExtractionJob.php:296-305`, `app/Medical/Queries/AiBulkJobQuery.php`
**Reason:** Product question, deliberately not fixed by instruction. Written up in DEFERRED.md under "Review findings left for owner decision", together with the `access:recent` re-check question, the AI calls person filter and the `/copilot` readability follow-up. The admin check that WR-01 added to the tick covers part of the second half of this finding.
**Original issue:** `ai_consent` is one boolean, not tied to a provider, so enabling a provider widens every existing consent; bulk gates only on that boolean and a single admin acknowledgment.

### IN-05 (rest) and IN-03/IN-07 behaviour

**Reason:** Only the cheap parts of IN-05 were done. Not done: the cancelled "never ran" call row keeps `started_at`, the re-wait stays a fixed minute, and `CodexRunner::scan` still scans stderr on every poll (the downstream gate is correct). IN-03 (paused runs still run already queued items) and IN-07 (no preemption) are documented, not changed. IN-06 retention is documented only; no pruning was added.

---

_Fixed: 2026-11-14_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
