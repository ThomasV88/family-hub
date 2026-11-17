---
phase: 10-profile-settings-and-child-accounts-issues-7-6
plan: 04
subsystem: profile-member-ui
tags: [profile, avatar, account-menu, help, manual, i18n]
requires: ["10-03"]
provides:
  - "GET /profile (profile.show) and PUT /people/{user}/name (profile.name.update)"
  - "UpdateProfileNameRequest: trim and collapse whitespace, no control/format characters, max length only on change, email prohibited"
  - "UiAvatar userId/src props, size 96, image error fallback; useAvatarUrl; UiMenu avatar and header leading avatar"
  - "Account menu item Profile settings above Settings"
  - "Pages/Profile/Edit.vue and Components/Profile/ProfilePhotoEditor.vue (reusable for children in 10-05)"
  - "Help tips profile.photo, profile.name; manual topic profile; who-sees-what section profile-photo"
affects: [10-05, 10-07, 10-08]
key-files:
  created:
    - app/Http/Controllers/Profile/ProfileController.php
    - app/Http/Requests/Profile/UpdateProfileNameRequest.php
    - tests/Feature/Profile/ProfileUpdateTest.php
    - resources/js/Components/ui/useAvatarUrl.js
    - resources/js/Components/Profile/ProfilePhotoEditor.vue
    - resources/js/Pages/Profile/Edit.vue
    - resources/manual/en/profile.json
    - resources/manual/sk/profile.json
    - resources/manual/pl/profile.json
    - tests/js/profile/ProfilePage.test.js
    - tests/js/profile/ProfilePhotoEditor.test.js
  modified:
    - routes/web/profile.php
    - tests/Support/EndpointMatrix/profile.php
    - resources/js/Components/ui/UiAvatar.vue
    - resources/js/Components/ui/UiMenu.vue
    - resources/js/Shell/AccountMenu.vue
    - lang/en.json
    - lang/sk.json
    - lang/pl.json
    - resources/help/topics.json
    - resources/help/en.json
    - resources/help/sk.json
    - resources/help/pl.json
    - resources/manual/en/who-sees-what.json
    - resources/manual/sk/who-sees-what.json
    - resources/manual/pl/who-sees-what.json
    - tests/js/ui/display.test.js
    - tests/js/AccountMenuStyleGuide.test.js
    - tests/js/shell/AppShell.test.js
    - tests/js/manual/ManualCoverage.test.js
    - tests/js/a11y-sweep.test.js
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 10 Plan 04: Member profile settings Summary

A member opens Profile settings from the account menu (their photo or initial is the trigger), renames themselves, uploads, replaces or removes their photo, and sees their e-mail read-only; every avatar component can now show a private photo and falls back to the initial.

## Commits
- b5a252b profile page route and display-name update (`Refs #7`)
- 7fafd28 avatar photos, account menu avatar and Profile settings item (`Refs #7`)
- bbadfd4 profile settings page, photo editor, help tips and manual (`Refs #7`)

## Tests
Isolated compose project `fh1004` (own Postgres, scratch folder over storage/logs), removed with `down -v`; no containers left. Pint on all touched PHP files: pass. Full serial Pest suite: 2885 passed, 1 skipped. Full Vitest: 150 files, 5117 tests passed (includes manual and help coverage, a11y sweep with Profile/Edit in en/sk/pl, light/dark, 390 and 1280 px). Manual Pest (`tests/Feature/Manual`) and Help Pest green.

## User documentation
New manual topic `profile` (category account, `covers: ["feature:profile"]`, sections open-profile, change-name, photo, email) and new section `profile-photo` in `who-sees-what`, in en, sk, pl. Help tips `profile.photo` and `profile.name` (screen `profile_page`) in all three languages. `feature:profile` added to `FEATURES` in `ManualCoverage.test.js`, plus a test pinning the profile section ids and the who-sees-what section.

Touched `resources/manual/` files, sk and pl are drafts for native review:
- resources/manual/en/profile.json, resources/manual/sk/profile.json, resources/manual/pl/profile.json
- resources/manual/en/who-sees-what.json, resources/manual/sk/who-sees-what.json, resources/manual/pl/who-sees-what.json

Also for native review: `resources/help/sk.json` and `pl.json` (profile.photo, profile.name, screen name), and the `profile.*` keys in `lang/sk.json` and `lang/pl.json`.

## Deviations from Plan
- Existing toast keys reused: the photo toast reads "Photo saved" / "Photo removed" (10-03 keys) instead of the UI-SPEC "Photo updated". New `profile.toast.saved` ("Profile saved") added.
- Validation messages are lang keys (`profile.err_name_required`, `err_name_max`, `err_name_chars`, `err_email_locked`) that the page translates, same pattern as the photo errors. Added to the `profile.*` namespace.
- Task 1 test used `component('Profile/Edit', false)` at commit time because the page file lands in Task 3; switched to the strict form in Task 3.
- The trigger avatar is drawn at 24 px through a `--ui-avatar-size` override so the account button keeps its height (UiAvatar's validator stays `[32, 40, 96]`).
- The photo editor's phone layout uses a viewport media query (max-width 480 px) instead of a container query, because the editor root is itself the container.
- Existing account-menu tests were adjusted for the new first item (indexes of Settings and Manual shifted by one).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model. T-10-20 (403 for another adult, audit row only when actor differs), T-10-21 (`email` prohibited, e-mail unchanged test), T-10-22 (control and format characters refused, whitespace collapsed), T-10-23 (explicit subject field list test), T-10-24 (object URLs revoked in `onFinish` and on unmount) are all covered by tests.

## Self-Check: PASSED
Commits b5a252b, 7fafd28, bbadfd4 and all created files verified present.
