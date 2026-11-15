---
phase: 05-finance
plan: 13
subsystem: finance
tags: [matching, recurring, privacy, composite-fk, leak-net]
requires: [05-03, 05-05, 05-08, 05-10]
provides:
  - MatchQuery (scoped reads, insertOrIgnore writes, membership-scoped deleteMatch)
  - RecurringMatchService (matchTransactions, backfill, candidates, matchManually, unmatch, recordPayment)
  - finance.bills.record|match|unmatch routes, BillController, BillMatchRequest, BillRecordRequest
  - page prop `candidates`, Row `matchedRecurringName`, Panel `match`
key-files:
  created:
    - app/Finance/Queries/MatchQuery.php
    - app/Finance/Recurring/RecurringMatchService.php
    - app/Http/Controllers/Finance/BillController.php
    - app/Http/Requests/Finance/BillMatchRequest.php
    - app/Http/Requests/Finance/BillRecordRequest.php
    - routes/web/finance-bills.php
    - tests/Support/EndpointMatrix/finance-bills.php
    - tests/Support/FinancePrivacy/bills.php
    - tests/Feature/Finance/AutoMatchTest.php
    - tests/Feature/Finance/MatchingFlowTest.php
  modified:
    - app/Finance/Transactions/TransactionWriter.php
    - app/Finance/Recurring/RecurringWriter.php
    - app/Finance/Queries/TransactionQuery.php
    - app/Finance/Transactions/TransactionPresenter.php
    - app/Http/Controllers/Finance/RecurringController.php
decisions:
  - "Auto matching runs only on legs the editor actually wrote (TransactionWriter::applyChanges now reports whether it wrote); a hidden transfer counterpart is never passed, read or written, and the service additionally drops ids of hidden accounts through a scoped read."
  - "Auto candidates are restricted to the saved transaction ids; backfill (item create/edit) reads all unmatched same-account payments in the lookback window. Open occurrences come from RecurringQuery::occurrences, so effective_from, lookback and ends_on are honoured."
  - "TransactionWriter and RecurringWriter call the service inside their own DB transaction; the service resolves TransactionWriter lazily in recordPayment to avoid a constructor cycle."
  - "createSingle now returns the FinanceTransaction and takes a trailing bool $autoMatch (recordPayment passes false)."
  - "Record payment on an already matched occurrence is a 422 (finance.error.tx_save); change a paid occurrence through match or unmatch."
  - "Candidate amountMinor is the stored signed amount (negative for expenses), consistent with transaction rows."
  - "Request accessors are named bookedOn()/dueDate() because FormRequest inherits Request::date()."
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 5 Plan 13: Expected-vs-actual matching Summary

Automatic matching of saved transactions and recurring items to open occurrences (same account only, idempotent insertOrIgnore, rejection memory), plus manual match, unmatch and one-step record payment, with match info on transaction rows and ranked same-account candidates on the recurring page.

## Commits
- dbf1721: MatchQuery, RecurringMatchService, writer hooks, AutoMatchTest (10 tests)
- d80aa35: bill routes, controller, requests, candidates prop, match fields on transactions, matrix rows, leak-net payloads, MatchingFlowTest (10 tests)

## Verification
- Pint clean on this plan's files.
- tests/Feature/Finance + EndpointMatrixTest + Arch: 247 passed (FinancePrivacyTest query-log and differential, FinanceWriteParityTest and scoped-mutation checks cover the new routes via bills.php).
- Hidden-counterpart regression (AutoMatchTest): V's PATCH and DELETE of the A leg of a B-hidden transfer leave B's match row, rejection row, B transactions and the status read as O identical; assertScopedMutations passes and every select/update/delete on the match, rejection and transaction tables carries finance_account_members.
- Full Pest: 1283 passed, 1 failed (see Deferred). Vitest 1342 passed. `npm run build` OK (SW-PRECACHE OK).

## Deviations from Plan
None in behaviour. Tests and implementation were committed together per task (as in earlier plans).

## Deferred Issues
- `tests/Unit/Finance/NoFloatScanTest` fails on `Number(` in sibling-owned Vue files: PaymentCalendar.vue and RecurringPanel.vue (05-14, committed) and ImportMappingStep.vue and Import.vue (05-16). Re-run once with the same result. Not this plan's files, not touched.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-05-48..51 mitigated and tested; T-05-52 accepted).

## Self-Check: PASSED
