---
phase: 10-profile-settings-and-child-accounts-issues-7-6
plan: 08
subsystem: staging-seed-and-release-gate
tags: [staging, seed, i18n, gate]
requires: ["10-05", "10-06", "10-07"]
provides:
  - "Staging seed with one synthetic child (photo, three wishes, managed by sk_sk.1)"
  - "tests/js/i18n-phase10.test.js locale parity"
key-files:
  created:
    - tests/js/i18n-phase10.test.js
  modified:
    - database/seeders/StagingSeeder.php
    - database/seeders/Staging/WishlistSeeder.php
    - tests/Feature/StagingSeedTest.php
    - tests/Feature/StagingDemoSeedTest.php
    - tests/Feature/StagingAuditChildTest.php
    - docs/ops/staging-data.md
    - docs/ops/server.md
    - tests/js/ui/AvatarCallSites.test.js
status: PARTIAL - Tasks 1 and 2 done; Task 3 (staging browser checkpoint) pending orchestrator
---

# Phase 10 Plan 08: Staging seed child and release gate (Tasks 1-2 only)

Tasks 1 and 2 are done. Task 3 (staging browser verification and DB-level child invariant) is NOT done: the orchestrator pushes `develop` and runs it. This plan is not complete.

## Commits
- 6f9ce1e feat(10-08): seed a synthetic child with a generated photo on staging (Refs #6, Refs #7)
- f8b1b12 test(10-08): locale parity for phase 10 and drop the MemberRow avatar allowlist entry (Refs #6, Refs #7)

## What was built
- StagingSeeder creates "Maťko Demo (demo)" through the production `CreateChild` action (actor = seeded admin, manager = `sk_sk.1`, who holds Wishlist Edit through the Parent preset), then stores a GD-generated PNG (coloured shapes, no face or text) via `ImageProcessor` and `ProfilePhotoStore`. Three demo wishes via `WishlistSeeder::seedChild`. The child is not one of the six roles, so task, finance and health seeders are untouched.
- Tests: users 7 to 8, synthetic predicate rewritten (no non-example.test e-mail, no NULL-e-mail user without " (demo)"), new test for one child, photo files, managers and no orphan photo file after seeding twice, audit test that seeded data passes and a real child fails.
- Docs: staging-data.md child row (cannot sign in); server.md line on profile photos in the uploads volume (existing backup).
- i18n-phase10.test.js: en/sk/pl parity of `profile`, `settings.members.child`, `settings.members.convert`, `profile.people` (both directions), audit details `profile_changed_photo|name|other`, `child_created`, `child_converted`, and help topics `profile.photo`, `profile.name`, `members.child`, `members.convert`.
- AvatarCallSites allowlist: `MemberRow.vue` entry removed (only the StyleGuide entry remains); test still green.

## Gate results (isolated compose project fh1008, scratch folder over storage/logs, serial, removed with `down -v`, no containers left)
- Targeted staging Pest (5 files): 54 passed
- Full Pest: 2931 passed, 1 skipped
- Full Vitest: 153 files, 5179 tests passed (includes manual and help coverage)
- `npm run build` with SW-PRECACHE OK
- Pint `--test`: pass (957 files)
- check-nginx-template.sh, check-compose.sh: OK; check-upload-limit.sh: OK (upload 302, profile 302, other 413, medical 302)

## Deviations
1. [Rule 1 - Test adaptation] The "shared staging password for every account" test now checks only users with an e-mail (the child has a random unusable password by design); the ai_calls count assertion uses `User::count()` instead of the literal 7.
2. [Rule 2 - Seed purity] `CreateChild` writes a `child_created` audit row; the seed must write no audit rows (existing test), so the seeder passes a silent `AuditLogger` subclass to `CreateChild`. The production creation path is otherwise unchanged.
3. The plan's audit key `profile_changed_by_other` does not exist in the code; the parity test covers the real keys `profile_changed_photo`, `profile_changed_name`, `profile_changed_other`.

## User documentation
No user-visible change in this plan (staging seed, ops docs, tests). Files touched in phase 10 plans 04, 05, 06 (sk and pl are drafts and need native review): `resources/help/{en,sk,pl}.json`, `resources/help/topics.json`, `resources/manual/{en,sk,pl}/admin.json`, `profile.json`, `who-sees-what.json`, `wishlist.json`.

## Known Stubs
None.

## Pending
Task 3: orchestrator pushes `develop`, waits for the staging deploy, runs browser steps 1-7 and DB invariant checks 8-12. Note for step 1-4: the seeded child is "Maťko Demo (demo)", managed by `sk_sk.1`, so `/profile/people/{id}` shows it for that parent.

## Self-Check: PASSED
Both commits and all created files verified present.
