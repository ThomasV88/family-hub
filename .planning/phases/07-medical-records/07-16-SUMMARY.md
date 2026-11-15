---
phase: 07-medical-records
plan: 16
subsystem: medical-exports
tags: [laravel, inertia, vue, export, provenance, print, audit]
requires: ["07-12", "07-13"]
provides:
  - "App\\Medical\\Provenance (single definition of unverified)"
  - "ExportQuery, Exporter, JsonFormat, MarkdownFormat, Filename"
  - "health.exports.show / print / download routes (edit)"
  - "PrintLayout.vue (reused by 07-18)"
affects: [07-17, 07-18, 07-20]
tech-stack:
  added: []
  patterns: ["audit-first fail-closed export", "lazy chunked reads under Edit scope", "client-side footer date to keep responses clock-free"]
key-files:
  created:
    - app/Medical/Provenance.php
    - app/Medical/Queries/ExportQuery.php
    - app/Medical/Export/Exporter.php
    - app/Medical/Export/JsonFormat.php
    - app/Medical/Export/MarkdownFormat.php
    - app/Medical/Export/Filename.php
    - app/Http/Requests/Health/ExportRequest.php
    - app/Http/Controllers/Health/ExportController.php
    - routes/web/health-exports.php
    - tests/Support/EndpointMatrix/health-exports.php
    - tests/Support/MedicalPrivacy/exports.php
    - resources/js/Layouts/PrintLayout.vue
    - resources/js/Pages/Health/Export.vue
    - resources/js/Pages/Health/ExportPrint.vue
    - tests/Unit/Health/ExportFormatTest.php
    - tests/Feature/Health/ExportTest.php
    - tests/js/health/Export.test.js
  modified: []
key-decisions:
  - "Unverified = source ai AND verified_at null, defined once in Provenance; excluded by default, labeled in all three formats when included"
  - "Specialty scope exports only that specialty's visits and their documents (no allergies/medications/vaccinations)"
  - "Markdown carries no generated_at and the print props carry none either (footer date is taken in the browser), so the privacy differential stays clock-free"
  - "Download format defaults to json when absent; invalid format or missing specialty is 422 for a visible person only"
requirements-completed: [MED-07, MED-08, MED-09]
duration: ~1h
completed: 2026-11-14
---

# Phase 7 Plan 16: Exports Summary

On-demand exports for people a member can change: a printable doctor view in a bare forced-light A4 PrintLayout, streamed JSON data file and anonymized Markdown for AI, with provenance labeling, audit-first fail-closed authorization, nothing stored, and name-free filenames.

## Tasks

1. Provenance, export query, exporter and formats: commit bd75244
2. Routes, request, controller, form, print page, PrintLayout, matrix and privacy rows, tests: committed as part of 1da925c (the sibling plan 07-15 commit swept my already-staged files from the shared index; content is intact)

## Behavior delivered

- `ExportRequest::authorize` resolves the person with `ExportQuery::subject` (Edit scope, uuid check, module Edit); hidden, unknown, malformed and view-only all give the identical 403 before validation. View-only members get only the emergency card (07-18).
- `ExportController::print` and `download` write one `medical_exported` row (subject, scope person|specialty, format html|json|md) before building anything; there is no try/catch, so a failing audit write errors the request and produces no body (tested with FailingAuditLogger).
- Responses: in-memory body with `Content-Type` json/markdown utf-8, `nosniff`, `Cache-Control: no-store, private`, `Content-Disposition` from `Filename::disposition` (`health-export-<all|specialty-slug>-<date>.<json|md>`, via Str::slug and HeaderUtils::makeDisposition). No `Storage::` or `Cache::` in export code (grep test plus fake-disk test).
- 500-visit fixture: JSON and Markdown complete with peak memory growth under 32 MiB (lazy(200) reads).
- Markdown: legend line first when unverified items are included, data-not-instructions preface, the fixed free-text limit line, age only, `[UNVERIFIED]` prefix, escaped single-line fields, fenced notes with fences longer than any backtick run, control and format characters stripped.
- JSON: `verification` and `provenance{source,verified_at}` on every record, `unverified_notice` only when unverified items are present, vaccinations expose `public_id` only, name kept.
- Export.vue: scope and format radiogroups, specialty select only for used specialties (v-if), `format_ai_warning` inline `role=note` under For AI only (aria-describedby), unverified checkbox only when `hasUnverified`, downloads via securityDownload with immediate URL revoke and toast, 423 via handleHealthRefusal, danger alert on failure, "not kept" line.
- ExportPrint.vue in PrintLayout: single h1, person block, stacked-on-narrow tables, document title and date only, Unverified tags, legend and generated footer, no shell landmarks.

## Known limits

- **Anonymisation limit (threat T-07-82b, accepted):** the AI Markdown removes only structured identity fields (name, birth date, contacts). Free-text fields (visit notes, diagnosis, doctor, medication/vaccination notes, allergy substance/reaction, document titles) are exported verbatim and can contain the person's name or other identifying details; the app does not scrub free text (no reliable name detection across SK/PL inflected forms, and altering medical text is worse). Mitigations: the form shows `health.export.format_ai_warning` whenever For AI is selected, the Markdown preface says "Free-text fields are copied as written and may contain identifying details.", and a unit test asserts a note containing the canary name is exported verbatim. 07-20 should show this limit to the owner.
- Specialty scope omits allergies, medications and vaccinations (plan decision); a doctor wanting the summary uses the all scope.
- The "Export records" header button on the person page is added by 07-17 (not here).

## Deviations from Plan

- **[Rule 3] Privacy area file seeds no hidden data.** `tests/Support/MedicalPrivacy/exports.php` sorts before `subjects.php`, so a hidden seed there would run before the hidden subjects exist; the other area files already seed hidden canaries in every table the exports read. It adds only an unverified allergy on the visible person plus the export panel variants.
- **[Rule 1] No clock in responses.** `generated_at` is omitted from the Markdown and from the print page props (HTML-escaped page JSON is not normalised by the shared differential helper, and a second boundary between the before/after captures would break it); the print footer date is formatted in the browser in the family time zone. JSON downloads still carry `generated_at`.
- Download `format` defaults to `json` when absent so the plain download URL is a valid request for the privacy and gate matrix.

## Verification

- `ExportFormatTest` (10), `ExportTest`, `Export.test.js` (15), `MedicalPrivacyTest`, `SensitiveGateMatrixTest`, `EndpointMatrixTest`: green.
- Full Pest: 1938 passed, 2 failed in files this plan does not own (`DocumentUploadTest` file count, `VaccinationReminderTest` digit-in-text check); both passed on one re-run (sibling work in the shared tree and a date-dependent assertion). Not touched.
- Full Vitest: 117 files, 2770 tests passed. `npm run build` passes. Pint clean on this plan's PHP files (`app/Medical/PersonPage.php`, owned by 07-14, reports a style issue; left alone).

## Self-Check: PASSED

All listed files exist; commits bd75244 and 1da925c (contains the task 2 files) are in `git log`.
