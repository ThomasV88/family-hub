---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 03
subsystem: recipes-domain
tags: [access, services, per-member-state, query, recipes]
requires: ["12-01", "12-02"]
provides:
  - "RecipeAccess (mayView, mayEdit, hasView, hasEdit, abilities) with no admin bypass"
  - "RecipeQuery (index with filters and paging, filterOptions, categorySuggestions, find, visible, lockForActor)"
  - "RecipeService (create, update by diff on ids, setVisibility, delete with after-commit photo directory removal)"
  - "RecipeStateService (tick, stepDone, favourite, note, servings, reset, stateFor)"
  - "RecipeImageStore (directory, deleteDirectory only)"
affects: [12-04, 12-05, 12-06, 12-14, 12-16]
key-files:
  created:
    - app/Recipes/RecipeAccess.php
    - app/Recipes/RecipeService.php
    - app/Recipes/RecipeStateService.php
    - app/Recipes/Queries/RecipeQuery.php
    - app/Recipes/Images/RecipeImageStore.php
    - tests/Feature/Recipes/RecipeAccessTest.php
    - tests/Feature/Recipes/RecipeQueryTest.php
    - tests/Feature/Recipes/RecipeServiceTest.php
    - tests/Feature/Recipes/RecipeStateTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 03: Recipes domain layer Summary

The recipe rules live in four classes: record-level access (author with Recipes edit only, no admin bypass), CRUD that updates ingredients and steps in place by id so other members' ticks and done steps survive an edit, absolute idempotent per-member state, and an overview query that always starts from `visibleTo`.

## Commits
- 91cb872 RecipeAccess and RecipeQuery with tests (`Refs #9`)
- 9cbf33b RecipeService, RecipeStateService, RecipeImageStore with tests (`Refs #9`)

## Tests
Isolated compose project `fh1203` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on app/Recipes and tests/Feature/Recipes: pass (style fixes applied before commit). `tests/Feature/Recipes`: 51 passed. Full serial Pest suite: 3365 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change: domain classes and tests only, no route or page. No `resources/manual/`, help or lang file touched.

## `recipes.error.*` keys thrown (ValidationException, texts to be added by 12-08 and 12-09)
title_invalid, description_invalid, servings_invalid, prep_minutes_invalid, cook_minutes_invalid, source_url_invalid, visibility_invalid, category_invalid, categories_count, ingredient_invalid, ingredient_note_invalid, ingredients_required, ingredients_count, quantity_invalid, unit_unknown, steps_required, step_invalid, steps_count, unknown_line, note_too_long (state: also servings_invalid, shared with the create/update key).

## Decisions and notes for later plans
- Answer order: 404 (hidden, unknown, or module level none) then 403 (visible but not author or lost edit) then 422. Create without Recipes edit is 403.
- `RecipeQuery::lockForActor` (visibility plus row lock) and `RecipeQuery::visible` (no lock) are the standard loaders; the state service uses `visible` and checks child rows with `where recipe_id`.
- `RecipeService::create` takes `created_via` from `$data` (manual or url); 12-14 can add its trailing import argument. Quantities accept `1234.56` or a comma decimal, max `recipes.quantity_max`; the stored value is the normalised string.
- Update never deletes and reinserts: kept ids are updated in place, id-less rows inserted, missing rows deleted (cascade removes their marks). A duplicated payload id counts as `unknown_line`.
- Other members' state rows are kept when a recipe goes private; `visibleTo` hides them and they return on re-share.
- `RecipeQuery::index` eager-loads author (id, name), categories and photos for the cards.

## Deviations from Plan
Minor, no rule triggered: added `RecipeAccess::hasView/hasEdit` and `RecipeQuery::lockForActor/visible` helpers (shared by services, not in the contract); input validation keys beyond the two named in the plan (listed above) so the DB CHECKs are never the first line of defence.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-07 to T-12-11 covered by RecipeQueryTest, RecipeServiceTest, RecipeStateTest).

## Self-Check: PASSED
