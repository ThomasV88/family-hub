---
phase: 01-foundation-and-infrastructure
plan: 06
subsystem: infra
tags: [restic, postgres, backup, restore-drill, cron]
requires: [01-01, 01-02]
provides:
  - scripts/backup.sh (nightly|pre-migrate|manual, retention, optional off-site copy)
  - scripts/restore-drill.sh (scratch-DB restore with verification)
  - scripts/backup-freshness.sh (26h staleness check)
  - scripts/test-backup-local.sh (end-to-end local proof)
  - ops/cron/family-hub.cron.example
affects: [01-04, 01-10, 01-11, 01-12]
key-files:
  created: [scripts/backup.sh, scripts/restore-drill.sh, scripts/backup-freshness.sh, scripts/test-backup-local.sh, ops/cron/family-hub.cron.example]
key-decisions:
  - "Failed pipeline forgets the snapshot restic created from a truncated stream"
  - "Drill creates/drops the scratch DB as DRILL_ADMIN_USER (default postgres) because fh_owner has no CREATEDB; restore itself runs as fh_owner"
requirements-completed: [FOUND-04]
duration: ~30min
completed: 2026-11-13
---

# Phase 1 Plan 06: Encrypted backups and restore drill Summary

pg_dump streams from the postgres container straight into an encrypted restic repo, with retention, scripted restore drill, freshness check and cron file; `bash scripts/test-backup-local.sh` prints TEST-BACKUP OK against a throwaway Postgres (compose project `fh0106`, torn down afterwards).

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 backup.sh + local test | 886db76 | backup, wrong-password, no-plaintext, copy repo, pg_dump failure cases pass |
| 2 drill, freshness, cron | d9a0935 | drill, stale-scratch recovery, live-DB refusal, freshness cases pass |

## Deviations from Plan

**1. [Rule 1 - Bug] pg_dump failure still left a snapshot.** restic snapshots an empty/truncated stream before pg_dump reports failure, so pipefail alone failed the exit code but kept a bad snapshot. Fix: capture `--json` snapshot id and `forget --prune` it when either pipeline stage fails (backup.sh).

**2. [Rule 3 - Blocking] fh_owner cannot CREATE DATABASE.** The drill creates/drops the scratch DB as `DRILL_ADMIN_USER` (default `postgres`, socket trust inside the container), owned by fh_owner; the restore runs as fh_owner. Plan 01-10/11 must make sure the server postgres superuser is `postgres` (or set DRILL_ADMIN_USER).

**3. Optional `BACKUP_LOG_FILE` override** (default `./logs/backup.log`) so the test does not write into the repo.

## Notes for later plans

- `logs/` is not in `.gitignore`; plan owning .gitignore (or 01-10) should add `/logs/`.
- Cron file references `./cert-renew.sh` (plan 01-08/01-10) and expects scripts copied into the stack dir.
- Nothing was run against the live server; no off-site repo configured (owner decision).

## Known Stubs

None.

## Threat Flags

None.

## Self-Check: PASSED

Scripts and commits verified; final `test-backup-local.sh` run printed TEST-BACKUP OK.
