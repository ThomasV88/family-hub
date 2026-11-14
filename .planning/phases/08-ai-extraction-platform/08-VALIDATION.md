---
phase: 08
slug: ai-extraction-platform
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-11-14
---

# Phase 08 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Draft created at planning time from the 23 plans. Plan 08-21 Task 3 updates every row to its real status and signs it off (status approved, `nyquist_compliant: true`). Plan 08-22 (live login, real run, owner decisions, release) stays pending until the live session.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHP, in the app container) + Vitest (frontend) + axe-core |
| **Config file** | phpunit.xml, vitest.config.js |
| **Quick run command** | `docker compose -p fh08NN run --rm app ./vendor/bin/pest --compact <file>` |
| **Full suite command** | `docker compose run --rm app php -d memory_limit=1G ./vendor/bin/pest --compact && npx vitest run` |
| **Ops checks** | `bash scripts/check-compose.sh`, `bash scripts/test-deploy-guards.sh`, `npm run build` |
| **Estimated runtime** | Pest ~180 s, Vitest ~70 s (Phase 7 baseline plus Phase 8) |

Note: phpunit.xml sets `memory_limit` 512M, which overrides the `-d` flag. Phase 7 peak was 275 MiB; 08-21 measures the Phase 8 peak and raises the limit only if needed. No test runs the real Codex CLI: `tests/Support/FakeCodex.php` replaces it everywhere.

---

## Sampling Rate

- **After every task commit:** run only the task's own command (map below)
- **After every plan:** full Pest and Vitest once in the plan's last task
- **Per wave merge:** `bash scripts/check-compose.sh`, `SensitiveGateMatrixTest`, `MedicalPrivacyTest`, `MedicalPrivacyArchTest`, `AiArchTest`, `MedicalMutationScanTest`
- **Before `/gsd-verify-work`:** full suite, build and check-compose green
- **Max feedback latency:** about 10 s per task command, about 4 min for the full suite

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 08-01-01 | 01 | 1 | AI-01,AI-02,AI-03,AI-04 | T-08-01..03 | Ship all Phase 8 copy in one early plan (UI-SPEC "added in lang/en.json, lang/sk.json, lang/pl.json in one early plan") and the three... (All Phase 8 copy in EN, SK and PL) | vitest | `npx vitest run tests/js/i18n-phase8.test.js tests/js/i18n-phase7.test.js` | W0 (created by the task) | ⬜ pending |
| 08-01-02 | 01 | 1 | AI-01,AI-02,AI-03,AI-04 | T-08-01..03 | Ship all Phase 8 copy in one early plan (UI-SPEC "added in lang/en.json, lang/sk.json, lang/pl.json in one early plan") and the three... (Three content-free audit events; full verification) | pest+vitest | `docker compose -p fh0801 run --rm app ./vendor/bin/pest tests/Unit/MedicalLangTest.php --compact && npx vitest run tests/js/settings/AuditHealth.test.js` | W0 (created by the task) | ⬜ pending |
| 08-02-01 | 02 | 1 | AI-04 | T-08-SC, T-08-04..07 | Gate the one external package behind the owner's approval, then build the images: poppler in the shared base (the app renders PDF pages... (Owner approves the Codex CLI and poppler-utils packages (before any install)) | manual | `test -f .planning/phases/08-ai-extraction-platform/08-RESEARCH.md && grep -c "0.169.0" .planning/phases/08-ai-extraction-platform/08-RESEARCH.md` | W0 (created by the task) | ⬜ pending |
| 08-02-02 | 02 | 1 | AI-04 | T-08-SC, T-08-04..07 | Gate the one external package behind the owner's approval, then build the images: poppler in the shared base (the app renders PDF pages... (Codex pin, lockfile and Docker stages; image checks) | script | `docker run --rm family-hub-ai:check codex --version \| grep -q "codex-cli 0.169.0" && docker compose run --rm app sh -c 'command -v pdftoppm && ! command -v codex'` | W0 (created by the task) | ⬜ pending |
| 08-03-01 | 03 | 1 | AI-01,AI-02 | T-08-08..12 | The extraction contract: schema v1, the server-side validator and normaliser, the entry key helper, the failure enum and the static... (Schema v1, failure enum, validator, entry keys and PlainText) | pest | `docker compose -p fh0803 run --rm app ./vendor/bin/pest tests/Unit/Ai/ExtractionValidatorTest.php tests/Unit/Health/ExportFormatTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-03-02 | 03 | 1 | AI-01,AI-02 | T-08-08..12 | The extraction contract: schema v1, the server-side validator and normaliser, the entry key helper, the failure enum and the static... (Static prompt with untrusted-data framing; full verification) | pest | `docker compose -p fh0803 run --rm app ./vendor/bin/pest tests/Unit/Ai --compact` | W0 (created by the task) | ⬜ pending |
| 08-04-01 | 04 | 1 | AI-02,AI-03 | T-08-13..17 | The data model for proposals, decisions, the call log, agent health and notice acknowledgments, with database-level guarantees, and its... (Proposal and decision tables, models, factory, leak-net registration) | pest | `docker compose -p fh0804 run --rm app ./vendor/bin/pest tests/Feature/Ai/AiSchemaTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Unit/Health/MedicalMutationScanTest.php tests/Unit/Health/MedicalTableScanTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-04-02 | 04 | 1 | AI-02,AI-03 | T-08-13..17 | The data model for proposals, decisions, the call log, agent health and notice acknowledgments, with database-level guarantees, and its... (Content-free call log with lifecycle guard and definer prune, agent status, notice acks; full verification) | pest | `docker compose -p fh0804 run --rm app ./vendor/bin/pest tests/Feature/Ai --compact` | W0 (created by the task) | ⬜ pending |
| 08-05-01 | 05 | 1 | AI-01,AI-04 | T-08-18..23 | The single process seam for Codex: exact invocation, scrubbed environment, supervision with group kill and output caps, login-status... (Config, value objects, CodexRunner and the fake CLI) | pest | `docker compose -p fh0805 run --rm app ./vendor/bin/pest tests/Unit/Ai/CodexRunnerTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-05-02 | 05 | 1 | AI-01,AI-04 | T-08-18..23 | The single process seam for Codex: exact invocation, scrubbed environment, supervision with group kill and output caps, login-status... (Arch rules for the AI layer; full verification) | pest | `docker compose -p fh0805 run --rm app ./vendor/bin/pest tests/Unit/Arch/AiArchTest.php tests/Unit/Ai --compact` | W0 (created by the task) | ⬜ pending |
| 08-06-01 | 06 | 1 | AI-02 | T-08-24..26 | Open the Phase 7 record layer for AI-origin writes without a second write path: a `source` argument on the four create methods, a scoped... (Source argument and verify transition in the four record queries) | pest | `docker compose -p fh0806 run --rm app ./vendor/bin/pest tests/Feature/Health/RecordProvenanceTest.php tests/Feature/Health/VisitTest.php tests/Feature/Health/MedicationTest.php tests/Feature/Health/AllergyTest.php tests/Feature/Health/VaccinationTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-06-02 | 06 | 1 | AI-02 | T-08-24..26 | Open the Phase 7 record layer for AI-origin writes without a second write path: a `source` argument on the four create methods, a scoped... (One rule set per record type; full verification) | pest | `docker compose -p fh0806 run --rm app ./vendor/bin/pest tests/Feature/Health --compact` | W0 (created by the task) | ⬜ pending |
| 08-07-01 | 07 | 2 | AI-04 | T-08-27..32 | Run the AI worker as its own locked-down service and teach every ops script about it, with static checks that keep credentials confined (ai-worker service in both compose files and its env example) | script | `IMAGE_PREFIX=x IMAGE_TAG=y docker compose -f compose.server.yaml config --format json \| node -e "const c=JSON.parse(require('fs').readFileSync(0));const a=c.services['ai-worker'];if(!a\|\|a.ports\|\|!a.init\|\|!JSON.stringify(a.volumes).includes('codex')) process.exit(1)"` | W0 (created by the task) | ⬜ pending |
| 08-07-02 | 07 | 2 | AI-04 | T-08-27..32 | Run the AI worker as its own locked-down service and teach every ops script about it, with static checks that keep credentials confined (check-compose assertions, deploy, smoke, CI and runbook; full verification) | script | `bash scripts/check-compose.sh && bash scripts/test-deploy-guards.sh && bash -n scripts/deploy.sh` | W0 (created by the task) | ⬜ pending |
| 08-08-01 | 08 | 2 | AI-01,AI-02,AI-04 | T-08-33..37 | The second process seam (PDF rasterization, shared by the review pane and the AI input), the per-job tmpfs work dir, and the... (PdfRasterizer and multi-page fixture) | pest | `docker compose -p fh0808 run --rm app ./vendor/bin/pest tests/Unit/Ai/PdfRasterizerTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-08-02 | 08 | 2 | AI-01,AI-02,AI-04 | T-08-33..37 | The second process seam (PDF rasterization, shared by the review pane and the AI input), the per-job tmpfs work dir, and the... (Work dir lifecycle and preflight command; full verification) | pest | `docker compose -p fh0808 run --rm app ./vendor/bin/pest tests/Unit/Ai --compact` | W0 (created by the task) | ⬜ pending |
| 08-09-01 | 09 | 2 | AI-02 | T-08-38..40 | The server side of the label lifecycle: verify routes for the four record types and provenance on every record row of the person page (Verify routes and controller with identical 403, matrix rows and area file) | pest | `docker compose -p fh0809 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/RecordVerifyTest.php tests/Feature/Security/SensitiveGateMatrixTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Feature/Health/MedicalWriteParityTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-09-02 | 09 | 2 | AI-02 | T-08-38..40 | The server side of the label lifecycle: verify routes for the four record types and provenance on every record row of the person page (Provenance on person page record rows; full verification) | pest | `docker compose -p fh0809 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health --compact` | W0 (created by the task) | ⬜ pending |
| 08-10-01 | 10 | 3 | AI-01,AI-04 | T-08-41..45 | The agent layer: the registry interface with the single Codex agent, outcome classification into content-free failure codes, and login... (Agent interface, registry and Codex agent outcome mapping) | pest | `docker compose -p fh0810 run --rm app ./vendor/bin/pest tests/Unit/Ai/CodexAgentTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-10-02 | 10 | 3 | AI-01,AI-04 | T-08-41..45 | The agent layer: the registry interface with the single Codex agent, outcome classification into content-free failure codes, and login... (Login health and the check job; full verification) | pest | `docker compose -p fh0810 run --rm app ./vendor/bin/pest tests/Feature/Ai/LoginHealthTest.php tests/Unit/Ai --compact` | W0 (created by the task) | ⬜ pending |
| 08-11-01 | 11 | 3 | AI-02 | T-08-46..49 | The PDF page-image derivative that makes side-by-side review possible for PDFs (Page-image action, route, matrix row and area file) | pest | `docker compose -p fh0811 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/DocumentPageImageTest.php tests/Feature/Health/DocumentServeTest.php tests/Feature/Security/SensitiveGateMatrixTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Feature/Health/MedicalCacheHeadersTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-11-02 | 11 | 3 | AI-02 | T-08-46..49 | The PDF page-image derivative that makes side-by-side review possible for PDFs (Full verification) | pest | `docker compose -p fh0811 run --rm app ./vendor/bin/pest tests/Feature/Health/DocumentPageImageTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-12-01 | 12 | 4 | AI-01,AI-02,AI-03,AI-04 | T-08-50..57 | The background job that turns a queued extraction into a ready proposal or a content-free failure, as a state machine over one row with... (System-side job query, store copy method and the extraction job) | pest | `docker compose -p fh0812 run --rm app ./vendor/bin/pest tests/Feature/Ai/RunExtractionJobTest.php tests/Unit/Health/MedicalMutationScanTest.php tests/Unit/Arch/AiArchTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-12-02 | 12 | 4 | AI-01,AI-02,AI-03,AI-04 | T-08-50..57 | The background job that turns a queued extraction into a ready proposal or a content-free failure, as a state machine over one row with... (Reaper, leak tests through the fake CLI; full verification) | pest | `docker compose -p fh0812 run --rm app ./vendor/bin/pest tests/Feature/Ai --compact` | W0 (created by the task) | ⬜ pending |
| 08-13-01 | 13 | 5 | AI-01,AI-03,AI-04 | T-08-58..64 | The request side of a run: start (with consent, availability, kind and notice checks), cancel, and status polling, plus the AI props on... (Start, cancel and status: query, request, controller, routes, matrix and area) | pest | `docker compose -p fh0813 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/AiRunTest.php tests/Feature/Security/SensitiveGateMatrixTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Feature/Health/MedicalWriteParityTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-13-02 | 13 | 5 | AI-01,AI-03,AI-04 | T-08-58..64 | The request side of a run: start (with consent, availability, kind and notice checks), cancel, and status polling, plus the AI props on... (AI props on the person page; full verification) | pest | `docker compose -p fh0813 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health --compact` | W0 (created by the task) | ⬜ pending |
| 08-14-01 | 14 | 5 | AI-03,AI-04 | T-08-65..69 | The admin call log and login-health backend, the schedules for the AI maintenance commands, and the deliberate allowlist changes that... (Admin call log query, controller, routes, section and allowlists) | pest | `docker compose -p fh0814 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Settings/AiCallsPageTest.php tests/Feature/Settings/MembersPageTest.php tests/Feature/Audit/AuditPageTest.php tests/Feature/Finance/AccountAdminTest.php tests/Feature/Settings/SettingsShellTest.php tests/Unit/Arch/MedicalPrivacyArchTest.php tests/Feature/Security/SensitiveGateMatrixTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-14-02 | 14 | 5 | AI-03,AI-04 | T-08-65..69 | The admin call log and login-health backend, the schedules for the AI maintenance commands, and the deliberate allowlist changes that... (Schedules for login check, reaper and prune; full verification) | pest | `docker compose -p fh0814 run --rm app ./vendor/bin/pest tests/Feature/Ai/AiScheduleTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-15-01 | 15 | 6 | AI-02 | T-08-70..76 | The review backend: the review page props and the per-entry accept / edit-accept / reject / undo endpoints, built on the Phase 7 record... (Review query and page, routes, matrix rows, area additions) | pest | `docker compose -p fh0815 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/AiReviewPageTest.php tests/Feature/Security/SensitiveGateMatrixTest.php tests/Feature/Health/MedicalPrivacyTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-15-02 | 15 | 6 | AI-02 | T-08-70..76 | The review backend: the review page props and the per-entry accept / edit-accept / reject / undo endpoints, built on the Phase 7 record... (Atomic, idempotent accept / edit-accept / reject / undo; full verification) | pest | `docker compose -p fh0815 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/AiAcceptTest.php tests/Feature/Health/AiReviewPageTest.php tests/Feature/Health/MedicalWriteParityTest.php --compact` | W0 (created by the task) | ⬜ pending |
| 08-16-01 | 16 | 6 | AI-01,AI-02 | T-08-77..80 | The user-facing start of the flow: Run AI with the one-time OpenAI notice, run states and next actions on the document, and polling.... (Status tag, notice dialog and polling composable) | vitest | `npx vitest run tests/js/health/AiStatusPolling.test.js` | W0 (created by the task) | ⬜ pending |
| 08-16-02 | 16 | 6 | AI-01,AI-02 | T-08-77..80 | The user-facing start of the flow: Run AI with the one-time OpenAI notice, run states and next actions on the document, and polling.... (Run AI control in the document panel; full verification) | vitest | `npx vitest run tests/js/health/RunAi.test.js tests/js/health/Documents.test.js` | W0 (created by the task) | ⬜ pending |
| 08-17-01 | 17 | 6 | AI-03,AI-04 | T-08-81..83 | The admin "AI calls" settings page: login health card, filters, call table, pagination and all states (AI calls page, login card, filters and table) | vitest | `npx vitest run tests/js/settings/AiCalls.test.js tests/js/settings/SettingsShell.test.js` | W0 (created by the task) | ⬜ pending |
| 08-17-02 | 17 | 6 | AI-03,AI-04 | T-08-81..83 | The admin "AI calls" settings page: login health card, filters, call table, pagination and all states (Accessibility sweep inclusion) | vitest | `npx vitest run tests/js/settings/AiCalls.test.js` | W0 (created by the task) | ⬜ pending |
| 08-18-01 | 18 | 7 | AI-02 | T-08-84..87 | The side-by-side review screen with per-entry Accept, Reject and Undo (editing in place arrives in 08-19, which plugs into the Edit... (Page shell, layout switch, pane toggle and document pane) | vitest | `npx vitest run tests/js/health/AiReview.test.js` | W0 (created by the task) | ⬜ pending |
| 08-18-02 | 18 | 7 | AI-02 | T-08-84..87 | The side-by-side review screen with per-entry Accept, Reject and Undo (editing in place arrives in 08-19, which plugs into the Edit... (Suggestions list and entries with Accept, Reject, Undo; full verification) | vitest | `npx vitest run tests/js/health/AiReview.test.js` | W0 (created by the task) | ⬜ pending |
| 08-19-01 | 19 | 8 | AI-02 | T-08-88..89 | Edit-in-place for suggestions: the per-type form, saving edited values through the accept endpoint, server-error flagging after a failed... (Per-type edit form and entry wiring) | vitest | `npx vitest run tests/js/health/AiReviewEdit.test.js tests/js/health/AiReview.test.js` | W0 (created by the task) | ⬜ pending |
| 08-19-02 | 19 | 8 | AI-02 | T-08-88..89 | Edit-in-place for suggestions: the per-type form, saving edited values through the accept endpoint, server-error flagging after a failed... (One edit at a time across the page; full verification) | vitest | `npx vitest run tests/js/health` | W0 (created by the task) | ⬜ pending |
| 08-20-01 | 20 | 8 | AI-02 | T-08-90..92 | Make AI provenance visible on records and let members verify them: the ProvenanceTags component in every record location and the "Mark... (ProvenanceTags in record rows and the print export) | vitest | `npx vitest run tests/js/health/Provenance.test.js tests/js/health/Allergies.test.js tests/js/health/Medications.test.js tests/js/health/Vaccinations.test.js tests/js/health/Visits.test.js tests/js/health/Export.test.js` | W0 (created by the task) | ⬜ pending |
| 08-20-02 | 20 | 8 | AI-02 | T-08-90..92 | Make AI provenance visible on records and let members verify them: the ProvenanceTags component in every record location and the "Mark... (Panel header tags and "Mark as verified"; full verification) | vitest | `npx vitest run tests/js/health` | W0 (created by the task) | ⬜ pending |
| 08-21-01 | 21 | 9 | AI-01,AI-02,AI-03,AI-04 | T-08-93..97 | Phase-wide proof: leak-suite completeness for the new routes, privacy differentials and canary scans, cache/service-worker/history... (Leak-suite completeness, privacy differential and admin canary scan) | pest | `docker compose -p fh0821 run --rm app php -d memory_limit=1G ./vendor/bin/pest tests/Feature/Health/MedicalLeakSuiteTest.php tests/Feature/Ai/AiPrivacyDifferentialTest.php tests/Feature/Health/AdminMedicalPrivacyTest.php tests/Feature/Health/MedicalPrivacyTest.php tests/Unit/Arch --compact` | W0 (created by the task) | ⬜ pending |
| 08-21-02 | 21 | 9 | AI-01,AI-02,AI-03,AI-04 | T-08-93..97 | Phase-wide proof: leak-suite completeness for the new routes, privacy differentials and canary scans, cache/service-worker/history... (Cache headers, service worker, history and staging audit) | pest+vitest | `docker compose -p fh0821 run --rm app ./vendor/bin/pest tests/Feature/Health/MedicalCacheHeadersTest.php tests/Feature/Health/StagingAuditMedicalTest.php --compact && npx vitest run tests/js/health/sw-health.test.js tests/js/health/history-health.test.js` | W0 (created by the task) | ⬜ pending |
| 08-21-03 | 21 | 9 | AI-01,AI-02,AI-03,AI-04 | T-08-93..97 | Phase-wide proof: leak-suite completeness for the new routes, privacy differentials and canary scans, cache/service-worker/history... (Accessibility sweep, full phase gate and validation sign-off) | pest+vitest | `docker compose -p fh0821 run --rm app php -d memory_limit=1G ./vendor/bin/pest --compact && npx vitest run && npm run build && bash scripts/check-compose.sh` | W0 (created by the task) | ⬜ pending |
| 08-22-01 | 22 | 10 | AI-01,AI-02,AI-03,AI-04 | T-08-98..101 | Take Phase 8 live with the owner: owner decisions and the data-use check first, staging release with a real Codex login, one real run on... (Data-use check and owner decisions before any document is sent (deferred, with owner)) | manual | `grep -n "Phase 8 decisions" docs/ops/server.md` | n/a (live) | ⬜ pending |
| 08-22-02 | 22 | 10 | AI-01,AI-02,AI-03,AI-04 | T-08-98..101 | Take Phase 8 live with the owner: owner decisions and the data-use check first, staging release with a real Codex login, one real run on... (Staging release and the real Codex login (deferred, with owner)) | manual | `ssh my-server-agent 'sudo docker ps --format "{{.Names}}"' \| grep -c "ai-worker"` | n/a (live) | ⬜ pending |
| 08-22-03 | 22 | 10 | AI-01,AI-02,AI-03,AI-04 | T-08-98..101 | Take Phase 8 live with the owner: owner decisions and the data-use check first, staging release with a real Codex login, one real run on... (One real run and the live-session checklist on staging (deferred, with owner)) | manual | `grep -n "Phase 8 live checklist" docs/ops/server.md` | n/a (live) | ⬜ pending |
| 08-22-04 | 22 | 10 | AI-01,AI-02,AI-03,AI-04 | T-08-98..101 | Take Phase 8 live with the owner: owner decisions and the data-use check first, staging release with a real Codex login, one real run on... (Production release and the production Codex login (deferred, with owner)) | manual | `ssh my-server-agent 'cd /opt/family-hub && sudo docker compose ps ai-worker --format "{{.Health}}"' \| grep -q healthy && ssh my-server-agent 'cd /opt/family-hub && sudo docker compose exec -T ai-worker php artisan ai:preflight' \| grep -q "AI preflight OK"` | n/a (live) | ⬜ pending |
| 08-22-05 | 22 | 10 | AI-01,AI-02,AI-03,AI-04 | T-08-98..101 | Take Phase 8 live with the owner: owner decisions and the data-use check first, staging release with a real Codex login, one real run on... (Requirements, roadmap and deferred records) | script | `grep -n "AI-04" .planning/REQUIREMENTS.md \| grep -q "Copilot part in Phase 9" && grep -n "\[x\] \*\*AI-03" .planning/REQUIREMENTS.md && ! grep -n "\[x\] \*\*AI-04" .planning/REQUIREMENTS.md` | n/a (live) | ⬜ pending |
| 08-23-01 | 23 | 7 | AI-01,AI-02 | T-08-102..103 | Show each document's AI state in the folder and timeline lists and remove the obsolete Phase 7 "AI not available yet" line. Split out of... (AI status tags on document rows) | vitest | `npx vitest run tests/js/health/AiDocumentRows.test.js tests/js/health/Visits.test.js tests/js/health/Documents.test.js` | W0 (created by the task) | ⬜ pending |
| 08-23-02 | 23 | 7 | AI-01,AI-02 | T-08-102..103 | Show each document's AI state in the folder and timeline lists and remove the obsolete Phase 7 "AI not available yet" line. Split out of... (Remove the obsolete consent line; full verification) | vitest | `npx vitest run tests/js/health/AiConsent.test.js tests/js/i18n-phase7.test.js tests/js/i18n-phase8.test.js` | W0 (created by the task) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Support/FakeCodex.php` — fake codex scripts for every runner, agent, job and leak test (08-05)
- [ ] `tests/Support/MedicalPrivacy/zz_ai.php` — AI leak-net area, sorts last (08-13, extended by 08-15)
- [ ] `tests/Support/MedicalPrivacy/visits_verify.php`, `subjects_documents_pages.php` — verify and page-image areas (08-09, 08-11)
- [ ] `tests/Support/EndpointMatrix/health-ai.php`, `health-verify.php`, `settings-ai-calls.php` — matrix rows (08-13, 08-09, 08-14)
- [ ] `tests/Support/MedicalFileFixtures.php::pdfPages()` — multi-page PDF fixture (08-08)
- [ ] `tests/Unit/Arch/AiArchTest.php` — process seams and no-log rule (08-05)
- [ ] `tests/js/i18n-phase8.test.js` — key parity and plurals (08-01)
- [ ] Framework install: none (no new test dependency)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Package legitimacy of @openai/codex 0.169.0 and poppler-utils | AI-04 | Owner approval (slopcheck unavailable) | 08-02 Task 1 |
| Real Codex device login per stack and `codex login status` | AI-04 | Needs the owner's ChatGPT account | 08-22 Tasks 2 and 4 |
| One real extraction run and research checklist items 1-8 (tools disabled, schema accepted, CODEX_HOME contents, memory, timings, expired-login transcript) | AI-01..AI-04 | Needs a live login and network to OpenAI | 08-22 Task 3 |
| ChatGPT data-controls/training setting and terms reading | AI-04 | Owner decision | 08-22 Task 1 |
| Native SK/PL review of the AI copy; real-device check of the review screen | AI-02 | Needs a native reader and real devices (jsdom cannot measure layout) | 08-22 Task 3 |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency acceptable
- [ ] `nyquist_compliant: true` set in the frontmatter (by 08-21 Task 3)

**Approval:** pending (draft; signed off by 08-21)
