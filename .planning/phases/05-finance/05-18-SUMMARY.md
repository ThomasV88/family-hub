---
phase: 05-finance
plan: 18
subsystem: finance-import-commit
tags: [laravel, csv-import, idempotency, privacy]
requires: [05-05, 05-11, 05-13]
provides:
  - ImportCommitter (idempotent commit, reconcile, recurring match), ImportUndo
  - POST /finance/import/{batch}/commit, POST .../undo, DELETE /finance/import/{batch}
  - RecurringMatchService::previewMatches (read-only matchName on review rows)
  - finance:prune-imports (daily)
affects: [05-16 wizard UI (now backed)]
key-files:
  created:
    - app/Finance/Import/ImportCommitter.php
    - app/Finance/Import/ImportUndo.php
    - app/Http/Requests/Finance/ImportCommitRequest.php
    - app/Console/Commands/PruneFinanceImports.php
    - tests/Feature/Finance/{ImportCommitTest,ImportUndoTest,PruneFinanceImportsTest}.php
  modified:
    - app/Finance/Queries/ImportQuery.php
    - app/Finance/Recurring/RecurringMatchService.php
    - app/Http/Controllers/Finance/ImportController.php
    - routes/web/finance-import.php
    - routes/console.php
    - tests/Support/EndpointMatrix/finance-import.php
    - tests/Support/FinancePrivacy/imports.php
decisions:
  - "Commit request resolves the batch in authorize(): hidden/foreign/unknown/non-draft is an identical 404 before validation; a committed batch redirects without validation"
  - "Commit locks the batch row (FOR UPDATE), inserts with ON CONFLICT (account_id, import_hash) DO NOTHING RETURNING id, and matches only the ids actually inserted"
  - "Undo second flash is session key toast_kept {key, kept, params:{n}} next to toast (HandleInertiaRequests shares only 'toast', not edited here); the Done step reads kept from batch.result"
  - "Reconcile skips a transaction when the hash is already booked on the account (guards the unique index)"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 5 Plan 18: Import commit, undo, prune Summary

Second half of CSV import: one-transaction idempotent commit with reconciliation of unticked probable duplicates and automatic recurring matching, undo that deletes only pristine rows, discard of a draft, match previews on review rows, and the daily prune of stale drafts.

## Commits

- 5290d1b feat(05-18): commit, reconcile, match preview, discard, undo backend + commit tests + matrix/privacy rows
- e4cc33f feat(05-18): finance:prune-imports + schedule + undo/prune tests
- style(05-18): pint on ImportController

## Privacy properties

- Every UPDATE/DELETE in ImportQuery carries `Visibility::` (membership subquery); the commit INSERT is conditional on the viewer's draft and membership. Statement-capture tests assert `finance_account_members` in every write of commit and undo.
- The only unscoped write is `ImportQuery::pruneDrafts` (docblock `Unscoped write:`, already allowlisted in FinanceMutationScanTest): drafts only, by created_at.
- Commit/undo on hidden, foreign-draft or random ids return the same 404 (tested; FinanceWriteParityTest passes with payload `include => []`).
- After commit staged rows are deleted; only counts remain.

## Verification

Full Pest 1318 passed, Vitest 1415 passed, build and SW precache OK, Pint clean on own files. Compose project fh0518 torn down.

## Deviations from Plan

None of substance. `canUndo` in the history list and the `matchName` prop were wired in ImportController (promised to the 05-16 UI). Undo flashes both toasts as `toast` and `toast_kept` because the shared Inertia props expose one `toast` key.

## Known Stubs

None.

## Threat Flags

None beyond the plan model (T-05-69..73 mitigated and tested).

## Self-Check: PASSED
