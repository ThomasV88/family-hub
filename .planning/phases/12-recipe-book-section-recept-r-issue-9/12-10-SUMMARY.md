---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 10
subsystem: recipes-ui-shopping-bridge
tags: [recipes, shopping, vue, dialog, catalog-matching, i18n, manual, help]
requires: ["12-06", "12-09"]
provides:
  - "Components/Recipes/AddToShoppingDialog.vue: review dialog (preview on open, new or open list, servings, per-row review, add-up hints, all-or-nothing commit)"
  - "Components/Recipes/shoppingQuantity.js: toShopping, convertTo, formatForInput (pure)"
  - "Show.vue: Add to shopping list button in the header (wide) and the phone bar, dialog mounted once"
  - "lang recipes.shopping.* and recipes.toast.items_added (en, sk, pl)"
  - "help screen recipe_shopping, topic recipes.shopping"
  - "manual section shopping (recipes topic)"
affects: [12-17]
key-files:
  created:
    - resources/js/Components/Recipes/AddToShoppingDialog.vue
    - resources/js/Components/Recipes/shoppingQuantity.js
    - tests/js/recipes/shoppingQuantity.test.js
    - tests/js/recipes/AddToShoppingDialog.test.js
  modified:
    - resources/js/Pages/Recipes/Show.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/manual/en/recipes.json
    - resources/manual/sk/recipes.json
    - resources/manual/pl/recipes.json
    - tests/js/recipes/Show.test.js
    - tests/js/i18n-phase12.test.js
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 10: Add-to-shopping review dialog Summary

Members add a recipe's ingredients to a new or an open shopping list after a row-by-row review: catalog matches with picture (changeable), servings rescaling, g/kg and ml/l add-up with lines already on the list, and an all-or-nothing commit through the 12-06 endpoints.

## Commits
- a011fa1 shopping quantity helper with table tests (`Refs #9`)
- 3246c6d dialog, button wiring in Show.vue, strings, help tip, manual section, dialog and Show tests, i18n-phase12 subtrees (`Refs #9`)

## Behaviour
- **Preview** (`GET /recipes/{id}/shopping` through `securityFetch`) on every open, with a ticket so a late answer of an earlier open is ignored. Skeleton rows meanwhile. 403 and 404 close the dialog and report to the page (`failed`: toast, 404 shows the unavailable state, 403 reloads). Any other failure (5xx, 429, dropped connection) shows the warn alert and continues with typed rows built from the recipe props to a new list.
- **Target**: Open list (default, the most recently changed one) when the member has one, else New list; Open list is disabled with the hint when there is none. New list: name defaults to the recipe title (clipped by characters to 80), optional store.
- **Rows**: include checkbox, name, quantity, unit, match tag (Matched / Suggested: {name} / Not in catalog), catalog thumbnail and name, ghost Change (search plus select of catalog items, first option "Add as typed item"). No amount: 1 pcs with the hint. Servings control copy rescales every row the member has not edited; typed quantities are kept.
- **Add-up**: pending lines of the chosen list with the same catalog item. Same unit: "Already on the list: {qty}. It will be added up." g/kg and ml/l: the row is converted to the line's unit, only when the round trip is lossless (5 g next to a kg line is not turned into 0.01 kg); otherwise "Different unit: kept as a separate line."
- **Submit**: JSON `{target, list_id | name + store_id, items:[{catalog_item_id | name, quantity, unit_id}]}`. Guarded against double submit, not dismissable while sending. Success closes, toast "{n} items added to {list}" with the Open list action (`router.visit('/shopping/lists/{id}')`). 409: locked message, Open list radio selected, list select focused. 404: list gone (existing) or recipe not available (new). 403/419/429 use the recipe texts. 422: first known message (for example the 500-items limit), else the generic "Nothing was added". 5xx or network: generic message, rows intact. No raw lang key can reach the screen.

## Tests
- Vitest new: shoppingQuantity (25), AddToShoppingDialog (43 incl. axe in en, sk, pl, markup in names, emoji cut, late answer, 409 focus, double submit), Show (+5 button and wiring cases). Targeted run (tests/js/recipes, i18n-phase12, manual, help): 22 files, 530 tests passed. Full Vitest: 183 files, 5937 tests passed (includes a11y sweep, ManualCoverage, HelpCoverage, i18n-phase12).
- Pest in isolated compose project `fh1210` (own Postgres, scratch folder over storage/logs), serial: `tests/Feature/Manual` and `tests/Feature/Help` 49 passed. Full serial Pest: 3640 passed, 1 skipped. Project and volumes removed with `down -v`. No PHP file touched, so Pint was not needed.

## User documentation
Touched `resources/manual/` files (sk and pl are DRAFTS needing native review): `resources/manual/en/recipes.json`, `resources/manual/sk/recipes.json`, `resources/manual/pl/recipes.json` (new section `shopping` between `notes-favourites` and `deleting`, same block shapes in all three; topic summary extended).
Touched `resources/help/` files: `topics.json` (screen `recipe_shopping`, area `recipes`, field `dialog_title`), `en.json`, `sk.json`, `pl.json` (screen name and topic `recipes.shopping`, each under 400 characters). Placement: next to the review hint line in the dialog (UiDialog takes a plain-string title).
Also for native review: every new `recipes.shopping.*` key and `recipes.toast.items_added` in `lang/sk.json` and `lang/pl.json` (plural forms follow the 4-form sk/pl convention of Phase 12).

## Deviations from Plan
- **[Rule 3] Dialog size without touching UiDialog**: the shared dialog is 480 px and has no size option. The component adds an unscoped style block matched with `.ui-dialog:has(.rec-shop)` (centred 720 px, 80vh, body scrolls, footer stays in view, full-height sheet on a phone), so no other dialog changes. Not verified visually in a real browser (jsdom only).
- **Unit of a row with an amount but no unit** is pcs (not the catalog default), so "3 eggs" becomes 3 pcs; rows without an amount are 1 pcs as the plan says. `suggestedUnitId` of the preview is therefore unused by the dialog.
- **Names of matched rows**: editing the name of a Matched row does not change the match (the name is only sent when the row is a typed item); a typed row needs a name, a matched row does not.
- **Phone**: the header button is hidden on a phone (the bar button is the one), per UI-SPEC.
- A quantity above 999.99 shows `shopping.error.quantity_invalid` ("above 0 and below 1000"), anything else invalid shows `shopping.error.quantity`.

## Known Stubs
None. The two `data-slot` spans of 12-09 now hold the buttons.

## Threat Flags
None beyond the plan's threat model: T-12-38 (all names interpolated as text; a test renders markup in recipe title, list, store, catalog and ingredient names), T-12-39 (submit guard, tested with two clicks and one POST; server transaction from 12-06), T-12-40 accepted (server re-checks under the list lock).

## Self-Check: PASSED
Commits a011fa1 and 3246c6d exist; all created files present.
