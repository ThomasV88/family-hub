# Phase 5: Finance - Research

**Researched:** 2026-11-13
**Domain:** Exact-money bookkeeping with per-account privacy (Laravel 13, Inertia 3 + Vue, PostgreSQL), recurring bills matched to transactions, Slovak/Polish bank CSV import, hand-rolled SVG charts
**Confidence:** MEDIUM-HIGH (privacy, money, recurrence, encoding facts verified against the repo and the running image; bank-specific CSV layouts are NOT verified, no real samples exist)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
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

### Deferred Ideas (OUT OF SCOPE)
Multi-currency conversion, bank API sync, receipts/attachments, yearly budgets, re-auth and session hardening for finance (Phase 6).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| FIN-01 | Record income and expenses with amount, currency, date, account, category | Money section (parser, bigint, signed storage), data model, scoped validation rules |
| FIN-02 | Exact storage (integer minor units + currency code) | Currency exponent table, composite FK account/currency, CHECK constraints, no-float guards, property tests |
| FIN-03 | Admin configures which members see which accounts | Visibility architecture (membership table, scoped binding, admin rule, 404 parity, leak test suite) |
| FIN-04 | Recurring expenses with amount, schedule, category | Recurrence section (RecurrenceEngine reuse, anchor at 12:00, schedule-only mode, `between` extension) |
| FIN-05 | Upcoming bills + expected-vs-actual matching | Matcher design (scoring, greedy unique assignment, rejections, idempotency, derived statuses) |
| FIN-06 | Monthly budgets per category with progress | Budget section (effective-from versioning, single-account budgets, integer progress, timezone-free month math) |
| FIN-07 | Overviews and charts | SVG chart section (server-side aggregation, nice ticks, a11y, table alternative) |
| FIN-08 | CSV import (SK/PL, Windows-1250, `;`, decimal comma), profiles, review, duplicates, undo | CSV pipeline, encoding detection, staged-rows flow, hash design, undo semantics, fixtures |
</phase_requirements>

## Summary

Finance is mostly a privacy problem wearing a bookkeeping costume. The hard parts are (1) making every read path (lists, sums, charts, search, budgets, matching, import dedupe, home card, validation messages) pass through ONE viewer-scoped query layer so hidden accounts cannot leak through an aggregate, and (2) getting money and CSV decoding exactly right without floats and without a library. Both are solvable with plain PHP/Postgres/Vue and no new packages. The repo already has the right scaffolding: `AccessGate` (no admin bypass), `RequireAccess` (which already writes content-free `finance_read`/`finance_write`/`access_denied` rows before the controller runs), the `EndpointMatrix` harness, the Phase 4 differential/canary/query-log leak-test pattern, `RecurrenceEngine`, and `LocalTime`.

Three environment facts found by probing the running image change the design. (a) PHP `NumberFormatter` in the Alpine image has ONLY English locale data (sk, pl and en all rendered `-€1,234.50`), so no server-side locale formatting: parse with a hand-written strict parser, format in the browser with `Intl.NumberFormat`. (b) `mbstring` has no Windows-1250 (`mb_convert_encoding(..., 'Windows-1250')` throws), but `iconv` (GNU libiconv) handles `WINDOWS-1250`, `ISO-8859-2`, `CP852`; use `iconv` for all decoding. (c) `bcmath` is in the Dockerfile but the currently running worker container lacks it (`bcadd` missing, stale image); do not depend on it, integer math is enough, and rebuild images anyway. Also verified: PHP 8.4 emits a deprecation unless `fgetcsv`/`str_getcsv` get an explicit `escape` argument (pass `''`); `DateTimeImmutable::createFromFormat('!d.m.Y','31.02.2026')` silently returns 2026-03-03 unless you check `getLastErrors()` warnings; `Normalizer` NFD does not strip `Ł/ł` (so a transliteration map is needed for the dedupe hash); `HasUuids` in Laravel 13 generates UUIDv7.

**Primary recommendation:** Use UUIDv7 route keys everywhere, one `App\Finance\Visibility` scope (account membership subquery) applied by every query class (arch-tested), signed `bigint amount_minor` with composite FK `(account_id, currency)`, derived (not stored) bill statuses over a sparse match table, staged-row import (raw file never persisted) with a per-account `UNIQUE(account_id, import_hash)`, and a leak-test suite that enumerates finance routes from the router at run time.

## Project Constraints (from CLAUDE.md)

- Stack is Laravel (PHP) + Inertia + Vue + Postgres; ignore the Node/Next.js recommendations in CLAUDE.md PART 2. No Redis; database queue with a separate worker; Docker Compose services `app`, `nginx`, `worker`, `scheduler`, `postgres`.
- Privacy: per-module permissions enforced server-side; health and finance default restricted.
- Languages SK, PL, EN from the start; devices phone/desktop/iPad (PWA).
- Subscription-based AI only: not relevant to Phase 5 (no AI in finance; do not add any).
- Project skill: `sketch-findings-family-hub` (finance reference read: tabs Overview / Transactions / Recurring, calendar default on Recurring, state rule, lock icon, import panel with struck-through duplicates, whole-euro display with thin minus in sketch only).
- GSD workflow: all file changes go through GSD commands (planner/executor), not direct edits.
- Global instructions: staging only for testing, production only via PR to `main`; never print secrets.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Account visibility / permission decisions | API / Backend | Database (membership table, FK) | Must be server-side; UI hiding is cosmetic |
| Money parsing, validation, arithmetic | API / Backend | Database (CHECK, bigint, SUM) | Exactness lives in PHP ints + Postgres bigint; browser only displays |
| Money display / locale formatting | Browser / Client | — | Server ICU has English data only; `Intl` in browser handles sk/pl/en |
| Aggregates (totals, category sums, balances, budget progress) | Database / Storage | API / Backend | SQL `SUM`/`GROUP BY` always scoped by visible accounts; never summed in JS |
| Recurring occurrence enumeration | API / Backend | — | RecurrenceEngine (PHP), dates are date-only family-zone strings |
| Expected-vs-actual matching | API / Backend | Database (UNIQUE constraints) | Run at write time; idempotent via constraints |
| CSV decode/parse/map/dedupe | API / Backend | Browser (column-mapping UI only) | Raw file must be read once server-side and discarded |
| Charts and accessible table alternative | Browser / Client | API (pre-aggregated data) | Server sends <=24 aggregated points; SVG built in Vue |
| Dynamic response caching rules | Frontend Server (nginx/middleware) | Service worker | `NoStoreDynamicResponses` already applies; SW must never cache finance |

## Standard Stack

### Core (all already installed; NO new packages)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^13.17 (composer.json) | Routing, Eloquent, validation, scheduler | Project stack [VERIFIED: composer.json] |
| inertiajs/inertia-laravel | ^3 | Pages, props | Project stack [VERIFIED: composer.json] |
| rlanvin/php-rrule | v3.0.0 | Occurrence math behind `RRuleRecurrenceEngine` | Already used by Phase 3 [VERIFIED: composer.lock] |
| PostgreSQL | project image | bigint money, partial unique indexes, window functions | [VERIFIED: compose.yaml has `postgres`] |
| PHP ext iconv (GNU libiconv), mbstring, intl | PHP 8.4 Alpine | Decoding 1250/8859-2, UTF-8 checks, `Normalizer` | [VERIFIED: probed in worker container] |
| Pest 4.7 / Vitest 5.1 | — | PHP and JS tests | Existing [VERIFIED: composer.json, package.json] |

### Supporting (hand-written in-repo, per the owner's "prefer no new packages")

| Component | Location (recommended) | Purpose |
|-----------|-----------------------|---------|
| `App\Finance\Currency` | `app/Finance/Currency.php` | Allowlist + exponent table (EUR, CZK, PLN, USD, GBP, HUF...) |
| `App\Finance\MoneyParser` / `MoneyFormat` | `app/Finance/` | Strict string to minor int, minor int to plain decimal string |
| `App\Finance\Visibility` + query classes | `app/Finance/Queries/` | The only place that touches finance tables |
| `App\Finance\Import\*` | `app/Finance/Import/` | Decoder, CsvReader, ColumnMapper, RowNormalizer, Deduper, Committer, Undo |
| `App\Finance\Recurring\*` | `app/Finance/Recurring/` | OccurrenceWindow, Matcher, StatusResolver |
| `resources/js/Finance/` | charts (`BarChart.vue`, `LineChart.vue`, `niceTicks.js`), `money.js`, panels | Vue components |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Hand-rolled money (`MoneyParser`) | `brick/money`, `moneyphp/money` | Mature, but pull in bcmath/gmp and ICU (our ICU is English-only) and the owner wants no new packages; our needs are add/sum/compare only |
| `fgetcsv` + own reader wrapper | `league/csv` | league/csv is excellent but a new dependency; PHP native handles quoted multiline fields once `escape` is `''` |
| SVG hand-rolled | Chart.js / ECharts | Owner decision: no heavy chart library |
| Server-side `NumberFormatter` | `icu-data-full` apk | Would add image size and still not needed if browser formats |

**Installation:** none. **Image note:** rebuild the app/worker images so `bcmath` from the Dockerfile is really present (running worker lacks it), but finance code must not call `bc*`.

**Version verification:** no external packages are recommended, so there are no registry versions to verify; in-repo versions came from `composer.json`/`composer.lock` (rrule v3.0.0), the container (PHP 8.4, ICU `INTL_ICU_VERSION` 78.1, Node 22.23.5 on host).

## Package Legitimacy Audit

No external packages are recommended for this phase; nothing to audit. If the planner is tempted to add `league/csv`, `brick/money` or a chart library, that contradicts the owner's stated preference and needs a `checkpoint:human-verify` first.

**Packages removed due to slopcheck [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
Browser (Inertia/Vue, Intl formatting, SVG charts)
   |  GET /finance?month=YYYY-MM&account=<uuid>   POST/PATCH/DELETE  /finance/...   POST /finance/import (multipart)
   v
nginx (default 1m body; one route-specific 3m location for /finance/import) --> php-fpm
   v
Middleware: web -> EnsureRouteDeclaresAccess -> RequireAccess("module,finance,view|edit")
   |        (writes content-free finance_read / finance_write / access_denied audit row BEFORE controller)
   v
Controller (thin) --> FormRequest (scoped rules: account, category, recurring, batch resolved through Visibility)
   v
App\Finance\Queries\*  (ONLY code allowed to touch finance_* tables; every method takes Viewer)
   |-- AccountQuery        visible accounts + balances (SUM bigint)
   |-- TransactionQuery    lists, search, filters, pagination counts
   |-- OverviewQuery       income/expense/balance cards, category sums, monthly balance (GROUP BY currency)
   |-- BudgetQuery         progress (spent from visible account only)
   |-- RecurringQuery      expected occurrences in window + match status
   v
PostgreSQL: finance_accounts, finance_account_members, finance_transactions, finance_categories,
            finance_recurring, finance_recurring_matches, finance_match_rejections, finance_budgets,
            finance_import_profiles, finance_import_batches, finance_import_rows (staged)

Import:  upload(file) -> read bytes once -> Decoder(iconv) -> CsvReader(fgetcsv, escape '') -> Mapper(profile)
         -> RowNormalizer (MoneyParser, date, text) -> Deduper (hash per account) -> staged rows (status=draft)
         -> review page (toggle skip/probable-duplicate action) -> commit: INSERT ... ON CONFLICT DO NOTHING in 1 tx
         -> Matcher runs on the new transactions -> batch.committed. Undo: delete pristine rows, detach edited ones.
         (temp upload deleted by PHP at request end; nothing written to storage; finance:prune-imports removes stale drafts)

Reminders (optional): scheduler -> outbox (existing) for "bill due" using dedupe_key; recipients filtered by account visibility.
```

### Recommended Project Structure

```
app/Finance/            Currency, MoneyParser, MoneyFormat, Visibility, Viewer
app/Finance/Queries/    AccountQuery, TransactionQuery, OverviewQuery, BudgetQuery, RecurringQuery
app/Finance/Recurring/  OccurrenceWindow (uses RecurrenceEngine), Matcher, StatusResolver, TextNormalizer
app/Finance/Import/     Decoder, CsvReader, DateParser, ColumnMapper, RowNormalizer, Deduper, Committer, Undo
app/Models/Finance*     Eloquent models (prefix Finance, e.g. FinanceAccount) with resolveRouteBinding scoped
app/Http/Controllers/Finance/  thin controllers; Settings/FinanceAccountsController (admin, no money data)
routes/web/finance.php  + tests/Support/EndpointMatrix/finance*.php (one file per route group)
resources/js/Finance/   money.js, charts/, panels/, pages in resources/js/Pages/Finance/
tests/Support/FinancePrivacy.php  scenario + capture + canary helpers (mirror WishlistPrivacy)
tests/Unit/Arch/FinancePrivacyArchTest.php
lang/*.json             all finance keys added in ONE up-front plan (project convention)
```

### Pattern 1: One scope, applied everywhere (FIN-03)

**What:** `Visibility::accountIds(User $viewer)` returns a subquery `select account_id from finance_account_members where user_id = ?`. Every query class calls `->whereIn('account_id', Visibility::accountIds($viewer))` (or the model `scopeVisibleTo`). Query classes take a `Viewer` value object so a developer cannot forget it.
**When to use:** every read AND every write precondition (validation, matching candidates, dedupe lookups, undo).

```php
// Source: pattern mirrors Task::scopeVisibleTo in this repo (app/Models/Task.php)
public function scopeVisibleTo(Builder $q, User $user): void
{
    $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('finance_account_members as m')
        ->whereColumn('m.account_id', $q->getModel()->getTable().'.account_id')
        ->where('m.user_id', $user->id));
}
```

**Admin rule (recommendation, resolves the open point):** admin sees only accounts shared with them, consistent with Phase 2 "no admin bypass". Split by data class: account METADATA (name, currency, archived flag, member list) is managed by admin on a Settings page (`access:admin`, shows NO balance, count or transactions); MONEY data (opening balance, transactions, anything derived) is members-only. Consequences: opening balance is set at creation and editable only by a member who can see the account; the creating admin is NOT automatically a member; an account with zero members is valid and invisible to all non-admins.

**Record binding with 404 parity:** hidden and nonexistent ids must produce byte-identical responses. Do not use `Rule::exists('finance_accounts','id')` (a hidden account passes it, which allows WRITES to hidden accounts and gives a different error). Resolve ids via the scoped query class and return the same validation message/404. Watch middleware ordering: if implicit route-model binding runs before `access`, a user with no finance grant could get 404 vs 403 as an existence oracle; either resolve explicitly in the controller/FormRequest after `access` has run, or prove ordering in the EndpointMatrix (no-grant user gets the same 403 for visible, hidden and random uuids).

**UUIDv7 route keys:** sequential ids leak "something exists between 5 and 7" and the volume of hidden activity. Use `HasUuids` (Laravel 13 generates UUIDv7 [VERIFIED: vendor HasUuids.php uses `Str::uuid7()`]) and `uuid` PK columns; whereNumber route constraints become `whereUuid`.

### Pattern 2: Signed integer money with DB-enforced consistency (FIN-01/02)

**Storage:** `amount_minor bigint` SIGNED (expense negative, income positive), `currency char(3)`, `kind smallint/varchar`. Balance = `opening_balance_minor + SUM(amount_minor)`. Transfers are two rows (out negative, in positive) sharing `transfer_id uuid`, same currency required in v1 (no conversion), excluded from income/expense totals and category charts.

```sql
-- Source: designed for this repo; pattern follows wishes_*_check constraints in 2026_11_15_100000_create_wishlist_tables.php
ALTER TABLE finance_accounts ADD CONSTRAINT finance_accounts_currency_check CHECK (currency ~ '^[A-Z]{3}$');
ALTER TABLE finance_accounts ADD CONSTRAINT finance_accounts_id_currency_unique UNIQUE (id, currency);
-- every amount carries its currency AND it must equal the account's currency:
ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_account_currency_fk
  FOREIGN KEY (account_id, currency) REFERENCES finance_accounts (id, currency);
ALTER TABLE finance_transactions ADD CONSTRAINT finance_transactions_amount_check CHECK (
  amount_minor <> 0 AND amount_minor BETWEEN -10000000000000 AND 10000000000000   -- 1e13 keeps JS Number exact
  AND ((kind = 'income' AND amount_minor > 0) OR (kind = 'expense' AND amount_minor < 0) OR kind = 'transfer'));
-- duplicate detection, race safe:
CREATE UNIQUE INDEX finance_transactions_import_hash_unique ON finance_transactions (account_id, import_hash) WHERE import_hash IS NOT NULL;
-- indexes for aggregates:
CREATE INDEX finance_transactions_account_date_idx ON finance_transactions (account_id, booked_on DESC, id);
CREATE INDEX finance_transactions_category_idx ON finance_transactions (account_id, category_id, booked_on) WHERE kind = 'expense';
```

The account currency must be immutable (or only changeable while the account has no transactions); that single rule makes the composite FK safe.

**Currencies and minor units:** ISO 4217 gives EUR/PLN/CZK/USD/GBP 2 decimals; HUF is 2 in ISO but `Intl.NumberFormat` rendered `1 235 HUF` (0 digits) in Node 22 [VERIFIED: node probe], and JPY is 0. So: keep an explicit `Currency` table in code with `exponent`, do not take it from `Intl`, and ALWAYS pass `minimumFractionDigits = maximumFractionDigits = exponent` to `Intl.NumberFormat` so storage and display agree. Recommend v1 allowlist EUR, CZK, PLN (owner's region) plus USD/GBP; leave HUF/JPY out unless wanted (`[ASSUMED]` that the owner does not need them).

**Parsing (PHP):** a strict hand-written state machine, no `floatval`, no `round`, no `number_format`. Accept: optional leading `+`/`-`/`−` (U+2212), optional trailing `-`, parentheses negative, spaces and U+00A0/U+202F as grouping, ONE decimal separator (`,` or `.`) with 1..exponent digits, a known currency suffix/prefix stripped. Two modes: (1) manual entry: decimal separator is `,` or `.`, no grouping accepted, reject more fraction digits than exponent (matches the Phase 4 rule); (2) CSV: decimal separator and grouping character come from the PROFILE, never guessed ("1,234" is genuinely ambiguous). Compute `intdiv`/string padding only; amounts are bounded so `abs*100` fits in int64.

**Rounding rules:** the only division is percentage tolerance and display percent. Define ONE helper `percentOf(int $minor, int $pct): int = intdiv(abs($minor) * $pct + 50, 100)` (half-up) for tolerance, and compare budgets with cross-multiplication (`spent * 100 >= limit * 80`) instead of dividing. Postgres: always `SUM(amount_minor)::bigint` (SUM of bigint returns numeric, which PDO hands back as a string).

**JS formatting:** build a decimal STRING from the integer (`sign + intPart + '.' + zero-padded fraction`) and pass it to `Intl.NumberFormat.format()`; Intl v3 accepts decimal strings with exact precision, which avoids the float intermediate even for display `[ASSUMED: all target browsers (Chrome 106+, Safari 15.4+, Firefox 116+) support string input; verify on the owner's iPad; fallback is formatting integer and fraction parts separately]`. The existing `resources/js/Wishlist/money.js` uses `Number(price)` (fine for wishlist display, not for finance): write `resources/js/Finance/money.js` separately and do not reuse `App\Wishlist\Money` (fixed 2 decimals, non-negative, 9-digit cap, no currency table). Sums are computed in SQL and arrive as ints; the browser never adds amounts.

**Locale output observed in Node 22 (for test expectations):** sk-SK `-1 234,50 €`, pl-PL `-1234,50 €` (pl has min-grouping 2, so 4-digit numbers are ungrouped; `12 345` is grouped), en-GB `-€1,234.50`. Tests must not hardcode the exact spaces (they are NBSP/narrow NBSP); compare via `Intl` itself or normalise whitespace.

### Pattern 3: Recurring expenses on the Phase 3 engine (FIN-04/05)

**Engine facts [VERIFIED: app/Recurrence]:** `RecurrenceEngine::next($rrule, $anchorLocal, $mode, $completedAtLocal, $tz, $now)` returns the next scheduled local instant strictly after the anchor AND after `now` in Schedule mode; month-end clamp for days 29-31 is `BYMONTHDAY=28..N;BYSETPOS=-1` so Feb 31 becomes Feb 28/29 and Apr 31 becomes Apr 30; `RuleBuilder` allowlist has `daily|weekly|monthly_day|monthly_last|custom`, no YEARLY, no COUNT/UNTIL.

**Recommendations:**
1. Use Schedule mode ONLY. Bills are fixed-schedule: rent due on the 1st must not shift because it was paid on the 9th. Completion mode does not apply (document so nobody exposes it in the UI).
2. Store `recurrence_rule` (RuleBuilder canonical string), `starts_on date`, `ends_on date null`, `schedule_effective_from date`, `expected_amount_minor`, `tolerance_pct`, `window_before_days`, `window_after_days`, `text_hint`, `account_id`, `category_id`.
3. Anchor at **12:00 local** on `starts_on` (take only the date of results). 12:00 is outside every DST gap in any zone, so a configurable `family.timezone` can never produce a nonexistent or ambiguous time; `LocalTime::at()` already handles the rest.
4. Add a small method to enumerate a window (e.g. `RecurrenceEngine::between($rrule, $anchor, $from, $to, $tz, $cap=62)`; implement by looping `next()` with `now = from - 1 second`, or via php-rrule `getOccurrencesBetween`). Cap results (rule `daily` for a 31-day window is 31, fine). Do NOT call `next()` with the real `now` to enumerate past months: Schedule mode requires `> now`, so past occurrences would never come back (a pitfall for the overdue lookback and the calendar's past days). Reuse the existing `MonthEndTest`/`DstTest` style tests for the new method.
5. **Occurrences are computed on the fly; only matches/skips are rows.** Table `finance_recurring_matches (id uuid, recurring_id, due_date date, transaction_id, matched_by 'auto'|'manual', expected_amount_minor snapshot, created_at)` with `UNIQUE(recurring_id, due_date)` and `UNIQUE(transaction_id)`, FK `ON DELETE CASCADE` on both. Statuses are DERIVED at read time, never stored (so deleting a transaction or undoing an import reverts the status automatically):
   - matched and `abs(actual - expected) <= tolerance` -> **Paid**
   - matched manually or outside tolerance -> **Paid differently** (show the difference)
   - not matched and `due_date >= today(family tz)` -> **Upcoming**; not matched and `due_date < today` -> **Overdue**
6. **Edits:** a change to rule/start/amount sets `schedule_effective_from = today`; unmatched occurrences before that date are not evaluated (otherwise editing the rent amount flips old months to Overdue); matched past occurrences still render from match rows. Overdue lookback cap 45 days so a forgotten recurring item does not accumulate months of red.
7. A recurring "savings" item may legitimately be paid as a TRANSFER row; let candidates include `expense` rows and negative `transfer` rows of the same account `[ASSUMED, confirm with owner: see Open Questions]`.
8. "Zaplatené" button (sketch): creates a manual expense transaction on the recurring's account for the expected amount and today's date (user can edit) AND inserts the match row in the same DB transaction.

### Pattern 4: Matching engine (FIN-05)

Pure, deterministic, integer-only function `Matcher::score(Occurrence $o, Transaction $t): ?int` plus a greedy unique assignment.

- **Candidate filter (hard):** same `account_id` (never cross-account, which also removes any cross-visibility channel), `kind` in expense/negative transfer, transaction not already in `finance_recurring_matches`, no row in `finance_match_rejections` for `(recurring_id, due_date, transaction_id)`, `booked_on BETWEEN due - before AND due + after` (defaults 5 / 10 days; per-recurring editable).
- **Score (higher better):** amount within tolerance: exact +50, within tolerance +35; text hint present in normalised description +30; date proximity `max(0, 20 - 3*|days|)`; auto-accept when `amountOk` AND score >= 50, OR hint matches AND date in window (becomes Paid differently when amount outside tolerance). Everything else is only a "suggested" candidate for manual match.
- **Assignment:** collect all (occurrence, transaction) pairs above threshold, sort by score desc, then date distance, then transaction id, assign greedily with both sides unique. This is what stops two overdue months of rent from both claiming the same payment.
- **When it runs:** (1) after a manual transaction is saved, (2) after an import batch commit (on that batch's transactions only), (3) when a recurring item is created/edited (backfill over its lookback window). NOT on page load (page load only derives status). Inserts use `insertOrIgnore` / `ON CONFLICT DO NOTHING`, so re-running is a no-op; manual matches (`matched_by='manual'`) are never replaced by auto.
- **Manual unmatch** deletes the match row AND inserts a `finance_match_rejections` row so auto-matching does not immediately re-match the same pair; manual match of a different transaction replaces the previous match for that occurrence (one-per-occurrence unique).
- **Late imports (the bug found in sketch testing):** import review must apply the same logic so a manually recorded payment is not duplicated (see CSV pipeline "probable duplicate").
- **Normalisation (`TextNormalizer`):** lowercase (`mb_strtolower`), NFD via `Normalizer` then strip `\p{Mn}`, plus an explicit map for characters NFD does not decompose (verified: `Łódź` becomes `Łodz`, so map Ł/ł -> L/l, Đ/đ -> D/d, ß -> ss), collapse non-alphanumerics to single spaces, trim. Normalizer works in the image despite the English-only locale data (NFD ran fine) `[VERIFIED: probe]`.

### Pattern 5: Budgets (FIN-06)

- Table `finance_budgets (id uuid, account_id, category_id, amount_minor bigint > 0, effective_from date (first of month), created_by)` with `UNIQUE(account_id, category_id, effective_from)`. The limit for month M is the row with the greatest `effective_from <= first_of(M)`; "budgets roll to the next month" is implemented by that lookup (no copy rows, a change applies from the chosen month forward, past months keep their old limit). Optionally "end budget" via a row with `amount_minor = NULL`-like tombstone (`ended boolean`).
- **Attach a budget to exactly ONE account in v1.** A budget spanning accounts makes its progress viewer-dependent (partial sums) or leaky. Single-account means budget visible iff account visible, progress always complete. Cost: a family "Groceries" budget over two accounts needs two budgets; flag to owner (Open Questions Q2).
- **Progress query:** `SELECT category_id, -SUM(amount_minor)::bigint AS spent FROM finance_transactions WHERE account_id = ? AND kind='expense' AND booked_on >= :m1 AND booked_on < :m2 GROUP BY category_id`. States by integer compare: `spent*100 >= limit*80` approaching, `spent > limit` over (red, plus text not colour only).
- **Unspent carry-over** (interpretation risk): CONTEXT says "roll to the next month"; the research brief says "rollover". Plan v1 as "limit persists". If the owner wants unspent carry-over, compute `carry = GREATEST(0, SUM over months since rollover_from of (limit - spent))` with `generate_series` in SQL, never stored; clamp at 0 (no negative carry). Recorded as Open Question Q1.
- **Month boundaries are timezone-free:** `booked_on` is a `date` in the family calendar; the month filter is two date strings built from `YYYY-MM`. "Today" for Upcoming/Overdue is `CarbonImmutable::now(config('family.timezone'))->toDateString()` (same as `HomeController`). NEVER `date_trunc('month', booked_on)` directly: on a `date` it is promoted to `timestamptz` using the session time zone; use `booked_on::timestamp` or `to_char(booked_on,'YYYY-MM')`.
- **Cross-currency:** every aggregate groups by currency and the UI shows one block per currency; never add EUR to CZK. Budgets inherit the account currency.

### Pattern 6: Accessible SVG charts (FIN-07)

- **Data volume:** aggregate in SQL, send at most 12-24 monthly points and at most ~12 category bars (top N + "Other"). Thousands-of-points performance then never arises; if a daily chart is added later, downsample server-side.
- **Chart types:** category spending = horizontal bars (labels readable on phones, easy direct labelling); balance over months = line/columns with a zero baseline (balance can be negative). Not a donut.
- **Components:** `BarChart.vue`, `LineChart.vue` taking `{label, valueMinor}[]` + `currency`; a pure `niceTicks(minMinor, maxMinor, targetCount)` working on integers (step in {1,2,5} x 10^k minor units, includes 0), unit-tested with seeded random ranges; viewBox-based scaling (`width:100%`, `preserveAspectRatio`), labels via `Intl.NumberFormat(..., {notation:'compact'})` for axes and full format for tooltips/table.
- **A11y:** `<figure>` + `<figcaption>`; the SVG is `aria-hidden="true"` and a real table (`UiTable`) carries the data for assistive tech and sighted users via a Chart/Table toggle (WCAG 1.1.1); bars have direct value labels so meaning is not colour-only (1.4.1); non-text contrast >= 3:1 against the surface (1.4.11); over-budget gets an icon/text, not just red. Colours: use existing tokens (`--color-primary`, `--color-danger`, `--color-text-muted`, gridlines `--color-border-strong`); the design system has a one-accent rule so a single-hue chart with direct labels needs no categorical palette (and avoids a 6-colour AA problem). Contrast of the chosen fills should be asserted in the existing `tests/js/tokens.test.js` style in light and dark.
- **Motion:** no animation by default; if a draw-in is wanted, gate it with `@media (prefers-reduced-motion: no-preference)`.
- **No fetch on mount:** pass chart data as Inertia page props (one request, one audit row); month switching is a visit with a query string.

### Pattern 7: CSV import pipeline (FIN-08)

**Decode:**
1. Read the uploaded temp file once into a string (`file_get_contents($file->getRealPath())`); never call `->store()`/`->move()`. PHP removes the temp upload at request end `[CITED: PHP manual, file uploads]` (still assert in a test that no new files appear in `storage/` or the system temp dir).
2. Reject: size > 2 MiB (rule `max:2048` plus nginx cap), any NUL byte when not UTF-16, ZIP signature `PK\x03\x04` (an `.xlsx` renamed), more than 5000 data rows (stop reading at cap+1).
3. Encoding detection (suggest only; the profile's explicit encoding wins): UTF-8 BOM -> strip, UTF-8; UTF-16 BOM (`FF FE`/`FE FF`) -> `iconv('UTF-16...')`; else `mb_check_encoding($s,'UTF-8')` true and has non-ASCII -> UTF-8; else if bytes in 0x80-0x9F (other than undefined) appear -> `WINDOWS-1250` (in ISO-8859-2 that range is C1 control codes, so it is a strong 1250 signal; `0x8A/0x8D/0x8E/0x9A/0x9D/0x9E` are Š Ť Ž š ť ž in 1250); else default `WINDOWS-1250` for SK/PL, offering ISO-8859-2 in the dropdown (they differ for Š/š/Ť/ť/Ž/ž, so the preview must show decoded text and let the user flip encoding and re-preview).
4. Use `iconv(from, 'UTF-8', $bytes)`, NOT `mb_convert_encoding` (mbstring throws on `Windows-1250` in this image `[VERIFIED: probe]`). `iconv` verified for `WINDOWS-1250`, `CP1250`, `ISO-8859-2`, `CP852`. Undefined bytes make iconv return false/notice: catch and show "cannot decode with this encoding". Decode the WHOLE file before parsing so delimiter detection and quote handling operate on UTF-8 (UTF-16 would otherwise break byte-wise parsing).
5. Normalise newlines (CRLF/CR to LF) after decoding, strip BOM char, drop NUL/C0 controls (except tab/newline inside quoted fields), cap field lengths (description 500, counterparty 200).

**Parse:** feed a `php://temp/maxmemory:2097152` stream to `fgetcsv($h, 0, $delimiter, '"', '')`; the explicit empty `escape` is REQUIRED on PHP 8.4 (verified deprecation, which Laravel would surface) and gives RFC-4180 behaviour (`""` inside quotes, quoted multiline fields stay one row). Delimiter suggestion: count `;` `,` `\t` `|` outside quotes over the first ~20 non-empty lines, choose the candidate with the most consistent column count >= 2; user can override. Preamble: the user picks the header row (profile stores `header_row`), rows above are skipped; footer/summary rows (fewer columns, empty required cells, "Total"/"Súčet" lines) become row errors listed in review rather than being silently imported. A 2 MiB cap and a 5000-row cap keep memory far below the 128M `memory_limit` `[VERIFIED: ini probe]`.

**Map (profile):** table `finance_import_profiles (id uuid, name, delimiter, encoding, decimal_separator, grouping_separator null, date_format (allowlisted key), header_row, has_header, amount_mode 'single'|'split'|'indicator', columns json, indicator_values json, created_by)`. Columns are stored by HEADER NAME with index fallback (survives column reordering by the bank). Profiles contain no money data, so they are family-wide for anyone with finance access; they must NOT store an account id. Date formats are an allowlist (`d.m.Y`, `j.n.Y`, `Y-m-d`, `d/m/Y`, `d-m-Y`, with optional ` H:i[:s]`, `d.m.y`) parsed with `DateTimeImmutable::createFromFormat('!'.$fmt, $s)` AND a check that `getLastErrors()` has no warnings (31.02.2026 otherwise silently becomes 3 March `[VERIFIED: probe]`). Amount modes: single signed column; split debit/credit columns (both positive or debit negative; both filled = row error); amount plus direction indicator column (values mapped in the profile, e.g. `MD`/`D`, `Debit`/`Credit`). Handle trailing minus, parentheses, NBSP grouping. A currency column, if mapped, must equal the account currency else the row is invalid (never silently import EUR rows into a CZK account).

**Realities, honestly:** official bank documentation found only for adjacent facts: mBank's ELIXIR format permits ISO 8859-2, Windows-1250 and CP852 `[CITED: mbank.pl Elixir docs via search]`; a Slovak UniCredit merchant CSV spec states `;`, WINDOWS CP1250, CRLF, comma decimal with space thousands `[CITED: unicreditbank.sk Merchant_vypisy.pdf via search]`; a PKO BP Slovakia import spec uses `;` and allows comma or dot decimals; Czech PPF banka CSV is CP 1250. No official CSV layout was found for Tatra banka, Slovenská sporiteľňa, VÚB or the mBank history export (the `#Data operacji` header marker comes only from a third-party gist). Therefore: ship generic profiles only (CONTEXT already decides this), never named bank presets, and say so in the UI copy.

**Review step data flow (no raw file kept):** the upload request does everything up to dedupe and writes a DRAFT batch (`finance_import_batches.status='draft'`, `user_id`, `account_id`, `profile snapshot json`) plus staged rows (`finance_import_rows`: row_no, booked_on, amount_minor, kind, description, counterparty, reference, import_hash, state `new|duplicate|probable_duplicate|invalid`, error_code, `include boolean`, `matched_transaction_uuid null`). The review page reads staged rows (paginated), the user toggles `include` and picks the action for probable duplicates, commit runs one DB transaction. Draft batches are visible only to their creator AND only while the account is visible. Alternative (re-upload the file on commit, store nothing) was rejected: it doubles upload size, complicates large reviews, and makes row selection fragile; staged rows hold exactly what CONTEXT allows ("only parsed rows with batch id are stored"). Add `finance:prune-imports` (daily, drafts older than 24 h; also delete drafts of a user when a new upload for the same account starts), registered like `wishlist:prune`.

**Duplicate detection (hash):**
`import_hash = sha256(account_uuid | booked_on | amount_minor | normalized_description | ordinal)` where `ordinal` is the 1-based index of identical `(date, amount, normalized_description)` rows WITHIN THE FILE. Why: two identical legitimate payments the same day (two coffees) get ordinals 1 and 2 and both import; re-importing an overlapping statement reproduces the same ordinals and skips them. If the profile maps a unique bank reference/transaction id column, hash that instead of the ordinal. Hash is per account (account id inside the hash AND in the unique index), so no cross-account inference. Known limits to document: an overlapping file that cuts between two identical rows can mis-skip one; descriptions that embed variable timestamps defeat dedupe (the user can map a different description column). Normalisation is the shared `TextNormalizer`. `UNIQUE(account_id, import_hash)` partial index plus `ON CONFLICT DO NOTHING` makes commit idempotent and race safe (double-click commit, two tabs).

**Probable duplicate of a manual entry:** same account, same `amount_minor`, `booked_on` within +-3 days, existing transaction `source='manual'` and not yet reconciled: stage as `probable_duplicate` with the existing transaction's uuid, default action "reconcile" (skip the row and write its `import_hash` onto the manual transaction so a future re-import dedupes exactly, and so its recurring match stays), user may choose "import anyway".

**Undo semantics:** `finance_transactions.import_batch_id` (uuid FK, nullable) and `edited_at timestamp null` (set by the app on any user edit of amount/date/description/category/account). Undo (allowed to a user who can edit the account, at any time): delete batch rows where `edited_at IS NULL`; rows already edited are KEPT and detached (`import_batch_id = NULL`), and the result message reports "N removed, M kept because you edited them". Matches cascade away with deleted rows so recurring statuses revert. Transfers created from imported rows count as edited (kept). Batch row stays with `undone_at` for history; deleting rows frees their hashes so the statement can be re-imported. Undo is one DB transaction and writes a `finance_write` audit row via the route middleware (content-free).

**CSV injection:** the app only renders text through Vue (escaped), so display is safe. Spreadsheet formula injection matters ONLY for export, and export is not in FIN-01..08: recommend NOT building CSV export in Phase 5 (it is also the largest extra leak surface). If the planner adds it anyway: only for visible accounts via the same query class, prefix text cells starting with `= + - @ \t \r` with `'` (numeric columns excluded), UTF-8 BOM, `;` delimiter, ASCII decimals, no-store headers, and include the route in the leak tests (they enumerate routes so it will be covered automatically).

### Pattern 8: Privacy test structure (mirror Phase 4)

Reuse the shape of `tests/Support/WishlistPrivacy.php` and `tests/Feature/Wishlist/ReservationPrivacyTest.php`:

1. `FinancePrivacy::surfaces()` enumerates every named GET route starting `finance.` plus `home`, `settings.*` (audit page, members/permissions) from the router at run time; unknown route parameters throw (forces a provider for every new route).
2. **Differential:** viewer V (finance edit, member of account A only). Capture all surfaces (Inertia + HTML, both as in Phase 4) with only account A data; then seed account B (hidden from V) with transactions, a recurring item, a budget, a staged import batch, matches, a category used only there, and capture again: bodies must be byte-identical.
3. **Canary:** hidden data uses sentinel values (name `ZxCanaryFin`, amount minor `777777` = `7777.77`, hash prefix, a distinctive category/description) and a scan over every captured body asserts none appear (strip ISO timestamps as in `scannable`; scan for the amount in minor, decimal and comma forms).
4. **Query-log:** for V's requests, assert every SQL statement that mentions `finance_transactions|finance_recurring|finance_budgets|finance_import_*` also contains the membership predicate (`finance_account_members`) or is keyed by an account id from the visible set; assert zero SELECTs against hidden-account ids is not directly checkable, so assert the predicate presence and that counts/sums queries are scoped (this is the structural net behind the differential test).
5. **Arch tests (`Pest arch()`):** finance models and `App\Finance\Queries` may only be used by `App\Finance`, `App\Http\Controllers\Finance`, `App\Http\Requests\Finance`, `Database\Factories`, `Tests\Support`; `HomeController` and notification handlers may use only a dedicated `App\Finance\HomeSummary` that takes a `Viewer`; plus a plain file-scan test that `DB::table('finance_` / raw `finance_transactions` strings appear only under `app/Finance/Queries` and migrations (Pest arch cannot match strings).
6. **Write-path parity:** a table of write endpoints x (visible id, hidden id, random uuid) asserting identical status/body for hidden and random ids; validation errors for hidden `account_id`, `transfer_to`, `recurring_id`, `transaction_id` (manual match) identical to nonexistent.
7. **EndpointMatrix rows:** literal expectations per role for every finance route (admin 403 on money routes unless granted, guestPreset/noGrants 403, child per Phase 2 preset finance level; verify the preset table before writing literals), admin 200 on the Settings account-membership routes, plus a coverage test that already fails when a registered route lacks a matrix row.

**Inertia shared props:** do not add accounts/balances to `HandleInertiaRequests::share`; keep all finance data as page props only. `auth.access.finance` is only a level string (already shared). The Home card must be absent (prop not present, not `null`) when the viewer has no finance grant, and must be computed through `HomeSummary` (counts of overdue/upcoming bills on VISIBLE accounts only, no amounts needed).

**Audit interaction [VERIFIED: RequireAccess.php, AuditLogger.php, AuditEvent.php]:** every request to a route with `access:module,finance,*` writes one `finance_read` (GET/HEAD) or `finance_write` row before the controller; meta allowlist for those events is empty so no ids/amounts can be logged; denied attempts write `access_denied` with `level`. Implications: (a) volume is one row per page navigation: avoid polling, avoid separate JSON fetches on mount, and avoid Inertia partial-reload chatter (a month switch is one visit); (b) rows are pruned at 2 years by `audit:prune`; (c) record-level 404s (hidden id) are not audited (deliberate, to keep parity); (d) no new audit events are required; if the owner wants "import committed" or "account visibility changed" evidence, add events with meta limited to counts/never content, and `PermissionChanged`-style rows for visibility changes (`target` = the member whose visibility changed, no amounts) `[ASSUMED recommendation]`. Admin changing account membership is a `finance_write` on the settings route only if that route also carries `access:module,finance,edit`; since it is `access:admin`, add an explicit `AuditEvent` for membership changes so it is not unaudited (Open Question Q3).

### Anti-Patterns to Avoid
- **Any finance query outside `App\Finance\Queries`** or without the visibility predicate; aggregates and COUNT(*) are as leaky as rows.
- **`Rule::exists(...)`/`findOrFail` on unscoped models** for account/transaction/recurring/batch ids.
- **Summing across currencies**, or computing totals/progress in JS.
- **Floats anywhere**: `floatval`, `(float)`, `round()`, `number_format()`, `Number(...)`/`parseFloat` for money in `app/Finance` and `resources/js/Finance` (enforce with a scan test).
- **`date_trunc('month', booked_on)`** without `::timestamp`; **`createFromFormat` without warning check**; **`mb_convert_encoding` for 1250**; **`fgetcsv` without `escape: ''`**.
- Storing the raw CSV (storage disk, cache, log, session, Telescope-style debug); logging parsed rows or amounts; putting transaction descriptions in exception messages.
- Showing "category in use (N transactions)" counts or blocking category deletion by hidden usage: delete = reassign to "Uncategorized", no counts.
- Caching finance responses in the service worker (the SW is precache-only; keep a test, see `tests/js/sw-precache.test.js`).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Recurrence/month-end math | New date loop | `RecurrenceEngine` + `RuleBuilder` (+ small `between` method) | Clamp, DST and 10-year cap already tested |
| Permission checks | New gate | `AccessGate` + `access:` middleware + `Visibility` scope | Deny-by-default, audit hooks already wired |
| Audit logging | New log table | `AuditLogger` / `RequireAccess` hook | Content-free by construction (meta allowlist) |
| Currency display | PHP `NumberFormatter` | `Intl.NumberFormat` in browser | Server ICU has English data only (verified) |
| CSV quoting/multiline | Regex splitter | `fgetcsv(..., escape: '')` | Quoted newlines and `""` escapes handled natively |
| Charset conversion | Custom byte tables | `iconv` (libiconv) | 1250/8859-2/852 verified present |
| Duplicate-safe inserts | Check-then-insert | Partial unique index + `ON CONFLICT DO NOTHING` | Race-safe, covers double-click commit |
| Staged-import cleanup | Ad hoc deletes | Scheduler command like `wishlist:prune` | Existing pattern, `withoutOverlapping` |
| Reminders for bills (if built) | New mailer | Existing notification outbox + `dedupe_key` | Idempotent, quota/prefs handled |
| UI table/panels | New components | Phase 01.1 `Ui*` components (UiTable, panels) | Design system, a11y sweeps already include them |

**Key insight:** the deceptively complex parts are not libraries' jobs here: the exact rules (visibility, tolerance, hashing, undo) are domain rules that must be written once, in one place, and tested adversarially.

## Runtime State Inventory

Not a rename/refactor phase: greenfield module. One addition: the running worker image predates the `bcmath` Dockerfile change (stale image); rebuild images so staging and local match the Dockerfile. No stored data, OS registrations or secrets are renamed.

## Common Pitfalls

### Pitfall 1: Aggregate leaks
**What goes wrong:** a hidden account's spending shows up in a category total, budget bar, balance chart, home card, search result count, pagination total, or "N matches" text.
**Why:** developers scope the list query but write the SUM separately.
**How to avoid:** single `Queries` namespace, `Viewer` parameter, arch test, differential + canary test over every surface including HTML and Inertia JSON.
**Warning signs:** `DB::table('finance_transactions')` outside `app/Finance/Queries`; a response that changes when hidden data is seeded.

### Pitfall 2: Validation and binding as existence oracles
**What goes wrong:** `exists:finance_accounts,id` lets a user POST a transaction into a hidden account, and distinct messages/404 vs 403 reveal what exists.
**How to avoid:** scoped resolver with identical message for hidden and nonexistent; matrix proves no-grant users get identical 403 for any uuid; UUIDv7 keys remove id-gap inference.

### Pitfall 3: Alpine ICU is English-only
**What goes wrong:** `NumberFormatter('sk')` formats like English (`-€1,234.50`), which silently produces wrong SK/PL output in emails/exports.
**How to avoid:** no server-side locale formatting; browser `Intl`. Add an explicit test that documents the image limitation if anything server-side formats (or `apk add icu-data-full` if the owner later wants emails with amounts).

### Pitfall 4: Encoding mojibake and silent wrong decoding
**What goes wrong:** 1250 decoded as UTF-8 or ISO-8859-2 corrupts Š/š/Ť/ť/Ž/ž or yields replacement characters, which then enter descriptions and the dedupe hash (re-import under the other encoding never dedupes).
**How to avoid:** preview shows decoded text, suggested encoding with reason, profile stores the explicit encoding; fixtures in 1250, ISO-8859-2, UTF-8+BOM; negative test (1250 bytes decoded as UTF-8 is rejected by the detector). Do not use `mb_*` for 1250.

### Pitfall 5: Lenient date parsing
**What goes wrong:** `31.02.2026` becomes 3 March, `1.2.26` ambiguous. **How to avoid:** `!` prefix, `getLastErrors()` warning check, allowlisted formats, row-level error rows rather than silent coercion; flag dates more than ~1 year in the future or before account opening as warnings.

### Pitfall 6: `date_trunc` timezone drift and SUM types
**What goes wrong:** month buckets shift by a day near midnight in non-UTC sessions; `SUM` returns numeric strings. **How to avoid:** `::timestamp`/`to_char`, `::bigint`, casts in the model, and date-only columns with string month boundaries.

### Pitfall 7: Engine misuse for history
**What goes wrong:** `next()` with real `now` never returns past occurrences, so past-month status and the calendar's past days are empty. **How to avoid:** a `between` method (or `now = from - 1s`), tested with `MonthEndTest` style cases (31st in Feb/Apr, leap year, DST weeks).

### Pitfall 8: Matching instability
**What goes wrong:** the same transaction claimed twice; unmatch undone on next run; editing a recurring amount turns old months Overdue. **How to avoid:** two UNIQUE constraints, rejection table, `schedule_effective_from`, greedy unique assignment, idempotent re-run test (run matcher twice, assert identical state).

### Pitfall 9: Import undo deleting user work
**What goes wrong:** deleting batch rows removes transactions the user edited or matched. **How to avoid:** `edited_at`, keep-and-detach rule, result message, test for each state (pristine, edited, matched, transfer-converted).

### Pitfall 10: Upload and draft hygiene
**What goes wrong:** raw file persisted or left in `/tmp`; drafts accumulate; huge file exhausts memory. **How to avoid:** read once, 2 MiB/5000-row caps, nginx route-specific limit (default is 1m in `docker/nginx/snippets/laravel-locations.conf`; add a `location ~ ^/finance/import$` like the wish-image one with 3m; PHP limits are already 6M/8M), `finance:prune-imports`, a test asserting no files created in storage/temp and no raw content in logs.

### Pitfall 11: Staging data
**What goes wrong:** real finance data in staging. **How to avoid:** any staging seed uses synthetic names and `@example.test`; extend `staging:audit` (app/Console/Commands/StagingAudit.php) to check finance tables with a marker convention.

## Code Examples

### Strict amount parse (sketch of the contract; tests are the spec)
```php
// Source: designed for this repo (no float, no bc*); verified building blocks: intdiv, ctype, preg
final class MoneyParser
{
    /** "−1 234,50", "1.234,50-" (profile: decimal ',', grouping '.'), "(12,5)" -> int minor units, or null if invalid */
    public static function toMinor(string $raw, Currency $cur, string $decimalSep, ?string $groupingSep): ?int
    {
        $s = trim(str_replace(["\u{00A0}", "\u{202F}", ' ', "\u{2212}"], ['', '', '', '-'], $raw));
        $neg = false;
        if (preg_match('/^\((.+)\)$/', $s, $m)) { $neg = true; $s = $m[1]; }
        if (str_ends_with($s, '-')) { $neg = ! $neg; $s = substr($s, 0, -1); }
        if (str_starts_with($s, '-')) { $neg = ! $neg; $s = substr($s, 1); } elseif (str_starts_with($s, '+')) { $s = substr($s, 1); }
        if ($groupingSep !== null) { $s = str_replace($groupingSep, '', $s); }
        $parts = explode($decimalSep, $s);
        if (count($parts) > 2 || ! ctype_digit($parts[0]) || strlen($parts[0]) > 13
            || (isset($parts[1]) && (! ctype_digit($parts[1]) || strlen($parts[1]) > $cur->exponent))) { return null; }
        $frac = str_pad($parts[1] ?? '', $cur->exponent, '0');
        $minor = (int) ($parts[0].$frac);
        return $neg ? -$minor : $minor;
    }
}
```

### Window enumeration of a bill (due dates only, family zone)
```php
// Source: pattern over RRuleRecurrenceEngine (app/Recurrence); `between` is the recommended new method
$anchor = LocalTime::at($recurring->starts_on->toDateString(), '12:00', $tz);
$dues = $engine->between($recurring->recurrence_rule, $anchor, $from, $to, $tz); // list<CarbonImmutable>
$dates = array_map(fn ($d) => $d->format('Y-m-d'), $dues);
```

### Decode and parse (UTF-8 text through native CSV)
```php
// Source: verified in worker container: iconv WINDOWS-1250 ok, fgetcsv needs explicit escape on PHP 8.4
$utf8 = iconv('WINDOWS-1250', 'UTF-8', $bytes);          // false on undefined bytes -> user-facing error
$h = fopen('php://temp/maxmemory:2097152', 'w+'); fwrite($h, $utf8); rewind($h);
while (($row = fgetcsv($h, 0, ';', '"', '')) !== false) { /* ... */ }
```

### JS display without float intermediate
```js
// Source: Intl.NumberFormat v3 accepts decimal strings; verify on target browsers (see Assumptions A3)
export function formatMinor(minor, currency, exponent, locale) {
  const neg = minor < 0; const abs = String(Math.abs(minor)).padStart(exponent + 1, '0');
  const dec = (neg ? '-' : '') + abs.slice(0, abs.length - exponent) + (exponent ? '.' + abs.slice(-exponent) : '');
  return new Intl.NumberFormat(INTL_LOCALES[locale] ?? locale, { style: 'currency', currency,
    minimumFractionDigits: exponent, maximumFractionDigits: exponent }).format(dec);
}
```

### Synthetic fixtures (realistic without real bank files)
Generate in code, not by hand: `tests/Support/CsvFixtures.php` builds statements as UTF-8 then converts with `iconv('UTF-8','WINDOWS-1250', $csv)` / `ISO-8859-2` / UTF-8 BOM / UTF-16LE. Variants to cover: `;` and `,` and tab; decimal comma with NBSP/space/dot grouping; `DD.MM.YYYY`, `D.M.YYYY`, `YYYY-MM-DD`, with time; header preamble (account info, IBAN, period lines) and footer (opening/closing balance, totals); split debit/credit; amount + `MD/D` indicator; trailing minus and parentheses; quoted fields with embedded `;`, `""` and newlines; diacritics-heavy text (`Ľudovít Štúr`, `Żabka Łódź`, `Škôlka`, `Zaplatené`); CRLF/LF/CR; blank trailing lines; identical same-day rows (ordinal test); overlapping statements (re-import); wrong-currency row; invalid date; oversized and 5001-row files; binary/zip-renamed file; mojibake negative (1250 decoded as UTF-8). Commit a small set of generated files under `tests/fixtures/finance/` with a README stating they are synthetic (invented IBANs, invented merchants), so byte-level 1250 regressions are visible in review. Do not claim any file matches a real bank.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `fgetcsv` default escape `\` | Pass `escape: ''` explicitly | PHP 8.4 deprecation `[VERIFIED: probe]` | Omitting it prints a Deprecated notice |
| Random-id integer keys | UUIDv7 (`HasUuids`) | Laravel 13 default uuid7 `[VERIFIED: vendor]` | Ordered index locality, no enumeration |
| `Intl.NumberFormat` with Number only | Accepts decimal strings (NumberFormat v3) | ES2023 | Exact display `[ASSUMED browser support, A3]` |

**Deprecated/outdated:** relying on `bcmath` (not needed here); `mb_convert_encoding` for Windows-1250 (not supported in this build).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Owner needs only EUR/CZK/PLN (+USD/GBP); HUF/JPY not needed in v1 | Pattern 2 | Currency table needs more rows; HUF exponent decision (2 per ISO vs 0 display) must be revisited |
| A2 | Account/budget/recurring metadata is admin-managed and admin is not an automatic member | Pattern 1 | Product flow differs from owner expectation; UI for creating accounts moves |
| A3 | Intl.NumberFormat decimal-string input works on the owner's devices (Chrome 106+, Safari 15.4+, FF 116+) | Pattern 2, Code Examples | Display falls back to split-part formatting |
| A4 | "Budgets roll to the next month" means the limit persists (not unspent carry-over) | Pattern 5 | If carry-over is wanted, add SQL carry computation (design given) |
| A5 | A budget belongs to exactly one account in v1 | Pattern 5 | Owner may want cross-account budgets (needs "visible iff all accounts visible" rule) |
| A6 | Recurring "savings" may be matched by transfer rows | Pattern 3/4 | Savings would show Overdue when paid as a transfer |
| A7 | Default windows (5 days before / 10 after), tolerance 0-5%, probable-duplicate window +-3 days | Pattern 4/7 | Tuning only; make configurable |
| A8 | Encoding heuristic: bytes 0x80-0x9F imply Windows-1250 rather than ISO-8859-2 | Pattern 7 | Wrong suggestion; mitigated by preview + explicit profile encoding |
| A9 | Slovak/Polish banks commonly export `;`-delimited Windows-1250 CSV with decimal comma | Pattern 7 | Only affects defaults; profiles are user-mapped. Bank-specific layouts are NOT verified |
| A10 | CSV export is out of scope (not in FIN-01..08) | Pattern 7 | If wanted, add scoped export plus formula-injection guard |
| A11 | Pest `arch()` `->not->toUse([...functions...])` works for global functions; else use a file-scan test | Validation | Falls back to the file-scan test |
| A12 | PHP deletes the uploaded temp file at request end | Pattern 7 | A test (no leftover files) catches it |

## Open Questions (RESOLVED)

RESOLVED by orchestrator decisions: (1) the budget limit persists to following months (no unspent carry-over in v1); (2) budgets are per category (family-wide limit) and progress counts only transactions of accounts visible to the viewer; (3) admin membership changes get their own AuditEvent; (4) a recurring savings item may match transfer rows, cross-currency transfers deferred; (5) planner must read Preset.php before writing literal EndpointMatrix expectations; (6) Home card shows counts of overdue and upcoming bills on visible accounts only; (7) generic CSV profiles only, no bank-named profiles until the owner supplies anonymised statements (deferred); admin sees account metadata only and money data only for accounts shared with them; transfers with a hidden counterpart render as plain income or expense; categories are family-wide non-sensitive.

1. **Budget "roll to the next month": persisting limit or unspent carry-over?**
   - Known: CONTEXT says "budgets roll to the next month"; brief says "rollover".
   - Unclear: whether leftover/overspend carries.
   - Recommendation: plan "limit persists" (effective-from versions); ask owner; carry-over costs one SQL expression, no schema change.
2. **One budget per account vs family-wide budget across accounts?**
   - Recommendation: single account in v1 (safe and simple); if cross-account is required use "visible only if viewer can see every account in scope".
3. **Audit of membership changes by an admin who lacks finance grant.**
   - Known: `access:admin` routes bypass the finance audit hook.
   - Recommendation: add an `AuditEvent` (e.g. `finance_visibility_changed`, meta none or counts) written by the settings controller; confirm with owner.
4. **Recurring savings as transfer** (A6) and **cross-currency transfer** (deferred, same-currency only in v1).
5. **Phase 2 preset defaults for finance** (parent/child/grandparent levels): verify in `app/Access/Preset.php` before writing literal matrix expectations (not read in this research).
6. **Home card content:** counts of overdue/upcoming bills on visible accounts only (recommended) vs nothing.
7. **Bank sample files:** none exist; ask the owner for one anonymised statement per bank he uses, to verify encoding/columns before any starter profile (CONTEXT permits starter profiles only when verified).

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP ext iconv (libiconv) | CSV decoding | yes | Alpine libiconv, WINDOWS-1250/ISO-8859-2/CP852 ok | — |
| PHP ext mbstring | UTF-8 checks, lowercase | yes | no Windows-1250 support | use iconv |
| PHP ext intl | `Normalizer` for hashing | yes | ICU 78.1, English locale data only | no server formatting |
| PHP ext bcmath | not required | no (stale running worker; present in Dockerfile) | — | integer math; rebuild images anyway |
| Postgres (compose `postgres`) | bigint, partial indexes | yes | project image | — |
| Node (host) | Vitest | yes | 22.23.5 | — |
| `app` compose service | tests | not running at probe time (worker used) | — | `docker compose run --rm app` per Phase 4 |

**Missing dependencies with no fallback:** none. **Missing with fallback:** bcmath in the running image (unneeded).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.7 (PHPUnit 12) for PHP; Vitest 5.1 + axe for Vue |
| Config file | `phpunit.xml`, `tests/Pest.php`, `vitest.config.js` |
| Quick run command | `docker compose -p fh05NN run --rm app ./vendor/bin/pest tests/Feature/Finance/<File>.php` / `npx vitest run tests/js/finance/<file>` (targeted per task, project name per Phase 4 convention) |
| Full suite command | `docker compose -p fh05NN run --rm app ./vendor/bin/pest && npx vitest run && npm run build` (final task of each plan) |

### Phase Requirements to Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| FIN-01 | Create/edit/delete income, expense, transfer; scoped validation | feature | `pest tests/Feature/Finance/TransactionTest.php` | Wave 0 |
| FIN-02 | Parse/format round-trip per currency, bounds, CHECK/FK rejects, no-float scan | unit + feature | `pest tests/Unit/Finance/MoneyTest.php` (seeded 1000-case property loop), `tests/Unit/Finance/NoFloatScanTest.php` | Wave 0 |
| FIN-03 | Differential, canary, query-log, arch, write parity, matrix | feature/arch | `pest tests/Feature/Finance/AccountPrivacyTest.php tests/Unit/Arch/FinancePrivacyArchTest.php tests/Feature/Access/EndpointMatrixTest.php` | Wave 0 |
| FIN-04 | Occurrence window incl. 29-31, leap, DST; edit effective-from | unit | `pest tests/Unit/Finance/OccurrenceWindowTest.php` | Wave 0 |
| FIN-05 | Matcher scoring, greedy uniqueness, idempotent re-run, unmatch rejection, statuses, undo reverts | unit + feature | `pest tests/Unit/Finance/MatcherTest.php tests/Feature/Finance/BillStatusTest.php` | Wave 0 |
| FIN-06 | Budget versioning, progress thresholds, month boundary, currency grouping | feature | `pest tests/Feature/Finance/BudgetTest.php` | Wave 0 |
| FIN-07 | niceTicks, chart a11y (axe, table alternative), SK/PL/EN light/dark, no-store | vitest | `npx vitest run tests/js/finance/` | Wave 0 |
| FIN-08 | Decode (1250/8859-2/UTF-8/UTF-16), parse variants, mapping modes, dedupe/ordinal, probable duplicate, commit idempotent, undo states, caps, no file persisted | unit + feature | `pest tests/Unit/Finance/Import/ tests/Feature/Finance/ImportTest.php` | Wave 0 |

### Sampling Rate
- **Per task commit:** the task's targeted Pest/Vitest command.
- **Per wave merge:** all `tests/Feature/Finance`, `tests/Unit/Finance`, `tests/Unit/Arch`, the EndpointMatrix test, and `npx vitest run tests/js/finance`.
- **Phase gate:** full suite green before `/gsd-verify-work`; leak suite and matrix included.

### Wave 0 Gaps
- [ ] `tests/Support/FinancePrivacy.php` (surfaces, scenario, capture, canary scan) and `tests/Support/CsvFixtures.php`
- [ ] `tests/Support/EndpointMatrix/finance*.php`, `tests/Unit/Arch/FinancePrivacyArchTest.php`
- [ ] factories (`database/factories`) for account, transaction, recurring, budget, profile, batch
- [ ] `tests/fixtures/finance/` generated files + README
- [ ] `tests/js/i18n-phase5.test.js` (key parity SK/PL/EN, pipe plurals) and a11y-sweep entries for finance pages
- [ ] Mutation sanity checks for the leak suite (prove it fails when a scope is removed), as Phase 4 did

## Security Domain

### Applicable ASVS Categories (Level 1)

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (Phase 2/6) | Existing Fortify; step-up deferred to Phase 6 |
| V3 Session Management | no | Existing; no-store on dynamic responses |
| V4 Access Control | yes | `access:module,finance,*` + `Visibility` scope + 404 parity + EndpointMatrix + leak suite; deny by default; admin has no money bypass |
| V5 Input Validation | yes | FormRequests, strict `MoneyParser`, allowlists (currency, date format, encoding, delimiter, amount mode), caps (size, rows, field length), scoped existence rules |
| V6 Cryptography | limited | `sha256` for dedupe hash only (not a secret); no hand-rolled crypto; DB at rest per infra |
| V7 Error/Logging | yes | Audit content-free; never log rows/amounts/descriptions; exceptions without data |
| V8 Data Protection | yes | Raw file never stored; no-store headers; SW never caches finance; backups cover DB only |
| V12 Files and Resources | yes | Server-side type checks (finfo + signatures, not client MIME), size limits, no storage write, temp cleanup, draft prune |
| V13/V14 API/Config | yes | CSRF on all writes (existing), throttle on import/commit/undo, security headers include snippet |

### Known Threat Patterns for Laravel + Inertia + Postgres finance

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| IDOR on account/transaction/batch ids | Information disclosure / Tampering | UUIDv7 keys, scoped resolvers, identical 404 for hidden and missing |
| Aggregate or count leakage | Information disclosure | Single scoped query layer, arch + differential + canary + query-log tests |
| Mass assignment of `account_id`/`amount_minor`/`import_batch_id` | Tampering | Non-fillable guarded columns; set via services with `forceFill` (repo convention) |
| CSV formula injection (export only) | Tampering | Do not build export; if built, prefix guard |
| Malicious/oversized upload (zip bomb, binary, huge rows) | DoS | 2 MiB/5000-row caps, signature checks, stream read, nginx limit, throttle |
| Encoding/delimiter confusion causing mis-imported amounts | Tampering | Explicit profile, decoded preview, strict parser, row errors, review before commit |
| Double submit / race on commit | Tampering | One DB transaction, unique hash index, `ON CONFLICT DO NOTHING`, idempotent commit |
| Finance data cached on shared devices | Information disclosure | `NoStoreDynamicResponses`, precache-only SW (test), no localStorage of finance data |
| Audit/Home/notification side channels | Information disclosure | `HomeSummary` through `Visibility`; recipients of any bill reminder filtered by account visibility |
| Prompt/other injection into descriptions | Tampering | Vue escaping only; never `v-html` for imported text (lint/test) |

## Sources

### Primary (HIGH confidence)
- Repository code read this session: `app/Access/*`, `app/Http/Middleware/RequireAccess.php`, `app/Audit/*`, `app/Recurrence/*`, `app/Models/Task.php`, `app/Wishlist/Money.php`, `resources/js/Wishlist/money.js`, `tests/Support/WishlistPrivacy.php`, `tests/Feature/Wishlist/ReservationPrivacyTest.php`, `tests/Unit/Arch/WishlistPrivacyArchTest.php`, `tests/Support/EndpointMatrix*`, `docker/app/Dockerfile`, `docker/nginx/snippets/laravel-locations.conf`, `routes/console.php`, `database/migrations/2026_11_15_100000_create_wishlist_tables.php`
- Probes in the running worker container (PHP 8.4 Alpine): `NumberFormatter` sk/pl/en output, iconv encodings, `mb_convert_encoding` failure on Windows-1250, `fgetcsv` deprecation, `createFromFormat` overflow, `Normalizer` behaviour, ini limits, missing `bcadd`
- Node 22.23.5 `Intl.NumberFormat` probes (sk-SK, pl-PL, en-GB; EUR/PLN/HUF/CZK/JPY/BHD)
- `.planning/phases/05-finance/05-CONTEXT.md`, `.claude/skills/sketch-findings-family-hub/references/finance.md`, `.planning/sketches/006-finance-module/README.md`

### Secondary (MEDIUM confidence)
- mBank ELIXIR format documentation (code pages ISO 8859-2, Windows-1250, CP852) via web search: https://www.mbank.pl/indywidualny/konta/pytania-i-odpowiedzi/platnosci-elixir/
- UniCredit Bank Slovakia merchant statement guide (CSV `;`, WINDOWS CP1250, CRLF): https://www.unicreditbank.sk/content/dam/cee2020-pws-sk/sk-dokumenty/Merchant_vypisy.pdf
- KBSK EDI format (windows-1250): https://www.kb.cz/getmedia/4b10714c-c8d7-4944-ab45-1e0922c72dc2/KBSK_format_edi_best_SK.pdf

### Tertiary (LOW confidence)
- Third-party Freenance import guides (mBank history export variants, 1250 vs UTF-8): https://freenance.io/poradniki/jak-importowac-transakcje-z-banku/ ; GitHub gist for the `#Data operacji` header marker. Neither is authoritative; no real bank CSV layout was verified.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH (nothing new; all verified in repo/container)
- Architecture (visibility, money, matching, import flow): MEDIUM-HIGH (derived from verified repo patterns; design choices flagged as assumptions)
- Pitfalls: HIGH for the probed ones (ICU data, iconv/mbstring, escape deprecation, date overflow, Normalizer, bcmath), MEDIUM for bank-format realities
- Bank-specific CSV layouts: LOW (no samples; generic profiles only)

**Research date:** 2026-11-13
**Valid until:** 2026-12-13 (stable domain; re-check if the Docker base image or Laravel major changes)
