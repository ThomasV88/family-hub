---
phase: 05-finance
plan: 05
subsystem: finance-import
tags: [csv, iconv, encoding, fixtures, config]
requires: [05-02]
provides:
  - config/finance.php (single owner of Phase 5 tunables)
  - App\Finance\Import\{ImportError, DecodedFile, Decoder, CsvReader, DateParser}
  - Tests\Support\CsvFixtures (synthetic statement generator) and 3 committed fixtures
affects: [05-06, 05-11, 05-18]
key-files:
  created:
    - config/finance.php
    - app/Finance/Import/ImportError.php
    - app/Finance/Import/DecodedFile.php
    - app/Finance/Import/Decoder.php
    - app/Finance/Import/CsvReader.php
    - app/Finance/Import/DateParser.php
    - tests/Support/CsvFixtures.php
    - tests/fixtures/finance/ (3 csv + README.md)
    - tests/Unit/Finance/Import/{DecoderTest,CsvReaderTest,DateParserTest}.php
  modified:
    - .gitattributes
decisions:
  - "Delimiter guess scores lines-agreeing x delimiter-count so decimal commas never beat a real column separator"
  - "DateParser rejects years < 1900 so 'd/m/Y' on '01/02/26' returns null instead of year 0026"
  - "Fixtures marked -text in .gitattributes (repo default eol=lf would corrupt CRLF/legacy-encoded bytes)"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 5 Plan 05: CSV decode and parse Summary

Pure CSV front half: iconv decoding of UTF-8/BOM/UTF-16/Windows-1250/ISO-8859-2/CP852, fgetcsv with escape '' and row cap, strict allowlisted date parsing, and a code-generated synthetic statement fixture generator.

## Commits

- 70f751b feat(05-05): finance config and pure CSV decode/parse pipeline (single commit for RED+GREEN; tests and implementation written together, TDD gate commits not split)

## Deviations from Plan

1. [Rule 1 - Bug] `ImportError::$code` cannot be declared `public readonly string` (PHP: cannot redeclare Exception::$code). Redeclared as untyped `public $code` assigned in the constructor; behaviour matches the contract (public string code).
2. [Rule 1 - Bug] DateParser: `Y` accepts 2-digit years, so `01/02/26` parsed as year 0026. Added a year >= 1900 guard.
3. [Rule 2 - Missing] `.gitattributes` has `* text=auto eol=lf`; added `tests/fixtures/finance/*.csv -text` so committed fixtures keep exact bytes.
4. Unit tests use `uses(Tests\TestCase::class)` (no DB) because Decoder/DateParser read `config()`.

## Verification

- 29 unit tests pass; Pint clean; grep gates clean (no mb_convert_encoding, no logging/storage calls, fgetcsv with `''`).
- Full Pest: first run had one failure in 05-04's FinanceTableScanTest (Rule::exists in an unrelated existing request); re-run of the Finance unit suite passed after the sibling's commit. Vitest 955 passed, build OK. Compose project fh0505 torn down.

## Known Stubs

None.

## Self-Check: PASSED
