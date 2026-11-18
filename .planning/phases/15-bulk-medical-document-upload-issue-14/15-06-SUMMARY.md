---
phase: 15-bulk-medical-document-upload-issue-14
plan: 06
subsystem: health-ui
tags: [vue, i18n, upload-batches, polling, issue-14]
requires: [15-05]
provides: [health.batch lang subtree (en, sk, pl), audit labels, StartBatchControl, OpenBatchBanner, BatchUploader, BatchItemList, useBatchStatus, Health/UploadBatch page shell]
affects: [15-07, 15-08]
key-files:
  created:
    - resources/js/Health/Batch/StartBatchControl.vue
    - resources/js/Health/Batch/OpenBatchBanner.vue
    - resources/js/Health/Batch/BatchUploader.vue
    - resources/js/Health/Batch/BatchItemList.vue
    - resources/js/Health/Batch/useBatchStatus.js
    - resources/js/Health/Batch/batchState.js
    - resources/js/Pages/Health/UploadBatch.vue
    - tests/js/i18n-phase15.test.js
    - tests/js/health/BatchStart.test.js
    - tests/js/health/BatchUploader.test.js
    - tests/js/health/UploadBatchPage.test.js
  modified:
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/js/Components/Settings/AuditTable.vue
    - resources/js/Health/PersonHeader.vue
    - resources/js/Pages/Health/Person.vue
    - tests/js/settings/AuditHealth.test.js
    - tests/js/i18n-phase7.test.js
decisions:
  - "Polling reloads the Inertia props (batch, items, current) when the polled state of the current document differs from the state the page props were rendered with, so 15-07's review area always reads fresh server data"
  - "The review region of the page is a named section (data-testid batch-review) holding the empty state, the finished line and the current document title with its status; 15-07 replaces the body of that section"
metrics:
  tasks: 3
  commits: [0aa2568, f6339dd, c24c392]
requirements: [BULK-01, BULK-02, BULK-03, BULK-07]
---

# Phase 15 Plan 06: Batch UI, first half Summary

A member can start a batch from the person, upload up to 50 files (one request at a time, per-file progress and reasons), and watch every document's status live on a batch page that comes entirely from the server (reload or another device shows the same thing). The person page shows an unfinished batch with progress, expiry and a Continue link. All strings of the feature, including those 15-07 needs, exist in en, sk and pl.

## Commits

- 0aa2568 feat(15-06): lang subtree `health.batch` (85 leaves) and six `audit.detail.medical_upload_batch_changed_*` labels, AuditTable case, i18n-phase15 parity test, AuditHealth test
- f6339dd feat(15-06): StartBatchControl, OpenBatchBanner, BatchUploader, wiring in PersonHeader and Person.vue
- c24c392 feat(15-06): batchState, useBatchStatus, BatchItemList and the `Health/UploadBatch` page

Trailer on every commit: `Refs #14`.

## Verification

- Vitest: i18n-phase15 (8), AuditHealth (14), i18n (20), brand (24), BatchStart (10), BatchUploader (11), UploadBatchPage (17), Person (34), PersonManage (39), DocumentUploader (12), AiStatusPolling all green.
- `npm run build` succeeds, `SW-PRECACHE OK`.
- Grep gates: 0 storage API hits and 0 `v-html` in resources/js/Health/Batch and the page.
- Full Vitest run: 6748 tests; the only failure was tests/js/i18n-phase7.test.js (its plural-key detector did not know the new health.batch subtree); fixed in the docs commit by excluding `health.batch.` like the other later subtrees, then green.

## Behaviour notes for 15-07

- Carry-over (a): audit events created, rescan, continued, removed (and deleted, expired from the job) have labels; AuditQuery needed no change (MedicalUploadBatchChanged is already in the `health` group).
- Carry-over (b): `health.batch.err_full`, `err_duplicate`, `err_closed` exist; BatchUploader maps them from the 422 `errors.file[0]` key and shows them without a retry button.
- Carry-over (c): empty-string choices reach the server as null and revert to the AI value. 15-07 should present "cleared" as "back to the AI value" and not rely on an empty string being stored.
- Carry-over (d): no new string contains the old name; the parity test asserts it.
- The page props come from `UploadBatchQuery::page`: `batch`, `items`, `current` ({item, document, review, choices, adjustments}), `limits` ({maxFiles, maxMb, maxRescans}), `ai`, `person`. `status.batch`/`status.items` (useBatchStatus) are the live copies; choosing an item PUTs `/current` and reloads `['batch','items','current']`.
- Extra lang keys beyond the plan's list (this plan owns the subtree): `delete_keep`, `back`. `start_hint` is written but not yet shown (15-08 may use it for the help tip or the empty state).

## Deviations from Plan

**1. [Rule 3 - Blocking] ai prop passed into PersonHeader.** StartBatchControl needs the providers and notice state; PersonHeader only had `person`. Added an optional `ai` prop to PersonHeader and passed the page's `ai` from Person.vue (same file set as the plan).

**2. [Rule 2 - Missing] Polling refreshes page props.** The plan polls only the status route; the review area (15-07) reads `current`, which only comes with the Inertia props. The page therefore calls `router.reload({only:['batch','items','current']})` when the current document's state changes.

**3. Gate refusal in polling.** The plan says "a 403 goes through handleHealthRefusal"; handleHealthRefusal maps the idle-gate 423 codes (reauth, confirm, second factor). Polling hands the 423 to it; any other failure (including 403) counts as a failed poll and shows "Try again" after three. The server's 403 is deliberate and identical for every miss, so there is no more specific message to show.

**4. Start control on narrow screens.** The "Upload many documents" button is not hidden into the person overflow menu below 640 px (the existing edit actions are hidden by a scoped container query in PersonHeader that does not reach into the child component). The control stays visible; the plan did not ask for a menu entry.

**5. TDD.** Tests and implementation written together per task (RED for the lang task was implicit: the parity test needs the strings); no separate RED commits.

**6. Upload starts automatically** once files are picked (AI starts on the first document while the rest upload), instead of a separate Start button.

## User documentation

User-visible behaviour is added (button, page, statuses) but the manual section and the contextual help tips are written in 15-08: "manual and help in 15-08". No `resources/manual` or `resources/help` files touched here; no HelpTip placed yet so the help coverage test stays consistent.

## SK and PL texts for native review (drafts)

All of `health.batch.*` in `lang/sk.json` and `lang/pl.json`, and these audit keys in both files: `audit.detail.medical_upload_batch_changed_created`, `_rescan`, `_continued`, `_removed`, `_deleted`, `_expired`. Points worth a native look: the plural forms of `state.review` and `undecided` (one | few | many/fractional | other, same pattern as existing keys), `rescan_limit` in Slovak ("{count}-krát"), "partia dokumentów" (PL) / "dávka dokumentov" (SK) as the term for batch, and the tone of `start_hint`, `failed_hint` and `delete_body`.

## Known Stubs

None. The review region shows only the current document title and status until 15-07 adds the review controls (planned, not a hidden stub: the region is named in the plan as "filled in 15-07").

## Threat Flags

None beyond the plan's register: T-15-27 (memory-only, no-store status request, grep gates), T-15-28 (interpolation only, no v-html), T-15-29 (UI hides Start for non-editors, server enforces), T-15-30 (one file at a time, 30 s wait, at most 3 retries on 429, asserted with fake timers).

## Self-Check: PASSED

All files listed above exist; commits 0aa2568, f6339dd, c24c392 verified present.
