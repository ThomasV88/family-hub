---
phase: 04-wishlist
plan: 02
subsystem: infra
tags: [docker, gd, exif, nginx, restic, backup]
requires: []
provides:
  - gd (jpeg, webp) and exif in the app image, upload ini 6M/8M
  - shared private uploads volume (app, worker, scheduler) in dev and server compose
  - nginx 8m body limit only on the wish image upload route, shared fastcgi snippet
  - uploads as separately tagged restic snapshot in backup, off-site copy, restore drill, freshness
affects: [04-07, deploy]
key-files:
  created: [docker/app/php-uploads.ini, docker/nginx/snippets/fastcgi-app.conf, tests/Unit/Wishlist/ImageRuntimeTest.php]
  modified: [docker/app/Dockerfile, compose.yaml, compose.server.yaml, docker/nginx/snippets/laravel-locations.conf, scripts/backup.sh, scripts/restore-drill.sh, scripts/backup-freshness.sh, scripts/test-backup-local.sh, scripts/check-compose.sh]
key-decisions:
  - "DB snapshot keeps tags <env>+<kind> and path /<db>.dump; uploads snapshot has tags uploads + uploads-<kind>, path /uploads.tar, never the bare kind tag"
  - "Upload cap 5 MiB in app; PHP 6M/8M; nginx 8m on ^/wishlist/wishes/[0-9]+/image$ only"
requirements-completed: [WISH-03]
duration: ~1h
completed: 2026-11-13
---

# Phase 4 Plan 02: Image infrastructure and uploads backup Summary

gd+exif image, shared uploads volume, upload-route-only body limit, and uploads in the encrypted backup, copy, drill and freshness check.

## Commits
- 75cb635: image, compose, nginx, check-compose, ImageRuntimeTest
- 8c291ae: backup, restore drill, freshness, local test

## Verification
- `php -m` lists gd and exif; ImageRuntimeTest 4 passed; worker-written file readable by app.
- check-compose.sh and check-nginx-template.sh OK.
- `TEST_BACKUP_PROJECT=fh0402b bash scripts/test-backup-local.sh` ends TEST-BACKUP OK (copy repo drill, explicit uploads id refusal, UPLOADS_PATH failure, pre-migrate, uploads freshness).
- Full Pest 686 passed, Vitest 686 passed, build SW-PRECACHE OK. Compose project fh0402 torn down (`down -v`).

## Deviations from Plan
None of substance. The server compose uses an `x-uploads` YAML anchor (volumes list) applied to app, worker, scheduler (not the shared x-app anchor, so migrate does not mount it). Test needed `restic forget <ids>` (not `--tag` alone) to drop uploads-nightly snapshots, since forget requires a policy or ids.

## Not run
The 2 MB multipart smoke on the upload route (413 vs reach) belongs to plan 04-07; nginx config is only syntax-checked here.

## Known Stubs
None.

## Self-Check: PASSED
