---
phase: 05-finance
plan: 16
subsystem: finance-import-ui
tags: [vue, inertia, csv, import, wizard]
requires: [05-09, 05-11]
provides:
  - Pages Finance/Import (File, Columns, history) and Finance/ImportBatch (Review, Done)
  - resources/js/Finance/import/* wizard components and importApi.js preview helper
affects: [05-18]
key-files:
  created:
    - resources/js/Finance/import/{importApi.js,ImportStepper.vue,ImportUploadStep.vue,ImportMappingStep.vue,ImportReviewStep.vue,ImportDoneStep.vue,ImportHistory.vue}
    - resources/js/Pages/Finance/{Import,ImportBatch}.vue
    - tests/js/finance/{importFixtures.js,ImportUpload.test.js,ImportMapping.test.js,ImportReview.test.js}
  modified:
    - lang/{en,sk,pl}.json
decisions:
  - "Settings are posted as one JSON string (settings), because multipart fields are trimmed by Laravel and would erase tab and space separators; preview sends tab as the word 'tab'"
  - "The first saved profile is preselected on the File step; 'New profile' is the last option"
  - "Duplicate rows are struck through only while unticked (ticking one means it will be imported)"
  - "Wizard pages use AppShell without FinanceShell (full-page wizard, no tabs)"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 5 Plan 16: Import wizard UI Summary

Four-step CSV import wizard (File, Columns, Review, Done) plus earlier-imports history, built against 05-11 routes and the 05-18 commit/undo/discard contract. The File object and everything decoded from it live in component memory only (no browser storage); the preview call re-sends the file on each parse-relevant change (encoding, separator, header row) and is called only from user actions.

## Commits

- 2983ca1 feat(05-16): File and Columns steps, history, preview API (+ lang keys)
- 0616a5e feat(05-16): Review and Done steps
- c61fbee fix(05-16): drop Number() from the wizard to satisfy NoFloatScanTest

## Verification

- ImportUpload 22, ImportMapping 22, ImportReview 33 Vitest tests pass (axe in en/sk/pl, light/dark, 320px and wide).
- Full Vitest 1342 passed, build and SW precache OK, full Pest 1283 passed after the Number() fix (NoFloatScanTest re-run green). Compose project fh0516 torn down.
- Grep gates: no localStorage/sessionStorage/indexedDB/v-html in the wizard; `line-through` present in ImportReviewStep.

## Deviations from Plan

**1. [Rule 3 - Blocking] Two lang keys added** - `finance.imp.col_select` and `finance.imp.col_status` (en/sk/pl) because the review table needs column headers that did not exist (axe empty-table-header). lang files were not in the plan's file list.

**2. [Rule 1 - Bug] NoFloatScanTest** - first full run failed on `Number(` in the wizard; replaced with `parseInt(x, 10)`.

**3. View transactions link** - goes to `/finance/transactions?account={id}` (current month). The batch props carry no booked-date range, so the "month of the import" cannot be derived on the client. If wanted, 05-18/05-11 can add a `lastBookedOn` to the result.

## Known Stubs

None. `matchName` and `canUndo` are consumed as given by 05-18 (absent/null before it lands: no match tag, no history Undo button).

## Threat Flags

None beyond the plan model (T-05-61..64 mitigated: text-only rendering, memory-only file, XSRF header on preview fetch, client size/extension pre-check).

## Self-Check: PASSED
