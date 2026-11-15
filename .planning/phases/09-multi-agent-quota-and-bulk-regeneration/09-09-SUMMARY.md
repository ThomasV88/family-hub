---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 09
subsystem: ai
tags: [agents, registry, cooldown, quota, audit]
requires: ["09-01", "09-03", "09-04", "09-06", "09-07"]
provides:
  - AiAgentSettingsQuery (audited, locked admin writes)
  - AgentHealth cooldown ladder and Copilot daily ceiling
  - Stateless ordered AgentRegistry (candidates, nextCandidate, earliestResume, anyRunnable)
key-files:
  created:
    - app/Medical/Queries/AiAgentSettingsQuery.php
    - tests/Feature/Ai/AiAgentSettingsQueryTest.php
    - tests/Feature/Ai/AgentQuotaTest.php
    - tests/Feature/Ai/AgentRegistryTest.php
  modified:
    - app/Medical/Ai/AgentHealth.php
    - app/Medical/Ai/Agents/AgentRegistry.php
    - app/Medical/Ai/CheckLoginJob.php
    - tests/Feature/Ai/LoginHealthTest.php
    - tests/Unit/Ai/CodexAgentTest.php
decisions:
  - "Daily ceiling counts ai_calls by COALESCE(started_at, queued_at) since local midnight (succeeded and failed only)."
  - "Registry resolves settings and health through constructor injection but keeps no cached state; the singleton binding is unchanged."
  - "For bulk, the first-enabled-agent restriction is applied before allowedKeys filtering."
  - "A missing settings row is inserted with defaults inside the locked transaction before a change is applied."
metrics:
  completed: 2026-11-14
---

# Phase 9 Plan 09: Agent selection layer Summary

Ordered, audited agent settings with a stateless registry that picks the next healthy agent, the earliest resume time, a per-agent cooldown ladder (60/180/720/1440 min) and the Copilot daily ceiling.

## Tasks
1. AiAgentSettingsQuery: setEnabled/setModel/move under `lockForUpdate`, audit before update, reasons unknown/last_enabled/invalid_model/stale. Commit "feat(09-09): add audited agent settings query".
2. AgentHealth: recordQuotaHit, cooldownUntil, coolingDown, dailyCeilingReached, ceilingResetAt, snapshot cooldownUntil; recordSuccess clears hits and cooldown. Commit "feat(09-09): add cooldown ladder and Copilot daily ceiling to agent health".
3. AgentRegistry (`agents()` renamed `all()`, no caching, `allowedKeys` on candidates/nextCandidate/earliestResume), CheckLoginJob over `all()`, rewritten CodexAgentTest registry case. Commit "feat(09-09): ordered agent registry with candidates and earliest resume".

## Deviations from Plan

**1. [Rule 1 - Bug] Stale Phase 8 assertion in LoginHealthTest.** The worker command now reads `--queue=ai-control,ai,ai-bulk` (09-02), so the test expecting `--queue=ai-control,ai ` failed. Updated it to `--queue=ai-control,ai,`.

**2. [Rule 3 - Blocking] CodexAgentTest needs the database.** The registry now reads settings, so the Unit file uses `RefreshDatabase` in addition to `TestCase`.

Tests compare resume times with a 2 second tolerance because the status columns store whole seconds.

## Known Stubs
None.

## Verification
`tests/Unit/Ai`, `tests/Unit/Arch`, `tests/Feature/Ai` in containers (project fh0909): 344 passed. Pint run on touched files. Project removed with `down -v`.

## Self-Check: PASSED
