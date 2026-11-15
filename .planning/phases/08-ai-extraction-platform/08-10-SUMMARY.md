---
phase: 08-ai-extraction-platform
plan: 10
subsystem: ai
tags: [codex, agent-registry, login-health, queue]
requires: ["08-03", "08-04", "08-05", "08-08"]
provides:
  - Agent interface, AgentInput, AgentOutcome, AgentRegistry (singleton), CodexAgent
  - AgentHealth (display, runnable, snapshot, recordCheck/AuthFailure/Success)
  - CheckLoginJob (unique, queue ai, database connection)
affects: [08-12, 08-14, 08-22]
key-files:
  created:
    - app/Medical/Ai/Agents/Agent.php
    - app/Medical/Ai/Agents/AgentInput.php
    - app/Medical/Ai/Agents/AgentOutcome.php
    - app/Medical/Ai/Agents/AgentRegistry.php
    - app/Medical/Ai/Agents/CodexAgent.php
    - app/Medical/Ai/AgentHealth.php
    - app/Medical/Ai/CheckLoginJob.php
    - tests/Unit/Ai/CodexAgentTest.php
    - tests/Feature/Ai/LoginHealthTest.php
  modified:
    - app/Providers/AppServiceProvider.php
requirements-completed: [AI-01, AI-04]
completed: 2026-11-14
---

# Phase 8 Plan 10: Agent layer and login health Summary

Registry-backed Codex agent that maps a CLI run to a validated payload or a content-free failure code, plus login health derived from `codex login status` checks and real-run auth failures.

## Commits
- 45265ec feat(08-10): add agent registry and Codex agent outcome mapping
- b71e03a feat(08-10): add login health and check job
- 553e722 fix(08-10): keep medical table access out of DB facade

## Verification
- CodexAgentTest (12), LoginHealthTest (8), tests/Unit/Ai and tests/Unit/Arch pass; Pint clean.
- Full Pest (1G, about 190 s): 2141 passed, 1 failed (MedicalTableScanTest flagged `DB::` in AgentHealth, fixed in the third commit; re-ran tests/Unit/Health, Arch and LoginHealth, 77 passed). The full suite was not re-run after that one-line fix.
- Vitest and `npm run build` not run: PHP-only plan, host Node 18, shared tree with 08-11. phpunit.xml untouched; peak memory not measured (ran with 1G on the command line).

## Decisions (PROVISIONAL, for 08-22 review)
- Mapping order in CodexAgent: aborted, timeout, idle_timeout, output_too_large, then validator on result.json (valid file wins even with exit 1), then auth if the auth signature was seen, then cli_error only when no file exists and exit != 0, else the validator's code (empty or missing file gives no_output).
- AgentHealth: a fresh check whose state is `unknown` (timeout or unparseable output) displays `not_responding`, not `logged_in`.
- An auth failure expires the display only while it is newer than both the last success and the last check. This lets a later check that sees the login present clear it; otherwise a not-runnable agent could never produce the success that clears the flag. T-08-41 still holds for runs between checks.
- 'unknown' (no check yet) is runnable.
- Writes go through `AiAgentStatus::query()->toBase()->upsert(...)`: the model has timestamps on but the table has no `created_at`, and MedicalTableScanTest bans `DB::` in app/Medical outside Queries.
- Uniqueness test dispatches twice to the real database queue and counts one row in `jobs` (Queue::fake does not honour uniqueness).

## Deviations from Plan
- [Rule 1] AgentHealth first used `forceFill()->save()` and `DB::table`; both failed (no `created_at`; scan test). Replaced with the toBase upsert above.
- Test payload helper is named `agentPayload()` to avoid redeclaring `goldenPayload()` from ExtractionValidatorTest.

## Known Stubs
None. No keep-alive job built, per the owner default. Scheduling CheckLoginJob is left to 08-14.

## Threat Flags
None.

## Self-Check: PASSED
