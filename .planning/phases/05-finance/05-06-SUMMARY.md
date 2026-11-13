---
phase: 05-finance
plan: 06
subsystem: finance-import
tags: [csv, profile, dedupe, hash]
requires: [05-02, 05-05]
provides:
  - App\Finance\Import\{ProfileSpec, NormalizedRow, RowNormalizer, ImportHasher}
affects: [05-11, 05-18]
key-files:
  created:
    - app/Finance/Import/ProfileSpec.php
    - app/Finance/Import/NormalizedRow.php
    - app/Finance/Import/RowNormalizer.php
    - app/Finance/Import/ImportHasher.php
    - tests/Unit/Finance/Import/RowNormalizerTest.php
    - tests/Unit/Finance/Import/ImportHasherTest.php
    - tests/Unit/Finance/Import/PipelineVariantsTest.php
  modified:
    - tests/Support/CsvFixtures.php
decisions:
  - "Required columns resolve by case-insensitive header name, then index; unresolved required roles throw ImportError profile_mismatch"
  - "Row error precedence: columns, date, amount, currency, before_open"
  - "Reference-bearing rows hash accountId|ref|reference; others accountId|date|amount|normalizedDescription|ordinal"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 5 Plan 06: CSV map and hash Summary

Generic, allowlisted CSV profile (ProfileSpec), header-name mapping into signed-minor-unit NormalizedRows in single, split and indicator amount modes, and per-account sha256 dedupe hashes with in-file ordinals, proven over 28 synthetic statement variants.

## Commits

- 542eac3 feat(05-06): profile spec, normalizer, hasher and tests (RED and GREEN in one commit)

## Deviations from Plan

1. [Rule 1 - Bug] tests/Support/CsvFixtures.php (05-05): grouping used strrev over the joined string, corrupting a multibyte separator (NBSP became invalid UTF-8). Now reverses groups instead.
2. NBSP grouping variant uses profile grouping ' ' (MoneyParser squashes NBSP before separators; ProfileSpec allowlists only ' ', '.', ',').
3. Tests and implementation committed together (no separate RED commit).

## Verification

- tests/Unit/Finance/Import: 84 passed (28 pipeline variants); Pint clean; grep gate for Log/Storage/file_put_contents clean.
- Full Pest (1G): 1170 passed, 2 failed in sibling-owned files (05-09 account-id endpoint test, settings sections list) that were in flight; first run had 6 sibling failures that shrank after 05-09 committed. Vitest 955 and build green. Compose project fh0506 torn down.

## Known Stubs

None.

## Self-Check: PASSED
