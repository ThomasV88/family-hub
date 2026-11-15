---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 08
subsystem: ai-worker
tags: [preflight, copilot, subscription-only]
requires: ["09-04"]
provides: [copilot-preflight-checks, workdir-copilot-home-guard]
key-files:
  modified:
    - app/Medical/Ai/Preflight.php
    - app/Medical/Ai/Work/WorkDir.php
    - tests/Unit/Ai/PreflightTest.php
    - tests/Unit/Ai/WorkDirTest.php
requirements: [AI-04]
completed: 2026-11-14
---

# Phase 9 Plan 08: Copilot preflight and work-dir guard Summary

Preflight now always checks the Copilot install (binary, pinned `--version`, baked cache marker, login home, fallback model) and WorkDir refuses any work root equal to, inside or above the Copilot home.

## What was done

- `envKeysClean()` already iterated `ai.forbidden_env`, which 09-04 extended with the GH/Copilot names, so no code change was needed there. Tests now cover all nine names in the putenv, `$_ENV` and `$_SERVER` variants, empty values, and GH_HOST.
- `problems()` adds `copilot_binary_missing`, `copilot_version_mismatch`, `copilot_cache_missing`, `copilot_home_unwritable`, `copilot_home_in_tmp` and `copilot_model_invalid`. The version check runs `<binary> --version` under `env -i` with a throwaway HOME and COPILOT_HOME (removed with `WorkDir::removeTree`) and parses the version with the trailing-dot-safe regex.
- `WorkDir::root()` refuses the configured Copilot home, its children and parents, plus the fixed `/copilot` location.

## Decisions

- Applied the "inside the home" refusal to the fixed `/codex` and `/copilot` paths too, so `/codex/x` is now refused as well (stricter than before, no existing test relied on it).
- Copilot checks run unconditionally, independent of the admin agent switch, as the contract says.

## Deviations from Plan

None. The existing PreflightTest helper was extended with a fake `copilot` script, a cache marker and a Copilot home so the baseline "no problems" cases still hold.

## Verification

PreflightTest and WorkDirTest: 61 passed. Pint run on the four files. Project fh0908 removed. Full suite deferred to 09-19.

Task commit: 2e6ef51
