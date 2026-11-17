---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 02
subsystem: shopping-schema
tags: [schema, models, migrations, shopping]
requires: []
provides:
  - "Tables shopping_units (nine built-ins seeded), shopping_stores, shopping_catalog_items, shopping_lists, shopping_list_members, shopping_items with CHECKs and partial indexes"
  - "Models ShoppingUnit, ShoppingStore, ShoppingCatalogItem, ShoppingList, ShoppingListMember, ShoppingItem"
  - "Enums ListStatus, ItemState, MemberRole, SourceKind; NameKey::from(); config/shopping.php; factories"
affects: [11-03, 11-04]
key-files:
  created:
    - database/migrations/2026_11_28_110000_create_shopping_catalog_tables.php
    - database/migrations/2026_11_28_120000_create_shopping_list_tables.php
    - config/shopping.php
    - app/Shopping/ListStatus.php
    - app/Shopping/ItemState.php
    - app/Shopping/MemberRole.php
    - app/Shopping/SourceKind.php
    - app/Shopping/NameKey.php
    - app/Models/ShoppingUnit.php
    - app/Models/ShoppingStore.php
    - app/Models/ShoppingCatalogItem.php
    - app/Models/ShoppingList.php
    - app/Models/ShoppingListMember.php
    - app/Models/ShoppingItem.php
    - database/factories/ShoppingUnitFactory.php
    - database/factories/ShoppingStoreFactory.php
    - database/factories/ShoppingCatalogItemFactory.php
    - database/factories/ShoppingListFactory.php
    - database/factories/ShoppingItemFactory.php
    - tests/Feature/Shopping/SchemaTest.php
    - tests/Unit/Shopping/NameKeyTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 02: Shopping schema and models Summary

The Shopping data model exists with database-enforced invariants (status, state, role, quantity range, done and locked timestamps, one carry-over per list, case and accent insensitive unique names), models, enums, factories and config, and no starter catalog or stores.

## Commits
- 0c00355 migrations, enums, NameKey, config, NameKeyTest (`Refs #8`)
- 726cda5 models, factories, SchemaTest (`Refs #8`)

## Tests
Isolated compose project `fh1102` (own Postgres, scratch folder over storage/logs via an override file), serial, removed with `down -v`. Pint on all touched PHP: pass (two style fixes applied before commit). SchemaTest plus NameKeyTest: 28 passed. migrate:fresh and rollback of both migrations succeed. Full serial Pest: 2976 passed, 1 skipped. Vitest not run: no JS, lang or manual file changed in this plan.

## User documentation
No user-visible change: tables, models, factories and config only; the UI arrives in 11-06 and later. No `resources/manual/`, help or lang file touched.

## Deviations from Plan
None. Plan executed as written. Notes: the unit-name uniqueness is enforced with partial unique indexes on `lower(name_xx)`; the ShoppingListMember model has no timestamps columns managed by Eloquent (only `created_at`, set by services).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-11-05, T-11-06, T-11-08 covered by SchemaTest).

## Self-Check: PASSED
