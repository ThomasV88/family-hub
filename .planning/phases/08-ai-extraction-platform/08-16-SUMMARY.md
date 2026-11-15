---
phase: 08-ai-extraction-platform
plan: 16
subsystem: ai
tags: [ai-run-ui, polling, notice, vue]
requires: ["08-01", "08-13"]
provides:
  - AiStatusTag, AiNoticeDialog, RunAiControl, useAiStatus
  - Person.vue creates one useAiStatus instance and hands it to DocumentPanel as `aiStatus` (08-23 reuses it for the lists)
affects: [08-23]
key-files:
  created:
    - resources/js/Health/Ai/AiStatusTag.vue
    - resources/js/Health/Ai/AiNoticeDialog.vue
    - resources/js/Health/Ai/RunAiControl.vue
    - resources/js/Health/Ai/useAiStatus.js
    - tests/js/health/AiStatusPolling.test.js
    - tests/js/health/RunAi.test.js
  modified:
    - resources/js/Health/DocumentPanel.vue
    - resources/js/Pages/Health/Person.vue
requirements-completed: [AI-01, AI-02]
completed: 2026-11-14
---

# Phase 8 Plan 16: Run AI control and status polling Summary

A member with Edit can start, follow and cancel the AI reading of a document from its panel: permanent "Sends this document to OpenAI." line, one-time notice with "Keep document private" as default focus, run states with one next action, and a polling composable over the JSON status route that keeps nothing in browser storage.

## Commits
- a2f97e2 feat(08-16): AI status tag, notice dialog and polling composable
- 0367eab feat(08-16): Run AI control in the document panel

## Verification
- AiStatusPolling.test.js (30) and RunAi.test.js (35) plus Documents.test.js (19) pass; axe clean for every state in en/sk/pl, light and dark, and at 320 px.
- Full Vitest (container, node:22-alpine, `--testTimeout=30000`): 126 files, 3596 tests passed. `npm run build` passed (SW precache OK).
- Full Pest (1G, container): 2243 passed (25651 assertions), 0 failed. No PHP file was changed by this plan.
- `grep localStorage|sessionStorage` over resources/js/Health/Ai returns nothing.

## Decisions (PROVISIONAL, for 08-22 review)
- Polling uses `/health/people/{id}/ai-status` as a hardcoded same-origin path (the repo has no `route()` helper); the response `{documents, available}` replaces the statuses wholesale, and `available` from the poll overrides the stale page prop.
- `useAiStatus` accepts values or getters; `failedPolls` is a consecutive-error counter (the UI shows the poll-failed line at 3), `markQueued()` shows "Waiting for AI" immediately after a 202, `refresh()` restarts the waiting clock.
- RunAiControl takes an extra prop `poller` (the page's useAiStatus instance) besides `{personId, document, ai, consentOn}`. DocumentPanel gets props `ai`, `aiStatus`, `consentOn`; without `ai`/`aiStatus` the control is not rendered, so other callers are unaffected.
- The replace confirm ("Replace these suggestions?") is a UiDialog with a primary confirm button, not UiConfirmDialog (whose confirm is danger): UI-SPEC says it must not be danger because nothing saved is lost. Default focus is "Keep current suggestions". It opens when pending > 0 and an earlier ready proposal exists (also after a failed re-run, since the old proposal survives).
- Block precedence for the dead button: HEIC/unsupported, then consent off, then unavailable. The button stays focusable with `aria-disabled`; pressing it announces the explanation and sends nothing. The sends line is replaced by the explanation line while blocked, and aria-describedby points at whichever is shown.
- "Go to person header" emits `go-header`; DocumentPanel closes the panel and then focuses the consent switch (`[data-testid=ai-switch]`) after 60 ms, because the open panel traps focus.
- 409 `notice_required` (server lost the acknowledgement) reopens the notice; any other failure shows the single `queue_failed` alert. A failed cancel reuses that alert text because no dedicated cancel-failed copy exists.
- Transition announcements: queued to running, ready, failed, cancelled; the value on mount and unchanged polls announce nothing.
- `health.ai.not_available` is untouched; its removal is part of 08-23.
- Provenance copy is not used in this plan (`health.ai.provenance.*` belongs to the review screens).

## Deviations from Plan
None of substance. The plan's Task 1 behavior list put the tag and dialog tests in AiStatusPolling.test.js; they are there (not in RunAi.test.js).

## Known Stubs
None.

## Threat Flags
None. T-08-77 to T-08-80 mitigated as planned (permanent sends line, safe-default notice, no storage APIs, server 409 wins over props, backoff plus visibility pause plus 15-minute stop).

## Self-Check: PASSED
Files and commits a2f97e2, 0367eab exist.
