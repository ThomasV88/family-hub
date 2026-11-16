---
phase: 10-profile-settings-and-child-accounts-issues-7-6
plan: 01
subsystem: auth-data-model
tags: [children, users, migration, staging-audit, second-factor]
requires: []
provides:
  - "users.sign_in_disabled, photo_path, photo_thumb_path, photo_version; users.email nullable; invitations.user_id"
  - "DB CHECKs users_email_not_blank and users_child_no_credentials"
  - "User::canSignIn(), routeNotificationForMail(), guarded sendPasswordResetNotification()"
  - "UserFactory::child() state"
affects: [10-02, 10-03, 10-05, 10-06, 10-08]
key-files:
  created:
    - database/migrations/2026_11_26_100000_add_child_and_photo_columns_to_users.php
    - tests/Feature/Auth/UserEmailNullableTest.php
    - tests/Feature/Security/SecondFactorChildExclusionTest.php
    - tests/Feature/StagingAuditChildTest.php
  modified:
    - app/Models/User.php
    - database/factories/UserFactory.php
    - app/Security/SecondFactorPolicy.php
    - app/Console/Commands/StagingAudit.php
    - database/seeders/StagingSeeder.php
metrics:
  tasks: 2
  completed: 2026-11-17
---

# Phase 10 Plan 01: Child data model Summary

A child is a `users` row with no e-mail, `sign_in_disabled` and no credential, enforced by the database itself; `User::canSignIn()` is the single sign-in/mail predicate, and the staging audit and reseed are NULL-safe for children.

## Commits
- e79eb81 feat(10-01): migration, User predicate and mail guards, factory state, UserEmailNullableTest
- b0dc78f feat(10-01): SecondFactorPolicy excludes non-sign-in users; staging audit and seeder purge rewritten for NULL e-mail (synthetic child = no e-mail and name ending " (demo)"; photo rule; photo folder cleanup after commit)

## Tests
Isolated compose project `fh1001` (own Postgres, scratch logs mount), removed afterwards with `down -v`. Pest: tests/Feature/Auth, Invites, the three new files, StagingSeed, StagingDemoSeed, StagingAuditFinance, StagingAuditMedical, MembersPage: 157 passed. Pint on all touched PHP files: pass.

## User documentation
No user-visible change: schema, a model predicate and staging-only tooling; nobody can create a child until 10-05. No `resources/manual/` file touched.

## Deviations from Plan
- Migration rollback test uses `--path` for the specific migration file instead of `--step 1`, so it stays valid when later plans add migrations.
- Both tasks were implemented together, then committed as two commits by file group (tests passed for both before committing).
- The photo rule in staging:audit reports under the same "non-synthetic users" message as the users rule (same table).

## Known Stubs
None.

## Self-Check: PASSED
Files and commits e79eb81, b0dc78f verified present.
