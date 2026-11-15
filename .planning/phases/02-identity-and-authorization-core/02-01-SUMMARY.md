---
phase: 02-identity-and-authorization-core
plan: 01
subsystem: auth
tags: [laravel, fortify, socialite, i18n, vue-i18n]
requires: []
provides:
  - laravel/fortify 1.42.0 and laravel/socialite 5.32.0 installed (owner approved)
  - Headless Fortify (features empty), FortifyServiceProvider skeleton
  - Complete Phase 2 copy (217 keys) in SK/PL/EN
  - lang/{locale}/auth.php returning i18n keys, lang/{locale}/mail.php for invite and reset mails
affects: [02-02, 02-03, 02-04, 02-05, 02-06, 02-07, 02-08, 02-09, 02-10]
tech-stack:
  added: [laravel/fortify 1.42.0, laravel/socialite 5.32.0, laravel/passkeys 0.2.1 (transitive), bacon/bacon-qr-code 3.1.1 (transitive), web-auth/webauthn-lib 5.3.12 (transitive)]
  patterns: [server messages are i18n keys, plurals as vue-i18n pipe strings]
key-files:
  created:
    - config/fortify.php
    - app/Providers/FortifyServiceProvider.php
    - app/Actions/Fortify/ResetUserPassword.php
    - app/Actions/Fortify/PasswordValidationRules.php
    - lang/en/auth.php
    - lang/sk/auth.php
    - lang/pl/auth.php
    - lang/en/mail.php
    - lang/sk/mail.php
    - lang/pl/mail.php
    - tests/Feature/Auth/RegistrationAbsentTest.php
    - tests/js/i18n-phase2.test.js
  modified: [composer.json, composer.lock, bootstrap/providers.php, lang/en.json, lang/sk.json, lang/pl.json]
decisions:
  - "Fortify features stay an empty array; resetPasswords is enabled in plan 04 with its views"
  - "Passkeys config block and the two-factor and passkeys limiters removed; only the login limiter remains"
  - "vue-i18n special character @ in auth.error.email_invalid is written as {'@'}"
metrics:
  completed: 2026-11-13
  tasks: 3
---

# Phase 2 Plan 01: Packages, headless Fortify and Phase 2 copy Summary

Owner-approved Fortify and Socialite installed, Fortify wired headless with no features (`/register` and `/two-factor-challenge` are 404), and all Phase 2 screen copy present in SK/PL/EN so later plans never touch `lang/*.json`.

## Package approval

Owner answer relayed by the orchestrator, verbatim: **"Approve (Recommended)"** for laravel/fortify 1.42.0 and laravel/socialite 5.32.0 plus their transitive dependencies. No `composer require` ran before this answer (composer.lock unchanged until Task 2). Installed set: fortify v1.42.0, socialite v5.32.0, passkeys v0.2.1, webauthn-lib 5.3.12, bacon-qr-code v3.1.1, plus oauth1-client, php-jwt, phpseclib (Socialite/passkeys dependencies).

## Tasks

| Task | Commit | Notes |
|------|--------|-------|
| 1 Package legitimacy check | c361828 | Empty commit recording approval |
| 2 Install, headless Fortify | 66b563e | composer, config/fortify.php, provider, RegistrationAbsentTest (3 tests pass) |
| 3 Phase 2 copy | 6757c35 | 217 keys x 3 locales, auth.php, mail.php, i18n-phase2 test |

## Fortify details (assumptions A1 and A2)

- A1: `php artisan fortify:install` exists in 1.42.0 and registered the provider in `bootstrap/providers.php`. It also published two migrations (two-factor columns, passkeys table) and four actions; the migrations, `CreateNewUser`, `UpdateUserPassword` and `UpdateUserProfileInformation` were deleted.
- A2: routes registered with features empty: `login` (GET /login), `login.store` (POST /login), `logout` (POST /logout), plus `password.confirm` (GET /user/confirm-password), `password.confirm.store` (POST), `password.confirmation` (GET /user/confirmed-password-status). No register, two-factor or reset routes. Plans 03 and 04 can rely on `login`, `login.store`, `logout`. The `password.confirm*` routes exist regardless of features and are unused.
- `config/fortify.php`: guard web, `lowercase_usernames` true, home `/`, `limiters` `['login' => 'login']` (named limiter is defined in plan 02), `features` `[]`.
- `PasswordValidationRules` now uses `Password::defaults()` (configured in plan 02).

## Copy details

- Keys follow the UI-SPEC tables exactly (parsed from the spec to avoid typos) plus the 40 extra keys listed in the plan. Files are `lang/{en,sk,pl}.json`, not `en-GB.json`.
- `auth.error.throttle` is pipe plural: en 2 segments, sk and pl 4 segments (verified: sk 3 gives "minúty", sk 5 "minút", pl 2 "minuty", pl 5 "minut").
- Namespace `common` is new and does not clash with the PHP group file `lang/en/common.php`.

## Deviations from Plan

- [Rule 3 - Blocking] Running `pint` on `app config tests bootstrap` also reformatted `bootstrap/app.php` and `tests/Feature/HeartbeatTest.php`; those unrelated changes were reverted before committing.
- Removed the installer's passkeys config block and the two-factor and passkeys rate limiters along with the unused features (consistent with the plan's "never enabled").

## Deferred Issues

- `./vendor/bin/pint --test` over the whole repo flags `routes/console.php` (ordered_imports); pre-existing, out of scope. Pint on the files this plan touched passes.

## Known Stubs

None. The FortifyServiceProvider boot body is intentionally minimal; plans 02 and 04 fill it.

## Threat Flags

None. T-02-SC (human gate, lock committed), T-02-01 (RegistrationAbsentTest) and T-02-02 (single generic `auth.error.failed` key) are mitigated as planned.

## Verification

- `pest`: 53 passed (includes RegistrationAbsentTest); `vitest run`: 268 passed (24 files).
- Compose project `fh0201` torn down with `down -v`.

## Self-Check: PASSED

Files and commits (c361828, 66b563e, 6757c35) verified present.
