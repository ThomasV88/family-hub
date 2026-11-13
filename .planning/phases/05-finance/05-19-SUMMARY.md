---
phase: 05-finance
plan: 19
subsystem: finance
tags: [categories, laravel, inertia, vue, privacy]
requires: ["05-09", "05-10", "05-12"]
provides:
  - "Finance > Categories page with create, rename, delete-with-move (no usage counts)"
  - "CategoryQuery::reassign (documented unscoped write) and create/rename/remove/nameTaken"
affects: [finance]
tech-stack:
  patterns: ["family-wide non-sensitive list resolved through CategoryQuery::find, no visibility rule", "raw category_id-only UPDATE so edited_at and updated_at stay untouched"]
key-files:
  created:
    - app/Finance/Categories/CategoryWriter.php
    - app/Http/Controllers/Finance/CategoryController.php
    - app/Http/Requests/Finance/CategoryRequest.php
    - routes/web/finance-categories.php
    - tests/Feature/Finance/CategoryTest.php
    - tests/Support/EndpointMatrix/finance-categories.php
    - tests/Support/FinancePrivacy/categories.php
    - resources/js/Finance/CategoryPanel.vue
    - resources/js/Pages/Finance/Categories.vue
    - tests/js/finance/Categories.test.js
  modified:
    - app/Finance/Queries/CategoryQuery.php
    - tests/Unit/Finance/FinanceMutationScanTest.php
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
decisions:
  - "reassign() uses raw UPDATE statements that set category_id only; budgets: conflicting from-rows deleted, rest re-pointed"
  - "Draft import rows (finance_import_rows) are reassigned too so an open import keeps its categories"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 05 Plan 19: Family categories Summary

Family-wide category list: create, rename (defaults included, default_key kept) and delete with a required "Move to" target, with no usage counts in props, responses or copy.

## What was built

- **Backend.** Routes `finance.categories.{index,store,update,destroy}`, `CategoryRequest` (translation-key errors, move target must exist, same kind, not itself), `CategoryWriter` (delete = `reassign` then `remove` in one DB transaction), `CategoryController` (`?cat=` panel: unknown id 403, `new` without edit 403).
- **`CategoryQuery::reassign`** is the allowlisted unscoped write. Docblock starts with `Unscoped write:` and states (a) it can delete a from-budget row in a currency hidden from the actor when the target already has a budget for that (currency, effective_from), and (b) it must NOT touch updated_at or edited_at and changes nothing but category_id on hidden transactions and recurring rows (raw UPDATE).
- **UI.** `Categories.vue` (h1, breadcrumb to Finance, two locale-collated groups, no counts) and `CategoryPanel.vue` (name max 40, type chips on create only, delete dialog with required Move to select, default focus "Keep category").
- **Tests.** `CategoryTest` (9 cases): column-by-column proof that only `category_id` changes on visible and hidden transactions, recurring and budget rows (updated_at pre-aged); a later undo predicate (`edited_at IS NULL`) still deletes a moved imported row; PATCH and DELETE responses plus the follow-up Inertia and HTML pages are identical with and without hidden usage. Leak-net area file `categories.php` (`?cat=<id>`, `?cat=new`). Vitest 15 cases incl. axe in en/sk/pl, light and dark.

## Deviations from Plan

1. **[Rule 1 - Bug] FinanceMutationScanTest misused `toContain`.** `toContain('Unscoped write:', $message)` treats the message as a second needle, so any allowlisted method would fail it (it only passed because `reassign` did not exist yet; 05-18's `pruneDrafts` would hit the same). Replaced with `expect(str_contains(...))->toBeTrue($message)`. Commit 697b150.
2. **[Plan contract adjusted] 'Pets' category is created in the `visible` seed, usage only in `hidden`.** The plan said `hidden` seeds the category, but every finance page carries the family category list, so that would change bytes of the viewer's pages and break the leak net. Only the hidden usage (transactions, recurring item, PLN budget) is seeded as hidden.
3. **Acceptance grep vs instruction.** The plan's check `grep -n "edited_at" CategoryQuery.php` prints nothing; the explicit instruction to state in the docblock that `edited_at` is not touched makes it match that docblock line only. No code writes `edited_at`.
4. **[Rule 2] Draft import rows** (`finance_import_rows.category_id`) are reassigned in the same statement set; otherwise the FK `nullOnDelete` would silently uncategorise an open import. Also added `finance.cat.err_save` (en/sk/pl) for the panel's generic failure alert.
5. `lang/*.json`: committed only this plan's hunk; a sibling plan's uncommitted budget hunk in the same files was left untouched.

## Verification

Pint clean on own files; full Pest 1318 passed; full Vitest passed except Overview and Invitations axe tests that timed out under load (siblings' and older files) and passed on a single re-run; `npm run build` OK; compose project `fh0519` torn down.

## Known Stubs

None.

## Commits

- 697b150: backend (routes, request, writer, reassign, matrix, leak-net, tests, scan-test fix)
- d7fc8be: categories page, CategoryPanel, lang key, Vitest

## Self-Check: PASSED
