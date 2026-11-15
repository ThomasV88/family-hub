---
phase: 07-medical-records
plan: 02
subsystem: infra
tags: [docker, nginx, restic, backup, medical, storage]
requires: []
provides:
  - medical filesystem disk (storage/app/medical, 0600/0700, serve false)
  - medical Docker volume on app, worker, scheduler only
  - nginx 12m body limit on the document upload URL only
  - /medical.tar restic snapshot with freshness check and restore drill
affects: [07-10 upload pipeline, 08 worker]
tech-stack:
  patterns: [snapshot_volume function shared by uploads and medical backups]
key-files:
  created: [tests/Feature/Health/MedicalDiskTest.php]
  modified: [config/filesystems.php, compose.yaml, compose.server.yaml, docker/app/Dockerfile, docker/app/php-uploads.ini, docker/nginx/snippets/laravel-locations.conf, scripts/check-compose.sh, scripts/check-nginx-template.sh, scripts/smoke-server.sh, scripts/backup.sh, scripts/backup-freshness.sh, scripts/restore-drill.sh, scripts/test-backup-local.sh, docs/ops/server.md, tests/Unit/Wishlist/ImageRuntimeTest.php]
key-decisions:
  - "No app-level file encryption in v1 (provisional, recorded in docs/ops/server.md for 07-20)"
  - "nginx location regex is quoted because it contains braces"
requirements-completed: [MED-06]
duration: ~40min
completed: 2026-11-14
---

# Phase 7 Plan 02: Medical volume and backups Summary

Private medical disk on its own volume, scoped 12m nginx upload limit, and a separate encrypted `/medical.tar` restic snapshot (tags `<env>`, `medical`, `medical-<kind>`) with retention, off-site copy, freshness check and restore drill, all proven locally.

## Commits
- 6c66504 disk, volume, Dockerfile ownership (dev and prod), PHP and nginx limits, check scripts, MedicalDiskTest
- ce8fa58 backup.sh (`snapshot_volume`, `MEDICAL_PATH` in key list), freshness, drill, test-backup-local, runbook
- 2b8b369 ImageRuntimeTest follows the raised ini limits

## Verification
- check-compose.sh (server and dev), check-nginx-template.sh, MedicalDiskTest, test-backup-local.sh all pass. The backup test proves marker restore from primary and copy repos, drill `RESTORE-DRILL MEDICAL OK`, medical failure leaves database and uploads snapshots written and exits non-zero, pre-migrate skips, freshness fails without `medical-nightly`.
- Probe: adding the medical volume to nginx made check-compose.sh fail ("nginx mounts the medical volume"); reverted.
- Fresh dev container: `storage/app/medical` is `drwx------ app app`.
- Full Pest: 1640 passed after the fix below (an earlier run showed 3 further failures in files owned by plan 07-01 that were gone on re-run). Vitest passes, `npm run build` prints SW-PRECACHE OK. Stack torn down with `down -v`.

## Deviations
**[Rule 1 - Bug] ImageRuntimeTest asserted the old ini limits (6M/8M)**: updated to 11M/13M, a direct consequence of the planned ini change. Commit 2b8b369.

Smaller notes: the plan's nginx regex needed quotes (braces); in dev compose the medical mount lives in the existing `x-uploads` anchor (all dev app services use it, nginx/postgres never do); in server compose a single `x-medical` anchor lists both the uploads and medical mounts (YAML cannot merge two sequences). The restore drill already refused non-database snapshot ids, so no code change was needed there; test-backup-local now asserts it for medical ids.

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model.

## Self-Check: PASSED
