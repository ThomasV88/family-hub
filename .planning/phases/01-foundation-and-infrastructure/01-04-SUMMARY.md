---
phase: 01-foundation-and-infrastructure
plan: 04
subsystem: infra
tags: [docker-compose, nginx, tls, proxy-protocol, deploy, letsencrypt]
requires: [01-01, 01-02]
provides:
  - compose.server.yaml shared by staging and production (no published ports, edge alias)
  - env templates (compose, app, migrate) with the owner/runtime credential split
  - nginx TLS + PROXY protocol template and security-headers snippet
  - deploy.sh (guards, pre-migrate backup, owner-role migrate, rollback), cert-renew.sh, smoke-server.sh
affects: [01-05, 01-06, 01-08, 01-10]
key-files:
  created: [compose.server.yaml, ops/env/compose.env.example, ops/env/app.env.example, ops/env/migrate.env.example, scripts/check-compose.sh, docker/nginx/templates/default.conf.template, docker/nginx/snippets/security-headers.conf, scripts/check-nginx-template.sh, scripts/cert-renew.sh, scripts/deploy.sh, scripts/smoke-server.sh, scripts/test-deploy-guards.sh]
  modified: [docker/nginx/snippets/laravel-locations.conf, docker/nginx/dev.conf]
key-decisions:
  - "App healthcheck uses a php fsockopen to 127.0.0.1:9000 (no cgi-fcgi in the alpine image)"
  - "guard_reseed requires staging in STACK_ENV, project name, APP_ENV and directory"
duration: ~35min
completed: 2026-11-13
---

# Phase 1 Plan 04: Server deploy artifacts Summary

Server compose, TLS/PROXY-protocol nginx template, deploy script with tested guards and rollback, certificate script and post-deploy smoke test, all validated statically; the live server was not touched.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 compose + env templates + check | 9eac703 | `check-compose.sh` prints CHECK-COMPOSE OK |
| 2 nginx template + cert script | 8e131c0 | `check-nginx-template.sh` prints CHECK-NGINX OK |
| 3 deploy, smoke, guard tests | 61ce246, c6a33dc | `test-deploy-guards.sh`: 17 cases, GUARDS OK (RED confirmed before deploy.sh existed) |

## Deviations from Plan

**1. [Rule 3] jq not installed on host.** `check-compose.sh` asserts with `python3 -I` instead of jq (same checks, plus owner role only reaches `migrate`).

**2. [Rule 2] `$fh_proto` and `$fh_robots` variables.** The shared locations snippet now needs `$fh_https`, `$fh_proto` and `$fh_robots`; `dev.conf` defines them (empty robots, http) and includes the security headers, so dev stays working. Added `fastcgi_param HTTP_X_FORWARDED_PROTO`.

**3. [Rule 3] Verification of `smoke-local.sh` not possible as-is.** The shared dev stack currently returns HTTP 500 on `/` because plan 01-03's `system_heartbeats` migration is not applied in the dev database (not caused by this plan). Instead I ran dev.conf plus snippets in a throwaway nginx container (`fh0104-nginx`, removed) against the dev app and confirmed HSTS, nosniff, X-Frame-Options, CSP headers, a 404 for unknown paths and headers on `/build/` assets. Rerun `smoke-local.sh` after the heartbeat migration.

**4. Minor.** `chmod +x` I ran on scripts briefly changed modes of other executors' files; I restored them to their committed modes. `deploy.sh` also checks `.env/app.env/migrate.env` are not accessible to others and that `edge` network exists. Certificate issue adds `--dns-cloudflare-propagation-seconds 30` (as work-tracker does).

## Notes for dependent plans

- `deploy.sh` expects `backup.sh pre-migrate` (01-06), `php artisan staging:seed --force` and `staging:audit` (01-05) and requires `backup.sh`, `cert-renew.sh`, `postgres-init/01-roles.sh` and `secrets/*` including `restic_password` and `cloudflare.ini` in the stack dir.
- `postgres-init/01-roles.sh` is the dev script from 01-01 (reads `/run/secrets/*`); CI (01-08) must copy `docker/postgres/init/01-roles.sh` there.
- Real-run verification (pull, TLS, edge routing, `smoke-server.sh`) is deferred to plan 01-10 on the staging stack.
- `IMAGE_PREFIX` in the template is `ghcr.io/thomasv88/family-hub` as in the plan; confirm the real owner in 01-08.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model.

## Self-Check: PASSED

All created files exist; commits 9eac703, 8e131c0, 61ce246, c6a33dc in git; check-compose, check-nginx-template and test-deploy-guards pass.
