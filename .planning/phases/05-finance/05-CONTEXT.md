# Phase 5: Finance - Context

**Gathered:** 2026-11-13
**Status:** Ready for planning

<domain>
## Phase Boundary

The family sees where money goes: exact amounts, recurring expenses tracked against reality, monthly budgets, charts, and bank statement CSV import. Finance data is private by default (Phase 2 finance module grant defaults to none) with per-account visibility chosen by the admin.
</domain>

<decisions>
## Implementation Decisions

### Accounts, transactions, access
- Named accounts (bank, cash, savings, card), each with a currency and an opening balance; every transaction belongs to one account.
- Admin chooses per account which members see it; hidden accounts, their transactions, balances and charts never appear for others. This sits on top of the Phase 2 finance module grant (none by default). Enforcement through the Phase 2 policy layer plus record-level query scopes, endpoint-matrix rows and leak tests; access and changes are audited (never content).
- Money is stored as integer minor units plus ISO currency code on every amount; no floats anywhere; one currency per account; no automatic conversion in v1.
- Family-wide editable category list with a default SK/PL/EN set; transaction kinds: income, expense, transfer between accounts.

### Recurring expenses and budgets
- Recurring expenses (rent, savings, kindergarten, energy, ...) with amount, schedule (reusing the Phase 3 RecurrenceEngine incl. month-end clamp), category and account.
- Expected-vs-actual: each occurrence is matched against recorded or imported transactions (account, amount tolerance, date window, text hint); statuses Upcoming / Paid / Overdue / Paid differently; manual match and unmatch.
- Upcoming bills view: next 30 days, overdue first, plus a monthly payment calendar (sketch 006).
- Monthly budget per category with progress and over-budget warning; budgets roll to the next month.

### Charts and CSV import
- Charts: spending by category (month) and balance over months, built as small SVG components (no heavy chart library), light/dark accessible, with a data-table alternative.
- CSV import: upload, pick or create a mapping profile (delimiter, encoding Windows-1250/UTF-8, decimal comma, date format, columns), preview and review step, duplicate detection (date + amount + normalized description hash), commit, undo of the whole batch.
- Privacy: the uploaded file is parsed in memory/temp and never kept after import; only parsed rows with batch id are stored.
- Bank formats: generic saved profiles per bank; ship starter profiles only if verified with real sample files, otherwise generic with user mapping.

### Claude's Discretion
Table/class names, matching heuristics details, chart visual details following sketch 006 and the Phase 01.1 design system, test organisation.
</decisions>

<code_context>
## Existing Code Insights

### Reusable Assets
- Phase 2 policy layer (AccessGate, access middleware, endpoint matrix harness, audit logger which already audits finance_read/write and denied access), Phase 3 RecurrenceEngine/RuleBuilder/ReminderTimeCalculator and notification outbox, Phase 4 patterns for separate-namespace privacy, arch tests, canary/differential tests; Phase 01.1 Ui* components incl. UiTable; Money helper from Phase 4 (Wishlist) if suitable.

### Established Patterns
- Inertia pages, per-feature route files and EndpointMatrix files, lang keys added up front in one plan (vue-i18n pipe plurals), Vitest + axe sweeps in SK/PL/EN and light/dark, no-store dynamic responses, precache-only service worker (never cache finance responses).

### Integration Points
- modules.js (finance nav), Home dashboard card (restricted by access), settings, audit page, Phase 6 will add re-auth and sensitive-data hardening on top.
</code_context>

<specifics>
## Specific Ideas

Sketch 006: Finance tabs Overview / Transactions / Recurring, payment calendar, expected vs actual, import with duplicate matching.
</specifics>

<deferred>
## Deferred Ideas

Multi-currency conversion, bank API sync, receipts/attachments, yearly budgets, re-auth and session hardening for finance (Phase 6).
</deferred>
