---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 06
subsystem: recipes-shopping-bridge
tags: [recipes, shopping, catalog-matching, transaction, endpoint-matrix]
requires: ["12-03"]
provides:
  - "CatalogMatcher::match (exact and suggested tiers over NameKey)"
  - "RecipeShoppingBridge::preview and ::commit (one DB transaction through ListService and ItemService)"
  - "GET and POST /recipes/{recipe}/shopping (recipes.shopping.preview, recipes.shopping.store), JSON"
affects: [12-10, 12-17]
key-files:
  created:
    - app/Recipes/Shopping/CatalogMatcher.php
    - app/Recipes/Shopping/RecipeShoppingBridge.php
    - app/Http/Controllers/Recipes/ShoppingController.php
    - app/Http/Requests/Recipes/AddToShoppingRequest.php
    - routes/web/recipes-shopping.php
    - tests/Support/EndpointMatrix/recipes-shopping.php
    - tests/Support/RecipeShoppingFixtures.php
    - tests/Unit/Recipes/CatalogMatcherTest.php
    - tests/Feature/Recipes/ShoppingBridgeTest.php
    - tests/Feature/Recipes/ShoppingBridgeHttpTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 06: Shopping bridge backend Summary

The server side of "Add to shopping list": a preview with eligible lists and per-ingredient catalog matches, and an all-or-nothing commit that goes through Shopping's own services, so merge rules, locks, limits and quantity rules of Shopping apply unchanged.

## Commits
- e056cec CatalogMatcher, RecipeShoppingBridge, fixtures, unit and feature tests (`Refs #9`)
- d5e13c2 routes, AddToShoppingRequest, ShoppingController, matrix rows, HTTP test (`Refs #9`)

## Tests
Isolated compose project `fh1206` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. Pint on touched PHP: pass. Plan files plus EndpointMatrixTest: 263 passed. Full serial Pest: 3473 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change yet: JSON endpoints only, the dialog arrives in 12-10. No `resources/manual/`, help or lang file touched.

## Error keys (texts to be added by 12-10)
New: `recipes.shopping.err_target`, `recipes.shopping.err_list`, `recipes.shopping.err_items`. Reused Shopping keys (texts exist): `shopping.error.name_invalid`, `store_unknown`, `catalog_unknown`, `unit_unknown`, `quantity`, `quantity_invalid`, `quantity_merge_too_large`, `items_limit`, and `shopping.error.list_changed` (the 409 body message for a list locked or finished meanwhile; 12-10 shows it).

## Decisions and notes for later plans
- Preview row keys: `ingredientId, name, quantity, unitId, note, match{tier,catalogItemId,catalogName}, suggestedUnitId`. `unitId` is the ingredient's own unit (the contract listed it implicitly through suggestedUnitId; added for display-side conversions). Quantities are trimmed of trailing zeros ("0.5").
- Matcher: exact on `name_key`; suggested when exactly one catalog item equals the ingredient key minus one ending (`es, s, y, e, i, a`, at least 3 characters left) or its words are a strict whole-word subset of the ingredient's words; otherwise none. Documented outcome: "vajcia" vs "Vajce" is none (irregular plural).
- Commit validation order: gates (Recipes view, Shopping edit) then 404 for a hidden or unknown recipe (before the body is read, via `RecipeScopedRequest`) then 422 then Shopping's 404/403/409. A viewer on the target list gets 403 from ItemService, a list the actor cannot see 404.
- 409 from ItemService is turned into JSON `{message: 'shopping.error.list_changed'}` in the controller; the transaction has already rolled back (no new list, no rows).
- The request sends `unit_id` through only when the key is present; an absent key lets ItemService apply the catalog item's default unit. Rows never carry notes.
- 80-row requests need two ItemService calls (limit 50 each); a test proves ItemService alone refuses the same 80 rows.
- Matrix: parent, child and grandparent 200, guest preset (Shopping view only), admin and noGrants 403, guest redirect.

## Deviations from Plan
Minor, no rule triggered: preview rows additionally carry `unitId`; the chunking test infers the two chunks from the result because `ItemService` is final (no spy possible).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-22 to T-12-26 covered by ShoppingBridgeTest, ShoppingBridgeHttpTest and the endpoint matrix; throttle 20 per minute configured).

## Self-Check: PASSED
