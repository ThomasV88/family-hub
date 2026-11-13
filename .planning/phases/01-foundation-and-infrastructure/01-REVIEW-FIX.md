---
phase: 01-foundation-and-infrastructure
fixed_at: 2026-11-13T01:35:00Z
review_path: .planning/phases/01-foundation-and-infrastructure/01-REVIEW.md
iteration: 1
findings_in_scope: 12
fixed: 12
skipped: 0
status: all_fixed
---

# Phase 1: Code Review Fix Report

**Fixed at:** 2026-11-13
**Source review:** .planning/phases/01-foundation-and-infrastructure/01-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 12 (CR-01, CR-02, WR-01..WR-10; Info skipped by scope)
- Fixed: 12
- Skipped: 0

Verification: scripts/check-compose.sh, check-nginx-template.sh, test-deploy-guards.sh, test-backup-local.sh,
test-server-provision.sh, check-secrets.sh, the new test-server-postgres.sh, Pest (35 passed), Pint, Vitest (41 passed).
All run in an isolated compose project (`fh01fix`, nginx on port 18080). Nothing was run against a live server.
Items that cannot be proven locally are marked "requires human verification".

## Fixed Issues

### CR-01: Server postgres first-init fails (POSTGRES_DB collides with 01-roles.sh)

**Files modified:** `compose.server.yaml`, `scripts/test-server-postgres.sh` (new), `.github/workflows/ci.yml`
**Commit:** c9e5ee8
**Applied fix:** Removed `POSTGRES_DB`, healthcheck now `pg_isready -h 127.0.0.1 -U postgres -d postgres` (TCP, so it only passes after the real server is up). New `scripts/test-server-postgres.sh` boots the postgres service of compose.server.yaml on an empty volume with throwaway secrets and asserts `family_hub` is owned by `fh_owner` and both roles exist; wired into the CI `check` job.

### CR-02: Client-supplied X-Forwarded-* headers are trusted

**Files modified:** `docker/nginx/snippets/laravel-locations.conf`, `docker/nginx/templates/default.conf.template`, `docker/nginx/dev.conf`, `bootstrap/app.php`
**Commit:** 16dfa5d
**Applied fix:** nginx now overwrites `HTTP_X_FORWARDED_FOR/HOST/PORT/PREFIX` (plus PROTO) for PHP; new `$fh_port` variable (443 server, 80 dev). `trustProxies` narrowed from `*` to loopback and private ranges. Verified through the dev stack with a temporary route (not committed): spoofed X-Forwarded-For/Host/Port/Proto were ignored.

### WR-01: Throttling/logs use the Cloudflare/edge address

**Files modified:** `docker/nginx/templates/default.conf.template`
**Commit:** 8d621e9
**Applied fix:** `set_real_ip_from 10.250.250.0/24; real_ip_header CF-Connecting-IP;` (the header is inside TLS and only reachable via Cloudflare per the Hetzner firewall) instead of `real_ip_header proxy_protocol`. This deviates slightly from the suggestion (no Cloudflare range list to maintain): the trust anchor is the edge hop, the same as before. `nginx -t` passes. Requires human verification on staging: check the access log shows the visitor address.

### WR-02: Worker/scheduler healthchecks always pass

**Files modified:** `compose.yaml`, `compose.server.yaml`
**Commit:** b1ddf4b
**Applied fix:** `pgrep -f '[q]ueue:work'` and `'[s]chedule:work'`. Verified in the running worker: match for the real process, no match for a nonexistent one.

### WR-03: `.env` password guard conflicts with documented `*_FILE` keys

**Files modified:** `scripts/deploy.sh`, `scripts/test-deploy-guards.sh`
**Commit:** 9f35593
**Applied fix:** Guard ignores keys ending in `_FILE`; two new guard test cases (`*_PASSWORD_FILE` accepted, a real password next to one still rejected).

### WR-04: Backup freshness masked by pre-migrate snapshots; no alerting

**Files modified:** `scripts/backup-freshness.sh`, `scripts/test-backup-local.sh`, `docs/ops/server.md`
**Commit:** e735dd1
**Applied fix:** `--tag nightly`; every failure goes through `stale()` (stderr, backup.log, optional `BACKUP_ALERT_COMMAND` hook, exit 1). Documented in docs/ops/server.md; test covers manual-only snapshots and the hook.

### WR-05: Certificate presence/renew-reload detection depends on a root-only directory

**Files modified:** `scripts/cert-renew.sh`, `scripts/deploy.sh`
**Commit:** a4635bc
**Applied fix:** Hash and existence are read inside the certbot container (root) via `cert_hash`; new `cert-renew.sh exists` mode used by deploy.sh; renew reloads nginx also when a hash is empty. Tested locally for the exists mode with a fixture; the permission scenario itself requires human verification on the server.

### WR-06: Image pruning never removes old tagged images

**Files modified:** `scripts/deploy.sh`, `scripts/test-deploy-guards.sh`
**Commit:** c466fd2
**Applied fix:** `stale_images` lists this stack's `sha-*` app/nginx images other than IMAGE_TAG and `.last-good-tag`; deploy removes them with best-effort `docker rmi` (images in use are refused by docker). Unit-tested with a stubbed `docker`.

### WR-07: No off-site backup by default (warning only)

**Files modified:** `scripts/deploy.sh`, `scripts/backup-freshness.sh`
**Commit:** 8427c76
**Applied fix:** Warning (no block) when `STACK_ENV=production` and `BACKUP_COPY_REPOSITORY` is empty, per owner decision for Phase 1.

### WR-08: App and nginx images build Vite assets separately

**Files modified:** `docker/app/Dockerfile`, `docker/nginx/Dockerfile`, `scripts/check-nginx-template.sh`, `scripts/smoke-server.sh`, `.github/workflows/ci.yml`
**Commit:** 3c36ad7
**Applied fix:** The app image is the single asset build and carries the whole built `public/`; the nginx image takes `--build-arg APP_IMAGE` and copies `public/` from it (CI passes the just-pushed `sha-` app image). Also found and fixed while testing: both Dockerfiles failed `npm run build` because `scripts/check-sw-precache.mjs` was not copied into the asset stage (the deploy images could not have been built). Smoke now fetches one `/build/assets/*` file referenced by the home page. Requires human verification in CI: the buildx pull of the private app image for the nginx build.

### WR-09: Smoke failure leaves bad deploy live; redirect check vacuous

**Files modified:** `scripts/deploy.sh`, `scripts/smoke-server.sh`, `scripts/test-deploy-guards.sh`
**Commit:** 8379a74
**Applied fix:** deploy.sh runs `smoke-server.sh` after `up --wait` and writes `.last-good-tag` only if it passes, otherwise rolls back (`SKIP_SMOKE=1` bypass; smoke-server.sh added to required files). Smoke retries the first request (8 x 4 s) and checks the redirect with `GET /icons` (real nginx 301 whose Location must be https) instead of the 419 POST. Requires human verification on staging (cannot be run locally over TLS); the existing CI smoke step is kept as an outside-in check.

### WR-10: Restore drill comparisons flaky; may pick a pre-migrate snapshot

**Files modified:** `scripts/restore-drill.sh`, `scripts/test-backup-local.sh`
**Commit:** e042517
**Applied fix:** `DRILL_TAG` defaults to `nightly`; newest picked by `time`; table count must be `<=` live and `>= 1`; `DRILL_DB` must match `^[a-z_][a-z0-9_]*_restore_test$`. Test updated and extended.

## Skipped Issues

None. Info findings (IN-01..IN-08) were out of scope.

---

_Fixed: 2026-11-13_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
