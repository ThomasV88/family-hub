---
phase: 12-recipe-book-section-recept-r-issue-9
reviewed: 2026-11-17T00:00:00Z
depth: deep
files_reviewed: 88
files_reviewed_list:
  - app/Access/Module.php
  - app/Access/Preset.php
  - app/Console/Commands/PruneWishlist.php
  - app/Console/Commands/RecipesPruneImports.php
  - app/Console/Commands/RecipesReapImports.php
  - app/Console/Commands/StagingAudit.php
  - app/Http/Controllers/Recipes/ImportController.php
  - app/Http/Controllers/Recipes/PhotoController.php
  - app/Http/Controllers/Recipes/RecipeController.php
  - app/Http/Controllers/Recipes/ShoppingController.php
  - app/Http/Controllers/Recipes/StateController.php
  - app/Http/Controllers/Recipes/VisibilityController.php
  - app/Http/Middleware/NoStoreDynamicResponses.php
  - app/Http/Requests/Recipes/AddToShoppingRequest.php
  - app/Http/Requests/Recipes/FavouriteRequest.php
  - app/Http/Requests/Recipes/ImportNoticeRequest.php
  - app/Http/Requests/Recipes/MoveRecipePhotoRequest.php
  - app/Http/Requests/Recipes/NoteRequest.php
  - app/Http/Requests/Recipes/RecipeIndexRequest.php
  - app/Http/Requests/Recipes/RecipeScopedRequest.php
  - app/Http/Requests/Recipes/ServingsRequest.php
  - app/Http/Requests/Recipes/StartImportRequest.php
  - app/Http/Requests/Recipes/StepDoneRequest.php
  - app/Http/Requests/Recipes/StoreRecipeRequest.php
  - app/Http/Requests/Recipes/TickRequest.php
  - app/Http/Requests/Recipes/UpdateRecipeRequest.php
  - app/Http/Requests/Recipes/UploadRecipePhotoRequest.php
  - app/Http/Requests/Recipes/VisibilityRequest.php
  - app/Http/Requests/Settings/UpdatePermissionsRequest.php
  - app/Medical/Ai/Agents/AgentInput.php
  - app/Medical/Ai/Agents/CodexAgent.php
  - app/Medical/Ai/Agents/CopilotAgent.php
  - app/Medical/Ai/AiTask.php
  - app/Medical/Ai/FailureCode.php
  - app/Medical/Ai/MedicalTask.php
  - app/Medical/Ai/PromptBuilder.php
  - app/Medical/Queries/AdminAiCallQuery.php
  - app/Models/Recipe.php
  - app/Models/RecipeCategory.php
  - app/Models/RecipeImport.php
  - app/Models/RecipeIngredient.php
  - app/Models/RecipeMemberState.php
  - app/Models/RecipePhoto.php
  - app/Models/RecipeStep.php
  - app/Recipes/Ai/RecipePromptBuilder.php
  - app/Recipes/Ai/RecipeTask.php
  - app/Recipes/Ai/RecipeValidator.php
  - app/Recipes/Ai/RunRecipeImportJob.php
  - app/Recipes/Images/RecipeImageStore.php
  - app/Recipes/Import/FetchRecipePage.php
  - app/Recipes/Import/ImportPrefill.php
  - app/Recipes/Import/ImportReason.php
  - app/Recipes/Import/ImportStatus.php
  - app/Recipes/Import/ImportUrl.php
  - app/Recipes/Import/PageRejected.php
  - app/Recipes/Import/RecipeImportQuery.php
  - app/Recipes/Import/RecipeImportService.php
  - app/Recipes/Import/RecipePageReducer.php
  - app/Recipes/Import/ReducedPage.php
  - app/Recipes/Import/StartOutcome.php
  - app/Recipes/Queries/RecipePayload.php
  - app/Recipes/Queries/RecipeQuery.php
  - app/Recipes/RecipeAccess.php
  - app/Recipes/RecipeSearchKey.php
  - app/Recipes/RecipeService.php
  - app/Recipes/RecipeStateService.php
  - app/Recipes/RecipeVisibility.php
  - app/Recipes/Shopping/CatalogMatcher.php
  - app/Recipes/Shopping/RecipeShoppingBridge.php
  - app/Wishlist/Catalog/WishImageStore.php
  - app/Wishlist/Fetch/SafeFetcher.php
  - config/recipes.php
  - database/migrations/2026_11_29_100000_add_recipes_module_permissions.php
  - database/migrations/2026_11_29_110000_create_recipe_tables.php
  - database/migrations/2026_11_29_120000_add_kind_to_ai_calls.php
  - database/migrations/2026_11_29_130000_create_recipe_imports_table.php
  - database/migrations/2026_11_29_140000_add_recipe_providers_to_ai_notice_acks.php
  - database/seeders/Staging/RecipesSeeder.php
  - database/seeders/StagingSeeder.php
  - docker/nginx/snippets/laravel-locations.conf
  - routes/console.php
  - routes/web/recipes-import.php
  - routes/web/recipes-photos.php
  - routes/web/recipes-shopping.php
  - routes/web/recipes-state.php
  - routes/web/recipes.php
  - resources/ai/recipe.v1.prompt.md
  - resources/ai/recipe.v1.schema.json
  - resources/ai/recipe.v1.inline-json.md
findings:
  critical: 0
  warning: 6
  info: 6
  total: 12
status: issues_found
---

# Phase 12: Code Review Report

**Reviewed:** 2026-11-17
**Depth:** deep
**Files Reviewed:** 88 (backend only; factories and tests were skimmed, not reviewed)
**Status:** issues_found

## Summary

I looked for a break in every area on the priority list. I did not find a security defect or a data-loss defect, so there are no BLOCKERs. What I checked and could not break:

- **SSRF.**
  - The user-agent change is purely additive: `userAgent ?? 'FamilyHubImageFetcher/1.0'` is applied inside `send()`, and the validation, pinning and redirect code is untouched.
  - Userinfo, IPv6 literals, non-80/443 ports, backslashes and control characters are rejected in `SafeFetcher::validate()`.
  - Each hop is re-resolved and re-pinned with `CURLOPT_RESOLVE` plus a connected-IP check, and the URL is rebuilt from parsed parts.
  - Failure reasons never reach the browser: `RecipePayload::drafts()` drops `fetchReason`, and `ImportController::show` returns only the coarse `ImportReason`.
- **Prompt injection.**
  - The page text is hostile data. It sits between nonce markers (random 64-bit nonce per `RecipeTask`), and `<<<` is broken up in both the reducer and the task.
  - The template is read before the page text is added, so the page cannot inject `{{...}}` placeholders.
  - There is no tool use: the Codex runner passes `--sandbox read-only`, `features.shell_tool=false` and `web_search=disabled`, and the Copilot runner passes `--deny-tool=shell|write|read|url|memory`.
  - Output is strict-validated server side (exact keys, tags stripped, bidi and zero-width characters removed, units whitelisted).
  - Repair prompts carry only structural issue codes.
- **AI platform seam.**
  - `AgentInput::$task` defaults to `null`, which becomes `MedicalTask` and calls the same medical prompt, schema and validator as before.
  - The only change on the medical path is the `NoRecipe` early return in `CopilotAgent::map`, which the medical validator can never produce.
  - The `ai_calls_guard_update` body matches the Phase 9 body plus `kind`, and `down()` refuses to run while `recipe` rows exist.
- **Notice acknowledgement.**
  - The recipe upsert inserts `providers = NULL` explicitly and updates only `recipe_providers`.
  - All three medical writers update only `acknowledged_at` and `providers`.
  - All medical readers (`AiRunQuery`, `AiJobQuery`, `AdminAiBulkQuery`) treat `NULL` as "nothing acknowledged". A recipe acknowledgement cannot satisfy the medical notice.
- **Authorisation.**
  - Hidden and unknown recipes give a uniform 404 on every route. `RecipeScopedRequest` and `UpdateRecipeRequest` run the 404 check before validation.
  - Edit is author-only with no admin bypass.
  - The shopping bridge requires both recipes view and shopping edit.
  - Member-state rows are always keyed by the actor.
- **Update-by-diff.**
  - Ids from another recipe and duplicate ids are rejected before any write.
  - Removal and update are scoped by `recipe_id`.
  - Ticks and done marks survive because rows are never delete-and-reinserted.
- **Search.** `%`, `_` and `\` are escaped with an explicit `escape '\'`, and every raw fragment is bound. The tests cover `%`, `_`, and multi-token queries that exercise the ORDER BY placeholders.
- **Migrations.**
  - The recipes grants backfill copies the To-do level, uses `ON CONFLICT DO NOTHING` and is covered by a test.
  - The CHECK is dropped and re-added, and `down()` reverses it.
  - The `ai_calls` CHECK precedence is correct.
  - Column widths match the values the code stores.
- **Sweeper and photo route.**
  - `WishImageStore::FOREIGN_PREFIXES` now includes `wish-images/recipes/`, so the wish sweeper cannot delete recipe photos.
  - The photo route re-checks visibility on every view and sets `private, no-cache`, an ETag and nosniff.
  - Orphan files from a failed insert are cleaned up in a `catch`.
- **Staging.** The purge targets only synthetic user ids, and the audit rules flag any non-synthetic user.

The remaining issues are robustness and correctness problems of moderate impact (a 500 from crafted input, silent loss of text, brittle AI-reply validation) plus hardening notes.

## Warnings

### WR-01: Unbounded `page` parameter overflows OFFSET and returns HTTP 500

**File:** `app/Http/Requests/Recipes/RecipeIndexRequest.php:24` (consumed at `app/Recipes/Queries/RecipeQuery.php:73-76`)
**Issue:** `'page' => ['nullable','integer','min:1']` has no upper bound. `GET /recipes?page=999999999999999999` passes validation, and `($page - 1) * 24` leaves bigint range (or becomes a float). Postgres answers `bigint out of range` or `OFFSET must not be negative`, which surfaces as an unhandled 500 for any member with recipes view.
**Fix:**
```php
'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
```
Clamp again in `RecipeQuery::index` (`$page = min(max(1, $page), 10000)`).

### WR-02: `strip_tags` silently deletes legitimate recipe text containing `<`

**File:** `app/Recipes/Ai/RecipeValidator.php:311` and `app/Recipes/Import/RecipePageReducer.php:308`
**Issue:** PHP `strip_tags` treats a `<` followed by a non-space character as the start of a tag and removes everything up to the next `>`, or to the end of the string if none follows. A step such as "Bake <180 °C until golden, then reduce to <160 °C" is truncated or emptied without any signal. If the whole step is stripped, it becomes `''` and is dropped. This happens both before the model sees the text (the JSON-LD and microdata path in the reducer) and after (the validator). The member reviews the draft, but the loss is invisible because nothing in the form shows that text was removed.
**Fix:** Neutralise angle brackets instead of parsing markup. In the validator, replace `strip_tags($value)` with `str_replace(['<', '>'], ['‹', '›'], $value)` (or drop only `/<\/?[a-z][^>]*>/i`). Keep `html_entity_decode` where the source is HTML, because Vue escapes on output.

### WR-03: One blank ingredient name in a model reply fails the whole import

**File:** `app/Recipes/Ai/RecipeValidator.php:49,179-187,241-250`
**Issue:** `ingredient.name` is `req => true`, so an empty name (a section header such as "For the sauce:" emitted as an ingredient, or a line that is only a quantity) makes `field()` return `false`. `list()` then sets `$ok = false` and the entire reply is `InvalidOutput`. With Codex there is no repair call (`validateFile` is final), so one stray row discards a complete, otherwise good recipe. The member sees the generic "no recipe" reason and burns one of 20 daily slots. Copilot gets one repair pass, which may not fix it.
**Fix:** In `list()`, treat an ingredient whose only defect is an empty `name` as droppable, as is already done for empty strings in `steps` and `categories`. Skip it and keep going; the existing `ingredients:empty` check still catches a reply with none left.

### WR-04: Transliterated search keys can exceed their column width and return HTTP 500

**File:** `app/Recipes/RecipeService.php:218,241,146`; `app/Recipes/RecipeSearchKey.php:24-37`; `database/migrations/2026_11_29_110000_create_recipe_tables.php:17,35,60`
**Issue:** `title_key varchar(255)`, `name_key varchar(90)` (categories) and `name_key varchar(360)` (ingredients) are filled from `NameKey::from()`, which uses `Str::ascii`. The source limits are 120, 30 and 120 characters. The transliteration is not length-preserving: each CJK character becomes about 4-6 ASCII characters, and Cyrillic `щ`, `ж` and `ю` become 2-4. A 60-character Chinese title, or a 20-character Chinese category, overflows, and Postgres raises `22001 value too long`. The user gets a 500 instead of a validation error, and the other fields they typed are lost. SK, PL and EN are unaffected, but the app is installed for an extended family and `Str::ascii` is locale-blind.
**Fix:** Cut the key to the column width in one place, for example `mb_substr(NameKey::from($x), 0, 250)` in `RecipeSearchKey::title`, `::ingredient` and `::category`. Truncating the search key is harmless. Make the category `name_key` in `replaceCategories` go through the same helper.

### WR-05: Import reaper can fail a healthy fetch that is merely waiting in the shared default queue

**File:** `app/Console/Commands/RecipesReapImports.php:18` with `config/recipes.php` (`reap_fetching_minutes` = 5)
**Issue:** `FetchRecipePage` runs on the app `worker`, which is a single `queue:work` on the default queue that also carries mail, push and wish-image jobs. A fetch that waits more than 5 minutes behind a backlog (for example a burst of outbound mail) is reaped as `fetch_failed/network`. The fetch job later finds `status != 'fetching'` and returns. The member sees a network error for a page that was never attempted. The reaper clock should measure time since the fetch started, not since the row was created.
**Fix:** Either raise `reap_fetching_minutes` to about 15, or add `fetch_started_at` set by `FetchRecipePage::handle` and reap on `coalesce(fetch_started_at, created_at)` with a larger threshold for rows that never started.

### WR-06: Reducer reads the JSON-LD block before hidden-content stripping and the hidden-element filter is easy to bypass

**File:** `app/Recipes/Import/RecipePageReducer.php:68-75,426-446`
**Issue:** `jsonLdRecipe()` runs on the raw DOM before `stripNoise()`. JSON-LD is hidden by nature, so injected text in `name`, `description`, `recipeInstructions` and similar fields goes straight to the model. Per-field caps are applied: 500 characters for the description, 1000 for the other strings, and 300 lines. For HTML text, `isHidden()` covers `display:none`, `visibility:hidden`, `font-size:0`, `opacity:0` and far negative `left` or `text-indent`. It does not cover white-on-white text, `height:0;overflow:hidden`, `clip`, `max-height:0`, `top:-9999px`, `position:absolute` plus offscreen `margin`, `transform:scale(0)`, or `<details>` without `open`. Nothing here is a hard boundary, because the real defences are the nonce markers, the strict output validation, the absence of tools and the review-before-save screen. But the stated "hidden elements stripped" guarantee in the class docblock is weaker than it reads, and an injected "Servings: 1000 / Ingredient: ..." line can steer the draft.
**Fix:** Extend the style regex with `height:0`, `max-height:0`, `top:-\d{3,}`, `clip:rect(0`, `transform:scale(0`, and `color` equal to `background`. Mention in the docblock that the JSON-LD block is taken verbatim. If you want parity, cap the JSON-LD fields tighter than 1000 characters, since ingredients and steps are short.

## Info

### IN-01: `idsStuck()` interpolates `$column` into raw SQL

**File:** `app/Recipes/Import/RecipeImportQuery.php:244-249`
**Issue:** `whereRaw("coalesce({$column}, created_at) < ?", ...)` is safe today because both call sites pass literals and the docblock restricts the type. Nothing enforces it, so a future caller passing a variable would inject SQL.
**Fix:** `abort_unless(in_array($column, ['created_at','queued_at','started_at'], true), 500);` or use `match`.

### IN-02: Other members' ticks, notes, favourites and servings outlive a shared-to-private change

**File:** `app/Recipes/RecipeService.php:87-96`; `app/Recipes/RecipeStateService.php`
**Issue:** `setVisibility` only flips the column. While a recipe is private, every other member's `recipe_member_states` row (including their free-text `note`), ticks and done marks stay in the database, invisible to them and to the author, and they reappear unchanged on re-share. Deleting the recipe cascades them away without notice. Nothing leaks, because every read is scoped to the actor and `visibleTo`. The behaviour is consistent but probably surprising, and the manual does not say it.
**Fix:** Decide the policy. Either keep it and document it in the manual's privacy section, or clear other members' note and progress when the author makes the recipe private.

### IN-03: AI reply limits are enforced by silent truncation

**File:** `app/Recipes/Ai/RecipeValidator.php:280`
**Issue:** `array_slice($items, 0, maxItems)` drops ingredients beyond 80, steps beyond 60 and categories beyond 3 with no flag. Codex is bound by `maxItems` in the schema, but Copilot is not. `textCut` only reflects the reduced page text.
**Fix:** Optional. Return an `itemsCut` flag in the payload, or fail validation with `ingredients:too_many` so the repair pass can ask for fewer.

### IN-04: Pages with an undeclared non-UTF-8 charset are rejected as "not a web page"

**File:** `app/Recipes/Import/RecipePageReducer.php:155-176`
**Issue:** With no charset in the header or in the first 4 KB of meta, the code assumes UTF-8, and `mb_check_encoding` failing throws `PageRejected(NOT_HTML)`. Older Slovak and Polish recipe sites that serve Windows-1250 or ISO-8859-2 without declaring it are refused, with a misleading reason.
**Fix:** On UTF-8 failure with no declared charset, try `Windows-1250`, then `ISO-8859-2`, before rejecting.

### IN-05: Upload-quota slot is spent on addresses that can never be fetched

**File:** `app/Recipes/Import/ImportUrl.php:13-38`
**Issue:** `ImportUrl::normalise` only checks shape. `http://localhost/`, `http://host:8080/` and single-label hosts create an import row, count against `per_user_per_day` (20) and `active_per_user` (2), and fail later in `SafeFetcher::validate`. This is not an SSRF problem, since the fetcher blocks them. It only wastes quota and a queue slot.
**Fix:** Apply the cheap, DNS-free part of `SafeFetcher::validate` (scheme, port 80/443, no IP literal, FQDN regex) in `normalise` and return `invalid_url`.

### IN-06: `source_url` may carry credentials and is rendered as a link

**File:** `app/Recipes/RecipeService.php:349-362`; `app/Http/Requests/Recipes/StoreRecipeRequest.php:140-149`
**Issue:** The scheme is restricted to http and https, so `javascript:` is not possible. But `https://trusted.example@evil.example/` is accepted, stored and shown, which is a phishing-style display issue, and the host column shows the part after `@`. The import path rejects userinfo; the manual path does not.
**Fix:** Reject `isset($parts['user']) || isset($parts['pass'])` in both `httpUrl()` and `source()`, matching `ImportUrl`.

---

_Reviewed: 2026-11-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
