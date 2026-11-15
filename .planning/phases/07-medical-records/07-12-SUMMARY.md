---
phase: 07-medical-records
plan: 12
subsystem: medical-documents
tags: [health, documents, file-serving, cache-headers, prune, js-helpers, ai-consent]
requires: [07-06, 07-09, 07-10]
provides:
  - health.documents.preview / file / update / destroy routes
  - medical:prune-orphans (scheduled daily)
  - securityDownload / securityUpload, handleHealthRefusal
  - AiConsent::allowsDocument, SystemQuery documentSubjectId / knownPaths / existingSubjectIds
affects: [07-13, 07-14, 07-16]
key-files:
  created:
    - app/Http/Requests/Health/UpdateDocumentRequest.php
    - app/Console/Commands/PruneMedicalOrphans.php
    - resources/js/Security/securityTransfer.js
    - resources/js/Health/healthRefusal.js
    - tests/Support/MedicalPrivacy/subjects_documents_files.php
    - tests/Feature/Health/DocumentServeTest.php
    - tests/Feature/Health/DocumentManageTest.php
    - tests/Feature/Health/AiConsentDocumentTest.php
    - tests/js/security/securityTransfer.test.js
    - tests/js/health/healthRefusal.test.js
  modified:
    - app/Http/Controllers/Health/DocumentController.php
    - app/Medical/Queries/DocumentQuery.php
    - app/Medical/Queries/SystemQuery.php
    - app/Medical/AiConsent.php
    - app/Http/Middleware/NoStoreDynamicResponses.php
    - routes/web/health-documents.php
    - routes/console.php
    - tests/Support/EndpointMatrix/health-documents.php
    - tests/Feature/CacheHeadersTest.php
decisions:
  - "Update keeps the current visit unless the body has a visit_id key (explicit null detaches); the 07-14 UI must send visit_id explicitly when it edits the attachment."
  - "Validation messages reuse existing keys (health.error.load, health.upload.err_visit); no new translation keys were added."
  - "Prune leaves files whose names the store does not recognise (store assertPath throws) untouched instead of deleting them."
metrics:
  tasks: 3
  completed: 2026-11-14
---

# Phase 7 Plan 12: Document delivery and management Summary

Authenticated document preview and file routes with the decided cache split, scoped rename/attach/detach/delete, a daily orphan sweep that goes only through the store seam, plus the client transfer helpers, the shared 423 handler and the document-level AI consent check.

## What was built

- **Preview** (`health.documents.preview`): image only, variants `thumb` and `view`. Inline sanitized JPEG with `private, no-cache`, a `"<32 hex of sha256><variant>"` ETag, nosniff, `CSP default-src 'none'`, `Vary: Cookie`. `isNotModified` runs only after the scoped person and document lookup, so a hidden document never yields 304. NoStoreDynamicResponses now excepts `health.documents.preview` for 200 and 304 only.
- **File** (`health.documents.file`): PDF, HEIC original (stored mime) or the full JPEG, always an attachment named `<Str::slug(title) cut to 60 or "document">.<ext>` via `HeaderUtils::makeDisposition`; Cache-Control stays `no-store, private`; no ETag.
- **Refusals**: hidden, unknown, malformed, mismatched, wrong kind or variant, and a row whose file is missing all answer the identical 403 (tested with `MedicalPrivacy::normalise`, also with a real `If-None-Match`).
- **Manage**: `UpdateDocumentRequest::authorize()` resolves the person with `findVisible(..., Edit)` before validation (invalid bodies to hidden/own-off/unknown/malformed/view-only get the identical 403, no session errors). `DocumentQuery::find/update/delete` are scoped statements; delete returns the paths and the controller removes the files after the commit.
- **Prune**: `medical:prune-orphans` reads only `MedicalDocumentStore::files()/subjectDirectories()`, asks `SystemQuery::knownPaths/existingSubjectIds`, removes through `delete()/deleteSubjectDirectory()`, honours `medical.orphan_grace_minutes`, prints counts, scheduled `->daily()->name('medical-prune-orphans')`. No `Storage::` in the command.
- **JS**: `securityDownload` (fetch, blob, filename from `filename=` only) and `securityUpload` (XHR, progress fractions, abort via signal), both same-origin relative paths only, never reject, 423/413/422/429/network mapping. `handleHealthRefusal` sends reauth/confirm to `/confirm-access` and second-factor to `/settings/security` after `onBeforeLeave`.
- **AiConsent::allowsDocument**: resolves the person via `SystemQuery::documentSubjectId`, fail-closed (non-uuid never reaches SQL).

## Verification

- Full Pest: 1852 passed. Vitest: 113 files, 2669 passed. `npm run build` OK. Pint clean on this plan's PHP files.
- The 07-06 file-store scan and mutation scan pass (store `absolutePath` only in DocumentController; prune only uses `files`, `subjectDirectories`, `delete`, `deleteSubjectDirectory`).

## Deviations from Plan

- **[Rule 3 - Blocking] Area file name**: the plan listed `tests/Support/MedicalPrivacy/documents.php`; per the orchestrator it is `subjects_documents_files.php` so it sorts after `subjects.php` (and after 07-10's `subjects_documents.php`). It adds hidden image documents with canary titles, the `variant` param and the update payload.
- **Task commits overlap**: because the controller, routes and `DocumentQuery` hold both Task 1 and Task 2 code, the commits split by file (`6295c2f`: request, prune, schedule, SystemQuery; `36f41c6`: controller, routes, query, middleware, matrix, tests) rather than strictly by task.
- **Flaky full run**: the first full Pest run failed once on `SensitiveGateMatrixTest` (`health.documents.file` 403); the same file and the Security+Health groups passed in isolation, and the second full run passed (1852). Likely cause: `Storage::fake('medical')` uses a shared on-disk directory that the parallel 07-11 test run can wipe. Not reproducible, no code change.
- Test adjustments (not plan deviations): `Vary` is `Cookie, X-Inertia` (Inertia adds its own token), and `Str::slug` turns "2/3" into "23".

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model (T-07-57 to T-07-63 mitigated and tested).

## Commits

- 6295c2f feat(07-12): authorize-first update request and daily orphan sweep
- 36f41c6 feat(07-12): document preview and file routes, rename, attach and delete
- 75486cd feat(07-12): transfer helpers, health refusal handler and AiConsent::allowsDocument

## Self-Check: PASSED

All created files exist and the three commits are in `git log`.
