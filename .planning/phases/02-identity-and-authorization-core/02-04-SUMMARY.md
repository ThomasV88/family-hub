---
phase: 02-identity-and-authorization-core
plan: 04
subsystem: auth
tags: [laravel, fortify, password-reset, sessions, inertia, vue, settings]
requires: [02-03]
provides:
  - Enumeration-safe password reset (Fortify resetPasswords, Inertia pages, queued localized notification)
  - App\Auth\SessionRevoker (revokeAll, revokeOne) reused by plan 08
  - ThrottleAuthRoutes middleware (password.email, password.update) and no-referrer alias
  - SettingsSections::for(User), SettingsLayout.vue, Settings index and appearance pages
  - Settings item in the account menu
affects: [02-05, 02-06, 02-07, 02-08, 02-09]
tech-stack:
  added: []
  patterns: [one anonymous response bound to success and failure contracts, route-name keyed throttle in web group, sections list computed server-side from Route::has]
key-files:
  created:
    - app/Auth/SessionRevoker.php
    - app/Notifications/ResetPasswordNotification.php
    - app/Http/Middleware/{ThrottleAuthRoutes,NoReferrer}.php
    - app/Support/SettingsSections.php
    - app/Http/Controllers/Settings/SettingsController.php
    - routes/web/settings.php
    - lang/{en,sk,pl}/passwords.php
    - resources/js/Pages/Auth/{ForgotPassword,ResetPassword}.vue
    - resources/js/Layouts/SettingsLayout.vue
    - resources/js/Pages/Settings/{Index,Appearance}.vue
    - tests/Support/EndpointMatrix/{password,settings}.php
    - tests/Feature/Auth/{PasswordResetTest,SessionRevokerTest}.php
    - tests/Feature/Settings/SettingsShellTest.php
    - tests/js/auth/PasswordReset.test.js
    - tests/js/settings/SettingsShell.test.js
  modified:
    - config/fortify.php
    - app/Models/User.php
    - app/Actions/Fortify/ResetUserPassword.php
    - app/Providers/FortifyServiceProvider.php
    - bootstrap/app.php
    - resources/js/Shell/AccountMenu.vue
    - tests/Feature/Auth/RegistrationAbsentTest.php
    - tests/js/AccountMenuStyleGuide.test.js
decisions:
  - "Known and unknown emails get the same redirect back with status reset_link_sent (one class bound to both Fortify contracts)"
  - "User implements HasLocalePreference so queued mail renders in the member's locale"
  - "ThrottleAuthRoutes counts a request against all of its limits at once and answers back with errors.throttle in whole minutes"
metrics:
  tasks: 2
  completed: 2026-11-13
---

# Phase 2 Plan 04: Password reset, session revocation and Settings shell Summary

A member can request a reset link without the app revealing which emails exist, and setting a new password signs them out on every other device. Settings is reachable from the account menu for every member.

## Tasks

| Task | Commit | Notes |
|------|--------|-------|
| 1 Reset backend, SessionRevoker, throttling | edb8bfb | Pest 133 green |
| 2 Pages, Settings shell, account menu | 4c5be5b | Vitest 328 green, build SW-PRECACHE OK |

## Verification

- Pest 133 passed; Vitest 328 passed; `npm run build` prints SW-PRECACHE OK; Pint clean on all files of this plan (only the pre-existing `routes/console.php` flag remains); compose project `fh0204` removed.
- Tests cover identical known/unknown responses, queued notification only for the known user, valid/invalid token page, no-referrer header, session deletion and remember_token rotation, short/mismatch/expired errors, 6th request throttled, revokeOne scoped by id and user_id, matrix rows for four password and two settings routes.
- Acceptance greps satisfied (FailedPasswordResetLinkRequestResponse, ShouldQueue, remember_token, no registration/2FA/passkey features, autocomplete new-password, `/settings` in AccountMenu, aria-current in SettingsLayout).
- Human check (reset link from log, second browser signed out, Settings on phone and desktop) was not performed here; it remains for the end-of-phase check.

## Deviations from Plan

**1. [Rule 3 - Blocking] `MailMessage::locale()` does not exist**
- Fix: dropped it; strings are translated explicitly with the member locale and `User` implements `HasLocalePreference` so the framework template text follows too.
- Commit: edb8bfb.

**2. [Minor] Password error mapping inside the validator**
- Custom messages on `ResetUserPassword` map `password.min`, `confirmed` and `uncompromised` to the invite/auth keys (verified by test).

## Auth gates

None.

## Known Stubs

None. `Settings/Index` redirects on wide screens to the first section; admin sections (members, audit) appear when plans 05 and 06 register `settings.members` and `settings.audit`.

## Threat Flags

None beyond the plan's threat model (T-02-17 to T-02-22 mitigated and tested).

## Requirements

AUTH-02 was already ticked. AUTH-04 is only partly delivered (SessionRevoker exists; the admin revoke UI comes in plan 08), so it was not ticked.

## Self-Check: PASSED

All listed files exist; commits edb8bfb and 4c5be5b present.
