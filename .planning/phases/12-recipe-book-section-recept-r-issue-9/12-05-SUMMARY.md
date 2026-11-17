---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 05
subsystem: recipes-http
tags: [recipes, routes, inertia, requests, privacy, endpoint-matrix]
requires: ["12-03"]
provides:
  - "Routes recipes.{index,create,store,show,edit,update,destroy,visibility} and recipes.{ingredients.tick,steps.done,favourite,note,servings,progress.reset}"
  - "RecipePayload::index, show, form (Inertia props for Recipes/Index, Recipes/Show, Recipes/Form)"
  - "Form requests with quantity normalisation (0,5, 1/2, 1 1/2, vulgar fractions)"
  - "Endpoint matrix rows for every new route and RecipePrivacyTest"
affects: [12-07, 12-08, 12-09, 12-10, 12-16]
key-files:
  created:
    - app/Recipes/Queries/RecipePayload.php
    - app/Http/Controllers/Recipes/RecipeController.php
    - app/Http/Controllers/Recipes/VisibilityController.php
    - app/Http/Controllers/Recipes/StateController.php
    - app/Http/Requests/Recipes/RecipeScopedRequest.php
    - app/Http/Requests/Recipes/RecipeIndexRequest.php
    - app/Http/Requests/Recipes/StoreRecipeRequest.php
    - app/Http/Requests/Recipes/UpdateRecipeRequest.php
    - app/Http/Requests/Recipes/VisibilityRequest.php
    - app/Http/Requests/Recipes/TickRequest.php
    - app/Http/Requests/Recipes/StepDoneRequest.php
    - app/Http/Requests/Recipes/FavouriteRequest.php
    - app/Http/Requests/Recipes/NoteRequest.php
    - app/Http/Requests/Recipes/ServingsRequest.php
    - routes/web/recipes.php
    - routes/web/recipes-state.php
    - tests/Support/EndpointMatrix/recipes.php
    - tests/Support/EndpointMatrix/recipes-state.php
    - tests/Feature/Recipes/RecipePagesTest.php
    - tests/Feature/Recipes/RecipeHttpTest.php
    - tests/Feature/Recipes/RecipeStateHttpTest.php
    - tests/Feature/Recipes/QuantityInputTest.php
    - tests/Feature/Recipes/RecipePrivacyTest.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 12 Plan 05: Recipes HTTP layer Summary

The whole recipe domain is reachable over HTTP: overview (24 per page with `hasMore`), detail, create and edit form props, CRUD, visibility and the per-member marks. Every rule is delegated to the 12-03 services; hidden recipes answer exactly like unknown ids on every route.

## Commits
- ad0ab8f routes, requests, controllers, RecipePayload and the four HTTP/page/quantity test files (`Refs #9`)
- e6347db matrix rows and RecipePrivacyTest (`Refs #9`)

## Tests
Isolated compose project `fh1205` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. Pint on touched PHP: pass. Task 1 files: 56 passed. Task 2 set (`tests/Feature/Recipes`, `EndpointMatrixTest`, `tests/Feature/Security`): 576 passed. Full serial Pest: 3451 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change yet: routes and props only, there is no navigation entry or page until 12-07 to 12-09. No `resources/manual/`, help or lang file touched.

## Flash toasts (translation keys, texts to be added by 12-08 and 12-09)
`recipes.toast.saved`, `recipes.toast.deleted`, `recipes.toast.visibility_changed`, `recipes.toast.progress_reset`.

## Error keys the requests emit (texts to be added by 12-08 and 12-09)
Already in the 12-03 list: title_invalid, description_invalid, servings_invalid, prep_minutes_invalid, cook_minutes_invalid, visibility_invalid, ingredient_invalid, ingredient_note_invalid, ingredients_required, ingredients_count, quantity, unit_unknown, steps_required, step_invalid, steps_count, unknown_line, note_too_long, categories_count. New names used by the requests (no text yet): `recipes.error.source_url` (plan name; the service uses `source_url_invalid`), `recipes.error.categories` (request name; the service uses `category_invalid` and `categories_count`), `recipes.error.quantity` (request name; the service uses `quantity_invalid`), and `recipes.error.state_invalid` (a missing or non-boolean tick, done, favourite or note field; not expected from the UI). 12-08 and 12-09 should give texts to both the plan names and the 12-03 names.

## Decisions and notes for later plans
- Routes: write routes need Recipes edit at the gate, then 404 (hidden/unknown), then 403 (visible non-author), then 422; the check sits in the FormRequest `authorize` (new base class `RecipeScopedRequest`, used by the state requests and visibility, so a hidden recipe never reaches validation).
- JSON vs Inertia: `expectsJson()` without `X-Inertia` gives store 201 `{id}`, update 200 `{id}`, destroy/visibility/state 204; an Inertia visit gets 303 (even when it also accepts JSON).
- Quantity normalisation lives in `StoreRecipeRequest::normaliseQuantity` (public static): spaces at the ends, comma to dot, `a/b`, `n a/b`, vulgar fractions to a two-place half-up decimal; `1/0`, `0`, exponents, >9999 and >2 decimals are refused with `recipes.error.quantity`.
- Detail payload ingredient `quantity` is trimmed of trailing zeros ("1.00" becomes "1", "0.50" becomes "0.5"); the stored value stays two decimals.
- Author fields in the payload are `{id, name, isMe}` only. The avatar picture comes from the global Inertia `avatars` map (id to version) and `useAvatarUrl`, so no per-payload photo version is needed.
- The form payload also carries `canAddToShopping` (Shopping edit grant) and every form limit under `limits` in camelCase.
- Store ignores `id` on lines (not in its rules); Update allows nullable integer `id` and the service rejects unknown or duplicate ids as `unknown_line`.
- The edit page answers 404 for a hidden recipe and 403 for a visible non-author.
- Throttles: `throttle:30,1,recipes.write` on store and update, `throttle:240,1,recipes.state` on the six state routes.
- Matrix: the parent authors a fresh shared recipe per role (params are built per role); child and grandparent get 403 (visible, not author), guest preset 403 at the gate, state routes are 303 for all four module holders.
- Inertia pages do not exist yet: page tests use `component('Recipes/…', false)`; 12-07, 12-08 and 12-09 drop the `false` when their page lands.

## Deviations from Plan
Minor, no rule triggered: added `RecipeScopedRequest` (a shared base class not listed in `files_modified`); the plan's literal `recipes.error.*` request names differ from some 12-03 service names (listed above); extra `canAddToShopping` form prop; JSON 204 for destroy and visibility.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-17 to T-12-21 covered by RecipePrivacyTest, RecipeHttpTest, QuantityInputTest, RecipeStateHttpTest and the endpoint matrix; throttles configured on the routes).

## Self-Check: PASSED
