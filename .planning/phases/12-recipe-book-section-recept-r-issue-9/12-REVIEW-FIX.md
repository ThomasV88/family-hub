---
phase: 12-recipe-book-section-recept-r-issue-9
fixed_at: 2026-11-17T00:00:00Z
review_path: .planning/phases/12-recipe-book-section-recept-r-issue-9/12-REVIEW.md, 12-REVIEW-UI.md
iteration: 1
findings_in_scope: 29
fixed: 29
skipped: 0
status: all_fixed
---

# Phase 12: Code Review Fix Report

**Fixed at:** 2026-11-17
**Source reviews:** `12-REVIEW.md` (backend) and `12-REVIEW-UI.md` (frontend), plus the `PdfRasterizerTest` timing flake
**Iteration:** 1

All work is on `main`, one commit per finding or small group, every commit carries `Refs #9`. Nothing was pushed. Two new migrations were added (no pushed migration was edited). Parts of a few findings were deliberately narrowed; they are listed under "Partly done" below.

## Fixed (backend)

- **WR-01** page capped. The overview takes `page` 1 to 100 (validation) and the query clamps again. The cap is 100, not 1000, because of the WR-04 decision below (page N now returns pages 1..N). Test: 999999999999999999, 101, 100.
- **WR-02** new `App\Recipes\PlainText` replaces every `strip_tags` on recipe text (validator and reducer). Only real tags (`<` or `</` directly followed by a letter, closed by `>`), comments, CDATA and script/style/template contents go; "Bake <180 °C" and "< 5 min" stay. Entities are decoded once and the result is cleaned again, so `&lt;script&gt;` cannot come back as a tag. Tests in validator and reducer.
- **WR-03** an ingredient row whose name is missing, null or blank is dropped; the reply fails only if nothing usable remains (`ingredients:empty`). Logic change, requires human verification.
- **WR-04** `RecipeSearchKey` cuts title (255), category (90) and ingredient (360) keys on code points; the category key also feeds the primary key and the filter, so service and query use the same helper. Tests with CJK and Cyrillic (create, update, search by a cut category and title).
- **WR-05** new migration `2026_12_01_100000_add_fetch_started_at_to_recipe_imports` (nullable `fetch_started_at`), set by `FetchRecipePage::handle` when the fetch starts. The reaper closes a fetching import 5 minutes after the fetch started, or 60 minutes after creation if it never started (new `recipes.import.reap_fetch_unstarted_minutes`). A job waiting behind others is not reaped. Logic change, requires human verification.
- **WR-06** reducer docblock now states the real guarantees (and what is not detectable: white-on-white, stylesheet, script, overlap, tiny sizes; JSON-LD is read as it stands). Hidden detection adds `height:0`, `max-height:0`, `width:0`, `clip:rect(0`, `clip-path:inset(50%|100%)`, `scale(0)`, and offscreen `top`/`right`/`bottom`. JSON-LD blocks inside a hidden element are skipped, and ingredient lines from JSON-LD are capped at 300 characters. Tests for every variant and for a non-hidden `line-height:0.5`.
- **IN-01** `idsStuck` whitelists its column (`match`, `InvalidArgumentException`); fetching has its own query.
- **IN-03** too many ingredients (above 80) or steps (above 60) in a model reply is now a structural error (`ingredients:too_many`, `steps:too_many`, the Copilot repair pass can ask for fewer) instead of silent truncation. Categories keep the "first three after de-duplication" contract (documented in code).
- **IN-05** `ImportUrl::normalise` applies the DNS-free part of SafeFetcher: port 80 or 443 only, no IP literal, localhost or single label, FQDN with a letter TLD (IDN converted). Existing test for `:8443` changed to refuse.
- **IN-06** credentials in a source address are refused on the manual path (`RecipeService::source` and `StoreRecipeRequest`); the import path already refused them. An existing test that stored `user:pw@` now stores a plain address.

## Fixed (frontend)

- **WR-01** `useRecipeState`: a reset (any non-mark job) is a barrier; a tap after it is never merged into a mark waiting before it. Test with the exact sequence from the review.
- **WR-02** new `queue.resync()` (immediate when idle, after the queue drains otherwise). After a 403/409 `Show.vue` reloads and re-seeds ticks, favourite, servings; `RecipeNotes` follows a new server note unless the member has unsaved typing. A 404 on one ingredient or step row is "recipe changed" (toast, reload), not a gone page; recipe-level endpoints (favourite, servings, note, progress, delete) or a 404 on the reload itself still make it unavailable. Tests for all three.
- **WR-03** new `useBackGuard`: a sentinel history entry once the form is dirty (immediately for an import draft in review), so browser Back and iOS swipe-back open the leave dialog; Keep editing re-arms it, Leave goes one more step back, saving and Leave-by-link replace the sentinel (no duplicate history entry). Limits (documented in the composable and in the manual): reload, tab close and typed addresses rely on `beforeunload`, which iOS Safari does not prompt for; Forward and long-press history jumps are not intercepted.
- **WR-04** chosen approach: the server answers `?page=N` with pages 1..N (`RecipeQuery::MAX_PAGE` = 100, hence the cap in WR-01), so Back, reload and shared links show the whole loaded list. `Index.vue` replaces the list from props and announces only the newly added count.
- **WR-05** the card favourite uses `securityFetch` (not an Inertia visit), so no later visit cancels it, and any failure rolls back with the toast. The filter loading flag is tied to the latest visit (a cancelled visit no longer switches the skeleton off); `reloadDrafts` is `async`. Load more stays a normal visit on purpose: a filter change should cancel a stale load-more.
- **WR-06** scaled values snap: stored 2-decimal quantities are made exact first (0.33 to 1/3, also sixths and eighths), then displayed as a friendly fraction (spoons and no unit) or, for any unit, as the whole number they stand for ("1 tsp", not "0.99 tsp"). The recipe's own servings show the author's number untouched.
- **WR-07** `parseQuantity(text, locale)`: in English a comma followed by exactly three digits is a thousands separator ("1,000" is 1000, "10,000" is invalid), in sk/pl a decimal comma. Other comma forms and the dot are unchanged. Wired into the form, the paste parser and the shopping dialog.
- **WR-08** `cl` is a centilitre converted to ml (x10, only on the raw spelling, so the Slovak "čl" teaspoon still works); `pl` is no longer an alias.
- **WR-09** a reading draft row has **Cancel**, and the row polls again when the reloaded drafts still say reading. In the URL dialog the 3-minute give-up is its own "taking longer" state: Cancel and Try again stop the first import first (and refuse to go on if that fails), closing leaves it as a draft row. New lang key `recipes.import.err_slow`.
- **WR-10** the `aria-label` on ingredient and step checkboxes is gone; the visible label (quantity, name, step text) is the accessible name. The two unused lang keys were removed.
- **WR-11** plural forms in sk, pl and en for `servings.updated`, `ingredient.split_offer`, `form.categories_hint`, `form.categories_max`, `photos.hint`, `photos.cap`; call sites pass the count. Test `RecipePlurals.test.js`.
- **WR-12** the name of a catalog-matched shopping row is read-only with an explanation (`aria-describedby`); "Add as typed item" frees it again. `UiInput` now keeps a caller's `aria-describedby` outside a `UiField`.
- **WR-13** new migration `2026_12_01_110000_add_client_token_to_recipes` (nullable `client_token`, unique per author where not null). The form sends one token per form session; a retry returns the recipe the first request created (also when two requests race: the unique violation falls back to the winner). The form never visits `/recipes/undefined` (a missing id is shown as a save failure). Tests: retry, per author, malformed token, no token, DB uniqueness.
- **WR-14** favourite button on the card and on the detail page has a stable name (`aria-pressed` carries the state); the card name includes the recipe title. Keys `favourite_add` and `favourite_remove` were replaced by `favourite` and `favourite_of`.
- **IN-02** a failed main photo upload or `makeMain` after create is reported (`recipes.photos.main_failed`).
- **IN-05 (servings clamp feedback; the request numbered it IN-06)** a typed servings number that is pulled into range shows a visible line (`recipes.servings.clamped`); a recipe with more than 99 servings keeps its own value reachable with the stepper.
- **IN-06 (409 list locked, the review's own IN-06)** the locked list leaves the dialog choices and the next open list is selected; a new-list conflict no longer flips the radio.
- **IN-08** Ctrl/Cmd/Shift/Alt-click and middle click on **New recipe** keep the browser's own behaviour.
- **IN-09** the bottom-nav tooltip sits on the link, and the 7-item bar uses 40 px items with an invisible 44 px hit area, so it fits 320 px (new assertions for 360 and 320).
- **IN-11 (empty author name)** a recipe whose author is gone shows "by a former member" (card and detail) and says nobody can edit it.

## Test flake

`tests/Unit/Ai/PdfRasterizerTest.php` "returns nothing and leaves no process when the timeout is hit": the 1 ms timeout against real `pdfinfo`/`pdftoppm` raced. The test now puts stub `pdfinfo` and `pdftoppm` scripts (`exec sleep 37`) first on `PATH` (set through `$_ENV` and `putenv`, because Symfony Process reads `$_ENV` first), uses a 0.3 s timeout, and still proves the three calls return nothing and that no child is left (`pgrep -fx "sleep 37"`). It no longer depends on machine speed.

## Partly done (narrowed on purpose)

- **UI WR-13, Add to shopping POST:** no idempotency token. The orchestrator decision keeps all-or-nothing semantics; only recipe create got the token.
- **UI IN-11 hero photo:** only the empty author name was in scope; the full-size image in the gallery hero is unchanged.
- **Backend IN-03 categories:** still cut to the first three after de-duplication (by design, documented).
- **UI WR-04 and WR-01 (backend):** the page cap is 100 instead of 1000 because page N now returns N pages of rows; 2400 recipes is far above family scale.
- **UI WR-05 Load more:** still an Inertia visit (see above).

## User documentation

`resources/manual/{en,sk,pl}/recipes.json` (sections overview, adding, import-from-link, ingredients-servings, sharing, shopping; same ids, counts and links) and `resources/help/{en,sk,pl}.json` (`recipes.shopping`, 332 to 348 characters). Lang keys added in all three files: `recipes.import.err_slow`, `recipes.shopping.name_locked`, `recipes.photos.main_failed`, `recipes.servings.clamped`, `recipes.card.favourite`, `recipes.card.favourite_of`, `recipes.card.by_unknown`, `recipes.detail.only_author_unknown`.

**Native review needed (Slovak and Polish drafts):** the appended sentences in the manual files above, the `recipes.shopping` help text, the new lang keys, and the plural forms of the six counted strings in `lang/sk.json` and `lang/pl.json`.

## Notes for the owner

- Run the migrations as the owner role on staging first (`fetch_started_at`, `client_token`); both are nullable additions.
- Logic changes that need a human look: backend WR-03 (blank ingredient rows are dropped), WR-05 (reaper windows), IN-03 (long replies now fail), IN-05 (stricter import addresses); frontend WR-03 (history sentinel behaves slightly differently per browser, please try Back and swipe-back on a phone and an iPad) and WR-04 (page N returns pages 1..N).
- `useBackGuard` pushes one extra history entry while a form is dirty; Back from a clean form behaves as before.

## Verification

Isolated compose project `fh1212fix` (own Postgres, scratch folder over `storage/logs`), serial, removed with `down -v` (no container, volume or network left). Full serial Pest suite: 3672 passed, 1 skipped, 0 failed (includes `Feature/Manual`, `Help`, the migrations and the fixed rasterizer test, which also passed in repeated runs). Pint `--test`: pass (1176 files). Full Vitest: 189 files, 6233 tests passed (a11y sweep, manual and help coverage, `i18n-phase12`, pseudo-long and the new tests included).

---

_Fixed: 2026-11-17_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
