---
phase: 09-multi-agent-quota-and-bulk-regeneration
reviewed: 2026-11-14T00:00:00Z
depth: deep
files_reviewed: 50
files_reviewed_list:
  - app/Medical/Ai/CopilotRunner.php
  - app/Medical/Ai/Agents/CopilotAgent.php
  - app/Medical/Ai/Agents/CodexAgent.php
  - app/Medical/Ai/CodexRunner.php
  - app/Medical/Ai/Agents/AgentRegistry.php
  - app/Medical/Ai/AgentHealth.php
  - app/Medical/Ai/CheckLoginJob.php
  - app/Medical/Ai/RunExtractionJob.php
  - app/Medical/Ai/QuotaSignatures.php
  - app/Medical/Ai/ModelId.php
  - app/Medical/Ai/JsonReply.php
  - app/Medical/Ai/Preflight.php
  - app/Medical/Ai/PromptBuilder.php
  - app/Medical/Ai/ExtractionValidator.php
  - app/Medical/Ai/Work/WorkDir.php
  - app/Medical/Queries/AiJobQuery.php
  - app/Medical/Queries/AiRunQuery.php
  - app/Medical/Queries/AiAgentSettingsQuery.php
  - app/Medical/Queries/AiBulkEligibility.php
  - app/Medical/Queries/AiBulkJobQuery.php
  - app/Medical/Queries/AdminAiBulkQuery.php
  - app/Medical/Queries/AdminAiCallQuery.php
  - app/Medical/Queries/AiReviewQuery.php
  - app/Http/Controllers/Health/AiRunController.php
  - app/Http/Requests/Health/AiRunRequest.php
  - app/Http/Controllers/Settings/AiAgentsController.php
  - app/Http/Controllers/Settings/AiCallsController.php
  - app/Http/Controllers/Settings/AuditController.php
  - app/Console/Commands/AiBulkTick.php
  - app/Console/Commands/AiResumeWaiting.php
  - app/Console/Commands/AiReapStuck.php
  - app/Console/Commands/AiPruneCalls.php
  - routes/console.php
  - routes/web/settings-ai-agents.php
  - config/ai.php
  - database/migrations/2026_11_21_100000_create_ai_agent_settings.php
  - database/migrations/2026_11_21_110000_extractions_waiting_and_bulk_columns.php
  - database/migrations/2026_11_21_120000_create_ai_bulk_tables.php
  - docker/app/Dockerfile
  - compose.server.yaml
  - compose.yaml
  - ops/env/ai-worker.env.example
  - scripts/check-compose.sh
  - scripts/deploy.sh
  - resources/js/Settings/AiAgents/useBulkStatus.js
  - resources/js/Settings/AiAgents/BulkRegenPanel.vue
  - resources/js/Health/Ai/useAiStatus.js
  - resources/js/Health/Ai/RunAiControl.vue
  - resources/js/Components/Settings/AuditTable.vue
  - tests/Support/FakeCopilot.php
findings:
  critical: 1
  warning: 9
  info: 7
  total: 17
status: issues_found
---

# Phase 9: Code Review Report

**Reviewed:** 2026-11-14
**Depth:** deep
**Files Reviewed:** 50 (the frontend was read for polling, storage, `v-html` and token handling; the remaining Vue was grepped for `v-html`, `localStorage` and `sessionStorage`)
**Status:** issues_found

## Summary

Phase 9 is mostly well built. These parts hold up against the checks in the brief:

- **Copilot runner:** `env -i` allowlist, layered `--deny-tool` flags, no `--allow-all-tools`, no token variables, per-run `COPILOT_HOME` inside a work dir that is removed in `finally` (and swept later after a crash), and a process-group kill.
- **Compose:** `copilot-home` is mounted only on the ai-worker. `check-compose.sh` enforces this for the prod and dev stacks and also checks that backups never mention it.
- **Images:** the Copilot binary is only copied into the `ai-runtime` stage, so it is absent from the web and worker images.
- **Quota matching:**
  - Copilot matches stderr only, and only after a non-zero exit with no valid output.
  - Codex matches stderr and `error` / `turn.failed` events only, and `CodexAgent` still requires a failing exit and no valid result.
  - Reset parsing is clamped on both sides, and 100000-day and garbage input fall back to the ladder.
- **Waiting state machine:**
  - The `(available_at IS NOT NULL) = (status='waiting')` CHECK is honoured on every leaving transition I traced: resume, cancel, reap, quota timeout, `cancelRunExtractions`, `rewait`, `parkWaiting`.
  - `release()` is not used, and `--tries=1` is intact.
  - The worker `--timeout` of 720 is above the job's 660, and `retry_after` of 900 is above both.
- **Bulk start and finish:**
  - The dry-run token is single-use (`FOR UPDATE` plus `consumed_at`), owner-bound and time-bound.
  - The one-open-run unique index is in place.
  - The never-supersede-accepted guard runs under the subject `FOR SHARE` lock. I traced the lock order against the accept path and found no race and no deadlock.
- **Admin surface:**
  - The call-log person is masked for bulk rows (`CASE WHEN bulk_run_id IS NULL`).
  - The person filter never matches bulk rows.
  - Settings JSON is `no-store` and carries no credential, path or CLI text.
  - The model id rule is anchored with `/D` and is passed through the Laravel `regex` rule without being split on the comma in `{0,59}`.
  - Partial PATCH does not wipe fields.
  - Audit is written before the change, inside the same transaction.
- **Repair retry:** the prompt carries only issue codes from the rule table (`ISSUE_PATTERN` plus table-derived fields), never a key name or value.

The findings below fall into four groups:

- **Acknowledgement (CR-01):** the provider acknowledgement is a bare boolean, so a provider enabled after the dialog was rendered gets acknowledged silently.
- **Bulk (WR-01, WR-02, WR-03):** the bulk path has no run-viability gate, rests on a thin consent basis for non-member admins, and leaks per-person identifiers through the admin audit page.
- **Containment and quota (WR-04, WR-05, WR-06, WR-08):**
  - The work dir keeps the previous attempt's credential copy.
  - The Copilot idle timeout is likely to misfire.
  - The Copilot daily ceiling is mis-measured and can be consumed entirely by bulk.
  - Local-day bounds are bound inconsistently.
  - One stderr substring can flip a global cooldown for up to 8 days.
- **Operations and cleanup (WR-07, WR-09, INFO):** a migration `down()` can fail on its own data, and there are smaller operational and cleanup items.

## Critical Issues

### CR-01: The notice acknowledgement is a bare boolean that acknowledges whatever is enabled at request time

**File:** `app/Medical/Queries/AiRunQuery.php:105-107,159-167`, `app/Http/Requests/Health/AiRunRequest.php:41`, `app/Medical/Queries/AdminAiBulkQuery.php:100-107`, `app/Http/Controllers/Settings/AiAgentsController.php:56-61`

**Issue:** The brief's first invariant is that a document never reaches a provider the starter has not acknowledged. The job-side enforcement is sound. `RunExtractionJob` re-reads `ackedProviders()` before every attempt and restricts candidates to that set, so fallback and resume cannot widen it.

The weak point is how the stored acknowledgement is written.

- **Interactive start:** the client sends only `acknowledged: true`. The server then stores `providers = <currently enabled set>` in `ai_notice_acks`, not the set the user actually saw in the dialog.
- **Bulk start:** it behaves the same way. The confirm dialog names `props.providers`, which is a page-load snapshot. The server stores the enabled set at the moment of Start.

The window is real, because the `ai.providers` prop lives as long as the page does. Scenario:
1. The admin enables Copilot while a member has a person page open whose dialog lists only OpenAI.
2. The member clicks "Run AI" and confirms the OpenAI-only notice.
3. `noticeAcknowledged()` is false because Copilot is now enabled, and `acknowledged` is true, so the server upserts `codex,copilot`.
4. If Codex is cooling down, the document goes to GitHub Copilot, and the member was never told.

The same TOCTOU exists inside `start()`. The enabled set is read for the ack check and again for the upsert, with no lock on `ai_agent_settings`.

**Fix:** Make the acknowledgement name what was shown, and have the server verify it against the current state.
```php
// AiRunRequest rules
'acknowledged' => ['sometimes', 'boolean'],
'providers' => ['required_with:acknowledged', 'array', 'max:5'],
'providers.*' => ['string', 'in:codex,copilot'],

// AiRunQuery::start, inside the transaction, after locking the settings rows
$enabled = $this->agents->providers();           // read once, FOR SHARE on ai_agent_settings
sort($enabled);
$shown = $shownProviders; sort($shown);
if (! $acked && (! $acknowledged || $shown !== $enabled)) {
    return new StartResult('notice_required');    // UI re-renders the dialog with the fresh list
}
// persist exactly $enabled
```
Apply the same check to `AdminAiBulkQuery::start()`. The bulk panel should send the provider list it displayed and receive 409 with the fresh list on mismatch. Take `lockForShare` on `ai_agent_settings`, or compare inside the existing transaction, so enabling a provider cannot interleave.

## Warnings

### WR-01: The bulk tick has no run-viability gate, so a lost acknowledgement or a demoted admin silently burns the run

**File:** `app/Medical/Queries/AiBulkJobQuery.php:80`, `app/Medical/Ai/RunExtractionJob.php:296-305,149-150`

**Issue:** The tick picks an agent with `candidates([], true)[0]` and passes no allowed-provider set. It also never checks that the starting admin is still an admin. The job then re-applies both rules and cancels or fails the item.

- **Reordered agents:** if the admin reorders agents after Start so that a provider outside the run's acknowledged set becomes first, every tick enqueues one document and every job fails with `notice_required`.
- **Lost admin:** if the starting admin is demoted or deleted, `accessProblem()` returns `access` for each job.

Either way the run walks the whole eligible list, marks every item `failed` or `cancelled`, and then completes. The daily cap and spacing are consumed, the documents stay unread, and the run is reported as done. It is safe from a privacy standpoint but is destructive and misleading.

Also, `ackedProviders($started_by)` reads the admin's live shared `ai_notice_acks` row. The row is not frozen per run, so an interactive acknowledgement by the same admin later widens what an old bulk run may use.

**Fix:** In `tick()`, before `enqueue`:
- Verify `User::find(started_by)?->is_admin`. If it fails, set the run to `cancelled` and audit it.
- Compute `$allowed = ackedProviders(started_by)` and call `candidates([], true, $allowed)`. If it is empty, set `blocked_since` as for a missing agent.
- Better, store `providers` on `ai_bulk_runs` at Start and use that frozen set in both the tick and the job.

### WR-02: Bulk lets a non-member admin's acknowledgement stand in for the editors of every consenting person, including for providers added after those people consented

**File:** `app/Medical/Ai/RunExtractionJob.php:296-305` (PROVISIONAL A10), `app/Medical/Queries/AiBulkJobQuery.php:154-158`, `app/Medical/Queries/AdminAiBulkQuery.php:100-107`

**Issue:** Judged on A10 as written, per-person consent as the only run-time gate is an acceptable exposure. The admin sees counts only, and the proposals go to the person's own editors.

What widens it is that `ai_consent` is a single boolean and is not tied to a provider or notice version. Enabling Copilot silently extends every existing consent to GitHub. Interactive runs are protected because an editor of that person has to acknowledge the new provider. Bulk is not: one admin acknowledgement at Start releases all consenting people's medical documents to whichever provider is first in the order.

Also, no per-person gate prevents the admin's Health module from being `none` or non-recent. `access:recent` is checked only at Start, and the tick runs for days afterwards.

**Fix:**
- Record the provider set (or a notice version) on the consent row. For bulk, process a person only if that person's consent covers the provider that would run.
- Or restrict bulk to providers that were enabled when the person consented.
- Whichever you pick, document the choice in the A10 decision, and re-check `is_admin` and the Health module per item (see WR-01).

### WR-03: Bulk runs write person and document UUIDs and per-item timing into the audit log, which the admin audit page lists

**File:** `app/Medical/Queries/AiBulkJobQuery.php:186-187`, `app/Medical/Ai/RunExtractionJob.php:321-331`, `app/Medical/Queries/AiBulkJobQuery.php:225-227`, `app/Medical/Queries/AiJobQuery.php:271-279`, `app/Http/Controllers/Settings/AuditController.php:62-77`

**Issue:** The brief requires admins to see counts only for bulk rows. Every bulk enqueue writes `MedicalAiRunRequested` with `{subject, document}` under the admin actor. Every finish writes `MedicalAiRunFinished` with `{subject, document, status}`. `AuditEvent::metaKeys()` allows both keys, and `AuditController::present()` passes them to the Inertia props of `Settings/Audit`, which the admin can filter by member.

The admin therefore receives a per-person UUID, per-document UUIDs, timestamps and outcomes for people they may not be a member of. The call log masks the person but the audit page does not. There are no names, but UUIDs can be grouped and counted, and they identify people the admin does belong to.

**Fix:** For bulk rows write a run-level event only. `AiBulkRunChanged` already does this. Either skip the per-item `MedicalAiRunRequested` / `MedicalAiRunFinished` audit rows or log them with `subject` and `document` omitted and `bulk => true`. If the per-item rows must exist for forensics, add a `bulk` flag to the meta and have `AuditController::present()` strip `subject` and `document` when it is set. Apply the same change in `reapStuck` and `cancelRunExtractions`.

### WR-04: Work-dir clean-up between attempts removes files only, so the credential copy and session state survive into the next attempt and the repair call

**File:** `app/Medical/Ai/RunExtractionJob.php:263-271`, `app/Medical/Ai/CopilotRunner.php:182-204`

**Issue:** `clearAttemptFiles()` unlinks `is_file()` entries only. `copilot-home/` (the full copy of the GitHub login `config.json`, `settings.json`, `logs/`, and any session state) and `tmp/` stay in the work dir. Codex runs with its cwd set to the work dir.

- **Copilot before Codex:** a fallback from Copilot to Codex therefore leaves the Copilot token copy in the directory the next agent can read. Codex is the agent that sees an untrusted document and has a read-only but whole-filesystem-readable sandbox.
- **Repair call:** the repair call also reuses the first call's `copilot-home`.

The brief asks that the work dir never expose the previous agent's result and that credentials do not persist. In addition, `/copilot` is mounted in the same container as `/codex`, so a prompt-injected Codex run can read the Copilot token directly, and its output (free-text entry fields) lands in a proposal visible to the family. The container layout, not this code, is the root cause, but Phase 9 introduced the second credential.

**Fix:**
- Make `clearAttemptFiles()` recursive and delete `copilot-home` and `tmp/*`. In `CopilotRunner`, remove the per-run home in a `finally` immediately after the process ends, before the reply is parsed.
- Ideally place the per-run home outside the work dir that Codex receives.
- For the mount issue, consider a separate container per provider, or confine Codex to the work dir.

### WR-05: Copilot runs with `--stream off` and a 120 s idle timeout, so a slow single response is killed as `idle_timeout`

**File:** `app/Medical/Ai/CopilotRunner.php:35,52-53`, `config/ai.php:45`

**Issue:** With `--stream off` and `--log-level none`, the CLI emits no stdout and no stderr until the final answer. `setIdleTimeout(120)` measures time without output. A ten-page image extraction can easily take longer than two minutes to answer.

- **Terminal failure:** the run fails as `idle_timeout` (terminal, no fallback, no repair).
- **Cooldown:** `idle_timeout` is also in `QUOTA_FAILURES`, so the document gets a 10-minute re-run cooldown.
- **Cost:** a quota-costing call is wasted.

The values are marked ASSUMED, but this is a structural mismatch, not a tuning issue.

**Fix:** For the Copilot runner set the idle timeout equal to the process timeout (or drop it). Alternatively stream and discard stdout instead of buffering it. Add a Copilot-specific `ai.copilot.idle_timeout`.

### WR-06: The Copilot daily ceiling counts call rows, not spawns, and bulk can consume all of it

**File:** `app/Medical/Ai/AgentHealth.php:72-86`, `config/ai.php:29,126-137`

**Issue:** The ceiling is meant to be an AUP guard on automated bulk use. It counts `ai_calls` rows with status `succeeded` or `failed`, with these consequences:
- **Repair spawns:** one row can cover two CLI spawns (the repair call), so spawns can be up to double the ceiling.
- **Uncounted spawns:** attempts that spawned and were cancelled or aborted are not counted.
- **Counted non-spawns:** attempts that failed before spawning are counted.
- **Starvation:** `max_cap` is 30 and `daily_runs` is 30. A single bulk run with Copilot first in order can use the entire Copilot day, and the gate has no reserved headroom for interactive runs. The brief names this headroom gate explicitly.

**Fix:** Count spawns, for example with a small counter incremented in `CopilotRunner::exec`. Reserve interactive headroom: bulk candidates should use `daily_runs - reserve`, for instance `dailyCeilingReached($agent, $reserve)` when `$bulk` is true. Lower `max_cap` below `daily_runs`.

### WR-07: Local-day bounds are bound inconsistently, so the panel and the gate disagree

**File:** `app/Medical/Ai/AgentHealth.php:78`, `app/Medical/Queries/AiBulkJobQuery.php:66-68`, `app/Medical/Queries/AdminAiBulkQuery.php:273`

**Issue:** `AdminAiBulkQuery::activeBlock` binds `$local->startOfDay()->utc()`. `AgentHealth::dailyCeilingReached` and `AiBulkJobQuery::tick` bind a Bratislava-zoned `CarbonImmutable` directly. Laravel formats a `DateTimeInterface` binding as a naive `Y-m-d H:i:s` in the object's own zone, so the same instant is sent as two different wall-clock values.

Both cannot be correct against the same timestamptz column. The effect is a daily cap and a Copilot ceiling whose window is skewed by one to two hours, and a UI `todayUsed` that disagrees with what the tick enforces. This can let an extra batch through, or leave the UI saying "next batch" while the tick enqueues. I did not run it; which side is wrong depends on the connection time zone.

**Fix:** Bind UTC instants everywhere (`->utc()` or `->toIso8601String()`), and add a test around the DST boundary. Put the day-start computation in one helper.

### WR-08: One stderr substring flips a global cooldown for up to 8 days, and the reset parser's first match wins

**File:** `app/Medical/Ai/QuotaSignatures.php:20-31,40-51`, `config/ai.php:120`, `app/Medical/Ai/CopilotRunner.php:126-128`

**Issue:** The gating is correct: stderr only, non-zero exit, no valid output, and bounds on the reset time. But a single substring match anywhere in an 8 KiB stderr window sets a cooldown that affects every family member's runs, for up to `max_reset_days` (8) days. Two further problems:
- **First parser wins:** `parseReset` uses `??` across parsers, so the first parser that returns anything wins, even when it returns a past time. Any stale ISO timestamp in a log line before a valid `in 3 hours` sentence makes the candidate null (it fails the `lt(now+1min)` test), so the ladder applies instead of the printed reset time.
- **Residual spoofing:** the match is not anchored to a CLI line. Anything on stderr that echoes model text, such as a denied tool-call notice, could carry a signature. This is not proven, but the brief calls for defence against exactly this, and the effect is a global denial of service.

**Fix:**
- Cap the effective cooldown to 24 h (the wait cap) unless the match is anchored at line start.
- Choose the parse result closest to the signature rather than the first parser.
- Require the signature at the start of a line.
- Record hits, not durations, beyond the first one in a day.

### WR-09: The `down()` of the waiting migration fails on its own data

**File:** `database/migrations/2026_11_21_110000_extractions_waiting_and_bulk_columns.php:33-60`

**Issue:** `down()` refuses to run only when `waiting` rows exist. It then re-adds the narrower `cancel_reason` CHECK (`user`, `consent`, `access`), but `decided` rows (bulk-only) are not checked or rewritten. The comment at line 52 says they are reported, not rewritten, but nothing reports them. `ADD CONSTRAINT` fails with an opaque constraint violation partway through, which aborts the whole rollback.

**Fix:** Count `cancel_reason = 'decided'` at the top and refuse with an explicit message like the `waiting` guard. Alternatively rewrite them to `user` and document that. Also refuse or document the open `ai_bulk_runs` rows.

## Info

### IN-01: A deploy rollback leaves `waiting` rows that nothing resumes or reaps

**File:** `scripts/deploy.sh:227-244`, `database/migrations/2026_11_21_110000_extractions_waiting_and_bulk_columns.php:12-31`

**Issue:** Rolling back to a Phase 8 image keeps the migrated schema. A `waiting` row is then never resumed or reaped by the old code, and the new `one_active` index (which includes `waiting`) blocks any new run on those documents.

**Fix:** Add a runbook step in `docs/ops/server.md` and a rollback hook that fails or cancels `waiting` rows before the old image starts.

### IN-02: `--available-tools` is passed without a value and the containment claim depends on the live CLI

**File:** `app/Medical/Ai/CopilotRunner.php:36`

**Issue:** The empty variadic flag is meant to mean "no tools". If a CLI version treats empty as unset, only the `--deny-tool` flags apply. The fake CLI in `tests/Support/FakeCopilot.php` cannot prove either behaviour.

**Fix:** Pin this behaviour in the live-check list for the Copilot version in `config/ai.php`, and re-check on every version bump. The Dockerfile hardcodes `1.0.112`, so keep a single source for the version.

### IN-03: The bulk panel shows "running" while paused work continues

**File:** `app/Medical/Ai/RunExtractionJob.php:296-305`, `app/Console/Commands/AiResumeWaiting.php`, `app/Medical/Queries/AiJobQuery.php:404-413`

**Issue:** `bulkRunCancelled()` treats `paused` as live. Already-enqueued items, and waiting bulk rows that `resumeDue()` puts back in the queue, keep sending documents to the provider while the run is paused.

**Fix:** Either document that pause only stops new enqueues, or have the job park or skip when the run is paused.

### IN-04: A tick that dies between commit and dispatch leaves a queued row until the 6 h reaper

**File:** `app/Console/Commands/AiBulkTick.php:24-31`, `config/ai.php:51`

**Issue:** If the process is killed after the tick commits and before `dispatch` runs, the row is `queued` with no job. The open item blocks the run for `queued_stale_hours` (6 h).

**Fix:** In `tick()`, re-dispatch (or fail) items whose extraction is `queued` and older than a few minutes. This is the same reconcile path.

### IN-05: Smaller cleanup items

- **Stale memory comment:** `config/ai.php:68` says the ai-worker is 384m, while the compose files default `MEM_AI` to 768m. The 192m `/ai-work` tmpfs counts against that cgroup alongside a Copilot or Codex process and PHP. Please state the budget once.
- **Forbidden env names:** `config/ai.php:77-83` omits `GH_ENTERPRISE_TOKEN` and `GITHUB_ENTERPRISE_TOKEN`. They are harmless behind `env -i` but should be refused too.
- **Hardcoded fallback:** `BulkRegenPanel.vue` falls back to a start hour of 2 while the config is 1.
- **Cancelled call row:** `AiJobQuery::beginAttempt` leaves `started_at` set on the cancelled "never ran" row after a fallback (set by `claim`), which looks like a run in the admin log.
- **Fixed re-wait:** `rewait` is a fixed one minute with no backoff.
- **Codex stderr scan:** `CodexRunner::scan` scans stderr for quota signatures on every poll, not only after exit. The gate is downstream in `CodexAgent`, so the behaviour is correct but fragile. Align it with the Copilot runner.

### IN-06: No retention for bulk bookkeeping

**File:** `database/migrations/2026_11_21_120000_create_ai_bulk_tables.php`, `app/Console/Commands/AiPruneCalls.php`

**Issue:** `ai_bulk_run_items` keeps document UUIDs indefinitely and `ai_bulk_runs` has no pruning. `ai_calls` is pruned at 12 months, so the table sets disagree.

**Fix:** Prune completed run items and runs alongside `ai:prune-calls`, with the same floor of 12 months.

### IN-07: Interactive latency behind a long bulk job

**File:** `compose.server.yaml:111`

**Issue:** The queue order `ai-control,ai,ai-bulk` correctly favours interactive work. The single worker has no preemption, so an interactive run can wait behind one bulk job for up to the 11-minute job budget. This is acceptable but should be stated in the runbook.

**Fix:** Document the worst-case wait, or add a bulk-free window when interactive demand is high.

---

_Reviewed: 2026-11-14_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
