---
phase: 05-finance
plan: 20
subsystem: finance-verification
tags: [leak-suite, mutation-sanity, privacy, cache, staging-audit, axe, service-worker, validation]
requires: [05-07, 05-16, 05-17, 05-18, 05-19]
provides:
  - proof that the finance leak net is complete (route inventory) and sensitive (planted unscoped reads and writes are caught)
  - admin-not-ticked and view-only privacy tests
  - no-store checks over every finance response and a service-worker finance check
  - staging:audit rules for finance tables
  - finance axe sweep (all pages and panels, en/sk/pl, light/dark)
  - signed-off 05-VALIDATION.md (plans 05-01..05-20)
affects: [05-21]
key-files:
  created:
    - tests/Feature/Finance/FinanceLeakSuiteTest.php
    - tests/Feature/Finance/AdminFinancePrivacyTest.php
    - tests/Feature/Finance/FinanceCacheHeadersTest.php
    - tests/Feature/Finance/StagingAuditFinanceTest.php
    - tests/js/finance/a11y-finance.test.js
    - tests/js/finance/sw-finance.test.js
  modified:
    - app/Console/Commands/StagingAudit.php
    - tests/Unit/Finance/FinanceTableScanTest.php
    - tests/Unit/Finance/FinanceMutationScanTest.php
    - tests/Feature/Finance/FinanceWriteParityTest.php
    - resources/js/Finance/TransactionList.vue
    - tests/js/finance/Transactions.test.js
    - .planning/phases/05-finance/05-VALIDATION.md
decisions:
  - "Mutant routes (finance.zz_mutant_*) are registered inside tests only; a test asserts none exist in the app"
  - "Staging audit treats a null created_by (deleted creator) as non-synthetic, the stricter reading"
  - "05-VALIDATION signed off for 05-01..05-20; 05-21 rows stay pending"
metrics:
  completed: 2026-11-13
---

# Phase 5 Plan 20: Leak-suite completeness and phase sign-off Summary

The finance privacy net is now proven both complete and sensitive: unscoped read and write mutants are caught by the differential, canary, query-log and scoped-mutation checks while every real surface stays clean; the admin, view-only, no-store, service-worker, staging-audit and axe guards are in place and the validation map is signed off for plans 05-01..05-20.

## Tasks and commits

| Task | Commit | Content |
|------|--------|---------|
| JS sweep | 373216f | `a11y-finance.test.js` (15 page/panel variants x en/sk/pl x light/dark), `sw-finance.test.js`, heading fix |
| Task 2 | 72979df | StagingAudit finance rules, one-file scan allowlist with self-test, `StagingAuditFinanceTest` |
| Task 1 | c72c208 | `FinanceLeakSuiteTest`, `AdminFinancePrivacyTest`, `FinanceCacheHeadersTest`, minimum counts, per-class planted-write check |
| Task 3 | docs commit | 05-VALIDATION.md updated and signed off |


## What was proven

- Completeness: surfaces() contains all 10 inventory GET routes and no stray settings.* route; 13 record-id write routes are in hidden-vs-random parity; 19 write routes are in the scoped-mutation set (21 minus the two allowlisted category routes); a finance GET route with an unknown parameter makes `FinancePrivacy::urls()` throw.
- Mutation sanity (reads): an unscoped `count(*)` mutant is caught by the differential check, an unscoped grouped `sum(amount_minor)` mutant by the canary scan, and both by the query-log check; the same capture run keeps all real surfaces identical and canary-free, and the only unscoped statements are the mutants'.
- Mutation sanity (writes): a throwaway POST route running an unscoped `UPDATE finance_transactions` fails `assertScopedMutations`. The static scan (`FinanceMutationScanTest`) now plants four unscoped write shapes (builder update, raw SQL, upsert, delete) into every class in `app/Finance/Queries` and asserts each is flagged, and that a scoped variant is not (carry-over 2).
- Minimum counts: `FinanceWriteParityTest` now asserts at least 13 parity routes and at least 19 scoped-mutation routes (05-04 note).
- Admin: an admin who is a member of A but not of X sees X's name and type only on Settings > Finance accounts (opening null, no `currencyLocked`); no X amount, name or id on any other surface; after ticking themselves X appears.
- View-only member: 403 on all 21 finance write routes and `/finance/import`; pages carry `canEdit: false`.
- Cache: every finance GET surface including panels, the preview JSON (200 and 422), 403 and 404 responses carry `no-store`.
- Service worker: source has no runtime caching or finance URL; built precache contains none; the precache checker rejects finance URLs.
- Staging audit: fails for finance accounts/transactions/memberships of a real user and for rows with a deleted creator; passes for synthetic users.

## Deviations from Plan

**1. [Rule 1 - Bug] Transaction day headings skipped a heading level**
- Found during: axe sweep (heading-order on the full Transactions page: h1 then h3).
- Fix: `TransactionList.vue` day headings h3 to h2 (UI-SPEC line 195 said h3; the page has no h2 in between, so h2 is the valid level). `Transactions.test.js` selectors updated.
- Commit: 373216f

**2. Files outside the plan's list touched (user-requested)**: `FinanceWriteParityTest.php` (minimum counts) and `FinanceMutationScanTest.php` (per-class planted write), as requested in the task brief.

**3. Pest `toContain` message pitfall**: the new tests avoid passing a message as a second needle (same bug 05-19 fixed in the mutation scan). The pre-existing `FinancePrivacyTest` has `not->toContain($needle, "message")` which still passes correctly (an unmatched extra needle) but its message is not shown; left unchanged.

## Carry-over notes

1. `toast_kept` (05-18): `HandleInertiaRequests` shares only `flash.status`, `flash.toast` and `flash.data`; AppShell watches `flash.toast` only. Showing `toast_kept` would need a new shared prop plus an AppShell watcher plus a second toast, more than a one-line change, so it was not changed. The kept count is already shown on the batch "Done" step (`ImportDoneStep.vue` line with `imp_undone_kept`), so no information is lost; the session key is set but unused by the UI. Left for a later cleanup (either drop the session key or share it).
2. Mutation scan sanity confirmed for each query class (see above).
3. Overview/Home Finance card falls back to the coming-soon panel when no `financeCard` prop is sent. Acceptable: Home only omits the card when the viewer has no visible recurring items, by UI-SPEC design.

## Notes

- Import "columns" step is swept in the existing `ImportMapping.test.js` (it needs a chosen file); the new sweep covers the file step, review and done steps.
- Verification (local, compose project fh0520, torn down): full Pest 1335 passed, Vitest 1509 passed, `npm run build` and SW precache check OK, Pint clean on finance and console code.
- Requirements FIN-01..FIN-08 not marked complete: plan 05-21 (live staging/production and device checks) is still pending.

## Known Stubs
None.

## Threat Flags
None. T-05-78..82 mitigated and tested.

## Self-Check: PASSED
