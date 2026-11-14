---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 15
subsystem: settings-ai-calls
tags: [ai-06, call-log, quota, privacy]
requires: ["09-01", "09-12"]
provides: ["status filter 'quota'", "row flags quota/bulk", "bulk person masking"]
key-files:
  modified:
    - app/Medical/Queries/AdminAiCallQuery.php
    - app/Http/Controllers/Settings/AiCallsController.php
    - tests/Feature/Settings/AiCallsPageTest.php
    - resources/js/Settings/AiCalls/AiCallsFilters.vue
    - resources/js/Settings/AiCalls/AiCallsTable.vue
  created:
    - tests/js/settings/AiCallsQuota.test.js
decisions:
  - "Filter option 'quota' appended after the five existing statuses in the select."
  - "Table component tests mount AiCallsFilters/AiCallsTable directly rather than the page (page is owned by 09-16)."
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 9 Plan 15: Call log quota visibility and bulk masking Summary

AdminAiCallQuery now supports `status=quota` (failed + quota_exhausted), flags rows with `quota` and `bulk` booleans computed in SQL, masks the person for bulk rows and excludes bulk rows from person filtering; the UI shows a warn "Usage limit reached" tag with Hourglass and a muted "Not shown (bulk run)" person cell.

## Done
- Task 1: query filter and flags, controller `login` prop and AgentHealth dependency removed (check action/route untouched). AiCallsPageTest: deleted the `login.agent` assertion and the "reflects the login health states" case, added `missing('login')`, added tests for per-attempt rows with quota flag, quota filter, bogus ignored, bulk masking with canary name, no `bulk_run_id`/`bulkRunId` in props, person filter excluding bulk rows. 10 tests pass.
- Task 2: filter option, quota tag, masked cell. AiCallsQuota.test.js: 12 tests (en/sk/pl, axe light/dark, 40% pseudo-long strings, filter emit). Existing AiCalls.test.js still passes (33 tests, run read-only, not modified).

## Deviations from Plan
None. Pint reformatted the test file (one style fix).

## Known Stubs
None.

## Threat Flags
None. T-09-63 and T-09-64 mitigations covered by tests.

## Self-Check: PASSED
