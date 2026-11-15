---
phase: 05-finance
plan: 14
subsystem: finance-ui
tags: [recurring, calendar, a11y, vue, inertia]
requires: [05-09, 05-10, 05-13]
provides:
  - StatusTag, StillToPayCard (reused by Overview 05-17 and Home)
  - Finance/Recurring page with calendar and list views, DayPanel, BillPanel, RecurringPanel
key-files:
  created:
    - resources/js/Finance/StatusTag.vue
    - resources/js/Finance/StillToPayCard.vue
    - resources/js/Finance/PaymentCalendar.vue
    - resources/js/Finance/RecurringList.vue
    - resources/js/Finance/DayPanel.vue
    - resources/js/Finance/BillPanel.vue
    - resources/js/Finance/RecurringPanel.vue
    - resources/js/Pages/Finance/Recurring.vue
    - tests/js/finance/recurringFixtures.js
    - tests/js/finance/Recurring.test.js
    - tests/js/finance/PaymentCalendar.test.js
    - tests/js/finance/BillPanel.test.js
    - tests/js/finance/RecurringPanel.test.js
  modified:
    - resources/js/Finance/FinanceShell.vue
    - resources/js/Finance/FinanceToolbar.vue
    - resources/js/Finance/MonthSwitcher.vue
    - resources/js/Todo/RecurrenceFields.vue
decisions:
  - "Panels are driven by query-param visits (?day, ?bill, ?match=1, ?rec) with only=[dayPanel, panelBill, panelRecurring, candidates]; opening one clears the others."
  - "Statuses, differences, sums and candidates are only displayed from server props; the UI computes nothing but layout."
  - "Only 'calendar'/'list' is stored in localStorage (fh-finance-recurring-view)."
  - "Calendar default Tab stop is the selected day, else today, else the first day."
completed: 2026-11-13
---

# Phase 5 Plan 14: Recurring UI Summary

Recurring tab: still-to-pay card per currency, keyboard-operable payment calendar (default) and Overdue / Next 30 days list, day panel, bill panel (expected vs actual, record, match candidates, unmatch) and recurring panel reusing the Phase 3 `RecurrenceFields`.

## Commits
- 92dbb2c: StatusTag, StillToPayCard, PaymentCalendar, RecurringList, DayPanel, shell plumbing
- 530ff26: Recurring page, BillPanel, RecurringPanel, RecurrenceFields props, tests
- 675934f: keep `Number(`/`parseFloat` out of finance UI (NoFloatScanTest)

## Verification
- New Vitest files (Recurring, PaymentCalendar, BillPanel, RecurringPanel): 121 tests including axe in en/sk/pl, light/dark.
- Final task: full Vitest 1342 passed, build OK (SW-PRECACHE OK), Pest 1283 passed after the float-scan fix (NoFloatScanTest re-run green), compose project fh0514 torn down.

## Deviations from Plan
- **[Rule 3] Shared files touched.** `MonthSwitcher.vue` (partial-reload `only` list now includes `recurring`, `panelBill`, `panelRecurring`, `dayPanel`, `candidates`, plus `aria-disabled`), `FinanceToolbar.vue` and `FinanceShell.vue` (a `monthDisabled` prop passed through) were edited minimally so List mode can disable the switcher and month changes reload the recurring props. `RecurrenceFields.vue` gained `showMode` and `allowNone` props (defaults keep the task form unchanged) to hide "Repeat from" and "Does not repeat".
- **[Rule 2] New copy key** `finance.rec.err_save` (en/sk/pl) for the recurring save error (existing keys said "task" or "transaction"). The lines landed in the 05-16 commit 2983ca1 because that sibling committed the whole lang files while my edit was in the tree.
- **[Rule 1] Float scan.** `PaymentCalendar.vue` and `RecurringPanel.vue` used `Number(`, flagged by NoFloatScanTest (the helper name `dayNumber` also matched); replaced with `parseInt(..., 10)` and renamed.
- Chips use `aria-pressed` inside `role="radiogroup"` (same pattern as TransactionForm) because `role="radio"` on the chip failed axe.
- "Due date" label in the bill panel uses the existing `finance.field.date` ("Date"); no dedicated key exists.

## Known Stubs
None. Candidates and the bill routes depend on 05-13 (present in the tree at commit d80aa35).

## Threat Flags
None. T-05-53..55: text interpolation only (tested with an HTML-looking name), only the view string is stored client-side, all statuses and candidates come from server props.

## Self-Check: PASSED
