---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 09
subsystem: recipes-ui-detail
tags: [recipes, vue, detail, servings, ticks, notes, gallery, cooking-mode, wake-lock, i18n, manual, help]
requires: ["12-05", "12-07", "12-08"]
provides:
  - "Pages/Recipes/Show.vue: detail with gallery, per-member ticks, servings recalculation, favourite, notes, author actions and cooking mode"
  - "recipeScale.js (scaleQuantity, displayQuantity, displayNumber), useRecipeState.js (ordered FIFO queue of absolute-state requests), useWakeLock.js"
  - "RecipePhotoGallery, ServingsControl, IngredientRow, StepRow, RecipeNotes, RecipeIngredients, RecipeSteps, PhoneProbe components"
  - "lang recipes.detail, gallery, servings, progress, cooking, notes, delete, reset, unavailable, toast.{deleted,progress_reset,state_failed}"
  - "help screen recipe_detail with topics recipes.servings, recipes.progress, recipes.notes, recipes.cooking"
  - "manual sections ingredients-servings, cooking, notes-favourites, deleting"
affects: [12-10, 12-15, 12-17]
key-files:
  created:
    - resources/js/Pages/Recipes/Show.vue
    - resources/js/Components/Recipes/recipeScale.js
    - resources/js/Components/Recipes/useRecipeState.js
    - resources/js/Components/Recipes/useWakeLock.js
    - resources/js/Components/Recipes/RecipePhotoGallery.vue
    - resources/js/Components/Recipes/ServingsControl.vue
    - resources/js/Components/Recipes/IngredientRow.vue
    - resources/js/Components/Recipes/StepRow.vue
    - resources/js/Components/Recipes/RecipeNotes.vue
    - resources/js/Components/Recipes/RecipeIngredients.vue
    - resources/js/Components/Recipes/RecipeSteps.vue
    - resources/js/Components/Recipes/PhoneProbe.vue
    - tests/js/recipes/recipeScale.test.js
    - tests/js/recipes/useRecipeState.test.js
    - tests/js/recipes/useWakeLock.test.js
    - tests/js/recipes/Show.test.js
    - tests/js/recipes/CookingMode.test.js
  modified:
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

# Phase 12 Plan 09: Recipe detail Summary

Members cook from the recipe detail with their own ticks, servings and notes: ingredient and step ticks, the favourite, notes and the chosen servings are saved at once through one ordered queue of absolute-state requests with per-row rollback (the Phase 11 mark-queue lesson), servings recalculation is display only, the author alone sees Edit, Change visibility and Delete, and cooking mode enlarges the rows and keeps the screen on where the browser supports it.

## Commits
- 86d7b1c scaling, per-member state queue and wake lock helpers with tests (`Refs #9`)
- 359e7d9 detail page, gallery, servings control, rows, notes, delete and visibility actions, strings in en, sk, pl, RecipePagesTest drops the `false` flag (`Refs #9`)
- db5f7c6 cooking mode tests, four help tips, four manual sections, i18n-phase12 subtrees (`Refs #9`)

## Key behaviour
- **State queue (`useRecipeState`)**: every write of the detail (ingredient tick, step done, favourite, notes, servings, reset progress) goes through ONE FIFO queue with a single request in flight, sent with `securityFetch` (JSON, XSRF header, 204 expected). A request is never aborted and requests are never sent together, so several quick taps lose no mark. Marks are absolute target states. A mark is shown at once (override) until the last request of its own row settles; a tap on a row whose request is still waiting replaces the waiting target, a tap on a row in flight queues one more absolute state behind it. A failure rolls back only that row to the last server-confirmed state and calls `onFail(key, status)`; the page maps 403/404/409/419/429 through the shared `recipeFailure.js` (`failureKey`), 404 shows the unavailable state, 403/409 reload the props, everything else is the toast `recipes.toast.state_failed`. Reset my progress is a queued `DELETE /recipes/{id}/progress`, ticks clear on screen only after the server confirmed.
- **Servings**: `scaleQuantity` x target / base (null quantity or base: unchanged), `displayQuantity` shows 1/4, 1/3, 1/2, 2/3, 3/4 within 0.01 for tsp, tbsp and no unit, otherwise up to 2 decimals in the locale ("1,5 ks"), never grouped, g and ml never converted. The control is a labelled group (minus and plus 44 px, typed input 1..99 clamped on blur), shows Scaled and Reset to {n}, announces politely, saves 600 ms debounced (null when equal to the recipe's servings) and flushes on unmount. Hidden when the recipe has no servings.
- **Layout**: tabs (`?tab=` kept with `history.replaceState`, state object preserved) below 840 px of the page's own width, two columns from 840 px (ResizeObserver on the page root).
- **Delete and visibility**: delete is `UiConfirmDialog` danger with the exact UI-SPEC text and a double-submit guard (`deleting` until `onFinish`); 403/404/network answers toast and keep the page. Change visibility is a dialog with the two radios, shared to private asks the 12-08 confirm first (the dialog closes before the confirm opens so dialogs never stack).
- **Cooking mode**: local state, default off. Hides description and the thumbnail strip, rows 64 px, tick area is the whole row label, `--space-6` between steps, sticky "Ingredients"/"Steps" anchors on phone, phone bar with "Ingredients x/y" and "Steps x/y" plus ghost Leave, toggle in the header on wider screens and as the second button of the phone bar otherwise. `useWakeLock` requests the lock while on, releases on off, on hidden, on unmount, re-acquires on visible, and is silent when unsupported or refused; the hint only shows when supported.
- **Slots for 12-10**: `<span data-slot="add-to-shopping">` in the header actions and `data-slot="add-to-shopping-bar"` in the phone bar render nothing.

## Tests
- Vitest, new: recipeScale (25), useRecipeState (10), useWakeLock (5), Show (41, includes axe in en, sk, pl and wide, FIFO order of three quick ticks, rollback of one row, 403/404/409, debounced servings and notes, delete guard), CookingMode (13, incl. axe in en, sk, pl). Full Vitest: 181 files, 5855 tests passed (includes ManualCoverage, HelpCoverage, HelpPlacement, i18n-phase12, a11y sweep).
- Pest in isolated compose project `fh1209` (own Postgres, scratch folder over storage/logs), serial: `RecipePagesTest` 7 passed; full serial suite (includes `tests/Feature/Manual`, `tests/Feature/Help`, EndpointMatrix and Security): 3600 passed, 1 skipped. Pint on `tests/Feature/Recipes/RecipePagesTest.php` (the only PHP file touched): pass. Project and volumes removed with `down -v`.

## User documentation
Touched `resources/manual/` files (sk and pl are DRAFTS needing native review): `resources/manual/en/recipes.json`, `resources/manual/sk/recipes.json`, `resources/manual/pl/recipes.json` (four new sections `ingredients-servings`, `cooking`, `notes-favourites`, `deleting` after `sharing`, topic summary extended; same section ids and block types in all three).
Touched `resources/help/` files: `topics.json` (screen `recipe_detail`, area `recipes`, fields `servings`, `ingredients`, `notes`, `cooking_mode`), `en.json`, `sk.json`, `pl.json` (screen name and four topics, each under 400 characters). Placements: `recipes.servings` next to the Servings label (`ServingsControl`), `recipes.progress` next to the Ingredients heading (`RecipeIngredients`), `recipes.notes` on the notes label (`UiField` help), `recipes.cooking` next to the cooking mode toggle (header and phone bar).
Also for native review: every new `recipes.*` key in `lang/sk.json` and `lang/pl.json` (detail, gallery, servings, progress, cooking, notes, delete, reset, unavailable, three toasts), including the Slovak and Polish delete and reset confirm texts.

## Decisions and notes for later plans
- `recipes.toast.favourite_failed` (12-07) and the new `recipes.toast.state_failed` have the same text; the detail uses `state_failed` for every state write including the favourite.
- `recipes.error.forbidden` (12-08) is reused unchanged for a refused delete and state write.
- The slot spans for 12-10 sit in `Show.vue` header actions and in the phone bar; 12-10 should render its button and dialog there and may pass the chosen `servings` (the page's `servings` ref, display value) to its dialog.
- Photos: the hero uses `fullUrl` (1280 px), the thumbnail strip `thumbUrl`.

## Deviations from Plan
- **[Rule 3] Three small components beyond `files_modified`**: `RecipeIngredients.vue` and `RecipeSteps.vue` (the two sections are rendered in two places, tab panels and the wide two-column layout, so they are components rather than duplicated markup) and `PhoneProbe.vue`: AppShell provides the shell state only to components inside its slot, not to the page that renders AppShell, so a renderless probe inside the slot reports the phone layout to the page (the same reason Shopping's StickyActionBar lives in a child).
- **[Rule 2] Lang strings were added in the Task 2 commit** (the page cannot be tested without them), as 12-08 did; help, manual and the cooking tests are in the Task 3 commit as planned.
- `UiProgress` is 8 px high; the spec asks 4 px, so the two section bars are shortened with a class (`height: 4px`) instead of changing the shared component.
- A typed servings value above 99 is clamped to 99 on blur (the plan says 1..99 without naming the behaviour).
- Fixed in a Phase 11 style review of my own diff before commit: no defect found beyond the test-time `await` needed after mount (the phone layout arrives one tick after mount through PhoneProbe).

## Known Stubs
None. The "Add to shopping list" button and dialog are plan 12-10 and only their named slots exist.

## Threat Flags
None beyond the plan's threat model: T-12-34 (text interpolation only, a test renders markup in title and step text as text, the source link only for http(s) with `rel="noopener noreferrer nofollow"`, tests for javascript:, ftp: and data: URLs), T-12-35 (one request in flight, absolute states, per-row rollback, tested), T-12-37 (author actions rendered from `abilities` only, tested for a non-author).

## Self-Check: PASSED
Commits 86d7b1c, 359e7d9 and db5f7c6 exist; all created files present; git status clean apart from the pre-existing untracked .gitkeep files.
