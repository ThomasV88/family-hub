---
phase: 09-multi-agent-quota-and-bulk-regeneration
plan: 02
subsystem: infra
tags: [docker, compose, copilot-cli, ai-worker, check-compose]
requires: []
provides:
  - Pinned GitHub Copilot CLI 1.0.112 in the ai image only
  - copilot-home credential volume (ai-worker only, per stack, never backed up)
  - Queue order ai-control,ai,ai-bulk with worker timeout 720
  - Static checks confining Copilot
affects: [09-07, 09-08, 09-10, 09-20]
tech-stack:
  added: ["@github/copilot 1.0.112 (build stage only)"]
  patterns: ["CLI baked read-only cache, node-free runtime image"]
key-files:
  created: [docker/ai/copilot/package.json, docker/ai/copilot/package-lock.json]
  modified: [.dockerignore, docker/app/Dockerfile, compose.yaml, compose.server.yaml, scripts/check-compose.sh, ops/env/ai-worker.env.example, docs/ops/server.md]
key-decisions:
  - "Package legitimacy recorded as evidence, no approval prompt (owner decision)"
  - "Pin stays 1.0.112 (latest is still 1.0.112)"
requirements-completed: [AI-04]
duration: ~15 min
completed: 2026-11-14
---

# Phase 9 Plan 02: Copilot CLI packaging Summary

The GitHub Copilot CLI 1.0.112 is pinned, built in a node-only stage and copied only into the ai image with its cache baked read-only. It has its own `copilot-home` volume in the ai-worker, which serves `ai-control,ai,ai-bulk` with timeout 720, and static checks keep it confined.

## Package legitimacy evidence (T-09-SC, recorded, no prompt)

| Item | Result |
|---|---|
| `@github/copilot` version / latest | 1.0.112 / 1.0.112 (no newer version) |
| Created | 2025-09-25 (about 14 months old); platform package `-linuxmusl-x64` created 2026-05-18 |
| Repository | git+https://github.com/github/copilot-cli.git (matches github.com/github/copilot-cli) |
| Publisher | maintainers list is a GitHub staff set (mxie-github, tidy-dev, devm33, manuelpuyol, jonrohan, primer-css, ...) |
| npm scripts | none on `@github/copilot` and `@github/copilot-linuxmusl-x64` (`npm view ... scripts` empty); install also runs with `--ignore-scripts` |
| Weekly downloads (2026-11-07..13) | 1,711,132 (`@github/copilot`), 128,333 (`-linuxmusl-x64`) |
| Licence | SEE LICENSE IN LICENSE.md (kept at /opt/copilot/LICENSE.md, binary not stripped or repacked) |
| Integrity | sha512 in lockfile for both musl platform packages (10 integrity entries in the lockfile) |
| slopcheck | unavailable (research A1) |

No postinstall script, repository mismatch or foreign publisher was found, so the work continued.

## Tasks and commits

1. Pin and lockfile: `docker/ai/copilot/` (lockfile generated in `node:22-alpine`, `--package-lock-only --ignore-scripts`; contains both musl packages), `.dockerignore` entry. Commit chore(09-02).
2. Dockerfile `copilot` stage and `ai-runtime` copy, `/copilot` chowned in both ai-worker stages. Commit feat(09-02) "add pinned Copilot CLI build stage".
3. Compose (both stacks), check-compose rules, env example, runbook section. Commit feat(09-02) "confine Copilot ...".

## Verification

- ai image (`--target ai-worker`): `copilot --version` under `env -i` prints 1.0.112, `.extraction-complete` present at `/opt/copilot/cache/pkg/linuxmusl-x64/1.0.112/`, LICENSE.md present, `/copilot` exists, no `node`, `codex` still present. `touch /opt/copilot/cache/x` fails (read-only).
- App image (`--target prod`): no `copilot` command and no `/opt/copilot`. (Checked with a direct `docker run` on a freshly built prod target instead of `compose run app`, because that needs the full compose env. The compose project `fh0902` was never started; `down -v` ran with nothing to remove.)
- Image size: `/opt/copilot` is 479.9 MB (Codex 353.1 MB). Resulting ai images: `family-hub-ai:dev` 1 GB (was 498 MB before Copilot), prod-flavour ai image 1.1 GB.
- `bash scripts/check-compose.sh` prints CHECK-COMPOSE OK, `bash scripts/test-deploy-guards.sh` prints GUARDS OK (25 passed). `git diff scripts/backup.sh` empty. `timeout=720` present in both compose ai-worker commands.
- Negative proofs (compose.server.yaml edited temporarily, restored from a copy each time):
  - `copilot-home:/copilot` added to the shared medical volume anchor: `FAIL: app mounts copilot-home`
  - queue order swapped to `ai,ai-control,ai-bulk`: `FAIL: ai-worker does not work ai-control,ai,ai-bulk in that order`
  - `GITHUB_TOKEN` added to the ai-worker environment: `FAIL: ai-worker gets forbidden variable GITHUB_TOKEN`

## Deviations from Plan

- [Rule 3 - Blocking] The Dockerfile placement check found "copilot" in the stage's own comment header; the check anchors on the `# ---- copilot:` header comment instead of the `FROM` line. Not a behaviour change.
- The env example's comment "must exceed the queue:work --timeout (360)" updated to 720 to match the new timeout.
- Dev tmpfs also raised to 192m (plan listed it generally for both stacks); `MEM_AI` default 768m on the server file only, as in the plan.
- `docker/ai/copilot/package-lock.json` is root-owned because it was generated in a container (readable, committed normally).

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model.

## Notes for follow-up

- Provisional values (MEM_AI 768m, tmpfs 192m) are confirmed in 09-20; the real `copilot login --device-code` is also 09-20 and was not run here.
- The ai image is now about 1 GB; pushing triggers a CI build of it (nothing was pushed).

## Self-Check: PASSED

Files exist (package.json, lockfile, Dockerfile stage, compose changes, check rules), three task commits exist, temporary images `family-hub-ai:check` and `family-hub-app:check09` removed, `family-hub-ai:dev` kept as the rebuilt dev image.
