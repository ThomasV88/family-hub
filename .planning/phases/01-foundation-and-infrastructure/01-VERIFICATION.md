---
phase: 01-foundation-and-infrastructure
verified: 2026-11-13T01:45:00Z
status: human_needed
score: 5/5 criteria verified locally as far as possible; 0/5 fully closed (live/device parts deferred)
re_verification: false
gaps: []
human_verification:
  - test: "Push develop; CI deploys to staging URL via edge proxy (plan 01-08 Task 3, 01-10, 01-11)"
    expected: "Staging reachable over TLS through edge; GitHub environments, secrets, deploy key, edge map lines in place"
    why_human: "Live server and GitHub configuration, deliberately deferred (DEFERRED.md)"
  - test: "PR develop -> main, production approval and deploy (plan 01-12)"
    expected: "Production URL serves the app after merge"
    why_human: "Live ops, deferred"
  - test: "Install PWA on a phone and iPad, use on desktop (criterion 3)"
    expected: "Add-to-home-screen works, update prompt appears, no medical/finance data cached"
    why_human: "Real devices"
  - test: "Switch SK/PL/EN on a real device (criterion 2)"
    expected: "Correct plurals, dates and numbers visually"
    why_human: "Visual and device check; unit tests pass locally"
  - test: "Run backup then restore drill on the staging server (criterion 4)"
    expected: "Encrypted restic backup scheduled by cron; restore into scratch DB succeeds; freshness check passes"
    why_human: "Restore has only been proven locally (test-backup-local.sh), not on the server"
  - test: "Run staging:seed and staging:audit on staging; confirm access log shows visitor IP (WR-01) and off-site backup copy"
    expected: "Audit passes with only synthetic users; real IP logged"
    why_human: "Needs live staging environment"
---

# Phase 1: Foundation and Infrastructure Verification Report

**Goal:** App runs on the owner's VPS in separate staging and production environments, in three languages, installable as PWA, with encrypted backups proven restorable.
**Status:** human_needed. Everything verifiable locally passes. The goal itself is not yet achieved in the live sense, because no server work has been done (owner-deferred, see DEFERRED.md). Plans 01-07, 01-08 (Tasks 1-2), 01-09 are implemented in code even though ROADMAP checkboxes are unticked; ROADMAP and REQUIREMENTS (FOUND-02..05 "Pending") should be updated after live work.

## Observable Truths

| # | Criterion | Status | Evidence |
|---|-----------|--------|----------|
| 1 | develop deploys to staging, main to production via edge | HUMAN | `.github/workflows/ci.yml`, `scripts/deploy.sh`, `compose.server.yaml`, nginx template exist. check-compose, check-nginx-template (nginx -t), test-deploy-guards (20/20), test-server-provision dry run, test-server-postgres all pass. Nothing deployed. |
| 2 | SK/PL/EN switch, correct plurals/dates/numbers | VERIFIED locally, device check human | lang/{en,pl,sk}(.json), resources/js/i18n.js; Pest LocaleTest and TransChoiceTest pass, Vitest i18n and LanguageSwitcher tests pass. |
| 3 | Installable PWA, SW caches only shell | PARTIAL: code VERIFIED, install HUMAN | `npm run build` passes `scripts/check-sw-precache.mjs` (allowlist of hashed assets, icons, offline.html, manifest; forbids runtime caching). NoStoreDynamicResponses middleware sets no-store; CacheHeadersTest passes. Real-device install not tested. |
| 4 | Encrypted scheduled backup and performed restore | PARTIAL: local VERIFIED, server HUMAN | `scripts/test-backup-local.sh` TEST-BACKUP OK (restic backup + restore drill locally); backup.sh, restore-drill.sh, backup-freshness.sh, cron in provisioning exist. Restore on server not performed. |
| 5 | Staging holds only synthetic data | PARTIAL: guards VERIFIED, staging HUMAN | StagingSeed/StagingAudit commands with production refusal; StagingSeedTest passes (idempotent, audit fails on real data, refuses in production). Not run on live staging. |

## Spot-checks run locally (all exit 0)

- Pest in dev stack: 35 passed (138 assertions)
- Vitest: 41 passed
- `npm run build` including SW-PRECACHE OK
- check-compose, check-nginx-template, check-secrets, test-deploy-guards, test-server-provision, test-backup-local, test-server-postgres: all OK
- Dev stack port 8080 was in use by the existing family-hub stack; used that running stack (no new stack needed).

## Requirements

FOUND-01 (stack/deploy pipeline): code complete, live deploy pending. FOUND-02 i18n: locally satisfied. FOUND-03 PWA: locally satisfied, device check pending. FOUND-04 backups: locally proven, server restore pending. FOUND-05 synthetic staging: guards satisfied, live audit pending. No orphaned requirements.

## Anti-patterns

None blocking found in the checks run (secret scan passes). Code review: 12/12 findings fixed per REVIEW-FIX.md; not independently re-audited line by line.

## Gaps

None in code. Only deferred live/device items remain (listed in `human_verification`).
