---
phase: 01-foundation-and-infrastructure
plan: 01
subsystem: infra
tags: [laravel-13, inertia-3, vue, pest, vitest, docker-compose, postgres, nginx]
requires: []
provides:
  - Laravel 13 + Inertia 3 + Vue scaffold with Home page
  - Local five-service Docker stack (app, nginx, worker, scheduler, postgres)
  - Pest on Postgres (family_hub_test), Vitest on host, smoke-local.sh
  - Owner (DDL) and runtime (DML) Postgres roles
  - php-fpm dev and prod image targets, nginx image with built public/
affects: [01-02, 01-03, 01-04, 01-05, 01-07, 01-09]
tech-stack:
  added: [laravel/framework 13, inertiajs/inertia-laravel 3, pestphp/pest 4, vue 3, "@inertiajs/vue3 3", vue-i18n 11, vite 8, vite-plugin-pwa 2.0.0, vitest 5]
  patterns: [one-shot owner-role migrations, nginx shared locations snippet, test bootstrap pinning test DB]
key-files:
  created: [compose.yaml, docker/app/Dockerfile, docker/nginx/Dockerfile, docker/nginx/snippets/laravel-locations.conf, docker/postgres/init/01-roles.sh, scripts/smoke-local.sh, tests/bootstrap.php, config/inertia.php, resources/js/Pages/Home.vue, resources/js/Layouts/BaseLayout.vue]
  modified: [.gitignore, phpunit.xml]
key-decisions:
  - "Owner approved all 27 packages/images (Task 1); versions pinned to what Composer/npm resolved"
  - "Composer ran in the approved composer:2 image because the host PHP lacks extensions"
duration: ~1h
completed: 2026-11-13
---

# Phase 1 Plan 01: Scaffold and local stack Summary

Laravel 13 + Inertia 3 + Vue walking skeleton served through a five-service Docker Compose stack, with Pest tests on Postgres and Vitest tests passing and a local smoke script printing SMOKE-LOCAL OK.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 Legitimacy checkpoint | 1b365e9 | Owner "Approve all" recorded in the plan |
| 2 Scaffold | f1f9187 | Vitest 2/2, `npm run build` ok, route `home` present |
| 3 Docker stack | ebd4362 | Pest 1/1 on Postgres, smoke OK, prod and nginx images build |

## Deviations from Plan

**1. [Rule 3 - Blocking] Worker/scheduler crashed before migrations.** Migrations never run on start, so `queue:work` died on the missing jobs table and `up --wait` reported unhealthy. Fix: compose commands wait for `migrate:status` to succeed, then exec the real command. (compose.yaml)

**2. [Rule 1 - Bug] Pest ran against the dev database.** Docker `env_file` values sit in `$_SERVER`, which Laravel reads before PHPUnit's `<env force>` values, so RefreshDatabase targeted `family_hub` (it failed on ownership, so no data was lost). Fix: `tests/bootstrap.php` mirrors forced values into `$_SERVER`; phpunit.xml forces the test DB and owner credentials.

**3. [Rule 1 - Bug] nginx 502 after app recreation.** nginx resolves `app` once at start. Fix: `depends_on.app.restart: true`.

**4. [Rule 3 - Blocking] Inertia page lookup.** Inertia 3 defaults to `js/pages`; added `config/inertia.php` pointing at `js/Pages` (plan layout).

**5. [Rule 3] Extra files:** `lang/.gitkeep` (nginx Dockerfile copies `lang/`), `docker/app/php-prod.ini` and `php-fpm-prod.conf`. `/index.php` is the only executed PHP file (other `.php` paths return 404), a hardening of the plan's `~ \.php$` location. Removed skeleton Tailwind, concurrently, multiplex, bunny fonts and Pest/skeleton example tests. Kept skeleton laravel/tinker, pail, pao.

## Deferred Issues

- `npm audit`: moderate `deepmerge` prototype-poisoning advisory via workbox-build (dev/build-time only, within the approved packages). Revisit when workbox updates.
- Host PHP 8.3 lacks pdo_pgsql/xml/intl: artisan and Pest run in containers.

## Known Stubs

None.

## Threat Flags

None beyond the plan's threat model. php-fpm port 9000 is not published; only nginx 8080 is.

## Self-Check: PASSED

Files and commits 1b365e9, f1f9187, ebd4362 verified in git.
