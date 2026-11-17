---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 04
subsystem: medical-document-search
tags: [search, postgres, unaccent, privacy, audit]
requirements: [GH11-SC4, GH11-SC5]
key-files:
  created:
    - app/Medical/Documents/Text/Snippets.php
    - app/Medical/Queries/DocumentSearchQuery.php
    - app/Medical/Queries/DocumentTextQuery.php
    - app/Http/Controllers/Health/DocumentSearchController.php
    - app/Http/Controllers/Health/DocumentTextController.php
    - app/Http/Requests/Health/DocumentSearchRequest.php
    - routes/web/health-search.php
    - tests/Support/MedicalPrivacy/zz_search.php
    - tests/Support/EndpointMatrix/health-search.php
    - tests/Unit/Documents/SnippetsTest.php
    - tests/Feature/Health/{DocumentSearchTest,DocumentSearchPrivacyTest,DocumentTextViewTest}.php
  modified:
    - app/Medical/PersonPage.php
    - app/Medical/Queries/DocumentTextStore.php
    - app/Audit/AuditEvent.php
    - app/Http/Middleware/RequireAccess.php
    - resources/js/Components/Settings/AuditTable.vue
    - lang/{en,sk,pl}.json
    - tests/Support/MedicalPrivacy.php
    - tests/Feature/Health/{MedicalLeakSuiteTest,MedicalCacheHeadersTest,DocumentPageTest}.php
    - tests/Unit/Arch/DocumentTextArchTest.php
    - tests/js/settings/AuditHealth.test.js
---

# Phase 13 Plan 04: Search backend and text view endpoints Summary

`POST /health/search` answers from one statement that starts at `Visibility::apply` on the documents, so a hidden, archived or foreign document is indistinguishable from a missing one. Snippets are plain `{text, hit}` segments built in PHP. A person's recognised text can be read by everyone who may see the document and removed by editors with a content-free audit row. The person page carries only flags. Inert in production until text rows exist or `ai.ocr.enabled` is on.

## Commits
- a617ce3 snippet builder, SQL/PHP fold parity test
- 85b4010 search endpoint, queries, person page props, privacy differential, matrix and net wiring
- 5e861ea text view and remove endpoints, audit event `medical_text_removed`, labels

## Tests
Isolated compose project `fh1304` (own Postgres 18, scratch folder over storage/logs, serial), removed with `down -v` at the end. Pint `--test`: pass (1225 files). Full Vitest: 189 files, 6233 tests passed. Full Pest, one clean serial run: see "Final full Pest run" below.

## JSON shapes for 13-05 (and 13-06)

**Search** `POST /health/search`, body `{q (required, max 200), person? (string, max 64), page? (1..50)}`, answer `{items, hasMore, page}`; `q` with no usable word (fewer than 2 characters, only symbols) answers `{items: [], hasMore: false, page: 1}`; invalid request shape is a 422 (validation messages never echo the query). Item:
`{documentId, personId, personName, title, uploadedOn (Y-m-d, family time zone), visitId|null, visitName|null, visitOn|null, visitSpecialty|null, page|null, snippets: [[{text, hit}, ...], ...] (0 to 2 snippets, no markup, no ellipsis: the view adds those), textComplete (true when no text), pagesRead|null, pagesTotal|null}`.
`visitName` is the visit's doctor (a visit has no name column); `visitOn` (Y-m-d) and `visitSpecialty` (taxonomy key) are extra fields so 13-05 can label a visit without a doctor. All three are null for an archived visit. Throttle `30/min`, 429 beyond.

**Text view** `GET /health/people/{person}/documents/{document}/text` gives `{pages: [{page, text}], pagesRead, pagesTotal, complete, language}`; hidden, archived, unknown, malformed: the one 403; visible document without text: 404. `DELETE` on the same path gives 204 (editors only), anything else the same 403. Both `no-store, private`.

**Person page props** (`/health/people/{person}` and the overview): `documents[].hasText: bool`, `search: {enabled: bool, textReliability: 'reliable'|'uncertain'}`. `enabled = config('ai.ocr.enabled') || any visible document has text`.

## Deviations
1. [Rule 3] The area file is `tests/Support/MedicalPrivacy/zz_search.php`, not `search.php`: area files are merged in sorted order and `search.php` would sort before `subjects.php`, whose hidden people and documents its seeds need.
2. [Rule 3] `health.search` is a POST named `health.*`, so the medical net would treat it as a write (view-only members must get 403 from every write, but may search). Added `MedicalPrivacy::READ_ONLY_POSTS` / `readPosts()` (excluded from `writes()`); its proof is `DocumentSearchPrivacyTest` plus a LeakSuite check that it stays registered with a matrix row and a payload.
3. [Rule 1] `RequireAccess` audited every non-GET as `health_write`; the search POST (a read, POST only to keep the query out of URLs) is now audited as `health_read`, content-free as before (no route ids, no query). Test: `is audited as a read, not a write`.
4. [Rule 2] The audit row for a removal is written by `DocumentTextStore::removeAudited` (new, constructor now takes `AuditLogger`) in one transaction with the delete, instead of the controller after the fact: a failed audit write removes nothing (tested). The plan said the controller records it; the controller only calls the store.
5. The tsquery binds the PHP-folded alternatives (lower case ASCII letters and digits, anything else is dropped) inside `fh_unaccent(lower(?))` instead of the raw tokens: no tsquery operator can be produced even by an exotic Unicode digit. The SQL fold on the index side is unchanged and parity is proven for every sk and pl letter.
6. Snippet words use `[\p{L}\p{N}]+` (not `\p{L}[\p{L}\p{N}]*`) so a numeric query ("500") highlights like `SearchTerms::parse` tokens do. Snippets carry no ellipsis characters; the view decides.
7. Golden endings: `SearchTerms` (13-01) trims only 1 or 2 trailing characters, so "-ach" and "-ego" cannot reach a base word three characters shorter. The test pairs are real related forms inside that rule (pacjentach finds pacjenta, przewlekłego finds przewlekłe); -u, -om, -ov, -ie behave as the plan lists. Widening the trim is a 13-01 rule change and was left alone.
8. Existing tests adjusted for new behaviour: `DocumentPageTest` (document prop keys gain `hasText`), `MedicalLeakSuiteTest` inventories (`health.documents.text` GET, `health.documents.text.destroy` write parity, count 20).
9. `GET .../text` for a document the viewer sees but that has no text is a 404 (as planned); it reveals only the absence of text on a document the member may already open.

## Security notes
- Query text is POST/JSON only, validated, never in URL, log or audit (tested against storage/logs, audit_logs, response headers). A `QueryException` would carry bound values; `ScrubbedQueryReport` already withholds bindings for `medical_*` statements, so nothing is caught or logged here.
- One statement, membership predicate present (`MedicalPrivacy::unscopedReads` clean); no count query, no `ts_headline`, `LIMIT 21`. A malformed `person` filter returns empty without any medical query.
- Differential test: for hidden person, own profile with self access off, archived person and archived document the answers (including a person filter aimed at the hidden person and page 2) are byte for byte equal to a family without the document.
- The search does leave one timing difference by design (malformed person filter skips the SQL); the plan specified it.

## User documentation
One user-visible change: the audit event label "Removed recognised text in Health" (Settings > Audit, administrators). `resources/manual/*/admin.json` describes the audit log generally and does not list events, so no manual change; no help file touched. The sk ("Odstránený rozpoznaný text v Zdraví") and pl ("Usunięto rozpoznany tekst w Zdrowiu") labels in `lang/sk.json` and `lang/pl.json` are drafts for native review. Search box, results and text view appear with 13-05, which carries the manual and help updates.

## Known stubs
None.

## Final full Pest run
3872 passed, 1 skipped, 0 failed (36177 assertions). An earlier partial run (Health, Ai, Audit, matrix, Unit) had one timing flake in the 13-01 file `tests/Unit/Ai/Eval/TextMetricsTest.php` (CER of 3000 characters under 2.0 s took 2.6 s while Vitest ran in parallel); it passed in the clean full run, file not touched.

## Self-Check: PASSED
Created files exist; commits a617ce3, 85b4010 and 5e861ea are in `git log`.
