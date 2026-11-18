---
phase: 15-bulk-medical-document-upload-issue-14
plan: 01
subsystem: medical-documents
tags: [upload-batches, schema, visibility, issue-14]
requires: []
provides:
  - medical_upload_batches and medical_upload_batch_items tables
  - medical_documents.upload_batch_id pending marker
  - medical_ai_extractions.upload_batch_id, fresh_read, adjustments
  - config medical.upload_batches limits
affects: [15-02, 15-03, 15-04]
tech-stack:
  added: []
  patterns: ["pending documents hidden via upload_batch_id IS NULL in list queries"]
key-files:
  created:
    - database/migrations/2026_12_04_100000_create_medical_upload_batches.php
    - app/Models/MedicalUploadBatch.php
    - app/Models/MedicalUploadBatchItem.php
    - tests/Feature/Health/UploadBatchSchemaTest.php
    - tests/Feature/Health/PendingDocumentVisibilityTest.php
  modified:
    - config/medical.php
    - app/Medical/Queries/DocumentQuery.php
    - app/Medical/Queries/DocumentSearchQuery.php
    - app/Medical/Queries/ExportQuery.php
    - app/Medical/Queries/AiBulkEligibility.php
    - tests/Support/MedicalPrivacy.php
decisions:
  - "Limits: 50 files per batch, 5 re-scans per document, 30-day expiry, one open batch per person (partial unique index)"
  - "find() and countStoredForSubject() deliberately not filtered: batch page needs find, pending documents count toward the 200 cap"
metrics:
  tasks: 2
  files: 11
requirements: [BULK-01, BULK-07, BULK-08]
---

# Phase 15 Plan 01: Upload batch schema and pending visibility Summary

Server-side upload batch tables (one open batch per person via partial unique index, state, adjustments and choices as columns) with a pending marker on documents that keeps unfinished batch documents out of the person page, search, export and the bulk re-read.

## Commits

- 6f0d9fa feat(15-01): upload batch tables, models and limits
- 3b7306e feat(15-01): hide unfinished batch documents from lists, search, export and bulk re-read

## Verification

- UploadBatchSchemaTest (6 tests) and AiPhase9SchemaTest pass.
- Full tests/Feature/Health, BulkRunTest, BulkTickTest, BulkNoTextTest and tests/Unit: 1686 passed, 1 skipped.
- docker compose project fh1501 torn down.

## Deviations from Plan

**1. [Rule 3 - Blocking] Batch tables declared in the medical privacy net**
- **Found during:** Task 2 (full Health run)
- **Issue:** MedicalPrivacyTest "classifies every medical table" failed because the two new medical_* tables were in neither READ_TABLES nor FAMILY_WIDE_TABLES.
- **Fix:** Added medical_upload_batches and medical_upload_batch_items to READ_TABLES and MUTATION_TABLES in tests/Support/MedicalPrivacy.php, so later plans' statements must carry the membership scope.
- **Files modified:** tests/Support/MedicalPrivacy.php (added to Task 2 commit)

**2. Test detail:** jsonb reorders keys, so the adjustments default is asserted with toEqual, not toBe.

TDD note: Task 2 filters were written together with the test in one pass, so no separate RED run was recorded for it.

## User documentation

No user-visible change: schema and a hidden-until-finished filter for documents that cannot exist yet. Manual and help are 15-08. No resources/manual files touched.

## Known Stubs

None.

## Self-Check: PASSED
