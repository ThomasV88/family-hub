---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 17
subsystem: ui
tags: [vue, ai, waiting, provenance, polling, notice]
requires: ["09-01", "09-11"]
provides:
  - Waiting-for-AI-quota tag (time, soon, short), Cancel run, quota failure details
  - "Read by <agent> <model>" provenance line in panel and review header
  - Provider-aware permanent line and notice variants (codex, multi, github)
  - 60 s waiting poll cadence with visibility pause and 60 min stop
key-files:
  created:
    - resources/js/Health/Ai/aiWhen.js
    - resources/js/Health/Ai/AiProvenanceLine.vue
    - tests/js/health/AiWaiting.test.js
  modified:
    - resources/js/Health/Ai/AiStatusTag.vue
    - resources/js/Health/Ai/useAiStatus.js
    - resources/js/Health/Ai/RunAiControl.vue
    - resources/js/Health/Ai/AiNoticeDialog.vue
    - resources/js/Pages/Health/AiReview.vue
    - tests/js/health/AiStatusPolling.test.js
    - tests/js/health/AiDocumentRows.test.js
    - tests/js/health/RunAi.test.js
    - tests/js/health/AiReview.test.js
decisions:
  - "Family time zone is the existing Europe/Bratislava constant (same as AiCallsTable); formatWhen takes locale as sk/pl/en and maps to INTL_LOCALES."
  - "Agent display names (OpenAI Codex, GitHub Copilot) live in AiProvenanceLine as a fixed map; unknown agent renders nothing."
  - "useAiStatus tracks visible waiting time (hidden time does not count towards the 60 min). `stalled` is separate from `stoppedLongWait`: waiting shows health.ai.status.still_waiting, queued/running keep the Phase 8 run.still_waiting text."
  - "A schedule change (waiting to queued/running or back) restarts that schedule's clock."
  - "After cancelling a waiting run, focus returns to Run AI (applied to every cancel in RunAiControl)."
  - "Review: a waiting run opened directly shows a status block; Cancel run there returns to the document panel via router.visit. Newer-waiting cancel hides the tag locally and announces 'The run was cancelled'. A past newer-waiting time falls back to the 'retrying soon' text."
  - "Rows keep the short waiting text because no availableAt reaches AiStatusTag from TimelineList; no Person/DocumentPanel/TimelineList change was needed."
metrics:
  tasks: 3
  completed: 2026-11-14
---

# Phase 9 Plan 17: Waiting, provenance and provider disclosure UI Summary

Members now see when a document waits for AI quota and when it retries, who read a finished document, and which providers a run may use, with a 60 s bounded poll while waiting.

## Tasks

1. aiWhen.js, AiStatusTag waiting variants, AiProvenanceLine, useAiStatus waiting cadence. Commit ba34f60.
2. RunAiControl (waiting, failure codes, provenance, provider line, notice variant, stalled, focus after cancel) and AiNoticeDialog `variant`. Commit 009781e.
3. AiReview provenance line, newer-waiting tag with Cancel run, waiting status block. Commit adadc6a.

## Verification

Vitest in node:22-alpine, only this plan's five files: AiWaiting 22, AiStatusPolling 36, AiDocumentRows 10, RunAi 64, AiReview 81 = 213 passed (axe in en/sk/pl, light and dark, 320 px for review; literal `<b>` model; pseudo-long wrap). No Pest tests (frontend-only plan). Full suites and build run in 09-19.

## Deviations from Plan

None of substance. The "focus returns to Run AI after cancel" behavior was added to the shared cancel path (the contract required it for waiting; queued/running now behave the same).

## Known Stubs

None.

## Threat Flags

None. Agent and model are rendered by text interpolation only (T-09-68); the provider line and notice follow `ai.providers` (T-09-69); no storage, polling only while visible and bounded to 60 min (T-09-70).

## Notes

SK and PL strings used here come from 09-01 and remain flagged for native review.

## Self-Check: PASSED
