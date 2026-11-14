---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 04
subsystem: ai
tags: [config, quota, copilot, bulk]
requires: []
provides:
  - ai.copilot, ai.quota, ai.bulk, ai.agents, ai.local_timezone, ai.timeouts.min_call config
  - ModelId::isValid
  - QuotaSignatures::match and parseReset
  - FailureCode quota_exhausted, quota_timeout, no_agent, notice_required
  - optional trailing members on AgentOutcome, AgentInput, ProcessResult
affects: [09-06, 09-07, 09-08, 09-10]
key-files:
  created: [app/Medical/Ai/ModelId.php, app/Medical/Ai/QuotaSignatures.php, tests/Unit/Ai/ModelIdTest.php, tests/Unit/Ai/QuotaSignaturesTest.php]
  modified: [config/ai.php, app/Medical/Ai/FailureCode.php, app/Medical/Ai/Agents/AgentOutcome.php, app/Medical/Ai/Agents/AgentInput.php, app/Medical/Ai/ProcessResult.php]
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 9 Plan 04: Shared contracts and quota matcher Summary

Config-driven usage-limit matcher (per-agent lowercase needles, UTC reset parsing, clamp, buffer) plus all Phase 9 tunables with PROVISIONAL defaults, a shared model-id rule and new failure codes.

## Commits
- 969fbea feat(09-04): quota config, model id rule, failure codes and quota signature matcher

## Tests
ModelIdTest and QuotaSignaturesTest pass (plus CodexAgentTest regression run, 35 tests). Pint clean. Container project fh0904 removed.

## Decisions
- `ModelId::PATTERN` ends with `/D` so a newline-suffixed id is refused (the contract pattern without `D` would accept it, contradicting the behavior list).
- Reset clamp: the lower bound (now + 1 min) applies to the time the CLI printed, the upper bound (8 days) to the buffered result. Otherwise the 60 s buffer would make a 20 s reset valid, against the stated behavior.
- Copilot `quota` needle narrowed to `plan: 100% used` only (bare "quota" is too broad for "unknown text is not quota"); live check L4 can add more.
- `ai.copilot.login_keys` defaults are ASSUMED (logged_in_users, last_logged_in_user and camelCase variants); to be corrected at live check L1.
- `timeouts.job` left at 330 as planned (09-10 raises it).

## Deviations from Plan
None beyond the decisions above.

## Known Stubs
None.

## Self-Check: PASSED
