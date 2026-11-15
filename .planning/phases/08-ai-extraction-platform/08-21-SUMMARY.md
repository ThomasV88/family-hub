---
phase: 08-ai-extraction-platform
plan: 21
subsystem: testing
tags: [privacy, leak-net, a11y, axe, staging-audit, validation, pest, vitest]
requires: [08-07, 08-11, 08-17, 08-19, 08-20, 08-23]
provides:
  - Phase 8 routes proven inside the medical leak net (completeness check, never-existed differential, canary scan)
  - no-store, private and no-validator checks for status, review and page-image routes
  - staging:audit rules for medical_ai_extractions and ai_calls
  - axe, 320 px and +40% sweeps for every Phase 8 surface
  - signed-off 08-VALIDATION.md
affects: [08-22]
tech-stack:
  added: []
  patterns: ["router-enumerated completeness check with a sanity route", "delete-the-person differential for never-existed parity"]
key-files:
  created:
    - tests/Feature/Ai/AiPrivacyDifferentialTest.php
  modified:
    - tests/Feature/Health/MedicalLeakSuiteTest.php
    - tests/Feature/Health/MedicalCacheHeadersTest.php
    - tests/Feature/Health/StagingAuditMedicalTest.php
    - tests/Feature/Health/AiReviewPageTest.php
    - app/Console/Commands/StagingAudit.php
    - tests/js/health/sw-health.test.js
    - tests/js/health/history-health.test.js
    - tests/js/health/a11y-health.test.js
    - tests/js/a11y-sweep.test.js
    - .planning/phases/08-ai-extraction-platform/08-VALIDATION.md
    - .planning/phases/08-ai-extraction-platform/DEFERRED.md
key-decisions:
  - "Never-existed parity is proved by asking the same URLs before and after deleting the hidden person (cascade), plus random-uuid and malformed-id variants, with app.debug off so JSON refusals carry no stack trace"
  - "phpunit.xml memory_limit stays 512M: measured full-suite peak 305.5 MiB allocated (263.7 MiB used), below the 410 MiB threshold"
  - "StagingAudit treats any started_by outside the synthetic users as non-synthetic, including ids of deleted users (the column is not nullable and has no foreign key)"
requirements-completed: []
duration: about 2 h
completed: 2026-11-14
---

# Phase 8 Plan 21: Phase-wide proof Summary

The AI surfaces are inside the medical leak net, never cached, audited on staging, swept for accessibility, and the validation map is signed off for everything except the deferred live plan 08-22. Full Pest (2252 passed), full Vitest (4288 passed), build, check-compose and test-deploy-guards are all green, run alone.

## Tasks and commits

| Task | Commit | What |
|------|--------|------|
| 1 | 34b3060 | Completeness check for every Phase 8 route (matrix row, area params, hidden-record provider, leak surface) with a sanity case for an unregistered read and write route; `AiPrivacyDifferentialTest` (hidden vs never-existed vs random vs malformed id on all 8 AI routes, mismatched person/document pairs, canary scan of other members, admin pages, audit, call log, failed jobs, jobs table and log files); `AiReviewPageTest` now asserts `component('Health/AiReview')` |
| 2 | 966c455 | no-store and private plus no ETag on status, review and page images (and no-store on every AI JSON write, visible and hidden); review page has `encryptHistory` and a stale session lands on confirm-access; sw-health rejects AI urls and mentions none; history-health scans AiReview, Health/Ai and AiCalls for storage and history writes; StagingAudit rules for `medical_ai_extractions` and `ai_calls` with three tests |
| 3 | 7b4159a | a11y-health sweep gained 51 states (RunAiControl in 16 states, AiNoticeDialog, AiReview wide, stacked document and suggestions, accepted/rejected, edit, server-flagged edit, failed action, superseded, empty, loading, error, ProvenanceTags, document rows with status tags in timeline, folder and person page, AI calls login states, multi-page, empty, loading) across en/sk/pl, light/dark, 320 px, +40%; new describe runs +40% text at 320 px for every Phase 8 state; `Settings/AiCalls` registered in the page-level sweep |
| docs | 60d38e3 | 08-VALIDATION.md signed off; two native-review copy tasks recorded in DEFERRED.md |

## Phase gate evidence (run alone, foreground)

- Pest: 2252 passed, 25872 assertions, 0 failed, 216 s. No test failed in the full run, so there was nothing to re-run alone (the earlier cross-test failures did not recur, consistent with parallel plans sharing one test Postgres).
- Peak memory of the full Pest run (shutdown hook with `memory_get_peak_usage`): 320,339,968 bytes allocated (305.5 MiB), 263.7 MiB used, limit 512M. Below the 410 MB threshold, so `phpunit.xml` was not changed.
- Vitest: 130 files, 4288 passed. `npm run build` green, `SW-PRECACHE OK`. Vitest ran after the build, so the built service worker checks were real.
- `check-compose.sh`: OK. `test-deploy-guards.sh`: 23 passed. Pint is clean on every PHP file any Phase 8 commit touched; repo-wide `pint --test` lists 29 older files (Phase 3 to 6 security, auth, audit, wishlist tests) that no Phase 8 plan touched, left alone as out of scope.
- Image checks: `codex --version` is 0.169.0 in the AI image; the app image has `pdftoppm` and no `codex`.

## Carry-overs

- `Settings/AiCalls` registered in `tests/js/a11y-sweep.test.js` (explicit import, 12 new cases). The sweep's inertia mock needed `Head` and `router.reload`, added.
- +40% at 320 px for document rows with status tags: new describe in a11y-health (timeline rows, folder rows, person page), sk and pl, axe plus no horizontal overflow plus no truncation hooks.
- `AiReviewPageTest` tightened to `component('Health/AiReview')`.
- Discard-dialog body (08-19) and singular type nouns (08-18) recorded as native-review copy tasks in DEFERRED.md and in the Manual-Only row of the validation map.
- HEIC and PDFs over 10 pages: not changed (08-22 decision list).
- 08-22 rows stay pending. 08-VALIDATION.md: status approved, `nyquist_compliant: true`, `wave_0_complete: true`, every 08-01..08-21 and 08-23 row green.

## Deviations from Plan

None that change scope. Notes:

- [Rule 3] The plan lists no `tests/Feature/Health/AiReviewPageTest.php`, `DEFERRED.md` or `tests/js/a11y-sweep.test.js` edit for this task; all three were done as the explicit carry-overs.
- `phpunit.xml` unchanged (see peak memory above), so no deviation there.
- The history check for the review page is a Pest assertion (`encryptHistory` in the Inertia page, stale session to confirm-access) in `MedicalCacheHeadersTest`, plus a source scan in `history-health.test.js`. "Cleared on idle lock" itself is proven by the existing `HealthHistoryTest` (confirm-access carries `clearHistory`); `AiReview.vue` uses `history.replaceState(history.state, ...)` only for `?pane=`, and the scan allows exactly that form.
- `AiCallsPageTest` still asserts `component('Settings/AiCalls', false)` where the page exists now (one place); not in this plan's scope or the carry-overs, left as is.
- Some a11y-health targets for AI calls empty and loading states use loose selectors (`h2`, `h1`, `.ui-skeleton`); the per-slice AiCalls tests assert those states precisely.

## Known Stubs

None.

## Threat Flags

None. T-08-93 to T-08-97 are covered by the completeness check, the differential, the canary scan, the no-store and service worker checks, and the StagingAudit rules.

## Self-Check: PASSED

- Files exist: tests/Feature/Ai/AiPrivacyDifferentialTest.php, 08-VALIDATION.md, DEFERRED.md.
- Commits found: 34b3060, 966c455, 7b4159a, 60d38e3.
- `docker compose -p fh0821 down -v` done.
