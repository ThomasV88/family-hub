---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 15
subsystem: recipes-import-ui
tags: [recipes, import, ui, vue, notice, review, a11y, i18n, manual, help]
requires: ["12-10", "12-14"]
provides:
  - "NewRecipeDialog (Type it in / Read from a web page) and ImportFromUrlDialog (one-time notice, progress, cancel, failures)"
  - "useImportStatus polling helper"
  - "ImportDraftAlerts and ImportDraftRow on the overview"
  - "Form review mode (prefill prop) with From the page tags"
  - "Recipe import label on the admin AI calls table"
  - "Strings, help tip recipes.import, manual section import-from-link, administrator guide text"
affects: [12-17]
key-files:
  created:
    - resources/js/Components/Recipes/NewRecipeDialog.vue
    - resources/js/Components/Recipes/ImportFromUrlDialog.vue
    - resources/js/Components/Recipes/ImportDraftAlerts.vue
    - resources/js/Components/Recipes/ImportDraftRow.vue
    - resources/js/Components/Recipes/useImportStatus.js
    - tests/js/recipes/useImportStatus.test.js
    - tests/js/recipes/ImportFromUrlDialog.test.js
    - tests/js/recipes/NewRecipeDialog.test.js
    - tests/js/recipes/FormReview.test.js
  modified:
    - resources/js/Pages/Recipes/Index.vue
    - resources/js/Pages/Recipes/Form.vue
    - resources/js/Components/Recipes/IngredientRows.vue
    - resources/js/Components/ui/UiField.vue
    - resources/js/Components/ui/UiProgress.vue
    - resources/js/Settings/AiCalls/AiCallsTable.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/manual/en/recipes.json
    - resources/manual/sk/recipes.json
    - resources/manual/pl/recipes.json
    - resources/manual/en/admin.json
    - resources/manual/sk/admin.json
    - resources/manual/pl/admin.json
    - tests/js/recipes/Overview.test.js
    - tests/js/settings/AiCalls.test.js
    - tests/js/i18n-phase12.test.js
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 12 Plan 15: Import UI Summary

Members can start a recipe import from a link (choice dialog, one-time notice that the page text goes to the AI provider, progress, cancel, background drafts on the overview, every failure with its own text), review the prefilled form with "From the page" tags before saving, and administrators see the runs as "Recipe import" without any address or content.

## Commits
- 05805c2 strings, help tip, manual and administrator guide (`Refs #9`)
- 8be12d3 useImportStatus, NewRecipeDialog, ImportFromUrlDialog, indeterminate UiProgress (`Refs #9`)
- 0bc7b5a draft rows, form review mode, AI calls label (`Refs #9`)

Task order: the strings task was committed first because the component tests read the lang, help and manual files; each commit is green on its own.

## What was built
- **useImportStatus**: GET `/recipes/imports/{id}` every 2 s; the next poll is scheduled only after the answer (never two in flight); paused while `document.hidden` (one immediate check when shown again); stops on ready/failed/cancelled/used, on 403/404/419, after 5 failed polls in a row, after 3 minutes (`timedOut`) and with its scope (unmount). A stale answer after stop is ignored.
- **NewRecipeDialog**: two 64 px rows. The overview skips it (straight to `/recipes/create`) when `import.available` is false or missing.
- **ImportFromUrlDialog**: field (`type=url`, `inputmode=url`, client http/https check, max 2000), the one-time notice (recipe acknowledgement only: POST `/recipes/imports/notice` with the providers; 409 shows the notice again with the new providers; 403/419/429/5xx have texts), the permanent hint with `HelpTip recipes.import`, start (202 progress; 409 notice_required and unavailable; 429/limit; 422; 403; 419; others), progress with live region, cancel (posts cancel; a failed cancel stays open with a text), close during loading keeps the import running (`background`, the overview reloads its drafts), Escape and close are disabled while the start request runs, double-submit guards on start, notice and cancel, focus moves to the field, the status or the failure. Failures: fetch and no_recipe offer Change address, others Try again, the limit offers none; Type it in goes to `/recipes/create?source=<encoded url>`.
- **Overview**: `ImportDraftAlerts` (own drafts: reading polled through `ImportDraftRow`, ready with Review and Discard confirm, failed with Try again, Type it in and Dismiss), reloads `drafts` and `import` through `router.reload` once when a draft ends, announces a draft that became ready or failed in a live region, DELETE failures (403/419/429/5xx) show a toast, 404 reloads silently.
- **Form review mode** (`prefill.importId` set): title Check the recipe, info banner, `From the page` tag (Sparkles) on title, description, servings, times, categories, ingredients and steps while the value is unchanged, `Not found on the page.` for empty fields the page had nothing for, the original line under each ingredient as plain text (never sent), source as a link with its host and an Edit button (http/https only), long-page hint, `import_id` in the save body, leaving always asks (the draft is not saved). An empty servings stays empty in review mode. `?source=` prefill (no importId) fills only Source in the normal form.
- **AI calls**: `row.kind === 'recipe'` shows "Recipe import" in the person column; test asserts no URL, host or title is rendered.
- `UiField` gained a `label-after` slot (for the tag) and `UiProgress` an `indeterminate` mode (no `aria-valuenow`, still under reduced motion).

## User documentation
- Manual: new section `import-from-link` in `recipes.json` after `adding` (same ids, block types, item counts in en, sk, pl); step 1 of `adding` now describes the choice dialog; `admin.json` section `ai-agents` gained the recipe import paragraph (same settings, ceiling and quota, listed as Recipe import without address or content, one-time notice, no per-person switch) and mentions recipe pages.
- Help: topic `recipes.import` (screen `recipe_import`, field `url_step`, under 400 characters) in `topics.json`, `en.json`, `sk.json`, `pl.json`.
- Lang: `settings.ai_calls.intro` and `settings.ai_agents.intro` now mention recipe pages (all three languages).
- Touched `resources/manual/` files: en/sk/pl `recipes.json` and `admin.json`. Touched `resources/help/` files: `topics.json`, `en.json`, `sk.json`, `pl.json`.
- **sk and pl texts are drafts and need native review** (lang `recipes.new`, `recipes.import`, `recipes.review`, `recipes.drafts`, `settings.ai_calls.kind_recipe`, the two intros, help tip, both manual files).

## Tests
- Vitest full run (docker node 22): 187 files, 6037 tests passed (a11y sweep, manual coverage, help coverage, i18n-phase12 and the new tests included). New: useImportStatus (11), ImportFromUrlDialog (32, including axe), NewRecipeDialog (6), FormReview (12), Overview (+17), AiCalls (+4).
- `tests/Feature/Manual` and `tests/Feature/Help` (project `fh1215`): 49 passed.
- Full serial Pest in project `fh1215` (own Postgres, scratch folder over storage/logs, removed with `down -v`): 3640 passed, 1 skipped.
- No PHP file touched, so Pint was not needed.

## Deviations from Plan
- **[Rule 3 - Blocking] AI calls label placed in `AiCallsTable.vue`**, not `AiCalls.vue`: the person cell is rendered by the table component, and the row already carries `kind` from `AdminAiCallQuery`. `AiCalls.vue` is unchanged.
- **Extra files** not in `files_modified`: `ImportDraftRow.vue` (one poller per active draft, at most 2 per member), `IngredientRows.vue` (original line), `UiField.vue` (`label-after` slot), `UiProgress.vue` (indeterminate), `tests/js/i18n-phase12.test.js` order assertion updated because `import-from-link` now sits between `adding` and `photos` as the plan demands.
- **Dismiss button on a failed draft** (not in the UI-SPEC): a failed draft otherwise stays on the overview for 24 hours; Dismiss sends the same DELETE that marks it dismissed.
- **Generic failure text** `recipes.import.err_generic` for the 3-minute give-up and unknown failures (the plan only says "generic failure"); `err_start` for a start that fails for an unclassified reason.
- Discard confirm body text is new (the UI-SPEC only gave title and buttons): "The recipe read from the page is thrown away. Nothing was saved."
- The page photo is not part of v1 (orchestrator decision); not built.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model: T-12-62 (no `v-html`; test with markup in the original line, an ingredient name, a draft host and the address), T-12-63 (notice tied to the enabled provider set, permanent hint, manual), T-12-64 (label only; test asserts no URL, host or title), T-12-65 (2 s interval, paused when hidden, 3 minute stop, one request in flight).

## Self-Check: PASSED
