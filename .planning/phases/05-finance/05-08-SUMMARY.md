---
phase: 05-finance
plan: 08
subsystem: finance
tags: [privacy, transactions, transfers, inertia, leak-net]
requires: [05-04, 05-05]
provides:
  - finance.transactions.index|store|update|destroy and finance.accounts.index routes
  - FinancePage::props (shared `finance` prop contract), CategoryQuery, TransactionQuery, TransactionPresenter, TransactionWriter, TransactionRequest
  - leak-net area file tests/Support/FinancePrivacy/transactions.php; EndpointMatrixTest accepts closure `data`
key-files:
  created:
    - app/Finance/FinancePage.php
    - app/Finance/Queries/CategoryQuery.php
    - app/Finance/Queries/TransactionQuery.php
    - app/Finance/Transactions/TransactionPresenter.php
    - app/Finance/Transactions/TransactionWriter.php
    - app/Http/Controllers/Finance/AccountsController.php
    - app/Http/Controllers/Finance/TransactionController.php
    - app/Http/Requests/Finance/TransactionRequest.php
    - routes/web/finance-accounts.php
    - routes/web/finance-transactions.php
    - tests/Support/EndpointMatrix/finance-accounts.php
    - tests/Support/EndpointMatrix/finance-transactions.php
    - tests/Support/FinancePrivacy/transactions.php
    - tests/Feature/Finance/AccountsPageTest.php
    - tests/Feature/Finance/TransactionTest.php
    - tests/Feature/Finance/TransferPrivacyTest.php
    - tests/Feature/Finance/TransferWriteParityTest.php
  modified:
    - tests/Feature/Access/EndpointMatrixTest.php
decisions:
  - "Display kind and the transfer counterpart come from one visibility-scoped subquery (counterpart_account_id); a transfer leg without a visible counterpart is presented purely by sign, so the payload equals a plain entry."
  - "Own-leg-only mutation: TransactionQuery::visibleCounterpart is null for a hidden or missing leg; the writer propagates amount/date/description only when it is non-null and never validates against a hidden counterpart."
  - "Category kind on PATCH is judged by the leg's sign (income if positive), so a hidden-counterpart transfer leg behaves exactly like a plain entry; transfers are created without category."
  - "PATCH 404 comes from FormRequest::authorize (before validation), so hidden and unknown transaction ids are identical; immutable keys (account_id, kind, from/to_account_id) are rejected with finance.error.immutable for any value."
  - "Writes redirect 303 to the transactions list, keeping only whitelisted referer params (account, month, q, type, category, source) and dropping tx, so a delete never lands on a now-403 panel."
metrics:
  completed: 2026-11-13
---

# Phase 5 Plan 08: Transactions backend Summary

Record, list, edit and delete income, expense and two-leg transfer transactions on visible accounts, plus the read-only Accounts page, with hidden-counterpart transfers indistinguishable from plain entries on both the read and the write path.

## Commits
- bbd9413: FinancePage, CategoryQuery, TransactionQuery, TransactionPresenter, Accounts and list routes/controllers, AccountsPageTest, TransferPrivacyTest, list tests
- 5f40e80: TransactionRequest, TransactionWriter, write routes, matrix rows (closure `data`), leak-net area file, write tests, TransferWriteParityTest
- final commit: Pint fix of TransactionTest (import ordering)

## Verification
- Full Pest: 1185 tests; the only failure (NotificationSettingsTest section order) came from a sibling plan's in-flight settings change and passed on re-run. Vitest 1144 green, `npm run build` OK (SW-PRECACHE OK), Pint clean on this plan's files.
- Grep gates: no `Rule::exists`/`exists:finance`, no `counterpartForUpdate`, one `visibleCounterpart`, no implicit model binding in finance controllers.
- FinancePrivacyTest (differential, canary, query log) and FinanceWriteParityTest now cover the new routes via the area file; FinanceMutationScanTest and arch tests pass.

## Deviations from Plan
- None in behaviour. TDD: tests and implementation committed together per task (as in 05-04). Extra list tests (paging, filters, 403 parity, query log) live in TransactionTest.php, which the plan lists under Task 2.
- Validation messages for kind/note use the existing `finance.error.tx_save` key; no new translation keys were needed (all finance.error.* and finance.toast.* keys already existed).

## Known Stubs
Panel `match` is always null by contract (05-13 fills it).

## Threat Flags
None beyond the plan's threat model (T-05-26..31, T-05-89 mitigated and tested).

## Self-Check: PASSED
