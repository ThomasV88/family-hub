---
phase: 05-finance
verified: 2026-11-13T22:30:00Z
status: human_needed
score: 5/5 roadmap success criteria verified in automated tests; live checks deferred (plan 05-21)
overrides_applied: 0
human_verification:
  - test: "Staging release on rebuilt images (bcmath, iconv, intl, migrations, prune schedule, nginx import limit, staging:audit)"
    expected: "Finance runs on staging; staging:audit passes; 2 MB import limit enforced by nginx"
    why_human: "Live server; plan 05-21 deferred by owner (DEFERRED.md)"
  - test: "Device privacy and exact amount display on iPad Safari, phone, desktop"
    expected: "Hidden accounts never visible; amounts display exactly (Intl decimal-string formatting)"
    why_human: "Physical devices"
  - test: "Owner's anonymised SK/PL bank CSVs through a generic profile"
    expected: "Real layouts import with correct mapping, duplicates flagged, undo works"
    why_human: "Real bank files not available to CI; only generic profiles ship"
  - test: "Native SK/PL wording review of finance.* copy"
    expected: "Natural wording"
    why_human: "Human language review"
  - test: "Production release and real-member visibility confirmation"
    expected: "Real members see only permitted accounts"
    why_human: "Live production"
---

# Phase 5: Finance Verification Report

**Goal:** The family can see where money goes, with exact amounts, recurring expenses tracked against reality, budgets, charts and bank statement import.
**Re-verification:** No. Initial verification.

## Local runs (own execution)

| Check | Result |
|---|---|
| Pest in container | 1335 passed (15127 assertions) |
| Vitest | 86 files, 1509 tests passed |
| npm run build | OK (SW precache check OK) |
| scripts/check-compose.sh | CHECK-COMPOSE OK |
| scripts/check-nginx-template.sh | CHECK-NGINX OK |

## Success criteria

| # | Criterion | Status | Evidence |
|---|---|---|---|
| 1 | Record income/expense, exact amounts | VERIFIED (automated) | TransactionTest, MoneyParserTest, NoFloatScanTest (no-float scan), SQL-side bigint aggregation in OverviewQuery, integer-day arithmetic. Device display of Intl formatting is human. |
| 2 | Admin picks account visibility; others never see | VERIFIED (automated) | FinanceLeakSuiteTest (route enumeration, differential/canary/query-log, hidden-vs-random write parity, planted mutant routes), FinanceWriteParityTest, TransferWriteParityTest (hidden-counterpart edit/delete byte-identical), FinanceMutationScanTest (scanner self-test and planted unscoped write in every query class), AdminFinancePrivacyTest, FinanceCacheHeadersTest, StagingAuditFinanceTest. |
| 3 | Recurring, upcoming bills, expected vs actual | VERIFIED (automated) | RecurringTest, BillStatusTest, StatusResolverTest, MatcherTest, AutoMatchTest, MatchingFlowTest. |
| 4 | Budgets, progress, charts | VERIFIED (automated) | BudgetTest, OverviewTest, Vitest component tests incl. axe sweep, SVG charts with table alternatives. |
| 5 | CSV import: Windows-1250, saved profile, review, duplicates, undo | VERIFIED (automated, synthetic fixtures only) | Fixtures statement-sk-windows1250 / pl-iso88592 / utf8-bom; ImportPreviewTest, ImportStageTest (asserts no files persisted on local disk), ImportCommitTest, ImportUndoTest (keep-and-detach, re-import, scoped UPDATE/DELETE), PruneFinanceImportsTest. Real bank layouts unverified. |

## Anti-patterns / notes

- No gaps found. Known non-blocking notes from DEFERRED.md: `toast_kept` flashed but not shown (Done step shows count); Home finance card falls back to coming-soon panel when no prop sent; only generic CSV profiles ship.
- REQUIREMENTS.md FIN-01..08 intentionally remain unticked until 05-21.

## Status rationale

All automated truths pass, but live staging/production, real devices, real bank CSVs and native wording review were not run (plan 05-21 deferred), so status is human_needed, not passed.
