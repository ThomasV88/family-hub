---
phase: 10-profile-settings-and-child-accounts-issues-7-6
fixed_at: 2026-11-17T00:00:00Z
review_path: .planning/phases/10-profile-settings-and-child-accounts-issues-7-6/10-REVIEW.md
iteration: 1
findings_in_scope: 9
fixed: 8
skipped: 1
status: partial
---

# Phase 10: Code Review Fix Report

**Fixed at:** 2026-11-17
**Source review:** .planning/phases/10-profile-settings-and-child-accounts-issues-7-6/10-REVIEW.md
**Iteration:** 1

**Summary:**
- Findings in scope: 9 (WR-01..WR-06, IN-02, IN-03, IN-04; IN-01 and IN-05 excluded by the orchestrator)
- Fixed: 8
- Skipped: 1

All work is on branch `main`, one commit per finding (WR-03 and WR-04 share one commit because both touch the same test file). Nothing was pushed.

## Fixed Issues

### WR-01: Edit routes distinguish an unknown id (404) from a forbidden one (403)

**Files modified:** `app/Http/Requests/Profile/UpdateProfileNameRequest.php`, `app/Http/Requests/Profile/UploadProfilePhotoRequest.php`, `app/Http/Controllers/Profile/ProfilePhotoController.php`, tests (`ProfilePhotoTest`, `ProfilePeopleTest`, `ProfileUpdateTest`, `tests/Support/EndpointMatrix/profile.php`)
**Commit:** c823795
**Applied fix:** `authorize()` and `destroy` answer 404 for an unknown id and for a person the caller may not edit. Tests and the endpoint matrix now expect 404 where they expected 403, and assert that an unknown id gives the same answer.

### WR-02: Staging synthetic child marked by a user-editable name suffix

**Files modified:** `app/Support/SyntheticChild.php` (new), `app/Console/Commands/StagingAudit.php`, `database/seeders/StagingSeeder.php`, `tests/Feature/StagingAuditChildTest.php`, `docs/ops/staging-data.md`
**Commit:** 4a51615
**Applied fix:** No schema change. A child is synthetic only when it has no e-mail, the " (demo)" suffix, at least one wish-list manager, and every manager is an `@example.test` member. One SQL rule in `SyntheticChild::condition()` is shared by the seeder purge and `staging:audit`. A real child, a child nobody manages, or a child with any real manager is never purged and is flagged by the audit, whatever its name. The seeder takes the ids first and deletes by id, because its owned-rows purge removes the manager rows the rule reads. Documented in `docs/ops/staging-data.md`. Tests: look-alike with a real manager is flagged and survives a reseed, unmanaged "(demo)" child survives, mixed managers count as real, existing seeded-data tests still pass.
**Status note:** logic change, requires human verification.

### WR-03: `invitations.user_id` nullOnDelete

**Files modified:** `database/migrations/2026_11_27_100000_cascade_invitation_child_link.php` (new), `tests/Feature/Invites/ChildConversionTest.php`
**Commit:** b2c4d0c
**Applied fix:** New migration (the pushed one is untouched) replaces the foreign key with `cascadeOnDelete`, so an open conversion invitation disappears with its child instead of becoming a plain account-creating invite. Test deletes a child and asserts the invitation is gone. Note: deleting a child with wishes or tasks is blocked by RESTRICT keys anyway, so only the staging reseed path could reach this today.

### WR-04: Conversion create not serialised per child

**Files modified:** `app/Auth/InviteService.php`, `app/Http/Controllers/Auth/InviteAcceptController.php`, `tests/Feature/Invites/ChildConversionTest.php`
**Commit:** b2c4d0c
**Applied fix:** `create()` for a child takes `pg_advisory_xact_lock('child-invite:<id>')`, locks the child row and re-checks `email === null && sign_in_disabled` inside the transaction. The loser gets a `ValidationException` (key `child`, shown by the card as the existing generic "invitation was not sent, nothing changed" banner) and nothing is created. In the accept controller, a link that is still valid but cannot be accepted now renders the dead-link state instead of the same form again. Tests: converted-meanwhile refusal creates nothing, lock statements are issued, dead link renders `revoked` with no token or e-mail.
**Status note:** logic change, requires human verification. True concurrency is covered by lock-statement assertions, not by a parallel-process test.

### WR-05: ProfilePhotoStore read-modify-write without a lock

**Files modified:** `app/Profile/ProfilePhotoStore.php`, `tests/Feature/Profile/ProfilePhotoStoreTest.php`
**Commit:** 629054a
**Applied fix:** `put` and `remove` swap the columns inside a transaction on the row re-read with `lockForUpdate`, take the old paths and `photo_version` from that locked row (so the version is monotonic and the earlier pair is deleted), and delete old files after the commit. The caller's instance is kept in step. Tests: a stale instance does not orphan files or repeat a version (put and remove), and a `for update` on `users` is issued.
**Status note:** logic change, requires human verification.

### WR-06: Converted child keeps parents as wish-list managers, adult is not told

**Files modified:** `app/Http/Controllers/Profile/ProfileController.php`, `resources/js/Pages/Profile/Edit.vue`, `lang/en.json`, `lang/sk.json`, `lang/pl.json`, `resources/manual/en/admin.json`, `resources/manual/sk/admin.json`, `resources/manual/pl/admin.json`, `tests/Feature/Profile/ProfilePeopleTest.php`, `tests/js/profile/ProfilePage.test.js`
**Commit:** 7d5128c
**Applied fix:** Managers are kept (owner decision "links stay"). The own Profile settings page now shows an info note naming the members who manage the viewer's own wish list (new prop `wishlistManagers`, signed-in members only, absent on a child's page). Manual section `child-convert` in `admin.json` gets one list item in en, sk and pl, with the same section ids and counts. No new help tip (no new field or button).
**Native review needed:** `profile.people.managers_notice` in `lang/sk.json` and `lang/pl.json`, and the new list item in `resources/manual/sk/admin.json` and `resources/manual/pl/admin.json` are drafts.

### IN-02: Failed photo removal gives no feedback

**Files modified:** `resources/js/Components/Profile/ProfilePhotoEditor.vue`, `tests/js/profile/ProfilePhotoEditor.test.js`
**Commit:** df2a3f8
**Applied fix:** The delete call handles `onError`, `onHttpException` and `onNetworkError` (Inertia 3 reports 403/404/5xx through the latter) and shows `profile.photo.err_write` in the existing alert. Cleared on the next upload or removal. Three tests.

### IN-03: Raw translation key on parent-selection errors

**Files modified:** `app/Http/Requests/Settings/StoreChildRequest.php`, `tests/Feature/Settings/ChildCreateTest.php`
**Commit:** 6a15e21
**Applied fix:** Messages for `parent_ids.*.integer` and `parent_ids.*.distinct` map to `settings.members.child.err_parent`. Test asserts every error is a translation key, never `validation.*`.

## Skipped Issues

### IN-04: Migration fails the whole deploy if a blank e-mail row exists

**File:** `database/migrations/2026_11_26_100000_add_child_and_photo_columns_to_users.php:25`
**Reason:** The migration is already on `origin/main` and `origin/develop`, and the instruction was never to edit a pushed migration. A guard added now would not run where the migration already ran, and an edit would change a file whose checksum-by-name is already recorded. If production has not applied it yet, run `select count(*) from users where email = ''` there before release; a non-zero count would stop the deploy (Postgres DDL is transactional, nothing half-applied).
**Original issue:** `users_email_not_blank` is added to a populated table; an `email = ''` row makes `ADD CONSTRAINT` fail.

## Excluded by the orchestrator (not attempted)

- IN-01 (`photoUserId` stays as decided in the plan)
- IN-05 (note only)

## Verification

Isolated compose project `fh1010fix` (own Postgres, scratch folder over storage/logs), serial, removed afterwards. Full serial Pest suite: 2942 passed, 1 skipped (was 2931 before this fix pass). Pint `--test`: pass (959 files). Full Vitest: 153 files, 5185 tests passed (manual, help coverage and a11y included).

---

_Fixed: 2026-11-17_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
