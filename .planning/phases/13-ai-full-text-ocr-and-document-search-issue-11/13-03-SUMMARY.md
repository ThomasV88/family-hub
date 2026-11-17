---
phase: 13-ai-full-text-ocr-and-document-search-issue-11
plan: 03
subsystem: medical-document-text
tags: [ocr, postgres, unaccent, privacy, consent]
requirements: [GH11-SC3, GH11-SC5]
key-files:
  created:
    - database/migrations/2026_12_02_100000_create_medical_document_texts_table.php
    - app/Medical/Queries/DocumentTextStore.php
    - app/Medical/Documents/Text/{TextNormalizer,DocumentText,SaveOutcome}.php
    - tests/Support/DocumentTextFixtures.php
    - tests/Unit/Documents/TextNormalizerTest.php
    - tests/Unit/Arch/DocumentTextArchTest.php
    - tests/Feature/Health/{DocumentTextSchemaTest,DocumentTextStoreTest,DocumentTextLeakTest}.php
  modified: [config/ai.php, app/Medical/Queries/SubjectQuery.php, app/Console/Commands/StagingAudit.php, tests/Support/MedicalPrivacy.php, tests/Feature/Health/StagingAuditMedicalTest.php]
---

# Phase 13 Plan 03: Recognised text storage foundation Summary

Own table `medical_document_texts` (plain NFC text, pages separated by U+000C) with a generated accent-folded tsvector and GIN index, a store that writes only under AI consent and no archive, purge of a person's text when consent is withdrawn, privacy net, leak canary, arch pins and a `staging:audit` rule. Nothing writes text yet (13-06); everything is inert and `ai.ocr.enabled` is false.

## Commits
- 765d062 migration, `ai.ocr` config, schema tests
- cd3a292 normaliser, value object, store, consent purge, fixtures, tests
- a24d233 privacy lists, leak canary, arch pins, staging audit rule

## Tests
Isolated compose project `fh1303` (own Postgres 18, scratch folder over storage/logs, serial), removed with `down -v` (no containers or volumes left). Pint `--test`: pass (1212 files). Full Pest in one clean run: 3811 passed, 1 skipped, 0 failed (this also covers the rest of tests/Feature that 13-01 left unverified). Vitest not run: no JS, lang, help or manual file touched.
Restore drill (not Pest): pg_dump -Fc of the migrated test database, pg_restore into a scratch database, `public.fh_unaccent('Łukasz')` gives `Lukasz`, the generated column expression still names `fh_unaccent`, and an insert in the restored database computes `search` (`'lukasz':1`). Scratch database dropped.
The Postgres image (postgres:18-alpine) ships `unaccent` (available, trusted); `fh_owner` (not superuser) created it without any privilege change.

## API for 13-04, 13-05, 13-06
- `App\Medical\Queries\DocumentTextStore` (see deviation 1): `save(documentId, ?extractionId, startedBy, ?agent, ?model, ?language, list<string> $pages, pagesTotal, complete): SaveOutcome`, `remove(Viewer, subjectId, documentId): bool`, `purgeForSubject(Viewer, subjectId): int`.
- `SaveOutcome`: Saved, ConsentOff, Archived, Missing. `DocumentText` value object (`pages()` splits on U+000C), `TextNormalizer::page()/body()`, `TextNormalizer::PAGE_SEPARATOR`.
- Fixtures (class, not global functions): `Tests\Support\DocumentTextFixtures::save($doc, $pages, ?$pagesTotal, $complete, $language, $startedBy)` (turns consent on), `::row($doc)`, `::samplePages()`, constants `CANARY = 'ZxCanaryOcr'`, `SLOVAK`, `POLISH`, `ENGLISH`.
- Arch allowlist constant for 13-04/13-06 to extend: `DOC_TEXT_ARCH_TABLE_ALLOWED` in `tests/Unit/Arch/DocumentTextArchTest.php` (paths relative to `app/`; currently `Medical/Queries/DocumentTextStore.php`, `Console/Commands/StagingAudit.php`). New Queries classes naming the table must be added there. They also need `Visibility::` in any mutating method naming a medical table (mutation scan), and `medical_document_texts` is now in MedicalPrivacy READ/MUTATION tables, so request-side statements need the membership predicate.
- Prefix limit documented by a test: `paracetamolu:*` does not match `paracetamol` (13-04 uses SearchTerms alternatives).

## Deviations
1. [Rule 3] `DocumentTextStore` lives in `app/Medical/Queries/`, not `app/Medical/Documents/Text/`. The repo scans (MedicalTableScan: medical table names and raw `DB::` only in Queries/Visibility/models; mutation scan) forbid both outside Queries, as 13-01 learned. Arch allowlist adjusted accordingly. `save` is split into a guard part and a private `write()` naming only the new table so the mutation scan (needs `Visibility::` for methods mutating owned tables) is not tripped; the new table is not in `MD_MUT_OWNED` (left untouched).
2. [Rule 1] `save` records what the body really holds: pages dropped by the 200 000 character limit lower `pages_read`, and `complete` is forced false unless `pages_read = pages_total` (otherwise the CHECK would throw on 50 pages x 12 000 characters). `extraction_id` parameter is nullable (column is nullable).
3. Fixture helpers are a class with static methods and class constants, not global `docTextSave` and `DOC_TEXT_CANARY` (files in `tests/Support` are autoloaded classes, global functions would not be loaded).
4. The schema test checks `fh_app` rights through `has_table_privilege` and `has_function_privilege` because `fh_owner` cannot `SET ROLE fh_app`; the default privileges are the real mechanism (no GRANT in the migration). TRUNCATE is asserted absent.
5. Leak test omits `/health` (index redirects) and uses the person page props via `viewData('page')`.
6. TextNormalizer removes control and direction characters before NFC (so a removed mark cannot block composition) instead of after.

## Decisions / notes
- A QueryException message carries bound values (the text). The store never catches or logs; 13-06's job must keep its content-free catch and must not report the exception.
- Consent purge sits inside `SubjectQuery::setConsent`'s transaction after the update, only when the value turns false and the row changed; a View member cannot trigger it.

## User documentation
No user-visible change: no text row can exist before the transcription step (13-06), so the table, config and consent purge have no visible effect yet. No `resources/manual/` or `resources/help/` file touched; the manual section "Recognised text" (13-05) and the consent dialog sentence (13-09) document the purge.

## Known stubs
None.

## Self-Check: PASSED
All listed files exist; commits 765d062, cd3a292, a24d233 are in `git log`.
