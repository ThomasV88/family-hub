---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 07
subsystem: recipes-ui-overview
tags: [recipes, navigation, bottom-bar, overview, filters, manual]
requires: ["12-05"]
provides:
  - "Recipes nav entry (ChefHat, /recipes, ACCESS_KEY.recipes) in sidebar and bottom bar"
  - "Bottom bar that never truncates labels at 6 and 7 items (en, sk, pl at 360 px)"
  - "Pages/Recipes/Index.vue, RecipeCard.vue, RecipeFilters.vue, recipeFilters.js"
  - "lang: nav.recipes, module.recipes, recipes.overview/card/filter/visibility/toast.favourite_failed"
  - "Manual topic recipes (section overview, covers module:recipes) and a recipes row in who-sees-what"
affects: [12-08, 12-09, 12-10, 12-15]
key-files:
  created:
    - resources/js/Pages/Recipes/Index.vue
    - resources/js/Components/Recipes/RecipeCard.vue
    - resources/js/Components/Recipes/RecipeFilters.vue
    - resources/js/Components/Recipes/recipeFilters.js
    - resources/manual/en/recipes.json
    - resources/manual/sk/recipes.json
    - resources/manual/pl/recipes.json
    - tests/js/recipes/Overview.test.js
    - tests/js/recipes/recipeFilters.test.js
    - tests/js/i18n-phase12.test.js
  modified:
    - resources/js/Shell/modules.js
    - resources/js/Shell/NavItem.vue
    - resources/js/Shell/BottomNav.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/manual/en/who-sees-what.json
    - resources/manual/sk/who-sees-what.json
    - resources/manual/pl/who-sees-what.json
    - tests/js/shell/moduleAccess.test.js
    - tests/js/shell/AppShell.test.js
    - tests/js/shell/BottomNav.test.js
    - tests/Feature/Recipes/RecipePagesTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 07: Recipes navigation and overview Summary

Recipes is in the navigation, the bottom bar no longer truncates labels at 6 or 7 items, and the overview shows recipe cards in a container-query grid (1, 2, 3 columns at most) with server-side search, filters in the address, chips, favourites and Load more.

## Commits
- ed6a79f nav entry, bottom bar rule, recipeFilters.js, lang, manual topic, shell tests (`Refs #9`)
- 87dc231 overview page, card, filters component, who-sees-what row, tests (`Refs #9`)

## Bottom bar (orchestrator requirement)
- 6 items (`dense`): labels 11 px (`0.6875rem`), letter-spacing -0.02em, item padding 1 px inline, equal columns `minmax(0, 1fr)`, `min-width: 0` on list items, links and the bottom link.
- 7 or more items (`tight`): list becomes flex, every item is `flex: 1 1 0` with `min-width: 44px`; only the current item shows its (whole, never ellipsised) label and grows (`flex: 1 0 auto`); the other items are icon-only: label visually hidden, `aria-label` and `title` carry the name. 44 px touch targets and `is-current` styling unchanged. NavItem got an `nav-item--current` class on its `li`.
- Verified with a Chrome 145 headless measurement at a 360 px viewport using the real CSS of NavItem.vue and BottomNav.vue (scoped selectors flattened) and every label of en, sk, pl (including Prehľad, Financie, Nákupy, Receptár, Zdravie, Wishlist, Úlohy, Zakupy, Receptarz, Finanse), every item as current, with the worst-case fonts DejaVu Sans, Liberation Sans, Nimbus Sans and Noto Sans Mono: no label truncated (`scrollWidth <= clientWidth`), no list overflow, every target at least 44 px high and 43.5 px wide. Control: at 13 px the same harness reports truncation, so the check does detect it. The harness lives in the scratchpad only (not committed).
- Vitest guards (jsdom does no layout): CSS-source assertions for min-width 0, 11 px labels, 44 px minimum and the current-item growth, a width budget check (6 x 44 px plus a long label fits in 360 px), and DOM tests for icon-only name/aria-label/title at 7 items in en, sk, pl.
- Re-check on staging (real device fonts at 360 px, 6 and 7 items) is left to the orchestrator.

## Tests
- Targeted: shell, recipes and i18n-phase12 Vitest files green; ManualCoverage, HelpCoverage and `tests/Feature/Manual` pass.
- Full Vitest: 171 files, 5608 tests passed.
- Isolated compose project `fh1207` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. `RecipePagesTest` plus `tests/Feature/Manual`: 17 passed. Full serial Pest: 3473 passed, 1 skipped.
- Pint on `tests/Feature/Recipes/RecipePagesTest.php` (the only PHP file touched, one line changed): pass.

## User documentation
Touched `resources/manual/` files: `en|sk|pl/recipes.json` (new topic, order 36, section `overview`, covers `module:recipes`) and `en|sk|pl/who-sees-what.json` (new row "A recipe" and a link to the Recipes topic). `getting-started.json` does not list modules, so it was left unchanged. No help (`resources/help/`) change: this plan adds no field or rule that needs a green "?" tip yet. Slovak and Polish texts are drafts and need native review (manual topic, who-sees-what row and all `recipes.*` lang keys).

## Decisions and notes
- Category filter values are the category names (what the 12-03 query matches on); chips show "Category: {name}".
- Clear filters also clears the search text (the whole view returns to its default).
- Load more uses `router.get` with `page` in the address (`only: recipes, hasMore, page`); already shown cards are kept and merged by id on the client. A reload of `?page=2` shows page 2 only.
- Favourite toggle uses `router.put` with `only: ['flash']` so the redirect does not replace the shown cards; failure rolls back and toasts `recipes.toast.favourite_failed`.
- Filters: the inline row is always rendered in the DOM and shown from 640 px container width by CSS; below that the "Filters (n active)" button opens a `UiDialog` with the same component.

## Deviations from Plan
- [Rule 3] `NavItem.vue` was modified (not in the plan's `files_modified`) because the bottom bar rule needs a per-item icon-only state; this follows the orchestrator's extra requirement.
- The author avatar on cards is 24 px by CSS override on `UiAvatar` (its `size` prop only allows 32, 40, 96); `size=32` is passed and shrunk with a deep selector.
- Help tips: none added (plan said none for this plan).

## Known Stubs
None. "New recipe" links to `/recipes/create`, whose page arrives in 12-08/12-09 (the route exists since 12-05).

## Threat Flags
None beyond the plan's threat model: T-12-27 (text interpolation only, a test renders a markup title as text), T-12-28 (nav is cosmetic), T-12-29 (filter options come from the server payload).

## Self-Check: PASSED
Commits ed6a79f and 87dc231 exist; all created files present; git status clean apart from pre-existing untracked .gitkeep files.
