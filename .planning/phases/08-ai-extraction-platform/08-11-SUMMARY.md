---
phase: 08-ai-extraction-platform
plan: 11
subsystem: health-documents
tags: [pdf, poppler, privacy, routes]
requires: [08-08]
provides: [health.documents.page]
affects: [08 review screen PDF side-by-side]
key-files:
  modified:
    - app/Http/Controllers/Health/DocumentController.php
    - routes/web/health-documents.php
    - tests/Support/EndpointMatrix/health-documents.php
  created:
    - tests/Support/MedicalPrivacy/subjects_documents_pages.php
    - tests/Feature/Health/DocumentPageImageTest.php
requirements: [AI-02]
completed: 2026-11-14
---

# Phase 8 Plan 11: PDF page-image derivative Summary

`GET /health/people/{person}/documents/{document}/pages/{page}` (`health.documents.page`) renders one PDF page as a JPEG on demand through PdfRasterizer, never stored or cached.

## What was built
- `DocumentController::page`: scoped `visibleDocument()`, then `kind === 'pdf'`, page matching `^[1-9][0-9]{0,2}$`, page <= `ai.pdf.view_max_pages`, then `PdfRasterizer::page` on `MedicalDocumentStore::absolutePath`. Every failure (including null from an unreadable PDF or a page beyond the real page count) is the same 403.
- Response: image/jpeg, inline `page-<n>.jpg`, nosniff, CSP `default-src 'none'`, `Vary: Cookie`. `Cache-Control: no-store, private` comes from NoStoreDynamicResponses; no cache exemption added.
- Route middleware `access:module,health,view`, `throttle:120,1,health.documents.page`. The health read audit row is written by the existing route audit middleware (ids only; `page` is not recorded).
- Matrix row with a real 2-page PDF (parent 200, others 403, guest 302). Leak-net area file supplies `page` = '1'.

## Tests
DocumentPageImageTest (4): pages 1..3 with headers and JPEG magic plus no files created under storage or the medical disk; identical 403 for page 0/4/x/01/1000, HEIC, image, hidden person, hidden document, malformed id; unreadable PDFs (garbage bytes, catalog without pages) answer identical 403 within the timeout; 10 rapid requests stay 200. Full Pest suite: 2134 passed. Pint clean.

## Deviations / decisions
- Area file is named `subjects_documents_pages.php` as in the plan; it only supplies `page`, no hiddenParams (a page number is not a hidden record). The seeded area PDFs are stubs, so the leak net compares the same 403 for visible and hidden subjects; content proof is DocumentPageImageTest.
- A truncated valid PDF is recovered by poppler and renders (200); the unreadable test uses garbage and a pageless catalog instead.
- No JS or Vue touched, so Vitest and `npm run build` were not run.

## Self-Check: PASSED
