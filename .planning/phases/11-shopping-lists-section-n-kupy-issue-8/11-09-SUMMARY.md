---
phase: 11-shopping-lists-section-n-kupy-issue-8
plan: 09
subsystem: staging-seed
tags: [staging, seed, audit, shopping]
requires: ["11-03", "11-04"]
provides:
  - "Staging Shopping demo (30 catalog items with pictures, 5 stores with logos, custom unit, 8 lists in every state) created through the production services"
  - "staging:audit rules for all six owner-linked shopping tables"
key-files:
  created:
    - database/seeders/Staging/ShoppingSeeder.php
    - tests/Feature/Shopping/StagingAuditShoppingTest.php
  modified:
    - database/seeders/StagingSeeder.php
    - app/Console/Commands/StagingAudit.php
    - tests/Feature/StagingDemoSeedTest.php
    - tests/Feature/StagingSeedTest.php
    - docs/ops/staging-data.md
    - docs/ops/server.md
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 11 Plan 09: Staging Shopping seed and audit Summary

Staging gets a rich synthetic Shopping demo built only through ListService, ItemService, ListStateService, CatalogService, StoreService, UnitService and ShoppingImageStore, and `staging:audit` now fails on any shopping row tied to a non-synthetic user.

## Commits
- 0f8856f feat(11-09): seed a synthetic Shopping demo through the production services on staging (`Refs #8`)
- 966587f feat(11-09): staging audit rules for every shopping table and ops docs for the Shopping demo (`Refs #8`)

## What was built
- `ShoppingSeeder`: custom unit "can/plechovka/puszka" (actor = demo admin), 5 fictional stores with GD logos, 30 Slovak catalog items with GD pictures (flat shapes derived from the name, no text, no faces, no network), and 7 scenario lists (+ the carry-over list = 8 rows): open weekly list with contributors, viewer, decimal quantities, a note and a reminder tomorrow 18:00 in the family time zone; open list with no store; locked half-marked; locked fully resolved; finished without carry-over; finished with unavailable items and its carry list "Veľký nákup (nezakúpené)"; copy (keep true) of the finished list.
- `StagingSeeder`: calls the seeder after the other modules; purge deletes lists of synthetic creators before users (RESTRICT), then catalog items, stores and custom units of synthetic creators, and removes `wish-images/shopping/{catalog,stores}/{id}` after commit.
- `StagingAudit::rules()`: lists (creator and responsible), members, items (created_by, state_changed_by), catalog items, stores, units (null allowed for built-ins).
- Docs: Slovak Shopping scenario map in `docs/ops/staging-data.md`; one line on `wish-images/shopping/` in the uploads volume in `docs/ops/server.md`.

## Tests
Isolated compose project `fh1109` (own Postgres, scratch folder over storage/logs, serial), removed with `down -v` (no containers left). Pint on all touched PHP: pass. All staging-related Pest files (StagingSeed, StagingDemoSeed, StagingAuditChild, StagingAuditFinance, StagingAuditMedical, StagingAuditShopping, HelpSync): 71 passed. Full Pest suite and Vitest not run (no JS, lang, help or manual file touched; the only code outside staging tooling is none).

## User documentation
No user-visible change: staging-only seed data, audit rules, ops docs and tests; no `resources/manual/`, help or lang file touched. Production gets no starter data (the seeder is only called from the staging seeder).

## Deviations from Plan
- [Rule 3 - Test isolation] Added `Storage::fake('local')` to `beforeEach` of StagingSeedTest, StagingDemoSeedTest and the audit test so seeded pictures never land on the real disk; `StagingSeedTest::synthRows` also creates tester shopping rows so the reseed test proves the purge. Seed test files were in `files_modified`; no new files beyond the plan.
- Seeding runs as the demo members holding Shopping edit (parent1, parent2, grandparent); catalog and stores are created by parent1 (the services do not take the admin as actor except units).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model (T-11-36 to T-11-38 covered by StagingAuditShoppingTest, the seed-only call site and the GD generators).

## Self-Check: PASSED
Both commits and all created files verified present.
