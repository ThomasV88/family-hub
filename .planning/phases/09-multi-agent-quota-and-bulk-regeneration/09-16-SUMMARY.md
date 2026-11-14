---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 16
subsystem: admin-settings-ui
tags: [ai-agents, vue, a11y, settings]
requires: ["09-01", "09-12"]
provides: [Settings/AiAgents page, AgentCard, per-agent AiLoginHealthCard]
affects: [09-18]
key-files:
  created:
    - resources/js/Pages/Settings/AiAgents.vue
    - resources/js/Settings/AiAgents/AgentCard.vue
    - resources/js/Settings/AiAgents/agentNames.js
    - tests/js/settings/AiAgents.test.js
  modified:
    - resources/js/Settings/AiCalls/AiLoginHealthCard.vue
    - resources/js/Pages/Settings/AiCalls.vue
    - tests/js/settings/AiCalls.test.js
    - tests/js/a11y-sweep.test.js
requirements: [AI-05]
completed: 2026-11-14
---

# Phase 9 Plan 16: AI agents settings page Summary

UI-SPEC Screen 1: ordered agent cards (switch, Move up/down, model, login health, cooldown, warnings) with per-card saving, announcements and toasts, and the login card moved out of the AI calls page.

## Commits
- feat(09-16): AI agents settings page with ordered agent cards and per-agent login health (single task commit, all three tasks)

## What was built
- `Settings/AiAgents.vue`: intro, `<ol aria-label>` in saved order, apply note with "Open AI calls" link, empty, load-error and skeleton states, one `role=status` region plus toast per success. Saves go through `securityFetch` (PATCH update, POST move with `expectedPosition`); the response `agents` replaces the list (also on 422 and 409). No optimistic reorder; one save at a time (other cards inert while one saves).
- `AgentCard.vue`: header (position badge aria-hidden, h3, On/Off tag, Saving status), switch with helper, last-agent lock line, Copilot provider reminder, order buttons with position names, model form (client validation with the server pattern, Enter saves, error cleared on change), health card, warn alert for enabled-but-not-signed-in (with Check again that drives the health card), cooldown tag and "Paused until" with `<time>`. Controls use `aria-disabled`, never native disabled, so focus is never lost; after a move focus goes to the same-direction button or the opposite one at an end.
- `AiLoginHealthCard.vue`: new required `agent` prop, h4 "{agent} login", expired help names the agent, the single-agent line removed, reloads only `agents`, announces only state changes.
- `AiCalls.vue`: login card removed, replaced by the muted pointer line linking to AI agents. `login` prop dropped.
- a11y sweep registers `Settings/AiAgents` (two agents, Copilot on and not signed in, Codex cooling down), light and dark, en/sk/pl, 390 and 1280 px.

## Decisions and deviations
- `settings.section.ai_agents` and all `settings.ai_agents.*` keys already existed in EN/SK/PL (added by 09-01), so no lang edit was needed. Transition announcements of the health card reuse existing keys as "{agent}: {state tag}" instead of new sentences.
- SettingsLayout.vue and Settings/Index.vue already mapped the `bot` icon (the section's icon name is `bot`), so they were left unchanged.
- The layout already renders the page h1 "AI agents"; the spec h2 is a visually hidden h2 with the same text, which keeps heading order valid (h1, h2, h3 cards) without a duplicate visible title.
- `UiInput` always renders `placeholder=""` (its default), so the model field has an empty placeholder attribute rather than none; no text is shown.
- The health card's "No answer yet" text is the existing `settings.ai_login.no_answer` string.
- A 423 gate refusal is treated as a save error (nothing is replayed); the existing confirm flow is not wired here.
- The two-column card layout uses the settings container query at 800 px (content frame max 720 px per spec).
- Rule 1: a test-side fix only (fixtures); no production deviations.

## Verification
Vitest in `node:22-alpine` (own files only): AiAgents.test.js, AiCalls.test.js, SettingsShell.test.js, a11y-sweep.test.js: 4 files, 145 tests passed. No Pest was run (no PHP changes).

## Known Stubs
None. The bulk panel is added below the list by 09-18.

## Self-Check: PASSED
