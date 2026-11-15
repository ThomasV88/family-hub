---
phase: 08-ai-extraction-platform
plan: 17
subsystem: ui
tags: [admin, ai-calls, login-health, vue, a11y]
requires: ["08-01", "08-14"]
provides:
  - Settings/AiCalls page (login health card, filters, call table, pagination, states)
  - Bot icon mapping in the settings nav
affects: [08-21, 08-22]
key-files:
  created:
    - resources/js/Pages/Settings/AiCalls.vue
    - resources/js/Settings/AiCalls/AiLoginHealthCard.vue
    - resources/js/Settings/AiCalls/AiCallsFilters.vue
    - resources/js/Settings/AiCalls/AiCallsTable.vue
    - tests/js/settings/AiCalls.test.js
  modified:
    - resources/js/Layouts/SettingsLayout.vue
    - resources/js/Pages/Settings/Index.vue
requirements-completed: [AI-03, AI-04]
completed: 2026-11-14
---

# Phase 8 Plan 17: Admin AI calls page Summary

Admin Settings > AI calls page: Codex login card with queued re-check and polling, three immediate-apply filters, a content-free non-interactive call table with pagination and all empty, error and loading states.

## Commits
- 010fe69 feat(08-17): admin AI calls page with login health card, filters and table (Task 1 and the Task 2 tests, one commit since both only touch AiCalls.test.js)

## Verification
- AiCalls.test.js (36 tests) and SettingsShell.test.js pass: all four login states, no credential input, Check again polling with fake timers (3 s, 30 s cap, early stop on a fresh result), seven columns with Intl time and duration, rows without anchors, filter queries, Clear filters, empty, filtered-empty, error, loading, pagination, sk/pl plurals for 0, 1, 2, 4, 5, 12, 22, 25, axe in en/sk/pl light and dark for eight states, pseudo-long text, forced-colors and reduced-motion source checks.
- Full Vitest in node:22-alpine: 125 files, 3561 tests pass. `npm run build` passes (SW-PRECACHE OK).
- Full Pest (1G, fh0817): first run 2239 passed, 4 failed, all in sibling 08-15's in-progress review route (RunExtractionJobTest, MedicalLeakSuiteTest canary on the review page); fixed by 08-15's commit d4afc12. Final run after that: 2243 passed, 0 failed.
- Compose project fh0817 removed with `down -v`.

## Decisions (PROVISIONAL)
- Card heading and empty/error states use h2, not the h3 in the UI-SPEC: under the layout's h1 an h3 fails axe heading-order.
- Pagination status uses the existing `audit.page` ("Page {n} of {total}"); the plural key `settings.ai_calls.pages` is used in the pager nav aria-label ("AI calls, 3 pages") because no key combines both. Plural forms are tested through the key itself.
- "No answer yet. Try again later." is rendered from `settings.ai_login.no_answer` ("No answer yet") only: no "try later" key exists and lang files are outside this plan.
- Retry button text reuses `health.error.retry` ("Try loading again"); no settings-specific key exists.
- Duration uses `Intl.NumberFormat` unit short style, so en-GB prints "12 secs" and "1 min 5 secs", not "12 s". Locale-correct, tests derive expectations from Intl.
- Missing `login.checkedAt` (unknown state) hides the "Last checked" line.
- `calls` prop null renders the loading skeleton (card + five rows); a partial-reload `onError` renders the error state.
- Filter selects use the member integer id and person UUID exactly as 08-14 emits.

## Deviations from Plan
None in behaviour beyond the decisions above.

## Notes for 08-21
- `tests/js/a11y-sweep.test.js` registers pages by explicit import, not by enumeration: 08-21 must add `Settings/AiCalls` there. Own axe coverage already exists in AiCalls.test.js.
- Vitest/build run in `node:22-alpine` with the repo bind-mounted (host Node 18).

## Known Stubs
None.

## Threat Flags
None. T-08-81 (no input fields besides filters, tested), T-08-82 (no anchors or handlers in rows, tested) covered.

## Self-Check: PASSED
Files and commit 010fe69 exist.
