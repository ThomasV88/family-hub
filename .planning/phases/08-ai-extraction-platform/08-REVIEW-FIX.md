---
phase: 08-ai-extraction-platform
fixed_at: 2026-11-14T00:00:00Z
review_path: .planning/phases/08-ai-extraction-platform/08-REVIEW.md
iteration: 1
findings_in_scope: 9
fixed: 9
skipped: 0
status: all_fixed
---

# Phase 8: Code Review Fix Report

**Fixed at:** 2026-11-14
**Source review:** .planning/phases/08-ai-extraction-platform/08-REVIEW.md
**Iteration:** 1

**Summary:**
- Requested warnings: WR-01, WR-02, WR-03, WR-04, WR-05, WR-07, WR-08, WR-09, WR-10 (9), all fixed
- WR-06 was not attempted (see Not attempted)
- Info items fixed: IN-02, IN-07, IN-08, IN-09. Left for the live session: IN-01, IN-03, IN-04, IN-05, IN-06
- Note: the fixer ran in the main working tree, not a separate git worktree, because the Pest containers mount the repo and the caller was waiting synchronously. All commits are on `main`, one per finding, nothing pushed.

## Fixed Issues

### WR-01: `finishReady` not atomic with cancel and consent
**Files modified:** `app/Medical/Queries/AiJobQuery.php`, `app/Medical/Ai/RunExtractionJob.php`, `tests/Feature/Ai/FinishReadyAtomicTest.php`, `tests/Feature/Ai/RunExtractionJobTest.php`
**Commit:** 0dfa62a
**Applied fix:** The extraction row is locked `FOR UPDATE` and must be `running`, not cancel-requested; the subject's `ai_consent` is read `FOR SHARE` (serializes with a consent change). The row count of the final update is checked and a mismatch rolls back. The old proposal is superseded only after all of that. The job maps a `false` result to `finishCancelled` (reason from `abortReason`, else `user`) and supersedes nothing. `AiJobQuery` lost `final` so the test can inject a cancel between the last recheck and the write. Requires human verification: lock-order and race logic.

### IN-09: call-log write inside the proposal transaction (done with WR-01)
**Commit:** 0dfa62a
**Applied fix:** `closeCall` now runs in a savepoint and swallows a `QueryException`, so a refused call-log row can never undo a ready proposal. Test closes a call whose `model` is already set.

### WR-02: re-run and accept again duplicates records
**Files modified:** `app/Medical/Queries/AiReviewQuery.php`, `config/medical.php`, `config/ai.php`, `tests/Feature/Health/AiAcceptTest.php`
**Commit:** 748f89c
**Applied fix:** Accept takes `FOR UPDATE` on the subject row, then looks for an existing record of the same type with equal content (case-insensitive, null-safe: visit date+doctor+specialty+diagnosis, medication name+dose+start, allergy substance+reaction, vaccination vaccine+given+next due). A match is linked as the accepted record (same `accepted` state and `recordId`), so nothing is inserted and no second vaccination reminder is scheduled; one decision per entry is kept. Caps under the same lock: `ai.max_accepts_per_proposal` (100) and `medical.max_records_per_subject` (1000), refused as a 422 `record_limit` general error. Requires human verification: the match keys are a product judgement (a different dose is a different medication).

### WR-03: `ai_calls_prune` has no floor
**Files modified:** `database/migrations/2026_11_20_100000_add_ai_calls_prune_floor.php`, `app/Console/Commands/AiPruneCalls.php`, `tests/Feature/Ai/AiCallsGuardTest.php`
**Commit:** 0f6c4ad
**Applied fix:** New migration (the old one had shipped) `CREATE OR REPLACE` with `RAISE EXCEPTION` below 12 months or NULL, `public.ai_calls` qualified, `search_path` pinned (`public, pg_temp`), grants kept. The command clamps the config value to at least 12. Tests run as the real `fh_app` role: 0, -5, 11 and NULL are refused and nothing is deleted.

### WR-04: `WorkDir::sweepStale` on an unvalidated root
**Files modified:** `app/Medical/Ai/Work/WorkDir.php`, `tests/Unit/Ai/WorkDirTest.php`
**Commit:** 4e3a913
**Applied fix:** New `WorkDir::root()`: absolute, no dot segments, not `/`, not `/codex`, not a parent of the Codex home. `create()` and `sweepStale()` refuse an unsafe root. The sweep deletes only direct children named like a job dir (`^[0-9a-z]{26}$`) and never follows links. Tests cover empty, blank, `/`, `//`, relative, dotted, `/codex`, a Codex parent, foreign names and symlinks.

### WR-05: Preflight does not enforce the no-secrets rule
**Files modified:** `app/Medical/Ai/Preflight.php`, `config/ai.php`, `compose.yaml`, `ops/env/ai-worker.dev.env` (new), `ops/env/ai-worker.env.example`, `scripts/check-compose.sh`, `docs/ops/server.md`, `tests/Unit/Ai/PreflightTest.php`
**Commit:** 7b2dd9f
**Applied fix:** `Preflight::secretsClean()` matches variable names against `ai.forbidden_env_patterns` (`MAIL_`, `GOOGLE_`, `VAPID_`, `AWS_`, `STRIPE_`, `*_API_KEY`, `*_SECRET`, `APP_PREVIOUS_KEYS`) in `getenv`, `$_ENV` and `$_SERVER` and reports `forbidden_secret` (code only; non-empty values only). `APP_KEY` stays covered through `config('app.key')`. The work-dir check uses `WorkDir::root()`. The dev `ai-worker` now reads `ops/env/ai-worker.dev.env` instead of `.env`, and the repo `.env` that the bind mount would expose is masked with `/dev/null`. Verified in a container that the dev env has no `APP_KEY`/`MAIL_*` and `secretsClean()` is true. `check-compose.sh` now also checks the dev `ai-worker` (profile `ai`).

### WR-07: no queue bound or per-document cool-down
**Files modified:** `app/Medical/Queries/AiRunQuery.php`, `app/Medical/Ai/StartResult.php`, `config/ai.php`, `tests/Feature/Health/AiRunTest.php`
**Commit:** 62bc6e6 (refined in 846b403)
**Applied fix:** `ai.limits`: 3 active runs per user, 20 active globally, 10 minute cool-down per document. Checked in `start()` after the active check and before any write, under a transaction advisory lock so the counts hold across people. Refusal is `StartResult('busy')`, HTTP 409 reason `busy`; the UI treats an unknown reason as its generic failure message (no new copy). The cool-down counts only runs that used quota (ready, superseded, or failed after the CLI ran), not cancelled, worker_lost, unreadable or preflight failures. The review's daily per-person limit was not added.

### WR-08: login check queues behind extractions
**Files modified:** `app/Medical/Ai/CheckLoginJob.php`, `config/ai.php`, `compose.yaml`, `compose.server.yaml`, `scripts/check-compose.sh`, `docs/ops/server.md`, `tests/Feature/Ai/LoginHealthTest.php`, `tests/Feature/Ai/AiScheduleTest.php`
**Commit:** be65b8c
**Applied fix:** `CheckLoginJob` uses `ai.control_queue` (`ai-control`). Both compose files run `queue:work --queue=ai-control,ai`, so the check is picked first after every job and staleness is bounded by one job (about 5.5 minutes, below the 15 minute window). `check-compose.sh` requires the order. Docs updated.

### WR-09: rollback and deploy coupling to ai-worker
**Files modified:** `scripts/deploy.sh`, `scripts/check-compose.sh`, `scripts/test-deploy-guards.sh`, `docs/ops/server.md`
**Commit:** d482b93
**Applied fix:** The gate and rollback `up` lists are `app worker scheduler nginx`. The ai-worker starts afterwards in a non-gating step (failure logs a warning, deploy exits 0). The ai image pull and the `SKIP_PULL` inspect are non-fatal. Rollback restarts the ai-worker only if `ai_image_available <good tag>`, otherwise stops it. `check-compose.sh` fails if the ai-worker is in a gate or rollback list (verified with a negative test) and requires the separate start; `test-deploy-guards.sh` gained cases (25 pass), including `ai_image_available` with a fake `docker`. Requires human verification: the real deploy flow was not run (no server access).

### WR-10: rasterizer memory limit and page route cost
**Files modified:** `app/Medical/Documents/PdfRasterizer.php`, `config/ai.php`, `routes/web/health-documents.php`, `tests/Unit/Ai/PdfRasterizerTest.php`
**Commit:** 4ac8429
**Applied fix:** `ulimit -v` is 131072 KB for the web path (container 192m) and 262144 KB for the worker path (384m), via `ai.pdf.ulimit_kb` and `ai.pdf.ulimit_kb_worker`. `page()` no longer runs `pdfinfo` (a page past the end makes `pdftoppm` exit non-zero, same null), so the route spawns one `pdftoppm`. Route throttle 120 to 60 per minute. Caveat: virtual limits were only exercised on small test PDFs; tune on staging.

### IN-07: failed dispatch leaves a blocking queued row
**Files modified:** `app/Http/Controllers/Health/AiRunController.php`, `app/Medical/Queries/AiJobQuery.php`, `app/Medical/Queries/AiRunQuery.php`, `tests/Feature/Health/AiRunTest.php`
**Commit:** 846b403
**Applied fix:** The controller dispatches through `Bus::dispatch` in a try block; on failure `AiJobQuery::failQueued` closes the queued row and its call row (failed, worker_lost) and the exception is rethrown. Test mocks a failing dispatcher.

### IN-08: unvalidated model name
**Files modified:** `app/Medical/Ai/CodexRunner.php`, `app/Medical/Ai/Agents/CodexAgent.php`, `app/Medical/Ai/Preflight.php`, tests
**Commit:** c95523d
**Applied fix:** `CodexRunner::isValidModel` (must start with a letter or digit, max 60 chars). The configured model is dropped to the default if invalid (preflight reports `model_invalid`). When a model is configured, that name is recorded, never text the CLI echoed.

### IN-02: plaintext page images left in /tmp after a crash
**Files modified:** `app/Medical/Ai/Work/WorkDir.php`, `tests/Unit/Ai/WorkDirTest.php`
**Commit:** dc253fc
**Applied fix:** `sweepStale` also removes `fh-<ulid>` scratch dirs under the system temp dir older than the cutoff (the worker runs the sweep after every job). Mounting a tmpfs `/tmp` for the app container was not done.

### Test hygiene
**Commit:** d444a96
`AiCallsPageTest` follows the `ai-control` queue. `AiCallsGuardTest` now purges its extra DB connections after each test: the dev Postgres has `max_connections=20` and the added tests made the full suite exhaust it ("remaining connection slots are reserved").

## Not attempted

### WR-06: CLI under a dedicated uid
**Reason:** Not safe to do and test here. The worker runs unprivileged, so `setpriv` needs `CAP_SETUID` through a file capability, which conflicts with `no-new-privileges` (IN-06), and it needs a root-capable container to test. Written up with the exact approach in `.planning/phases/08-ai-extraction-platform/DEFERRED.md` (committed ae7d875), together with IN-01, IN-03, IN-04, IN-05, IN-06 and the live tuning notes.

---

_Fixed: 2026-11-14_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
