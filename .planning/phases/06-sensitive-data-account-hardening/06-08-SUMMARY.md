---
phase: 06-sensitive-data-account-hardening
plan: 08
subsystem: auth
tags: [passkeys, webauthn, laravel-passkeys, second-factor, reauth]
requires: [06-04, 06-05]
provides:
  - PasskeyCeremony adapter (single seam over laravel/passkeys actions, catches Throwable)
  - Passkey management endpoints (options, store, rename, remove) with access:recent on enrolment and removal
  - Passkey as second step of sign-in and on "Confirm it's you"
  - Per-environment rp id / origin / user handle secret config, one-flag switch
  - FakePasskeys container fakes
affects: [06-09, 06-13]
key-files:
  created:
    - app/Security/Passkeys/PasskeyCeremony.php
    - app/Http/Controllers/Security/PasskeyController.php
    - app/Http/Controllers/Security/ChallengePasskeyController.php
    - app/Http/Controllers/Security/ReauthPasskeyController.php
    - app/Http/Requests/Security/PasskeyNameRequest.php
    - app/Http/Middleware/EnsurePasskeysEnabled.php
    - routes/web/security-passkeys.php
    - tests/Support/FakePasskeys.php
    - tests/Support/EndpointMatrix/security-passkeys.php
    - tests/Unit/Security/PasskeyCeremonyTest.php
    - tests/Feature/Security/PasskeyManagementTest.php
    - tests/Feature/Security/PasskeyChallengeTest.php
    - tests/Feature/Security/PasskeyReauthTest.php
  modified:
    - config/fortify.php
    - .env.example
decisions:
  - "Ceremony test coverage is container fakes (package actions swapped, real requests/session/ownership/events/audit) plus the 06-13 real-device UAT; the optional SoftwareAuthenticator real-crypto helper was not built"
  - "Features::passkeys() stays off; Fortify's provider still reads config('fortify.passkeys.*'), so config/fortify.php carries an env-built, array_filter'ed `passkeys` array (unset values fall back to APP_URL / app.key, never null)"
metrics:
  tasks: 2
  completed: 2026-11-14
---

# Phase 6 Plan 08: Passkey backend Summary

Passkeys as a second factor behind one adapter over the installed laravel/passkeys 0.2.1 actions: register, rename and remove for the signed-in member, passkey on the sign-in challenge (member taken from `login.id`) and on confirm-access, without Fortify's passkey feature or any passwordless route.

## Commits
- f6f08ef: adapter, management endpoints, name request, config, env docs, fakes, unit and management tests (Task 1)
- a98e522: challenge and reauth controllers, routes, matrix rows, tests (Task 2)

## Behaviour
- `PasskeyCeremony` resolves GenerateRegistrationOptions, StorePasskey, GenerateVerificationOptions, VerifyPasskey from the container; `register()` returns null and `verify()` returns false on any Throwable (including the package requests' expired-options ValidationException); only the exception class is logged.
- Registration options require user verification (package default, asserted in a test). Options and store carry `access:recent`: stale session gives 423 `confirm_required`, no options stored, no passkey row, no audit row. Remove also needs it; rename does not.
- Name: trimmed, 1-60 chars, `\p{C}` rejected, error key `passkey.error.name`, validated before the ceremony so a bad name never burns the stored options.
- Challenge: `User::find(session('login.id'))` (no `Auth::user()`), 401 JSON / redirect to /login without a pending login or without passkeys, VerifyPasskey called with the expected user (cross-member credentials rejected), then guard login with `login.remember`, session regenerate, `SensitiveSession::confirm()`, `login.*` keys forgotten, `{redirect}` from SafeIntended. The `login_success` row comes from the existing Login listener.
- Reauth: page mode returns `{redirect}`, dialog mode `{confirmed:true}` and leaves `url.intended` alone; failures give 422 `{error:'passkey_failed'}` plus `second_factor_failed {method: passkey, context}`.
- Config: `fortify.passkeys` (PASSKEYS_RP_ID, PASSKEYS_ALLOWED_ORIGINS comma list, PASSKEYS_USER_HANDLE_SECRET) filtered of empty values; tests show APP_URL and app.key fallbacks. `.env.example` documents PASSKEYS_USER_HANDLE_SECRET, SECURITY_PASSKEYS_ENABLED and SECURITY_GOOGLE_REAUTH.

## Verification
- PasskeyCeremonyTest, PasskeyManagementTest, PasskeyChallengeTest, PasskeyReauthTest, EndpointMatrixTest: 126 passed together.
- Acceptance greps: `access:recent` x3 in the routes file, `Features::passkeys` 0 in config/fortify.php, no `new StorePasskey/VerifyPasskey/Generate*Options` in app, 0 `Auth::user()` in ChallengePasskeyController.
- Full PHP suite: see the completion report. `pest` as a whole aborts at load with "Cannot redeclare function addPasskey()" because 06-07's PolicyAndResetTest (committed b6ce3cc) and 06-01's SecondFactorModelTest both declare it; not my files, left alone (reported to the orchestrator).

## Deviations from Plan
- **[Rule 3 - Blocking] EnsurePasskeysEnabled middleware (new file)**: "all routes 404 when the flag is off" needs a route-level switch; added `app/Http/Middleware/EnsurePasskeysEnabled.php`, referenced by class name in the routes file (no alias edit in bootstrap/app.php, which parallel plans touch).
- Ceremony fakes live at the package-action level (the synthetic CBOR/clientData payloads in FakePasskeys deserialise through the real package requests), so no PasskeyCeremony-level fake was needed.
- Matrix: `passkeys.update`/`passkeys.destroy` guest rows are 404, not 302: Laravel binds `{passkey}` before the access rule runs (nothing is revealed; the bound id does not exist). `confirm-access.passkey.store` with an empty body is 302 (validation before the 404).
- Management routes were committed in Task 1 with four routes and the file completed in Task 2 (so each commit boots).

## Known Stubs
None.

## Threat Flags
None beyond the plan's threat model.

## Notes for 06-09 / 06-13
- Browser contract: options `{options}` (pass to `navigator.credentials`), then POST `{credential, mode?, remember?}`; failure is 422 `{error:'passkey_failed'}` (or a standard 422 validation body for a malformed payload); stale enrolment/removal is 423 `{error:'confirm_required'}`; throttle bodies come from the existing limiters.
- Challenge-step throttle (`two-factor`, 5/min per pending member) counts the options GET as well as the POST.
- Real authenticator behaviour (Touch ID, Windows Hello, security keys, iOS PWA) is only verified in the 06-13 device UAT; the crypto is not exercised in automated tests.

## Self-Check: PASSED
