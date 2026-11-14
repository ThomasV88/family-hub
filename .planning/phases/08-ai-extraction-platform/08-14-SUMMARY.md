---
phase: 08-ai-extraction-platform
plan: 14
subsystem: ai
tags: [admin, ai-calls, login-health, scheduler]
requires: ["08-01", "08-04", "08-10", "08-12"]
provides:
  - AdminAiCallQuery (names-only call list, filters, options)
  - AiCallsController, routes settings.ai_calls and settings.ai_calls.check
  - Admin section ai_calls (7th)
  - Schedules ai-check-login, ai-reap-stuck, ai-prune-calls
affects: [08-17, 08-22]
key-files:
  created:
    - app/Medical/Queries/AdminAiCallQuery.php
    - app/Http/Controllers/Settings/AiCallsController.php
    - routes/web/settings-ai-calls.php
    - tests/Support/EndpointMatrix/settings-ai-calls.php
    - tests/Feature/Settings/AiCallsPageTest.php
    - tests/Feature/Ai/AiScheduleTest.php
  modified:
    - app/Support/SettingsSections.php
    - routes/console.php
    - tests/Support/SensitiveRoutes.php
    - tests/Unit/Arch/MedicalPrivacyArchTest.php
    - tests/Feature/Settings/MembersPageTest.php
    - tests/Feature/Audit/AuditPageTest.php
    - tests/Feature/Finance/AccountAdminTest.php
    - tests/Feature/Notifications/NotificationSettingsTest.php
requirements-completed: [AI-03, AI-04]
completed: 2026-11-14
---

# Phase 8 Plan 14: Admin AI call log, login health and schedules Summary

Admin-only Settings > AI calls page backend (names-only call list, filters, Codex login health, queued re-check) plus the three scheduler entries for the AI maintenance work.

## Commits
- f89deda feat(08-14): add admin AI call log and login health page
- 897cf80 feat(08-14): schedule login check, stuck-run reaper and call prune
- 8a956d3 test(08-14): admin sections list includes ai_calls in notification settings test

## Verification
- Own tests (AiCallsPageTest 9, AiScheduleTest 4, section tests, MedicalPrivacyArchTest, SettingsShellTest, SensitiveGateMatrixTest, SecurityPageTest) pass; Pint clean on my files.
- Full Pest once (1G, about 205 s): 2203 passed, 4 failed. One was mine (NotificationSettingsTest admin section order, fixed in 8a956d3 and re-run green). The other three are 08-13's in-progress health AI routes (AiRunTest cancel/status, MedicalLeakSuiteTest "catches unscoped" on ai-status and document file), not touched by this plan.
- The SensitiveGateMatrixTest failures seen mid-run (missing matrix row for health.documents.ai.run) were 08-13's and passed once its matrix file landed.
- Vitest and `npm run build` not run: PHP-only plan, host Node 18, shared tree with 08-13. Peak memory not measured; phpunit.xml untouched.

## Decisions (PROVISIONAL, for 08-22 review)
- Section placed after finance_accounts (before notifications), not directly after audit: AccountAdminTest pins finance_accounts to audit + 1.
- Member filter uses the integer user id (as the Audit page does), not a public id; there is no public id column.
- A page number past the last page is clamped to the last page.
- `settings.ai_calls` goes into `grantAllowlist()` (read-only, the prefix `settings.ai_calls.` is a grant prefix); `settings.ai_calls.check` into `accessGranting()` so the stale-session proof covers it.
- check redirects back with flash `status = ai-calls.check-queued` (neutral key).
- Login prop is `{agent: 'codex', state, checkedAt}`; the Vue page maps the agent key to a label (08-17).

## Deviations from Plan
- [Rule 3] The arch pin allows three medical references in AiCallsController (AdminAiCallQuery, AgentHealth, CheckLoginJob), not AdminAiCallQuery only: the contract requires login health and the check dispatch in the same controller. `mdArchSettingsLeaks` exempts it and `mdArchAiCallsViolations` pins the set, with mutation cases.
- [Rule 3] NotificationSettingsTest (not in files_modified) hard-codes the admin section order; updated to include ai_calls.
- Task 1 and 2 test files ran in one container pass, so the schedule lines were committed in a separate second commit.
- Inertia assertions use `component('Settings/AiCalls', false)`: `ensure_pages_exist` is true in the test config for the non-testing path, the Vue page arrives in 08-17.
- Tests that need the real recent-confirmation window call `useRealSensitiveSession()` (the default test session bypasses access:recent).

## Known Stubs
None. The Vue page Settings/AiCalls is delivered by 08-17.

## Threat Flags
None. T-08-65 (grep gate: no title/payload in the query; canary test), T-08-66 (matrix rows for every preset), T-08-68 (access:recent, throttle 6/min, unique job) covered by tests.

## Self-Check: PASSED
