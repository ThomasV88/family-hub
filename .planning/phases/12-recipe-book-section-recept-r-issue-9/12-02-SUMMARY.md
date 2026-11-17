---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 02
subsystem: recipes-schema
tags: [schema, models, migrations, recipes, fixtures]
requires: []
provides:
  - "Tables recipes, recipe_categories, recipe_photos, recipe_ingredients, recipe_steps, recipe_member_states, recipe_ingredient_ticks, recipe_step_done with CHECKs, cascades and a partial unique main-photo index"
  - "Models Recipe (visibleTo scope), RecipeCategory, RecipePhoto, RecipeIngredient, RecipeStep, RecipeMemberState; enum RecipeVisibility; RecipeSearchKey; config/recipes.php; factories; tests/Support/RecipeFixtures"
affects: [12-03, 12-04]
key-files:
  created:
    - database/migrations/2026_11_29_110000_create_recipe_tables.php
    - config/recipes.php
    - app/Models/Recipe.php
    - app/Models/RecipeCategory.php
    - app/Models/RecipePhoto.php
    - app/Models/RecipeIngredient.php
    - app/Models/RecipeStep.php
    - app/Models/RecipeMemberState.php
    - app/Recipes/RecipeVisibility.php
    - app/Recipes/RecipeSearchKey.php
    - database/factories/RecipeFactory.php
    - database/factories/RecipeIngredientFactory.php
    - database/factories/RecipeStepFactory.php
    - database/factories/RecipePhotoFactory.php
    - tests/Support/RecipeFixtures.php
    - tests/Feature/Recipes/RecipeSchemaTest.php
    - tests/Unit/Recipes/RecipeSearchKeyTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 02: Recipes schema and models Summary

The Recipes data model exists with database-enforced invariants (visibility, created_via, servings, minutes and quantity ranges, one main photo per recipe, per-member state keyed by (row, user), unit FK to the Shopping unit list with null on delete), models, the `visibleTo` scope (own or shared), the accent-folded search key, config with every limit, factories and shared fixtures.

## Commits
- f063f3a migration, config/recipes.php, RecipeSchemaTest (`Refs #9`)
- 0847711 models, RecipeVisibility, RecipeSearchKey, factories, RecipeFixtures, RecipeSearchKeyTest (`Refs #9`)

## Tests
Isolated compose project `fh1202` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v` (no containers left). Pint on touched PHP: pass. `tests/Feature/Recipes` plus `tests/Unit/Recipes`: 30 passed. Full serial Pest: 3338 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change: tables, models, factories and config only; no route or page. No `resources/manual/`, help or lang file touched.

## Deviations from Plan
None of substance. Notes:
- `RecipeFixtures::member()` takes the level as a label (`none`, `view`, `edit`) because `AccessLevel` is int-backed; it uses `AccessLevel::fromLabel`. It is exercised by one test (12-01's CHECK exists), contrary to the plan's caution which only applied while 12-01 was pending.
- Failed-insert assertions in the schema test run inside `DB::transaction` (savepoint) so Postgres does not abort the surrounding test transaction.
- An early full run showed 10 failures only because a second Pest process was running against the same test database at the same time; the clean serial rerun is green.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-04 visibleTo scope test, T-12-05 CHECKs, T-12-06 composite-key tables, all covered by RecipeSchemaTest).

## Self-Check: PASSED
