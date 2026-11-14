---
phase: 08-ai-extraction-platform
plan: 07
subsystem: infra
tags: [docker-compose, ai-worker, codex, ops, ci]
requires: [08-02]
provides:
  - ai-worker service (server compose, dev profile ai) with codex-home as its only writable mount
  - ops/env/ai-worker.env.example
  - check-compose assertions for ai-worker isolation, deploy/CI/runbook support
affects: [08-08, 08-22]
key-files:
  created: [ops/env/ai-worker.env.example]
  modified: [compose.yaml, compose.server.yaml, scripts/check-compose.sh, scripts/deploy.sh, scripts/smoke-local.sh, scripts/test-deploy-guards.sh, .github/workflows/ci.yml, docs/ops/server.md]
key-decisions:
  - "ai-worker uses fh_app and its own env file (provisional, owner default, 08-22)"
  - "MEM_AI default 384m, measured live in 08-22"
requirements-completed: [AI-04]
duration: 25min
completed: 2026-11-14
---

# Phase 8 Plan 07: ai-worker service and ops Summary

Locked-down single-process ai-worker (own image, own env file, sole holder of codex-home, medical read-only, tmpfs work dir) with machine-checked isolation, deploy/CI support and a runbook section.

## Commits
- 01b8845 feat(08-07): ai-worker service in dev and server compose
- 999f11d feat(08-07): ai-worker in ops scripts, CI image and runbook

## Verification
- `docker compose -f compose.server.yaml config` renders; dev `config --services` lacks ai-worker, with `--profile ai` lists it. Dev ai-worker built; `codex --version` = 0.169.0 (no login, no exec).
- `check-compose.sh` OK; `test-deploy-guards.sh` 23 cases OK; `bash -n` on changed scripts OK.
- Negative proof: mounting codex-home into `worker` made check-compose fail ("worker mounts codex-home"); reverted.
- `grep codex-home scripts/backup.sh` returns nothing; deploy.sh has 6 ai-worker lines.
- Full Pest once: 2113 passed, 3 failed (MedicalPrivacyTest and two others). The same filter re-run later passed (197 tests); the failures came from other plans (08-08/09) writing PHP/routes into the shared tree during the run. This plan changes no PHP.

## Deviations from Plan
- [Rule 3] `docker compose config` inlines `env_file` into `environment`, so the planned `env_file == [ai-worker.env]` assertion cannot use rendered JSON. check-compose checks the rendered environment for forbidden keys (APP_KEY, MAIL_, GOOGLE_, VAPID_, *_API_KEY, REDIS) and does a raw-file check that the ai-worker block has `env_file: ai-worker.env` and does not mention app.env.
- deploy.sh also treats `ai-worker.env` in `guard_secrets` (mode must not be world-accessible) and in REQUIRED_FILES.
- Added check-compose rule that every long-running compose service is in each deploy.sh `up` list (Pitfall 10).
- `MEM_AI` not added to `ops/env/compose.env.example` (file outside files_modified); default 384m applies.

## Decisions
- ai-worker uses `fh_app` and a separate env file for now (PROVISIONAL, confirm in 08-22).
- Dev ai-worker mounts the code bind and `medical-dev:ro`, `codex-home-dev`; APP_KEY forced empty.

## Notes for 08-22
- Server dirs need `ai-worker.env` (mode 600) before the first deploy or deploy.sh refuses.
- CI change builds and pushes the `-ai` image on the next push to develop/main; nothing pushed here.
- Live items: login, `docker stats` memory, `exec` with voice dir removed, optional hardening (commented in compose).

## Known Stubs
None.

## Self-Check: PASSED
