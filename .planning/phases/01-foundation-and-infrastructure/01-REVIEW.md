---
phase: 01-foundation-and-infrastructure
reviewed: 2026-11-13T00:00:00Z
depth: standard
files_reviewed: 36
findings:
  critical: 2
  warning: 10
  info: 8
  total: 20
status: issues_found
---

# Phase 1: Code Review Report

**Reviewed:** 2026-11-13 | **Depth:** standard | **Status:** issues_found

Service worker is clean (precache-only). Two blockers: server postgres bootstrap cannot work, and client X-Forwarded-* headers are trusted. Live-server paths (cert, postgres) were not run on a real server; CR-01 and WR-05 rest on entrypoint/certbot behavior.

## Critical Issues

### CR-01: Server postgres first-init fails (POSTGRES_DB collides with 01-roles.sh)

**File:** `compose.server.yaml:37`, `docker/postgres/init/01-roles.sh:22`
**Issue:** compose.server.yaml sets `POSTGRES_DB: family_hub`; the official entrypoint creates it owned by `postgres` before initdb scripts run; `01-roles.sh` then runs `CREATE DATABASE family_hub OWNER fh_owner` under ON_ERROR_STOP and aborts ("already exists"). Dev works only because compose.yaml does not set POSTGRES_DB. First real deploy would fail at `up -d --wait postgres`.
**Fix:** Remove `POSTGRES_DB` from compose.server.yaml (default `postgres`), healthcheck `pg_isready -U postgres -d postgres`. Add a CI check that boots compose.server.yaml postgres with throwaway secrets.

### CR-02: Client-supplied X-Forwarded-* headers are trusted

**File:** `bootstrap/app.php:15`, `docker/nginx/snippets/laravel-locations.conf:42-49`
**Issue:** `trustProxies(at: '*')` plus nginx only overriding `HTTP_X_FORWARDED_PROTO`: client `X-Forwarded-For/-Host/-Port/-Prefix` reach PHP. Throttles on `POST /heartbeat` (6/min) and `/locale` (30/min) can be bypassed (heartbeat inserts a row and dispatches a job); X-Forwarded-Host poisons url()/route() (matters for later reset/invite links).
**Fix:** In the index.php location set `fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr; HTTP_X_FORWARDED_HOST $host; HTTP_X_FORWARDED_PORT 443; HTTP_X_FORWARDED_PREFIX "";` (dev config: port 80, http). Narrow trusted proxies to the nginx container network instead of `*`.

## Warnings

### WR-01: Throttling/logs use the Cloudflare/edge address
**File:** `docker/nginx/templates/default.conf.template:22-24`
**Issue:** PROXY protocol address is a Cloudflare IP, so all visitors behind one PoP share a throttle bucket; comment claims a real client address.
**Fix:** `set_real_ip_from` Cloudflare ranges, `real_ip_header CF-Connecting-IP`, `real_ip_recursive on`; keep `set_real_ip_from 10.250.250.0/24` for the PROXY hop only.

### WR-02: Worker/scheduler healthchecks always pass
**File:** `compose.server.yaml:71,82`, `compose.yaml:49,60`
**Issue:** `CMD-SHELL pgrep -f queue:work` matches its own parent `sh -c`.
**Fix:** Use `pgrep -f '[q]ueue:work'` (and same for schedule), or a freshness signal from the heartbeat.

### WR-03: `.env` password guard conflicts with documented `*_FILE` keys
**File:** `scripts/deploy.sh:53`, `scripts/backup.sh:15-16`
**Issue:** Guard rejects any `.env` key containing PASSWORD, but backup scripts need `RESTIC_PASSWORD_FILE` / `BACKUP_COPY_PASSWORD_FILE` in `.env`; enabling the off-site copy makes every deploy abort.
**Fix:** Exclude keys ending in `_FILE` from the guard; add a case in `scripts/test-deploy-guards.sh`.

### WR-04: Backup freshness masked by pre-migrate snapshots; no alerting
**File:** `scripts/backup-freshness.sh:18`, `scripts/server-provision.sh:193`
**Issue:** `snapshots --latest 1` ignores tags; stale result only logged.
**Fix:** Add `--tag nightly`; on stale, emit a clearly visible failure (non-zero exit plus a hook point for notification, documented in docs/ops/server.md).

### WR-05: Certificate presence/renew-reload detection depends on a root-only directory
**File:** `scripts/cert-renew.sh:34-36`, `scripts/deploy.sh:184`
**Issue:** Certbot runs as root in a container; `deploy` user probably cannot stat `live/<domain>/fullchain.pem`, so hash before/after is empty and nginx is never reloaded after renewal; deploy.sh re-runs certonly every deploy. (Unverified on the server.)
**Fix:** Do existence/hash checks inside a root container (`dc run --rm --entrypoint sha256sum certbot ...`) or always reload nginx after a successful `renew`.

### WR-06: Image pruning never removes old tagged images
**File:** `scripts/deploy.sh:206`
**Issue:** `docker image prune -f --filter until=168h` without `-a` removes only dangling images; `sha-*` images accumulate.
**Fix:** Explicitly remove `$prefix-app`/`$prefix-nginx` images whose tag is neither IMAGE_TAG nor `.last-good-tag`.

### WR-07: No off-site backup by default; password lives next to data
**File:** `scripts/server-provision.sh:119-120,172-174`
**Issue:** Repo on same VPS disk; BACKUP_COPY_REPOSITORY empty.
**Fix:** Warn in deploy.sh / backup-freshness.sh when `BACKUP_COPY_REPOSITORY` is empty and `STACK_ENV=production`. (Owner decided "no off-site in Phase 1", so a warning, not a block.)

### WR-08: App and nginx images build Vite assets separately
**File:** `docker/app/Dockerfile:28-36`, `docker/nginx/Dockerfile:3-11`
**Issue:** Two independent `npm run build` runs can produce different hashes; HTML references files nginx lacks (blank page). Smoke only checks `data-page`.
**Fix:** Build once: nginx image copies `public` from the app image / shared assets stage. Add a smoke check fetching one referenced `/build/assets/...` file.

### WR-09: Smoke failure leaves bad deploy live; redirect check vacuous
**File:** `scripts/smoke-server.sh:34-36`, `.github/workflows/ci.yml:197-198`
**Issue:** POST /heartbeat without CSRF returns 419 with no Location, so the check passes vacuously; smoke runs after `.last-good-tag` is written.
**Fix:** Test redirect scheme via a GET that redirects (http to https); run smoke inside deploy.sh before writing `.last-good-tag`, roll back on failure; retry first request for warm-up.

### WR-10: Restore drill comparisons flaky; may pick a pre-migrate snapshot
**File:** `scripts/restore-drill.sh:48-64`
**Issue:** Picks newest of any tag; requires LIVE_TABLES == DRILL_TABLES; `s[-1]` not guaranteed newest; DRILL_DB only guarded against DB_NAME.
**Fix:** Default `DRILL_TAG=nightly`; pick newest by `time`; compare `restored <= live`; validate DRILL_DB against `^[a-z_][a-z0-9_]*_restore_test$`.

## Info

- IN-01 `scripts/check-secrets.sh:31-34`: add patterns for ghs_/ghu_/ghr_, Cloudflare tokens, `sk-`, `AKIA`, `Authorization: Bearer`; scan `ops/env/*.example` for real-looking `APP_KEY=base64:`.
- IN-02 `scripts/backup-freshness.sh:37`: pass `$AGE`/`$MAX` to python as argv instead of interpolating.
- IN-03 `.env` loader duplicated in backup.sh, restore-drill.sh, backup-freshness.sh: move to `scripts/lib/env.sh`.
- IN-04 `HandleInertiaRequests.php:25-28`: redundant `version()` override; remove.
- IN-05 `HandleInertiaRequests.php:47-50`: `app.env` shared to all visitors; share only an `isStaging` boolean. `1440` magic number in HomeController.
- IN-06 `compose.yaml:68-69`: bind dev nginx to `127.0.0.1:8080:80`.
- IN-07 `.github/workflows/ci.yml`, `docker/app/Dockerfile`: pin `actions/checkout`, `setup-node` to SHAs; base images by digest; drop composer binary from prod target.
- IN-08 `scripts/deploy.sh:81-82` misleading comment ("wipes the database"); `.dockerignore` add `storage/app/*`; `staging:audit` should require APP_ENV staging|local|testing.
