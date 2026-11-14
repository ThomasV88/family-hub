---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 06
subsystem: medical-ai
tags: [codex, quota, ai-06]
requires: ["09-04"]
provides: ["Codex QuotaExhausted outcome with reset time", "CodexAgent model override and deadline timeout"]
key-files:
  modified:
    - app/Medical/Ai/CodexRunner.php
    - app/Medical/Ai/Agents/CodexAgent.php
    - tests/Support/FakeCodex.php
    - tests/Unit/Ai/CodexRunnerTest.php
    - tests/Unit/Ai/CodexAgentTest.php
decisions:
  - "CodexRunner::exec gained an optional third argument ?float $timeout (min of it and config) so the agent can pass a deadline-derived timeout; the runner is final so the agent gets it via this argument rather than config mutation."
  - "FakeCodex::quotaWithValidResult takes ($stderr, $json) because the golden payload lives in the test, not the fake."
  - "Deadline below ai.timeouts.min_call returns a Timeout outcome with duration 0 without spawning."
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 9 Plan 06: Codex quota, model and deadline Summary

Codex runner now flags an exhausted usage limit (stderr and error/turn.failed events only, TZ=UTC for deterministic reset times) and CodexAgent maps it to QuotaExhausted with reset time and minutes, accepts an optional admin model, and fits its timeout in the job deadline.

## Commits
- a13acc5: feat(09-06): detect Codex usage limit, take admin model, respect job deadline

## What changed
- CodexRunner: `TZ=UTC` in env allowlist; `QuotaSignatures::match('codex', ...)` beside both `matchesAuth` calls (first hit kept); `ProcessResult` filled with quota members; `isValidModel` delegates to `ModelId`; result file and stdout answer never scanned.
- CodexAgent: constructor `(?CodexRunner, ?string $model)`; invalid override ignored; mapping order aborted, timeouts/cap, valid result, quota with non-zero exit, auth, then Phase 8 mapping; recorded model is the override or configured model, never echoed text.
- FakeCodex: `quota`, `quotaEvent`, `quotaInAnswer`, `quotaWithValidResult`.

## Verification
CodexRunnerTest and CodexAgentTest: 48 passed (container project fh0906, removed afterwards). Pint clean. Only own tests run.

## Deviations
None beyond the decisions above (small signature additions, no rule-triggering issues).

## Known Stubs
None.

## Self-Check: PASSED
