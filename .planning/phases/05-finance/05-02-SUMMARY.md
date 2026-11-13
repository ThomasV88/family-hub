---
phase: 05-finance
plan: 02
subsystem: finance
tags: [money, parsing, intl, tdd]
requires: []
provides:
  - App\Finance\Currency, MoneyParser (manual/csv), MoneyFormat::plain, TextNormalizer::normalize
  - resources/js/Finance/money.js and MoneyAmount.vue
  - NoFloatScanTest guarding app/Finance, resources/js/Finance, resources/js/Pages/Finance
key-files:
  created:
    - app/Finance/Currency.php
    - app/Finance/MoneyParser.php
    - app/Finance/MoneyFormat.php
    - app/Finance/TextNormalizer.php
    - resources/js/Finance/money.js
    - resources/js/Finance/MoneyAmount.vue
    - tests/Unit/Finance/MoneyParserTest.php
    - tests/Unit/Finance/TextNormalizerTest.php
    - tests/Unit/Finance/NoFloatScanTest.php
    - tests/js/finance/money.test.js
    - tests/js/finance/MoneyAmount.test.js
decisions:
  - "Unit-test scan uses dirname(__DIR__, 3) because Unit tests have no Laravel app (base_path unavailable)"
metrics:
  completed: 2026-11-13
---

# Phase 5 Plan 02: Exact money core Summary

Float-free money parsing and display: strict PHP parser (manual and CSV modes, 1e13 bound), string-arithmetic `money.js` feeding `Intl.NumberFormat` with U+2212 minus, `MoneyAmount.vue`, diacritic-free `TextNormalizer`, and a permanent no-float scan test.

## Commits
- 48370bb: PHP Currency, MoneyParser, MoneyFormat, TextNormalizer plus tests
- bb3a0bc: money.js, MoneyAmount.vue plus Vitest tests
- cd0a085: NoFloatScanTest

## Verification
- Pest `tests/Unit/Finance`: 54 passed (includes 1000-case seeded round-trip).
- Full Pest 1019 passed, Vitest 955 passed, `npm run build` OK (SW precache OK), Pint clean on own files.
- Scan test check: temporarily appending `(float)` to MoneyFormat.php made NoFloatScanTest fail ("MoneyFormat.php contains (float)"); reverted before commit.

## Deviations from Plan
- [Rule 1 - Bug] NoFloatScanTest first used `base_path()`, which fails in Unit tests (no app); switched to `dirname(__DIR__, 3)`. Scan helper functions are prefixed `fhScan` to avoid global name collisions with sibling tests.
- TDD: tests and implementation were written together per task and committed as one feat/test commit each, not separate RED/GREEN commits.
- Note: the scan now also covers sibling plan files under app/Finance (for example app/Finance/Recurring); it passed with them present.

## Known Stubs
None.

## Self-Check: PASSED
