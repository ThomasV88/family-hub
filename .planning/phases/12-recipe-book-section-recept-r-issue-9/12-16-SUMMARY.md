---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 16
subsystem: staging-seed
tags: [staging, seed, audit, recipes]
requires: ["12-04", "12-05", "12-06", "12-13"]
provides:
  - "Staging Recipes demo (12 synthetic Slovak recipes, generated photos, member progress, one shopping run) created through the production services"
  - "staging:audit rules for recipes, recipe_member_states, recipe_ingredient_ticks, recipe_step_done, recipe_imports"
key-files:
  created:
    - database/seeders/Staging/RecipesSeeder.php
    - tests/Feature/Recipes/StagingAuditRecipesTest.php
  modified:
    - database/seeders/StagingSeeder.php
    - app/Console/Commands/StagingAudit.php
    - tests/Feature/StagingDemoSeedTest.php
    - tests/Feature/StagingSeedTest.php
    - docs/ops/staging-data.md
    - docs/ops/server.md
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 16: Staging Recipes seed and audit Summary

Staging gets 12 synthetic Slovak recipes built only through RecipeService, RecipeImageStore, RecipeStateService and RecipeShoppingBridge, and `staging:audit` now fails on any recipe, member state, tick, done mark or import tied to a non-synthetic user.

## Commits
- b9afde9 feat(12-16): seed synthetic Recipes demo through the production services on staging (`Refs #9`)
- 9851938 feat(12-16): staging audit rules for every recipe table and ops docs for the Recipes demo (`Refs #9`)

## What was built
- `RecipesSeeder`: 12 recipes across parent1, parent2, child1 and grandparent; 8 shared and 4 private; all ten starter categories (sk labels) used; one with servings null; one scaling-hostile list (soľ without quantity, 1 vajce, 3 strúčiky as pcs, 0.5 tsp); one with 10 photos, most others with one (GD JPEG, flat shapes, no text, no network); one 98-character title; "Zemlovka" with `created_via` url and `https://recepty.example/zemlovka`. Progress: ticks and done steps from two members on the shared "Palacinky s džemom", a personal serving count, favourites for three members, one note. One Add-to-shopping run (built from the bridge preview, catalog matches kept) into the open "Týždenný nákup" list. No import row is seeded.
- `StagingSeeder`: calls the seeder after ShoppingSeeder; purge deletes recipes of synthetic authors before users (RESTRICT), imports of synthetic users, and member states, ticks and done marks of synthetic users; the `wish-images/recipes/{id}` folders go after commit (warning carries only the exception class).
- `StagingAudit::rules()`: five recipe tables.
- Docs: Slovak Recipes scenario map in `docs/ops/staging-data.md` (owners, shared/private, progress, shopping list, import cannot be seeded); one line on `wish-images/recipes/` in `docs/ops/server.md`.

## Tests
Isolated compose project `fh1216` (own Postgres, scratch folder over storage/logs via an override file), serial. StagingDemoSeedTest, StagingSeedTest and StagingAuditRecipesTest: 49 passed. Pint on all touched PHP: pass. Full serial Pest suite: 3640 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change: staging-only seed data, audit rules, ops docs and tests; no `resources/manual/`, help or lang file touched. Production gets no recipe data from this plan (the seeder is only called from the staging seeder).

## Deviations from Plan
- [Rule 3 - Test coverage] `tests/Feature/StagingSeedTest.php` (not in `files_modified`, but part of the plan's verify command) was extended: `seededCounts` lists the recipe tables and `synthRows` creates tester recipes, member progress, an import and a photo file, so the reseed test proves the purge.
- The recipe-table counts the plan asked for in `demoCounts` were added; `shopping_items` is not pinned (the bridge run is asserted by content: "Džem" free text and "Múka" catalog match in the weekly list).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-66 covered by StagingAuditRecipesTest, T-12-67 by calling only production services, T-12-68 by the reseed tests).

## Self-Check: PASSED
Both commits and all created files verified present.
