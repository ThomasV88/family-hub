---
phase: 10-profile-settings-and-child-accounts-issues-7-6
plan: 02
subsystem: auth-lockout
tags: [children, sign-in, privacy, middleware, arch-test]
requires: ["10-01"]
provides:
  - "Every session-establishing path refuses accounts where User::canSignIn() is false"
  - "App\\Http\\Middleware\\BlockDisabledSignIn (web group, before HandleInertiaRequests)"
  - "Children excluded from task, occasion and vaccination reminder recipients"
affects: [10-05, 10-08]
key-files:
  created:
    - app/Http/Middleware/BlockDisabledSignIn.php
    - tests/Feature/Auth/ChildSignInBlockedTest.php
    - tests/Unit/Arch/SignInArchTest.php
    - tests/Feature/Notifications/ChildNoMailTest.php
  modified:
    - app/Providers/FortifyServiceProvider.php
    - app/Http/Middleware/GuardTwoFactorRoutes.php
    - app/Http/Controllers/Security/ChallengePasskeyController.php
    - app/Auth/GoogleLoginService.php
    - app/Actions/Fortify/ResetUserPassword.php
    - bootstrap/app.php
    - app/Wishlist/Occasions/OccasionGivers.php
    - app/Tasks/ReminderRecipients.php
    - app/Medical/Reminders/VaccinationReminderPlanner.php
metrics:
  tasks: 3
  completed: 2026-11-17
---

# Phase 10 Plan 02: Child sign-in and mail lockout Summary

A sign-in-disabled account cannot establish a session by password, two-factor challenge (forged `login.id`), passkey second step, Google, password reset or a live session or remember cookie, and no scheduler path addresses a child; proven by a differential test using a real e-mail and a known password.

## Commits
- b1058bc password, two-factor, passkey, Google paths (authenticateUsing keeps the dummy-hash compare then checks canSignIn; GuardTwoFactorRoutes treats a pending login of a missing or disabled user like an expired challenge; passkey `pending()` and GoogleLoginService check canSignIn, and a disabled account is never linked by e-mail)
- b38f978 ResetUserPassword refusal (same `auth.reset.invalid_body` key as a bad token), BlockDisabledSignIn middleware registered after SetLocale and before HandleInertiaRequests, SignInArchTest
- 51a7149 OccasionGivers, ReminderRecipients, VaccinationReminderPlanner exclude children; ChildNoMailTest (5 tests)

All commits carry `Refs #6`.

## Tests
Isolated compose project `fh1002` (own Postgres, scratch folder over storage/logs), removed with `down -v`. Pest over tests/Feature/Auth, Unit/Arch, Feature/Security, Sessions, Invites, Notifications, Wishlist, Todo, Health: all passed except the then-unfinished ChildNoMailTest fixtures, fixed and re-run green (5 passed); ChildSignInBlockedTest and SignInArchTest green (Auth/Security/Invites/Sessions batch 345 passed). Pint on all touched PHP files: pass.

## User documentation
No user-visible change for existing members (reset form, login errors and mail for normal accounts unchanged); the lockout only affects accounts that cannot be created until 10-05, which documents "a child cannot sign in and gets no e-mail" in manual and help. No `resources/manual/` file touched.

## Deviations from Plan
- GuardTwoFactorRoutes::pendingUserBlocked ignores a non-numeric `login.id` (returns false) so the existing 401 "no pending login" answer for such input is unchanged (an existing PasskeyChallengeTest case; `User::find('abc')` would otherwise raise a SQL error). Missing numeric ids are treated as blocked.
- Passkey forged-id tests assert 410 (the route guard refuses before the controller); controller `pending()` is the second layer.
- Plan files_modified listed `GoogleController.php`; not changed because GoogleLoginService already returns no user for a disabled account, so `Auth::login` there is only reached for allowed users.
- Task 3 vaccination/occasion tests assert on the outbox (no row for the child) rather than a Notification::fake channel-level record; the `routeNotificationForMail` null plus Mail::fake case covers the mail channel.

## Known Stubs
None.

## Self-Check: PASSED
Files and commits b1058bc, b38f978, 51a7149 verified present.
