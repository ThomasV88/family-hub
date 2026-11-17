---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 17
subsystem: release-gate
tags: [i18n, a11y, gate, recipes]
requires: ["12-15", "12-16"]
provides:
  - "Final locale parity for Phase 12 (lang JSON, AI calls label, seven help topics, manual section order)"
  - "Pseudo-long layout test for the Recipes pages"
  - "Recipes pages in the accessibility sweep"
key-files:
  modified:
    - tests/js/i18n-phase12.test.js
    - tests/js/a11y-sweep.test.js
  created:
    - tests/js/recipes/PseudoLong.test.js
status: PARTIAL - Tasks 1 and 2 done; Task 3 (staging browser verification, 17 steps) pending the orchestrator
---

# Phase 12 Plan 17: Final parity, a11y sweep and full gate (Tasks 1-2 only)

Tasks 1 and 2 are done. Task 3 (the 17-step staging check on synthetic data, including the real-AI import check list) is NOT done: the orchestrator pushes `develop`, waits for the staging deploy and runs it. This plan is not complete until then.

## Commits
- 6d71bc3 test(12-17): final locale parity and pseudo-long layout check for Recipes (Refs #9)
- a938016 test(12-17): add Recipes pages to the accessibility sweep (Fixes #9) - the last code commit of the phase

## What was built
- `i18n-phase12.test.js`: a final block that checks the AI calls label, `nav.recipes`, `module.recipes` and `permissions.module.recipes` in en, sk, pl; the seven help topics (`recipes.import`, `sharing`, `servings`, `progress`, `notes`, `shopping`, `cooking`) registered in `topics.json` and present in en, sk, pl at most 400 characters; and `recipes.json` with the ten section ids in the same order and the same block shapes in all three languages. The earlier subtree parity (all `recipes.*` leaves, placeholders, plural forms en 2 / sk 4 / pl 4) stays.
- `recipes/PseudoLong.test.js` (7 tests, every string at least 40 percent longer): Index, Show (phone), Show in cooking mode, the shopping dialog, Form (create) and Form (review) keep titles, ingredient names, step text and form labels whole (no truncate, ellipsis or line-clamp element), no button has `whitespace-nowrap`, and a source scan proves `RecipeCard.vue` (the grid card title) is the only Recipes file with a clamp.
- `a11y-sweep.test.js`: added Recipes/Index (recipes, empty, a ready and a failed import draft), Recipes/Show (author, non-author), Recipes/Form (create, edit, review) in en, sk, pl x light, dark x 390, 1280 px, plus Index, Show (author) and Form (create) at 360 px (bottom bar with seven items), plus Show in cooking mode (3 cases). The sweep grew from 158 to 288 cases, no axe violations. The Inertia mock grants `recipes: 'edit'`.
- No lang, help or manual file needed a fix: no key was missing in any language.

## Gate results (isolated compose project fh1217, own Postgres, scratch folder over storage/logs, serial; removed with `down -v`, no fh1217 containers or volumes left)
- Pint `--test`: pass (1173 files)
- Full Vitest: 188 files, 6164 tests passed (includes ManualCoverage, HelpCoverage, HelpPlacement, i18n-phase12, PseudoLong, a11y-sweep)
- `npm run build`: OK with SW-PRECACHE OK (`public/build` is gitignored and writable here)
- Full Pest (serial): 3639 passed, 1 skipped, 1 failed. The failure is `tests/Unit/Ai/PdfRasterizerTest.php:75` ("returns nothing and leaves no process when the timeout is hit", Phase 8 file): it sets a 1 ms timeout and expects `pdfinfo` to be killed, but under full-suite load the process finished first (returned 2 pages instead of 0). The file passes alone, 3 reruns in a row (9 passed). Not touched by Phase 12; timing flake, left unchanged (listed below).
- check-nginx-template.sh: OK; check-compose.sh: OK; check-upload-limit.sh: OK (upload 302, profile 302, shopping 302, recipes 302, other 413, medical 302, medicalother 413)

## Deviations
- The plan text lists the final manual order as overview, adding, import-from-link, photos, ingredients-servings, cooking, notes-favourites, sharing, shopping, deleting. The files (all three languages, identical) have `sharing` right after `photos` (written by 12-08, and the 12-08 test requires it). The parity test pins the order that is actually shipped; no manual file was reordered, because that would change reading order for a doc-only reason.
- The PseudoLong test also needed a phone width (400 px) plus a flush before the cooking toggle exists (the phone layout arrives one tick after mount); test detail only.
- Out of scope, not fixed: the Phase 8 PdfRasterizer timeout flake above.

## User documentation
No user-visible change in this plan (tests only). Files touched in Phase 12 (all sk and pl texts are drafts):
- Manual: `resources/manual/{en,sk,pl}/recipes.json` (12-07 overview; 12-08 adding, photos, sharing; 12-09 ingredients-servings, cooking, notes-favourites, deleting; 12-10 shopping; 12-15 import-from-link), `who-sees-what.json` (12-07), `admin.json` (12-01 and 12-15 permission row and AI calls paragraph)
- Help: `resources/help/topics.json`, `resources/help/{en,sk,pl}.json` (12-08 `recipes.sharing`, 12-09 `servings`, `progress`, `notes`, `cooking`, 12-10 `shopping`, 12-15 `import`; area `recipes` and screens `recipe_form`, `recipe_detail`, `recipe_shopping`, `recipe_import`)
- Lang JSON: `lang/{sk,pl}.json` subtrees `recipes.*` (overview, card, filter, visibility, toast, form, ingredient, step, category_starter, photos, image, error, detail, gallery, servings, progress, cooking, notes, delete, reset, unavailable, shopping, new, import, review, drafts), `nav.recipes`, `module.recipes`, `permissions.module.recipes`, `permissions.preset.*_hint`, `settings.ai_calls.kind_recipe`

### Drafts needing native review (sk and pl)
1. Manual: `resources/manual/{sk,pl}/recipes.json` (all ten sections), `who-sees-what.json`, `admin.json`
2. Help: `resources/help/sk.json`, `resources/help/pl.json` (seven `recipes.*` topics, area and four screen names; sk "Receptár", pl "Receptarz")
3. `lang/sk.json`, `lang/pl.json`: every `recipes.*` key above, including the four-form plurals (`overview.loaded_more`, `card.servings`, `photos.some_failed`, `shopping.quantities_for`, `shopping.add_n`, `shopping.create_add_n`, `toast.items_added`), the delete and reset confirm texts, the import notice (`recipes.import.notice_body`, `hint`), and the starter category names

## Leftovers and notes from the 12-xx summaries (no action taken)
- 12-08: categories maximum is 3 (config), the UI-SPEC text said 5. `recipes.error.forbidden` is defined once and reused by 12-09.
- 12-09: `UiProgress` is 8 px high, the two section bars are shortened by class to 4 px; a typed servings value above 99 is clamped on blur; `PhoneProbe.vue` exists because AppShell provides shell state only inside its slot.
- 12-10: the shopping dialog size is set by an unscoped `:has(.rec-shop)` style, verified only in jsdom, so the staging check must look at it (Task 3 step 9). A row with an amount but no unit is added as pcs; `suggestedUnitId` of the preview is therefore unused. Editing a Matched row's name does not change the match.
- 12-11: no `maxLength` in the recipe AI schema (Codex strict subset), limits are enforced by `RecipeValidator`; a well-formed "not a recipe" reply does not trigger the Copilot repair call.
- 12-12: mbstring has no Windows-1250 in this PHP build, decoding uses the iconv path.
- 12-13: `ai_notice_acks.providers` was made nullable so a recipe acknowledgment never satisfies the medical notice.
- 12-15: the page photo is not taken over on import (decision D14); a Dismiss button on failed drafts and a generic failure text were added beyond the UI-SPEC.
- Phase 8 `PdfRasterizerTest` 1 ms timeout test is timing sensitive in the full suite (see gate results).

## Decision record for the owner (issue sections 7 and 8; the orchestrator copies it into the PR body)

| Topic | Decision |
|---|---|
| Default grants | Parent, Child, Grandparent edit; Guest view; backfilled from To-do (D1) |
| Private or shared | Only the author edits; no administrator bypass (D18) |
| Deleting a shared recipe | Author only; removes everyone's notes and ticks; shopping lists stay unchanged (D19) |
| Categories | Free text with a starter set, at most 3 per recipe |
| Units | The Shopping unit list |
| Servings | Optional, 1 to 99; display-only recalculation; friendly fractions for spoons and rows without a unit, decimals for pieces; rows without an amount never scale; no per-ingredient do-not-scale flag |
| Progress | Per member (ingredient ticks, step ticks) with Reset; no expiry (D15) |
| Search and filters | Title and ingredient search with accent folding; filters for category, author, ingredient, mine/shared, favourites (D16) |
| Phone navigation | Seven-item bottom bar uses dense labels (D4) |
| Photos | Up to 10 per recipe, 5 MB each, private, re-encoded |
| Page fetch | In the worker container through the SSRF-safe fetcher, honest user agent, 2 MB page cap, JSON-LD first, hidden text stripped (D6, D11, D12) |
| AI import | `ai` queue, fail fast on quota, one-time notice per enabled provider set, no consent switch (owner); drafts deleted after 24 h, rows after 7 days (D7, D8, D9) |
| AI calls table | Shows "Recipe import" without URL or content (D10) |
| Add to shopping | New or open list; catalog match exact or single suggestion; rows without an amount as 1 pcs; all-or-nothing; notes not copied |
| Import limits | 2 active per member, 20 per day per member, 10 global |

### Risk answers (issue section 8)
- Extraction reliability: automated tests cover JSON-LD, microdata, text fallback and charsets; the real-AI result on 10 public URLs is Task 3 step 13 (table to be added here by the orchestrator).
- SSRF: the shared SafeFetcher with DNS pinning, redirect and size caps, run in the worker container only.
- Prompt injection: page text is untrusted; hidden text stripped; narrow sandbox, no secrets in the ai-worker; output validated against a schema and the recipe validator before it reaches a draft.
- Ingredient and unit recognition: unit paste matching with aliases in en, sk, pl; unknown lines keep the original line in the review form for manual correction.
- Catalog matching quality: only exact or single-suggestion matches are tagged, the member sees and can change each row before adding.
- Recalculation limits: display only, fractions rounded to friendly values, no unit conversion between families.
- Access rules: per-module grant plus record rules (private recipes 404 to others, photos only through authenticated routes), covered by the endpoint matrix and privacy tests.

### Backlog (not built)

| Item | Note |
|---|---|
| Page photo import | Import by photo of a cookbook page |
| JSON-LD-only prefill without AI | Fast path when the page has complete JSON-LD (D20) |
| Taking over the page photo on import | D14 |
| Cooking history | "Cooked on" log per recipe |
| Meal planning | Weekly planner with one combined shopping list |
| Tags | Beyond the free-text categories |
| Ingredient groups | "For the dough", "For the sauce" (D17) |
| Cheaper model setting for text runs | Separate model choice for recipe text runs |

## Known Stubs
None.

## Pending
Task 3 (orchestrator): push `develop`, wait for the staging deploy of this HEAD, run browser steps 1-14 (360, 390, 820, 1280 px; en plus one pass in sk and pl) and read-only server checks 15-17 (counts, `schedule:list` shows `recipes:reap-imports` and `recipes:prune-imports`, `staging:audit`), recording pass or fail per step and the import check list table. Any fix after that carries `Refs #9`.

## Self-Check: PASSED
Commits 6d71bc3 and a938016 and the three test files verified present.
