---
phase: 15-bulk-medical-document-upload-issue-14
plan: 03
subsystem: medical-documents
tags: [upload-batches, scheduler, ai-queue, consent, expiry, issue-14]
requires: [15-01]
provides:
  - UploadBatchJobQuery advance, cancelBatchRuns, purge, resetForConsent, expireDue, openBatchIds
  - BatchKicker::kick
  - ai:upload-batch-tick (every minute) and medical:expire-upload-batches (hourly)
  - AiRunQuery::acknowledgmentNeeded and recordAcknowledgment
  - AuditEvent::MedicalUploadBatchChanged
affects: [15-04, 15-05]
key-files:
  created:
    - app/Medical/Queries/UploadBatchJobQuery.php
    - app/Medical/Batches/BatchKicker.php
    - app/Console/Commands/AiUploadBatchTick.php
    - app/Console/Commands/ExpireUploadBatches.php
    - tests/Feature/Ai/UploadBatchTickTest.php
    - tests/Feature/Health/UploadBatchExpiryTest.php
    - tests/Feature/Health/UploadBatchConsentTest.php
  modified:
    - app/Medical/Queries/AiRunQuery.php
    - app/Medical/Queries/SubjectQuery.php
    - app/Audit/AuditEvent.php
    - routes/console.php
    - tests/Feature/Ai/AiScheduleTest.php
    - tests/Unit/Health/MedicalMutationScanTest.php
decisions:
  - "Batch runs are interactive rows (queue ai.queue, no bulk_run_id) marked with upload_batch_id; they count toward the global active cap but not the per-member limit"
  - "One active run per batch: a quota-parked (waiting) run blocks the next document until the existing ai:resume-waiting path requeues it"
  - "Consent withdrawal cancels with cancel_reason consent and resets items to pending; the batch and its choices stay"
  - "purge releases documents with an accepted decision (upload_batch_id null) before deleting the batch; files are deleted through DB::afterCommit"
metrics:
  tasks: 2
  commits: [43e57bc, ed43c47]
requirements: [BULK-02, BULK-03, BULK-05, BULK-08]
---

# Phase 15 Plan 03: Batch tick, quota resume, consent hook and expiry Summary

System side of upload batches: a per-minute tick starts at most one document per batch under the existing Phase 9 guards, quota parking and resume work through the existing waiting path, consent withdrawal pauses batches consistently with Phase 13, and batches expire after 30 days without losing finished work.

## Commits

- 43e57bc feat(15-03): advance upload batches one document at a time with quota parking and tick
- ed43c47 feat(15-03): expire upload batches after 30 days and pause them on consent withdrawal

## What was built

- `UploadBatchJobQuery::advance` (advisory lock per batch, one active run, subject share lock and consent, re-scan first then position, starter = requested_by or acted_by checked with AccessGate/isEditor, global cap, acknowledged-provider agent, savepoint insert, ai_calls row, audit row). `fresh_read` and non-neutral `adjustments` are written on the extraction.
- `BatchKicker` dispatches `RunExtractionJob` on `ai.queue` after commit and calls `failQueued` when dispatch throws. `ai:upload-batch-tick` prints `enqueued=N` only.
- `AiRunQuery::start` now uses the extracted `acknowledgmentNeeded` / `recordAcknowledgment` (behaviour unchanged); `busy()` skips batch rows for the per-member count.
- `cancelBatchRuns`, `purge` ('deleted'|'expired'), `resetForConsent` (called from `SubjectQuery::setConsent` right after the text purge), `expireDue`, `medical:expire-upload-batches` (counts only).
- Audit event `medical_upload_batch_changed` (subject, batch, action, count), group health.

## Verification

Full tests/Feature/Health, tests/Feature/Ai, tests/Feature/Audit and tests/Unit: 2051 passed, 1 skipped. Compose project fh1503 torn down. The quota end-to-end test uses FakeCodex (quota answer, then success).

## Deviations from Plan

**1. [Rule 3 - Blocking] Architecture scanners updated** (tests/Unit/Health/MedicalMutationScanTest.php, not in files_modified)
- UploadBatchJobQuery added to the unscoped-class allowlist (with reason) and to the MedicalDocumentStore user and `delete` caller lists, otherwise MedicalMutationScanTest failed.
- Commit: 43e57bc

**2. Audit label not added to the UI.** `resources/js/Components/Settings/AuditTable.vue` and `lang/*.json` have no label for `medical_upload_batch_changed` yet (outside this plan's files). The audit page falls back to its default rendering; a label (en/sk/pl) should be added by the plan that owns UI or in 15-08.

**3. Minor:** the plan's `cancelBatchRuns` writes no item changes (only `resetForConsent` resets items); advance is not retried for items stuck in `sent` after a failed or access-cancelled run: a re-scan (15-05) takes over, as the plan describes.

TDD note: tests and implementation for task 1 were written in one pass (test file first, run red only for fixture errors); no separate RED commit.

## User documentation

No user-visible change in this plan by itself (scheduler, worker side, consent side effect on rows that cannot exist before the UI ships). The consent and expiry behaviour goes into the manual and help in 15-08. No resources/manual or resources/help files touched.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-15-09 to T-15-14 mitigated and tested).

## Self-Check: PASSED

Files and commits 43e57bc, ed43c47 verified present.
