---
phase: 05-finance
plan: 01
subsystem: i18n
tags: [i18n, vue-i18n, finance, sk, pl, en]
requires: []
provides:
  - finance.* copy namespace (371 keys) in en/sk/pl
  - settings.section.finance_accounts, audit.detail.finance_visibility_added/removed
affects: [05-02, 05-03, all later Phase 5 UI plans]
tech-stack:
  added: []
  patterns: [pipe plurals (en 2 segments, sk/pl 4 segments), copy added up front in one plan]
key-files:
  created: [tests/js/i18n-phase5.test.js]
  modified: [lang/en.json, lang/sk.json, lang/pl.json]
key-decisions:
  - "Import size cap copy is 2 MB (UI-SPEC 5 MB superseded); rows cap via finance.imp.err_rows {max}"
  - "Audit visibility lines take only the member name, no account placeholder"
requirements-completed: [FIN-01, FIN-02, FIN-03, FIN-04, FIN-05, FIN-06, FIN-07, FIN-08]
duration: 10min
completed: 2026-11-13
---

# Phase 5 Plan 01: Finance copy Summary

All Phase 5 copy exists in English, Slovak and Polish, with a parity test, before any Finance screen is built.

The 371 `finance.*` keys came from the UI-SPEC Copywriting Contract tables, plus the locked-decision additions from the plan. Those cover the 2 MB cap, encodings, indicator amount mode, extra row reasons, default category names, generic scoped-validation errors, the immutable-field error, budget toasts, the admin "Manage accounts" link and settings label, and the two audit visibility lines. I generated the JSON from the spec tables with a script and kept the existing 4-space, unescaped-UTF-8 formatting. Existing keys are unchanged.

## Verification
- `tests/js/i18n-phase5.test.js` covers key parity, placeholders, no `!`, compile and render, string/object collisions, plural segment counts (2 in en, 4 in sk and pl) and sample SK/PL plural forms. It passes together with the earlier i18n tests (131 tests).
- Full Vitest: 955 passed. Build: SW-PRECACHE OK. Full Pest: 1019 passed.
- Compose project `fh0501` was torn down.

## Deviations from Plan
None. One acceptance note: `grep -c "5 MB" lang/*.json` prints 1 per file, not 0. The match is the existing wishlist image limit (`wishlist...err_size`, "The image is larger than 5 MB."), which is not finance copy. The test asserts that no `finance.imp.*` value contains "5 MB".

## Known Stubs
None.

## Commits
- c435131: feat(05-01): add finance copy in en/sk/pl with parity test

## Self-Check: PASSED
