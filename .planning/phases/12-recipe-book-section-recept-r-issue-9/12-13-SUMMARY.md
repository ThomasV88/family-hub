---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 13
subsystem: recipes-import
tags: [recipes, import, ai, queue, ssrf, notice, fail-fast]
requires: ["12-03", "12-11", "12-12"]
provides:
  - "recipe_imports table (status CHECKs, one active import per user and url) and RecipeImport model and factory"
  - "ai_notice_acks.recipe_providers, separate from the medical providers column"
  - "RecipeImportService: noticeState, acknowledge, start, cancel, discard"
  - "FetchRecipePage (default queue, worker container) and RunRecipeImportJob (ai queue, ai-worker)"
  - "RecipeImportQuery (claim, finish*, beginAttempt, activeFor, draftsFor, statusFor)"
affects: [12-14, 12-15]
key-files:
  created:
    - database/migrations/2026_11_29_130000_create_recipe_imports_table.php
    - database/migrations/2026_11_29_140000_add_recipe_providers_to_ai_notice_acks.php
    - app/Models/RecipeImport.php
    - database/factories/RecipeImportFactory.php
    - app/Recipes/Import/ImportStatus.php
    - app/Recipes/Import/ImportUrl.php
    - app/Recipes/Import/RecipeImportQuery.php
    - app/Recipes/Import/RecipeImportService.php
    - app/Recipes/Import/StartOutcome.php
    - app/Recipes/Import/FetchRecipePage.php
    - app/Recipes/Ai/RunRecipeImportJob.php
    - tests/Support/RecipeImportFixtures.php
    - tests/Unit/Recipes/ImportUrlTest.php
    - tests/Feature/Recipes/ImportSchemaTest.php
    - tests/Feature/Recipes/ImportStartTest.php
    - tests/Feature/Recipes/FetchRecipePageTest.php
    - tests/Feature/Recipes/RunRecipeImportJobTest.php
  modified: []
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 12 Plan 13: Recipe import pipeline Summary

The backend of "create a recipe from a URL with the AI": the start service (notice, limits, reuse), the page fetch in the app `worker` container through SafeFetcher with reduction to text, and the ai-worker job that turns the text into a validated draft or a content-free failure code. Nothing waits for quota.

## Commits
- 8a75643 import table, recipe notice column, URL normaliser, model, factory, query (`Refs #9`)
- 79d4d59 start service, StartOutcome, FetchRecipePage (`Refs #9`)
- 99e2882 RunRecipeImportJob (`Refs #9`)

## Queue wiring (read, not changed)
- `FetchRecipePage` has no connection or queue set: it uses the default `database` connection and the `default` queue, which the existing `worker` container (`queue:work`, no `--queue`) runs, exactly like `FetchWishImage`. SafeFetcher therefore runs only there; never in php-fpm and never in the ai-worker.
- `RunRecipeImportJob` forces `onConnection('database')->onQueue(config('ai.queue'))` (queue `ai`), which only the ai-worker (`--queue=ai-control,ai,ai-bulk`) serves. The ai-worker receives the import id only and reads the reduced text from the row.
- No compose file changed, so `scripts/check-compose.sh` was not affected.

## Outcome and failure codes for 12-14 and 12-15
Start outcomes (`StartOutcome::$outcome`): `started` (id), `reused` (id; same address active, or ready and not dismissed within `ready_ttl_hours`), `invalid_url`, `unavailable` (no Recipes edit or no runnable agent), `notice_required` (`providers` = enabled set to show), `limit` (2 active per member, 20 per local day per member, 10 active globally).

`recipe_imports.status`: `fetching`, `queued`, `running`, `ready`, `failed`, `cancelled`, `used` (set by 12-15). `cancel_reason`: `user`, `access`. `failure_code` (only with `failed`):
- `fetch_failed` with `fetch_reason` one of `bad_url`, `blocked_address`, `too_many_redirects`, `http_status`, `too_large`, `timeout`, `network`, `not_html`, `empty`
- `no_recipe` (page held no recipe), `invalid_output`, `no_output`, `output_too_large`, `timeout`, `idle_timeout`, `cli_error`, `preflight`, `worker_lost`
- `quota_exhausted` (every allowed agent cooling down, at its ceiling, or just hit its limit), `notice_required` (the recipe notice no longer covers the enabled providers), `no_agent`, `auth` (all agents failed to log in)

Service API: `noticeState(User)`, `acknowledge(User, list<string> shown): bool`, `start(User, url)`, `cancel(User, id): string`, `discard(User, id): string` (the last two throw `ModelNotFoundException`, a 404, for another member's or a malformed id). `RecipeImportQuery`: `activeFor`, `draftsFor`, `statusFor` (own rows only, `statusFor` adds `payload`, `textCut`, `sourceKind`), plus unscoped `claim`, `finishReady`, `finishFailed`, `finishCancelled`, `failFetching`, `failQueued`, `beginAttempt`, `closeAttempt`, `abortReason`, `ackedRecipeProviders`, `idsInStatusOlderThan` for the jobs and the 12-14 reaper commands.

## Security notes
- Fetch: SafeFetcher only, recipe user agent and size cap, in the worker container; tests prove a private DNS answer (`10.0.0.5`) and a redirect to `169.254.169.254` end as `blocked_address` with no `ai_calls` row and no job dispatched; a 403 is `http_status`, 3 MB is `too_large`, `image/png` is `not_html`, an empty body is `empty`. A cancel that lands while the page loads wins (conditional update on `fetching`).
- Queue payloads are the import id only (asserted by serialising both jobs); the URL is read from the row, the ai-worker never sees it. The prompt received by the fake CLI holds the text between nonce markers, no `-i`, and no URL.
- No log, report or dump call in `app/Recipes/Import` and `app/Recipes/Ai` (the arch rule from 12-11 is active and passes); failures are codes on the row. An unexpected error in the ai job is rethrown as the fixed message "Recipe import job failed." (test proves the original message does not leak). `source_text` is cleared at every terminal state (by `UPDATE`s and by a CHECK that allows it only for active rows); `payload` exists only for `ready` (CHECK).
- Notice: acknowledgment is the separate `recipe_providers` column, written under `pg_advisory_xact_lock_shared(hashtext('ai-agent-settings'))` only when the shown set equals the enabled set. Tests: a medical-only acknowledgment never satisfies recipes; a recipe-only acknowledgment leaves the medical side empty and does not satisfy the medical notice; enabling Copilot later makes it unacknowledged again. The ai job re-reads the recipe acknowledgment before every attempt and never sends the page to an unacknowledged provider.
- AI consent: no per-person switch is read for recipes (owner decision); an enabled agent plus the acknowledgment is enough.
- Fail fast: `AgentRegistry::nextCandidate` loop with `recordQuotaHit` / `recordAuthFailure` / `recordSuccess`; with no candidate the import fails at once (`notice_required`, then `quota_exhausted`, then `no_agent`). There is no `waiting` state in `recipe_imports` at all.
- `ai_calls` rows for recipes have `kind = recipe`, null subject and document, and carry no URL or content.

## User documentation
No user-visible change: backend pipeline only, no route, lang, help, manual or JS file touched (12-14 and 12-15 add the UI and its manual and help text).

## Tests
Isolated compose project `fh1213` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. Pint on touched PHP: pass. Task sets green: `ImportUrlTest`, `ImportSchemaTest` (14), `ImportStartTest` and `FetchRecipePageTest` (27), `tests/Feature/Recipes` + `tests/Feature/Ai` + `AiArchTest` (424 passed, the whole AI suite included). Full serial Pest: 3600 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched. No test calls a real CLI or the real network (fake CLI scripts, `FakeDnsResolver` and a Guzzle `MockHandler`).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] `ai_notice_acks.providers` made nullable**
- **Found during:** Task 1
- **Issue:** `providers` was `NOT NULL DEFAULT 'codex'`, so writing a recipe acknowledgment for a member without a medical row would have created a row with `providers = 'codex'`, which silently satisfies the medical notice (violates D8 and T-12-56).
- **Fix:** The second migration also runs `ALTER COLUMN providers DROP NOT NULL` (default kept, so existing inserts are unchanged) and the recipe acknowledgment inserts `providers = NULL`; every medical reader already treats NULL as "nothing acknowledged" (`value()` null). `down` removes rows whose `providers` is NULL (they exist only for recipes), restores NOT NULL and drops the column.
- **Files:** database/migrations/2026_11_29_140000_add_recipe_providers_to_ai_notice_acks.php
- **Commit:** 8a75643

**2. `RecipeTask` source label:** the job passes `'url'` (a valid `RecipeTask` label) instead of the row's `source_kind` (`json_ld`, `microdata`, `text`), because `RecipeTask` accepts only `url|text|file` and would fall back to `url` anyway.

**3. Start dispatches after the transaction returns** (not via `DB::afterCommit`), which is after the commit by construction; a dispatch failure closes the row as `fetch_failed`/`network`. Reuse of a ready import is limited to rows inside `ready_ttl_hours` so a stale leftover is not handed back.

**4.** `claim` also requires `cancel_requested_at IS NULL` and a non-null `source_text`; `RecipeImportQuery` gained `ackedRecipeProviders`, `failFetching`, `failQueued` and `idsInStatusOlderThan` (needed by the jobs and by 12-14), and a `closeAttempt` for the quota loop.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-51 to T-12-56 are covered by FetchRecipePageTest, RunRecipeImportJobTest, ImportStartTest, ImportSchemaTest and AiArchTest).

## Self-Check: PASSED
