---
phase: 05-finance
plan: 15
subsystem: finance
tags: [overview, aggregates, home-card, privacy]
requires: [05-10, 05-12]
provides: [OverviewQuery, finance.index route, HomeSummary, financeCard Home prop]
affects: [05-17]
key-files:
  created:
    - app/Finance/Queries/OverviewQuery.php
    - app/Http/Controllers/Finance/OverviewController.php
    - routes/web/finance.php
    - app/Finance/HomeSummary.php
    - tests/Support/EndpointMatrix/finance.php
    - tests/Support/FinancePrivacy/overview.php
    - tests/Feature/Finance/OverviewTest.php
    - tests/Feature/Finance/HomeFinanceCardTest.php
  modified:
    - app/Http/Controllers/HomeController.php
requirements: [FIN-07, FIN-06, FIN-05]
completed: 2026-11-13
---

# Phase 5 Plan 15: Overview aggregates and Home card Summary

Per-currency Overview aggregates summed in SQL over visible accounts only (DisplayKind-aware), the `finance.index` route with the `?budget=` panel, and the Home `financeCard` prop through HomeSummary.

## What was built
- OverviewQuery: summary (income, spending, month-end balance), categorySpending (top 7, other, all; half-up integer percent), balanceSeries (12 month-ends via `SUM() OVER` window, carry-forward for empty months). All sums `::bigint`, no `date_trunc`, no float. Hidden-counterpart transfer legs count as income/expense through `DisplayKind::sql`; visible transfers are excluded.
- OverviewController: one Inertia response (`Finance/Overview`) with blocks in AccountQuery currency order, budgets from BudgetQuery, stillToPay from RecurringQuery (null when no visible recurring items). `panelBudget`: `new` needs edit, unknown, hidden-currency and malformed ids give the identical 403.
- HomeSummary + HomeController: `financeCard` {paymentsCount, overdueCount} added only when the user has finance view/edit and a visible recurring item; key absent otherwise. HomeController depends only on HomeSummary (arch rule holds).
- Matrix row for finance.index; leak-net area file overview.php (panel variants over budgets.php/recurring.php seeders). FinancePrivacyTest, query-log scope check and arch tests pass.

## Deviations
None of substance. The panel for a budget outside the current month's rows returns a minimal row (spent 0) with `currency` added; `limitMinor` may be null for a tombstone.

## Verification
Pint clean on own files; full Pest 1260 passed, 1 failed (see below); Vitest 1182 passed; build OK; compose project torn down.

## Deferred issues
`tests/Unit/Finance/NoFloatScanTest` fails on `Number(` in PaymentCalendar.vue, RecurringPanel.vue, ImportMappingStep.vue, Import.vue. These are sibling or earlier plan UI files (not owned by 05-15); failure reproduced on a re-run. Not edited here.

## Commits
34bcd2b feat(05-15) main implementation; follow-up style(05-15) pint fix for the two test files.

## Self-Check: PASSED
