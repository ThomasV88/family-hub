---
phase: 08-ai-extraction-platform
reviewed: 2026-11-14T00:00:00Z
depth: deep
files_reviewed: 62
files_reviewed_list:
  - app/Medical/Ai/AgentHealth.php
  - app/Medical/Ai/Agents/Agent.php
  - app/Medical/Ai/Agents/AgentInput.php
  - app/Medical/Ai/Agents/AgentOutcome.php
  - app/Medical/Ai/Agents/AgentRegistry.php
  - app/Medical/Ai/Agents/CodexAgent.php
  - app/Medical/Ai/CheckLoginJob.php
  - app/Medical/Ai/CodexRequest.php
  - app/Medical/Ai/CodexRunner.php
  - app/Medical/Ai/ExtractionOutcome.php
  - app/Medical/Ai/ExtractionSchema.php
  - app/Medical/Ai/ExtractionValidator.php
  - app/Medical/Ai/FailureCode.php
  - app/Medical/Ai/LoginState.php
  - app/Medical/Ai/Preflight.php
  - app/Medical/Ai/ProcessResult.php
  - app/Medical/Ai/PromptBuilder.php
  - app/Medical/Ai/ProposalEntries.php
  - app/Medical/Ai/RunExtractionJob.php
  - app/Medical/Ai/StartResult.php
  - app/Medical/Ai/Work/WorkDir.php
  - app/Medical/Documents/MedicalDocumentStore.php
  - app/Medical/Documents/PdfRasterizer.php
  - app/Medical/PlainText.php
  - app/Medical/Queries/AdminAiCallQuery.php
  - app/Medical/Queries/AiJobQuery.php
  - app/Medical/Queries/AiReviewQuery.php
  - app/Medical/Queries/AiRunQuery.php
  - app/Medical/Queries/VisitQuery.php
  - app/Http/Controllers/Health/AiReviewController.php
  - app/Http/Controllers/Health/AiRunController.php
  - app/Http/Controllers/Health/DocumentController.php
  - app/Http/Controllers/Health/RecordVerifyController.php
  - app/Http/Controllers/Settings/AiCallsController.php
  - app/Http/Requests/Health/AiDecisionRequest.php
  - app/Http/Requests/Health/AiRunRequest.php
  - app/Console/Commands/AiPreflight.php
  - app/Console/Commands/AiPruneCalls.php
  - app/Console/Commands/AiReapStuck.php
  - app/Models/AiCall.php
  - app/Models/MedicalAiExtraction.php
  - config/ai.php
  - database/migrations/2026_11_19_100000_create_medical_ai_tables.php
  - database/migrations/2026_11_19_110000_create_ai_call_tables.php
  - docker/app/Dockerfile
  - docker/ai/package.json
  - compose.yaml
  - compose.server.yaml
  - ops/env/ai-worker.env.example
  - scripts/check-compose.sh
  - scripts/deploy.sh
  - routes/web/health-ai.php
  - routes/web/health-documents.php
  - routes/web/settings-ai-calls.php
  - routes/console.php
  - resources/ai/prompt.v1.md
  - resources/ai/extraction.v1.schema.json
  - resources/js/Health/Ai/useAiStatus.js
  - resources/js/Health/Ai/EntryEditForm.vue
  - resources/js/Health/Ai/DocumentPane.vue
  - resources/js/Pages/Health/AiReview.vue
  - resources/js/Settings/AiCalls/AiCallsTable.vue
findings:
  critical: 0
  warning: 10
  info: 9
  total: 19
status: issues_found
---

# Phase 8: Code Review Report

**Reviewed:** 2026-11-14
**Depth:** deep
**Files Reviewed:** 62
**Status:** issues_found

## Summary

Phase 8 (AI extraction platform) was reviewed across the Codex runner and process containment, the job and query layers, the migrations, the compose and deploy files, and the review and polling frontend. No exploitable path was found for a hidden person, an API-key bypass, XSS, or content in a log, an `ai_calls` row, a shared prop or the jobs table. Finding counts are 0 critical, 10 warnings and 9 info.

Verified as sound:

- **Consent and access.** Consent is checked under `FOR SHARE` on the subject row at queue time, re-read at claim, re-read every 2 s while the CLI runs, and re-read after it exits. The starter's Health Edit grant and membership are rechecked every 10 s with the gate cache cleared. All AI routes authorize before validating, so a hidden, unknown or view-only target gets one 403.
- **Process containment.** `env -i` with an exact allowlist, `setsid`, group SIGKILL, argv arrays only, stdout and stderr caps, and a per-job 0700 work dir on tmpfs cleaned in `finally`. The Codex binary exists only in the `ai-runtime` stages. Only `ai-worker` mounts `codex-home`. `check-compose.sh` enforces this and that no backup script names it. Preflight fails on `APP_KEY` and on API-key variables.
- **Untrusted model output.** The validator is a strict closed-key table with a version pin. The accept path filters keys through the Phase 7 rule set, calls the Phase 7 queries with source `'ai'`, and ignores any client-supplied source or verification field. Vue renders everything through interpolation (no `v-html`).
- **Data integrity.** Decisions are protected by a primary key plus a `FOR UPDATE` on the extraction row. Start is protected by two partial unique indexes and a handled 23505. The `ai_calls` `model` set-once rule cannot fire on any current code path. `ai_calls_prune` pins `search_path` with `pg_temp` last.
- **Phase 7 repeats.** WR-03 (partial-PATCH wipe) does not recur, because accept builds the full field set and the edit form never sends verification fields. WR-09 (SQL bindings in logs) is covered by `ScrubbedQueryReport`, since all new tables match `medical_`, and the job wraps every throwable in a content-free exception. WR-10 recurs only in a weaker form: see WR-02 and WR-07.

The main concerns are a non-atomic finish, duplicate records from re-runs, an unguarded recursive delete, a tamperable "append-only" log, same-uid CLI containment, and deploy and rollback coupling.

## Warnings

### WR-01: `finishReady` is not atomic with cancel or consent, and can supersede the old proposal without producing a new one

**File:** `app/Medical/Queries/AiJobQuery.php:100-122`
**Issue:**
- `finishReady` reads `status = 'running'` without a lock (`:102`), supersedes the previous `ready` row (`:106-107`), and then updates the new row with `where status = 'running'`. The affected-row count of that second update is never checked. If the row stops being `running` between the two statements (reaper, cancel, document delete), the transaction still commits: the old proposal is superseded, no new `ready` row exists, and `closeCall` closes the call as `succeeded`. The user loses the previous proposal.
- `recheck()` in `RunExtractionJob` and the final write are separate steps. A cancel (`cancel_requested_at`) or a consent withdrawal that lands after `recheck()` but before the commit still persists a `ready` proposal. A cancel is acknowledged to the user as "running" and the result then appears anyway. For a person whose consent was just withdrawn, a proposal is created, which the checklist says must produce nothing.

**Fix:** Make the guard part of the write. `lockForUpdate` the extraction row first, and include the cancel and consent conditions in the UPDATE.
```php
$row = DB::table('medical_ai_extractions as e')
    ->join('medical_subjects as s', 's.id', '=', 'e.subject_id')
    ->where('e.id', $id)->where('e.status', 'running')
    ->whereNull('e.cancel_requested_at')->where('s.ai_consent', true)
    ->lockForUpdate()->first(['e.document_id']);
if ($row === null) { throw new LogicException('not running'); }
// ... supersede, then:
$n = DB::table('medical_ai_extractions')->where('id', $id)->where('status', 'running')->update([...]);
if ($n !== 1) { throw new LogicException('not running'); }
```
The caller then maps the `false` result to `finishCancelled`.

### WR-02: Re-running a document and accepting again duplicates records, with no per-person cap

**File:** `app/Medical/Queries/AiReviewQuery.php:117-166`, `app/Medical/Queries/AiRunQuery.php:78-81`
**Issue:**
- Decisions are keyed by `(extraction_id, entry_key)` only. A new run on the same document supersedes the old proposal, and its entries start `pending`. Accepting "Amoxicillin" from run 2 creates a second medication row next to the one from run 1. Duplicate allergies and vaccinations are also created, and duplicate vaccinations with a due date create duplicate reminders.
- Nothing bounds record creation. One proposal can hold 100 entries, runs are repeatable, and only documents have a cap. This is the same pattern as Phase 7 WR-10: a count or uniqueness rule that is not enforced under the subject lock.

**Fix:**
- In `accept`, take a `FOR UPDATE` on the subject row. Look for an existing record of the same type with the same normalized key (lower-case name plus date for vaccination, substance for allergy, name plus start date for medication). When one exists, return `state: 'duplicate'` with its id, so the user links instead of creating.
- Add a cap such as `medical.max_records_per_subject`, checked under the same lock.

### WR-03: `ai_calls_prune` has no floor, so the "append-only" log can be wiped by the app role

**File:** `database/migrations/2026_11_19_110000_create_ai_call_tables.php:94-103`
**Issue:** `fh_app` is granted `EXECUTE` on `ai_calls_prune(int)`. The function is `SECURITY DEFINER`, sets the `ai.prune` flag itself, and deletes `WHERE queued_at < now() - make_interval(months => months)`. `ai_calls_prune(0)` or a negative value therefore deletes every row, including today's, from any statement running as the app role (SQL injection, a compromised web container). The trigger guard is satisfied by the definer. This defeats the "history cannot be removed by the runtime role" intent that the REVOKE sets up.
**Fix:** Enforce a floor inside the function.
```sql
IF months IS NULL OR months < 6 THEN
  RAISE EXCEPTION 'ai_calls retention floor is 6 months';
END IF;
```
Also qualify the table as `public.ai_calls`.

### WR-04: `WorkDir::sweepStale` does a recursive delete on an unvalidated root

**File:** `app/Medical/Ai/Work/WorkDir.php:71-86`, `app/Medical/Ai/RunExtractionJob.php:71`
**Issue:**
- `sweepStale` is called from the `finally` of every job. It globs `rtrim(config('ai.work_dir'), '/') . '/*'` and `removeTree`s every directory older than 30 minutes, with no check on the root.
- `env('AI_WORK_DIR', '/ai-work')` returns `''` when the variable is present but empty. The glob then becomes `/*`, and the worker deletes every top-level directory it can write to. That includes `/codex`, which is chowned to www-data and holds the ChatGPT login (`auth.json`). A value such as `/codex` or `/var/www/html/storage` has the same effect on its subdirectories.
- `ai:preflight` rejects an empty or unwritable work dir, but it only guards container start. `sweepStale` itself has no guard.

**Fix:** Refuse unless the root is absolute, not `/`, and sits under an allowlisted prefix. Only delete entries whose basename matches the ULID pattern.
```php
if ($root === '' || $root[0] !== '/' || substr_count($root, '/') < 1 || $root === '/codex') { return 0; }
foreach (glob($root.'/*', GLOB_ONLYDIR) as $dir) {
    if (preg_match('/^[0-9a-z]{26}$/', basename($dir)) !== 1 || is_link($dir)) { continue; }
```

### WR-05: Preflight does not enforce the "no mail, Google or VAPID secrets" rule that the docs and env example claim

**File:** `app/Medical/Ai/Preflight.php:24-33`, `ops/env/ai-worker.env.example:2-3`, `compose.yaml:76`
**Issue:**
- The env example says "ai:preflight refuses to start if one is set" for `APP_KEY`, `MAIL_*`, `GOOGLE_*`, `VAPID_*` and `*_API_KEY`. The code checks only `config('app.key')` and the fixed `forbidden_env` list. `MAIL_PASSWORD`, `GOOGLE_CLIENT_SECRET` and `VAPID_PRIVATE_KEY` in `ai-worker.env` pass.
- `check-compose.sh` validates only the template. The real server file is never inspected.
- In dev, `compose.yaml:76` gives `ai-worker` `env_file: .env`, so it receives every dev secret except the blanked `APP_KEY`. Dev therefore never exercises the isolation that production relies on.

**Fix:** In `problems()`, scan `$_ENV`, `$_SERVER` and `getenv()` for `^(MAIL_|GOOGLE_|VAPID_|AWS_|STRIPE_)` and `_API_KEY$|_SECRET$`, and report `forbidden_secret`. Give the dev `ai-worker` an `ops/env/ai-worker.dev.env` instead of `.env`.

### WR-06: The CLI runs as the same uid as the PHP worker, with the whole medical volume and the worker's environment reachable

**File:** `app/Medical/Ai/CodexRunner.php:29-62,168-182`, `compose.server.yaml` (ai-worker volumes)
**Issue:** `env -i` and `setsid` do not change the uid. The Codex child is www-data, the same as `queue:work`. `--sandbox read-only` blocks writes but allows reads of the filesystem. Reachable by the child are:
- `/var/www/html/storage/app/medical`, which holds every person's documents, mounted `:ro` because PHP needs it, and readable by the same uid;
- `/proc/<php-pid>/environ`, which holds `DB_PASSWORD`;
- `/codex/auth.json`.

`features.shell_tool=false` removes the obvious reader, and the model still has an image-view tool. A prompt-injected document that gets the model to view or quote another path would put another person's data, or a secret, into a proposal shown to an editor who has no access to that person. This is not exploitable today without a path-enumeration primitive. It leaves a single configuration flag, not a boundary, between an injected document and cross-person disclosure.
**Fix:**
- Run the CLI as a dedicated uid with `/usr/bin/setpriv --reuid=<uid> --regid=<gid> --clear-groups` inside the `env -i` wrapper.
- Make the work dir group-readable only by that uid.
- Set `hidepid=2` on `/proc`, or put the worker env in a file the child cannot read.
- Prefer copying the one document in by an init step rather than mounting the whole volume where the child runs.

### WR-07: No per-user or global queue bound and no per-document cooldown, so the subscription quota can be burned

**File:** `routes/web/health-ai.php:8-9`, `app/Medical/Queries/AiRunQuery.php:78-81`
**Issue:**
- The only limits are `throttle:10,1` per user and one active run per document. Any Edit-level member can queue up to 10 runs a minute across their people and documents. Each run takes up to about 5.5 minutes on a single worker.
- A document that already has a `ready` proposal can be re-run immediately, repeatedly.
- 600 queued runs an hour is about 55 hours of backlog against the owner's flat ChatGPT quota. It also fills the `jobs` table, and it starves `CheckLoginJob` (see WR-08).

**Fix:** In `start()`, under the existing subject lock, add:
- a cap on active runs per user (for example 3);
- a global cap (for example 20 queued);
- a per-document cooldown since the last `finished_at` (for example 10 minutes);
- a daily per-person limit.

Return `'busy'` as a new `StartResult`.

### WR-08: The login check queues behind extraction jobs, so a backlog makes the app report the agent as "not responding" and blocks new runs

**File:** `app/Medical/Ai/CheckLoginJob.php:20-25`, `app/Medical/Ai/AgentHealth.php:35-37,59-62`, `config/ai.php:51`
**Issue:** `CheckLoginJob` is dispatched to the same single-concurrency `ai` queue as extractions, every 5 minutes. Three or more queued extractions (over 15 minutes of work) leave `checked_at` stale. `display()` then returns `not_responding`, `runnable()` is false, and `start()` answers `unavailable`. The admin page shows a login problem that does not exist. The state heals only when the backlog drains.
**Fix:** Do not infer health from the check's age while any extraction is `running`. For example, treat `checked_at` as fresh when `last_success_at` is recent or an active row exists. Alternatively run the check in the scheduler's own process, or give it a separate queue and a second worker with `--max-jobs=1`.

### WR-09: Rollback fails when the previous tag has no `-ai` image, and an `ai-worker` failure blocks and rolls back the whole app deploy

**File:** `scripts/deploy.sh:171-176,215,225`
**Issue:**
- `dc up -d --wait ... ai-worker` is part of the single gate that decides whether a deploy is "good", and the rollback `up` also includes `ai-worker` with `IMAGE_TAG=$good`.
- For the first Phase 8 rollout the last good tag has no `${PREFIX}-ai:<tag>` image. A failed deploy then makes the rollback `up` fail on the missing image, so compose aborts and the app stays on the broken tag.
- Independently, an optional service (an `ai:preflight` mismatch, a missing `ai-worker.env` value) can fail the deploy of unrelated app fixes and roll the whole stack back.

**Fix:** Start `ai-worker` as a second, non-gating step, for example `dc up -d --wait ai-worker || err "ai-worker unhealthy (AI disabled)"`. Exclude it from the rollback list unless `docker image inspect "$prefix-ai:$good"` succeeds.

### WR-10: The rasterizer's memory limit is far above the container memory limits, and the page route spawns three processes per request

**File:** `app/Medical/Documents/PdfRasterizer.php:104-105`, `config/ai.php:39`, `compose.server.yaml` (`mem_limit` app 192m, ai-worker 384m), `routes/web/health-documents.php:14`
**Issue:**
- `ulimit -v 786432` (768 MiB of virtual address space) is not an RSS limit, and it exceeds the app container's 192 MiB. A hostile or merely large PDF can drive `pdftoppm` into a cgroup OOM kill. In the app container that kills the php-fpm workers, and in `ai-worker` it kills the run and the queue worker.
- `GET .../pages/{page}` is allowed 120 per minute per user. Each call runs `pdfinfo` twice (the controller's `page()` calls `pageCount()` again) plus `pdftoppm`, up to 25 s of CPU each, in the web container.

**Fix:**
- Set `ulimit_kb` below the smaller `mem_limit` (for example 131072 for the app path), and take a second configurable value for the worker.
- Pass the page count into `page()` to remove the duplicate `pdfinfo`.
- Lower the route throttle to about 30/min, or cache page JPEGs encrypted, if reloading becomes an issue.

## Info

### IN-01: Group kill after the child has been reaped can hit a recycled pid

**File:** `app/Medical/Ai/CodexRunner.php:107,168-174`
**Issue:** `killGroup` is also called on the normal-exit path, after `isRunning()` has reaped the child, with `posix_kill(-$pid, SIGKILL)`. If the pid was recycled as another process group id in that interval, that group is killed. The window is tiny.
**Fix:** Sweep only when the process is still running or the exit was abnormal. For a clean exit, call `posix_kill(-$pid, 0)` first and skip the kill if it reports no such group.

### IN-02: Plaintext page images are written to `/tmp` and survive a crash

**File:** `app/Medical/Ai/Work/WorkDir.php:34-41`, `app/Medical/Documents/PdfRasterizer.php:51,68-95`
**Issue:** `makeTempDir()` uses `sys_get_temp_dir()` (container filesystem, not the tmpfs `/ai-work`). The rasterized pages of a medical document are written there and removed in `finally`. A SIGKILL, OOM or timeout leaves them on the container layer. `sweepStale` never looks in `/tmp`. The same applies to the web container for the page viewer.
**Fix:** Put the temp dirs under `config('ai.work_dir')` in the worker, and mount a size-limited tmpfs at `/tmp` for the app container. Add `/tmp/fh-*` to the sweep.

### IN-03: Auth-failure patterns are very broad

**File:** `config/ai.php:46`, `app/Medical/Ai/CodexRunner.php:76-78`
**Issue:** Substrings such as `401` and `sign in` are matched against an 8 KB stderr window. A document or log line containing "401 mg" or "sign in" can mislabel an invalid-output failure as `Auth`, which records `last_auth_failure_at` and flips the family-wide status to "expired" until the next check. The config already notes these are provisional (frozen in 08-22).
**Fix:** Match only on structured events (`type = error` with a status code) or on whole-word, anchored patterns. Require the match to appear before any model output.

### IN-04: The validator silently nulls bad dates and accepts strings longer than the record limits

**File:** `app/Medical/Ai/ExtractionValidator.php:12-14,154-165`
**Issue:**
- `date()` returns `null` for a malformed or out-of-range date instead of failing the run. A wrong date in the proposal turns into a missing date with no signal to the reviewer.
- `MAX_STRING` is 4000, while the record rules cap fields at 120-2000. Such entries pass the validator and then fail at accept with a generic form error.

**Fix:** Either fail the field (`false`) or record a per-entry `warnings` list. Align the string limits with the `VisitRequest` and `MedicationRequest` rules.

### IN-05: `ai_calls` keeps ids and person linkage after the person or document is deleted

**File:** `database/migrations/2026_11_19_110000_create_ai_call_tables.php:24-28`
**Issue:** There are no foreign keys, by design, and rows are kept for 12 months. After a person is deleted, the history still holds that person's `subject_id`, `document_id` and `started_by`, and the admin page lists the rows with a null person name. For a family that deletes a person's records, that is residual data about the person.
**Fix:** Decide the policy and document it. Either null out `subject_id` and `document_id` when a subject is deleted (an allowed definer path), or state in the privacy notice that the call log is kept for 12 months.

### IN-06: Container hardening for `ai-worker` is not applied

**File:** `compose.server.yaml` (ai-worker block)
**Issue:** The comment defers `cap_drop: ALL`, `security_opt: no-new-privileges`, `read_only: true` and a `pids_limit` to plan 08-22. This is the one container that runs an LLM CLI over untrusted documents. It is currently a normal container with a writable root filesystem and no process limit.
**Fix:** Apply them before the first production use, and extend `check-compose.sh` to assert them.

### IN-07: A failed dispatch leaves a `queued` row that blocks the document for six hours

**File:** `app/Http/Controllers/Health/AiRunController.php:25`, `app/Medical/Queries/AiRunQuery.php:89-99`
**Issue:** The row is committed, then `dispatch(...)->afterCommit()` runs. If the jobs insert fails, the response is a 500 and the `queued` row stays. The partial unique index blocks any new run on that document until the user cancels it or `queued_stale_hours` (6) passes.
**Fix:** Catch the dispatch exception and call `AiJobQuery::finishFailed`-style cleanup for a queued row, or dispatch inside the start transaction with the `database` connection.

### IN-08: The configured model name is passed to the CLI unvalidated, and the reported model has a free-text channel

**File:** `app/Medical/Ai/Agents/CodexAgent.php:77-82`, `app/Medical/Ai/CodexRunner.php:45-47,79-81`
**Issue:**
- `AI_CODEX_MODEL` goes straight into `-m`. `validModel()` is applied only to what the CLI reports. A value that starts with `-` or contains `=` is an operator-only risk, but the same regex should guard it.
- The reported model is read with `^model:\s*(\S+)` from an 8 KB stderr window. If a CLI version echoes assistant text to stderr, a prompt-injected document can set up to 60 characters of its choosing, which the admin page then shows in the model column. That is a small content path to the admin.

**Fix:** Validate the configured model with the same regex and reject a leading `-`. Take the reported model only from the first banner line (before any other output) and compare it to an allowlist or `config('ai.codex.model')`.

### IN-09: The `ai_calls` write sits inside the proposal transaction

**File:** `app/Medical/Queries/AiJobQuery.php:121,166-175`
**Issue:** `closeCall` runs inside the `finishReady` transaction. No current path can trip the `model` set-once or terminal-row trigger, because `start` and `claim` leave `model` null. But any future code that sets `model` early would make the trigger raise, roll back the ready proposal, and fall into `finishFailed`, whose own `closeCall` would raise too. The row would then be stuck `running` until the reaper. A log write is then able to destroy a successful extraction.
**Fix:** Write the call row in the same transaction but guarded (a `WHERE model IS NULL` predicate), or move it after commit with a swallowed failure. Add a test that closes a call whose `model` is already set.

---

_Reviewed: 2026-11-14_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
