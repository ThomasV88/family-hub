---
phase: 09
slug: multi-agent-quota-and-bulk-regeneration
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-11-14
---

# Phase 09 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Drafted at planning time from the 20 plans (task-to-test map below) and revised after the plan checker (6 warnings: CodexAgentTest in 09-09, bulk completion rule, provider-acknowledgment recheck, leaving `waiting`, bulk-row masking in the call log, 09-10 work order). Plan 09-19 ran the full gate alone, set every row to its real status and signed off (2026-11-14). Plan 09-20 (live Copilot login, real runs, live checklist L1-L8, tiny bulk run, owner decisions, release) stays pending until the live session.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHP, in the app container) + Vitest (frontend) + axe-core |
| **Config file** | phpunit.xml, vitest.config.js |
| **Quick run command** | `docker compose -p fh09NN run --rm app ./vendor/bin/pest --compact <file>` (one compose project per plan, `down -v` afterwards) |
| **Vitest command** | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 <files>` (host Node is 18) |
| **Full suite command** | `docker compose -p fh0919 run --rm app php -d memory_limit=1G ./vendor/bin/pest --compact` then the Vitest command without file arguments and `npm run build` in the same container (09-19 only) |
| **Ops checks** | `bash scripts/check-compose.sh`, `bash scripts/test-deploy-guards.sh`, image checks from 09-02 Task 2 |
| **Estimated runtime** | Pest ~240 s, Vitest ~150 s (Phase 8 baseline 224.7 s / 130 files plus Phase 9) |

Notes: phpunit.xml sets `memory_limit` 512M, which overrides the `-d` flag; the Phase 8 peak was 305.5 MiB allocated (263.7 MiB used), raise threshold about 410 MiB. No test runs a real AI CLI: `tests/Support/FakeCodex.php` and `tests/Support/FakeCopilot.php` (new, 09-07) replace them everywhere. Tests that open a second DB connection purge it in `afterEach` (dev Postgres `max_connections=20`).

---

## Sampling Rate

- **After every task commit:** run only the task's own command (map below), under the plan's own compose project `fh09NN`
- **After every plan:** the plan's own test files only. Plans that share a wave never run the full Pest suite (Phase 8 lesson: parallel full runs against the shared tree caused one-off failures); the full suites run once, alone, in 09-19
- **Per wave merge:** `bash scripts/check-compose.sh`, `SensitiveGateMatrixTest`, `MedicalPrivacyTest`, `MedicalPrivacyArchTest`, `MedicalMutationScanTest`, `AiArchTest`, `tests/Feature/Ai` (run by the orchestrator between waves, under a fresh compose project)
- **Before `/gsd-verify-work`:** full suite, build, check-compose, deploy guards and image checks green (09-19 Task 3)
- **Max feedback latency:** about 15 s per task command, about 7 min for the full gate

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 09-01-01 | 01 | 1 | AI-05,AI-06,AI-07 | T-09-01..02 | All Phase 9 copy in EN, SK and PL with a parity and plural test | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/i18n-phase9.test.js tests/js/i18n-phase8.test.js` | W0 (created by the task) | ✅ green |
| 09-01-02 | 01 | 1 | AI-05,AI-06,AI-07 | T-09-01..02 | Two content-free audit events and their rendering | pest+vitest | `docker compose -p fh0901 run --rm app ./vendor/bin/pest tests/Feature/Audit/AuditAiAgentEventsTest.php tests/Unit/MedicalLangTest.php --compact && docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/settings/AuditHealth.test.js` | W0 (created by the task) | ✅ green |
| 09-02-01 | 02 | 1 | AI-04 | T-09-SC, T-09-03..07 | Package legitimacy evidence and the pinned lockfile | script | `node -e "const p=require('./docker/ai/copilot/package.json');const l=require('./docker/ai/copilot/package-lock.json');const k=Object.keys(l.packages\|\|{});if(p.dependencies['@github/copilot']!=='1.0.112'\|\|!k.some(x=>x.endsWith('copilot-linuxmusl-x64'))\|\|!k.some(x=>x.endsWith('copilot-linuxmusl-arm64')))process.exit(1)" && grep -q "docker/ai/copilot/node_modules" .dockerignore` | W0 (created by the task) | ✅ green |
| 09-02-02 | 02 | 1 | AI-04 | T-09-SC, T-09-03..07 | Copilot build stage and ai-runtime copy; image checks | script | `docker run --rm family-hub-ai:check sh -c 'env -i PATH=/usr/bin:/bin HOME=/tmp COPILOT_HOME=/tmp/ch COPILOT_CACHE_HOME=/opt/copilot/cache /opt/copilot/bin/copilot --version \| grep -q "1.0.112" && ls /opt/copilot/cache/pkg/*/*/.extraction-complete && test -f /opt/copilot/LICENSE.md && test -d /copilot && ! command -v node && command -v codex' && docker compose -p fh0902 run --rm app sh -c '! command -v copilot && ! test -d /opt/copilot'; docker compose -p fh0902 down -v` | W0 (created by the task) | ✅ green |
| 09-02-03 | 02 | 1 | AI-04 | T-09-SC, T-09-03..07 | Compose, static checks, env rule and runbook | script | `bash scripts/check-compose.sh && bash scripts/test-deploy-guards.sh && grep -q "ai-control,ai,ai-bulk" compose.server.yaml && grep -q "ai-control,ai,ai-bulk" compose.yaml && grep -q "copilot-home" scripts/check-compose.sh` | W0 (created by the task) | ✅ green |
| 09-03-01 | 03 | 1 | AI-05,AI-06,AI-07 | T-09-08..11 | Agent settings, cooldown columns and notice provider set (M1) with models | pest | `docker compose -p fh0903 run --rm app ./vendor/bin/pest tests/Feature/Ai/AiPhase9SchemaTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-03-02 | 03 | 1 | AI-05,AI-06,AI-07 | T-09-08..11 | Waiting status, per-attempt call rows and bulk columns (M2); pinned tests | pest | `docker compose -p fh0903 run --rm app ./vendor/bin/pest tests/Feature/Ai/AiPhase9SchemaTest.php tests/Feature/Ai/AiSchemaTest.php tests/Feature/Ai/AiCallsGuardTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-03-03 | 03 | 1 | AI-05,AI-06,AI-07 | T-09-08..11 | Bulk tables (M3), models and factory | pest | `docker compose -p fh0903 run --rm app ./vendor/bin/pest tests/Feature/Ai/AiPhase9SchemaTest.php tests/Feature/Ai/AiSchemaTest.php tests/Feature/Ai/AiCallsGuardTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-04-01 | 04 | 1 | AI-04,AI-06 | T-09-12..14 | Config keys, model-id rule, failure codes and optional outcome members | pest | `docker compose -p fh0904 run --rm app ./vendor/bin/pest tests/Unit/Ai/ModelIdTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-04-02 | 04 | 1 | AI-04,AI-06 | T-09-12..14 | Quota signature matcher with reset-time parsing | pest | `docker compose -p fh0904 run --rm app ./vendor/bin/pest tests/Unit/Ai/QuotaSignaturesTest.php tests/Unit/Ai/ModelIdTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-05-01 | 05 | 1 | AI-04 | T-09-15..16 | Strict reply extraction and structural issue codes | pest | `docker compose -p fh0905 run --rm app ./vendor/bin/pest tests/Unit/Ai/JsonReplyTest.php tests/Unit/Ai/ExtractionValidatorTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-05-02 | 05 | 1 | AI-04 | T-09-15..16 | Inline-schema prompt with the repair block | pest | `docker compose -p fh0905 run --rm app ./vendor/bin/pest tests/Unit/Ai/PromptBuilderTest.php tests/Unit/Ai/ExtractionValidatorTest.php tests/Unit/Ai/JsonReplyTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-06-01 | 06 | 2 | AI-06 | T-09-17..19 | Quota scan, TZ and model rule in the Codex runner | pest | `docker compose -p fh0906 run --rm app ./vendor/bin/pest tests/Unit/Ai/CodexRunnerTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-06-02 | 06 | 2 | AI-06 | T-09-17..19 | Codex agent maps quota, takes the admin model and respects the deadline | pest | `docker compose -p fh0906 run --rm app ./vendor/bin/pest tests/Unit/Ai/CodexAgentTest.php tests/Unit/Ai/CodexRunnerTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-07-01 | 07 | 2 | AI-04 | T-09-20..26 | CopilotRunner, request object and the fake CLI | pest | `docker compose -p fh0907 run --rm app ./vendor/bin/pest tests/Unit/Ai/CopilotRunnerTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-07-02 | 07 | 2 | AI-04 | T-09-20..26 | CopilotAgent with validation and one content-free repair | pest | `docker compose -p fh0907 run --rm app ./vendor/bin/pest tests/Unit/Ai/CopilotAgentTest.php tests/Unit/Ai/CopilotRunnerTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-07-03 | 07 | 2 | AI-04 | T-09-20..26 | Arch rules confining Copilot | pest | `docker compose -p fh0907 run --rm app ./vendor/bin/pest tests/Unit/Arch/AiArchTest.php tests/Unit/Ai/CopilotAgentTest.php tests/Unit/Ai/CopilotRunnerTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-08-01 | 08 | 2 | AI-04 | T-09-27..29 | Forbidden GitHub/Copilot variables and Copilot startup checks | pest | `docker compose -p fh0908 run --rm app ./vendor/bin/pest tests/Unit/Ai/PreflightTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-08-02 | 08 | 2 | AI-04 | T-09-27..29 | Work-dir root refuses the Copilot home | pest | `docker compose -p fh0908 run --rm app ./vendor/bin/pest tests/Unit/Ai/WorkDirTest.php tests/Unit/Ai/PreflightTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-09-01 | 09 | 3 | AI-05,AI-06 | T-09-30..33 | Agent settings query (read side and audited admin writes) | pest | `docker compose -p fh0909 run --rm app ./vendor/bin/pest tests/Feature/Ai/AiAgentSettingsQueryTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-09-02 | 09 | 3 | AI-05,AI-06 | T-09-30..33 | Cooldown ladder, daily ceiling and per-agent health | pest | `docker compose -p fh0909 run --rm app ./vendor/bin/pest tests/Feature/Ai/AgentQuotaTest.php tests/Feature/Ai/LoginHealthTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-09-03 | 09 | 3 | AI-05,AI-06 | T-09-30..33 | Ordered registry with candidates and earliest resume; login checks for both agents | pest | `docker compose -p fh0909 run --rm app ./vendor/bin/pest tests/Feature/Ai tests/Unit/Ai/CodexAgentTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-10-01 | 10 | 4 | AI-06,AI-07 | T-09-34..40, T-09-84 | Per-attempt rows, park/resume and finish guards in the system query | pest | `docker compose -p fh0910 run --rm app ./vendor/bin/pest tests/Feature/Ai/FinishReadyAtomicTest.php tests/Feature/Ai/WaitingRunTest.php tests/Unit/Health/MedicalMutationScanTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-10-02 | 10 | 4 | AI-06,AI-07 | T-09-34..40, T-09-84 | The fallback loop in the job, the job budget and the resumer | pest | `docker compose -p fh0910 run --rm app ./vendor/bin/pest tests/Feature/Ai/RunExtractionJobTest.php tests/Feature/Ai/WaitingRunTest.php tests/Feature/Ai/AiScheduleTest.php tests/Unit/Arch/AiArchTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-10-03 | 10 | 4 | AI-06,AI-07 | T-09-34..40, T-09-84 | Leak tests across fallback, repair and waiting | pest | `docker compose -p fh0910 run --rm app ./vendor/bin/pest tests/Feature/Ai/AiJobLeakTest.php tests/Feature/Ai/RunExtractionJobTest.php tests/Feature/Ai/WaitingRunTest.php tests/Feature/Ai/FinishReadyAtomicTest.php tests/Feature/Ai/AiScheduleTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-11-01 | 11 | 4 | AI-06 | T-09-41..45 | Waiting, failure code, provenance and providers in start, cancel, status and the person page | pest | `docker compose -p fh0911 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/AiWaitingStatusTest.php tests/Feature/Health/AiRunTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-11-02 | 11 | 4 | AI-06 | T-09-41..45 | Review payload provenance and newer waiting run; leak-net area | pest | `docker compose -p fh0911 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/AiReviewPageTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Feature/Health/MedicalLeakSuiteTest.php tests/Feature/Health/AiWaitingStatusTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-12-01 | 12 | 4 | AI-05 | T-09-46..49 | Controller, routes, section entry, gate allowlists, matrix and arch pin | pest | `docker compose -p fh0912 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Settings/AiAgentsPageTest.php tests/Unit/Arch/MedicalPrivacyArchTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-12-02 | 12 | 4 | AI-05 | T-09-46..49 | Pinned section counts and order for the eighth admin section | pest | `docker compose -p fh0912 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Settings/MembersPageTest.php tests/Feature/Audit/AuditPageTest.php tests/Feature/Finance/AccountAdminTest.php tests/Feature/Settings/AiCallsPageTest.php tests/Feature/Notifications/NotificationSettingsTest.php tests/Feature/Settings/SettingsShellTest.php tests/Feature/Security/SecurityPageTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-13-01 | 13 | 5 | AI-07 | T-09-50..56 | Eligibility counts and the system-side bulk query | pest | `docker compose -p fh0913 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Ai/BulkRunTest.php tests/Unit/Health/MedicalMutationScanTest.php tests/Unit/Health/MedicalTableScanTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-13-02 | 13 | 5 | AI-07 | T-09-50..56 | Dry run, start, pause, resume, cancel and the counts-only panel | pest | `docker compose -p fh0913 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Ai/BulkRunTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-13-03 | 13 | 5 | AI-07 | T-09-50..56 | Gated bulk endpoints, gate allowlists and matrix rows | pest | `docker compose -p fh0913 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Ai/BulkRunTest.php tests/Feature/Settings/AiAgentsPageTest.php tests/Feature/Security/SensitiveGateMatrixTest.php tests/Unit/Arch/MedicalPrivacyArchTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-14-01 | 14 | 6 | AI-07 | T-09-57..62 | Tick: reconcile, complete, gates and single enqueue | pest | `docker compose -p fh0914 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Ai/BulkTickTest.php tests/Unit/Health/MedicalMutationScanTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-14-02 | 14 | 6 | AI-07 | T-09-57..62 | Command, schedule and end-to-end bulk run through the job | pest | `docker compose -p fh0914 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Ai --compact` | W0 (created by the task) | ✅ green |
| 09-15-01 | 15 | 5 | AI-06 | T-09-63..64 | Quota filter and row flag in the admin call log | pest | `docker compose -p fh0915 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Settings/AiCallsPageTest.php --compact` | W0 (created by the task) | ✅ green |
| 09-15-02 | 15 | 5 | AI-06 | T-09-63..64 | Filter option and "Usage limit reached" tag in the call log UI | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/settings/AiCallsQuota.test.js` | W0 (created by the task) | ✅ green |
| 09-16-01 | 16 | 5 | AI-05 | T-09-65..67 | Agent card and page with enable, order and model | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/settings/AiAgents.test.js` | W0 (created by the task) | ✅ green |
| 09-16-02 | 16 | 5 | AI-05 | T-09-65..67 | Login health card per agent, AI calls pointer line and navigation | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/settings/AiAgents.test.js tests/js/settings/AiCalls.test.js tests/js/settings/SettingsShell.test.js` | W0 (created by the task) | ✅ green |
| 09-16-03 | 16 | 5 | AI-05 | T-09-65..67 | Accessibility sweep registration | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/a11y-sweep.test.js` | W0 (created by the task) | ✅ green |
| 09-17-01 | 17 | 5 | AI-06 | T-09-68..70 | Waiting tag, time formatting, provenance line and the waiting poll cadence | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/health/AiWaiting.test.js tests/js/health/AiStatusPolling.test.js tests/js/health/AiDocumentRows.test.js` | W0 (created by the task) | ✅ green |
| 09-17-02 | 17 | 5 | AI-06 | T-09-68..70 | Run AI control: waiting, quota timeout, provenance, provider line and notice variants | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/health/RunAi.test.js tests/js/health/AiWaiting.test.js` | W0 (created by the task) | ✅ green |
| 09-17-03 | 17 | 5 | AI-06 | T-09-68..70 | Review header provenance, newer waiting run and the waiting status block | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/health/AiReview.test.js tests/js/health/RunAi.test.js tests/js/health/AiWaiting.test.js tests/js/health/AiStatusPolling.test.js tests/js/health/AiDocumentRows.test.js` | W0 (created by the task) | ✅ green |
| 09-18-01 | 18 | 6 | AI-07 | T-09-71..73 | Plan block: scope, cap, dry run, result and the freshness rule | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/settings/AiBulk.test.js tests/js/settings/AiAgents.test.js` | W0 (created by the task) | ✅ green |
| 09-18-02 | 18 | 6 | AI-07 | T-09-71..73 | Start confirmation, active run progress, pause, resume, cancel and polling | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/settings/AiBulk.test.js` | W0 (created by the task) | ✅ green |
| 09-18-03 | 18 | 6 | AI-07 | T-09-71..73 | History table and accessibility, language and layout passes | vitest | `docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/settings/AiBulk.test.js tests/js/settings/AiAgents.test.js` | W0 (created by the task) | ✅ green |
| 09-19-01 | 19 | 7 | AI-04,AI-05,AI-06,AI-07 | T-09-74..78 | End-to-end flows and privacy proofs across Phase 9 surfaces | pest | `docker compose -p fh0919 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Ai/AiPhase9EndToEndTest.php tests/Feature/Ai/AiPrivacyDifferentialTest.php tests/Feature/Health/AdminMedicalPrivacyTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Unit/Arch --compact` | W0 (created by the task) | ✅ green |
| 09-19-02 | 19 | 7 | AI-04,AI-05,AI-06,AI-07 | T-09-74..78 | Cache headers, service worker and accessibility of the new health states | pest+vitest | `docker compose -p fh0919 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/MedicalCacheHeadersTest.php --compact && docker run --rm -v "$PWD":/app -w /app node:22-alpine npx vitest run --testTimeout=30000 tests/js/health/sw-health.test.js tests/js/health/a11y-health.test.js` | W0 (created by the task) | ✅ green |
| 09-19-03 | 19 | 7 | AI-04,AI-05,AI-06,AI-07 | T-09-74..78 | Full phase gate and validation sign-off | pest+vitest | `docker compose -p fh0919 run --rm app php -d memory_limit=1G ./vendor/bin/pest --compact && docker run --rm -v "$PWD":/app -w /app node:22-alpine sh -c 'npx vitest run --testTimeout=30000 && npm run build' && bash scripts/check-compose.sh && bash scripts/test-deploy-guards.sh && grep -q "nyquist_compliant: true" .planning/phases/09-multi-agent-quota-and-bulk-regeneration/09-VALIDATION.md` | W0 (created by the task) | ✅ green |
| 09-20-01 | 20 | 8 | AI-04,AI-05,AI-06,AI-07 | T-09-79..83 | Owner decisions before any document is sent to GitHub (deferred, with owner) | manual | `grep -n "Phase 9 decisions" docs/ops/server.md` | n/a (live) | ⬜ pending |
| 09-20-02 | 20 | 8 | AI-04,AI-05,AI-06,AI-07 | T-09-79..83 | Staging release and the staging Copilot device login (deferred, with owner) | manual | `ssh my-server-agent 'sudo docker ps --format "{{.Names}}"' \| grep -c "ai-worker"` | n/a (live) | ⬜ pending |
| 09-20-03 | 20 | 8 | AI-04,AI-05,AI-06,AI-07 | T-09-79..83 | Tool-denial gate, real runs, live checklist and a tiny bulk run on staging (deferred, with owner) | manual | `grep -n "Phase 9 live checklist" docs/ops/server.md` | n/a (live) | ⬜ pending |
| 09-20-04 | 20 | 8 | AI-04,AI-05,AI-06,AI-07 | T-09-79..83 | Production release, the production Copilot login and the records (deferred, with owner) | manual | `ssh my-server-agent 'cd /opt/family-hub && sudo docker compose ps ai-worker --format "{{.Health}}"' \| grep -q healthy && ssh my-server-agent 'cd /opt/family-hub && sudo docker compose exec -T ai-worker php artisan ai:preflight' \| grep -q "AI preflight OK"` | n/a (live) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Requirement Coverage

| Requirement | Plans | Proof |
|-------------|-------|-------|
| AI-04 (Copilot part) | 09-02, 09-04, 09-05, 09-07, 09-08, 09-19, 09-20 | Image/compose checks, CopilotRunnerTest, CopilotAgentTest, PreflightTest, WorkDirTest, AiArchTest; live login and L3 gate in 09-20 |
| AI-05 | 09-01, 09-03, 09-09, 09-12, 09-16, 09-19, 09-20 | AiAgentSettingsQueryTest, AgentRegistryTest, LoginHealthTest, AiAgentsPageTest, AiAgents.test.js |
| AI-06 | 09-01, 09-03, 09-04, 09-06, 09-09, 09-10, 09-11, 09-15, 09-17, 09-19, 09-20 | QuotaSignaturesTest, CodexRunner/AgentTest quota cases, AgentQuotaTest, RunExtractionJobTest, WaitingRunTest, AiWaitingStatusTest, AiCallsPageTest, AiWaiting/RunAi/AiReview tests, AiPhase9EndToEndTest |
| AI-07 | 09-01, 09-03, 09-10, 09-13, 09-14, 09-18, 09-19, 09-20 | FinishReadyAtomicTest bulk guard, BulkRunTest, BulkTickTest, AiBulk.test.js, AiPhase9EndToEndTest |

## UI-SPEC Screen Coverage

| Screen / contract item | Backend plan | Frontend plan |
|------------------------|--------------|---------------|
| 1. Admin "AI agents" section (cards, switch, order, model, login health, warnings, cooldown, states) | 09-09, 09-12 | 09-16 |
| 2. Waiting for AI quota, quota timeout, provenance, cancel, polling, review header | 09-10, 09-11 | 09-17 |
| 2. Provider line and provider-aware one-time notice | 09-03 (providers column), 09-11 | 09-17 (copy 09-01) |
| 3. Bulk re-generation panel (plan block, freshness, Start, confirm, progress, pause/resume/cancel, history) | 09-13, 09-14 | 09-18 |
| 4. AI calls additions (attempt rows, "Usage limit reached" tag and filter, login card pointer) | 09-15 | 09-15, 09-16 |
| 5. Empty and error states | 09-12, 09-13 | 09-16, 09-17, 09-18 |
| Copywriting contract, plurals, checker follow-ups | — | 09-01 |
| Privacy and gate contract (admin-only, identical 403, no-store, counts only) | 09-12, 09-13, 09-19 | 09-16, 09-18, 09-19 |

---

## Wave 0 Requirements

- [x] `tests/Support/FakeCopilot.php` — fake copilot scripts for runner, agent, job and leak tests (09-07)
- [x] `tests/Support/FakeCodex.php` quota constructors (09-06)
- [x] `tests/Support/MedicalPrivacy/zz_ai_agents.php` — leak-net area for waiting and Copilot runs, sorts after `zz_ai.php` (09-11)
- [x] `tests/Support/EndpointMatrix/settings-ai-agents.php` — matrix rows (09-12, extended by 09-13)
- [x] `MedicalAiExtractionFactory::waiting()`/`bulk()` and `AiBulkRunFactory` (09-03)
- [x] `tests/js/i18n-phase9.test.js` — key parity and plurals (09-01)
- [x] Framework install: none (no new test dependency, no new Composer or npm app dependency)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Package legitimacy evidence of @github/copilot 1.0.112 | AI-04 | Registry evidence recorded, no approval prompt (owner decision) | 09-02 Task 1 SUMMARY table |
| Real Copilot device login per stack, plaintext-storage answer, OAuth scopes, config.json marker keys (L1, A2, A7) | AI-04, AI-05 | Needs the owner's GitHub account | 09-20 Tasks 2 and 4 |
| Tool-denial gate on a live call (L3, A12) | AI-04 | Needs a live Copilot login and network | 09-20 Task 3 step 1 |
| One real run per agent, fallback on a real unusable agent, memory and tmpfs (L2, L5, A3, A4, A13) | AI-04, AI-06 | Needs live logins | 09-20 Task 3 steps 2-4 |
| Real usage-limit transcripts and token refresh (L4, L6, A5, A8, A11, A14, A15) | AI-06 | Needs a real limit or time | 09-20 Task 3 steps 4-5 |
| Real bulk dry run and tiny bulk run on staging | AI-07 | Needs staging and live logins | 09-20 Task 3 step 6 |
| Copilot data-use setting, AUP stance, Additional usage budget $0 (L7, L8, A9, A10) | AI-04, AI-07 | Owner decisions | 09-20 Task 1 |
| Native SK/PL review of all Phase 9 copy; real-device check of AI agents and bulk panel | AI-05, AI-06, AI-07 | Needs a native reader and real devices | 09-20 Task 3 step 7 |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency acceptable (full gate: Pest 248 s, Vitest 137 s, build and ops checks under 1 min each)
- [x] `nyquist_compliant: true` set in the frontmatter (by 09-19 Task 3)

Rows 09-01-01 .. 09-19-03 are green (every automated command of the map was exercised by the full runs below, run alone, in the foreground, nothing failed). Rows 09-20-01 .. 09-20-04 stay pending: they are live (owner, server, real logins) and deferred by owner decision.

### Known limits (accepted, not hidden)

- **No real concurrent-lock test.** `RefreshDatabase` wraps each test in one transaction, so two real connections cannot race over uncommitted rows. The advisory lock in `AiBulkJobQuery::tick` is proved by a second connection (`btlock`) seeing the lock held, and `resumeDue`'s `FOR UPDATE SKIP LOCKED` by a SQL-shape assertion (09-10, 09-14). A true two-process race is not tested; inventing a sleep-based test would be flaky. The race outcomes themselves (a second tick is a no-op, a `23505` on `one_active` becomes a skipped item) are covered.
- **Live behaviour is not proven here:** the real Copilot login, real usage-limit transcripts (quota signatures stay ASSUMED), the tool-denial gate, token refresh, memory and tmpfs sizing and native SK/PL copy review are 09-20.
- **Image checks ran against the existing `family-hub-ai:dev` and `family-hub-app:dev` images** rebuilt in 09-02; the Dockerfile and `docker/ai` have not changed since 09-02, so no rebuild was needed.
- **Pint, repo wide:** `config/security.php` (Phase 6 file, no Phase 9 commit touches it) still has a style finding; it is not Phase 9 work and was left alone.

### Evidence (filled by 09-19, run alone, 2026-11-14)

| Gate | Result |
|------|--------|
| Full Pest | 2592 passed, 28078 assertions, 0 failed, 248.4 s (`docker compose -p fh0919 run --rm app php -d memory_limit=1G ./vendor/bin/pest --compact`, foreground) |
| Peak memory of the full Pest run | 358,088,704 bytes allocated (341.5 MiB, about 358 MB), 289.8 MiB used, limit 512M. Below the roughly 410 MB threshold, so `phpunit.xml` was not changed |
| Full Vitest (node:22-alpine) | 135 files, 4752 passed, 0 failed, 136.5 s, run after the build so the built service worker checks were real |
| `npm run build` | green, `SW-PRECACHE OK` |
| `bash scripts/check-compose.sh` | `CHECK-COMPOSE OK` |
| `bash scripts/test-deploy-guards.sh` | 25 passed, 0 failed, `GUARDS OK` |
| Image checks (09-02-02) | AI image: `copilot --version` 1.0.112 under `env -i`, `.extraction-complete` present, `LICENSE.md` present, `/copilot` exists, no `node`, `codex` present (`AI-IMAGE-OK`). App image: no `copilot`, no `/opt/copilot`, no `codex`, `pdftoppm` present (`APP-IMAGE-OK`) |
| Pint on every PHP file touched by a Phase 9 commit | 91 files, PASS (09-19 tests were fixed by Pint; `AiJobQuery.php` is clean; `config/security.php` is not a Phase 9 file, see Known limits) |
| 09-19 additions | `AiPhase9EndToEndTest` (6 tests), canary and name scan in `AiPrivacyDifferentialTest`, admin non-member test in `AdminMedicalPrivacyTest`, agents/bulk no-store tests in `MedicalCacheHeadersTest`, agents/bulk exclusion in `sw-health.test.js`, 16 Phase 9 states in `a11y-health.test.js` (Vitest total 1216 in the two health files) |
| Bulk guard fix (09-14 observation) | `AiJobQuery::hasAcceptedDecision` now looks at accepted decisions on any extraction of the document; new tests in `FinishReadyAtomicTest` and `AiPhase9EndToEndTest` |

**Approval:** approved 2026-11-14 by 09-19 (everything except the deferred live plan 09-20).
