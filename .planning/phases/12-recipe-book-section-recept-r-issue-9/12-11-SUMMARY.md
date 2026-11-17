---
phase: 12-recipe-book-section-recept-r-issue-9
plan: 11
subsystem: recipes-ai-foundation
tags: [recipes, ai, codex, copilot, schema, validator, ai-calls, arch-test]
requires: ["12-02"]
provides:
  - "AiTask seam (schemaPath, prompt, inlinePrompt, validateFile, inspect) carried on AgentInput::$task, MedicalTask as default"
  - "RecipeTask, RecipeValidator (ruleTable), RecipePromptBuilder, recipe.v1 schema and prompts"
  - "ai_calls.kind ('medical' | 'recipe', immutable) with nullable subject_id and document_id under a CHECK"
  - "FailureCode NoRecipe and FetchFailed"
affects: [12-13, 12-15]
key-files:
  created:
    - app/Medical/Ai/AiTask.php
    - app/Medical/Ai/MedicalTask.php
    - app/Recipes/Ai/RecipeTask.php
    - app/Recipes/Ai/RecipeValidator.php
    - app/Recipes/Ai/RecipePromptBuilder.php
    - resources/ai/recipe.v1.schema.json
    - resources/ai/recipe.v1.prompt.md
    - resources/ai/recipe.v1.inline-json.md
    - database/migrations/2026_11_29_120000_add_kind_to_ai_calls.php
    - tests/Unit/Ai/AiTaskSeamTest.php
    - tests/Unit/Recipes/RecipeValidatorTest.php
    - tests/Unit/Recipes/RecipePromptBuilderTest.php
  modified:
    - app/Medical/Ai/Agents/AgentInput.php
    - app/Medical/Ai/Agents/CodexAgent.php
    - app/Medical/Ai/Agents/CopilotAgent.php
    - app/Medical/Ai/PromptBuilder.php
    - app/Medical/Ai/FailureCode.php
    - app/Medical/Queries/AdminAiCallQuery.php
    - database/factories/AiCallFactory.php
    - tests/Unit/Arch/AiArchTest.php
    - tests/Feature/Ai/AiCallsGuardTest.php
    - tests/Feature/Settings/AiCallsPageTest.php
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 12 Plan 11: AI foundation for the recipe import Summary

The two agents can now run a recipe extraction through a small task seam while the medical path stays byte for byte what it was: no default changes, the runners (`CodexRunner`, `CopilotRunner`) are untouched, so the lockdown (read-only sandbox, no tools, `env -i`, no API keys) is unchanged.

## Commits
- e47b686 AiTask seam, MedicalTask, agents read the task from AgentInput (`Refs #9`)
- 191b86d ai_calls kind migration, factory, admin query, failure codes, guard and page tests (`Refs #9`)
- 15f8bb3 recipe schema, prompts, validator, task, arch rule (`Refs #9`)

## Tests
Isolated compose project `fh1211` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v`. Pint on touched PHP: pass. Whole `tests/Unit/Ai` and `tests/Feature/Ai` (medical, unchanged) green, plus the new tests; full serial Pest: 3510 passed, 1 skipped. Vitest not run: no JS, lang, help or manual file touched.

## User documentation
No user-visible change: backend contract only. The "Recipe import" label of the admin AI calls page is added by 12-15; this plan only returns `kind` from the query (the page shows it as before; the row for a recipe call has no person). No `resources/manual/`, help or lang file touched.

## Security notes
- Page text is data: nonce markers (`bin2hex(random_bytes(8))` per `RecipeTask`, never stored), the nonce is spelled out in the rules, every `<<<` in the text is broken up to `< < <`, the prompt is cut on a line boundary so the longest (inline) prompt stays within `recipes.import.max_prompt_bytes`.
- Output is untrusted: strict structure (exact keys and types) then cleaning (tags, control, zero-width and bidi characters), length and range limits, unit allowlist. Issue codes name rule-table fields only, never a value.
- `ai_calls` stays content-free (no URL column, none added); `kind` is in the immutable identity of the guard function; rollback is refused while recipe rows exist.
- `AiArchTest` now bans `Log::`, `logger(`, `report(`, `dd(`, `dump(`, `error_log(` in `app/Recipes/Ai` and `app/Recipes/Import` (the latter skipped until it exists) and asserts the scanned directories are not empty.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] No `maxLength` in the recipe schema**
- **Found during:** Task 3
- **Issue:** The plan's schema contract lists `maxLength` limits, but Phase 8 research and the medical schema (and its drift test) establish that the Codex `--output-schema` strict subset does not support `maxLength`; a rejected schema would make every Codex import fail.
- **Fix:** The schema carries the numeric bounds and `maxItems`; text limits are in the prompt and enforced by `RecipeValidator` (cut at a character boundary). The drift test asserts the schema has no `maxLength`.
- **Files modified:** resources/ai/recipe.v1.schema.json, tests/Unit/Recipes/RecipeValidatorTest.php

**2. [Rule 1 - Bug] NoRecipe must not trigger the Copilot repair call**
- **Found during:** Task 3
- **Issue:** A well-formed `is_recipe: false` reply has no structural issue codes; `CopilotAgent::map` would have treated it as "invalid" and spent a repair call.
- **Fix:** One guarded branch in `CopilotAgent::map` that returns `NoRecipe` as final. Medical never produces that code, so its behaviour is unchanged. Codex already passes `outcome->failure` through.
- **Commit:** 15f8bb3

**3. [Rule 3 - Blocking] Existing tests listing the call columns and row keys**
- **Issue:** `AiCallsGuardTest` ("no column that could hold content") and `AiCallsPageTest` (`hasAll` on the row keys) enumerate columns, so `kind` had to be added; both were extended (plan listed AiCallsGuardTest, not AiCallsPageTest). `AiSchemaTest` and `AiPhase9SchemaTest` needed no change.
- **Commit:** 191b86d

**4.** `PromptBuilder::ISSUE_PATTERN` made public so the recipe builder reuses the exact filter (no behaviour change).

## Decisions and notes for later plans
- `RecipeValidator::UNITS` is the allowed unit key list (the built-in Shopping units); `RecipeTask::__construct(string $pageText, string $sourceKind = 'url')`; `sourceKind` is limited to `url|text|file` and only appears as a label line.
- Normalised payload: `quantity` is a float rounded to 2 decimals or null, text fields null when empty after cleaning, empty list items dropped, lists cut to their maximum, categories deduplicated by `NameKey`. `is_recipe: true` needs at least one ingredient and one step after cleaning, else `invalid_output`. A quantity given as text (for example "1/2") is `invalid_output` (strict types), an out-of-range number becomes null.
- The `ai_calls` recipe rows are written by 12-13 with `kind = 'recipe'`, `subject_id` and `document_id` null; the person filter never matches them.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-12-41 to T-12-46 covered by RecipePromptBuilderTest, RecipeValidatorTest, AiArchTest, AiCallsGuardTest and the unchanged medical suites).

## Self-Check: PASSED
