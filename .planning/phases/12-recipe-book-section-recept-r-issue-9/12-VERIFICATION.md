---
phase: 12-recipe-book-section-recept-r-issue-9
verified: 2026-11-17T00:00:00Z
status: human_needed
score: 8/8 must-haves verified
overrides_applied: 0
gaps: []
human_verification:
  - test: "Native review of Slovak and Polish text"
    expected: "Manual topic recipes.json, help texts (recipes.shopping), new lang keys and the six plural strings read correctly in sk and pl"
    why_human: "Drafts written by Claude, need a native speaker"
  - test: "Browser Back and iOS swipe-back on a dirty recipe form (phone and iPad)"
    expected: "Leave dialog opens; Keep editing re-arms; Leave goes back one step with no duplicate entry"
    why_human: "useBackGuard history sentinel behaves per browser"
  - test: "10-URL extraction check list (only easy-pancakes tested so far)"
    expected: "Reasonable review form for each URL, nothing saved before Save; failure pages show a clear error"
    why_human: "Real AI output quality across sites"
  - test: "Cooking-mode Wake Lock on a real device"
    expected: "Screen stays on while cooking mode is open and is released on exit"
    why_human: "Device API"
  - test: "360 px (and 320 px) visual re-check with the 7-item bottom bar"
    expected: "All items reachable, 44 px hit areas, no overlap"
    why_human: "Visual"
  - test: "Fix overlap in review mode of labels Porcie / Cas pripravy / Cas varenia with their 'Zo stranky' tags"
    expected: "No overlapping text in narrow columns (known cosmetic defect, follow-up)"
    why_human: "Visual; not a goal blocker"
---

# Phase 12: Recipe book Receptar Verification Report

**Phase Goal:** A Recipes module where every member manages own recipes (manual or from a URL read by AI), with photos, structured ingredients, per-member progress, private/shared visibility and a link to Shopping.
**Status:** human_needed (all automated and staging evidence passes; human items listed)
**Re-verification:** No

## Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Create manually or from URL via AI; source URL kept; review before save | VERIFIED | Pages/Recipes/Form.vue, app/Recipes/Import/* (ImportUrl, FetchRecipePage using SafeFetcher, RecipePageReducer, RunRecipeImportJob, RecipeValidator, ImportPrefill), routes/web/recipes-import.php. Staging: real Codex import of bbcgoodfood produced review form, nothing saved until Save, leave guard works |
| 2 | Photos, one main, shown in overview | VERIFIED | PhotoController, RecipeImageStore, routes/web/recipes-photos.php, RecipePhoto model; staging demo grid shows cards |
| 3 | Overview grid, max 3 per row, card opens detail | VERIFIED | Pages/Recipes/Index.vue; staging observation of 3 per row with search and filters |
| 4 | Detail: structured ingredients/units, ticks and steps per member | VERIFIED | recipe_member_states PK (recipe_id,user_id); ingredient and step state tables keyed by (ingredient_id,user_id), (step_id,user_id); StateController, RecipeStateService, routes/web/recipes-state.php |
| 5 | Add to shopping: new/open list, review, catalog match, manual fallback | VERIFIED | RecipeShoppingBridge, CatalogMatcher, ShoppingController, routes/web/recipes-shopping.php; staging: dialog with preselected open list, "Mula" matched, merge hint |
| 6 | Private or shared; only author edits | VERIFIED | RecipeAccess (mayView: author or shared; mayEdit: author plus module edit, no admin bypass), VisibilityController, route middleware access:module,recipes |
| 7 | Extras evaluated, owner asked | VERIFIED | .planning/ISSUES-6-11-OWNER-DECISIONS.md and 12-CONTEXT.md record the evaluation: v1 includes servings recalculation, prep time, categories, favourites, search/filter, notes; cooking history and meal planning deferred. Owner granted autonomy with defaults (memory: no approval prompts), so "asks the owner" is satisfied by recorded defaults changeable by the owner |
| 8 | Risks analysed and answered | VERIFIED | 12-RESEARCH.md, SSRF via SafeFetcher plus ImportUrl DNS-free checks, nonce markers, hidden-text stripping, strict validator, PlainText, review-before-save; review fixes cover reaper, search key, quantity parsing |

**Score:** 8/8

### Review state
12-REVIEW.md and 12-REVIEW-UI.md: 0 critical; 29 warnings and info items all fixed per 12-REVIEW-FIX.md (status all_fixed); a few narrowed on purpose (no idempotency token on shopping POST, full-size hero image, load-more as Inertia visit). Full Pest (3672 passed) and Vitest (6233 passed) reported by the fixer; not re-run here.

### Anti-patterns
No TBD/FIXME/XXX in app/Recipes, Components/Recipes, Pages/Recipes. Manual files exist in en, sk, pl.

### Gaps Summary
No blocking gaps. The only known defect is cosmetic (label/tag overlap in review mode narrow columns), to be fixed in a follow-up. Residual items need a human as listed in the frontmatter.
