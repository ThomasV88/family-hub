---
phase: 15-bulk-medical-document-upload-issue-14
plan: 05
subsystem: health-http
tags: [laravel, health, upload-batches, http, privacy]
requires: [15-04]
provides: [health.batches.* routes, upload_batch_id on documents.store, PersonPage openBatch]
affects: [15-06, 15-07]
key-files:
  created:
    - routes/web/health-upload-batches.php
    - app/Http/Controllers/Health/UploadBatchController.php
    - app/Http/Requests/Health/UploadBatchRequest.php
    - tests/Feature/Health/UploadBatchHttpTest.php
    - tests/Feature/Health/UploadBatchUploadTest.php
    - tests/Support/MedicalPrivacy/zz_upload_batches.php
  modified:
    - routes/web/health-documents.php
    - app/Http/Controllers/Health/DocumentController.php
    - app/Http/Requests/Health/StoreDocumentRequest.php
    - app/Medical/PersonPage.php
    - app/Medical/Queries/UploadBatchQuery.php
    - tests/Feature/Health/DocumentPageTest.php
    - tests/Feature/Health/MedicalWriteParityTest.php
decisions:
  - documents.store throttle raised from 30 to 60 per minute
metrics:
  tasks: 2
  completed: 2026-11-18
---

# Phase 15 Plan 05: Batch HTTP surface Summary

The batch lifecycle (create, page, status, current, choices, adjustments, rescan, continue, remove, delete) is reachable over HTTP for Edit members only, files go into a batch through the existing hardened document upload URL, and the person page reports the open batch.

## Commits

- 4396b8b: batch routes, UploadBatchRequest, UploadBatchController, UploadBatchHttpTest (21 tests)
- faca6f2: upload_batch_id on documents.store, openBatch/batchMaxFiles on PersonPage, scope fixes, privacy net area, UploadBatchUploadTest (12 tests)

All Health and Unit/Health tests pass (673), `scripts/check-upload-limit.sh fh1505` passes (medical URL still 302, other 413). The stack `fh1505` was brought down with `down -v`. Trailer used: `Refs #14`.

## Deviations from Plan

**1. [Rule 1 - Bug] UploadBatchQuery statements lacked the membership scope (15-04 code)**
- Found during: Task 2 (full Health run; MedicalPrivacyTest, MedicalWriteParityTest, MedicalLeakSuiteTest now exercise the batch routes through the privacy net).
- Fix: added private `scopedItems()` (items filtered with a subselect on the scoped batch) and routed every batch update and item read/count/update through `scopedBatches()`/`scopedItems()`; new `openFor()` for the person page uses it too.
- Files: app/Medical/Queries/UploadBatchQuery.php (not in files_modified).

**2. [Rule 3 - Blocking] Privacy net needed providers and an exemption (outside files_modified)**
- tests/Support/MedicalPrivacy/zz_upload_batches.php: `batch` and `item` params, hidden seeds, payloads for every write route.
- tests/Feature/Health/MedicalWriteParityTest.php: for `health.batches.*` routes only, UploadBatchJobQuery statements (cancel runs, advance, purge; keyed by the batch id the same request proved in scope, class already allowlisted in MedicalMutationScanTest) are exempt from the per-statement membership proof. Narrow regexes on four tables.
- tests/Feature/Health/DocumentPageTest.php: expected `limits` now includes batchMaxFiles.

**3. Shape differences from the plan text**
- DELETE batch answers 200 `{removed}` (a 204 cannot carry a body).
- Extra 409 `{reason: not_ready}` for choices on an item without a ready proposal (query returns not_ready).
- Validation messages use the existing lang key `health.error.load` (the `health.batch.*` subtree, including err_full/err_duplicate/err_closed returned by the upload endpoint, is written in 15-06).
- Empty strings in choice values arrive as null (ConvertEmptyStringsToNull) and are dropped by saveChoices, so clearing a field reverts it to the AI value; 15-07 should keep that in mind.
- The Inertia page test sets `inertia.testing.ensure_pages_exist=false` because Health/UploadBatch.vue arrives in 15-06.
- TDD: tests and implementation written in one pass per task (RED run only for fixture errors); no separate RED commits.
- Full `pest` run without a raised limit dies in an unrelated memory test (MedicalImageLimitsTest) when run with `-d memory_limit=1G`; not caused by this plan, Health directories run green.

## User documentation

No user-visible change yet: routes without UI (the page component arrives in 15-06); the raised upload throttle is not described to users. No resources/manual or resources/help files touched. Manual and help are 15-08.

## Known Stubs

None (the `Health/UploadBatch` page component is deliberately delivered by 15-06).

## Threat Flags

None beyond the plan's register: T-15-21 (no binding, authorize before validation, identical 403 asserted for 9 miss kinds on every route, privacy net), T-15-22/23 (unchanged store path, refusals delete files, asserted), T-15-24 (throttles asserted), T-15-25 (no-store asserted), T-15-26 (web group CSRF).

## Self-Check: PASSED

Created files and both commits verified present.
