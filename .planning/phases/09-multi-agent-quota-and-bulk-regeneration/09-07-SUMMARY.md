---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 07
subsystem: ai
tags: [copilot, process-seam, prompt-injection, arch-test, fake-cli]
requires: ["09-04", "09-05"]
provides:
  - CopilotRunner (exec, loginStatus), CopilotRequest
  - CopilotAgent (key 'copilot') with validation and one content-free repair
  - FakeCopilot test CLI
  - Arch rules confining Copilot
affects: [09-09 registry, 09-10 attempt loop, 09-20 live checks]
tech-stack:
  patterns: [env -i allowlist, setsid group kill, per-run COPILOT_HOME inside the work dir, validate-then-trust replies]
key-files:
  created:
    - app/Medical/Ai/CopilotRunner.php
    - app/Medical/Ai/CopilotRequest.php
    - app/Medical/Ai/Agents/CopilotAgent.php
    - tests/Support/FakeCopilot.php
    - tests/Unit/Ai/CopilotRunnerTest.php
    - tests/Unit/Ai/CopilotAgentTest.php
  modified:
    - tests/Unit/Arch/AiArchTest.php
decisions:
  - "Stdout is streamed to reply.txt capped at ai.caps.result; stderr over ai.caps.stderr also counts as outputCapped (same as CodexRunner)."
  - "Issue codes without a colon (json_syntax from inspect) are sent as root:json_syntax, because PromptBuilder::buildInline drops codes that do not match its pattern."
  - "A non-empty reply that is not a JSON object is an InvalidOutput (repairable); an empty reply with exit 0 is NoOutput, with a failing exit CliError."
  - "Per-call timeout is min(ai.timeouts.process, deadline - now); below ai.timeouts.min_call the agent returns Timeout without spawning. CodexAgent has no deadline logic yet, so this is new here."
  - "WorkDir.php was added to the allowlist of the '/copilot' literal rule."
metrics:
  tasks: 3
  tests: "36 passed (146 assertions): CopilotRunnerTest 13, CopilotAgentTest 16, AiArchTest 7"
  completed: 2026-11-14
---

# Phase 9 Plan 07: Copilot runner and agent Summary

A locked-down process seam for the GitHub Copilot CLI, an agent that trusts a reply only after validation (one content-free repair at most), the fake `copilot` CLI for all later tests, and arch rules that keep Copilot confined.

## What was built

- `CopilotRunner::exec` builds the exact research argv (all five `--deny-tool` kinds, empty `--available-tools`, builtin MCPs and `github-mcp-server` off, no custom instructions, `--max-ai-credits`, `--usage-output-file`, `--model` and `--attachment` last). The child runs under `env -i` with the allowlist only, through `setsid`, cwd = work dir, stdin closed. A per-run `copilot-home` (0700) inside the work dir holds a 0600 copy of the login `config.json` and an empty `settings.json`; `HOME` and `COPILOT_HOME` point there, so session logs vanish with `WorkDir::cleanup()` and the persistent home is never written. Quota and auth signatures are read from stderr only, and only after a non-zero exit.
- `CopilotRunner::loginStatus` reads the JSONC `config.json` marker keys from `ai.copilot.login_keys` and returns only the enum; it never spawns a process.
- `CopilotAgent` follows the Q2 order (aborted, timeout/idle/cap, valid reply wins even with exit 1, quota, auth, CliError, else the inspect outcome). One fresh repair call with issue codes only, never the previous reply, never `--resume`, only for `InvalidOutput` with at least `repair_min_seconds` left. Durations are summed; the model is the configured valid model, else the first valid `modelMetrics` key, else `default`.
- `FakeCopilot` has every constructor from the contract (plus `replying($stdout, $exit)` and `usageFile($json, $stdout)` for the agent tests; `sequence` also saves each call's prompt to `<counter>.pN`).
- `AiArchTest`: `CopilotRunner` in the process allowlist, controllers barred from it, `COPILOT_` and `'/copilot'` literals confined, allow-all/yolo/resume/share/etc. flags banned under `app/`, plus in-memory mutation cases.

## Deviations from Plan

**1. [Rule 3 - Blocking] `WorkDir.php` allowed to name `'/copilot'`.** Plan 09-08 (committed in parallel) added `'/copilot'` to WorkDir's protected-location guard, so the literal rule as specified (CopilotRunner, Preflight, config only) failed on the current tree. WorkDir.php is now in `AI_ARCH_COPILOT_ALLOWED` with a comment. Commit e72d100.

**2. [Rule 1 - Bug] `json_syntax` issue code normalised to `root:json_syntax`.** `ExtractionValidator::inspect` returns the bare code `json_syntax`, but `PromptBuilder::buildInline` filters out codes without a colon, which would have produced an empty "Problems:" list. The agent prefixes `root:` to such codes. Commit 84802ea.

No other deviations. Defaults chosen without asking are in the decisions list above.

## Verification

Run in containers (project `fh0907`, removed with `down -v`), only this plan's tests: CopilotRunnerTest, CopilotAgentTest, AiArchTest green; pint clean. The real `copilot` binary was not run; no login, no AI call, no server access.

## Open items for live checks (09-20)

- Real effect of the denial flags (L3), whether the per-run home with a copied `config.json` authenticates (L1/A5), real quota and auth wording (L4), `config.json` key names (A2).

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model.

## Commits

- be74d25 feat(09-07): add locked-down CopilotRunner, request object and fake copilot CLI
- 84802ea feat(09-07): add CopilotAgent with validated replies and one content-free repair
- e72d100 test(09-07): confine Copilot in the AI arch rules

## Self-Check: PASSED

All seven files exist and the three commits are in `git log`.
