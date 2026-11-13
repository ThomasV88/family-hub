---
phase: 05
slug: finance
status: approved
nyquist_compliant: true
wave_0_complete: true
created: 2026-11-13
---

# Phase 05 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (PHP, in app container) + Vitest (frontend) |
| **Config file** | phpunit.xml, vitest.config.js |
| **Quick run command** | `docker compose run --rm app ./vendor/bin/pest --compact` |
| **Full suite command** | `docker compose run --rm app ./vendor/bin/pest && npx vitest run` |
| **Estimated runtime** | targeted per-task commands under 15 s; full Pest ~95 s; Vitest ~60 s |

---

## Sampling Rate

- **After every task commit:** Run `docker compose run --rm app ./vendor/bin/pest --compact`
- **After every plan wave:** Run `docker compose run --rm app ./vendor/bin/pest && npx vitest run`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds for a per-task command (full suites run once per plan)

---

## Per-Task Verification Map

Pre-filled during planning (plan revision) for every task of 05-01 .. 05-21. Commands run from the repo root. Status is updated by 05-20 Task 3 from the plans' SUMMARIES; `nyquist_compliant` was set true by that sign-off (2026-11-13); 05-21 rows stay pending until the deferred live plan runs. Threat refs list up to three IDs of the plan's register (… = more in the plan).

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior / Task | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|------------------------|-----------|-------------------|-------------|--------|
| 05-01-01 | 01 | 1 | FIN-01,FIN-02,FIN-03,FIN-04,FIN-05,FIN-06,FIN-07,FIN-08 | T-05-01, T-05-02, T-05-03 | finance.* copy in lang/{en,sk,pl}.json with a parity test | vitest | `npx vitest run tests/js/i18n-phase5.test.js tests/js/i18n-phase4.test.js tests/js/i18n-phase3.test.js tests/js/i18n-phase2.test.js tests/js/i18n.test.js` | created in task (test first) | ✅ green |
| 05-01-02 | 01 | 1 | FIN-01,FIN-02,FIN-03,FIN-04,FIN-05,FIN-06,FIN-07,FIN-08 | T-05-01, T-05-02, T-05-03 | Full suite once | full suite | `npx vitest run && npm run build && docker compose -p fh0501 run --rm app php -d memory_limit=1G ./vendor/bin/pest && docker compose -p fh0501 down -v` | ✅ (prior task) | ✅ green |
| 05-02-01 | 02 | 1 | FIN-01,FIN-02 | T-05-04, T-05-05, T-05-06 … | Currency, MoneyParser, MoneyFormat, TextNormalizer (PHP, TDD) | unit (Pest) | `docker compose -p fh0502 run --rm app ./vendor/bin/pest tests/Unit/Finance/MoneyParserTest.php tests/Unit/Finance/TextNormalizerTest.php` | created in task (test first) | ✅ green |
| 05-02-02 | 02 | 1 | FIN-01,FIN-02 | T-05-04, T-05-05, T-05-06 … | money.js, MoneyAmount.vue (Vitest, TDD) | vitest + axe | `npx vitest run tests/js/finance/money.test.js tests/js/finance/MoneyAmount.test.js` | created in task (test first) | ✅ green |
| 05-02-03 | 02 | 1 | FIN-01,FIN-02 | T-05-04, T-05-05, T-05-06 … | No-float scan test, then the full suite | unit (Pest) | `docker compose -p fh0502 run --rm app ./vendor/bin/pest tests/Unit/Finance && docker compose -p fh0502 run --rm app ./vendor/bin/pint --test app/Finance tests/Unit/Finance && docker compose -p fh0502 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0502 down -v` | created in task (test first) | ✅ green |
| 05-03-01 | 03 | 1 | FIN-04,FIN-05 | T-05-08, T-05-09, T-05-10 | RecurrenceEngine::between with month-end, leap and DST tests | feature (Pest) | `docker compose -p fh0503 run --rm app ./vendor/bin/pest tests/Unit/Recurrence tests/Feature/Todo/CompletionTest.php` | created in task (test first) | ✅ green |
| 05-03-02 | 03 | 1 | FIN-04,FIN-05 | T-05-08, T-05-09, T-05-10 | Matcher and StatusResolver (pure, integer-only) | unit (Pest) | `docker compose -p fh0503 run --rm app ./vendor/bin/pest tests/Unit/Finance/MatcherTest.php tests/Unit/Finance/StatusResolverTest.php` | created in task (test first) | ✅ green |
| 05-03-03 | 03 | 1 | FIN-04,FIN-05 | T-05-08, T-05-09, T-05-10 | Full suite once | full suite | `docker compose -p fh0503 run --rm app ./vendor/bin/pint --test app/Recurrence app/Finance/Recurring tests/Unit/Recurrence/BetweenTest.php tests/Unit/Finance && docker compose -p fh0503 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0503 down -v` | ✅ (prior task) | ✅ green |
| 05-04-01 | 04 | 2 | FIN-01,FIN-02,FIN-03 | T-05-11, T-05-12, T-05-13 … | Core schema, models, factories with DB-constraint tests | feature (Pest) | `docker compose -p fh0504 run --rm app ./vendor/bin/pest tests/Feature/Finance/FinanceSchemaTest.php` | created in task (test first) | ✅ green |
| 05-04-02 | 04 | 2 | FIN-01,FIN-02,FIN-03 | T-05-11, T-05-12, T-05-13 … | Viewer, Visibility, AccountQuery with arch, file-scan and mutation-scope rules | feature (Pest) | `docker compose -p fh0504 run --rm app ./vendor/bin/pest tests/Feature/Finance/VisibilityTest.php tests/Unit/Arch/FinancePrivacyArchTest.php tests/Unit/Finance/FinanceTableScanTest.php tests/Unit/Finance/FinanceMutationScanTest.php` | created in task (test first) | ✅ green |
| 05-04-03 | 04 | 2 | FIN-01,FIN-02,FIN-03 | T-05-11, T-05-12, T-05-13 … | Leak net (FinancePrivacy harness, differential/canary/query-log and write-parity tests), then full suite | feature (Pest) | `docker compose -p fh0504 run --rm app ./vendor/bin/pest tests/Feature/Finance tests/Unit/Arch tests/Unit/Finance && docker compose -p fh0504 run --rm app ./vendor/bin/pint --test app/Finance app/Models database/migrations/2026_11_16_100000_create_finance_core_tables.php database/factories tests/Feature/Finance tests/Support/FinancePrivacy.php tests/Support/FinancePrivacy tests/Unit && docker compose -p fh0504 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0504 down -v` | created in task (test first) | ✅ green |
| 05-05-01 | 05 | 2 | FIN-08 | T-05-16, T-05-17, T-05-18 … | Config, fixture generator, Decoder, CsvReader, DateParser | unit (Pest) | `docker compose -p fh0505 run --rm app ./vendor/bin/pest tests/Unit/Finance/Import/DecoderTest.php tests/Unit/Finance/Import/CsvReaderTest.php tests/Unit/Finance/Import/DateParserTest.php` | created in task (test first) | ✅ green |
| 05-05-02 | 05 | 2 | FIN-08 | T-05-16, T-05-17, T-05-18 … | Full suite once | full suite | `docker compose -p fh0505 run --rm app ./vendor/bin/pint --test config/finance.php app/Finance/Import tests/Support/CsvFixtures.php tests/Unit/Finance/Import && docker compose -p fh0505 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0505 down -v` | ✅ (prior task) | ✅ green |
| 05-06-01 | 06 | 3 | FIN-08 | T-05-20, T-05-17b, T-05-19b … | ProfileSpec, RowNormalizer, ImportHasher and the 20+ variant pipeline test | unit (Pest) | `docker compose -p fh0506 run --rm app ./vendor/bin/pest tests/Unit/Finance/Import` | created in task (test first) | ✅ green |
| 05-06-02 | 06 | 3 | FIN-08 | T-05-20, T-05-17b, T-05-19b … | Full suite once | full suite | `docker compose -p fh0506 run --rm app ./vendor/bin/pint --test app/Finance/Import tests/Unit/Finance/Import && docker compose -p fh0506 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0506 down -v` | ✅ (prior task) | ✅ green |
| 05-07-01 | 07 | 3 | FIN-03 | T-05-21, T-05-22, T-05-23 … | Admin account backend with audit event and matrix rows | feature (Pest) | `docker compose -p fh0507 run --rm app ./vendor/bin/pest tests/Feature/Finance/AccountAdminTest.php tests/Feature/Access/EndpointMatrixTest.php tests/Feature/Audit tests/Feature/Finance/FinanceWriteParityTest.php` | created in task (test first) | ✅ green |
| 05-07-02 | 07 | 3 | FIN-03 | T-05-21, T-05-22, T-05-23 … | Settings > Finance accounts page and AccountPanel | vitest + axe | `npx vitest run tests/js/settings/FinanceAccountsPage.test.js tests/js/finance/AccountPanel.test.js tests/js/settings/AuditPage.test.js tests/js/settings/SettingsShell.test.js` | created in task (test first) | ✅ green |
| 05-07-03 | 07 | 3 | FIN-03 | T-05-21, T-05-22, T-05-23 … | Full suite once | full suite | `docker compose -p fh0507 run --rm app ./vendor/bin/pint --test app/Http/Controllers/Settings/FinanceAccountsController.php app/Http/Requests/Settings/FinanceAccountRequest.php app/Finance app/Audit app/Support routes/web/settings-finance-accounts.php tests/Support/EndpointMatrix/settings-finance-accounts.php tests/Feature/Finance/AccountAdminTest.php && docker compose -p fh0507 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0507 down -v` | ✅ (prior task) | ✅ green |
| 05-08-01 | 08 | 3 | FIN-01,FIN-02,FIN-03 | T-05-26, T-05-27, T-05-28 … | FinancePage, CategoryQuery, TransactionQuery, TransactionPresenter, AccountsController and the read routes | feature (Pest) | `docker compose -p fh0508 run --rm app ./vendor/bin/pest tests/Feature/Finance/AccountsPageTest.php tests/Feature/Finance/TransferPrivacyTest.php` | created in task (test first) | ✅ green |
| 05-08-02 | 08 | 3 | FIN-01,FIN-02,FIN-03 | T-05-26, T-05-27, T-05-28 … | TransactionRequest, TransactionWriter, write routes, matrix and leak-net area file | feature (Pest) | `docker compose -p fh0508 run --rm app ./vendor/bin/pest tests/Feature/Finance tests/Feature/Access/EndpointMatrixTest.php tests/Unit/Finance/FinanceMutationScanTest.php` | created in task (test first) | ✅ green |
| 05-08-03 | 08 | 3 | FIN-01,FIN-02,FIN-03 | T-05-26, T-05-27, T-05-28 … | Full suite once | full suite | `docker compose -p fh0508 run --rm app ./vendor/bin/pint --test app/Finance app/Http/Controllers/Finance app/Http/Requests/Finance routes/web/finance-transactions.php routes/web/finance-accounts.php tests/Feature/Finance tests/Support/EndpointMatrix tests/Support/FinancePrivacy tests/Feature/Access/EndpointMatrixTest.php && docker compose -p fh0508 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0508 down -v` | ✅ (prior task) | ✅ green |
| 05-09-01 | 09 | 3 | FIN-01,FIN-02,FIN-03 | T-05-33, T-05-34, T-05-35 … | Shell, toolbar, chips, month switcher, helpers, Accounts page | vitest + axe | `npx vitest run tests/js/finance/FinanceShell.test.js tests/js/finance/Accounts.test.js` | created in task (test first) | ✅ green |
| 05-09-02 | 09 | 3 | FIN-01,FIN-02,FIN-03 | T-05-33, T-05-34, T-05-35 … | Transactions tab, filters, rows, form and panel | vitest + axe | `npx vitest run tests/js/finance/Transactions.test.js tests/js/finance/TransactionPanel.test.js` | created in task (test first) | ✅ green |
| 05-09-03 | 09 | 3 | FIN-01,FIN-02,FIN-03 | T-05-33, T-05-34, T-05-35 … | Full suite once | full suite | `npx vitest run && npm run build && docker compose -p fh0509 run --rm app php -d memory_limit=1G ./vendor/bin/pest && docker compose -p fh0509 down -v` | ✅ (prior task) | ✅ green |
| 05-10-01 | 10 | 4 | FIN-04,FIN-05 | T-05-37, T-05-38, T-05-39 … | Recurring schema, models, factories, OccurrenceWindow, RecurringQuery with derived statuses | feature (Pest) | `docker compose -p fh0510 run --rm app ./vendor/bin/pest tests/Feature/Finance/BillStatusTest.php` | created in task (test first) | ✅ green |
| 05-10-02 | 10 | 4 | FIN-04,FIN-05 | T-05-37, T-05-38, T-05-39 … | Recurring CRUD routes, request, writer, page props, matrix and area file | feature (Pest) | `docker compose -p fh0510 run --rm app ./vendor/bin/pest tests/Feature/Finance tests/Feature/Access/EndpointMatrixTest.php tests/Unit/Arch` | created in task (test first) | ✅ green |
| 05-10-03 | 10 | 4 | FIN-04,FIN-05 | T-05-37, T-05-38, T-05-39 … | Full suite once | full suite | `docker compose -p fh0510 run --rm app ./vendor/bin/pint --test database/migrations/2026_11_16_110000_create_finance_recurring_tables.php app/Models app/Finance app/Http database/factories routes/web/finance-recurring.php tests/Feature/Finance tests/Support && docker compose -p fh0510 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0510 down -v` | ✅ (prior task) | ✅ green |
| 05-11-01 | 11 | 4 | FIN-08 | T-05-42, T-05-43, T-05-44 … | Import schema, models, factories, ImportQuery, ImportStager | feature (Pest) | `docker compose -p fh0511 run --rm app ./vendor/bin/pest tests/Feature/Finance/ImportStageTest.php` | created in task (test first) | ✅ green |
| 05-11-02 | 11 | 4 | FIN-08 | T-05-42, T-05-43, T-05-44 … | Import routes (index, preview, store, show), requests, nginx limit, matrix and area file | feature (Pest) | `docker compose -p fh0511 run --rm app ./vendor/bin/pest tests/Feature/Finance tests/Feature/Access/EndpointMatrixTest.php tests/Unit/Arch` | created in task (test first) | ✅ green |
| 05-11-03 | 11 | 4 | FIN-08 | T-05-42, T-05-43, T-05-44 … | Full suite once | full suite | `docker compose -p fh0511 run --rm app ./vendor/bin/pint --test database/migrations/2026_11_16_120000_create_finance_import_tables.php app/Models app/Finance app/Http database/factories routes/web/finance-import.php tests/Feature/Finance tests/Support && docker compose -p fh0511 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0511 down -v` | ✅ (prior task) | ✅ green |
| 05-12-01 | 12 | 4 | FIN-06 | T-05-58, T-05-59, T-05-60 … | Budget schema, DisplayKind, BudgetQuery, writer, routes, matrix and leak-net area file | feature (Pest) | `docker compose -p fh0512 run --rm app ./vendor/bin/pest tests/Feature/Finance/BudgetTest.php tests/Feature/Finance/FinancePrivacyTest.php tests/Feature/Finance/FinanceWriteParityTest.php tests/Feature/Access/EndpointMatrixTest.php tests/Unit/Arch tests/Unit/Finance` | created in task (test first) | ✅ green |
| 05-12-02 | 12 | 4 | FIN-06 | T-05-58, T-05-59, T-05-60 … | Full suite once | full suite | `docker compose -p fh0512 run --rm app ./vendor/bin/pint --test database/migrations/2026_11_16_130000_create_finance_budgets_table.php app/Models/FinanceBudget.php database/factories/FinanceBudgetFactory.php app/Finance/Queries/DisplayKind.php app/Finance/Queries/BudgetQuery.php app/Finance/Budgets app/Http/Controllers/Finance/BudgetController.php app/Http/Requests/Finance/BudgetRequest.php routes/web/finance-budgets.php tests/Support/EndpointMatrix/finance-budgets.php tests/Support/FinancePrivacy/budgets.php tests/Feature/Finance/BudgetTest.php && docker compose -p fh0512 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0512 down -v` | ✅ (prior task) | ✅ green |
| 05-13-01 | 13 | 5 | FIN-05 | T-05-48, T-05-49, T-05-50 … | MatchQuery, RecurringMatchService and automatic matching hooks | feature (Pest) | `docker compose -p fh0513 run --rm app ./vendor/bin/pest tests/Feature/Finance/AutoMatchTest.php tests/Feature/Finance/TransactionTest.php tests/Feature/Finance/RecurringTest.php tests/Feature/Finance/BillStatusTest.php` | created in task (test first) | ✅ green |
| 05-13-02 | 13 | 5 | FIN-05 | T-05-48, T-05-49, T-05-50 … | Bill routes (record, match, unmatch), candidates prop, match info on transactions | feature (Pest) | `docker compose -p fh0513 run --rm app ./vendor/bin/pest tests/Feature/Finance tests/Feature/Access/EndpointMatrixTest.php tests/Unit/Arch` | created in task (test first) | ✅ green |
| 05-13-03 | 13 | 5 | FIN-05 | T-05-48, T-05-49, T-05-50 … | Full suite once | full suite | `docker compose -p fh0513 run --rm app ./vendor/bin/pint --test app/Finance app/Http/Controllers/Finance app/Http/Requests/Finance routes/web/finance-bills.php tests/Feature/Finance tests/Support && docker compose -p fh0513 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0513 down -v` | ✅ (prior task) | ✅ green |
| 05-14-01 | 14 | 5 | FIN-04,FIN-05 | T-05-53, T-05-54, T-05-55 | StatusTag, StillToPayCard, PaymentCalendar, RecurringList, DayPanel and the Recurring page | vitest + axe | `npx vitest run tests/js/finance/Recurring.test.js tests/js/finance/PaymentCalendar.test.js` | created in task (test first) | ✅ green |
| 05-14-02 | 14 | 5 | FIN-04,FIN-05 | T-05-53, T-05-54, T-05-55 | BillPanel and RecurringPanel | vitest + axe | `npx vitest run tests/js/finance/BillPanel.test.js tests/js/finance/RecurringPanel.test.js tests/js/finance/Recurring.test.js` | created in task (test first) | ✅ green |
| 05-14-03 | 14 | 5 | FIN-04,FIN-05 | T-05-53, T-05-54, T-05-55 | Full suite once | full suite | `npx vitest run && npm run build && docker compose -p fh0514 run --rm app php -d memory_limit=1G ./vendor/bin/pest && docker compose -p fh0514 down -v` | ✅ (prior task) | ✅ green |
| 05-15-01 | 15 | 5 | FIN-07,FIN-06,FIN-05 | T-05-56, T-05-57, T-05-58b … | OverviewQuery, Overview route, HomeSummary and Home card prop | feature (Pest) | `docker compose -p fh0515 run --rm app ./vendor/bin/pest tests/Feature/Finance tests/Feature/HomePageTest.php tests/Feature/Access/EndpointMatrixTest.php tests/Unit/Arch` | created in task (test first) | ✅ green |
| 05-15-02 | 15 | 5 | FIN-07,FIN-06,FIN-05 | T-05-56, T-05-57, T-05-58b … | Full suite once | full suite | `docker compose -p fh0515 run --rm app ./vendor/bin/pint --test app/Finance/Queries/OverviewQuery.php app/Finance/HomeSummary.php app/Http/Controllers/Finance/OverviewController.php app/Http/Controllers/HomeController.php routes/web/finance.php tests/Support/EndpointMatrix/finance.php tests/Support/FinancePrivacy/overview.php tests/Feature/Finance/OverviewTest.php tests/Feature/Finance/HomeFinanceCardTest.php && docker compose -p fh0515 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0515 down -v` | ✅ (prior task) | ✅ green |
| 05-16-01 | 16 | 5 | FIN-08 | T-05-61, T-05-62, T-05-63 … | Import page with File and Columns steps, history, preview API | vitest + axe | `npx vitest run tests/js/finance/ImportUpload.test.js tests/js/finance/ImportMapping.test.js` | created in task (test first) | ✅ green |
| 05-16-02 | 16 | 5 | FIN-08 | T-05-61, T-05-62, T-05-63 … | ImportBatch page with Review and Done steps | vitest + axe | `npx vitest run tests/js/finance/ImportReview.test.js` | created in task (test first) | ✅ green |
| 05-16-03 | 16 | 5 | FIN-08 | T-05-61, T-05-62, T-05-63 … | Full suite once | full suite | `npx vitest run && npm run build && docker compose -p fh0516 run --rm app php -d memory_limit=1G ./vendor/bin/pest && docker compose -p fh0516 down -v` | ✅ (prior task) | ✅ green |
| 05-17-01 | 17 | 6 | FIN-06,FIN-07,FIN-05 | T-05-65, T-05-66, T-05-67 … | niceTicks and the two SVG charts with table alternatives | vitest + axe | `npx vitest run tests/js/finance/niceTicks.test.js tests/js/finance/charts.test.js` | created in task (test first) | ✅ green |
| 05-17-02 | 17 | 6 | FIN-06,FIN-07,FIN-05 | T-05-65, T-05-66, T-05-67 … | Overview page, summary cards, budgets and budget panel, Home card, nav entry | vitest + axe | `npx vitest run tests/js/finance/Overview.test.js tests/js/finance/BudgetPanel.test.js tests/js/finance/HomeStillToPayCard.test.js tests/js/shell tests/js/Home.test.js` | created in task (test first) | ✅ green |
| 05-17-03 | 17 | 6 | FIN-06,FIN-07,FIN-05 | T-05-65, T-05-66, T-05-67 … | Full suite once | full suite | `npx vitest run && npm run build && docker compose -p fh0517 run --rm app php -d memory_limit=1G ./vendor/bin/pest && docker compose -p fh0517 down -v` | ✅ (prior task) | ✅ green |
| 05-18-01 | 18 | 6 | FIN-08,FIN-05 | T-05-69, T-05-70, T-05-71 … | Commit, reconcile, match preview and discard | feature (Pest) | `docker compose -p fh0518 run --rm app ./vendor/bin/pest tests/Feature/Finance/ImportCommitTest.php tests/Feature/Finance/ImportStageTest.php tests/Feature/Finance/FinancePrivacyTest.php tests/Feature/Finance/FinanceWriteParityTest.php tests/Feature/Access/EndpointMatrixTest.php` | created in task (test first) | ✅ green |
| 05-18-02 | 18 | 6 | FIN-08,FIN-05 | T-05-69, T-05-70, T-05-71 … | Undo and daily prune | feature (Pest) | `docker compose -p fh0518 run --rm app ./vendor/bin/pest tests/Feature/Finance tests/Feature/Access/EndpointMatrixTest.php tests/Unit/Arch` | created in task (test first) | ✅ green |
| 05-18-03 | 18 | 6 | FIN-08,FIN-05 | T-05-69, T-05-70, T-05-71 … | Full suite once | full suite | `docker compose -p fh0518 run --rm app ./vendor/bin/pint --test app/Finance app/Http/Controllers/Finance app/Http/Requests/Finance app/Console/Commands/PruneFinanceImports.php routes/web/finance-import.php tests/Feature/Finance tests/Support && docker compose -p fh0518 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0518 down -v` | ✅ (prior task) | ✅ green |
| 05-19-01 | 19 | 6 | FIN-01 | T-05-74, T-05-75, T-05-76 … | Category backend (routes, request, writer, reassign, matrix) | feature (Pest) | `docker compose -p fh0519 run --rm app ./vendor/bin/pest tests/Feature/Finance/CategoryTest.php tests/Feature/Finance/FinancePrivacyTest.php tests/Feature/Access/EndpointMatrixTest.php tests/Feature/Finance/FinanceWriteParityTest.php tests/Unit/Finance/FinanceMutationScanTest.php` | created in task (test first) | ✅ green |
| 05-19-02 | 19 | 6 | FIN-01 | T-05-74, T-05-75, T-05-76 … | Categories page and CategoryPanel | vitest + axe | `npx vitest run tests/js/finance/Categories.test.js` | created in task (test first) | ✅ green |
| 05-19-03 | 19 | 6 | FIN-01 | T-05-74, T-05-75, T-05-76 … | Full suite once | full suite | `docker compose -p fh0519 run --rm app ./vendor/bin/pint --test app/Finance app/Http/Controllers/Finance/CategoryController.php app/Http/Requests/Finance/CategoryRequest.php routes/web/finance-categories.php tests/Feature/Finance/CategoryTest.php tests/Support/EndpointMatrix/finance-categories.php tests/Support/FinancePrivacy/categories.php && docker compose -p fh0519 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0519 down -v` | ✅ (prior task) | ✅ green |
| 05-20-01 | 20 | 7 | FIN-03,FIN-01,FIN-02,FIN-04,FIN-05,FIN-06,FIN-07,FIN-08 | T-05-78, T-05-79, T-05-80 … | Leak-suite completeness, mutation sanity, admin privacy, cache headers | feature (Pest) | `docker compose -p fh0520 run --rm app ./vendor/bin/pest tests/Feature/Finance/FinanceLeakSuiteTest.php tests/Feature/Finance/AdminFinancePrivacyTest.php tests/Feature/Finance/FinanceCacheHeadersTest.php` | created in task (test first) | ✅ green |
| 05-20-02 | 20 | 7 | FIN-03,FIN-01,FIN-02,FIN-04,FIN-05,FIN-06,FIN-07,FIN-08 | T-05-78, T-05-79, T-05-80 … | Staging audit rules, a11y sweep, service-worker check | feature (Pest) | `docker compose -p fh0520 run --rm app ./vendor/bin/pest tests/Feature/Finance/StagingAuditFinanceTest.php tests/Unit/Finance/FinanceTableScanTest.php tests/Feature/StagingSeedTest.php && npx vitest run tests/js/finance/a11y-finance.test.js tests/js/finance/sw-finance.test.js` | created in task (test first) | ✅ green |
| 05-20-03 | 20 | 7 | FIN-03,FIN-01,FIN-02,FIN-04,FIN-05,FIN-06,FIN-07,FIN-08 | T-05-78, T-05-79, T-05-80 … | Validation map and the phase-wide full run | full suite | `docker compose -p fh0520 run --rm app ./vendor/bin/pint --test app/Finance app/Http/Controllers/Finance app/Http/Requests/Finance tests/Feature/Finance tests/Unit/Finance && docker compose -p fh0520 run --rm app php -d memory_limit=1G ./vendor/bin/pest && npx vitest run && npm run build && docker compose -p fh0520 down -v && grep -c "nyquist_compliant: true" .planning/phases/05-finance/05-VALIDATION.md` | ✅ (prior task) | ✅ green |
| 05-21-01 | 21 | 8 | FIN-01,FIN-03,FIN-05,FIN-07,FIN-08 | T-05-83, T-05-84, T-05-85 … | Image rebuild and staging release with server checks | manual (deferred) | `manual: live server commands run with the owner (ssh my-server-agent; expected bcmath/iconv/intl present, migrations ran, schedule listed, nginx limit active, staging:audit OK)` | n/a | ⬜ pending |
| 05-21-02 | 21 | 8 | FIN-01,FIN-03,FIN-05,FIN-07,FIN-08 | T-05-83, T-05-84, T-05-85 … | Device privacy and display checks, real bank CSVs, wording review | manual (deferred) | `manual: physical-device verification, owner's real bank files and native wording review (not automatable)` | n/a | ⬜ pending |
| 05-21-03 | 21 | 8 | FIN-01,FIN-03,FIN-05,FIN-07,FIN-08 | T-05-83, T-05-84, T-05-85 … | Production release | manual (deferred) | `manual: live production release with the owner` | n/a | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

Key cross-cutting guards (where each privacy property is proven):

- No float money: tests/Unit/Finance/NoFloatScanTest.php (05-02), re-run by every full-suite task.
- Finance SQL only in App\Finance\Queries: FinancePrivacyArchTest + FinanceTableScanTest (05-04).
- No write reaches a hidden account: FinanceMutationScanTest (static) and FinancePrivacy::assertScopedMutations in FinanceWriteParityTest (runtime) (05-04); TransferWriteParityTest (05-08); AutoMatchTest hidden-counterpart regression (05-13).
- Account/kind immutability and same-account matches: TransactionTest (05-08), RecurringTest + BillStatusTest composite-FK cases (05-10).
- Hidden data invisible on every surface: FinancePrivacyTest differential/canary/query-log over all area files (05-04 onward), FinanceLeakSuiteTest completeness + mutation sanity (05-20).

---

## Wave 0 Requirements

Existing infrastructure (Pest 4, Vitest, axe helpers, EndpointMatrix harness) plus the guard and fixture files created test-first in 05-02 (NoFloatScanTest), 05-04 (FinancePrivacy harness, arch, table-scan and mutation-scan tests) and 05-05 (CsvFixtures generator, committed encoding fixtures) cover all phase requirements. Every other test file is created by its own task before the implementation (TDD), so no task depends on a missing test file.

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Staging release on rebuilt images (bcmath, iconv, intl, migrations, prune schedule, nginx import limit, staging:audit) | FIN-08, FIN-03 | live server with the owner | 05-21 Task 1 |
| Device privacy and exact display (iPad Safari Intl decimal-string formatting, phone, desktop) | FIN-01, FIN-02, FIN-03 | physical devices | 05-21 Task 2 steps 1-4 |
| Owner's anonymised SK/PL bank CSVs through a generic profile | FIN-08 | real bank files not available to CI | 05-21 Task 2 step 5 |
| Native SK/PL wording review | all | human language review | 05-21 Task 2 step 6 |
| Production release and real-member visibility confirmation | FIN-03 | live production | 05-21 Task 3 |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency within the 15 s per-task bound
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved 2026-11-13 for plans 05-01..05-20 (full Pest 1335, Vitest 1509, build and Pint green); 05-21 live checks pending
