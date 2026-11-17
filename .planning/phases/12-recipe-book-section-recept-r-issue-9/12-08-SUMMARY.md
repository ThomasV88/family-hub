---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 08
subsystem: recipes-ui-form
tags: [recipes, vue, form, photos, quantities, toast-action, i18n, manual, help]
requires: ["12-04", "12-05", "12-07"]
provides:
  - "Pages/Recipes/Form.vue: create (JSON POST then photo uploads then detail) and edit (router.put with line ids)"
  - "IngredientRows, StepRows, CategoryField, RecipePhotosField components"
  - "quantityInput.js (parseQuantity, formatQuantityInput, quantityForRequest), ingredientPaste.js, recipePhotosApi.js, recipeFailure.js"
  - "useToast action {label, onSelect} rendered by UiToastRegion"
  - "UiMenu iconOnly and triggerVariant props"
  - "lang recipes.form, ingredient, step, category_starter, photos, image, error (all 12-03 and 12-05 keys), toast.{saved,visibility_changed,ingredient_removed,step_removed,undo}, visibility.{private_hint,shared_hint}"
  - "help topic recipes.sharing (area recipes, screen recipe_form), manual sections adding, photos, sharing"
affects: [12-09, 12-10, 12-15]
key-files:
  created:
    - resources/js/Pages/Recipes/Form.vue
    - resources/js/Components/Recipes/IngredientRows.vue
    - resources/js/Components/Recipes/StepRows.vue
    - resources/js/Components/Recipes/CategoryField.vue
    - resources/js/Components/Recipes/RecipePhotosField.vue
    - resources/js/Components/Recipes/quantityInput.js
    - resources/js/Components/Recipes/ingredientPaste.js
    - resources/js/Components/Recipes/recipePhotosApi.js
    - resources/js/Components/Recipes/recipeFailure.js
    - tests/js/recipes/quantityInput.test.js
    - tests/js/recipes/ingredientPaste.test.js
    - tests/js/recipes/Form.test.js
    - tests/js/recipes/RecipePhotosField.test.js
    - tests/js/ui/ToastAction.test.js
  modified:
    - resources/js/Components/ui/useToast.js
    - resources/js/Components/ui/UiToastRegion.vue
    - resources/js/Components/ui/UiMenu.vue
    - resources/js/Components/ui/UiField.vue
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
    - tests/js/i18n-phase12.test.js
    - tests/Feature/Recipes/RecipePagesTest.php
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 12 Plan 08: Recipe create and edit form Summary

Members with Recipes edit can create and edit a recipe by hand with photos, up to 3 categories, ingredients (decimal comma and fractions, unit list shared with Shopping, paste-to-split), steps, source and visibility; errors from the client and from the server land on the right field, edits keep line ids so other members' ticks survive, and the manual and a help tip describe it in three languages.

## Commits
- 41c9623 quantity and ingredient paste helpers, toast actions (`Refs #9`)
- 19a3b58 form page, ingredient, step and category components, failure helper, texts (`Refs #9`)
- d2634d8 photos field and API, help tip, manual sections (`Refs #9`)

## Tests
- Vitest, new: quantityInput (34), ingredientPaste (6), ToastAction (3), Form (37, includes axe in en, sk, pl, with errors), RecipePhotosField (21, includes the create flow with queued photos and axe). Full Vitest: 176 files, 5734 tests passed (includes ManualCoverage, HelpCoverage, HelpPlacement, i18n-phase12, a11y sweep).
- Pest in isolated compose project `fh1208` (own Postgres, scratch folder over storage/logs), serial: `RecipePagesTest`, `tests/Feature/Manual`, `tests/Feature/Help` green (56 passed); full serial suite result below. Pint on `tests/Feature/Recipes/RecipePagesTest.php` (the only PHP file touched): pass. Project and volumes removed with `down -v`.

## User documentation
Touched `resources/manual/` files (sk and pl are DRAFTS needing native review): `resources/manual/en/recipes.json`, `resources/manual/sk/recipes.json`, `resources/manual/pl/recipes.json` (new sections `adding`, `photos`, `sharing` after `overview`, topic summary extended; same section ids and block types in all three).
Touched `resources/help/` files: `topics.json` (topic `recipes.sharing`, area `recipes`, screen `recipe_form`, field `visibility`), `en.json`, `sk.json`, `pl.json` (area, screen and topic text, under 400 characters). The tip sits on the legend of the "Who can see this recipe" fieldset (`<HelpTip topic="recipes.sharing">`).
Also for native review: all new `recipes.*` keys in `lang/sk.json` and `lang/pl.json` (form, ingredient, step, category_starter, photos incl. the four-form plural `photos.some_failed`, image, error, toast, visibility hints).

## Decisions and notes for later plans
- Create sends `securityFetch('/recipes', POST)` and then uploads queued photos one by one (`uploadQueue`), then calls main when the chosen main is not the first stored. A failed photo keeps the recipe and the toast becomes `recipes.photos.some_failed`; 403, 404 and 429 stop the rest and count them as failed. Edit uses `router.put` (the redirect flashes `recipes.toast.saved`); photo actions on edit call the 12-04 endpoints at once and replace the list from the response.
- Rows are `{key, id, ...}`; blank rows (no name, quantity and note) are dropped from the request; a server line error `ingredients.N.field` maps back through the kept-row keys.
- Quantity is sent as a dot-decimal string (`quantityForRequest`), invalid text blocks the save with `recipes.error.quantity_invalid`. Unit paste matching: built-in aliases in all three languages plus the current locale's labels and custom unit names, accent-insensitive.
- Leave guard: `router.on('before')` returns false when dirty (or photos are queued) and opens the confirm; Leave re-issues the stored visit once (`allowNext`); `beforeunload` is set only while dirty. Own saves pass through `allowNext`.
- `recipes.error.forbidden` is defined here (12-09's namespace list also names it, same text). 12-09 must not redefine it differently. 12-09 still owns `recipes.toast.deleted`, `progress_reset`, `state_failed`.
- Categories max is 3 (config `categories_max`, as the plan says; the UI-SPEC text said 5).
- UI-SPEC "accent" colour is `--color-primary` in this codebase (`--color-accent` is reserved and unused), used for the main photo border and star.

## Deviations from Plan
- **[Rule 3] `UiMenu.vue` got optional `iconOnly` and `triggerVariant` props** (not in `files_modified`): the row and photo menus need an icon-only trigger, which the component lacked. Default behaviour unchanged.
- **[Rule 2] `UiField.vue` error paragraph got `aria-live="polite"`** (UI-SPEC validation rule); no existing test depends on it.
- **[Rule 2] `recipeFailure.js` is a new file** (not listed): a Recipes-namespaced version of Shopping's `failure.js`, so 403/404/409/419/429 show Recipes texts (Shopping's helper would show "list" messages). New texts `recipes.error.{save_failed,forbidden,not_found,changed,too_many_requests,session_expired}`.
- **Undo for removed steps** (`recipes.toast.step_removed`) added beyond the plan, same pattern as ingredients, so a long step is not lost by a mis-tap.
- Lang strings were added in the Task 2 commit (the form needs them to test), not Task 3; the help tip, manual and photos texts are in Task 3 as planned.
- The 12-05 note about two error key names (`source_url` and `source_url_invalid`, `categories` and `categories_count`, `quantity` and `quantity_invalid`) is honoured: both names have texts.

## Known Stubs
None. The form's review mode (after a URL import) and the shopping and detail pages belong to 12-09, 12-10 and 12-15.

## Threat Flags
None beyond the plan's threat model: T-12-30 (text interpolation only; a test renders markup titles and ingredient names as text), T-12-31 (JSON create through securityFetch with the XSRF header, multipart upload with the same header, same-origin only), T-12-33 (object URLs revoked on remove and unmount, only used as image sources).

## Self-Check: PASSED
Full serial Pest suite: 3527 passed, 1 skipped.
