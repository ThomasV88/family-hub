---
phase: 07-medical-records
plan: 14
subsystem: health-ui
tags: [health, documents, uploader, inertia, vue]
requires: [07-12, 07-13]
provides:
  - "PersonPage props documents, unattachedDocumentIds, limits, per-visit documentCount, document timeline entries and filter, panels document and new-document"
  - "DocumentUploader, DocumentPanel, DocumentPreview, AttachDocumentDialog, documentFormat.js"
  - "VisitPanel documents section; SpecialtyFolder documents variant; TimelineList document rows"
affects: [07-15, 07-16]
key-files:
  created:
    - resources/js/Health/DocumentUploader.vue
    - resources/js/Health/DocumentPanel.vue
    - resources/js/Health/DocumentPreview.vue
    - resources/js/Health/AttachDocumentDialog.vue
    - resources/js/Health/documentFormat.js
    - tests/Support/MedicalPrivacy/subjects_documents_page.php
    - tests/Feature/Health/DocumentPageTest.php
    - tests/js/health/DocumentUploader.test.js
    - tests/js/health/Documents.test.js
  modified:
    - app/Medical/PersonPage.php
    - resources/js/Pages/Health/Person.vue
    - resources/js/Health/PersonHeader.vue
    - resources/js/Health/VisitPanel.vue
    - resources/js/Health/SpecialtyFolder.vue
    - resources/js/Health/TimelineList.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
decisions:
  - "The server's per-person cap answers health.upload.err_limit (count 200), whose text says 'files at a time' (the batch limit). The client maps that key to a new health.upload.err_cap text; the controller is untouched."
  - "health.doc.saved and health.doc.deleted already existed in en/sk/pl (07-12 wrongly noted them as missing); a parity assertion was added in Documents.test.js."
  - "Document attach/detach always PATCHes with title and an explicit visit_id (null detaches)."
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 14: Documents on the person page Summary

Editors upload one or several PDFs/photos from the person page (one request per file, progress, retry, cancel, leave guard), manage documents in a panel (preview, open, download, rename, attach/detach, delete), and see them under visits, in a "Documents without a visit" folder and on the timeline; view-only members can open and download.

## What was built

- **PersonPage**: `documents` (id, kind, title, visitId, uploadedOn, sizeBytes only), `unattachedDocumentIds`, `limits`, scoped `documentCount` per visit, document timeline entries and a `documents` filter chip only when unattached documents exist; panels `document` (id resolved inside the person, else 403) and `new-document` (edit only, optional `visit` must be this person's visit, else the same 403).
- **DocumentUploader**: native multi-file input (accept includes .heic/.heif), hint with server limits, client pre-check of extension and size, optional name for one file, visit select, per-file queue with UiProgress, retry, Cancel aborting the running transfer, leave guard (Inertia `before` + beforeunload), 423 via `handleHealthRefusal` with the guard lifted first, `errors.file[0]` keys translated with the returned params, partial reload after the queue.
- **DocumentPanel / DocumentPreview**: image via `<img>` with onerror recovery (Confirm it's you, one retry per click), PDF file card (no embed), HEIC no-preview card; Open in new tab, Download via `securityDownload` with the object URL revoked right after the click; PATCH always carries `visit_id`.
- **Visit panel**: Documents section, Remove from visit (`visit_id: null`), AttachDocumentDialog with "Upload a new document" jumping to `?panel=new-document&visit=`.
- Lang: `health.upload.err_cap`, `health.upload.leave_body`, `health.upload.remove` in en/sk/pl.

## Deviations from Plan

1. **[Rule 1 - Bug] Cap text**: server `err_limit` (count 200) read "up to 200 files at a time"; mapped client-side to the new `err_cap` ("record holds up to {count} documents and is full").
2. **[Rule 2 - Missing] Keys**: `leave_body` (UiConfirmDialog requires a body) and `remove` were not defined; added in all three locales. Toast keys `health.doc.saved/deleted` were already present.
3. **Area file name**: `tests/Support/MedicalPrivacy/subjects_documents_page.php` instead of `documents.php`, so it sorts after `subjects_documents.php` (seeds `document`), following 07-12.
4. **Person.vue**: the empty-visits empty state now also requires no documents, so documents of a person without visits stay reachable. SpecialtyFolder keeps its original visits count expression (Visits.test.js asserts it).

## Verification

- DocumentPageTest (5), DocumentUploader.test.js (12), Documents.test.js (19), all tests/js/health pass.
- Axe clean for uploader (waiting/uploading/failed), document panel (image, load failure, PDF, HEIC, read-only), attach dialog (en). sk/pl are checked for translation, not axe, in jsdom.
- No `localStorage|sessionStorage|indexedDB` under resources/js/Health; `revokeObjectURL` in DocumentPanel; `accept=` includes `.heic`.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register (T-07-68 to T-07-72 mitigated and tested).

## Commits

- 20abdb5 feat(07-14): document props, uploader with queue, document panel and preview
- 8e34eb2 feat(07-14): attach documents from visits, unattached folder and timeline documents
