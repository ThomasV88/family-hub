---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 18
subsystem: bulk-regeneration-ui
tags: [ai, bulk, vue, a11y, admin, dry-run]
requires: ["09-13", "09-16"]
provides: [BulkRegenPanel, BulkProgress, BulkHistoryTable, useBulkStatus]
affects: [09-19]
key-files:
  created:
    - resources/js/Settings/AiAgents/BulkRegenPanel.vue
    - resources/js/Settings/AiAgents/BulkProgress.vue
    - resources/js/Settings/AiAgents/BulkHistoryTable.vue
    - resources/js/Settings/AiAgents/useBulkStatus.js
    - tests/js/settings/AiBulk.test.js
  modified:
    - resources/js/Pages/Settings/AiAgents.vue
    - tests/js/settings/AiAgents.test.js
requirements: [AI-07]
completed: 2026-11-14
---

# Phase 9 Plan 18: Bulk re-generation panel Summary

UI-SPEC Screen 3: scope and cap, mandatory dry run with a freshness rule, guarded Start with a provider-naming confirm, active-run progress with Pause/Resume/Cancel, 15 s visible-only polling, and a counts-only history, all on the 09-13 endpoints.

## Commits
- b38c9ff feat(09-18): bulk re-generation panel with mandatory dry run, guarded start, progress controls and history (all three tasks, one commit)

## What was built
- `BulkRegenPanel.vue`: block A (custom `role=radiogroup` with roving tabindex and arrow keys, number cap field with blur and check validation, dry-run button with aria-busy, result section with focus on its h3, one-time announcement, days estimate, `<time>` checked-at), freshness (succeeded, scope and cap unchanged, validity timer, no server 409/410), Start as `aria-disabled` with a permanent `aria-describedby` reason line, confirm dialog (providers wording, count, cap, hour, optional "no AI tool signed in" note, "Do not start" focused), start outcomes (201, 409 active, 409/410 stale, error), block B swap, finished alert with three count rows, single status region. Token and result live in component memory only.
- `BulkProgress.vue`: state tag (icon plus text, danger for stopped; `<time>` for next batch and quota wait), h3 "Progress", `UiProgress` with `aria-valuetext` = the plural sentence, counts `<dl>`, "Today" line, Pause/Resume with accessible names "Pause bulk run" and "Resume bulk run" (one persistent button, so focus stays), "Cancel bulk run" with a non-danger confirm and "Keep running" focused, poll error with "Try loading again".
- `useBulkStatus.js`: polls `settings.ai_agents.bulk` every 15 s only while a run is open and the tab is visible, immediate refresh on becoming visible, stops after three consecutive errors until retry, derives transitions (paused, resumed, waiting, finished, ended) for announcements; own actions adopt responses quietly.
- `BulkHistoryTable.vue`: eight columns, newest first, capped at 10, `ListChecks` empty state, skeleton and error states, reads only the contract fields.
- `AiAgents.vue`: new `bulk` and `bulkConfig` props, panel rendered below the list (after the page status region).

## Decisions (defaults picked)
- Confirm dialogs use `UiDialog` with a primary confirm button, because `UiConfirmDialog` hard-codes a danger confirm button and the spec requires non-danger.
- Cap field is `type=number` (min 1, max from config, inputmode numeric); empty or non-numeric reads as the same cap error.
- Expiry timer starts at client receipt of the dry run (immune to clock skew); the displayed "Checked at" is the server time; the server stays the final word.
- Waiting tag reuses `health.ai.status.waiting` and `waiting_announce` (with `<time>`) rather than new keys; control failures reuse `settings.ai_agents.save_error`. No lang file changes were needed (09-01 had every key).
- History error state is driven by a `loadFailed` prop or a failed history retry; the page does not currently set it.
- Cancelled-by-another-admin ends polling and returns the plan block without an announcement.

## Deviations from Plan
**1. [Rule 3 - Blocking] AiAgents.test.js adjusted.** The existing assertion `findAll('h3')` equals the two agent names now also sees the history h3. Scoped it to `ol h3`. File was not in files_modified; one-line test change only.

## Verification
Vitest in `node:22-alpine`: AiBulk.test.js and AiAgents.test.js (106 passed), plus a11y-sweep.test.js (62 passed, AiAgents page included). axe covers plan (no result, fresh, zero, stale, error), active (running, paused, next batch, waiting, stopped, finished), confirm and cancel dialogs, empty, loading and error history, light and dark, en, sk, pl. Plural keys checked for 0, 1, 2, 4, 5, 12, 22, 25 in en, sk, pl. Canary tests confirm extra fields (startedBy, documentId, titles) never render. No Pest was run (no PHP changes). The full suite and build are left to 09-19.

## Known Stubs
None.

## Threat flags
None. T-09-71 to T-09-73 mitigated as planned (aria-disabled Start until fresh check, confirm with providers and cap, contract-field-only rendering, no storage APIs).

## Self-Check: PASSED
