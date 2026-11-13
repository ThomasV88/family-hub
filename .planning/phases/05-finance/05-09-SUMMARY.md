---
phase: 05-finance
plan: 09
subsystem: finance-ui
tags: [vue, inertia, finance, transactions, a11y, privacy]
requires: ["05-01", "05-02", "05-08 props contract"]
provides:
  - FinanceShell, FinanceToolbar, AccountChips, MonthSwitcher (frame for every /finance page)
  - financeQuery.js (financeUrl, visitFinance), labels.js (categoryLabel, accountTypeIcon, formatDay)
  - TransactionList, TransactionRow, TransactionFilters, TransactionPanel, reusable TransactionForm
  - Pages Finance/Transactions and Finance/Accounts
key-files:
  created:
    - resources/js/Finance/financeQuery.js
    - resources/js/Finance/labels.js
    - resources/js/Finance/FinanceShell.vue
    - resources/js/Finance/FinanceToolbar.vue
    - resources/js/Finance/AccountChips.vue
    - resources/js/Finance/MonthSwitcher.vue
    - resources/js/Finance/TransactionList.vue
    - resources/js/Finance/TransactionRow.vue
    - resources/js/Finance/TransactionFilters.vue
    - resources/js/Finance/TransactionForm.vue
    - resources/js/Finance/TransactionPanel.vue
    - resources/js/Pages/Finance/Transactions.vue
    - resources/js/Pages/Finance/Accounts.vue
    - tests/js/finance/fixtures.js
    - tests/js/finance/FinanceShell.test.js
    - tests/js/finance/Transactions.test.js
    - tests/js/finance/TransactionPanel.test.js
    - tests/js/finance/Accounts.test.js
decisions:
  - "visitFinance merges its params over the current URL query and always drops page and tx, so chip, month and filter changes never leave a stale page or panel."
  - "Edit and 'Show more' follow the 05-08 contract: PATCH carries only amount, booked_on, description, note and (non-transfer) category_id; Show more is a partial reload with page+1, preserveUrl and no reset."
  - "The save toast comes from the server flash (finance.toast.tx_saved), which AppShell already translates; the form does not toast itself, to avoid a double toast."
metrics:
  completed: 2026-11-13
---

# Phase 5 Plan 09: Finance shell and Transactions UI Summary

Finance frame (account chips, month switcher, route-backed tabs, zero-account empty state), the Transactions tab with debounced search, filters, day-grouped paged list in wide table or stacked layout, a reusable transaction form and a panel for create, edit, delete and read-only view, plus the member Accounts page, all driven by page props and single Inertia visits.

## Commits
- 7f79701: shell, toolbar, chips, month switcher, helpers, Accounts page, shell and Accounts tests
- f0b34fa: transactions list, filters, rows, form, panel, page and tests

## Verification
- Vitest full: 1144 passed. `npm run build` OK (SW-PRECACHE OK). Full Pest: 1185 passed (incl. NoFloatScanTest over resources/js/Finance and Pages/Finance). Compose project `fh0509` torn down.
- Own Vitest files: FinanceShell 28, Accounts 13, Transactions 62, TransactionPanel 63, each with axe in en/sk/pl, light and dark (shell with and without accounts, Accounts page, list wide/320px/empty/filtered empty/show-more, filters inline and open, panel in each kind, edit, transfer edit, read-only, pseudo-long SK).
- Acceptance greps: no `fetch(`, `axios` or `onMounted(...router` in the shell files; no `v-html` in resources/js/Finance or Pages/Finance.

## Privacy and contract notes
- Chips, selects and the Accounts page iterate only the `finance.accounts` the server sent; tests assert no hidden-account name or id in the DOM. Zero visible accounts renders only the h1 and the empty state (admins get "Add account" linking to /settings/finance-accounts?acct=new): no toolbar, tabs, Import, Add or links.
- The `currency_locked` hint is not rendered anywhere in this plan (Accounts page asserts it is absent); it belongs to the Settings account panel.
- Edit never sends account_id, kind, from_account_id or to_account_id (tests assert exact key sets).
- Amounts are sent as the raw typed string; client parsing (parseMoney) is UX only. Money renders through MoneyAmount/formatMinor with U+2212; transfers are unsigned.

## Deviations from Plan

### Auto-fixed / judgement calls

**1. [Rule 3 - Design] Wide transaction list is a hand-built table, not UiTable**
- **Why:** UiTable has no way to group rows under day headings, and one UiTable per day would repeat the sticky header. The table uses the same tokens and one `<tbody>` per day with an `<h3>` heading row.
- **Files:** resources/js/Finance/TransactionList.vue

**2. [Rule 2 - Scope] Toolbar Import CSV link target**
- Links to `/finance/import` (with the selected account) as a plain anchor via `UiButton href`; the page itself comes from a later plan.

**3. TDD ordering**
- Tests and implementation were written together per task and committed once per task, not as separate RED/GREEN commits.

## Known Stubs
None. Matching meta ("Matches {name}") and the import date on the Imported tag are not rendered because the 05-08 Row/Panel contract carries no such data (`match` is filled by 05-13).

## Self-Check: PASSED
- Files exist (all 13 source files and 5 test files listed above) and commits 7f79701 and f0b34fa are in `git log`.
