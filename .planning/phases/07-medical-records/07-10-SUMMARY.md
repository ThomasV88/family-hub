---
phase: 07-medical-records
plan: 10
subsystem: medical-documents
tags: [upload, sniffing, image-pipeline, postgres, nginx]
requires: [07-02, 07-05]
provides:
  - DocumentSniffer, DocumentRejected, MedicalDocumentStore (single medical-disk seam)
  - medical_documents table, MedicalDocument model and factory, DocumentQuery (create side)
  - POST /health/people/{person}/documents (health.documents.store)
  - ImageProcessor::process() optional fullPx/thumbPx
  - live probe for the 12m medical upload limit
affects: [07-12, 07-14]
key-files:
  created:
    - app/Medical/Documents/DocumentSniffer.php
    - app/Medical/Documents/DocumentRejected.php
    - app/Medical/Documents/MedicalDocumentStore.php
    - app/Medical/Queries/DocumentQuery.php
    - app/Models/MedicalDocument.php
    - database/factories/MedicalDocumentFactory.php
    - database/migrations/2026_11_18_130000_create_medical_documents_table.php
    - app/Http/Controllers/Health/DocumentController.php
    - app/Http/Requests/Health/StoreDocumentRequest.php
    - routes/web/health-documents.php
    - tests/Support/MedicalFileFixtures.php
    - tests/Support/EndpointMatrix/health-documents.php
    - tests/Support/MedicalPrivacy/subjects_documents.php
    - tests/Unit/Health/DocumentSnifferTest.php
    - tests/Feature/Health/DocumentUploadTest.php
  modified:
    - app/Wishlist/Catalog/ImageProcessor.php
    - scripts/check-upload-limit.sh
decisions:
  - HEIC/PDF originals stored unchanged; JPEG/PNG/WebP only as sanitized re-encodes
  - Refusals return translation keys (plus params), not translated text
  - documents carry source/verified_at/verified_by (orchestrator instruction) with the manual-never-verified CHECK
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 7 Plan 10: Document upload pipeline Summary

Hardened upload path for medical documents: bytes-only type sniffing (PDF magic at byte 0, HEIC brand allowlist), sanitized image re-encodes through the generalised ImageProcessor, ULID-named files on the medical disk through one IO seam, the documents table with a composite visit FK, and the authorize-first upload route.

## What was built

- **DocumentSniffer**: finfo on the bytes; pdf needs `application/pdf` and `%PDF-` at offset 0; heic needs a heic/heif finfo type, `ftyp` at offset 4 and an allowlisted major brand (AVIF refused); only JPEG/PNG/WebP are images. GIF, BMP, SVG, junk-prefixed PDFs, empty and truncated input give `unsupported_type`.
- **MedicalDocumentStore**: size cap, sniff, then images via `ImageProcessor::process($bytes, $max, 2400, 480)` (pixel cap and decode lock inherited), PDF/HEIC stored byte-identical. All writes verified; a failed write deletes everything attempted and throws `write_failed`. `assertPath` (shape regex) guards `absolutePath()` and every entry of `delete()` before any disk access; `deleteSubjectDirectory()` needs a lower-case uuid; `files()` and `subjectDirectories()` are the read-only inventory for 07-12. `Storage::disk('medical')` appears only here.
- **ImageProcessor**: two optional parameters, defaults still `config('wishlist.*')`; not copied. ImageProcessorTest unchanged and green.
- **Schema**: `medical_documents` with kind/thumb/size/source/verified CHECKs, composite FK `(visit_id, subject_id)` with `ON DELETE SET NULL (visit_id)` (deleting a visit keeps the document), index (subject_id, created_at DESC) and (visit_id).
- **Route**: `access:module,health,edit` plus `throttle:30,1`. `StoreDocumentRequest::authorize()` uses `SubjectQuery::findVisible(..., Edit)` so view-only, hidden, unknown and malformed ids all get the same 403 before any file work. Visit and per-person limit are checked before the file is processed. DB failure after the write deletes the files. JSON success 201 `{document:{id,kind,title}}`; refusals 422 `{errors:{file:[key]}, params:{max,count}}`; HTML gets the normal validation redirect.
- **Probe**: `scripts/check-upload-limit.sh fh0710` now also posts 11 MiB to `/health/people/<uuid>/documents` (reaches PHP, got 302) and to `/login` (413). Output: `UPLOAD-LIMIT OK upload=302 other=413 medical=302 medicalother=413`.

## Deviations from Plan

1. **[Rule 1 - Contract mismatch] Refusals return translation keys, not translated text.** Health copy exists only in the vue-i18n JSON (no PHP lang file), and every other health/wishlist request already returns keys that the client translates. The 422 body carries `params.max` and `params.count` for the `{max}` and `{count}` placeholders. 07-14 should translate `errors.file[0]` with those params.
2. **[Rule 3 - Blocking] Area file renamed `subjects_documents.php`** (plan: `documents.php`). Area files merge in sorted order and the subjects `hidden` seed creates the hidden people, so `documents.php` ran first and crashed the parity test. Same pattern as the sibling `subjects_allergies.php`.
3. **Provenance columns on documents** (`source`, `verified_at`, `verified_by` plus the manual-never-verified CHECK) were added per the orchestrator instruction, although research line 272 says documents carry none in v1. Harmless, unused until Phase 8.
4. `err_limit` copy reads "up to {count} files at a time" (batch wording), but the per-person cap of 200 maps to it as contracted; 07-14 may want a dedicated per-person message.
5. Pint `--test` over `tests/Support` reports one style issue in `tests/Support/FakePasskeys.php`, a pre-existing file outside this plan (not touched).

## Verification

- DocumentSnifferTest + ImageProcessorTest: 42 passed; DocumentUploadTest: 15 passed
- Full Pest (memory_limit 1G): 1804 passed; Vitest: 2576 passed; `npm run build` OK
- Greps: no `getClientMimeType`/`getMimeType()` under app/Medical; `getClientOriginalName` only in `StoreDocumentRequest::defaultTitle()`; `Storage::disk('medical')` only in MedicalDocumentStore.

## Known Stubs

None. Serving, management and UI are 07-12 and 07-14.

## Commits

- 5836d0d feat(07-10): document sniffer, medical store and parameterised ImageProcessor
- 0e3bc21 feat(07-10): medical documents schema, upload route and limit probe

## Self-Check: PASSED
