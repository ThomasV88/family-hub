---
phase: 10-profile-settings-and-child-accounts-issues-7-6
reviewed: 2026-11-17T00:00:00Z
depth: deep
files_reviewed: 67
files_reviewed_list:
  - app/Actions/Fortify/ResetUserPassword.php
  - app/Actions/Profile/CreateChild.php
  - app/Audit/AuditEvent.php
  - app/Auth/GoogleLoginService.php
  - app/Auth/InviteService.php
  - app/Console/Commands/PruneWishlist.php
  - app/Console/Commands/StagingAudit.php
  - app/Http/Controllers/Profile/ProfileController.php
  - app/Http/Controllers/Profile/ProfilePhotoController.php
  - app/Http/Controllers/Security/ChallengePasskeyController.php
  - app/Http/Controllers/Settings/ChildrenController.php
  - app/Http/Controllers/Settings/MembersController.php
  - app/Http/Controllers/Settings/WishlistManagersController.php
  - app/Http/Middleware/BlockDisabledSignIn.php
  - app/Http/Middleware/GuardTwoFactorRoutes.php
  - app/Http/Middleware/HandleInertiaRequests.php
  - app/Http/Middleware/NoStoreDynamicResponses.php
  - app/Http/Requests/Profile/UpdateProfileNameRequest.php
  - app/Http/Requests/Profile/UploadProfilePhotoRequest.php
  - app/Http/Requests/Settings/ConvertChildRequest.php
  - app/Http/Requests/Settings/StoreChildRequest.php
  - app/Http/Requests/Settings/UpdateWishlistManagersRequest.php
  - app/Medical/PersonPage.php
  - app/Medical/Reminders/VaccinationReminderPlanner.php
  - app/Models/Invitation.php
  - app/Models/User.php
  - app/Profile/AvatarVersions.php
  - app/Profile/ProfileAccess.php
  - app/Profile/ProfilePhotoAccess.php
  - app/Profile/ProfilePhotoStore.php
  - app/Profile/ProfilePhotoWriteFailed.php
  - app/Providers/FortifyServiceProvider.php
  - app/Rules/EligibleWishlistManager.php
  - app/Security/SecondFactorPolicy.php
  - app/Tasks/ReminderRecipients.php
  - app/Wishlist/Catalog/WishImageStore.php
  - app/Wishlist/Occasions/OccasionGivers.php
  - app/Wishlist/WishlistManagerSync.php
  - config/profile.php
  - database/factories/UserFactory.php
  - database/migrations/2026_11_26_100000_add_child_and_photo_columns_to_users.php
  - database/seeders/Staging/WishlistSeeder.php
  - database/seeders/StagingSeeder.php
  - docker/nginx/snippets/laravel-locations.conf
  - resources/js/Components/Profile/ChildCreateDialog.vue
  - resources/js/Components/Profile/ProfilePhotoEditor.vue
  - resources/js/Components/Settings/AuditTable.vue
  - resources/js/Components/Settings/ChildConvertCard.vue
  - resources/js/Components/Settings/ChildProfileTab.vue
  - resources/js/Components/Settings/HealthAccessCard.vue
  - resources/js/Components/Settings/MemberPanel.vue
  - resources/js/Components/Settings/MemberRow.vue
  - resources/js/Components/Settings/WishlistManagersTab.vue
  - resources/js/Components/ui/UiAvatar.vue
  - resources/js/Components/ui/UiMenu.vue
  - resources/js/Components/ui/useAvatarUrl.js
  - resources/js/Health/PersonHeader.vue
  - resources/js/Pages/Profile/Edit.vue
  - resources/js/Pages/Settings/Members.vue
  - resources/js/Shell/AccountMenu.vue
  - resources/js/Todo/TaskColumn.vue
  - resources/js/Todo/VisibilityField.vue
  - resources/js/Wishlist/ContributorList.vue
  - resources/js/Wishlist/GiftListView.vue
  - resources/js/Wishlist/PersonChips.vue
  - routes/web/profile.php
  - routes/web/settings-children.php
findings:
  critical: 0
  warning: 6
  info: 5
  total: 11
status: issues_found
---

# Phase 10: Code Review Report

**Reviewed:** 2026-11-17
**Depth:** deep
**Files Reviewed:** 67
**Status:** issues_found

## Summary

I found no critical issues. The sign-in-disabled boundary holds on every path I traced, and I did not find a way for a child to get a session or a mail. Paths traced:

- **Password login:** `authenticateUsing` calls `canSignIn()` after the constant-time hash check.
- **2FA challenge and passkey challenge:** blocked in `GuardTwoFactorRoutes::pendingUserBlocked()`, in `ChallengePasskeyController::pending()` and in the challenge view.
- **Google login:** blocked by the `sub` lookup, the e-mail link path and the invite path.
- **Invitation accept:** conversion locks the row and re-checks `sign_in_disabled` and `email`.
- **Password reset:** `ResetUserPassword` and `sendPasswordResetNotification` both refuse.
- **Mail:** `routeNotificationForMail` returns null for a child.
- **Existing session or remember cookie:** `BlockDisabledSignIn` logs the user out on the next request.

Photo handling is sound:

- Uploads are re-encoded by GD into a fresh JPEG, so SVG and polyglot input cannot survive.
- Stored paths are server-generated from an integer id and a ULID, so there is no traversal.
- The photo route has one uniform 404, `private, no-cache` with a per-upload ETag, and the 304 is only reached after the access check.
- The nginx location applies an 8m limit to the upload URL only.

The migration is safe for every existing row. Each existing row has a non-null e-mail, so `users_child_no_credentials` holds. `users_email_not_blank` fails only if a blank e-mail already exists (see IN-04).

The remaining findings are race, lifecycle and seeder-safety issues.

## Warnings

### WR-01: Edit routes distinguish an unknown id (404) from a forbidden one (403)

**File:** `app/Http/Requests/Profile/UpdateProfileNameRequest.php:15`, `app/Http/Requests/Profile/UploadProfilePhotoRequest.php:14`, `app/Http/Controllers/Profile/ProfilePhotoController.php:87-88`
**Issue:** `findOrFail` gives 404 for a nonexistent id, and `canEdit` gives 403 for any existing person the caller may not edit. The view routes (`show`, `person`) are deliberately uniform 404, but `PUT /people/{id}/name`, `POST` and `DELETE /people/{id}/photo` let any authenticated member enumerate user ids by status code. Failure scenario: a member probes `PUT /people/1..N/name` and learns which ids exist, including accounts they cannot see. Ids are sequential, so the leak is low, but it contradicts the uniform-404 design stated in `routes/web/profile.php`.
**Fix:** Answer 404 for both cases. In each `authorize()` and in `destroy`, use `if (! $target || ! canEdit) throw new NotFoundHttpException;` (or `abort(404)`) instead of returning false or aborting 403. Keep 403 only where the caller can already see the target.

### WR-02: Staging "synthetic child" marker is a user-editable name suffix, so a real child can be deleted or hidden from the audit

**File:** `database/seeders/StagingSeeder.php:52-56`, `app/Console/Commands/StagingAudit.php:20`
**Issue:** The seeder deletes, and the audit treats as synthetic, any user with `email IS NULL AND name LIKE '% (demo)'`. The name is free text that an admin or parent can type, and the profile name form also allows renaming. Failure scenario: someone creates or renames a real staging child "Anna (demo)". The next staging deploy reseed deletes that row, together with its wishes and photo directory. Until then `staging:audit` passes it as synthetic, so a real child's photo and name can sit on staging unflagged. Staging only, but this is the "never delete or flag real data" boundary.
**Fix:** Tie the marker to something a user cannot type. For example, require that the child is managed by a synthetic user: `whereNull('email')->whereIn('id', WishlistManager::select('owner_id')->whereIn('manager_id', <example.test user ids>))`, and use the same rule in the audit. Alternatively, reject the literal suffix ` (demo)` in `StoreChildRequest` and `UpdateProfileNameRequest` outside local and testing.

### WR-03: `invitations.user_id` uses `nullOnDelete`, which turns an open conversion invite into a plain account-creating invite

**File:** `database/migrations/2026_11_26_100000_add_child_and_photo_columns_to_users.php:29`, `app/Auth/InviteService.php:171-203`
**Issue:** If the child row is deleted (today only the staging reseed does this), the open conversion invitation keeps its e-mail and token but loses `user_id`. `acceptLocked` then takes the "new user" branch. Failure scenario: a real person accepting that link gets a brand-new member account with the Child preset and none of the deleted child's data or links, with no error. Any future "remove member" feature would trigger it in production.
**Fix:** Use `cascadeOnDelete()` for `invitations.user_id`, so the invitation disappears with the child. Alternatively, revoke open conversion invitations in the code path that deletes a child.

### WR-04: Conversion create is not serialised per child, and the child state check sits outside the transaction

**File:** `app/Http/Controllers/Settings/ChildrenController.php:28-37`, `app/Auth/InviteService.php:30-58`
**Issue:** `convert()` checks `email === null && sign_in_disabled` without a lock, and `create()` only takes an advisory lock per e-mail. Failure scenario: two admins (or a double click) submit different addresses for the same child at once. Both pass the "older invitation" revoke because neither sees the other's uncommitted row, so two open conversion invitations exist for one child. Whichever is accepted first wins, and the other link then reaches `accept()`, which throws `InvalidInviteException`. `InviteAcceptController::store` re-renders the same valid-looking form with no message, so the second invitee sees nothing wrong yet cannot finish. The same happens if the child is converted between the controller check and `create()`.
**Fix:** In `create()` when `$childUserId !== null`, also take `pg_advisory_xact_lock(hashtext('child-invite:'.$childUserId))`. Lock the child row (`User::whereKey($childUserId)->lockForUpdate()`) and re-check `email === null && sign_in_disabled` inside the transaction before revoking and creating. In `InviteAcceptController::store`, render state `revoked` (or an error) after `InvalidInviteException` instead of the stale valid page.

### WR-05: `ProfilePhotoStore::put` and `remove` read-modify-write the user row without a lock

**File:** `app/Profile/ProfilePhotoStore.php:25-55`
**Issue:** The old paths and `photo_version` come from the model the controller loaded earlier. Failure scenario: two near-simultaneous uploads (double tap, or an admin and a parent editing the same child) both read the same old paths and both write new files. The later `save()` overwrites the earlier row, so the earlier pair of files is referenced by nothing and stays on disk until the 60-minute sweeper. Both saves also write the same `photo_version` (old + 1), so the `?v=` cache-buster does not change between them. `remove` racing `put` can null the paths right after `put` stored new ones, again orphaning files.
**Fix:** Wrap each method in a `DB::transaction`. Re-read the row first with `User::whereKey($user->id)->lockForUpdate()->first()` and use its paths and version. Delete the old files after the commit.

### WR-06: A converted child keeps its parents as wish-list managers, and the adult is never told

**File:** `app/Auth/InviteService.php:169-188`
**Issue:** Conversion keeps the same row, so the `wishlist_managers` rows (parents acting as recipient-side managers of this list) survive. After the new member signs in with their own credentials, their parents can still edit their list and are still excluded from reservations as recipients. The only way to see this is the Wishlist managers tab, which is admin-only. The owner decision "links stay" covers the data, but not the new person's consent. Failure scenario: a teenager converts, expects a private list, and the parents continue to manage it.
**Fix:** On conversion either clear the owner's manager rows (a deliberate admin choice later via the tab), or keep them and show the new member who manages their list on first sign-in and on the profile page. Document whichever is chosen in the manual.

## Info

### IN-01: `photoUserId` exposes the linked account id of a medical person to every health viewer

**File:** `app/Medical/PersonPage.php:124`
**Issue:** `hasAccount` already existed. The new `photoUserId` also reveals which user account a medical subject is linked to, to anyone with view access to that person (not only admins). Photos are visible to every member anyway, so exposure is small, but it is new linkage data on a restricted module.
**Fix:** Send a pre-built avatar URL only when the viewer may see the photo, or send the user id only to viewers who can already see the link (the admin settings page already has `linkedUserId`).

### IN-02: A failed photo removal gives the user no feedback

**File:** `resources/js/Components/Profile/ProfilePhotoEditor.vue:76-82`
**Issue:** `router.delete(...)` has no `onError` handler, so a 403, 404 or 5xx is silent while the editor still shows the photo.
**Fix:** Add `onError: () => { removeError.value = t('profile.photo.err_write'); }` and render it in the same `UiAlert`.

### IN-03: Parent-selection validation errors can surface a raw translation key

**File:** `resources/js/Components/Profile/ChildCreateDialog.vue:29`, `app/Http/Requests/Settings/StoreChildRequest.php:30-45`
**Issue:** `parent_ids.*` has `integer` and `distinct` rules with no custom messages. A failing value reaches `translate()`, which passes unknown messages through as `validation.integer` text. The generic banner covers most cases, but the field-level text is untranslated.
**Fix:** Add `parent_ids.*.integer` and `.distinct` to `messages()`, mapped to `settings.members.child.err_parent`.

### IN-04: Migration fails the whole deploy if a blank e-mail row exists

**File:** `database/migrations/2026_11_26_100000_add_child_and_photo_columns_to_users.php:25`
**Issue:** `users_email_not_blank` is added to the populated `users` table. A row with `email = ''` (old manual insert) makes `ADD CONSTRAINT` fail. Postgres DDL is transactional, so nothing is half-applied, but the production deploy would stop.
**Fix:** Add a guard at the top of `up()`: `if (DB::table('users')->where('email', '')->exists()) throw new RuntimeException('Fix blank user e-mails first.');`. Or run `select count(*) from users where email = ''` on production before release.

### IN-05: Large uploads over the nginx limit and name-rule duplication

**File:** `resources/js/Components/Profile/ProfilePhotoEditor.vue:50-66`, `app/Http/Requests/Profile/UpdateProfileNameRequest.php:20-27`, `app/Http/Requests/Settings/StoreChildRequest.php:16-22`
**Issue:** There is no client-side size check, so a photo over 8 MB gets an nginx 413 HTML page that Inertia shows in its generic error modal instead of the `profile.photo.err_size` message. The whitespace-collapse regex and the control-character rule are also copied into two requests.
**Fix:** Check `file.size > 5 * 1024 * 1024` in `onFile` and set the `err_size` message locally. Move the name normalisation and rule into a shared rule or trait.

---

_Reviewed: 2026-11-17_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: deep_
