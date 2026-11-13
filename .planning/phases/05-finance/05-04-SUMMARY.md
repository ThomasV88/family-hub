---
phase: 05-finance
plan: 04
subsystem: finance
tags: [privacy, schema, postgres, leak-net, tdd]
requires: [05-02]
provides:
  - finance_accounts, finance_account_members, finance_categories (11 seeded defaults), finance_transactions with DB-enforced money rules
  - App\Models\FinanceAccount, FinanceCategory, FinanceTransaction (UUIDv7) and factories
  - App\Finance\Viewer, App\Finance\Visibility (single membership scope), App\Finance\Queries\AccountQuery
  - Leak net: arch rule, table scan, mutation-scope scan, FinancePrivacy harness with area-file loader, differential/canary/query-log test, write-parity and scoped-mutation checks
key-files:
  created:
    - database/migrations/2026_11_16_100000_create_finance_core_tables.php
    - app/Models/FinanceAccount.php
    - app/Models/FinanceCategory.php
    - app/Models/FinanceTransaction.php
    - database/factories/FinanceAccountFactory.php
    - database/factories/FinanceCategoryFactory.php
    - database/factories/FinanceTransactionFactory.php
    - app/Finance/Viewer.php
    - app/Finance/Visibility.php
    - app/Finance/Queries/AccountQuery.php
    - tests/Support/FinancePrivacy.php
    - tests/Support/FinancePrivacy/accounts.php
    - tests/Feature/Finance/FinanceSchemaTest.php
    - tests/Feature/Finance/VisibilityTest.php
    - tests/Feature/Finance/FinancePrivacyTest.php
    - tests/Feature/Finance/FinanceWriteParityTest.php
    - tests/Unit/Arch/FinancePrivacyArchTest.php
    - tests/Unit/Finance/FinanceTableScanTest.php
    - tests/Unit/Finance/FinanceMutationScanTest.php
decisions:
  - "Mutation scan tokens include upsert( and DO UPDATE (checker note); runtime assertScopedMutations treats INSERT ... ON CONFLICT DO UPDATE as a mutation. BudgetQuery::upsertVersion (05-12) needs an explicit Visibility-scoped write or it fails the static scan."
  - "FinancePrivacy::unscopedMutations(list<string>) is split out of assertScopedMutations so statement shape is testable before later tables exist."
  - "Area-file merge: lists concatenate, maps keep the first definition (sorted file order), panels per route are concatenated."
metrics:
  completed: 2026-11-13
---

# Phase 5 Plan 04: Privacy-critical data core and leak net Summary

Finance schema with database-enforced exact money (composite (account_id, currency) FK, bigint with 1e13 CHECK bounds, sign-by-kind, transfer rule, per-account import-hash uniqueness), UUIDv7 models, the `Viewer` + `Visibility` membership scope with `AccountQuery`, and a structural leak net that auto-covers every finance page and write route later plans add.

## Commits
- bc82c8c: migration, three models, three factories, FinanceSchemaTest
- 97dabb2: Viewer, Visibility, AccountQuery, arch + table-scan + mutation-scope tests, VisibilityTest
- 1c5c922: FinancePrivacy harness, accounts area file, FinancePrivacyTest, FinanceWriteParityTest

## Verification
- Finance, Arch and Unit/Finance suites green; full Pest 1088 passed (memory_limit 1G), Vitest green, `npm run build` OK (SW-PRECACHE OK). Compose project fh0504 torn down.
- Acceptance probe: temporarily adding `\App\Models\FinanceTransaction::query()->count();` to HomeController made the arch tests (models-only-in-finance-layers, home-page-blind) and the static-call scan fail; reverted before any commit.
- Mutation scanner self-test proves an unscoped `->update(` method is reported, the `Visibility::`-carrying version passes, and `upsert(` / `DO UPDATE` bodies are reported.

## Deviations from Plan
- [Rule 1 - Bug] Plan said `Rule::exists(` must appear nowhere under app/Http/Requests, but the existing UpdateWishlistManagersRequest legitimately uses `Rule::exists` for users. The scan now bans `Rule::exists(` in finance request classes (Http/Requests/Finance/*, Settings/FinanceAccount*) and bans `exists:finance_` / `Rule::exists(...finance_...)` everywhere.
- [Rule 1] A 5-letter currency ('EUROX') is rejected by `char(3)` with SQLSTATE 22001 before the CHECK (23514); the schema test accepts either state for that case (still a DB refusal).
- Checker note folded in: tokens `upsert(`, `DO UPDATE`, `updateOrInsert(`, `updateOrCreate(` added to the static scan; runtime check handles ON CONFLICT DO UPDATE.
- The write-parity visible-write pass runs every non-GET finance route (including parameterless ones such as store), not only routes with a `params` provider.
- TDD: tests and implementation were committed together per task, not as separate RED/GREEN commits.

## Deferred Issues
- `pint --test tests/Unit` flags `tests/Unit/Wishlist/ImageProcessorTest.php` (fully_qualified_strict_types). Pre-existing, not this plan's file; not touched.

## Known Stubs
None. The write-parity and scoped-mutation tests assert nothing about routes yet because no finance write routes exist; plan 05-20 adds the minimum-count assertion.

## Self-Check: PASSED
