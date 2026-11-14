---
phase: 08-ai-extraction-platform
plan: 02
subsystem: infra
tags: [docker, codex, poppler, ai-worker]
requires: []
provides:
  - poppler-utils (pdftoppm, pdfinfo) in the shared base image (app, dev, ai-worker)
  - ai-worker-dev and ai-worker image targets with the pinned native Codex CLI
affects: [08-07, 08-08, 08-22]
tech-stack:
  added: ["@openai/codex 0.169.0 (build stage only)", "poppler-utils 25.12.0-r1 (apk)"]
  patterns: ["Codex native binary copied out of a Node build stage; no Node at run time"]
key-files:
  created: [docker/ai/package.json, docker/ai/package-lock.json]
  modified: [docker/app/Dockerfile, .dockerignore]
key-decisions:
  - "Codex lives only in ai-runtime (COPY --from=codex appears once); dev and prod targets have none"
  - "ai-worker prod runs as www-data, dev as app (uid 1000)"
requirements-completed: [AI-04]
duration: 15min
completed: 2026-11-14
---

# Phase 8 Plan 02: Codex pin and ai-worker image Summary

Pinned Codex 0.169.0 with a lockfile and added poppler plus a separate non-root ai-worker image (prod and dev targets) that alone carries the native Codex binary.

## Task 1: Owner approval (checkpoint)

The owner approved both packages on 2026-11-14 through AskUserQuestion: `@openai/codex` 0.169.0 (ai-worker image only) and `poppler-utils` 25.12.0-r1. Approved before any install.

## Task 2 results

- Lockfile generated in a throwaway `node:22-alpine` container with `--ignore-scripts`; carries integrity for the linux-x64 and linux-arm64 platform aliases. The file is root-owned on the host (written by the container); content is fine.
- `npm ci --ignore-scripts --omit=dev` in the codex stage; `codex-resources/voice` removed (research A5).
- Checks passed: app dev image has `pdftoppm`/`pdfinfo` 25.12.0 and no `codex`; ai-worker runs as uid 82 (www-data) with `codex-cli 0.169.0` and no node; ai-worker-dev runs as uid 1000 with `codex-cli 0.169.0`.
- Image sizes: app:dev 128 MB, ai-worker (prod, includes code) 589 MB, ai-worker-dev 498 MB (above the 450-500 MB estimate for prod, due to the code and vendor layers).
- Note: the dev image `family-hub-app:dev` was rebuilt with poppler-utils; plans 08 and later can rely on it.

## Deviations from Plan

- [Rule 3 - minor] `.dockerignore`: root-only `node_modules` pattern does not match nested dirs, so added `docker/ai/node_modules` explicitly.
- Voice-removal (A5): only `codex --version` verified; `exec` behaviour is unverified until 08-22 (no login or AI call permitted here).

## Provisional decisions

- ai-worker (prod) copies the full `COPY . .` code like prod, minus the Vite assets stage.

## Known Stubs

None.

## Self-Check: PASSED
