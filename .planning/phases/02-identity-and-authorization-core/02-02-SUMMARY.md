---
phase: 02-identity-and-authorization-core
plan: 02
subsystem: auth
tags: [laravel, fortify, inertia, vue, rate-limiting, sessions]
requires: [02-01]
provides:
  - users.is_admin, locale, google_id columns and unique lower(email) index
  - family:create-admin (single admin bootstrap, password via secret prompt)
  - Password::defaults() (min 12, uncompromised only in production and staging)
  - Fortify email/password login and logout, named `login` limiter, 30-day remember
  - Shared Inertia props auth.user and flash {status, toast, data}
  - AuthLayout, Auth/Login page, GoogleGlyph, AccountMenu header and Log out, UiMenu header and separator items
affects: [02-03, 02-04, 02-05, 02-09]
tech-stack:
  added: []
  patterns: [server errors are i18n keys, throttle errors carry whole minutes, forceFill for protected user columns]
key-files:
  created:
    - database/migrations/2026_11_13_100000_add_identity_columns_to_users_table.php
    - app/Console/Commands/CreateAdmin.php
    - resources/js/Layouts/AuthLayout.vue
    - resources/js/Pages/Auth/Login.vue
    - resources/js/Components/GoogleGlyph.vue
    - tests/Feature/Auth/CreateAdminTest.php
    - tests/Feature/Auth/LoginTest.php
    - tests/Feature/Auth/RememberMeTest.php
    - tests/js/auth/Login.test.js
  modified:
    - app/Models/User.php
    - database/factories/UserFactory.php
    - app/Providers/AppServiceProvider.php
    - app/Providers/FortifyServiceProvider.php
    - config/fortify.php
    - config/session.php
    - bootstrap/app.php
    - routes/web.php
    - app/Http/Middleware/HandleInertiaRequests.php
    - app/Http/Middleware/SetLocale.php
    - app/Http/Controllers/LocaleController.php
    - resources/js/Shell/AccountMenu.vue
    - resources/js/Components/ui/UiMenu.vue
    - resources/js/Layouts/AppShell.vue
    - scripts/smoke-local.sh
    - scripts/smoke-server.sh
decisions:
  - "Remember duration set in an Auth::resolved callback (setRememberDuration(43200)); RememberMeTest confirms an expiry of 29-31 days (assumption A3 holds)"
  - "AuthLayout renders its own h1 inside the card because UiCard only supports h2/h3 titles"
  - "Google button is rendered only when the page prop googleEnabled is true (false until plan 09)"
metrics:
  tasks: 3
  completed: 2026-11-13
---

# Phase 2 Plan 02: Admin bootstrap and password sign-in Summary

The owner can create the first admin with `family:create-admin`, sign in with email and password (30-day remember optional), see the Overview and log out from the account menu; guests are sent to `/login` and brute force is throttled with a localized wait message.

## Tasks

| Task | Commit | Notes |
|------|--------|-------|
| 1 Identity columns and create-admin | 73109f3 | CreateAdminTest 8 tests |
| 2 Fortify login, limiter, remember, logout, shared props, smoke scripts | 97667fa | LoginTest 10, RememberMeTest 3, existing tests updated |
| 3 Login page, AuthLayout, account menu, flash toast | 7f4a2f5 | Login.test.js 15 tests, menu and Home tests extended |

## Verification

- Pest 76 passed; Vitest 288 passed (25 files); `npm run build` prints SW-PRECACHE OK.
- Pint over the repo flags only `routes/console.php` (pre-existing, noted in 02-01).
- Compose project `fh0202` torn down with `down -v`.
- Threats: T-02-03 (limiter tests incl. cross-IP lockout and Lockout event), T-02-04 (identical error, dummy hash), T-02-05 (session id changes), T-02-06 (cookie 29-31 days), T-02-07/T-02-08/T-02-10 (CreateAdminTest) covered. T-02-09: redirect after login is Fortify's intended-or-home, no `next` parameter.

## Deviations from Plan

**1. [Rule 3 - Blocking] config/fortify.php lacked the keys plan 01 reported**
- Found during: Task 2 (login redirected to `/home`, limiter was not applied).
- Issue: the committed config contained only `features`, so package defaults applied (`home` `/home`, no named limiter, `lowercase_usernames` false), contrary to the 02-01 summary.
- Fix: set `guard`, `home` `/`, `lowercase_usernames` true and `limiters.login` = `login` explicitly.
- Commit: 97667fa.

**2. [Plan wording] UiMenu tests live in widgets.test.js**
- The plan names tests/js/ui/overlays.test.js, but the existing UiMenu tests are in tests/js/ui/widgets.test.js, so the header/separator test was added there. overlays.test.js is unchanged.

**3. [Minor] UiMenu separator logic**
- Existing code drew a separator before every item after the first. With the new explicit `separator` type, an automatic separator is skipped directly after an explicit one so none is doubled. Existing menu behaviour is unchanged.

**4. [Minor] Added tests beyond the plan**
- A guest heartbeat redirect test, a login no-store header test, and a flash.toast test in Home.test.js.

## Auth gates

None.

## Known Stubs

None. `googleEnabled` is passed as `false` by design; plan 09 flips it. The account menu has no Settings item until plan 04, as planned.

## Threat Flags

None.

## Notes for later plans

- Throttle errors reach the page as `errors.throttle` (whole minutes, string); Login.vue keeps submit disabled with a client timer.
- Page props `flash.status` keys handled by Login: `signed_out`, `session_ended`, `password_reset`.
- Human check at end of phase (not run here): open /login on phone and desktop, sign in, tick and untick "Stay signed in", check alerts in sk and pl, log out from the account menu.

## Self-Check: PASSED

Files and commits 73109f3, 97667fa, 7f4a2f5 verified present.
