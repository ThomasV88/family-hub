---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 14
subsystem: recipes-import
tags: [recipes, import, http, prefill, reaper, leak-canary, privacy]
requires: ["12-05", "12-13"]
provides:
  - "Routes recipes.imports.{notice,store,show,cancel,destroy} with matrix rows"
  - "Overview and form props: import {available, acknowledged, providers}; overview drafts"
  - "ImportPrefill (ready payload to form prefill), ?import= and ?source= on /recipes/create"
  - "Used-on-save rule in RecipeService::create (import_id)"
  - "recipes:reap-imports (5 min) and recipes:prune-imports (daily), leak canary test"
affects: [12-15, 12-16, 12-17]
key-files:
  created:
    - app/Http/Controllers/Recipes/ImportController.php
    - app/Http/Requests/Recipes/StartImportRequest.php
    - app/Http/Requests/Recipes/ImportNoticeRequest.php
    - routes/web/recipes-import.php
    - tests/Support/EndpointMatrix/recipes-import.php
    - app/Recipes/Import/ImportPrefill.php
    - app/Recipes/Import/ImportReason.php
    - app/Console/Commands/RecipesReapImports.php
    - app/Console/Commands/RecipesPruneImports.php
    - tests/Feature/Recipes/ImportHttpTest.php
    - tests/Feature/Recipes/ImportPrefillTest.php
    - tests/Feature/Recipes/ImportMaintenanceTest.php
    - tests/Feature/Recipes/ImportLeakTest.php
  modified:
    - app/Http/Controllers/Recipes/RecipeController.php
    - app/Http/Requests/Recipes/StoreRecipeRequest.php
    - app/Recipes/RecipeService.php
    - app/Recipes/Queries/RecipePayload.php
    - app/Recipes/Import/RecipeImportQuery.php
    - routes/console.php
    - tests/Feature/Ai/AiScheduleTest.php
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 12 Plan 14: Import routes, prefill and maintenance Summary

The import is now controllable over HTTP by its owner only, opens as a reviewable prefilled form, is consumed only on Save, cleans itself up, and a canary test proves page content stays out of every log and admin view.

## Commits
- 066517d routes, controller, requests, matrix rows, ImportHttpTest (`Refs #9`)
- 4dcea7b overview flags and drafts, ImportPrefill, create `?import=`/`?source=`, used-on-save rule (`Refs #9`)
- d914e48 reap and prune commands with schedule, maintenance and leak tests (`Refs #9`)

## Contract for 12-15 (UI)
Routes (all Recipes edit; JSON answers; names `recipes.imports.*`):
- POST `/recipes/imports/notice` `{providers[]}`: 204, or 409 `{outcome:'notice_changed', providers}`; 422 `{errors:{providers:['invalid']}}`.
- POST `/recipes/imports` `{url}`: 202 `{outcome:'started'|'reused', id}`; 422 `{errors:{url:['recipes.import.err_url']}}` (also for an empty or over-long value); 409 `{outcome:'notice_required', providers}`; 409 `{outcome:'unavailable'}`; 429 `{outcome:'limit'}` (the route throttle, 10 per minute, is a plain 429).
- GET `/recipes/imports/{import}`: `{id, status, reason, host, textCut}`. `status` is one of fetching, queued, running, ready, failed, cancelled, used. `reason` is null unless failed, else `fetch` (fetch_failed), `no_recipe` (no_recipe, invalid_output, no_output, output_too_large, cli_error, timeout, idle_timeout, worker_lost) or `unavailable` (quota_exhausted, quota_timeout, no_agent, auth, notice_required, preflight; also the fallback for an unknown code). Mapping lives in `App\Recipes\Import\ImportReason`.
- POST `/recipes/imports/{import}/cancel` and DELETE `/recipes/imports/{import}`: 204. Another member's, an unknown or a malformed id is a 404 on all three id routes.
- Throttles: notice 10/min, start 10/min, status 120/min, cancel and discard 30/min.

Props: overview (`Recipes/Index`) and form (`Recipes/Form`) both carry `import {available, acknowledged, providers}`; the overview also `drafts [{id, status, host, reason}]` (own imports only: active, ready and failed of the last 24 h, not dismissed).

Form prefill (`Recipes/Form` prop `prefill`, only on `/recipes/create`):
- `?import={uuid}` of an own ready draft: `{title, description, servings, prep_minutes, cook_minutes, categories[], ingredients[{name, quantity (string|null), unit_id, note, original}], steps[{body}], aiFields[], sourceUrl, sourceHost, textCut, importId}`. Another member's or an unknown or malformed id is a 404; an own draft that is not ready opens the plain form (no `prefill`).
- `?source={url}` (accepted by `ImportUrl::normalise`): `prefill = {sourceUrl, sourceHost, aiFields: [], textCut: false, importId: null}` with no title, ingredients or steps; an unusable value is ignored (no `prefill`).
- The form must post `import_id` (the `prefill.importId`) with the save and `source_url` (the member's edited value is what is stored). `import_id` is optional and never causes an error: a missing, used, foreign, malformed or pruned id saves the recipe as manual.

## Behaviour notes
- Save: `RecipeService::create(User, array, ?string $importId)` locks the member's own `ready` import in the same transaction, sets `created_via = 'url'`, then marks the import `used` with the payload deleted. A refused save (422) consumes nothing.
- Reaper: fetching older than `reap_fetching_minutes` (by created_at) becomes `fetch_failed`/`network`; queued older than `reap_queued_minutes` (by queued_at) becomes `worker_lost` with its call row closed; running older than `reap_running_minutes` (by started_at) becomes `worker_lost` through `finishFailed`, closing the open `ai_calls` row. `source_text` is null in every case. Prints the number of closed imports.
- Prune: ready older than `ready_ttl_hours` becomes cancelled with payload null; every non-active row older than `retention_days` is deleted (by created_at). `ai_calls` and the medical tables are untouched. Prints "expired deleted".
- Schedule names `recipes-reap-imports` (every five minutes, without overlapping) and `recipes-prune-imports` (daily).

## Security
- Responses and props hold enums, ids, host and the validated recipe fields only; the status answer never has the path, text, prompt, model output or failure code (tests assert it for every code).
- Leak canary (ImportLeakTest): a page with a canary in the title, an ingredient and a hidden element goes through FetchRecipePage and the real `queue:work` for a successful run, invalid output with the canary in it, a CLI that prints it on both streams and fails, and a thrown exception whose message holds it. In each case the canary is absent from the new log lines, `failed_jobs`, `jobs`, `audit_logs`, `ai_calls`, `recipe_imports` (all columns but the ready payload), the status JSON, the admin AI calls page, another member's overview and create page; `source_text` is null after the terminal state; the hidden element never reaches the agent prompt and never the ready payload.

## User documentation
No user-visible change: routes, props and maintenance commands only. No page, lang, help or manual file touched (12-15 adds the UI with its manual and help text). No `resources/manual/` file touched.

## Tests
Isolated compose project `fh1214` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. Pint on touched PHP: pass. Task sets: ImportHttpTest plus EndpointMatrixTest 256 passed; `tests/Feature/Recipes` 210 passed; `tests/Feature/Recipes`, `tests/Feature/Ai`, `tests/Unit/Arch` 477 passed. Full serial Pest: 3630 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched. No test calls a real CLI or the real network.

## Deviations from Plan
Minor, no rule triggered:
- Added `ImportReason` (shared by the controller and RecipePayload) and four helper methods on `RecipeImportQuery` (`idsStuck`, `runDurationMs`, `expireReady`, `deleteFinishedOlderThan`), none listed in `files_modified`.
- Matrix: the start row expects 409 `unavailable` for the editors (no runnable agent in the matrix fixture); outcomes are covered by ImportHttpTest.
- `?source=` prefill carries only the source fields (see contract above).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-57 to T-12-61 covered by ImportHttpTest, ImportPrefillTest, ImportMaintenanceTest, ImportLeakTest and the throttles).

## Self-Check: PASSED
