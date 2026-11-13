---
phase: 06-sensitive-data-account-hardening
plan: 10
subsystem: auth
tags: [google, socialite, second-factor, reauth, password_set]
requires: [06-04, 06-05]
provides:
  - Google sign-in routed through the two-step challenge
  - Flag-gated "Sign in with Google again" re-auth (security.google_reauth, default off)
  - password_set maintenance (Google invite false, password reset true)
affects: [06-13]
key-files:
  modified:
    - app/Http/Controllers/Auth/GoogleController.php
    - routes/web/google.php
    - tests/Support/EndpointMatrix/google.php
    - tests/Support/FakeGoogle.php
    - app/Auth/InviteService.php
    - app/Actions/Fortify/ResetUserPassword.php
    - resources/js/Pages/Auth/ConfirmAccess.vue
    - tests/js/security/ConfirmAccess.test.js
  created:
    - tests/Feature/Security/GoogleSecondFactorTest.php
    - tests/Feature/Security/GoogleReauthTest.php
requirements-completed: [AUTH-09, AUTH-10]
completed: 2026-11-14
---

# Phase 6 Plan 10: Google second factor and re-auth Summary

Google sign-in now parks login.id and redirects to the challenge for members with a factor; Google re-auth exists only for eligible members behind the off-by-default flag.

## Commits
- 98444db: controller branches, route, matrix row, password_set upkeep, two PHP test files
- 235a634: Google link and failure alert on ConfirmAccess, Vitest cases

## Behaviour
- Guest callback: member with TOTP or passkey gets login.id/remember(false)/at and a redirect to two-factor.login, no Auth::login. Otherwise login, regenerate, SensitiveSession::confirm(), SafeIntended::pull ('/' fallback, welcome toast kept).
- GET /confirm-access/google (name confirm-access.google): 404 unless ReauthMethods::googleAvailable and Google configured; sets session reauth.google {user, at}; Socialite redirect with prompt=select_account, max_age=0 (tagged [ASSUMED], 06-13).
- Callback (now access:public): signed in without flag goes to '/'; with a flag it must be fresh (600 s), bound to the user, and googleAvailable must still hold; identity id must equal google_id (resolve() never called). Failure audits second_factor_failed {google, reauth} and redirects /confirm-access with status google_reauth_failed. Success confirms, audits reauth_confirmed {google}, 303 to intended.
- ConfirmAccess.vue: full-page secondary button with GoogleGlyph in the no-method block only when methods.google; danger alert read from shared flash.status.

## Deviations
- [Rule 3] FakeGoogle gained `with` (returns self) so the redirect with extra parameters can be faked. Shared test support, additive.
- Failure alert reads `usePage().props.flash.status` (shared prop) rather than a new controller prop, to avoid editing 06-04's ReauthController; test mock extended with usePage.
- i18n keys reauth.google / reauth.google_failed already existed in en/sk/pl.

## Verification
- Task 1 set (Google tests, Auth, Invites, matrix): 217 pass. ConfirmAccess Vitest 11 pass.
- Full Vitest: only tests/js/finance/Overview.test.js axe failures in the first run; passes alone on re-run (parallel-plan flake, not my files).
- Full PHP suite could not run as one: Pest fatals with "Cannot redeclare function addPasskey()" between tests/Feature/Security/PolicyAndResetTest.php (06-07, in flight) and SecondFactorModelTest.php (06-01). Not mine; needs a rename by the 06-07 owner. Running everything except PolicyAndResetTest: run 1 had 6 failures (one in a passkey reauth test from a parallel plan), run 2 had 1 (NotificationSettingsTest), none in Google, Auth, Invites or matrix files. The failure set differs between runs, consistent with other plans mid-edit.

## Known Stubs
None.

## Self-Check: PASSED
