---
phase: 05-finance
reviewed: 2026-11-13T00:00:00Z
depth: deep
files_reviewed: 62
files_reviewed_list:
  - app/Finance/Viewer.php
  - app/Finance/Visibility.php
  - app/Finance/Currency.php
  - app/Finance/MoneyParser.php
  - app/Finance/MoneyFormat.php
  - app/Finance/TextNormalizer.php
  - app/Finance/FinancePage.php
  - app/Finance/HomeSummary.php
  - app/Finance/Queries/AccountQuery.php
  - app/Finance/Queries/AccountAdminQuery.php
  - app/Finance/Queries/TransactionQuery.php
  - app/Finance/Queries/CategoryQuery.php
  - app/Finance/Queries/BudgetQuery.php
  - app/Finance/Queries/OverviewQuery.php
  - app/Finance/Queries/RecurringQuery.php
  - app/Finance/Queries/MatchQuery.php
  - app/Finance/Queries/ImportQuery.php
  - app/Finance/Queries/DisplayKind.php
  - app/Finance/Transactions/TransactionWriter.php
  - app/Finance/Transactions/TransactionPresenter.php
  - app/Finance/Accounts/AccountAdmin.php
  - app/Finance/Categories/CategoryWriter.php
  - app/Finance/Budgets/BudgetWriter.php
  - app/Finance/Recurring/Matcher.php
  - app/Finance/Recurring/OccurrenceWindow.php
  - app/Finance/Recurring/RecurringMatchService.php
  - app/Finance/Recurring/RecurringWriter.php
  - app/Finance/Recurring/StatusResolver.php
  - app/Finance/Recurring/Days.php
  - app/Finance/Import/Decoder.php
  - app/Finance/Import/CsvReader.php
  - app/Finance/Import/DateParser.php
  - app/Finance/Import/ProfileSpec.php
  - app/Finance/Import/RowNormalizer.php
  - app/Finance/Import/ImportHasher.php
  - app/Finance/Import/ImportStager.php
  - app/Finance/Import/ImportCommitter.php
  - app/Finance/Import/ImportUndo.php
  - app/Http/Controllers/Finance/AccountsController.php
  - app/Http/Controllers/Finance/BillController.php
  - app/Http/Controllers/Finance/BudgetController.php
  - app/Http/Controllers/Finance/CategoryController.php
  - app/Http/Controllers/Finance/ImportController.php
  - app/Http/Controllers/Finance/OverviewController.php
  - app/Http/Controllers/Finance/RecurringController.php
  - app/Http/Controllers/Finance/TransactionController.php
  - app/Http/Controllers/Settings/FinanceAccountsController.php
  - app/Http/Requests/Finance/*.php (9 files)
  - app/Http/Requests/Settings/FinanceAccountRequest.php
  - app/Http/Middleware/RequireAccess.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - app/Console/Commands/PruneFinanceImports.php
  - app/Console/Commands/StagingAudit.php
  - database/migrations/2026_11_16_*_finance_*.php (4 files)
  - routes/web/finance*.php, routes/web/settings-finance-accounts.php
  - docker/nginx/snippets/laravel-locations.conf
  - config/finance.php
  - resources/js/Finance/money.js, charts/niceTicks.js, labels.js, financeQuery.js, import/importApi.js
  - resources/js/Pages/Finance/Import.vue
  - tests/Support/FinancePrivacy.php, tests/Feature/Finance/FinanceLeakSuiteTest.php, FinancePrivacyTest.php
findings:
  critical: 1
  warning: 13
  info: 9
  total: 23
status: issues_found
---

# Phase 5: Code Review Report

**Reviewed:** 2026-11-13
**Depth:** deep
**Files Reviewed:** 62 (groups listed above)
**Status:** issues_found

## Summary

The visibility design holds up. I traced every route for scoping on reads and writes. No route-model binding is used, so no binding-order problem exists. Hidden and unknown ids go through one scoped lookup and return one status. `DisplayKind`, the transfer handling, budgets, overview, charts and currency blocks all aggregate only over `finance_account_members` for the viewer. I found no raw SQL that carries user input. The only interpolated alias is hard-coded. Money is integer-only end to end. The CHECKs and composite FKs in the migrations match what the code assumes. I found no `v-html`, no formula-prone export, and no float arithmetic.

I could not find a way for a member to read or infer hidden-account money data through the finance routes. The problems are elsewhere:

- one silent money-loss bug in import dedupe (CR-01);
- two sanctioned ways to cross the privacy boundary on the write side or via family-wide stores (WR-01, WR-02, WR-03);
- data-integrity gaps around partially visible transfers and account currency changes;
- an exception path that can write CSV row content to the log;
- weak spots in the leak-suite machinery that make its "proof" weaker than its comments claim.

## Critical Issues

### CR-01: Import dedupe hash for rows with a `reference` ignores date and amount, so distinct transactions are silently dropped

**File:** `app/Finance/Import/ImportHasher.php:21-25` (consumer: `app/Finance/Queries/ImportQuery.php` `commitRows`, ON CONFLICT DO NOTHING)
**Issue:** When a row has a reference, the hash is `sha256(account|ref|reference)`. Date, amount and description are not part of it, and the ordinal rule is applied only to rows without a reference. Bank "reference" columns are often not unique: variable symbols on monthly rent, card-terminal ids, "0000". Two consequences:
1. Two different rows in the same file with the same reference get the same hash. Both are staged as `new` and ticked. `commitRows` inserts them in one statement with `ON CONFLICT (account_id, import_hash) DO NOTHING`, so the second row is discarded without error. The user sees "added N-1" or just a count and loses a real transaction.
2. A later statement containing a new payment with a recycled reference is flagged `duplicate` against the old row and not selected by default. The user is told it already exists.

Either way money rows vanish with no error.
**Fix:** Hash the full identity and keep the ordinal for exact repeats:
```php
$ref  = $row->reference !== null && $row->reference !== '' ? $row->reference : '';
$key  = $row->bookedOn.'|'.$row->amountMinor.'|'.($ref !== '' ? 'ref:'.$ref : $row->normalizedDescription);
$seen[$key] = ($seen[$key] ?? 0) + 1;
$out[] = $row->withHash(hash('sha256', $accountId.'|'.$key.'|'.$seen[$key]));
```
Also have `commit()` compare `count($inserted)` with `count($picked)` and tell the user how many were skipped as already present.

## Warnings

### WR-01: Family-wide import profiles store free-text column names that are not validated against the uploaded header, so row data can leak to members who cannot see the account

**File:** `resources/js/Pages/Finance/Import.vue:197`, `app/Finance/Import/ProfileSpec.php:62-75`, `app/Http/Controllers/Finance/ImportController.php:48-52`
**Issue:** `columns[role].name` is taken from whatever the client sends (up to 200 chars). The server never checks it against the header row of the uploaded file. `guessHeaderRow` returns the first widest row. For a file without a header that row is a data row, so choosing "has header" stores the first transaction's date, amount and description as column names. The saved profile (and `profile_name`, indicator tokens) is then listed to every member with finance edit access (`ImportController::index`, `$imports->profiles()`), regardless of account membership. Header text such as "IBAN partnera" or a profile name such as "Mum's ING" also tells a member that another account or bank exists. The comment "no account id, no money data" is therefore not enforced.
**Fix:** When saving a profile, accept a `name` only if it equals (case-insensitive) a cell of the uploaded file's header row and does not parse as a date or amount. Otherwise store `index` only. Cap indicator tokens to a short alphabetic allowlist. Consider showing profiles only to their creator, or only for accounts the viewer is a member of.

### WR-02: `CategoryQuery::reassign` lets any finance editor rewrite categories and budget history on accounts hidden from them

**File:** `app/Finance/Queries/CategoryQuery.php:73-85`; the allowlist in `tests/Feature/Finance/FinanceLeakSuiteTest.php:41` (`FH_LS_ALLOWLISTED_WRITES`)
**Issue:** It is documented as intended, but it is a write across the privacy boundary. An editor with no access to account B can delete "groceries" with target "other" and irreversibly re-categorise every transaction, recurring item and draft import row on B. The delete also rewrites or deletes budget versions in currencies that editor cannot see. Budget versions of the deleted category are moved into the target's timeline, which can change when a hidden-currency budget becomes active. Nothing is audited, because `updated_at` and `edited_at` are deliberately untouched. It is not a read oracle, since the actor learns nothing back. It is still data tampering, and the leak suite explicitly exempts these routes from the scoped-mutation check.
**Fix:** Restrict category delete-with-move to admins and write a content-free audit entry (`finance_category_deleted`). Alternatively make delete refuse when the category is referenced anywhere (this needs a non-leaking message) and offer only rename. If the family-wide behaviour stays, keep it out of the "viewer privacy" claims in the docs.

### WR-03: Admins can enumerate hidden accounts and add themselves. The audit entry cannot say which account, and the read is not audited

**File:** `app/Finance/Queries/AccountAdminQuery.php:20-37` (`metadata()`), `app/Http/Controllers/Settings/FinanceAccountsController.php:34-66`, `app/Finance/Accounts/AccountAdmin.php:44-55`, `app/Audit/AuditEvent.php:50`
**Issue:** `Settings > Finance accounts` (`access:admin` only) returns name, type, currency and member ids of every account to any admin. `Viewer` says "no admin branch", but an admin can read hidden account names and member lists, then tick themselves and gain full read and write on that account. That route is outside the `finance` module, so `RequireAccess` writes no `finance_read` row. The only trace is `FinanceVisibilityChanged` with `meta = {change: added}`, a target user and no account reference. A later review cannot tell which account was exposed.
**Fix:** Add an opaque `account_id` (a UUID, not content) to the allowed meta keys of `FinanceVisibilityChanged` and record it in `syncMembers`. Audit the settings-page read as a finance read. If admin enumeration is intended, say so in the privacy statement.

### WR-04: Editing or deleting one leg of a transfer whose counterpart is hidden desynchronises the transfer

**File:** `app/Finance/Transactions/TransactionWriter.php:44-66, 69-76`; `app/Http/Requests/Finance/TransactionRequest.php:~190-215`
**Issue:** When the counterpart is invisible (for example after an admin removes a member from one account), the leg is treated as "an ordinary entry". Changing the amount, or deleting it, leaves the hidden leg in place. The two legs no longer sum to zero. The hidden account's members then see a transfer leg with no partner. DisplayKind turns it into a plain income or expense for them, and their balance no longer reconciles with the other side. The tests prove the response is byte-identical to a plain entry, which is the design. The integrity effect on the hidden account is not covered anywhere. The DB has no constraint on transfer pairing (at most two legs, opposite signs, equal magnitude, different accounts).
**Fix:** Refuse amount, date and delete changes on a leg that has a hidden counterpart (return the same validation message as for any immutable field). Allow only description, note and category. If edits must stay allowed, add a deferred constraint trigger or a reconciliation job so a mismatch is detected.

### WR-05: Changing an account's currency or opening date is not blocked by recurring items, budgets or draft imports that depend on it

**File:** `app/Http/Requests/Settings/FinanceAccountRequest.php:136-149`, `app/Finance/Queries/AccountAdminQuery.php:110-117`
**Issue:** `currency_locked` considers only transactions. An account with no transactions but with recurring items (`expected_amount_minor`, `tolerance_minor` in the old currency) or open draft batches can still change currency. The recurring amounts are silently reinterpreted. A draft batch staged in the old currency is committed later with the new currency taken from `a.currency` at commit time. Moving `balance_as_of` later (only checked against the earliest transaction) can make staged rows pre-date the opening date.
**Fix:** Include `finance_recurring` rows, `finance_recurring_matches` and open drafts in the lock check, or delete drafts when currency or opening date changes. Re-validate `balance_as_of` at commit.

### WR-06: Recurring due dates stop after ten years from `starts_on`

**File:** `app/Recurrence/RRuleRecurrenceEngine.php:21,90-91` (called from `app/Finance/Recurring/OccurrenceWindow.php`)
**Issue:** `between()` caps the horizon at `start + MAX_YEARS` regardless of the requested window. A bill created with a real original date (mortgage 2012, insurance 2014) yields no occurrences today. It disappears from the calendar, Still to pay and Home counts, with no message. The request accepts any `starts_on` from 1900 onward. The same function also forces the anchor date into the result even when the rule would not produce it (for example a weekly rule that starts on a different weekday).
**Fix:** Compute the horizon from `max($fromDate, $start)` as `next()` does. Or reject `starts_on` older than the horizon with a validation error. Do not add the anchor date unless it matches the rule.

### WR-07: `throttle:120,1` and `throttle:60,1` on transaction update and delete have no prefix, so they share one bucket with each other and with other bare throttles

**File:** `routes/web/finance-transactions.php:11,13`
**Issue:** Laravel builds the key as `prefix + sha1(user id)`. Every other finance route passes a third argument. These two, plus the bare throttles in `notifications.php`, share one counter per user. Edits count against the 60/min delete limit, and a few `notifications.push.test` hits count against both.
**Fix:** Use `throttle:120,1,finance.transactions.update` and `throttle:60,1,finance.transactions.destroy`.

### WR-08: Finance pages are stored unencrypted in browser history and not cleared on logout

**File:** `config/inertia.php:155` (`INERTIA_ENCRYPT_HISTORY` defaults to false and is not set anywhere), logout flow
**Issue:** Inertia keeps page props (balances, transactions, import rows) in `history.state`. On a shared family tablet, pressing Back after logout can restore a finance page without a server request. This contradicts the CLAUDE.md rule not to leave authenticated finance data on shared devices. `no-store` headers do not cover history state.
**Fix:** Set `'history' => ['encrypt' => true]` (or `Inertia::encryptHistory()` in the finance routes) and call `Inertia::clearHistory()` on logout and session end.

### WR-09: A database error during staging or commit writes the CSV rows to the application log

**File:** `app/Finance/Queries/ImportQuery.php:70-100` (`insertBatchWithRows`) and `commitRows`; `bootstrap/app.php` (no `dontReport` or sanitising)
**Issue:** The code comments say nothing logs row content. But Laravel's `QueryException` message embeds the SQL with its bindings substituted. For these statements that means the whole JSON payload of all staged rows (dates, amounts, descriptions, counterparties, references). A deadlock, a constraint failure, or a malformed UTF-8 case reaches the default exception reporter and lands in `storage/logs`. This breaks the "raw file never persisted" claim.
**Fix:** Wrap the stage and commit transactions and rethrow a sanitised `ImportError('db')`, discarding the previous exception from the log. Or register `$exceptions->reportable(fn (QueryException $e) => ...)` that strips bindings for `finance_import_*` statements.

### WR-10: Auto-match accepts any amount when a text hint matches, and hints have no minimum length

**File:** `app/Finance/Recurring/Matcher.php:34-42`, `app/Http/Requests/Finance/RecurringRequest.php` (`text_hint` max 120 only)
**Issue:** `autoAccepts` returns true for any in-window candidate whose normalised description contains the hint, whatever the amount. A one- or two-character hint (for example "a") matches almost every expense in the window and pairs it to the bill ("paid differently"). Matches are greedy and unique, so a wrong pairing also blocks the right transaction.
**Fix:** Require a minimum normalised hint length (for example 4) and a sanity bound on the amount for hint-only matches, or demand the hint plus an amount within a configurable percentage.

### WR-11: `recordPayment` deletes any existing match first and takes no lock, so a double submit creates two payments

**File:** `app/Finance/Recurring/RecurringMatchService.php:140-146`
**Issue:** The request checks `bill['match'] === null`, then the service creates a transaction, calls `deleteMatch`, and inserts the match. Two concurrent requests both pass the check. The second replaces the first match, leaving the first expense unmatched and a duplicate expense booked. The throttle only limits rate.
**Fix:** Lock the recurring item (`lockForUpdate`) inside the transaction, re-check that no match exists, and drop the `deleteMatch` call (let the unique `(recurring_id, due_date)` constraint fail the second one).

### WR-12: The leak-suite scope checks prove less than they claim

**File:** `tests/Support/FinancePrivacy.php:291-325` (`unscopedReads`, `unscopedMutations`), constants at lines 36-44, `tests/Feature/Finance/FinanceLeakSuiteTest.php:41`
**Issue:**
1. A statement counts as scoped if the text `finance_account_members` appears anywhere in it. It does not check that the membership is `user_id = viewer`, or that it constrains the table being read or written. A query that joins the members table for another purpose, or a subselect scoping a different table, passes.
2. Plain `INSERT` statements are never treated as mutations, and the helper's own self-test asserts that. A write into a hidden account's rows is therefore only caught by per-feature tests, not by the net.
3. `finance_accounts`, `finance_account_members`, `finance_categories`, `finance_import_profiles` and `finance_budgets` are in neither list. Budget reads are unchecked.
4. The categories routes are allowlisted out of the mutation check. There is no test that `reassign` changes only `category_id` on hidden rows.
5. The mutation sanity test registers three mutants (count, sum, one UPDATE). There is no mutant for a missing hidden-counterpart scope in `DisplayKind`, for a body-id write into a hidden account, or for an unscoped INSERT. A regression of those kinds would pass the net.
**Fix:** Match the full predicate, for example `/finance_account_members.*user_id\s*=\s*\?/s`, per table. Treat `INSERT ... SELECT` and `INSERT` into a table with an `account_id` as a mutation that needs a visible-account proof. Add the missing tables. Add mutants for DisplayKind scope, hidden `account_id` on create, and an unscoped INSERT. Add a test that `reassign` leaves every other column of hidden rows unchanged.

### WR-13: `CP852` is offered but `iconv` on the Alpine image may not support it

**File:** `config/finance.php:8`, `app/Finance/Import/Decoder.php:74`, `docker/app/Dockerfile:6` (`php:8.4-fpm-alpine`)
**Issue:** PHP on Alpine uses musl `iconv` unless `gnu-libiconv` is installed. musl does not implement CP852 (I could not run the production image to confirm, so verify this). The tests run on the host's glibc, so they would not notice. If it is missing, choosing CP852 always yields `decode_failed`.
**Fix:** Add a test that runs inside the production image for every encoding in the allowlist. Install `gnu-libiconv` with `LD_PRELOAD`, or drop the option, or convert via `mb_convert_encoding` for the supported set.

## Info

### IN-01: BOM stripping with `ltrim` treats the BOM as a byte set

**File:** `app/Finance/Import/Decoder.php:83`
**Issue:** `ltrim($text, "\u{FEFF}")` removes any leading bytes EF, BB or BF. A file whose first character starts with byte EF (U+F000-U+FFFF, such as fullwidth forms) loses its lead byte and becomes invalid UTF-8. `json_encode(..., JSON_THROW_ON_ERROR)` in the stager then raises an exception that is not an `ImportError`.
**Fix:** `$text = preg_replace('/^\x{FEFF}+/u', '', $text) ?? $text;`

### IN-02: The comment "values are bounded by 1e13" is wrong for chart balances

**File:** `resources/js/Finance/charts/BalanceChart.vue:14,53`
**Issue:** Each transaction is bounded by 1e13, but a running balance is a sum, so it is not. About 900 maximum-size rows already pass 2^53. The `Number()` conversion for pixel placement then loses precision. This is academic at family scale. The comment is simply incorrect.
**Fix:** Correct the comment, or place marks from BigInt-derived ratios as `SpendingByCategoryChart` does.

### IN-03: Staged `row_no` is the index in the blank-line-filtered list, not the file line

**File:** `app/Finance/Import/RowNormalizer.php:48`, `app/Finance/Import/CsvReader.php`
**Issue:** `fgetcsv` skips empty lines, and multi-line quoted cells span lines. The "row N" shown for an invalid row does not match what the user sees in their editor.
**Fix:** Track the physical start line per record, or label the number "record N".

### IN-04: Missing defence-in-depth foreign keys

**File:** `database/migrations/2026_11_16_120000_create_finance_import_tables.php`
**Issue:** `finance_import_rows.probable_transaction_id` and `finance_transactions.import_batch_id` and `reconciled_batch_id` are single-column FKs. `batchRows` joins `finance_transactions` on `probable_transaction_id` without the membership scope. Today the id comes only from `manualCandidates` for the same account. If a future code path wrote another account's id, a hidden description and amount would be read back. A composite FK `(probable_transaction_id, account_id)` would remove that class of bug.
**Fix:** Add `account_id` to `finance_import_rows` (or join through the batch) with composite FKs, as done for recurring matches.

### IN-05: `staging:audit` skips `finance_categories` and `finance_match_rejections`

**File:** `app/Console/Commands/StagingAudit.php:34-45`
**Issue:** Custom category names are free text typed by real users and are family-wide. They are not checked. A staging database seeded from real data would pass the audit with real category names.
**Fix:** Add a rule for custom categories (for example require a synthetic marker prefix on staging), or document that categories are excluded.

### IN-06: Audit rows are written before the throttle, and every GET (including partial reloads) writes one

**File:** `app/Http/Middleware/RequireAccess.php:64-84`, route middleware order in `routes/web/finance*.php`
**Issue:** `access` runs before `throttle`, so throttled 429 requests still insert an audit row. GET routes have no throttle. Infinite-scroll partial reloads and every preview POST re-send add one row each. The entries are content-free but unbounded, and the insert is synchronous. The table is pruned only at two years.
**Fix:** Put the throttle before `access` for writes. Coalesce reads (one `finance_read` per user per minute) or skip partial Inertia reloads.

### IN-07: "The file is never persisted" is true for the app but not for nginx and PHP temp buffering

**File:** `docker/nginx/snippets/laravel-locations.conf:54-58`, `app/Http/Controllers/Finance/ImportController.php:221-227`
**Issue:** Uploads up to 3 MB are buffered by nginx under `client_body_temp_path` and by PHP under the upload temp dir, then deleted at the end of the request. A crash in between can leave a copy. The docs should state this. For tighter control, point both temp dirs to a tmpfs.
**Fix:** Mount tmpfs for `client_body_temp_path` and `upload_tmp_dir`, and adjust the comments.

### IN-08: Budget progress under a single-account filter compares one account's spend to a family-wide limit

**File:** `app/Finance/Queries/BudgetQuery.php:34-78`
**Issue:** With `?account=X`, `limitMinor` is the family-wide budget and `spentMinor` counts only account X. The percentage and "over" state are therefore misleading. This is not a leak.
**Fix:** Hide budgets when a single account is selected, or label the figure as "this account's share".

### IN-09: Invalid UTF-8 in a search or text field causes a 500

**File:** `app/Http/Controllers/Finance/TransactionController.php:~95` (`q` filter), `TransactionRequest` text fields
**Issue:** `mb_substr` leaves invalid bytes in place, and Postgres rejects the statement (error 22021). `GET /finance/transactions?q=%FF` returns a 500, and each request also writes a `finance_read` audit row.
**Fix:** Validate with `mb_check_encoding($q, 'UTF-8')` and treat a bad value as empty. Add the `string` rule plus an encoding check to the shared request prepare step.

---

_Reviewed: 2026-11-13_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
