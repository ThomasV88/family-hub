---
phase: 15-bulk-medical-document-upload-issue-14
plan: 04
subsystem: medical-documents
tags: [upload-batches, visibility, continue, rescan, issue-14]
requires: [15-01, 15-02, 15-03]
provides:
  - App\Medical\Queries\UploadBatchQuery (create, addDocument, page, status, setCurrent, saveChoices, saveAdjustments, requestRescan, continue, removeItem, delete)
  - App\Medical\Batches\ItemState display mapping
  - DocumentQuery::create optional $uploadBatchId
  - DocumentRejected reasons batch_full, duplicate, batch_closed
affects: [15-05, 15-06, 15-07]
key-files:
  created:
    - app/Medical/Queries/UploadBatchQuery.php
    - app/Medical/Batches/ItemState.php
    - tests/Feature/Health/UploadBatchQueryTest.php
    - tests/Feature/Health/UploadBatchContinueTest.php
  modified:
    - app/Medical/Queries/DocumentQuery.php
    - app/Medical/Documents/DocumentRejected.php
    - tests/Unit/Health/MedicalMutationScanTest.php
decisions:
  - "Every method resolves the batch through Visibility at AccessLevel::Edit; other person, hidden person, View member, malformed and unknown ids all answer null"
  - "Writers take the per-batch advisory lock then the batch row lock (the tick's order)"
  - "Continue is one transaction over AiReviewQuery::accept/reject; a ValidationException is re-keyed entries.<entryKey>.<field> and rolls everything back"
  - "A pending item with a re-scan requested or any queued/running/waiting run of its document answers not_ready on Continue"
metrics:
  tasks: 2
  commits: [047768e, 8efc3de]
requirements: [BULK-01, BULK-04, BULK-05, BULK-06, BULK-07, BULK-08]
---

# Phase 15 Plan 04: Member side of upload batches Summary

The scoped domain class for everything a member does with a batch: start it (consent, availability and notice like Run AI), upload into it within the limits, read and poll its server-side state, persist choices and adjustments, re-scan (capped at 5), Continue through the Phase 8 accept/reject path, remove an item and delete the batch.

## Commits

- 047768e feat(15-04): create, upload into, read and poll an upload batch
- 8efc3de feat(15-04): batch choices, adjustments, re-scan, Continue, remove and delete

## Verification

UploadBatchQueryTest (11) and UploadBatchContinueTest (11) pass; AiAcceptTest, AiReviewPageTest, UploadBatchTickTest, PendingDocumentVisibilityTest, tests/Unit and tests/Feature/Health, Ai, Audit (1012 tests) pass. Compose project fh1504 torn down.

## Deviations from Plan

**1. [Rule 3 - Blocking] Architecture scanner and rejection reasons** (outside files_modified)
- tests/Unit/Health/MedicalMutationScanTest.php: UploadBatchQuery added to the MedicalDocumentStore users and `delete` callers (removeItem deletes the files after commit). It stays out of the Visibility allowlist: every method that mutates medical_documents or extractions carries Visibility::.
- app/Medical/Documents/DocumentRejected.php: REASONS extended with batch_full, duplicate, batch_closed, which the plan's addDocument throws.

**2. Audit count.** The audit meta has a single `count`, so `continued` records approved + rejected decisions; each decision also has its own existing MedicalAiEntryDecided row (accepted or rejected), so the split is recoverable.

**3. Return shapes the plan left open.** saveChoices returns `['result' => 'saved', 'choices' => ...]`, or `['result' => 'not_ready']` for a non-ready or done item or a pending re-scan (null only outside the scope); unknown keys, unknown fields and bad decisions are dropped silently rather than refused. saveAdjustments returns null for a done item. addDocument for a batch of another person returns null (not batch_closed).

**4. create order.** An already open batch answers `exists` before the consent, availability and notice checks, so a member can always reopen it.

**5. pageCount** in `current.document` is filled only when the item is in review (taken from AiReviewQuery::review); otherwise null, because the file store's absolutePath is limited to two callers by the scan test.

**6. Audit label still missing** for `medical_upload_batch_changed` (as noted in 15-03), now also with actions created, rescan, continued, removed; belongs to the lang/audit-label work in 15-06.

TDD note: both test files were written together with the implementation in one pass per task; runs were red for fixture errors only, so no separate RED commits.

## User documentation

No user-visible change: domain layer without routes or UI; manual and help come in 15-08. No resources/manual or resources/help files touched.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-15-15 to T-15-20 mitigated; the cross-person, hidden, malformed and View-only cases are tested for every method).

## Self-Check: PASSED

Files and commits 047768e, 8efc3de verified present.
