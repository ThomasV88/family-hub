# Phase 8: AI Extraction Platform - Research

**Researched:** 2026-11-14
**Domain:** Running the Codex CLI (ChatGPT subscription login) headless from a Laravel 13 database-queue worker in a locked-down container: process supervision from PHP, versioned JSON proposal validated server-side, review/accept flow through the existing Phase 7 scoped queries, append-only call log, admin login health.
**Confidence:** HIGH for everything that rests on installed code, the installed image and the experiments run in throwaway containers this session (tagged `[VERIFIED: ...]`). MEDIUM for Codex behaviour taken from official docs (`[CITED: ...]`) but not exercised with a real login. Anything that needs the live ChatGPT login is tagged `[ASSUMED]` and collected in the Assumptions Log and in "Live-session verification checklist".

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

#### What the AI extracts and how a proposal is shaped
- Extraction produces lists of proposed visits, medications, allergies and vaccinations (the Phase 7 record types), plus optional document date and specialty. No lab values, no free-form diagnosis beyond the existing visit fields.
- One JSON Schema with `schema_version`, passed to `codex exec --output-schema`. The server validates again and stores the schema version on the proposal. Failed validation = `failed` status, never a partial proposal.
- One `ai_extractions` row per run (status, schema version, payload JSON, document, person, agent, model). Never written to record tables until accepted. A re-run creates a new row and marks the previous one superseded.
- The user accepts, edits or rejects each proposed entry individually. Accepted entries are created through the normal Phase 7 record queries (same scoping, validation, audit) with AI-extracted provenance, carrying the Phase 7 "unverified" label rules until a member verifies them.

#### Worker, Codex CLI and containment
- Separate `ai-worker` container with its own `ai` queue, non-root user, holding only the writable `codex-home` volume (refreshed `auth.json`) and read access to medical documents. The normal `worker` never gets CLI credentials; `ai-worker` gets no app secrets beyond queue/database access it needs.
- `codex exec --sandbox read-only --ephemeral --output-schema ... -o ...` in a fresh empty temp dir per job; document copy placed there, PDFs rasterized with `pdftoppm`, dir deleted afterwards. Success = output file exists and passes server-side validation, never exit code alone. Timeout kills the process group.
- Worker refuses to start if `OPENAI_API_KEY`, `CODEX_API_KEY` or `ANTHROPIC_API_KEY` is set (subscription-only cost rule). The prompt marks document content as untrusted data. No network tools, no MCP. Model output is data only and never reaches a shell or direct database write.
- Admin-visible login health via `codex login status` ("logged in / login expired"). One agent in Phase 8; an agent registry interface is built so Phase 9 adds Copilot, ordering and quota fallback without rework.

#### Review screen and the call log
- Members who can Edit the person start extraction, only if the person's AI-consent flag is on, via an explicit "Run AI" button per document with a one-time notice that the document leaves the server for OpenAI. Consent is checked server-side at queue time and again when the job runs (turned off in between cancels the job).
- Review is side by side: document preview on one side, proposed entries on the other with accept / edit / reject; stacked with a toggle on phones. Nothing is saved until a user acts. Status (queued, running, ready, failed) shows on the document.
- Append-only `ai_calls` table: who started it, person, document, agent, model, status, duration, time. No document text, prompt or result content. A content-free audit row is also written.
- Admin-only "AI calls" section in Settings, filterable by person, member and status; admins see person names only there (Phase 7 rule). Rows older than 12 months are pruned.

### Claude's Discretion
Table and class naming, exact JSON Schema field names, prompt wording (must be SK/PL/EN-document tolerant, output in the source language), polling vs. push for status updates, temp dir location, UI details within the approved Phase 7 design system.

### Deferred Ideas (OUT OF SCOPE)
- GitHub Copilot CLI, agent order, per-agent model setting, quota fallback and waiting: Phase 9.
- Bulk re-generation with dry run and daily cap: Phase 9.
- Lab value extraction and trend graphs: out of scope for v1.
- Real Codex login, live AI test on staging, data-controls/training check for the ChatGPT plan: deferred live plan at the end.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AI-01 | User can run AI extraction on a document on demand; the AI returns data in an exact JSON structure through a prepared prompt | Q1 (exact `codex exec` invocation, stdin prompt, `--output-schema`), Q4 (schema v1 + OpenAI strict-mode constraints), Q7 (queue-time and run-time consent, unique active run) |
| AI-02 | Results are validated against a versioned schema and saved as a proposal, not applied, until a user reviews and accepts or edits them side by side with the document | Q4 (server revalidation, injection-safe handling), Q5 (PDF page derivative for the side-by-side pane), Q6 (`medical_ai_extractions` + decisions), Q8 (atomic idempotent accept through Phase 7 queries with `source='ai'`) |
| AI-03 | Every call to an external AI is recorded (who, document, agent, model, time) | Q6 (`ai_calls` with lifecycle-guard trigger, verified on Postgres 18), Q9 (admin list), prune function for the 12-month rule |
| AI-04 | AI jobs run in a background worker in a locked-down, low-privilege container, using Codex CLI (Copilot is Phase 9) through subscription logins, never per-token APIs | Q2 (image, compose, env split), Q3 (supervision), Q10 (threats, terms, `forced_login_method`, env scrub, preflight assertion) |
</phase_requirements>

## Summary

The whole phase is "one new queue, one new container, one new process seam, three new tables, one new review page", and nearly every privacy-critical piece already exists in Phase 7 (`Visibility`, `Viewer`, `AiConsent`, `SystemQuery::isEditor`, `Provenance`, `source`/`verified_at` columns with CHECK constraints, the audit-first pattern, the endpoint matrix and the `MedicalPrivacy` leak harness). The new work is containment and correctness around an external CLI. Six findings from this session change what the plans must say, and two of them correct claims in the approved UI-SPEC and CLAUDE.md:

1. **The app image does NOT contain `pdftoppm`** (UI-SPEC and its checker follow-up say it "already ships" it). `[VERIFIED: ran family-hub-app:dev, no pdftoppm/pdfinfo/node]`. `apk add poppler-utils` adds 21 packages and about 16 MB; it must go into the `base` stage because the `app` container renders review pages and the `ai-worker` rasterizes for Codex.
2. **The Codex npm package is a ~350 MB native tree** (static musl binary 294 MB, `codex-code-mode-host` 70 MB, `voice` 40 MB, `rg`, a bundled `bwrap`), and the Node wrapper is not needed at run time. The native directory copied out of a Node build stage runs on Alpine 3.24 with no Node present. `[VERIFIED: codex-cli 0.169.0 ran as uid 1000 after `apk del nodejs npm`]`. Latest on npm is 0.169.0 (CLAUDE.md says 0.167.2).
3. **`codex login status` only proves credentials are present.** Exit 0 with "Logged in using ChatGPT" (or API-key text, which must be rejected), exit 1 "Not logged in"; it never consults `CODEX_API_KEY`, and "authentication is checked when you send a request". It cannot detect a dead refresh token. `[VERIFIED: exit 1 + "Not logged in" run here; CITED: openai/codex main login.rs]`. "Login expired" must therefore be derived from run outcomes as well.
4. **An unauthenticated `codex exec` does not fail fast**: five websocket reconnect attempts (about 6 s), exit 1, and the `-o` file is **created empty**. `[VERIFIED]`. Success must be "file exists AND non-empty AND validates", and error classification cannot rely on exit codes.
5. **The database queue would re-deliver a long job.** `retry_after` is 90 s (`config/queue.php`); a Codex run lasts minutes. The `ai-worker` needs `DB_QUEUE_RETRY_AFTER` above the job timeout, `--tries=1`, an atomic claim, and concurrency exactly 1 (OpenAI's own CI guidance: one `auth.json` per runner or serialized stream, never shared across concurrent jobs or machines, so staging and production need separate logins). `[VERIFIED: config/queue.php; CITED: learn.chatgpt.com/docs/auth/ci-cd-auth.md]`
6. **User namespaces are unavailable in a default Docker container, so Codex's `--sandbox read-only` (bubblewrap) cannot actually run commands there.** `[VERIFIED: "bwrap: No permissions to create a new namespace" as uid 1000]`. The sandbox flag stays (defence in depth) but the real controls are: no shell tool (`features.shell_tool=false`), `web_search="disabled"`, no MCP, scrubbed env, and a structured-output-only task. Do not weaken the container (no `apparmor=unconfined`, no privileged) to make bwrap work.

Everything else is a straightforward extension: dedicated `ai-worker` image target and env file, a `PdfRasterizer`/`CodexRunner` pair as the only code that spawns processes, `medical_ai_extractions` + `medical_ai_decisions` (medical-prefixed so the leak net covers them) and a content-free `ai_calls` guarded by a lifecycle trigger and a `SECURITY DEFINER` prune function (pattern verified on Postgres 18 this session), per-entry atomic accept through the existing Query classes with a new `source='ai'` entry point, and a dedicated cheap status route instead of Inertia partial reloads.

**Primary recommendation:** Build `App\Medical\Ai\*` as the only code that touches the CLI, the temp dir and the proposal tables; run the CLI through `env -i` + `setsid` + Symfony Process with a group kill, from a single-concurrency `ai-worker` that has its own env file (no `APP_KEY`, mail, VAPID or Google secrets), a read-only medical mount, a tmpfs work dir and a separate `codex-home` volume; treat the CLI output as untrusted bytes that must pass a hand-written server validator before any row is written.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Start/cancel a run, consent and access checks | API / Backend (`App\Medical\Ai` + Health controller) | Database (partial unique index, row lock on subject) | Server decides; UI state is cosmetic; consent re-checked at run time |
| Running Codex | `ai-worker` container (queue job + Symfony Process) | — | Only container with credentials; app, worker and scheduler never get `codex-home` |
| PDF to page images for Codex | `ai-worker` (`PdfRasterizer`) | — | Same class and flags as the viewer derivative |
| PDF page image for the review pane | API / Backend (`app` container, on demand) | — | Authenticated route, `no-store`, nothing persisted |
| Proposal validation | API / Backend (`ExtractionValidator`, runs in the job) | Database (CHECKs) | CLI output is untrusted; validated before any write |
| Proposal storage and per-entry decisions | Database (`medical_ai_*`) | API (scoped queries) | Medical content, viewer-scoped, cascade with document/person |
| Accept/edit into the record | API / Backend (existing Query classes) | Database (CHECK source/verified) | Same scoping, validation and audit as a manual write |
| Call log (append-only) | Database (triggers, revoke, definer prune) | API (insert/lifecycle only) | Tamper resistance against the app role |
| Login health | `ai-worker` (writes) | API (reads row, admin page) | Only the worker can run `codex login status` |
| Status polling | Browser (securityFetch timer) | API (cheap JSON route) | Per-person map of document statuses |
| Credentials (`auth.json`) | Docker volume `codex-home` (ai-worker only) | Ops (one-time login, never backed up in plain) | Never in DB, UI, env or backups |

## Standard Stack

### Core

No new composer or npm package. Everything is already installed or an OS/vendor binary.

| Item | Version | Purpose | Why Standard |
|------|---------|---------|--------------|
| `@openai/codex` (Codex CLI) | **0.169.0** (npm `latest`, 2026-11-14) | The agent CLI | Locked by CONTEXT; pinned exactly, upgraded deliberately `[VERIFIED: npm view; ran in image]` |
| poppler-utils (`pdftoppm`, `pdfinfo`) | 25.12.0-r1 (Alpine 3.24 repo) | PDF page rasterization, page count | Only listed OS package; +16 MB, 21 packages `[VERIFIED: apk in family-hub-app:dev]` |
| symfony/process | v8.1.10 | Process supervision | Already installed with Laravel `[VERIFIED: composer.lock]` |
| Laravel database queue | laravel/framework 13.42.0 | `ai` queue, `queue:work --queue=ai` | Project constraint (no Redis) |
| Postgres 18 triggers + `SECURITY DEFINER` function | postgres:18-alpine | Append-only call log, definer prune | Same layering as `audit_logs` `[VERIFIED: ran on postgres:18-alpine]` |
| Pest 4.7.8 / Vitest ^5.1 / axe-core 4.14.0 | installed | Tests | Project stack |

### Supporting

| Item | Purpose | When to Use |
|------|---------|-------------|
| `node:22-alpine` (already a Dockerfile stage) | Build stage that `npm ci`s the pinned Codex and hands over only the native directory | `codex` build stage; Node never reaches the runtime image |
| busybox `env`, `setsid` (in the PHP alpine image) | Exact env allowlist and a fresh process group | Wrapping every CLI spawn |
| `ext-posix`, `ext-pcntl` (loaded) | `posix_kill(-pgid, SIGKILL)`, queue job timeout alarm | `CodexRunner`, `queue:work` |
| tmpfs mount | Per-job work dir that never touches persistent disk | `AI_WORK_DIR` |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Node in the final image + `npm i -g @openai/codex` | Multi-stage copy of the native directory | Node adds 64 MB and a wrapper that only spawns the binary; copying the native dir avoids both `[VERIFIED]` |
| `opis/json-schema` (or similar) for validation | Hand-written Laravel validator + a drift test against the schema file | No new package for ~40 fields; drift is caught by a test that walks the schema |
| Dedicated Postgres role `fh_ai` with column grants | `fh_app` creds in a separate `ai-worker.env` (no other secrets) | `CREATE ROLE` needs a superuser on the existing server DB and the init script only runs on an empty data dir. Recommended: ship `fh_app` now, record `fh_ai` as optional hardening (Open Question 3) |
| Inertia partial reload for polling | Dedicated `health.people.ai.status` JSON route | `PersonPage` builds every prop eagerly, so `only:` would still run all queries `[VERIFIED: PersonPage.php lines 49-54]` |
| Persisting PDF page images | Render on demand, `no-store` | No new files on the medical disk (the orphan sweep would count them), no privacy surface, about 0.1 s per page `[VERIFIED: pdftoppm 0.09 s, 16 MB RSS on a test page]` |

**Installation (Dockerfile only, no `composer require`, no `npm install` in the app):**
```bash
# base stage: apk add --no-cache poppler-utils
# codex stage (node:22-alpine): npm ci --ignore-scripts   # docker/ai/package.json pins "@openai/codex": "0.169.0", with package-lock.json integrity hashes
# ai-runtime stage: COPY --from=codex <platform>/vendor/<triple>/ /opt/codex   (bin/codex, codex-path/rg, codex-resources/bwrap)
```

**Version verification:** `npm view @openai/codex version` returned 0.169.0; `codex --version` printed `codex-cli 0.169.0` on Alpine 3.24 as uid 1000 `[VERIFIED]`. `npm view @openai/codex@0.169.0 scripts` is empty (no postinstall). The npm registry lists maintainers `openai-publisher`, `tino-openai`, and others, repository `github.com/openai/codex`, license Apache-2.0, created 2025-04-16.

## Package Legitimacy Audit

> `slopcheck` could not be installed in this environment (pip install failed), so per protocol the one external package is tagged `[ASSUMED]` and the planner must gate the first pin behind a `checkpoint:human-verify`. The evidence that it is the genuine vendor package is strong (named in CLAUDE.md and in OpenAI's own docs, `openai-*` npm maintainers, repo `openai/codex`, no install scripts), so the checkpoint is a quick confirmation, not an investigation.

| Package | Registry | Age | Downloads | Source Repo | slopcheck | Disposition |
|---------|----------|-----|-----------|-------------|-----------|-------------|
| `@openai/codex` 0.169.0 (+ platform package `@openai/codex-linux-x64`) | npm | ~7 months (2025-04-16) | not queried | github.com/openai/codex | unavailable | `[ASSUMED]` Approved with `checkpoint:human-verify` before the lockfile is committed |
| poppler-utils | Alpine apk (main) | decades | n/a | gitlab.freedesktop.org/poppler | n/a (OS package) | Approved |

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none
**Note:** the platform package is an npm alias (`npm:@openai/codex@0.169.0-linux-x64`) installed by the main package's `optionalDependencies`; with `npm ci` and a committed lockfile the integrity hash pins it. Server architecture is `[ASSUMED]` x86_64 (Hetzner VPS); the Dockerfile must select the `x86_64` or `aarch64` directory from `uname -m`.

## Architecture Patterns

### System Architecture Diagram

```
Browser (Inertia/Vue)
  | POST run / cancel / accept / edit-accept / reject / undo        GET status (poll), review page, page image
  v
nginx -> php-fpm "app"  -- access:module,health,<edit|view> + idle gate + audit (existing)
  |   controllers App\Http\Controllers\Health\Ai*   (thin; ids resolved by Query classes, identical 403)
  |
  |-- RUN:  tx{ lock subject row FOR SHARE; AiConsent::allows; Viewer Edit scope; document kind != heic;
  |            INSERT medical_ai_extractions(queued)  [partial unique: one active run per document]
  |            INSERT ai_calls(queued); audit row; ai_notice_acks upsert }  ->afterCommit dispatch RunExtractionJob(extractionId) on queue "ai"
  |-- ACCEPT: tx{ lock extraction FOR UPDATE (Edit scope); INSERT decision ON CONFLICT DO NOTHING;
  |              existing Query::create(... origin 'ai')  ; link visit to document; audit }
  |-- PAGE IMAGE: scoped lookup -> PdfRasterizer (pdftoppm, ulimit, timeout) -> image/jpeg, no-store
  v
Postgres  (jobs, medical_ai_extractions, medical_ai_decisions, ai_calls, ai_agent_status, existing medical_*)
  ^
  | queue:work database --queue=ai --tries=1   (single process, retry_after 900)
ai-worker container (own image target, own env file, tmpfs work dir, medical:ro, codex-home:rw, init: true)
  RunExtractionJob(extractionId):
    1 claim  UPDATE ... SET status='running' WHERE id=? AND status='queued'   (0 rows => stop)
    2 recheck: user active + AccessGate Edit + SystemQuery::isEditor + AiConsent::allows + not cancelled  (else cancelled)
    3 env preflight (no OPENAI/CODEX/ANTHROPIC key)         4 workdir on tmpfs: copy image or rasterize PDF pages (cap)
    5 CodexRunner: env -i ... setsid codex exec ...   prompt on stdin;  poll loop: timeout, idle, output caps,
                   cancel flag, consent revoked  -> posix_kill(-pgid, SIGKILL)
    6 read -o file (size cap, non-empty, json depth cap) -> ExtractionValidator -> {ready+payload+entry_count | failed(code)}
    7 recheck consent/cancel BEFORE persist; supersede previous ready run in the same tx
    8 finally: delete workdir; ai_calls -> terminal status + duration; stderr/stdout discarded after classification
scheduler (app image):  ai:check-login (every 5 min, unique job on queue "ai"), ai:reap-stuck, ai:prune-calls (daily, definer fn), ai:prune-proposals
codex-home volume ---> only ai-worker; never in backups, never in app/worker/scheduler/nginx/postgres
```

### Recommended Project Structure
```
app/Medical/Ai/
├── Agents/AgentRegistry.php, Agent.php, CodexAgent.php   # one agent now; Phase 9 adds Copilot + order
├── CodexRunner.php            # the ONLY place that builds the codex command and spawns it (env -i, setsid, group kill, caps)
├── ProcessResult.php          # exit code, stdout/stderr bytes discarded after classify(); content never escapes
├── FailureCode.php            # enum: timeout, idle_timeout, no_output, invalid_output, output_too_large, cli_error, auth, quota (Phase 9), worker_lost, unreadable_document, unsupported_document
├── ExtractionSchema.php       # loads resources/ai/extraction.v1.schema.json, SCHEMA_VERSION, PROMPT_VERSION
├── ExtractionValidator.php    # hand-written validator + normaliser (see Q4)
├── PromptBuilder.php          # static prompt text + enum lists from Specialty
├── RunExtractionJob.php       # ShouldQueue, onQueue('ai'), tries 1, ids only
├── CheckLoginJob.php          # ShouldBeUnique, runs `codex login status`, writes ai_agent_status
├── Preflight.php              # env/binary/CODEX_HOME checks, used by `ai:preflight` and by the job
├── Work/WorkDir.php           # tmpfs dir lifecycle (create 0700, copy-in, cleanup, stale sweep)
└── Queries/                   # viewer-scoped: AiRunQuery (start/cancel/status), AiReviewQuery (read/decide); system-side: AiJobQuery (claim/finish, allowlisted like SystemQuery)
app/Medical/Documents/PdfRasterizer.php   # the ONLY place that spawns pdftoppm/pdfinfo (used by the page route and by the job)
app/Http/Controllers/Health/AiRunController.php, AiReviewController.php, DocumentPageController.php (or DocumentController::page)
app/Http/Controllers/Settings/AiCallsController.php   # admin list + check-again (names via an AdminAiCallQuery; arch allowlist extended deliberately)
app/Models/MedicalAiExtraction.php, MedicalAiDecision.php, AiCall.php, AiAgentStatus.php
config/ai.php               # binary path, model, timeouts, caps, work dir, retention
resources/ai/extraction.v1.schema.json, resources/ai/prompt.v1.md
docker/ai/package.json + package-lock.json    # pins @openai/codex 0.169.0 (build stage only)
ops/env/ai-worker.env.example
tests/Support/FakeCodex.php, tests/Support/MedicalPrivacy/zz_ai.php, tests/Support/EndpointMatrix/health-ai.php
```

### Pattern 1: Medical-prefixed content tables, content-free log table
`medical_ai_extractions` and `medical_ai_decisions` hold medical content and carry `subject_id`; the `medical_` prefix means `MedicalPrivacy`'s schema check forces them into `READ_TABLES`/`MUTATION_TABLES` (membership predicate on every viewer statement) and the Models glob (`app/Models/Medical*.php`) puts the models under the arch rule automatically. `ai_calls` and `ai_agent_status` are content-free and not subject-scoped by design (admin names-only page); they carry plain id columns (no FK) so history outlives people, like `audit_logs`.

### Pattern 2: The job is a state machine over one row
Claim atomically, recheck everything the request already checked, do the work, recheck before persist, finish in one transaction. A second delivery of the same job (crash, `retry_after`) finds `status != queued` and exits. Mirrors `SendOutboxItem` (`[VERIFIED: app/Jobs/SendOutboxItem.php]`).

### Pattern 3: Only two process seams
`CodexRunner` and `PdfRasterizer` are the only classes that may reference `Process`, `proc_open`, `exec`, `shell_exec`, `pdftoppm`, `codex`. An arch/scan test pins this (extends the existing source-scan style of `MedicalMutationScanTest`).

### Anti-Patterns to Avoid
- **Reading the document with `MedicalDocumentStore::absolutePath` from the job:** the 07-06 scan pins `absolutePath` to `DocumentController`. Add a store method `copyTo(string $relativePath, string $destination)` (file IO stays in the store seam) and extend the scan allowlist for that one method.
- **Writing derivative files under the `medical` disk:** `medical:prune-orphans` inventories every file there and its circuit breaker counts unknown files as orphans `[VERIFIED: PruneMedicalOrphans.php]`. Keep derivatives off that disk (on demand, nothing persisted).
- **Putting `ai_extractions` payload, stderr, stdout or the prompt in logs, `failed_jobs`, audit meta or exception messages.** `failed_jobs` stores the exception string; throw only content-free `AiRunFailed(FailureCode)`.
- **Trusting `-o` existence, exit code 0 or `codex login status` exit 0 alone** (all verified unreliable above).
- **Retrying automatically.** Cost, duplicate upload to OpenAI and the shared `auth.json` rule all argue for `$tries = 1` and an explicit user "Run AI again".

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Process timeout, incremental output | Custom `proc_open` loop | `Symfony\Component\Process\Process` (`setTimeout`, `setIdleTimeout`, `getIncrementalOutput`) plus one `posix_kill(-pid)` | Already installed; only the group kill is custom because Symfony's `stop()` leaves grandchildren alive `[VERIFIED: 2 sleeps survived plain stop(0, SIGKILL)]` |
| Exact child environment | `$process->setEnv()` with `false` entries | `/usr/bin/env -i VAR=... setsid <cmd>` | Symfony merges with the parent env; `env -i` gives an exact allowlist `[VERIFIED: child saw only HOME, PATH, PWD, SHLVL]` |
| Strict-mode JSON Schema validation | A schema library | Laravel validator mirroring the schema + drift test | Small closed schema, no new package, errors map to `invalid_output` |
| Append-only log guard | Application-level "never update" convention | Trigger + `REVOKE` + `SECURITY DEFINER` prune | Same layered pattern as `audit_logs`, verified on Postgres 18 |
| Unique active run | Check-then-insert in PHP | Partial unique index `WHERE status IN ('queued','running')` | Race-free double-start guard |
| Per-entry idempotent accept | A boolean on a JSON blob | `medical_ai_decisions` PK `(extraction_id, entry_key)` + `INSERT ... ON CONFLICT DO NOTHING` | Atomic, auditable, no JSON patching |
| PDF decoding | Imagick/GD for PDFs | `pdftoppm` | GD cannot read PDFs; Imagick is not installed `[VERIFIED: php -m]` |
| Record creation from a proposal | Direct inserts | The existing `VisitQuery/MedicationQuery/AllergyQuery/VaccinationQuery::create` with an `origin` argument | Keeps scoping, validation rules and the CHECK constraints in one place |
| Credential handling | A settings form for tokens | One-time `codex login --device-auth` in the container | Project rule: no credential ever in DB/UI |

**Key insight:** the dangerous parts of this phase (an external process reading medical files, a model producing text that becomes records) are exactly the parts where a generic helper does not exist, so keep each as one small class with one test file, and push everything else onto machinery Phase 7 already tested.

## Q1. The exact `codex exec` invocation (flags, tools, cwd, config, login status)

### What the current CLI documents (0.169.0)

`[VERIFIED: codex exec --help in the pinned binary]` and `[CITED: learn.chatgpt.com/docs/developer-commands?surface=cli, /docs/non-interactive-mode, /docs/config-file/config-reference]`:

| Flag / key | Documented behaviour | Use |
|------------|----------------------|-----|
| `-s, --sandbox read-only\|workspace-write\|danger-full-access` | Policy for model-generated shell commands; exec default is read-only | `--sandbox read-only` (explicit) |
| `--ephemeral` | Do not persist session rollout files | yes |
| `--skip-git-repo-check` | Run outside a git repo | yes (work dir is not a repo) |
| `--output-schema <FILE>` | JSON Schema for the final response | yes |
| `-o, --output-last-message <FILE>` | Writes the final message to a file (still printed to stdout per docs) | yes; the directory must exist or the CLI errors immediately `[VERIFIED: "Failed to open last message file ... No such file or directory"]` |
| `-i, --image <FILE>...` | Attach images to the first message; repeatable or comma-separated | yes |
| `-m, --model` | Override model | optional, from `config('ai.codex.model')` |
| `--ignore-user-config` | Skip `$CODEX_HOME/config.toml`; auth still uses `CODEX_HOME` | yes (removes any hand-edited config from the volume) |
| `--ignore-rules` | Skip execpolicy `.rules` | yes |
| `-c key=value` | TOML-parsed override | yes, see below |
| `--disable <FEATURE>` | Equivalent to `-c features.<name>=false` | `--disable memories` (accepted at parse time) |
| `--json` | stdout becomes JSONL events (`thread.started`, `turn.started`, `turn.completed`, `turn.failed`, `item.*`, `error`) | recommended, used only to classify failures |
| `--color never` | no ANSI | yes |
| prompt | positional or stdin. "If not provided as an argument (or if `-` is used), instructions are read from stdin. If stdin is piped and a prompt is also provided, stdin is appended as a `<stdin>` block." A prompt is required when `--image`, `--output-schema`, `-o` or `--ephemeral` are used | prompt on stdin, **no positional** (avoids the appended `<stdin>` block) |

Recommended argument vector (everything after `--cd` is the process cwd, no `-C` needed):

```
/usr/bin/env -i PATH=/opt/codex/bin:/usr/bin:/bin HOME=/codex CODEX_HOME=/codex LANG=C.UTF-8 TMPDIR=<workdir>/tmp \
/usr/bin/setsid /opt/codex/bin/codex exec \
  --sandbox read-only --ephemeral --skip-git-repo-check --ignore-user-config --ignore-rules --color never --json \
  --output-schema <workdir>/schema.json -o <workdir>/result.json \
  [-m <model>] \
  -c 'web_search="disabled"' -c 'features.shell_tool=false' -c 'forced_login_method="chatgpt"' \
  -c 'history.persistence="none"' -c 'analytics.enabled=false' -c 'check_for_update_on_startup=false' \
  --disable memories \
  -i <workdir>/page-1.jpg,<workdir>/page-2.jpg            # prompt arrives on stdin
```

All four argument shapes (`-i a,b` with no positional, `-i a,b -`, repeated `-i ... -`, `- -i a,b`) parsed and read the prompt from stdin `[VERIFIED: offline runs]`; clap treats a lone `-` as a value, so the safest shape is the first one (no positional at all).

### Which settings matter, and which are guesswork

| Control | Status |
|---------|--------|
| `features.shell_tool=false` ("Enable command execution tools (stable; on by default)") | `[CITED: config reference]`, accepted by the CLI `[VERIFIED: parsed]`, **effect unverified** until a real run shows the model has no command tool |
| `web_search="disabled"` ("`disabled` to remove the tool"; default `cached`) | `[CITED]` accepted `[VERIFIED: parsed]`, effect unverified |
| MCP | No `mcp_servers` exist in a fresh `CODEX_HOME`; `--ignore-user-config` guarantees none are loaded. `features.apps` (connectors, on by default) can additionally be set `false` `[CITED]` |
| `forced_login_method="chatgpt"` ("Restrict Codex to a specific authentication method") | `[CITED]`; makes an API-key login unusable even if one were stored. Effect unverified |
| `sandbox_workspace_write.network_access` | Irrelevant: we never use workspace-write |
| `history.persistence="none"`, `analytics.enabled=false`, `check_for_update_on_startup=false`, `--disable memories` | `[CITED]`, parse-accepted `[VERIFIED]`. A failed (pre-auth) run with a canary prompt left the canary in **no** file under `CODEX_HOME` with or without them `[VERIFIED]`, but a successful run was not possible, so persistence after a real run is a live-session check |
| bubblewrap | Not usable in default Docker (`[VERIFIED]`), Codex prints a warning and falls back to a bundled `bwrap`; with the shell tool disabled this does not matter. Never add `--privileged`, `apparmor=unconfined`, or `--yolo` |
| Default model | The 0.169.0 header printed `model: gpt-6.1-sol`, reasoning effort none `[VERIFIED]`; the docs' own example is the same string. Whether the subscription account may use it is unknown. Make the model configurable (`AI_CODEX_MODEL`), omit `-m` when empty and record `default` in `ai_calls.model` `[ASSUMED]` |
| Working directory | Codex roots at cwd. Use a fresh empty directory per job. A **`CODEX_HOME` under a temp dir is refused** for helper binaries (warning `Refusing to create helper binaries under temporary dir "/tmp"`) `[VERIFIED]`, so `CODEX_HOME` is the volume (`/codex`), never tmpfs |
| `CODEX_HOME` growth | Even with `--ephemeral` the home gained `state_5.sqlite`, `logs_2.sqlite`, `memories_1.sqlite`, `queue_1.sqlite`, `goals_1.sqlite`, `installation_id`, `skills/`, `tmp/` `[VERIFIED]`. After a real run, grep the volume for a canary string (live checklist) |

### `codex login status`

`[VERIFIED: ran, no credentials]` prints `Not logged in`, **exit 1**. `[CITED: openai/codex main codex-rs/cli/src/login.rs, MEDIUM because it is main, not the 0.169.0 tag]`: logged in with ChatGPT prints `Logged in using ChatGPT.` exit 0; stored API key prints a masked key and exit 0; load error prints `Error checking login status` exit 1; the env-var API key is **not** consulted (`enable_codex_api_key_env=false`); "Authentication is checked when you send a request." The official docs state only "exits with 0 when credentials are present" and do not document other codes `[CITED: developer-commands]`.

Consequences:
- Health check = run `codex login status` as the worker user, require exit 0 **and** stdout containing `Logged in using ChatGPT` (anything else, notably the API-key text, is `wrong_auth_mode` = not usable).
- It does not prove the refresh token is alive. Per OpenAI's CI guidance Codex refreshes a stale session (about 8 days) during a normal run and writes `auth.json` back; if jobs run too rarely, "add a scheduled maintenance job" `[CITED: learn.chatgpt.com/docs/auth/ci-cd-auth.md]`. For a family app that may be idle for weeks this is a real risk: an optional weekly tiny `codex exec` ("reply with {}") keeps the refresh alive at negligible quota. Owner decision, see Open Questions.
- A real run that fails with an auth signature (`401`, "sign in", "log in again", "refresh token") flips health to "Login expired" until the next successful run. The exact text is `[ASSUMED]`; capture a real expired-login transcript in the live session and then freeze the matcher. With `--json`, the `error` / `turn.failed` events carry the message; classify in memory, store only the `FailureCode`.

## Q2. Image, compose, users, volumes, queue and the startup assertion

### What exists today `[VERIFIED: compose.yaml, compose.server.yaml, Dockerfile]`

- `worker` uses the same image and `env_file: app.env` as `app` and `scheduler`: that file carries `APP_KEY`, DB creds (`fh_app`), mail/SMTP, Google OAuth secret, VAPID private key. It runs `queue:work --sleep=3 --tries=3 --max-time=3600` with **no `--queue`**, so it only serves the `default` queue; jobs dispatched with `onQueue('ai')` are never picked up by it. `mem_limit` worker 160m.
- prod image runs as `www-data` (alpine uid 82), dev as `app` uid 1000; `storage/app/medical` is created 0700 and files are 0600, so **the `ai-worker` must run as the same uid as `app`** (separation is by container and volume mount, not by uid).
- `medical` volume: `app`, `worker`, `scheduler` mount it read-write. Backups tar the `app` container paths (`snapshot_volume` runs `docker compose run ... app tar -C <path>`), so a new `codex-home` volume is **not** backed up unless someone adds it. Keep it that way and add a check.
- `deploy.sh` brings up `app worker scheduler nginx`, pulls/inspects `$prefix-app` and `$prefix-nginx` only, and its rollback `up` repeats the list. CI builds and pushes `app` and `nginx` images. `scripts/smoke-local.sh` hard-codes the service list (`app nginx postgres scheduler worker`). `check-compose.sh` asserts the five services and forbids `_API_KEY$|REDIS` keys in `environment`. All of these must learn about `ai-worker`.

### Dockerfile

Add (in this order, so the 350 MB layer sits **below** the per-commit code layer and is cached and registry-deduplicated across pushes):

```dockerfile
# base: add poppler (app renders review pages, ai-worker rasterizes for Codex)
RUN apk add --no-cache icu-libs libpq libzip libjpeg-turbo libpng libwebp poppler-utils ...   # existing line + poppler-utils

FROM node:22-alpine AS codex
WORKDIR /opt/codex-build
COPY docker/ai/package.json docker/ai/package-lock.json ./
RUN npm ci --ignore-scripts --omit=dev && \
    triple="$(uname -m)-unknown-linux-musl" && \
    d="$(find node_modules -type d -path "*vendor/${triple}" | head -1)" && test -x "$d/bin/codex" && \
    mkdir /opt/codex && cp -a "$d"/. /opt/codex/ && rm -rf /opt/codex/codex-resources/voice   # voice is not needed for exec (live check: codex still starts)

FROM base AS ai-runtime
COPY --from=codex /opt/codex /opt/codex
RUN ln -s /opt/codex/bin/codex /usr/local/bin/codex && mkdir -p /codex /ai-work

# then the same vendor/code layers as prod, WITHOUT the assets stage (no Vite output needed), as target `ai-worker` (prod flavour)
# and `ai-worker-dev` (bind-mounted code, uid 1000) for compose.yaml
```

Size: base 112 MB + poppler 16 MB + Codex about 310 to 350 MB (voice removal saves about 40 MB; whether the binary still starts without `voice/` printed `codex-cli 0.169.0` in this session, `exec` behaviour unverified). Expect an `ai` image of roughly 450 to 500 MB `[VERIFIED sizes, estimate for total]`. CI pushes a third image (`$prefix-ai`), cached after the first push because the layers below `COPY . .` do not change. `deploy.sh`: add `$prefix-ai` to the `docker image inspect`/pull/`stale_images` lists and `ai-worker` to both `up -d --wait` calls.

### Compose (server)

```yaml
x-ai: &ai
  image: ${IMAGE_PREFIX:?}-ai:${IMAGE_TAG:?}
  restart: unless-stopped
  env_file: ai-worker.env          # NOT app.env: DB_*, APP_ENV, QUEUE_CONNECTION, LOG_CHANNEL=stderr, DB_QUEUE_RETRY_AFTER=900, AI_*; no APP_KEY, mail, Google, VAPID
  environment: { TZ: Europe/Bratislava, HOME: /codex, CODEX_HOME: /codex }
  logging: *logging
  depends_on: { postgres: { condition: service_healthy } }

  ai-worker:
    <<: *ai
    command: ["sh", "-c", "php artisan ai:preflight && exec php artisan queue:work --queue=ai --sleep=3 --tries=1 --timeout=360 --max-jobs=100 --max-time=3600"]
    init: true                      # reaps codex/rg/bwrap children, forwards SIGTERM
    mem_limit: ${MEM_AI:-384m}      # [ASSUMED] measure Codex RSS in the live session; budget is tight (see Open Questions)
    volumes:
      - medical:/var/www/html/storage/app/medical:ro   # read-only: the worker only reads originals
      - codex-home:/codex                              # the ONLY mount of this volume
    tmpfs:
      - /ai-work:size=96m,mode=0700,uid=<app uid>      # document copies and page images; counts against mem_limit
    healthcheck: { test: ["CMD-SHELL", "pgrep -f '[q]ueue:work'"], interval: 15s, timeout: 3s, retries: 5, start_period: 20s }
    # optional hardening to try in the live session: cap_drop [ALL], security_opt [no-new-privileges:true], read_only: true (+ tmpfs for storage/logs, storage/framework, bootstrap/cache)
volumes: { codex-home: }
```

Notes:
- Dev (`compose.yaml`): `ai-worker` uses target `ai-worker-dev`, tag `family-hub-ai:dev`, `profiles: ["ai"]` so `docker compose up` neither builds a 450 MB image nor starts it, and `smoke-local.sh` stays valid. Tests never need it (the fake CLI replaces Codex).
- **The `ai-worker` has `fh_app` DB credentials** (queue, `medical_*`, `ai_*`, `audit_logs` insert) but not the other app secrets. Verified that Laravel boots, resolves the database queue and fails closed with an empty `APP_KEY`: `app('encrypter')` throws `MissingAppKeyException` only when used `[VERIFIED: ran php with APP_KEY= in the dev image]`. Add a test that runs the job path with `config(['app.key' => ''])`. `PushSubscription` is the only model with `encrypted` casts and the job never touches it.
- Single concurrency: one container, one `queue:work` process, never `deploy.replicas > 1`. Add a comment and a check-compose assertion (`deploy.replicas` unset or 1).
- Each stack (staging, production) has its own project name and therefore its own `codex-home` volume, so separate logins come for free. Never copy one `auth.json` to both.
- `retry_after` must exceed the worker `--timeout` and the job `$timeout`; defaults: process timeout 300 s, job `$timeout` 330, worker `--timeout=360`, `DB_QUEUE_RETRY_AFTER=900`. `ai:preflight` asserts `retry_after > timeout + 60`. `[ASSUMED]` durations: tune after the first real run.

### `ai:preflight` and the key assertion

An artisan command run before `queue:work` (and re-run by the job before every spawn) that exits non-zero when: any of `OPENAI_API_KEY`, `CODEX_API_KEY`, `ANTHROPIC_API_KEY` is set (also `OPENAI_BASE_URL`, `OPENAI_ORG_ID`, `CODEX_ACCESS_TOKEN`, `CODEX_AGENT_IDENTITY` as a precaution `[ASSUMED]` names); the binary is missing or `codex --version` differs from the pin in `config/ai.php`; `CODEX_HOME` is unwritable or under a temp dir; `DB_QUEUE_RETRY_AFTER` is too small; `APP_KEY` is non-empty (the worker must not hold it). Because `CodexRunner` also runs the child through `env -i`, a stray key could never reach the CLI even if the guard were bypassed. Extend `check-compose.sh`: `ai-worker` exists with `mem_limit`, no ports, `env_file == [ai-worker.env]`, mounts `codex-home` and `medical:ro` and **not** `uploads`, no other service mounts `codex-home`, `deploy.replicas` unset or 1, `ops/env/ai-worker.env.example` contains no `_API_KEY`, `APP_KEY`, `MAIL_`, `GOOGLE_`, `VAPID_`; a grep that `scripts/backup.sh`, `restore-drill.sh`, `backup-freshness.sh` never mention `codex-home`.

## Q3. Process supervision from PHP (and the fake CLI)

### Verified behaviour `[VERIFIED: symfony/process v8.1.10, PHP 8.4, alpine busybox, in family-hub-app:dev]`

| Check | Result |
|-------|--------|
| `env -i PATH=... HOME=... setsid sh -c '... sleep & sleep & wait'` then `posix_kill(-$pid, SIGKILL)` | pid is the group leader; both grandchildren gone |
| Plain `$process->stop(0, SIGKILL)` | 2 grandchildren survived: a group kill is mandatory |
| Env seen inside the child | only the allowlist plus `PWD`, `SHLVL` |
| Output flood (`yes`) with incremental read + cap check | killed after about 144 KB in 0.02 s |
| No `setInput` | stdin is closed immediately (`cat` returned), so a missing prompt cannot hang the CLI |

### Runner contract (`CodexRunner`)

```php
// Source: verified experiment this session, adapted. Names are illustrative.
$cmd = ['/usr/bin/env', '-i', 'PATH=/opt/codex/bin:/usr/bin:/bin', 'HOME=/codex', 'CODEX_HOME=/codex',
        'LANG=C.UTF-8', 'TMPDIR='.$work.'/tmp', '/usr/bin/setsid', $binary, 'exec', ...$flags];
$p = new Process($cmd, $work);            // cwd = fresh work dir
$p->setTimeout(300)->setIdleTimeout(120)->setInput($prompt);
$p->start();
$out = $err = 0; $head = '';
while ($p->isRunning()) {
    $out += strlen($p->getIncrementalOutput()); $err += strlen($p->getIncrementalErrorOutput());
    if ($out > $capOut || $err > $capErr || $this->shouldAbort()) {   // caps 2 MiB / 256 KiB; abort = cancel flag, consent off, access lost
        posix_kill(-$p->getPid(), SIGKILL); break;
    }
    try { $p->checkTimeout(); } catch (ProcessTimedOutException) { posix_kill(-$p->getPid(), SIGKILL); break; }
    usleep(250_000);                       // 4 Hz poll also drives the DB heartbeat/cancel read every ~2 s
}
$p->stop(0, SIGKILL);                      // reap; the group is already dead
```

Rules:
- The command is an **array**, never a shell string; the work dir path and image names are generated by the app (ULIDs), never from the client file name.
- Keep only counters and a classification, never retain stdout/stderr text beyond a bounded in-memory window used to classify the `error`/`turn.failed` events; discard before returning. Nothing is logged.
- `setsid` makes the child a session and group leader whose pid equals the group id; if `setsid` is missing the runner must refuse to run (preflight asserts `/usr/bin/setsid` and `/usr/bin/env` exist).
- The PHP job `$timeout` (330) must be larger than the process timeout (300) so our kill runs first; the pcntl alarm is only a backstop. After a worker kill, `init: true` and the container restart clean up the orphans; the reaper marks the row failed.
- `PdfRasterizer` follows the same shape with `sh -c 'ulimit -v 786432; exec "$@"' sh pdftoppm ...` (arguments passed positionally, never interpolated), timeout 20 s, `-scale-to 1800`, `-f n -l n -singlefile`, JPEG `-jpegopt quality=82` for the viewer and PNG or JPEG for Codex `[ASSUMED: ulimit -v value, tune]`.

### Fake `codex` for tests

A generated per-test shell script (`tests/Support/FakeCodex.php` writes `#!/bin/sh` to a temp file, `chmod 0700`, returns the path; tests set `config(['ai.codex.binary' => $path])`). No test hook in production code and no env-var mode switch (the real runner scrubs env, so a mode variable would never arrive). Each variant is a few lines of sh that finds the `-o` path in `$@`, reads stdin, and:

| Variant | Behaviour | Proves |
|---------|-----------|--------|
| `succeeding($payload)` | writes valid JSON to `-o`, exit 0 | happy path, `ready` row |
| `invalidJson`, `schemaViolation`, `extraField`, `hugeArray` | writes bad payload | `invalid_output`, no partial proposal |
| `emptyOutput()` | creates empty `-o`, exit 1 (matches the real unauthenticated behaviour) | non-empty check |
| `exitZeroNoFile()` | exit 0, no file | `no_output` |
| `failing($code, $stderr)` | stderr text, no file | classification incl. `auth` signature |
| `hanging()` / `silent()` | `sleep 600` | process and idle timeout |
| `forking($pidFile)` | spawns `sleep 600 &`, writes pid | group kill leaves no orphan |
| `flooding($bytes)` | `yes` to stdout/stderr | caps |
| `recording($file)` | writes `$@`, `env`, `pwd`, `ls`, stdin to `$file` | asserts exact flags, env allowlist (no `OPENAI_API_KEY`), cwd is a fresh dir containing only the inputs, prompt on stdin, `--output-schema` file equals the shipped schema |
| `loginStatus($exit, $text)` | answers `login status` | health states, `wrong_auth_mode` |

Unit tests drive `CodexRunner` directly (no DB); feature tests drive `RunExtractionJob` with `Queue::fake` replaced by calling `handle()` and with the real `FakeCodex`. All run in the `app` container (busybox `env`, `setsid`, `sh` exist there).

## Q4. Extraction JSON Schema

### Codex/OpenAI strict-mode constraints `[CITED: developers.openai.com/api/docs/guides/structured-outputs]` (applies to `--output-schema` is `[ASSUMED]` until a live run accepts the file)

- Root must be an object (no top-level `anyOf`); every object `additionalProperties: false`; **every property listed in `required`**; optional = union with `null` (`"type": ["string","null"]`).
- Supported: `string` (`pattern`, `format` in date-time, time, date, duration, email, hostname, ipv4, ipv6, uuid), `number/integer` bounds, `array` `minItems`/`maxItems`, `enum`, `anyOf`, `$ref`/`$defs`. **Not** supported: `allOf`, `not`, `if/then/else`, `dependent*`. `minLength`/`maxLength` are not listed, so do not rely on them.
- Limits: 5,000 properties, 10 nesting levels, 120,000 characters of names plus enum values.

### Schema v1 (`resources/ai/extraction.v1.schema.json`)

Top level (all required): `schema_version` (integer, enum `[1]`), `document_date` (date or null), `specialty_key` (enum of the `Specialty` keys + `other`, or null), `visits[]`, `medications[]`, `allergies[]`, `vaccinations[]` (each array `maxItems` 25). Entry fields (all required, nullable where the DB allows null or where "unknown" must not be invented):

| Type | Fields (Phase 7 column) | Nullable in schema |
|------|-------------------------|--------------------|
| visit | `visited_on` (date), `doctor` (120), `specialty_key` (enum), `specialty_other` (80), `diagnosis` (2000), `notes` (4000) | all nullable, including `visited_on` and `specialty_key` (DB columns are NOT NULL, so an entry without them is flagged at accept, see Q8) |
| medication | `name` (120), `dose` (120), `schedule` (160), `started_on`, `ended_on`, `notes` (1000) | all but `name` |
| allergy | `substance` (120), `reaction` (200), `severity` (enum `mild,moderate,severe,unknown`) | `reaction`, `severity` (UI-SPEC: "Choose a severity" when null) |
| vaccination | `vaccine` (120), `given_on` (date), `next_due_on` (date), `notes` (500) | `given_on` (DB NOT NULL, flagged), `next_due_on`, `notes` |

`[VERIFIED: column names, lengths and CHECKs read from the Phase 7 migrations]`. `document_date` and `specialty_key` at top level are context only (UI-SPEC: shown, never written). No `confidence`, no quotes of source text (fewer injection surfaces, less duplicated content).

**Versioning:** `SCHEMA_VERSION` (int) in the file and in `ExtractionSchema`; `PROMPT_VERSION` (int) separately; both stored on `medical_ai_extractions`. A payload whose `schema_version` is not the version the job sent is `invalid_output`. Changing the schema means a new file `extraction.v2.schema.json`, old rows keep their version and the review page reads by stored version (a v1 reader stays in code until old rows are pruned).

### Server-side revalidation (`ExtractionValidator`)

Order, all before any DB write, any failure = `failed` with `invalid_output` and **no payload stored**:
1. Size cap on the `-o` file (256 KiB) before reading, then `json_decode(..., flags: JSON_THROW_ON_ERROR, depth: 8)`; must be an array/object.
2. Exact key set per object (no extra, none missing): the same closed shape as the schema. A drift test walks the schema file and the validator rule table and fails if a property is missing on either side.
3. Types and enums: strings are strings; `specialty_key` is a `Specialty` value or `other`; `severity` in the 4-value set; dates strict `Y-m-d` and `checkdate`, plausible range (1900-01-01 to today plus 10 years, else the field becomes `null` and the entry keeps its other fields); arrays at most 25 entries; each string at most 4,000 characters (longer = `invalid_output`, not truncation).
4. Normalise strings: valid UTF-8 (`ValidUtf8` rule logic), strip control and Unicode format/bidi characters (the Markdown exporter already strips; reuse the same helper), trim, empty string to `null`. Field-length conformity to the Phase 7 caps (e.g. doctor 120) is **not** a proposal failure: such an entry is stored as proposed and flagged when accept re-runs the real form rules (UI-SPEC "Accept opens the edit form with the offending fields flagged").
5. Persist `payload` (jsonb) = the normalised structure, `entry_count`, `schema_version`, `prompt_version`.

### Injection-safe handling

Output is data in a closed schema (strict mode cannot emit free prose outside the fields). It is never concatenated into SQL (Eloquent/query-builder bindings only), shell, file paths, HTTP headers or HTML. Display is Vue text interpolation only (UI-SPEC rule). Accept re-validates through the normal Phase 7 form rules, which include `ValidUtf8`. Exports already escape and fence free text (07-16). A hostile document can still make the model emit false or misleading values, so the controls are the review step, the `AI-extracted`/`Unverified` labels, and the default exclusion of unverified rows from exports.

## Q5. PDF side-by-side (preview route today, derivative design)

### How preview works today `[VERIFIED: DocumentController.php, routes/web/health-documents.php, NoStoreDynamicResponses.php]`

`GET /health/people/{person}/documents/{document}/preview/{variant}` (variants `thumb`, `view`) serves **images only**: `abort_unless($row->kind === 'image' ...)`, `Cache-Control: private, no-cache` + HMAC ETag (the only exempted route besides wishlist images), `nosniff`, `CSP default-src 'none'`, scoped lookup first so a hidden document never yields 304. PDFs and HEIC are only downloadable through `.../file` (attachment, `no-store`). Images are stored as sanitized JPEGs (full ≤ 2400 px, thumb 480), so the existing `view` variant already feeds the image side-by-side pane and Codex; the browser pane needs nothing new for images. PDFs have no inline route.

### Recommended PDF derivative

New route `GET /health/people/{person}/documents/{document}/pages/{page}` (`access:module,health,view`, `throttle:120,1,health.documents.page`, name `health.documents.page`), handled by the same `visibleDocument()` helper (identical 403 for hidden, unknown, malformed, wrong kind, page out of range). It rasterizes one page with `PdfRasterizer` and returns `image/jpeg` with the hardened `fileResponse` headers but **`Cache-Control: no-store, private`** (the middleware default, **no exemption added**, consistent with PDFs being `no-store` and with review finding WR-05): nothing persisted server-side, nothing cached in the browser, no new files on the `medical` disk, no purge or backup concern. Cost per page is about 0.1 s and 16 MB RSS for a simple page `[VERIFIED]`; the throttle bounds abuse; the `app` container (192 MB) is fine because `-scale-to 1800` bounds the raster.

- **Page count:** the review page props include `pageCount` from `pdfinfo` (timeout 5 s, parse `Pages:`), not stored. A PDF poppler cannot open yields `pageCount = 0` and the 07 "No preview" file card.
- **Page cap:** viewer pages are not capped beyond `config('ai.pdf_view_max_pages')` (say 50); the **AI input** is capped at `ai.pdf_max_pages` (default 10 `[ASSUMED]`). The extraction row stores `pages_read` and `pages_total`; when `pages_read < pages_total` the status block and review header must say so (see UI gaps below).
- **Same rasterization feeds Codex:** `PdfRasterizer::pages($pdfPath, $workDir, $max, $format)` writes `page-1.jpg ...` into the tmpfs work dir; the runner passes them as `-i page-1.jpg,page-2.jpg`. One class, one set of flags, one set of limits.
- **Privacy of the derivative:** same authorization and headers as the file route; raster output carries no metadata; the work-dir copies live on tmpfs and are deleted in `finally`, with a stale-dir sweep at worker start and tmpfs discarded on container stop.
- **Environment:** GD (JPEG/PNG/WebP) present, Imagick absent `[VERIFIED: php -m]`, so `pdftoppm` is the only PDF path. HEIC: GD cannot decode, Codex's HEIC support is undocumented; **HEIC documents are not sent to AI** (`unsupported_document`), which the UI-SPEC does not cover (UI gap).
- **PDF hazards:** poppler parses untrusted files. Mitigations: `ulimit -v`, 20 s timeout, bounded output size, runs unprivileged, only after the same byte-sniff and `%PDF-` at offset 0 check that upload already enforced; keep poppler patched through the Alpine base image rebuild.

### UI-SPEC / CONTEXT gaps found (planner must decide, defaults given)

1. HEIC document: hide or `aria-disabled` Run AI with a new line ("AI cannot read this file type. Upload it as a photo or PDF.").
2. PDF over the page cap: header/status line "Only the first {n} pages were read." (new plural key).
3. Superseding timing (UI-SPEC says "on success of queueing"): supersede when the **new** run reaches `ready`, so a failed or cancelled re-run never destroys reviewable suggestions. Copy stays true.
4. "AI service not responding" wording per the checker follow-up, "Keep document private" and "Discard edit" labels also change the lang keys.

## Q6. Data model, roles/grants, append-only, prune

### Roles and grants pattern `[VERIFIED: 01-roles.sh, audit_logs migration]`

`fh_owner` owns objects and runs migrations; `fh_app` has default privileges `SELECT, INSERT, UPDATE, DELETE` on every table and sequence that `fh_owner` creates (`ALTER DEFAULT PRIVILEGES`), so new tables are writable by `fh_app` automatically and append-only tables must `REVOKE` explicitly. The audit migration also fails in `production`/`staging` when `fh_app` is missing. Follow it exactly.

### Tables

**`medical_ai_extractions`** (content; medical prefix so the leak net applies): `id uuid PK` (v4 via `Str::uuid()`, no timestamp leak), `subject_id uuid FK medical_subjects CASCADE`, `document_id uuid`, composite `FK (document_id, subject_id) -> medical_documents (id, subject_id) ON DELETE CASCADE` (requires a new `UNIQUE (id, subject_id)` on `medical_documents`, additive, Pattern 3 of 07), `status varchar(12)` CHECK in `queued, running, ready, failed, cancelled, superseded`, `cancel_reason varchar(8) null` CHECK in `user, consent, access`, `failure_code varchar(24) null`, `agent varchar(20) default 'codex'`, `model varchar(60) null`, `schema_version smallint`, `prompt_version smallint`, `started_by bigint` (plain, like `created_by`), `payload jsonb null`, `entry_count smallint null`, `pages_read smallint null`, `pages_total smallint null`, `cancel_requested_at timestamptz null`, `queued_at default now()`, `started_at`, `finished_at`, timestamps. CHECKs: `(payload IS NOT NULL) = (status IN ('ready','superseded'))`; `(cancel_reason IS NOT NULL) = (status = 'cancelled')`; `(failure_code IS NOT NULL) = (status = 'failed')`.
Indexes: partial unique `(document_id) WHERE status IN ('queued','running')` (double-start guard), partial unique `(document_id) WHERE status = 'ready'`, `(subject_id, document_id, queued_at DESC)`, partial `(status, queued_at) WHERE status IN ('queued','running')` for the reaper.

**`medical_ai_decisions`**: `extraction_id uuid FK CASCADE`, `entry_key varchar(20)` (`visit:0`), `decision varchar(8)` CHECK `accepted|rejected`, `record_type varchar(12) null`, `record_id uuid null` (vaccination: its public uuid), `edited boolean`, `decided_by bigint`, `decided_at`, PK `(extraction_id, entry_key)`. Reject-undo deletes a `rejected` row; `accepted` rows are final.

**`ai_calls`** (content-free): `id bigserial PK`, `extraction_id uuid`, `subject_id uuid`, `document_id uuid`, `started_by bigint` (all plain, no FK, history outlives rows and people), `agent varchar(20)`, `model varchar(60) null`, `attempt smallint default 1` (Phase 9 fallback = more rows per extraction), `status varchar(10)` CHECK `queued, running, succeeded, failed, cancelled`, `failure_code varchar(24) null`, `queued_at timestamptz default now()`, `started_at`, `finished_at`, `duration_ms integer null`. Indexes `(queued_at DESC)`, `(subject_id, queued_at DESC)`, `(started_by, queued_at DESC)`, `(status, queued_at DESC)`. Name display for admins joins `medical_subjects`/`users` at read time; a missing person shows a neutral "Removed person" (no name snapshot, so renames and erasures stay consistent).

**`ai_agent_status`**: `agent PK`, `login_state` (`logged_in, logged_out, wrong_mode, unknown`), `checked_at`, `last_auth_failure_at`, `last_success_at`. One row now, Phase 9 adds rows.
**`ai_notice_acks`**: `user_id PK FK users CASCADE`, `acknowledged_at` (UI-SPEC PROVISIONAL 1, per member).

### Append-only guarantee for `ai_calls` (verified)

The status moves queued to running to terminal, so a literal "never UPDATE" is impossible if admins must see Waiting/Running. Recommended and **verified on postgres:18-alpine this session**: `REVOKE DELETE, TRUNCATE` from `fh_app`, a `BEFORE UPDATE` row trigger that refuses any change to identity columns (`id, extraction_id, subject_id, document_id, started_by, agent, queued_at`) and allows `status` only to move forward (queued < running < terminal, terminal frozen), plus `finished_at`, `duration_ms`, `failure_code`, `model`, `started_at` set once; a `BEFORE DELETE`/`TRUNCATE` guard identical to the audit one (`current_setting('ai.prune') = 'on'` AND `current_user` is the table owner). Test results: update of identity column refused, backward status refused, forward update ok, terminal update refused, direct DELETE by `fh_app` "permission denied", prune function deleted only the 14-month-old row. This is a deliberate reading of "append-only" (identity immutable, lifecycle monotonic); flag it in the plan as the interpretation to confirm.

### 12-month prune that works under `fh_app`

`AuditPrune` is manual and owner-only (`audit:prune` refuses `fh_app`, is not scheduled), but the UI-SPEC promises automatic removal. The scheduler runs as `fh_app`, so create `ai_calls_prune(months int DEFAULT 12) RETURNS bigint LANGUAGE plpgsql SECURITY DEFINER SET search_path = public, pg_temp`, owned by the migrating role, `REVOKE ALL ... FROM PUBLIC; GRANT EXECUTE ... TO fh_app`, which does `set_config('ai.prune','on', true)` then deletes only rows older than the interval. Verified: inside the definer function `current_user` is the owner so the guard passes, while a direct `DELETE` by `fh_app` is refused. Command `ai:prune-calls` (`Schedule::command('ai:prune-calls')->daily()`) calls `SELECT ai_calls_prune(12)` and prints only the count. Test the grants with `SET ROLE fh_app` inside the Pest test transaction (tests connect as `fh_owner`; `AuditTriggerTest` shows the style).

### Proposal retention (not specified; owner decision)

Proposals duplicate medical content. Recommend `ai:prune-proposals` (daily, scheduler): delete `superseded`, `failed`, `cancelled` extraction rows and fully decided `ready` rows older than `ai.proposal_retention_days` (default 90 `[ASSUMED]`); undecided `ready` rows stay until the document or person is deleted (FK cascade). Decisions cascade with their extraction.

## Q7. Consent, access, idempotency, retries, cancellation

**Queue time (`AiRunQuery::start`)**, one transaction, fail-closed:
1. `Viewer::for($user)` (module level) and `Visibility::apply(..., Edit)` on the subject with `lockForUpdate`/`FOR SHARE`; consent toggling (`SubjectQuery::setConsent`) already takes `lockForUpdate` on the subject row, so reading `ai_consent` under the same lock makes "consent off" and "run" serialize. Use `AiConsent::allows` (reads the DB, ignores in-memory flags `[VERIFIED: AiConsent.php]`) **inside** the transaction after the lock.
2. Document resolved through `DocumentQuery::find(... Edit)`; kind must be `pdf` or `image` (not `heic`).
3. Insert extraction (`queued`) and call (`queued`); the partial unique index turns a double click or second tab into a unique violation caught as "already running" (409, same body as current state). Write the audit row and the `ai_notice_acks` upsert in the same transaction (audit first; a failing audit rolls everything back, matching `FailingAuditLogger` tests).
4. `RunExtractionJob::dispatch($extractionId)->onQueue('ai')->afterCommit()` (config has `after_commit => false`, so request it explicitly).

**Run time (`RunExtractionJob::handle`)**, no `Viewer`, no `abort()` (a thrown 403 would be retried/logged): (a) atomic claim `UPDATE ... SET status='running', started_at=now() WHERE id=? AND status='queued'`, zero rows = stop silently; (b) `AccessGate::forget($user)` then `AccessGate::allows($user, Health, Edit)` and `SystemQuery::isEditor($subjectId, $userId)` (the exact pattern of `VaccinationDueHandler::stillRelevant`, `[VERIFIED]`); also the starter user must still exist and be active; (c) `AiConsent::allows($subjectId)`; (d) `cancel_requested_at` null. Any failure = `cancelled` with reason `access` or `consent` (UI "Stopped: AI is off"), call row finished, no CLI spawned. The same `shouldAbort()` check runs every ~2 s inside the runner loop (consent switched off or cancel pressed kills the process group) and once more **after** the CLI returns and **before** the payload is persisted: if consent is now off or cancel was requested, discard the result.
**Hidden-person document at run time:** the job never uses a viewer scope; it addresses the document by id through `AiJobQuery` (allowlisted unscoped like `SystemQuery`, docblock states why) after the access re-verification above, so a starter whose access was removed in between produces a `cancelled` run, not a read.
**Retries:** `public int $tries = 1; public int $maxExceptions = 1; public int $timeout = 330; public bool $failOnTimeout = true;` plus the claim guard. If the worker dies mid-run the row is stuck `running`; `ai:reap-stuck` (scheduler, every 5 min) marks `running` rows older than `timeout + 120 s` as `failed(worker_lost)` and closes their call row; a redelivered job then fails the claim. `queued` rows older than 2 hours (worker down) show the "Still waiting" path in the UI and are left for the worker; the cancel button always works (queued cancel is an immediate `UPDATE ... WHERE status='queued'`).
**Cancel:** `POST .../ai/{extraction}/cancel` (edit level): queued, immediate `cancelled(user)`; running, set `cancel_requested_at`; the runner loop sees it within about 2 s, kills the group, finishes `cancelled(user)` (UI: "AI is reading" until the worker confirms, as specified).

## Q8. Accept / edit / reject and provenance

**Today `[VERIFIED: Query classes, migrations, Provenance.php]`:** every `create` hard-codes `'source' => 'manual', 'verified_at' => null`; every record table has `source` (`manual|ai`), `verified_at`, `verified_by` and `CHECK ((source = 'ai') OR (verified_at IS NULL))`; `Provenance::isUnverified` = `source = 'ai' AND verified_at IS NULL`; exports exclude unverified by default and label them when included. A "verify" action exists in Phase 7 scope (07-16 UI-SPEC says its label wins); setting `verified_at` is the only transition.

**Plan:** add an optional `string $source = 'manual'` (or a small `Origin` enum) parameter to the four `create` methods (`VaccinationQuery::insert` is private and shared with `markGiven`, thread it through); `'ai'` is passed only by the accept path. Because `source` is not in the `FIELDS`/`attributes()` allowlists, client input can never set it (keep it that way and add a test).
**Accept transaction** (`AiReviewQuery::decide`):
1. Scoped load of the extraction `FOR UPDATE` with `Visibility::apply(..., 'subject_id', Edit)`; must be `ready` (superseded and every other state refuse, same 403/409 shape).
2. Entry key must exist in the stored payload (`visit:0` ...), else the same refusal.
3. `INSERT INTO medical_ai_decisions ... ON CONFLICT DO NOTHING RETURNING`; zero rows means it was already decided: return the current state (200, no second record). This is what makes double click, retry and two tabs idempotent.
4. Validate the (possibly edited) values with the **same rule set as the Phase 7 form requests** (extract the rule arrays from `VisitRequest`, etc. into shared methods so there is one definition); failure = 422 with the offending fields and **no decision row** (the transaction rolls back), which drives "Accept opens the edit form with the field flagged".
5. Create the record through the Query `create(..., source: 'ai')`; store `record_type` and `record_id` on the decision row; for a visit, link the document through the existing `DocumentQuery::update(... changeVisit: true ...)` only when the document currently has no visit (do not silently re-attach).
6. Audit: the route is `access:module,health,edit` so `RequireAccess` already writes the content-free `health_write` row; add new audit events `MedicalAiRunRequested {subject, document}`, `MedicalAiEntryDecided {subject, document, decision}` (ids and enums only). Each new `AuditEvent` case needs `metaKeys`, `group()` ('health'), the audit page labels in `lang/*.json` and an update to the "all medical audit events" assertion in `MedicalLangTest`/`AuditHealth.test.js`.
**Reject** inserts a `rejected` decision; **Undo** deletes it (only if `rejected`); neither touches the record. **Edit-accept** is the same path with the edited field set and `edited = true`. No record exists from AI until a member acts, and "AI-extracted" is permanent provenance while "Unverified" ends at verification (UI-SPEC).

## Q9. Status updates and admin login health

**Polling transport:** a dedicated JSON route `GET /health/people/{person}/ai-status` (`access:module,health,view`, `throttle:60,1,health.people.ai.status`, name `health.people.ai.status`) returning `{documents: {<docId>: {status, extractionId, entryCount?, pending?}}}` for documents with a non-terminal run or a latest run, via one scoped query; entry counts only for Edit viewers (view-only members get the status tag only, per UI-SPEC). Same identical-403 rule, `Cache-Control: no-store, private` from `NoStoreDynamicResponses`, fetched with `securityFetch`, 423 mapped by `handleHealthRefusal`. It replaces "Inertia partial reload (`only`)" in the UI-SPEC wording: `PersonPage` eagerly builds all props, so an `only:` reload would still run every query. Initial statuses ride in the normal page props. Client behaviour (5 s, 15 s after 2 min, pause on hidden tab, stop on terminal state or 15 min) is unchanged. The route is under `/health`, so `SensitiveRoutes` finds it automatically and needs an `EndpointMatrix` row and `MedicalPrivacy` payloads.

**Admin login health (the app image has no `codex`):** the `ai-worker` is the only place `codex login status` can run, so it writes the answer and the app reads a row.
- `CheckLoginJob` (`ShouldBeUnique`, `uniqueFor` 300 s, on queue `ai`) runs `codex login status` through `CodexRunner` (env scrubbed, 10 s timeout) and upserts `ai_agent_status {login_state, checked_at}`. Scheduled every 5 minutes by the `scheduler` (`Schedule::job(new CheckLoginJob)->everyFiveMinutes()`, the `RecordHeartbeat` pattern) and dispatched by "Check again" (`POST settings.ai_calls.check`, `access:admin` + `access:recent`, throttled).
- States shown: `logged_in`, `logged_out` ("Login expired"), `wrong_mode` (treated as expired), `unknown` (never checked). "Worker not responding" = no `checked_at` newer than 15 minutes (a 5-minute job waits behind at most one 5-minute run). `last_auth_failure_at` from real runs (Q1) overrides `logged_in` to "Login expired" until the next success.
- "Run AI" is disabled for editors when the state is not `logged_in` or the worker is stale (UI-SPEC PROVISIONAL 6); the run route also refuses server-side with the same generic copy.
- "Check again" polling: the Settings card reloads only its `login` prop every 3 s for up to 30 s (Settings props are cheap, so an Inertia partial reload is fine there).

## Q10. Privacy and security (threats, terms)

| Threat | STRIDE | Mitigation (this phase) |
|--------|--------|--------------------------|
| Prompt injection in a document (text in the scan says "ignore previous instructions", "output allergy X") | Tampering | Strict structured output, no shell tool, no web search, no MCP, read-only sandbox flag, scrubbed env, prompt marks document as untrusted data, review-before-save, AI-extracted/Unverified labels, exports exclude unverified. Residual: false values; accepted, reviewed by a person |
| Exfiltration by a hijacked agent | Information disclosure | No command or search tool exists; env has no secrets; DB creds sit in the PHP process, not in the CLI's environment; `medical:ro`, tmpfs work dir; container egress is still open to the internet (domain filtering is not available in compose, record as accepted residual) |
| Medical document leaves the server | Information disclosure | Explicit per-document action, per-person consent flag, one-time notice, "Sends this document to OpenAI" line; data-controls/training setting of the ChatGPT plan is a live-session owner check |
| Content in logs, `failed_jobs`, audit, exceptions | Information disclosure | Content-free `FailureCode` enum only; stderr/stdout never stored; arch/scan test: nothing in `App\Medical\Ai` logs or interpolates CLI output; canary leak tests (extend `MedicalPrivacy` canary through a fake CLI that echoes the canary into stderr and `-o`) |
| Temp dir left behind after a crash | Information disclosure | tmpfs mount (discarded on container stop), `finally` cleanup, stale-dir sweep at worker boot and per job, dir mode 0700 |
| `auth.json` exposure or sharing | Spoofing | Only the `codex-home` volume of `ai-worker`; never in DB, UI, env, backups, compose `environment`, logs or an image layer; separate volume per stack; never copied between staging and production; check-compose and a backup-script grep enforce it |
| API key silently switching to per-token billing | Elevation (cost) | Preflight refusal, `env -i`, `forced_login_method="chatgpt"`, `login status` must say ChatGPT |
| Cost/abuse: repeated Run AI | DoS | Unique active run per document, throttle on run route, concurrency 1, optional per-person daily cap config (Phase 9 owns daily caps; a simple `ai.max_runs_per_document_per_day` guard is cheap) |
| Hostile PDF vs poppler | DoS/Elevation | `ulimit -v`, timeouts, unprivileged user, no network needed, sniffed `%PDF-` |
| Stale access (removed member, consent off mid-run) | Elevation | Run-time re-verification and in-loop abort (Q7) |

**Codex terms for this use vs CLAUDE.md.** CLAUDE.md says OpenAI is "most permissive", ChatGPT-managed auth in automation being an "advanced option for trusted runners". Re-checked 2026-11-14: still true in substance, with three nuances: (1) the CI/CD auth guide now describes the audience as "enterprise and other trusted private automation that must run as a ChatGPT-managed Codex account", says "API keys are still the recommended option for most CI/CD jobs", and **does not address personal plans or terms of service**; (2) it adds hard operational rules: "Use one `auth.json` per runner or per serialized workflow stream", "Do not share the same file across concurrent jobs or multiple machines", "Do not use this workflow for public or open-source repositories", never store `auth.json` in repos, logs or public artifacts; (3) OpenAI's general Terms of Use list among prohibited uses "automatically or programmatically extract data or Output" `[CITED via search snippet of openai.com/terms, MEDIUM]`; whether that clause governs a personal-subscription Codex CLI on a private server is **not answered by any OpenAI page found**. The repository is public (docs/ops/server.md), but the `auth.json` is not in it, so the "public repository" restriction is about where the credential lives. Nothing found contradicts CLAUDE.md, but "ToS permissive for a family server" is **[ASSUMED]** until the owner confirms in the live session (and the data-controls setting is checked there). No Anthropic or Google path is touched.

## Q11. Validation architecture (tests, commands, extensions)

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.7.8 (PHP, in the `app` container) + Vitest ^5.1 + axe-core 4.14.0 (host) |
| Config file | `phpunit.xml` (`memory_limit` 512M, `QUEUE_CONNECTION=sync`, DB `family_hub_test` as `fh_owner`), `vitest.config.js` |
| Quick run command | `docker compose run --rm app ./vendor/bin/pest --compact <file>` |
| Full suite command | `docker compose run --rm app php -d memory_limit=1G ./vendor/bin/pest --compact && npx vitest run` (phpunit.xml's 512M wins over the flag) |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AI-01 | Run route: consent off, hidden person, view-only, heic, double start, queue + afterCommit | feature | `... pest tests/Feature/Health/AiRunTest.php` | Wave 0 |
| AI-01 | Exact CLI argv, env allowlist, cwd, prompt on stdin | unit (fake CLI `recording`) | `... pest tests/Unit/Ai/CodexRunnerTest.php` | Wave 0 |
| AI-01 | timeout, idle, group kill, output caps, cancel/consent abort | unit | same file | Wave 0 |
| AI-02 | Validator accepts golden, rejects each violation; schema/validator drift test | unit | `... pest tests/Unit/Ai/ExtractionValidatorTest.php` | Wave 0 |
| AI-02 | Job states: ready, failed(invalid/empty/no file), cancelled(consent/access), supersede on ready, no partial proposal | feature | `... pest tests/Feature/Health/AiRunJobTest.php` | Wave 0 |
| AI-02 | Accept atomic, idempotent, 422 without decision row, `source='ai'`, unverified label, visit link | feature | `... pest tests/Feature/Health/AiAcceptTest.php` | Wave 0 |
| AI-02 | PDF page route: kinds, range, headers (`no-store`), identical 403, rasterizer limits | feature + unit | `... pest tests/Feature/Health/DocumentPageTest.php` | Wave 0 |
| AI-03 | `ai_calls` triggers, grants, definer prune (SET ROLE fh_app) | feature | `... pest tests/Feature/Ai/AiCallsGuardTest.php` | Wave 0 |
| AI-03 | Admin list: filters, names-only, no content canaries, 12-month line, non-admin 403 | feature | `... pest tests/Feature/Settings/AiCallsPageTest.php` | Wave 0 |
| AI-04 | Preflight (keys set, binary missing, retry_after), empty `APP_KEY` boots, compose assertions | unit + script | `bash scripts/check-compose.sh` and `... pest tests/Unit/Ai/PreflightTest.php` | Wave 0 |
| AI-04 | Login health states and "not responding" | feature | `... pest tests/Feature/Ai/LoginHealthTest.php` | Wave 0 |
| UI | `RunAiControl`, `AiNoticeDialog`, `AiReviewPage`, `AiCallsSection`, `ProvenanceTags`, plural tests, polling behaviour, focus rules, no storage, SK/PL +40% | vitest + axe | `npx vitest run tests/js/health/Ai*.test.js tests/js/i18n-phase8.test.js` | Wave 0 |

### Existing suites that must be extended (the plan that adds each route/table must do it in the same plan)
- `tests/Support/EndpointMatrix/health-ai.php`: a literal row with a success role and `params` closure for **every** `access:module,health` route (run, cancel, status, review page, accept, edit-accept, reject, undo, page image). `SensitiveGateMatrixTest` replays each in html/json/inertia/head/prefetch shapes; the fresh html replay cannot send multipart (not relevant here, all JSON/form).
- `tests/Support/MedicalPrivacy.php` area file `tests/Support/MedicalPrivacy/zz_ai.php` (see Q12 for the ordering reason): hidden/visible seeds with canary proposal payloads, params `extraction`, `entry`, `page`, payloads for each write route; add `medical_ai_extractions` and `medical_ai_decisions` to `READ_TABLES` and `MUTATION_TABLES`.
- `tests/Unit/Health/MedicalMutationScanTest.php`: add the new owned tables and models to `MD_MUT_OWNED`; allowlist the job-side class with its documented reason (no viewer, lifecycle columns only); extend the file-store scan for the one new `MedicalDocumentStore::copyTo` caller.
- `tests/Unit/Arch/MedicalPrivacyArchTest.php`: Settings-controller rule (`AiCallsController` needs `AdminAiCallQuery`, so extend the three allowlists deliberately, pinned like `MembersController`), plus new arch tests: `Process`/`proc_open`/`exec`/`shell_exec`/`pdftoppm`/`codex` only in `CodexRunner` and `PdfRasterizer`; `App\Medical\Ai` has no `Log::`/`report(`/`dd(`; Health controllers do not reference `CodexRunner`.
- `tests/Support/SensitiveRoutes.php`: new admin routes are named `settings.ai_calls.*` (no `health` substring) so they are outside the health net; add them to the admin allowlist reasoning (names only), as `settings.health_people` did.
- Settings section counts: `settings.ai_calls` is admin-only and adds a 7th section for admins; update `'sections', 6` in `MembersPageTest`, `AuditPageTest`, `AccountAdminTest` `[VERIFIED: grep]`; members stay at 3.
- Lang: `lang/en.json`, `lang/sk.json`, `lang/pl.json` (nested `health.ai.*`, `settings.ai_calls.*`, `settings.ai_login.*`), `tests/js/i18n-phase8.test.js` (key parity, SK/PL four plural segments, 0/1/2/4/5/12/22/25), `MedicalLangTest`-style PHP test for new audit events. Remove `health.ai.not_available` and the line it feeds in `AiConsentControl` (checker follow-up) together with its tests (`AiConsent.test.js`, `AiConsentTest`).
- Ops scripts: `check-compose.sh` assertions (Q2), `smoke-local.sh` service list (or the `ai` profile exclusion), `deploy.sh` image/up lists, CI third image, a `test-deploy-guards.sh` case if it enumerates services.
- Leak tests with the fake CLI: a fake that writes the canary into stderr, stdout and `-o`; after a `failed` and a `ready` run assert the canary is absent from `failed_jobs`, `storage/logs/*`, `audit_logs.meta`, `ai_calls`, the status route and every admin page.

### Sampling Rate
- **Per task commit:** the task's own Pest file and, for UI, its Vitest file.
- **Per wave merge:** `bash scripts/check-compose.sh`, `SensitiveGateMatrixTest`, `MedicalPrivacyTest`, `MedicalPrivacyArchTest`, `MedicalMutationScanTest`.
- **Phase gate:** full Pest + full Vitest + `npm run build` + `bash scripts/check-compose.sh` green before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Support/FakeCodex.php`, `tests/Support/MedicalPrivacy/zz_ai.php`, `tests/Support/EndpointMatrix/health-ai.php`
- [ ] All test files in the map above
- [ ] Framework install: none (no new test dependency)

**Commands that run in containers:** all Pest invocations (`docker compose run --rm app ./vendor/bin/pest ...`, use `-p fh08xx` project names per plan to avoid clashes, as Phase 7 did, and `down -v` afterwards), `bash scripts/check-compose.sh` (needs `docker compose config`), the fake-CLI tests (busybox `env`/`setsid`/`sh` exist only in the container), `docker build --target ai-worker` size check, the Postgres role/trigger test. Vitest, axe and `npm run build` run on the host. No test starts the real `ai-worker`.

## Q12. Phase 7 pitfalls to carry forward

1. **`MedicalPrivacy` area files run in sorted filename order and a `hidden` seed may read `$ctx['hidden']['person']` that an earlier-sorting file has not created yet** (the 07-08/07-09 failure) `[VERIFIED: 07-09 summary, MedicalPrivacy.php contract]`. Existing order: `consent, emergency, exports, subjects, subjects_allergies, subjects_documents, subjects_documents_files, subjects_documents_page, subjects_medications, subjects_vaccinations, visits`. The AI area needs documents and visits seeded first, so name it `zz_ai.php` (sorts last).
2. **Memory:** the privacy test and the suite peak at 275 MiB against `memory_limit` 512M (`phpunit.xml`; a `-d memory_limit` flag does not override it). Keep new fixtures light: one extraction with two entries, payloads under 2 KB, share seeds; re-measure peak after adding the area and the new matrix rows.
3. **Settings section-count assertions** (`has('sections', 6)` in three files, `3` for members) change when `ai_calls` appears (Q11).
4. **Lang files** are `lang/en.json`, `lang/sk.json`, `lang/pl.json` (nested), not the `en-GB` style names of the 07 spec; PHP-side `lang/{en,sk,pl}/*.php` exist for notification strings only. SK and PL need exactly four plural segments, `t(key, {n}, n)` call sites, names never concatenated into grammar. Native review flag stays on the new SK/PL strings.
5. **Flaky `Storage::fake('medical')`** (shared on-disk dir) caused one transient matrix failure in 07-12: new tests use unique project names and avoid `Storage::fake` where they can use a temp config disk root.
6. **Role the tests run as** is `fh_owner`; privilege behaviour must be tested with `SET ROLE fh_app` inside the transaction.
7. **`UiDetailPanel` modal change (07-19)** and `a11y-sweep.test.js`: new Vue surfaces (`RunAiControl` inside `DocumentPanel`, the review page) must pass axe in the existing sweep and at 320 px.
8. **Audit meta allowlists:** `AuditLogger` throws on any unlisted key, so every new event needs `metaKeys` before it is called.
9. **No route model binding, no `whereUuid`:** every miss answers the identical 403; resolve ids in Query classes.
10. **Hard delete cascades:** `medical_ai_*` must cascade with person and document (composite FK), and the file-removal-after-commit pattern is unchanged; `ai_calls` intentionally survives.
11. **Phase 7 open review items touching this phase:** WR-07 (any Edit member can switch AI consent on, including for a person with their own account) becomes materially more serious now that consent triggers external sending; it is a recorded owner decision for the live session, not for this phase to change silently.

## Common Pitfalls

### Pitfall 1: `-o` exists but is empty
**What goes wrong:** treating file existence as success. **Why:** unauthenticated and some failed runs create an empty `-o` file `[VERIFIED]`. **Avoid:** non-empty + size cap + validator. **Warning sign:** `ready` rows with `entry_count = 0` and no `turn.completed`.

### Pitfall 2: Job re-delivered while Codex is still running
**What goes wrong:** the database queue releases a job after `retry_after` (default 90 s), a second run uploads the same document again. **Avoid:** `DB_QUEUE_RETRY_AFTER` 900 in `ai-worker.env`, `$tries=1`, atomic claim, preflight assertion.

### Pitfall 3: Orphaned grandchildren after a timeout
**Avoid:** `setsid` + `posix_kill(-pgid)`, `init: true`, group kill tested with the forking fake `[VERIFIED mechanism]`.

### Pitfall 4: `login status` says "logged in" while runs fail
**Avoid:** combine with last-run auth signature; weekly keep-alive decision; document "expired" copy for the admin.

### Pitfall 5: Variadic `-i` swallowing the prompt
**Avoided empirically** (all shapes parsed) but keep the no-positional shape and a unit test on the exact argv.

### Pitfall 6: Stale `CODEX_HOME` config or env leaking behaviour
**Avoid:** `--ignore-user-config`, `-c` for every setting, `env -i`, never a `config.toml` in the volume.

### Pitfall 7: Writing files onto the `medical` disk
**Avoid:** nothing derived is persisted; tmpfs work dir; the orphan sweep stays valid.

### Pitfall 8: `ai-worker` image rebuilt and re-pushed on every commit
**Avoid:** Codex and poppler layers below `COPY . .`; pinned lockfile; CI cache.

### Pitfall 9: Supersede at queue time destroys reviewable suggestions when the re-run fails
**Avoid:** supersede when the new run becomes `ready` (Q5 gap 3).

### Pitfall 10: Deploy and smoke scripts silently ignore the new service
**Avoid:** update `deploy.sh`, `smoke-local.sh`, `check-compose.sh`, CI in the infrastructure plan, and add a check that every compose service appears in the deploy `up` list.

## Code Examples

### Preflight guard (shape)
```php
// Source: this research; names illustrative
foreach (['OPENAI_API_KEY','CODEX_API_KEY','ANTHROPIC_API_KEY'] as $k) {
    if (getenv($k) !== false && getenv($k) !== '') { $this->error("$k is set"); return self::FAILURE; }
}
```

### `ai_calls` guard SQL (verified on postgres:18-alpine)
```sql
-- lifecycle trigger: identity immutable, status only forward, terminal frozen
CREATE FUNCTION ai_calls_guard_update() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE o int; n int;
BEGIN
  IF NEW.id IS DISTINCT FROM OLD.id OR NEW.subject_id IS DISTINCT FROM OLD.subject_id
     OR NEW.queued_at IS DISTINCT FROM OLD.queued_at THEN RAISE EXCEPTION 'ai_calls identity columns are immutable'; END IF;
  o := CASE OLD.status WHEN 'queued' THEN 0 WHEN 'running' THEN 1 ELSE 2 END;
  n := CASE NEW.status WHEN 'queued' THEN 0 WHEN 'running' THEN 1 ELSE 2 END;
  IF o = 2 OR n < o THEN RAISE EXCEPTION 'ai_calls status only moves forward'; END IF;
  RETURN NEW;
END $$;
REVOKE DELETE, TRUNCATE ON ai_calls FROM fh_app;           -- default privileges granted them
-- definer prune: owner runs it, fh_app may only EXECUTE
CREATE FUNCTION ai_calls_prune(months int DEFAULT 12) RETURNS bigint LANGUAGE plpgsql
  SECURITY DEFINER SET search_path = public, pg_temp AS $$
DECLARE n bigint;
BEGIN
  PERFORM set_config('ai.prune', 'on', true);
  DELETE FROM ai_calls WHERE queued_at < now() - make_interval(months => months);
  GET DIAGNOSTICS n = ROW_COUNT; RETURN n;
END $$;
REVOKE ALL ON FUNCTION ai_calls_prune(int) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ai_calls_prune(int) TO fh_app;
```
The delete/truncate guard function is the `audit_logs_guard_removal` pattern with `ai.prune`; the migration also refuses to run in production/staging when `fh_app` is missing, like the audit migration.

### Per-entry idempotent decision
```php
// Source: this research
$inserted = DB::table('medical_ai_decisions')->insertOrIgnore([...]);   // PK (extraction_id, entry_key)
if ($inserted === 0) { return $this->currentState($extraction, $entryKey); }  // already decided: no second record
```

### Minimal fake CLI (shape)
```sh
#!/bin/sh
out=""; while [ $# -gt 0 ]; do case "$1" in -o) out="$2"; shift;; esac; shift; done
cat >/dev/null                                  # prompt on stdin
printf '%s' '{"schema_version":1,"document_date":null,"specialty_key":null,"visits":[],"medications":[],"allergies":[],"vaccinations":[]}' > "$out"
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `npm i -g @openai/codex` (Node wrapper) | Native platform package under `vendor/<triple>/`; wrapper only spawns it | 0.16x series | Final image needs no Node |
| `docs/codex` on developers.openai.com | Redirects (308) to `learn.chatgpt.com/docs/...` | observed 2026-11-14 | Cite the new URLs; the CLI also gained `--with-access-token`, `--ignore-user-config`, `forced_login_method`, `--strict-config` |
| `features.web_search` | Top-level `web_search = "disabled\|cached\|indexed\|live"` | current reference | Use the top-level key |
| `--full-auto` | Removed; use `--sandbox workspace-write` | current docs | Irrelevant (we use read-only) |
| Single `auth.json` shared freely | One per runner or serialized stream, never concurrent/multi-machine | current CI/CD auth guide | Concurrency 1, separate login per stack |

**Deprecated/outdated:** CLAUDE.md's `@openai/codex 0.167.2`, "image already ships pdftoppm" (UI-SPEC), Next.js/pg-boss/execa material (ignored, Laravel equivalents used).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `features.shell_tool=false`, `web_search="disabled"`, `forced_login_method="chatgpt"`, `history.persistence="none"`, `--disable memories` actually take effect in a real run (only parse-acceptance verified) | Q1 | A tool or persistence exists that the plan assumed removed; mitigated by scrubbed env, no secrets in reach, read-only mount, review step |
| A2 | `--output-schema` accepts exactly the OpenAI strict-mode subset described (nullable unions, `enum`, `maxItems`, no `maxLength`) | Q4 | Schema rejected at run time; fix by loosening; validator remains authoritative |
| A3 | Auth-failure signature strings (`401`, "sign in", "log in again", "refresh token") | Q1, Q9 | "Login expired" mis-detected; classify from a captured real transcript in the live session |
| A4 | Default model `gpt-6.1-sol` is usable on the owner's subscription; omit `-m` and record `default` | Q1 | Runs fail with a model error; set `AI_CODEX_MODEL` after checking `/model` availability |
| A5 | Codex runs with `voice/` removed and read-only `medical` mount and `HOME=/codex` | Q2 | `exec` needs a removed resource; restore the directory (+40 MB) |
| A6 | Server is x86_64 (Hetzner VPS) | Package audit | Dockerfile triple selection must also handle aarch64 (it does by `uname -m`) |
| A7 | Memory: ai-worker 384m is enough; 2.7 GB budget fits two stacks | Q2 | OOM kill during a run; measure `docker stats` live, possibly run staging ai-worker on demand |
| A8 | Timeouts (process 300 s, idle 120 s, job 330 s, `retry_after` 900) and PDF caps (10 pages, `-scale-to 1800`, `ulimit -v 786432`) | Q2, Q3, Q5 | Too short kills valid runs, too long wastes quota; tune after the first real runs |
| A9 | Codex does not read HEIC; HEIC documents are excluded from AI | Q5 | A supported format needlessly blocked (low cost) |
| A10 | OpenAI ToU allow this personal-subscription use on a private family server; data-controls and training settings acceptable | Q10 | Account action; owner confirms in the live session |
| A11 | Env names `OPENAI_BASE_URL`, `OPENAI_ORG_ID`, `CODEX_ACCESS_TOKEN`, `CODEX_AGENT_IDENTITY` also deserve refusal | Q2 | None (extra caution) |
| A12 | Proposal retention 90 days; weekly keep-alive run | Q6, Q1 | Owner decisions, not technical risks |
| A13 | `login status` source read from `main`, behaviour identical in 0.169.0 | Q1 | Exit codes differ; the live session runs `codex login status; echo $?` in both states |

## Open Questions (RESOLVED)

1. **Superseding timing and UI gaps (HEIC, page cap, wording)**
   - What we know: UI-SPEC supersedes at queueing, never mentions HEIC or page caps, and the checker asked for changed wording.
   - What's unclear: whether the owner accepts the deviations.
   - Recommendation: supersede on `ready`; HEIC refused with a new line; cap line with plural key; add all keys in the early lang plan.
   - RESOLVED: supersede on ready (08-12); HEIC refused with its own line, 10-page cap with pages-read line, wording keys (08-01, 08-13, 08-16); owner may revisit HEIC conversion and the cap in 08-22.

2. **Is "append-only `ai_calls`" satisfied by a lifecycle-guarded row?**
   - What we know: statuses must progress for Waiting/Running to show; identity immutability, forward-only status and no delete/truncate for `fh_app` are enforceable and verified.
   - Recommendation: accept this reading; the alternative (terminal-only inserts) loses Waiting/Running in the admin table.
   - RESOLVED: provisional default lifecycle-guarded row (08-04), owner confirms in 08-22 (Decision C).

3. **Dedicated Postgres role `fh_ai` instead of `fh_app` for `ai-worker`?**
   - What we know: needs a superuser `CREATE ROLE` on the existing server and edits to the init script and migrations; column grants add maintenance.
   - Recommendation: ship `fh_app` + separate env file now, record `fh_ai` as optional hardening with the exact GRANT list for the live session.
   - RESOLVED: provisional default fh_app + separate ai-worker.env (08-07), owner confirms in 08-22.

4. **Memory budget for two stacks.**
   - What we know: about 1.44 GB used by both stacks of 2.7 GB available (docs/ops/server.md); Codex RSS unknown.
   - Recommendation: default `MEM_AI=384m`, measure live, and run the staging `ai-worker` only during tests if needed.
   - RESOLVED: provisional default MEM_AI=384m (08-07), measured live in 08-22.

5. **Keep-alive for the refresh token.**
   - What we know: Codex refreshes during normal runs; long idle periods may let it lapse; `login status` cannot detect it.
   - Recommendation: optional weekly tiny `codex exec` job; owner decides on quota use.
   - RESOLVED: provisional default no keep-alive job (08-10), owner decides in 08-22 after observing refresh metadata.

6. **WR-07 (who may switch consent on).** Becomes consequential now; recommend the live-session decision (linked user only, or notification).
   - RESOLVED: behaviour unchanged in Phase 8, flagged as owner Decision B in 08-22.

7. **Proposal retention and admin visibility of `ai_calls.model` when the model is `default`.** Defaults given above; low risk.
   - RESOLVED: provisional default no time-based proposal prune (08-04); ai_calls.model NULL at queue time, set once at finish to the CLI-reported name or 'default' (08-04, 08-12); owner confirms in 08-22.

## Live-session verification checklist (what could not be verified here)

1. `codex login --device-auth` in the `ai-worker` container (as the app uid), then `codex login status; echo $?` (expect `Logged in using ChatGPT`, 0) and again after removing `auth.json` (expect 1).
2. One real run on a synthetic scan with the exact argv above. Confirm: `-o` content validates, `--json` events, the `model:` line, that the model has no shell/search tool, that `forced_login_method` does not break ChatGPT login, `docker stats` RSS, wall time.
3. After that run: `grep -r <canary-in-prompt-and-document> /codex` to see what `CODEX_HOME` kept (`logs_2.sqlite`, `state_5.sqlite`, `memories_1.sqlite`).
4. Capture a real expired/revoked login transcript (stderr and `--json` error events) and freeze the `auth` matcher; capture the usage-limit message for Phase 9.
5. Check `--output-schema` acceptance of schema v1 verbatim and the HEIC/PDF `-i` behaviour (expect rejection or ignore).
6. Confirm `read_only: true` + `cap_drop: [ALL]` + `no-new-privileges` still start the worker (optional hardening).
7. ChatGPT plan data-controls/training setting, and the owner's reading of the OpenAI terms for this use.
8. Confirm the weekly keep-alive need by observing `last_refresh` in `auth.json` metadata (never print tokens).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| Docker + Compose | tests, builds | yes | Docker 29.9.2 | none (blocking if absent) |
| `family-hub-app:dev` image | probes, tests | yes | PHP 8.4, Alpine 3.24.4, uid 1000 | rebuild |
| poppler-utils in app image | PDF pages, rasterizing | **no** (apk available, 25.12.0-r1, +16 MB) | - | add to `base` stage (planned) |
| Codex CLI | live session only | installable (0.169.0 verified on this image) | 0.169.0 | fake CLI for all tests |
| Node (host) | Vitest, build | yes | v22.23.5 | - |
| Postgres 18 | tests, trigger verification | yes (postgres:18-alpine) | 18 | - |
| `setsid`, `env`, `sh` (busybox) | runner, fake CLI | yes (in the PHP alpine image) | busybox 1.37 | refuse to run (preflight) |
| `ext-posix`, `ext-pcntl` | group kill, job timeout | yes | - | - |
| slopcheck | package audit | no | - | `[ASSUMED]` tag + `checkpoint:human-verify` |
| ChatGPT login / network to OpenAI | live session | not available to this research | - | deferred live plan |

**Missing dependencies with no fallback:** none for the build-and-test scope.
**Missing dependencies with fallback:** poppler (added by the plan), real Codex login (deferred, fake CLI covers all automated tests).

## Security Domain

### Applicable ASVS Categories (level 1, `security_enforcement` enabled)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes | Existing Fortify + idle/second-factor gate on every route; Codex login is infrastructure auth, never in the app UI |
| V3 Session Management | yes | Existing; polls and review pages inherit the gate and `no-store` |
| V4 Access Control | yes | `access:module,health`, `Visibility` Edit scope, identical 403, run-time re-verification, admin-only AI calls page |
| V5 Input Validation | yes | `ExtractionValidator` + Phase 7 form rules at accept; strict schema; byte caps; JSON depth cap |
| V6 Cryptography | partial | No new crypto; restic encrypts backups; `auth.json` never backed up in plain; no app-level file encryption (Phase 7 decision) |
| V8 Data Protection | yes | Content-free logs/audit/call table, tmpfs temp, no-store, consent + notice, retention prune |
| V12 Files | yes | Sniffed types, no client file names, derivative never persisted, read-only mount |
| V14 Configuration | yes | Env split, preflight, check-compose assertions, pinned CLI with lockfile integrity |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Prompt injection through document content | Tampering | Strict structured output, no tools, review before save, provenance labels |
| Command injection via file names or model text | Elevation | Array argv, generated ULID names, output never reaches a shell |
| Credential theft from `auth.json` | Spoofing/Disclosure | Single-container volume, no backup, no logs, separate per stack |
| Audit/log/queue payload content leak | Disclosure | Ids only in jobs, `FailureCode` enum, canary tests |
| Unbounded process or output | DoS | Timeouts, group kill, output caps, concurrency 1, throttles |
| IDOR on extraction/entry ids | Disclosure/Tampering | Scoped queries, opaque UUIDs, identical 403, matrix + differential tests |
| Per-token billing by accident | Cost | Env refusal, `env -i`, `forced_login_method`, status must say ChatGPT |

## Sources

### Primary (HIGH confidence)
- Installed code read this session: `docker/app/Dockerfile`, `compose.yaml`, `compose.server.yaml`, `scripts/{check-compose,backup,deploy,smoke-local}.sh`, `docker/postgres/init/01-roles.sh`, `config/queue.php`, `phpunit.xml`, `routes/web/health-*.php`, `routes/console.php`, `app/Medical/**`, `app/Audit/*`, `app/Jobs/*`, `app/Http/Controllers/Health/{Document,AiConsent,Visit}Controller.php`, the Phase 7 migrations, the audit migration, `tests/Unit/Arch/MedicalPrivacyArchTest.php`, `tests/Unit/Health/MedicalMutationScanTest.php`, `tests/Support/MedicalPrivacy.php`, `composer.lock`.
- Experiments run in throwaway containers this session: Codex 0.169.0 install, size, `--version` without Node, `codex login status` exit 1, `codex exec` flag parsing and unauthenticated behaviour, bubblewrap failure, canary persistence probe; pdftoppm/pdfinfo install size and timing; Symfony Process group kill / env scrub / output cap; Laravel boot with empty `APP_KEY`; Postgres 18 trigger/grant/definer-prune test.
- https://learn.chatgpt.com/docs/non-interactive-mode (redirect from developers.openai.com/codex/noninteractive): exec stdout/stderr, `-o`, `--output-schema`, `--ephemeral`, ChatGPT-managed auth warnings.
- https://learn.chatgpt.com/docs/developer-commands?surface=cli: full `codex exec` flag table, `codex login` and `login status`.
- https://learn.chatgpt.com/docs/auth and https://learn.chatgpt.com/docs/auth/ci-cd-auth.md: credential store options, refresh behaviour, one `auth.json` per runner, audience and restrictions.
- https://learn.chatgpt.com/docs/config-file/config-reference: `web_search`, `features.shell_tool`, `mcp_servers`, `forced_login_method`, `history.persistence`, `analytics.enabled`, `check_for_update_on_startup`, `shell_environment_policy`.
- https://developers.openai.com/api/docs/guides/structured-outputs(.md): supported schema subset and limits.
- npm registry (`npm view @openai/codex`), 2026-11-14: version 0.169.0, maintainers, repository, no postinstall.

### Secondary (MEDIUM confidence)
- https://raw.githubusercontent.com/openai/codex/main/codex-rs/cli/src/login.rs and `codex-rs/exec/src/cli.rs` (summarised by the fetch tool; `main`, not the 0.169.0 tag).
- https://developers.openai.com/codex/concepts/sandboxing and community pages on bubblewrap in Docker (consistent with the verified failure).

### Tertiary (LOW confidence)
- Search snippets of openai.com/terms about "automatically or programmatically extract data or Output" (wording only, scope for Codex subscriptions not stated).
- Third-party blogs on Codex auth in CI (not relied on).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, no new dependency, Codex and poppler verified by running them in the project image.
- Architecture: HIGH for everything built on existing Phase 7/Audit machinery and the verified SQL/process patterns; MEDIUM for the Codex-side effects tagged `[ASSUMED]`.
- Pitfalls: HIGH for those reproduced here (empty `-o`, no group kill by default, retry_after, bwrap, login status semantics); MEDIUM for auth-signature classification and idle-period token expiry.

**Research date:** 2026-11-14
**Valid until:** about 14 days for Codex CLI specifics (fast-moving: docs moved domains and flags changed within the last releases); 30 days for the rest.
