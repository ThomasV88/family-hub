---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 13
subsystem: shopping-mode-ui
tags: [shopping, vue, lock, marks, finish, carry-over, help, manual]
requires: ["11-03", "11-05", "11-11", "11-12"]
provides:
  - "Shopping/MarkButtons.vue, ShoppingProgress.vue, StickyActionBar.vue, FinishShoppingDialog.vue; ShoppingItemRow markable mode"
  - "Show.vue: Lock and Unlock with confirmations, optimistic marks with rollback, progress, Finish, archived state"
  - "Lang shopping.lock.*, mark.*, finish.*, archived.*, progress.*, error.{lock_empty,finish_failed,mark_failed}, toast.{locked,unlocked,finished,finished_carry}"
  - "Help topics shopping.lock (screen shopping_list) and shopping.carry_over (screen shopping_finish); manual sections lock-and-shop and finish"
affects: [11-14]
key-files:
  created:
    - resources/js/Shopping/MarkButtons.vue
    - resources/js/Shopping/ShoppingProgress.vue
    - resources/js/Shopping/StickyActionBar.vue
    - resources/js/Shopping/FinishShoppingDialog.vue
    - tests/js/shopping/ShowShoppingMode.test.js
    - tests/js/shopping/FinishShoppingDialog.test.js
  modified:
    - resources/js/Pages/Shopping/Show.vue
    - resources/js/Shopping/ShoppingItemRow.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/manual/en/shopping.json
    - resources/manual/sk/shopping.json
    - resources/manual/pl/shopping.json
    - tests/js/i18n-phase11.test.js
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 13: Shopping mode Summary

The responsible person can lock a list, mark each item Bought or Unavailable with large buttons that update at once and roll back on failure, and finish the shopping, with the owner's carry-over question creating an open "{name} (unbought)" list from the unavailable items; everyone else sees a read-only list.

## Commits
- 86126d1 lock and unlock, marks, progress, sticky bar, finish dialog, archived state, lang, help, manual, tests (`Refs #8`). Tasks 1 and 2 are one commit as the plan requires (the `shopping.lock` tip, lang and the coverage tests only pass together).

## Behaviour
- Lock (secondary button, `abilities.lock`) is disabled on an empty list with the hint "Add at least one item before you lock the list."; others on an open list see "Only {name} can lock this list." Lock and Unlock open a non-destructive confirmation (accent button, own `UiDialog` because `UiConfirmDialog` is danger-styled). A server error (`lock_empty`) shows inside the dialog.
- Marks: `MarkButtons` (`role="group"` "Mark {name}", `aria-pressed`, 44 px, labels hidden visually below 360 px) send the absolute state with `router.put`; the override shows at once, any failure (validation, HTTP error, network) drops it and toasts "Could not save. Try again."; a second tap on the same item waits for its request. Rows keep their order.
- Progress "Bought x · Unavailable y · Left z"; `StickyActionBar` is fixed above the bottom navigation on phone ("{n} items left" with plural forms, then Finish shopping) and a right-aligned row on wide screens.
- Finish dialog: case A confirmation posts `carry_name: null`; case B shows the owner wording, up to 5 unavailable items plus "+n more", the note, and the buttons Create new list (localised name cut to 80 characters), No, just finish, Cancel. Failure keeps the dialog open with the danger alert; Escape is disabled while saving.
- Archived: banner, no actions, the menu holds only Copy list and Send by e-mail (the List settings entry is hidden for finished lists).

## Tests
Isolated compose project `fh1113` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. No PHP changed, so no Pint run was needed. Full Vitest: 165 files, 5383 tests passed (new ShowShoppingMode 30 and FinishShoppingDialog 16, including axe in en, sk, pl, light and dark with pseudo-long text; ManualCoverage, HelpCoverage, HelpPlacement, i18n-phase11 green). `tests/Feature/Manual`, `Help`, `Shopping`: 335 passed. Full serial Pest suite: 3265 passed, 1 skipped.

## User documentation
Manual: new sections `lock-and-shop` and `finish` (after `people`, before `copy`) in `shopping.json`, topic summary extended. Help: topics `shopping.lock` and `shopping.carry_over` (screens `shopping_list`, `shopping_finish`), texts under 400 characters. Touched files; sk and pl are drafts for native review:
- resources/manual/en/shopping.json, resources/manual/sk/shopping.json, resources/manual/pl/shopping.json
- resources/help/topics.json, resources/help/en.json, resources/help/sk.json, resources/help/pl.json

Also for native review: the `shopping.lock`, `mark`, `finish`, `archived`, `progress` subtrees (including the three-form plurals of `progress.left`), `shopping.error.{lock_empty,finish_failed,mark_failed}` and `shopping.toast.{locked,unlocked,finished,finished_carry}` in `lang/sk.json` and `lang/pl.json`.

## Deviations from Plan
- **Help tip placement:** `UiDialog` takes a plain-string title, so the `shopping.carry_over` tip sits beside the owner question inside the dialog (same as 11-12). `shopping.lock` sits in the hint line under the header (next to the empty-list hint, the "Only {name}" hint, or alone for the responsible person).
- **Sticky bar on wide screens** is a plain action row under the list rather than a header button, so there is one Finish button in the DOM; Lock and Unlock stay in the header.
- **Archived banner** now reads from `shopping.archived.banner` (same text as the older `shopping.detail.archived`, which stays unused in Show but defined).
- The `Finish shopping` confirmation (case A) is built into `FinishShoppingDialog` rather than a separate confirm component.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model. T-11-49: absolute-state requests, rollback on failure, per-item single flight; T-11-50: finish buttons disabled while saving, one request; T-11-51: mark buttons render only with `abilities.mark` (tests for contributor and viewer), the server stays authoritative.

## Self-Check: PASSED
