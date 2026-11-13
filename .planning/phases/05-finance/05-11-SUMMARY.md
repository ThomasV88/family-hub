---
phase: 05-finance
plan: 11
subsystem: finance-import
tags: [csv, import, staging, dedupe, nginx]
requires: [05-05, 05-06, 05-08]
provides:
  - finance_import_profiles / finance_import_batches / finance_import_rows (+ FKs on finance_transactions)
  - App\Finance\Queries\ImportQuery, App\Finance\Import\ImportStager
  - Routes finance.import.{index,preview,store,show} and the nginx 3m body limit
affects: [05-16, 05-18]
key-files:
  created:
    - database/migrations/2026_11_16_120000_create_finance_import_tables.php
    - app/Models/FinanceImport{Profile,Batch,Row}.php
    - database/factories/FinanceImport{Profile,Batch}Factory.php
    - app/Finance/Queries/ImportQuery.php
    - app/Finance/Import/ImportStager.php
    - app/Http/Controllers/Finance/ImportController.php
    - app/Http/Requests/Finance/Import{Preview,Upload}Request.php
    - routes/web/finance-import.php
    - tests/Support/EndpointMatrix/finance-import.php
    - tests/Support/FinancePrivacy/imports.php
    - tests/Feature/Finance/Import{Stage,Preview}Test.php
  modified:
    - docker/nginx/snippets/laravel-locations.conf
decisions:
  - "Batch and row inserts are single INSERT ... SELECT statements guarded by a finance_account_members predicate (rows via jsonb_to_recordset in chunks of 500), so even staging writes carry the membership scope"
  - "A saved profile is checked strictly: every named column must be found in the header row, otherwise profile_mismatch (the generic resolver would silently fall back to indexes)"
  - "settings may arrive as nested multipart fields or one JSON string; numeric strings are coerced before the ProfileSpec allowlist"
  - "Probable duplicates are matched greedily by smallest date distance, then file order; a hand-entered transaction is claimed once"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 5 Plan 11: CSV import staging Summary

Preview (nothing stored), upload and review data for bank CSV import: the file is read once into memory, decoded, normalised, hashed and staged as a private draft batch with duplicate and probable-duplicate flags scoped to the chosen visible account.

## Commits

- 62cbd83 feat(05-11): schema, models, factories, ImportQuery, ImportStager (RED and GREEN in one commit)
- 7373973 feat(05-11): routes, requests, controller, nginx limit, matrix, area file, ImportPreviewTest

## Verification

- ImportStageTest 8 and ImportPreviewTest 13 pass; endpoint matrix, arch, table/mutation scans, FinancePrivacyTest and write parity green.
- `nginx -t` via `compose run --entrypoint nginx nginx -t` (no host ports) succeeds with the new location block.
- Full Pest (1G) 1251 passed; Vitest 1144 passed; build and SW precache OK. Compose project fh0511 torn down.
- Grep gates clean: no Storage/store/move/Log in the stager and controller.

## Deviations from Plan

None in behaviour. Notes:
- Tests and implementation were committed together per task (no separate RED commit).
- Pint `--test` over `app/Http` reports one pre-existing style issue in app/Http/Middleware/ThrottleAuthRoutes.php (plan 02, not touched here); all files of this plan are clean. Left alone as out of scope.

## Known Stubs

- Row `matchName` is always null (05-18 fills it, per the contract).
- Pages `Finance/Import` and `Finance/ImportBatch` do not exist yet (05-16); tests use `component(..., false)`.

## Threat Flags

None beyond the plan's threat model (T-05-42..47 mitigated: nginx/Laravel/decoder caps and throttles, no persistence of the raw file with snapshot test, account-scoped dedupe, creator-only drafts with identical 404, allowlisted settings).

## Self-Check: PASSED
