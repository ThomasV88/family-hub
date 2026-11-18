---
phase: 15-bulk-medical-document-upload-issue-14
plan: 07
subsystem: health-ui
tags: [vue, upload-batches, review, adjustments, rescan, issue-14]
requires: [15-06]
provides: [entryText.js, BatchEntry, BatchReview, AdjustmentPanel, BatchItemActions, DocumentPane imageFilter]
affects: [15-08]
key-files:
  created:
    - resources/js/Health/Ai/entryText.js
    - resources/js/Health/Batch/BatchEntry.vue
    - resources/js/Health/Batch/BatchReview.vue
    - resources/js/Health/Batch/AdjustmentPanel.vue
    - resources/js/Health/Batch/BatchItemActions.vue
    - tests/js/health/BatchReview.test.js
    - tests/js/health/BatchAdjust.test.js
  modified:
    - resources/js/Health/Ai/SuggestionEntry.vue
    - resources/js/Health/Ai/DocumentPane.vue
    - resources/js/Pages/Health/UploadBatch.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
decisions:
  - "Preview is a CSS filter brightness() contrast() grayscale() on the DocumentPane image; ranges 50-150 and 50-200, step 5, saved 500 ms after the last change and flushed on unmount"
  - "Approve/Reject is a two-button toggle with aria-pressed; pressing the pressed one clears the decision; the full choices object is PUT and the server's answer replaces local state"
  - "Keep document without findings is shown only for failed documents; for documents in review Continue already does it"
metrics:
  tasks: 2
  commits: [2d269b9, 2d4f221]
requirements: [BULK-04, BULK-05, BULK-06, BULK-07]
---

# Phase 15 Plan 07: Batch UI, second half Summary

The batch page now supports the whole per-document loop: the document beside its findings, server-saved Approve/Reject marks and edits, Continue with counts, an adjustment panel with live preview, re-scan with the AI notice and limit, and the failed-document actions (Try again, Keep without findings, Remove from batch). All state comes from props and server answers.

## Commits

- 2d269b9 feat(15-07): entryText.js shared formatter (SuggestionEntry uses it, AiReview tests unchanged), BatchEntry, BatchReview, mounted in the UploadBatch review region
- 2d4f221 feat(15-07): DocumentPane `imageFilter`, AdjustmentPanel, BatchItemActions, two lang keys

Trailer on both: `Refs #14`.

## Verification

- Vitest: tests/js/health (50 files, 3253 tests, including BatchReview 13, BatchAdjust 15, AiReview, AiReviewEdit, RunAi, UploadBatchPage), i18n files and brand guard all green. `npm run build` succeeds, `SW-PRECACHE OK`.
- Grep gates: no `v-html` and no browser storage API in the batch components.

## Deviations from Plan

**1. [Rule 2 - Missing] Two lang keys added** in `health.batch` (en, sk, pl): `rescan_left` ("Re-scans left: {count}", needed for the remaining count the plan asks for) and `remove_doc_keep` (cancel label of the remove dialog; a generic "Cancel" is forbidden by UiConfirmDialog and `delete_keep` says "batch").

**2. Landmarks.** Inside the page's review section the document and findings blocks are `role="group"` (not `<section>`): the findings heading repeats the section's own heading text and axe reported `landmark-unique` on the page.

**3. Where the PUT lives.** BatchEntry sends the choices PUT (as the plan's key link says) but BatchReview owns the choices map; an entry receives the whole map and emits `saved` with the server's answer.

**4. Edited values that are cleared** (empty field) reach the server as null and are ignored there, so the field reverts to the AI value (15-06 carry-over); the UI does not claim anything else.

**5. TDD.** Tests and implementation were written together per task, no separate RED commits (same as 15-06).

**6. Re-scan discards the document's marks** (server resets choices); no extra confirmation was added because the plan does not ask for one. 15-08 may want to mention it in the manual.

## User documentation

User-visible behaviour (review, Continue, re-scan, adjustments): manual and help in 15-08. No `resources/manual` or `resources/help` files touched here.

## SK and PL drafts for native review

- `health.batch.rescan_left`: sk "Zostáva opakovaných skenov: {count}", pl "Pozostało ponownych skanów: {count}"
- `health.batch.remove_doc_keep`: sk "Ponechať dokument", pl "Zachowaj dokument"

(The rest of `health.batch.*` used here was already listed as drafts in 15-06.)

## Known Stubs

None.

## Threat Flags

None beyond the register: T-15-31 (interpolation only, grep gate 0), T-15-32 (no storage, toasts carry counts only), T-15-33 (client ranges are convenience, server clamps), T-15-34 (re-scan follows the notice dialog, server decides).

## Self-Check: PASSED

All created files exist; commits 2d269b9 and 2d4f221 present.
