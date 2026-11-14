---
phase: 08-ai-extraction-platform
plan: 05
subsystem: ai
tags: [codex, process-supervision, symfony-process, arch-test]
requires: []
provides:
  - CodexRunner (exec, loginStatus), CodexRequest, ProcessResult, LoginState
  - config/ai.php
  - FakeCodex test helper
  - AiArchTest process-seam rules
affects: [08-06, 08-08, 08-22]
key-files:
  created:
    - config/ai.php
    - app/Medical/Ai/CodexRunner.php
    - app/Medical/Ai/CodexRequest.php
    - app/Medical/Ai/ProcessResult.php
    - app/Medical/Ai/LoginState.php
    - tests/Support/FakeCodex.php
    - tests/Unit/Ai/CodexRunnerTest.php
    - tests/Unit/Arch/AiArchTest.php
requirements-completed: [AI-01, AI-04]
completed: 2026-11-14
---

# Phase 8 Plan 05: CodexRunner Summary

Single supervised Codex process seam: env -i allowlist, setsid with process-group SIGKILL, byte caps and timeouts, stdin prompt, no output retained (counters, flags and a validated model id only), plus a fake CLI and arch rules.

## Commits
- fa95c0b feat(08-05): add supervised CodexRunner, ai config and fake codex
- c32a33d test(08-05): add AI layer arch rules

## Verification
- tests/Unit/Ai/CodexRunnerTest.php (17 cases) and tests/Unit/Arch/AiArchTest.php (4) pass; Pint clean.
- Full Pest: 2078 passed, 5 failed, all in tests/Feature/Ai/AiCallsGuardTest.php (another parallel plan's test, QueryException); none in this plan's files.
- Vitest and `npm run build` not completed (PHP-only plan, shared tree); vitest was started and aborted.

## Provisional decisions
- Unit tests use `uses(Tests\TestCase::class)` in the test file (tests/Pest.php only extends Feature and is out of scope), so `config()` overrides work.
- loginStatus classifies on stdout and stderr combined (real codex may print the status to stderr).
- A group kill is also issued after every run to sweep surviving grandchildren; exitCode is null when a kill flag is set.
- The orphan test treats a zombie (/proc state Z) as dead, since pid 1 in `docker compose run` does not reap.
- Arch patterns ignore `->exec(`, `::exec(` and `function exec(` declarations.
- ProcessResult has no string field other than reportedModel (regex-validated).

## Deviations from Plan
- [Rule 3] Added FakeCodex::emittingModel() (not in the contract list) to test reportedModel.
- No other deviations.

## Known Stubs
None.

## Self-Check: PASSED
