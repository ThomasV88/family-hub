---
phase: 05-finance
plan: 12
subsystem: finance
tags: [budgets, privacy, postgres, display-kind]
requires: [05-08]
provides:
  - finance_budgets (versioned by effective_from, NULL amount = tombstone)
  - App\Finance\Queries\DisplayKind::sql (shared display-kind CASE/EXISTS fragment, reused by Overview 05-15)
  - App\Finance\Queries\BudgetQuery (forMonth, find, activeFor, versionAt, hasEarlier, upsertVersion, deleteVersion)
  - BudgetWriter, BudgetController, BudgetRequest, routes finance.budgets.store|update|destroy
key-files:
  created:
    - database/migrations/2026_11_16_130000_create_finance_budgets_table.php
    - app/Models/FinanceBudget.php
    - database/factories/FinanceBudgetFactory.php
    - app/Finance/Queries/DisplayKind.php
    - app/Finance/Queries/BudgetQuery.php
    - app/Finance/Budgets/BudgetWriter.php
    - app/Http/Controllers/Finance/BudgetController.php
    - app/Http/Requests/Finance/BudgetRequest.php
    - routes/web/finance-budgets.php
    - tests/Support/EndpointMatrix/finance-budgets.php
    - tests/Support/FinancePrivacy/budgets.php
    - tests/Feature/Finance/BudgetTest.php
decisions:
  - "upsertVersion is insertOrIgnore (ON CONFLICT DO NOTHING) followed by an UPDATE restricted to the visible-account currencies, so no INSERT ... DO UPDATE and no allowlist change; the method also returns early when the currency is not visible."
  - "Removing a budget deletes the version row when it is the first ever version starting that month (equivalent to a tombstone), else writes a tombstone."
  - "BudgetRow is a plain array shape (no class) as specified in the contract."
  - "404 for PATCH/DELETE on a hidden-currency budget is raised in BudgetRequest::authorize before validation, so hidden and random ids answer identically regardless of payload."
metrics:
  completed: 2026-11-13
---

# Phase 5 Plan 12: Category budgets backend Summary

Monthly category budgets as per-currency versions that carry forward by month, with server-computed progress (percent, near at 90%, over with exact amount) counted only on visible and selected accounts, and write routes scoped to the currencies of the viewer's visible accounts.

## Commits
- 7ea0aa0: migration, model, factory, DisplayKind, BudgetQuery, writer, request, controller, routes, matrix, leak-net area file, BudgetTest

## Verification
- Task 1 set (BudgetTest, FinancePrivacyTest, FinanceWriteParityTest, EndpointMatrixTest, Arch, Unit/Finance incl. FinanceMutationScanTest) 253 passed.
- Full Pest 1241 passed (memory_limit 1G), Vitest green, `npm run build` OK, Pint clean on own files. Compose project fh0512 torn down.
- Acceptance greps: no `date_trunc` in Queries, `::bigint` present in BudgetQuery, no `->update(`/`->delete(` in BudgetWriter.

## Deviations from Plan
- [Rule 3 - Sibling interaction] The first full run failed FinanceWriteParityTest with a RuntimeException (a parallel plan's route had no area-file provider yet); the re-run was green with no change on my side.
- My own Pint run (non `--test`) briefly rewrote an unrelated file (ThrottleAuthRoutes.php); reverted with `git checkout -- <that file>` before committing.
- Extra query helpers `versionAt` and `hasEarlier` added to BudgetQuery for the writer's remove logic.
- TDD: tests and implementation committed together.

## Known Stubs
None. Budget panel and Overview props come in 05-15/05-17; error and toast keys (finance.budget.err_taken, err_amount, finance.error.month, finance.toast.budget_saved/removed) are raw keys until the i18n plan adds the strings.

## Self-Check: PASSED
