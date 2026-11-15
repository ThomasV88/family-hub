---
phase: 05-finance
plan: 10
subsystem: finance
tags: [recurring, privacy, composite-fk, derived-status, leak-net]
requires: [05-03, 05-05, 05-08]
provides:
  - finance_recurring, finance_recurring_matches, finance_match_rejections (composite same-account FKs)
  - OccurrenceWindow, RecurringQuery (visible, findVisible, occurrences, calendar, upcoming, stillToPay, bill, homeCounts, updateVisible, deleteVisible), RecurringWriter
  - finance.recurring.index|store|update|destroy and the Finance/Recurring props contract
  - leak-net area file recurring.php and matrix rows
key-files:
  created:
    - database/migrations/2026_11_16_110000_create_finance_recurring_tables.php
    - app/Models/FinanceRecurring.php
    - app/Models/FinanceRecurringMatch.php
    - app/Models/FinanceMatchRejection.php
    - database/factories/FinanceRecurringFactory.php
    - database/factories/FinanceRecurringMatchFactory.php
    - app/Finance/Recurring/OccurrenceWindow.php
    - app/Finance/Recurring/RecurringWriter.php
    - app/Finance/Queries/RecurringQuery.php
    - app/Http/Controllers/Finance/RecurringController.php
    - app/Http/Requests/Finance/RecurringRequest.php
    - routes/web/finance-recurring.php
    - tests/Support/EndpointMatrix/finance-recurring.php
    - tests/Support/FinancePrivacy/recurring.php
    - tests/Feature/Finance/BillStatusTest.php
    - tests/Feature/Finance/RecurringTest.php
decisions:
  - "Same-account matching is enforced by the database: both match and rejection tables carry account_id with composite FKs to finance_recurring(id, account_id) and finance_transactions(id, account_id); direct SQL moves of a matched transaction's or item's account fail with 23503."
  - "A matched occurrence keeps the expected amount snapshotted in its match row, so editing the amount later never flips a paid past month to paid differently."
  - "stillToPay returns one block per currency of the in-scope visible accounts (zero blocks included), in AccountQuery::currencies order; unpaid = upcoming + overdue; next = first 3 unpaid by date."
  - "next30 lists every status (an early payment shows as paid); overdue lists only overdue items inside the 45-day lookback."
  - "The repeat payload key is `recurrence` (same shape as the task form's RecurrenceFields); a posted mode is ignored. On PATCH, changing starts_on without a pattern rebuilds the stored pattern on the new day."
  - "Writes redirect 303 to the recurring page keeping only account and month."
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 5 Plan 10: Recurring expenses backend Summary

Recurring expenses (schema, CRUD, schedule-mode rules) with statuses derived at read time from the Phase 3 engine and sparse match rows, plus scoped read models for the calendar, overdue/next-30-days list, bill detail, still-to-pay per currency and Home counts. Matching writes come in 05-13.

## Commits
- 98ca525: schema, models, factories, OccurrenceWindow, RecurringQuery, BillStatusTest
- 33cb7d6: RecurringRequest, RecurringWriter, controller, routes, matrix rows, area file, RecurringTest

## Verification
- BillStatusTest (13) and RecurringTest (10) pass; tests/Feature/Finance, EndpointMatrixTest, arch and Unit/Finance: 360 passed (FinancePrivacyTest differential, canary and query-log, FinanceWriteParityTest and FinanceMutationScanTest cover the new routes through the area file).
- Final task: full Pest 1251 passed, Vitest 1144 passed, build OK (SW-PRECACHE OK), compose project torn down. Pint clean on this plan's files.
- Acceptance greps: no `status` in the migration, no completion mode in the request or writer.

## Deviations from Plan
- Invalid repeat input reuses the existing Phase 3 keys (`todo.error.interval`, `todo.error.weekdays`, `todo.error.save_failed`) and window/tolerance/hint errors reuse `finance.rec.hint_*` labels; no new translation keys were added (none existed for these cases).
- Tests and implementation were committed together per task (as in 05-04 and 05-08).

## Deferred Issues
- `pint --test` over the whole `app/Http` tree flags the pre-existing `app/Http/Middleware/ThrottleAuthRoutes.php` (not this plan's file, not touched).

## Known Stubs
`candidates` is always null in the page props by contract (05-13 fills it).

## Threat Flags
None beyond the plan's threat model (T-05-37..41 and T-05-90 mitigated and tested).

## Self-Check: PASSED
