---
phase: 01-foundation-and-infrastructure
plan: 05
subsystem: infra
tags: [artisan, staging, seeder, faker, pest]
requires: [01-01]
provides:
  - staging:seed command (environment allowlist plus --force)
  - staging:audit command (rule list of table and non-synthetic condition)
  - StagingSeeder with 6 synthetic @example.test users
  - fakerphp/faker as a runtime dependency
affects: [01-04]
key-files:
  created: [app/Console/Commands/StagingSeed.php, app/Console/Commands/StagingAudit.php, database/seeders/StagingSeeder.php, tests/Feature/StagingSeedTest.php]
  modified: [composer.json, composer.lock]
key-decisions:
  - "Faker moved from require-dev to require (owner already approved the package)"
duration: ~10min
completed: 2026-11-13
---

# Phase 1 Plan 05: Synthetic staging seed and audit Summary

`staging:seed` (allowed only in staging/local/testing, needs `--force`, idempotent, 6 users in sk_SK/pl_PL/en_GB with `@example.test` emails) and `staging:audit` (fails on any non-synthetic user, refuses in production) are in place with 7 passing Pest tests.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 Failing tests | 1fab70e | 7 tests failed (commands undefined) |
| 2 Implementation, Faker to runtime | 0042290 | 7/7 pass, both commands listed by artisan |

## Deviations from Plan

None in behavior. Composer ran in the `composer:2` image with `--ignore-platform-reqs --no-scripts` (host PHP lacks extensions, as in 01-01), so `composer.lock` content-hash and ordering changed beyond the single moved package.

## Known Stubs

None.

## Threat Flags

None.

## Self-Check: PASSED

Files exist and commits 1fab70e, 0042290 verified in git.
