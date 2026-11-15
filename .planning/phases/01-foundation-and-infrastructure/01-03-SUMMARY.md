---
phase: 01-foundation-and-infrastructure
plan: 03
subsystem: infra
tags: [laravel-queue, scheduler, postgres, inertia, pest, vitest]
requires: [01-01]
provides:
  - system_heartbeats table, SystemHeartbeat model, RecordHeartbeat queued job (7-day pruning)
  - every-minute schedule entry, throttled POST /heartbeat (route heartbeat.store)
  - Home page props contract heartbeat.{last_recorded_at,last_source,count_24h,expected_24h}
  - smoke-local.sh proof of scheduler -> database queue -> worker -> Postgres
affects: [01-07]
tech-stack:
  added: []
  patterns: [Inertia page reads props via usePage, DB queue and DB cache for withoutOverlapping]
key-files:
  created: [app/Jobs/RecordHeartbeat.php, app/Models/SystemHeartbeat.php, app/Http/Controllers/HomeController.php, app/Http/Controllers/HeartbeatController.php, database/migrations/2026_11_12_000001_create_system_heartbeats_table.php, tests/Feature/HeartbeatTest.php]
  modified: [routes/web.php, routes/console.php, resources/js/Pages/Home.vue, tests/Feature/HomePageTest.php, tests/js/Home.test.js, scripts/smoke-local.sh]
key-decisions:
  - "Home.vue reads heartbeat from usePage().props (matches the plan's Vitest mock) instead of defineProps"
duration: ~20min
completed: 2026-11-13
---

# Phase 1 Plan 03: Heartbeat slice Summary

Scheduler queues RecordHeartbeat every minute, the worker writes it to Postgres through the database queue, the home page shows the latest check and 24h count, and a CSRF-protected, throttled (6/min/IP) "Run check now" button queues a manual check.

## Tasks

| Task | Commit | Result |
|------|--------|--------|
| 1 Failing tests | 2de8fed | Pest 8 new failures, Vitest 2 failures (RED) |
| 2 Implementation | 7eb690c | Pest 16/16, Vitest 4/4, build ok, SMOKE-LOCAL OK |

## Deviations from Plan

**1. [Rule 3 - Blocking] Smoke port.** Other executors' stack already held port 8080, so verification ran under compose project `fh0103` with a scratchpad override publishing nginx on 18083 and `BASE_URL=http://localhost:18083`. No repo change; smoke script honours `BASE_URL` and compose env vars as before.

**2. Home.vue uses `usePage().props`** rather than `defineProps` so the plan's mocked-usePage Vitest case works. Behaviour in the real app is identical.

## Deferred Issues

None. Stack `fh0103` was torn down with volumes.

## Known Stubs

None. English strings and `toLocaleString()` are intentionally temporary until plan 01-07 (vue-i18n).

## Threat Flags

None beyond the plan's threat model (T-01-10 and T-01-11 mitigated: throttle:6,1 tested with 429, CSRF via web group, source hard-coded, pruning bounds table size).

## Self-Check: PASSED

Commits 2de8fed and 7eb690c verified in git; all listed files exist.
