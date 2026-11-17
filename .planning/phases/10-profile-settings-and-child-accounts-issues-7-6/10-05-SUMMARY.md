---
phase: 10-profile-settings-and-child-accounts-issues-7-6
plan: 05
subsystem: child-accounts
tags: [child-account, wishlist-managers, members-ui, profile, help, manual, i18n]
requires: ["10-02", "10-04"]
provides:
  - "POST /settings/members/children (settings.members.children.store): admin + recent auth, throttled, listed in SensitiveRoutes::accessGranting()"
  - "CreateChild action and WishlistManagerSync (owner row lock), EligibleWishlistManager rule (signs in + Wishlist Edit)"
  - "AuditEvent::ChildCreated (child_created, group account)"
  - "Members payload: signInDisabled, isChild, photoVersion, managerNames per member; eligibleParents prop"
  - "GET /profile/people/{user} (profile.people.show) and managedPeople on /profile"
  - "ChildCreateDialog, ChildProfileTab, child rows and child panel in Settings > Members"
affects: [10-06, 10-07, 10-08]
key-files:
  created:
    - app/Actions/Profile/CreateChild.php
    - app/Wishlist/WishlistManagerSync.php
    - app/Rules/EligibleWishlistManager.php
    - app/Http/Controllers/Settings/ChildrenController.php
    - app/Http/Requests/Settings/StoreChildRequest.php
    - routes/web/settings-children.php
    - tests/Support/EndpointMatrix/settings-children.php
    - tests/Feature/Settings/ChildCreateTest.php
    - tests/Feature/Profile/ProfilePeopleTest.php
    - tests/Feature/Wishlist/ChildListTest.php
    - resources/js/Components/Profile/ChildCreateDialog.vue
    - resources/js/Components/Settings/ChildProfileTab.vue
    - tests/js/profile/ChildCreateDialog.test.js
  modified:
    - app/Http/Controllers/Settings/WishlistManagersController.php
    - app/Http/Requests/Settings/UpdateWishlistManagersRequest.php
    - app/Http/Controllers/Settings/MembersController.php
    - app/Http/Controllers/Profile/ProfileController.php
    - routes/web/profile.php
    - tests/Support/EndpointMatrix/profile.php
    - tests/Support/SensitiveRoutes.php
    - app/Audit/AuditEvent.php
    - resources/js/Pages/Settings/Members.vue
    - resources/js/Pages/Profile/Edit.vue
    - resources/js/Components/Settings/MemberRow.vue
    - resources/js/Components/Settings/MemberPanel.vue
    - resources/js/Components/Settings/WishlistManagersTab.vue
    - resources/js/Components/Settings/AuditTable.vue
    - resources/js/Components/Profile/ProfilePhotoEditor.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/manual/en/admin.json
    - resources/manual/sk/admin.json
    - resources/manual/pl/admin.json
    - resources/manual/en/wishlist.json
    - resources/manual/sk/wishlist.json
    - resources/manual/pl/wishlist.json
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 10 Plan 05: Child accounts, creation and parent management Summary

An admin with a recent re-authentication creates a child (name, optional photo, at least one eligible parent) from Settings > Members in one transaction, with no e-mail and no way to sign in. The chosen parents manage the child's wish list, and a non-admin parent edits the child's name and photo from Profile settings > People you manage.

## Commits
- d3c11ce child creation backend: WishlistManagerSync extracted, CreateChild, route, matrix, audit event (`Refs #6`)
- 86097a1 Members payload for children, `/profile/people/{user}`, managedPeople, ChildListTest (`Refs #6`)
- 4d088d0 test: scope the member secrets check to the member rows (`Refs #6`)
- 34321fe add-child dialog, child rows and panel, People you manage, help tip and manual (`Refs #6`)

## Tests
Isolated compose project `fh1005` (own Postgres, scratch folder over storage/logs), serial, removed with `down -v` afterwards. Pint on all of app, routes, tests: pass. Full Vitest: 151 files, 5143 tests passed (manual and help coverage, axe for dialog, child row and panel and people card in en/sk/pl, light and dark, pseudo-long text). Pest `tests/Feature/Manual`, `Help`, `Settings`, `Audit`, EndpointMatrix, SensitiveGateMatrix, WishlistPrivacyArch: green. Full serial Pest suite: 2914 passed, 1 skipped.

## User documentation
Manual: new section `children` in `admin.json` (what a child account is, adding one, the tags, editing name and photo, recent confirmation) and new section `child-list` in `wishlist.json` (a child's list, parents manage it, edit name and photo under Profile settings > People you manage), plus one added sentence in the existing admin section `wishlist-managers` (only members who can sign in and hold Wishlist edit are offered). Same section ids, counts and links in en, sk, pl; `ManualCoverage.test.js` pins the two new ids. Help tip `members.child` (screen `members_child`, area settings) in en, sk, pl, placed on the Parents field of the dialog.

Touched `resources/manual/` files, sk and pl are drafts for native review:
- resources/manual/en/admin.json, resources/manual/sk/admin.json, resources/manual/pl/admin.json
- resources/manual/en/wishlist.json, resources/manual/sk/wishlist.json, resources/manual/pl/wishlist.json

Also for native review: `resources/help/sk.json` and `pl.json` (members.child, screen name `members_child`), and the `settings.members.child.*`, `profile.people.*` and `audit.detail.child_created` keys in `lang/sk.json` and `lang/pl.json`.

## Deviations from Plan
- **Flash location.** The created child id is flashed as `flash_data.created_child_id` (the key Inertia exposes as `flash.data`, like the invite link), not as a bare `created_child_id` session key, so the dialog can read it. Test asserts `session('flash_data.created_child_id')`.
- **Header order.** "Invite member" stays first and "Add child" (secondary, icon Baby) comes second, so existing tests that pick the first header button stay valid; matches "second button next to Invite member".
- **Multi-select as checkbox group.** The Parents field is a fieldset of checkboxes (UiCheckbox), because UiSelect is single-select only; it keeps the same hint, required rule and HelpTip. The existing WishlistManagersTab received an optional `candidates` prop so the child's Wishlist tab (and every Wishlist tab on the Members page) offers only eligible parents instead of members the server would refuse with 422.
- **Deferred mode for ProfilePhotoEditor.** Added `deferred` (keeps the file as an object-URL preview and emits `select`/`clear` instead of uploading) and `externalError`, needed because the child does not exist while the photo is chosen. The dialog hands the file to Members.vue, which posts it to `/people/{id}/photo` after the create succeeded; a failed upload leaves the child created and shows the photo error and an alert in the child's panel.
- **Extra component** `ChildProfileTab.vue` (name form, photo editor, info alert, read-only sign-in switch) instead of inlining in MemberPanel; not listed in the plan's files.
- **Edit.vue**: the page root is keyed by `subject.id` so navigating from `/profile` to `/profile/people/{id}` remounts the forms; the "Edit" buttons use UiButton with `href` and `router.visit` (SPA navigation).
- **WishlistManagerSync::sync** takes an optional third argument `?User $createdBy` (defaults to the authenticated user) so CreateChild and the controller record who designated the manager; the two-argument contract still works.
- Existing test `Invitations.test.js` is unchanged; the `MembersPage.test.js` useForm mock gained `errors`, `post`, `reset`, `clearErrors` because the page now also mounts the dialog.
- sk and pl texts are drafts for native review.

## Known Stubs
None. The read-only "Direct sign-in disabled" switch carries the hint "Turns off when you invite them with an e-mail"; the conversion that makes that true is plan 10-06.

## Threat Flags
None beyond the plan's threat model. T-10-25 (admin, recent auth, throttle, `accessGranting()` + SensitiveGateMatrixTest and a stale-admin 423 test), T-10-26 (EligibleWishlistManager in both requests; child and view-only parents refused), T-10-27 (uniform 404 for unrelated, adult, own id, unknown id), T-10-28 (explicit member field list test, no photo paths or secrets), T-10-29 (`child_created` audit row, content-free meta; profile edits audited by 10-03/10-04), T-10-30 (NULL e-mail, sign_in_disabled, random password, password_set false) are covered by tests.

## Self-Check: PASSED
Commits d3c11ce, 86097a1, 4d088d0, 34321fe and all created files verified present.
